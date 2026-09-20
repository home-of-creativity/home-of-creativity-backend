<?php

namespace App\Actions;

use App\Models\OpsSetting;
use App\Services\GoogleDriveClient;
use Illuminate\Support\Facades\Artisan;

class ProcessDriveChangeNotification
{
    public function __construct(private GoogleDriveClient $drive) {}

    /**
     * @return array{polled: bool, drive_file_ids: list<string>, fallback: bool}
     */
    public function handle(?string $fileId = null): array
    {
        $fileId = trim((string) $fileId);
        if ($fileId !== '') {
            Artisan::call('ops:poll-drive', ['--file' => $fileId]);

            return [
                'polled' => true,
                'drive_file_ids' => [$fileId],
                'fallback' => false,
            ];
        }

        $ids = [];
        $pageToken = (string) OpsSetting::getValue('drive_watch_page_token', '');
        if ($pageToken !== '' && $this->drive->configured()) {
            $listed = $this->drive->listChanges($pageToken);
            if ($listed !== null) {
                OpsSetting::setValue('drive_watch_page_token', $listed['newPageToken']);
                foreach ($listed['files'] as $file) {
                    if (! $this->drive->isUnderParentFolder($file)) {
                        continue;
                    }

                    if (($file['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
                        continue;
                    }

                    if ($this->drive->isDirectlyInHocClientRoot($file)) {
                        continue;
                    }

                    $ids[] = $file['id'];
                }
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [
                'polled' => false,
                'drive_file_ids' => [],
                'fallback' => false,
            ];
        }

        foreach ($ids as $id) {
            Artisan::call('ops:poll-drive', ['--file' => $id]);
        }

        return [
            'polled' => true,
            'drive_file_ids' => $ids,
            'fallback' => false,
        ];
    }
}
