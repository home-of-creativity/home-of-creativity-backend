<?php

namespace App\Actions;

use App\Models\ServiceRequest;
use App\Services\GoogleDriveClient;
use Illuminate\Support\Facades\Log;

class EnsureRequestDriveFolder
{
    public function __construct(private GoogleDriveClient $drive) {}

    public function handle(ServiceRequest $request): ServiceRequest
    {
        if (filled($request->google_drive_folder_id)) {
            return $request;
        }

        if (! $this->drive->configured()) {
            Log::warning('Google Drive folder skipped; credentials or parent folder missing.', [
                'request' => $request->number,
            ]);

            return $request;
        }

        $request->loadMissing('client');
        $company = $request->client?->driveCompanyFolderName() ?? 'شركة';
        $task = trim($request->title.' '.now()->format('Y-m-d'));
        $parent = (string) config('services.google.drive_parent_folder_id');

        $folderId = $this->drive->ensureFolderPath($parent, $company, $task);
        if ($folderId) {
            $request->forceFill(['google_drive_folder_id' => $folderId])->save();
        } else {
            Log::warning('Google Drive folder create returned empty.', [
                'request' => $request->number,
            ]);
        }

        return $request->fresh() ?? $request;
    }
}
