<?php

namespace App\Console\Commands;

use App\Actions\EnsureRequestDriveFolder;
use App\Enums\RequestStatus;
use App\Models\DriveDelivery;
use App\Models\ServiceRequest;
use App\Services\GoogleDriveClient;
use App\Services\TelegramNotifier;
use App\Support\ResolveServiceRequest;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PollDriveDeliveriesCommand extends Command
{
    protected $signature = 'ops:poll-drive {--limit=25}';

    protected $description = 'Send new Google Drive files from request folders to the client bot.';

    public function handle(GoogleDriveClient $drive, TelegramNotifier $telegram, EnsureRequestDriveFolder $ensureRequestDriveFolder): int
    {
        if (! $drive->configured()) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $this->backfillMissingFolders($ensureRequestDriveFolder, $limit);
        $requests = $this->nextFolderBatch($limit);

        foreach ($requests as $request) {
            try {
                $files = $drive->listNewFiles((string) $request->google_drive_folder_id);
            } catch (Throwable $exception) {
                Log::warning('Drive poll list failed.', [
                    'request' => $request->number,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            foreach ($files as $file) {
                $this->deliverFile($drive, $telegram, $request, $file);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{id: string, name: string, mimeType: string}  $file
     */
    private function deliverFile(
        GoogleDriveClient $drive,
        TelegramNotifier $telegram,
        ServiceRequest $request,
        array $file,
    ): void {
        $fileId = (string) ($file['id'] ?? '');
        if ($fileId === '') {
            return;
        }

        $delivery = DriveDelivery::query()->firstOrNew(
            ['drive_file_id' => $fileId],
            ['request_id' => $request->id],
        );

        if ($delivery->sent_at !== null) {
            return;
        }

        $binary = $drive->downloadFile($fileId);
        if (! is_string($binary) || $binary === '') {
            Log::warning('Drive delivery download empty.', [
                'request' => $request->number,
                'file' => $fileId,
            ]);

            return;
        }

        $sent = false;
        $chatId = $request->client?->telegram_user_id;
        $tmp = null;
        if (filled($chatId) && $telegram->configured('client')) {
            $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string) $file['name'])) ?: 'file';
            $tmp = 'drive-deliveries/'.$fileId.'-'.$safeName;
            Storage::disk('local')->put($tmp, $binary);
            try {
                $telegram->sendFile(
                    (string) $chatId,
                    Storage::disk('local')->path($tmp),
                    (string) $file['mimeType'],
                    'ملف جديد للطلب #'.ResolveServiceRequest::displayNumber($request).': '.$file['name'],
                );
                $sent = true;
            } catch (Throwable $exception) {
                Log::warning('Drive delivery telegram send failed.', [
                    'request' => $request->number,
                    'file' => $fileId,
                    'error' => $exception->getMessage(),
                ]);
            } finally {
                Storage::disk('local')->delete($tmp);
            }
        }

        $delivery->forceFill([
            'request_id' => $request->id,
            'name' => $file['name'],
            'mime_type' => $file['mimeType'],
            'sent_at' => $sent ? now() : null,
        ])->save();
    }

    private function backfillMissingFolders(EnsureRequestDriveFolder $ensureRequestDriveFolder, int $limit): void
    {
        $requests = ServiceRequest::query()
            ->with('client')
            ->where(function ($query): void {
                $query->whereNotNull('paid_at')
                    ->orWhere('status', RequestStatus::PaymentConfirmed)
                    ->orWhere('amount_paid', '>', 0);
            })
            ->where(function ($query): void {
                $query->whereNull('google_drive_folder_id')
                    ->orWhere('google_drive_folder_id', '');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($requests as $request) {
            try {
                $ensureRequestDriveFolder->handle($request);
            } catch (Throwable $exception) {
                Log::warning('Drive folder backfill failed.', [
                    'request' => $request->number,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return EloquentCollection<int, ServiceRequest>
     */
    private function nextFolderBatch(int $limit)
    {
        $lastId = (int) Cache::get('ops:poll-drive:last_id', 0);
        $query = ServiceRequest::query()
            ->with('client')
            ->whereNotNull('google_drive_folder_id');

        $requests = (clone $query)
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($requests->isEmpty() && $lastId > 0) {
            Cache::forget('ops:poll-drive:last_id');
            $requests = $query->orderBy('id')->limit($limit)->get();
        }

        if ($requests->isNotEmpty()) {
            Cache::put('ops:pFoll-drive:last_id', (int) $requests->last()->id, now()->addDay());
        }

        return $requests;
    }
}
