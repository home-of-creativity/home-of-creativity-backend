<?php

namespace App\Actions;

use App\Models\ServiceRequest;
use App\Services\GoogleDriveClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EnsureRequestDriveFolder
{
    public function __construct(private GoogleDriveClient $drive) {}

    public function handle(ServiceRequest $request): ServiceRequest
    {
        if (filled($request->google_drive_folder_id)) {
            return $request;
        }

        $request->loadMissing('client');
        $company = $request->client?->driveCompanyFolderName() ?? 'شركة';
        $folderDate = ($request->paid_at ?? now())->timezone((string) config('app.timezone'))->format('Y-m-d');
        $task = trim($request->title.' '.$folderDate);
        $parent = (string) config('services.google.drive_parent_folder_id');

        $folderId = $this->drive->ensureFolderPath($parent, $company, $task);
        if ($folderId) {
            $request->forceFill(['google_drive_folder_id' => $folderId])->save();

            return $request->fresh() ?? $request;
        }

        $reason = $this->drive->lastError() ?? 'Google Drive folder could not be created.';
        Log::warning('Google Drive folder skipped.', [
            'request' => $request->number,
            'error' => $reason,
        ]);

        throw ValidationException::withMessages([
            'drive' => $reason,
        ]);
    }
}
