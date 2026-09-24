<?php

namespace App\Actions;

use App\Models\Client;
use App\Services\GoogleDriveClient;

class AssignClientDriveFolder
{
    public function __construct(private GoogleDriveClient $drive) {}

    public function handle(Client $client, string $mode, ?string $folder, ?string $name = null, ?string $parent = null): Client
    {
        if ($mode === 'existing') {
            $id = $this->folderId((string) $folder);
            abort_if($id === '', 422, 'A Drive folder link is required.');
            $client->forceFill(['google_drive_folder_id' => $id])->save();

            return $client->refresh();
        }

        $folderName = trim((string) $name);
        if ($folderName === '') {
            $folderName = $client->driveCompanyFolderName();
        }
        $id = $this->drive->createFolder($folderName, $parent);
        abort_if($id === null, 422, $this->drive->lastError() ?? 'Could not create the Drive folder.');
        $client->forceFill(['google_drive_folder_id' => $id])->save();

        return $client->refresh();
    }

    private function folderId(string $value): string
    {
        $value = trim($value);
        if (preg_match('~folders/([a-zA-Z0-9_-]+)~', $value, $match) === 1) {
            return $match[1];
        }
        if (preg_match('~^[a-zA-Z0-9_-]{10,}$~', $value) === 1) {
            return $value;
        }

        return '';
    }
}
