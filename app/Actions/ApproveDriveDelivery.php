<?php

namespace App\Actions;

use App\Enums\RequestStatus;
use App\Models\DriveDelivery;
use App\Models\ServiceRequest;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApproveDriveDelivery
{
    public function __construct(
        private NotifyStaffDriveFile $notifyStaffDriveFile,
        private OpenRequestForClientReview $openRequestForClientReview,
    ) {}

    public function handle(ServiceRequest $request, DriveDelivery $delivery): DriveDelivery
    {
        if ((int) $delivery->request_id !== (int) $request->id) {
            throw ValidationException::withMessages([
                'drive_delivery_id' => 'هذا الملف لا يتبع هذا الطلب.',
            ]);
        }

        if ($delivery->sent_at === null) {
            throw ValidationException::withMessages([
                'drive_delivery_id' => 'هذا الملف لم يصل بعد للزبون.',
            ]);
        }

        if ($delivery->client_approved_at === null) {
            $delivery->forceFill(['client_approved_at' => now()])->save();
        }

        $fresh = $request->fresh(['client']) ?? $request;
        $ref = ResolveServiceRequest::displayNumber($fresh);
        $name = (string) ($delivery->name ?: $delivery->drive_file_id);

        try {
            $this->notifyStaffDriveFile->handle(
                $fresh,
                "✅ وافق الزبون على هذه الصورة\n#{$ref} — {$fresh->title}\n{$fresh->client?->name}\n{$name}",
                $delivery,
            );
        } catch (Throwable $exception) {
            Log::warning('Client file approval saved but staff notify failed.', [
                'request' => $fresh->number,
                'delivery' => $delivery->id,
                'error' => $exception->getMessage(),
            ]);
        }

        if ($this->allSentFilesApproved($fresh)) {
            $this->openRequestForClientReview->handle($fresh, 'Client approved every delivered file.');
        }

        return $delivery->fresh() ?? $delivery;
    }

    public function remainingUnapproved(ServiceRequest $request): int
    {
        return DriveDelivery::query()
            ->where('request_id', $request->id)
            ->whereNotNull('sent_at')
            ->whereNull('client_approved_at')
            ->count();
    }

    private function allSentFilesApproved(ServiceRequest $request): bool
    {
        return DriveDelivery::query()
            ->where('request_id', $request->id)
            ->whereNotNull('sent_at')
            ->exists()
            && $this->remainingUnapproved($request) === 0;
    }

    public function canComplete(ServiceRequest $request): bool
    {
        $fresh = $request->fresh() ?? $request;

        return $fresh->status === RequestStatus::ReadyForReview
            && $this->remainingUnapproved($fresh) === 0;
    }
}
