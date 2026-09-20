<?php

namespace App\Actions;

use App\Models\DriveDelivery;
use App\Models\ServiceRequest;
use App\Support\ResolveServiceRequest;
use Illuminate\Validation\ValidationException;

class ApproveDriveDelivery
{
    public function __construct(private NotifyStaffDriveFile $notifyStaffDriveFile) {}

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
        $this->notifyStaffDriveFile->handle(
            $fresh,
            "✅ وافق الزبون على هذه الصورة\n#{$ref} — {$fresh->title}\n{$fresh->client?->name}\n{$name}",
            $delivery,
        );

        return $delivery->fresh() ?? $delivery;
    }
}
