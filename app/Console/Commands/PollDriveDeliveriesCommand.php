<?php

namespace App\Console\Commands;

use App\Actions\AlertTelegramDeliveryFailure;
use App\Actions\EnsureRequestDriveFolder;
use App\Actions\NotifyEmployees;
use App\Actions\OpenRequestForClientReview;
use App\Enums\EmployeeProfession;
use App\Enums\RequestStatus;
use App\Models\DriveDelivery;
use App\Models\ServiceRequest;
use App\Services\GoogleDriveClient;
use App\Services\RequestStatusTransitionService;
use App\Services\TelegramNotifier;
use App\Support\ResolveServiceRequest;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PollDriveDeliveriesCommand extends Command
{
    private const IDLE_MINUTES = 2;

    private const MAX_FILE_BYTES = 20 * 1024 * 1024;

    protected $signature = 'ops:poll-drive {--limit=200} {--request=} {--file=}';

    protected $description = 'Send new Google Drive files from request folders to the client bot.';

    public function handle(
        GoogleDriveClient $drive,
        TelegramNotifier $telegram,
        EnsureRequestDriveFolder $ensureRequestDriveFolder,
        OpenRequestForClientReview $openRequestForClientReview,
        RequestStatusTransitionService $transitions,
        NotifyEmployees $notifyEmployees,
        AlertTelegramDeliveryFailure $alertTelegramDeliveryFailure,
    ): int {
        if (! $drive->configured()) {
            $error = $drive->configurationError() ?? 'Google Drive is not configured.';
            $this->error($error);
            Log::error('Drive poll skipped; Google Drive is not configured.', ['error' => $error]);
            $this->alertStaffOnce($notifyEmployees, 'Google Drive غير جاهز. الملفات في المجلدات لن تصل للزبون حتى يُضبط الحساب الخدمي.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $fileId = trim((string) $this->option('file'));
        if ($fileId !== '') {
            $this->deliverNamedFile(
                $drive,
                $telegram,
                $ensureRequestDriveFolder,
                $notifyEmployees,
                $alertTelegramDeliveryFailure,
                $transitions,
                $fileId,
            );

            return self::SUCCESS;
        }

        $target = $this->targetedRequest($ensureRequestDriveFolder);
        if ($target !== null) {
            $requests = $target;
        } else {
            $this->backfillMissingFolders($ensureRequestDriveFolder, $limit);
            $requests = $this->nextFolderBatch($limit);
        }

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

            $sentThisRun = 0;
            foreach ($files as $file) {
                if ($this->deliverFile($drive, $telegram, $notifyEmployees, $alertTelegramDeliveryFailure, $transitions, $request, $file)) {
                    $sentThisRun++;
                }
            }

            if ($sentThisRun === 0) {
                $this->openWhenIdle($openRequestForClientReview, $telegram, $request->fresh(['client']) ?? $request);
            }
        }

        return self::SUCCESS;
    }

    private function deliverNamedFile(
        GoogleDriveClient $drive,
        TelegramNotifier $telegram,
        EnsureRequestDriveFolder $ensureRequestDriveFolder,
        NotifyEmployees $notifyEmployees,
        AlertTelegramDeliveryFailure $alertTelegramDeliveryFailure,
        RequestStatusTransitionService $transitions,
        string $fileId,
    ): bool {
        $meta = $drive->fileMeta($fileId);
        if ($meta === null) {
            Log::warning('Drive poll skipped missing file.', ['file' => $fileId]);

            return false;
        }

        $request = $this->requestForFile($drive, $ensureRequestDriveFolder, $meta);
        if ($request === null) {
            Log::info('Drive file has no matching request folder.', [
                'file' => $fileId,
                'parents' => $meta['parents'] ?? [],
            ]);

            return false;
        }

        return $this->deliverFile(
            $drive,
            $telegram,
            $notifyEmployees,
            $alertTelegramDeliveryFailure,
            $transitions,
            $request,
            $meta,
        );
    }

    /**
     * @param  array{id: string, name: string, mimeType: string, modifiedTime?: ?string, md5Checksum?: ?string, size?: ?int, parents?: list<string>}  $file
     */
    private function requestForFile(
        GoogleDriveClient $drive,
        EnsureRequestDriveFolder $ensureRequestDriveFolder,
        array $file,
    ): ?ServiceRequest {
        $target = $this->targetedRequest($ensureRequestDriveFolder);
        if ($target !== null && $target->isNotEmpty()) {
            return $target->first();
        }

        $cursor = (string) (($file['parents'][0] ?? ''));
        for ($i = 0; $i < 6 && $cursor !== ''; $i++) {
            $match = ServiceRequest::query()
                ->with('client')
                ->where('google_drive_folder_id', $cursor)
                ->orderByDesc('id')
                ->first();
            if ($match instanceof ServiceRequest) {
                return $match;
            }

            $cursor = (string) ($drive->parentId($cursor) ?? '');
        }

        return null;
    }

    /**
     * @param  array{id: string, name: string, mimeType: string, modifiedTime?: ?string, md5Checksum?: ?string, size?: ?int}  $file
     */
    private function deliverFile(
        GoogleDriveClient $drive,
        TelegramNotifier $telegram,
        NotifyEmployees $notifyEmployees,
        AlertTelegramDeliveryFailure $alertTelegramDeliveryFailure,
        RequestStatusTransitionService $transitions,
        ServiceRequest $request,
        array $file,
    ): bool {
        $fileId = (string) ($file['id'] ?? '');
        if ($fileId === '') {
            return false;
        }

        $delivery = DriveDelivery::query()->firstOrNew(
            ['drive_file_id' => $fileId],
            ['request_id' => $request->id],
        );

        if ($delivery->failed_at !== null) {
            return false;
        }

        $remoteHash = filled($file['md5Checksum'] ?? null) ? (string) $file['md5Checksum'] : null;
        $modifiedTime = filled($file['modifiedTime'] ?? null) ? (string) $file['modifiedTime'] : null;

        if ($delivery->exists && ! $delivery->needsResend($modifiedTime, $remoteHash, null)) {
            return false;
        }

        $size = $file['size'] ?? null;
        if (is_int($size) && $size > self::MAX_FILE_BYTES) {
            $this->markPermanentFailure($delivery, $request, $notifyEmployees, $file, 'الملف أكبر من حد تلغرام.');

            return false;
        }

        $binary = $drive->downloadFile($fileId);
        if (! is_string($binary) || $binary === '') {
            Log::warning('Drive delivery download empty.', [
                'request' => $request->number,
                'file' => $fileId,
            ]);
            $this->alertStaffOnce(
                $notifyEmployees,
                'تعذر تنزيل ملف Drive للطلب '.$request->number.' ('.((string) ($file['name'] ?? $fileId)).'). اختصارات صور Google أو الملفات غير المشاركة مع الحساب الخدمي لا تصل للبوت.',
                'ops:poll-drive:empty-dl:'.$request->number,
            );

            return false;
        }

        if (strlen($binary) > self::MAX_FILE_BYTES) {
            $this->markPermanentFailure($delivery, $request, $notifyEmployees, $file, 'الملف أكبر من حد تلغرام.');

            return false;
        }

        $hash = md5($binary);
        if ($delivery->exists && ! $delivery->needsResend($modifiedTime, $remoteHash, $hash)) {
            return false;
        }

        $delivery->forceFill([
            'request_id' => $request->id,
            'name' => $file['name'],
            'mime_type' => $file['mimeType'],
            'content_hash' => $hash,
            'drive_modified_at' => $modifiedTime ? Carbon::parse($modifiedTime) : now(),
            'sent_at' => null,
            'failed_at' => null,
            'fail_reason' => null,
        ])->save();

        $sent = false;
        $chatId = $request->client?->telegram_user_id;
        $tmp = null;
        if (! filled($chatId) || ! $telegram->configured('client')) {
            $this->alertStaffOnce(
                $notifyEmployees,
                'ملف Drive جاهز للطلب '.$request->number.' لكن بوت الزبون غير مربوط أو توكن التلجرام غير مضبوط، لذلك لم تُرسل الصورة.',
                'ops:poll-drive:no-tg:'.$request->number,
            );
        }
        if (filled($chatId) && $telegram->configured('client')) {
            $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string) $file['name'])) ?: 'file';
            $tmp = 'drive-deliveries/'.$fileId.'-'.$safeName;
            Storage::disk('local')->put($tmp, $binary);
            try {
                $fresh = $request->fresh() ?? $request;
                $ref = ResolveServiceRequest::displayNumber($fresh);
                $canComplete = $fresh->status === RequestStatus::ReadyForReview;
                $telegram->lastMessageId = null;
                $telegram->sendFile(
                    (string) $chatId,
                    Storage::disk('local')->path($tmp),
                    (string) $file['mimeType'],
                    'ملف جديد للطلب #'.$ref.': '.$file['name'],
                    'client',
                    $delivery->clientRevisionKeyboard($ref, $canComplete),
                );
                $sent = true;
                $delivery->forceFill([
                    'telegram_message_id' => $telegram->lastMessageId,
                ])->save();
                $this->markDriveActivity($transitions, $fresh);
            } catch (Throwable $exception) {
                $message = $exception->getMessage();
                Log::warning('Drive delivery telegram send failed.', [
                    'request' => $request->number,
                    'file' => $fileId,
                    'error' => $message,
                ]);
                $alertTelegramDeliveryFailure->handle(
                    $request,
                    'drive',
                    $message,
                    (string) ($file['name'] ?? $fileId),
                );
                if ($this->isPermanentTelegramFailure($message)) {
                    $this->markPermanentFailure($delivery, $request, $notifyEmployees, $file, $message);
                }
            } finally {
                Storage::disk('local')->delete($tmp);
            }
        }

        $delivery->forceFill([
            'sent_at' => $sent ? now() : null,
        ])->save();

        return $sent;
    }

    private function markDriveActivity(RequestStatusTransitionService $transitions, ServiceRequest $request): void
    {
        $request->forceFill(['drive_last_activity_at' => now()])->save();

        if ($request->status === RequestStatus::PaymentConfirmed) {
            $transitions->transition(
                $request,
                RequestStatus::InProgress,
                'drive',
                'Work files uploaded to Drive.',
            );
        }
    }

    private function openWhenIdle(
        OpenRequestForClientReview $openRequestForClientReview,
        TelegramNotifier $telegram,
        ServiceRequest $request,
    ): void {
        if (! in_array($request->status, [
            RequestStatus::PaymentConfirmed,
            RequestStatus::InProgress,
            RequestStatus::RevisionRequested,
        ], true)) {
            return;
        }

        if (! $request->driveDeliveries()->whereNotNull('sent_at')->exists()) {
            return;
        }

        $last = $request->drive_last_activity_at
            ?? $request->driveDeliveries()->whereNotNull('sent_at')->max('sent_at');
        if ($last === null || Carbon::parse($last)->gt(now()->subMinutes(self::IDLE_MINUTES))) {
            return;
        }

        $updated = $openRequestForClientReview->handle($request);
        $chatId = $updated->client?->telegram_user_id;
        if (! filled($chatId) || ! $telegram->configured('client')) {
            return;
        }

        $ref = ResolveServiceRequest::displayNumber($updated);
        $telegram->sendInlineKeyboard(
            (string) $chatId,
            'اكتملت ملفات الطلب #'.$ref.".\nيمكنك اعتماد التسليم أو طلب تعديل.",
            DriveDelivery::clientReviewKeyboard($ref, true)['inline_keyboard'],
        );
    }

    /**
     * @param  array{id?: string, name?: string}  $file
     */
    private function markPermanentFailure(
        DriveDelivery $delivery,
        ServiceRequest $request,
        NotifyEmployees $notifyEmployees,
        array $file,
        string $reason,
    ): void {
        $delivery->forceFill([
            'request_id' => $request->id,
            'name' => $file['name'] ?? $delivery->name,
            'failed_at' => now(),
            'fail_reason' => mb_substr($reason, 0, 255),
        ])->save();

        // Sales/admin already get a once-per-file card from AlertTelegramDeliveryFailure.
    }

    private function isPermanentTelegramFailure(string $message): bool
    {
        $haystack = strtolower($message);

        return str_contains($haystack, 'too big')
            || str_contains($haystack, 'file is too big')
            || str_contains($haystack, 'image_process_failed')
            || str_contains($haystack, 'photo_invalid_dimensions')
            || str_contains($haystack, 'unsupported');
    }

    private function alertStaffOnce(NotifyEmployees $notifyEmployees, string $text, string $key = 'ops:poll-drive:unconfigured'): void
    {
        if (! Cache::add($key, true, now()->addHour())) {
            return;
        }

        $notifyEmployees->handlePlain(EmployeeProfession::Sales, $text);
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
            ->orderByDesc('id')
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
     * @return EloquentCollection<int, ServiceRequest>|null
     */
    private function targetedRequest(EnsureRequestDriveFolder $ensureRequestDriveFolder): ?EloquentCollection
    {
        $reference = trim((string) $this->option('request'));
        if ($reference === '') {
            return null;
        }

        try {
            $request = ctype_digit($reference)
                ? ServiceRequest::query()->with('client')->find((int) $reference)
                : app(ResolveServiceRequest::class)->byReference($reference)->load('client');
        } catch (ModelNotFoundException) {
            $request = null;
        }

        if ($request === null) {
            $this->error('Request not found: '.$reference);

            return new EloquentCollection;
        }

        if (blank($request->google_drive_folder_id)) {
            try {
                $request = $ensureRequestDriveFolder->handle($request)->load('client');
            } catch (Throwable $exception) {
                Log::warning('Drive folder target backfill failed.', [
                    'request' => $request->number,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return new EloquentCollection([$request]);
    }

    /**
     * @return EloquentCollection<int, ServiceRequest>
     */
    private function nextFolderBatch(int $limit)
    {
        return ServiceRequest::query()
            ->with('client')
            ->whereNotNull('google_drive_folder_id')
            ->where('google_drive_folder_id', '!=', '')
            ->whereIn('status', [
                RequestStatus::PaymentConfirmed,
                RequestStatus::InProgress,
                RequestStatus::RevisionRequested,
                RequestStatus::ReadyForReview,
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
