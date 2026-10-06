<?php

namespace App\Actions;

use App\Models\ClientReport;
use App\Services\GoogleDriveClient;
use Illuminate\Support\Facades\Storage;

/**
 * Upload a report to the client's Drive folder: the Word file and any attachments not uploaded
 * yet. Republishing replaces the same Word file instead of adding a copy.
 */
class PublishClientReport
{
    public function __construct(private GoogleDriveClient $drive) {}

    public function handle(ClientReport $report): ClientReport
    {
        $report->loadMissing(['client', 'attachments']);
        $client = $report->client;
        abort_unless($client && filled($client->google_drive_folder_id), 422, 'Assign a Drive folder to this client first.');
        abort_unless($this->readable($report->document_path), 422, 'Open and save this report in the editor before publishing.');

        $folderId = $this->drive->ensureFolderPath((string) $client->google_drive_folder_id, [$report->title]);
        abort_if($folderId === null, 422, $this->drive->lastError() ?? 'Could not open the report folder.');

        $document = $this->put(
            $folderId,
            (string) $report->drive_document_id,
            $report->title.'.docx',
            (string) Storage::disk('local')->get((string) $report->document_path),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );
        $report->forceFill([
            'drive_document_id' => $document['id'],
            'drive_document_url' => $document['url'],
            'published_at' => now(),
        ])->save();

        foreach ($report->attachments as $attachment) {
            $path = Storage::disk('public')->path($attachment->path);
            if (filled($attachment->drive_file_id) || ! is_file($path)) {
                continue;
            }
            $file = $this->drive->uploadFile(
                $folderId,
                $attachment->original_name,
                (string) file_get_contents($path),
                'application/octet-stream',
            );
            if ($file === null) {
                continue;
            }
            $attachment->forceFill([
                'drive_file_id' => $file['id'],
                'drive_url' => $file['url'],
            ])->save();
        }

        return $report->fresh(['attachments', 'client']);
    }

    /**
     * @return array{id: string, url: string}
     */
    private function put(string $folderId, string $fileId, string $name, string $contents, string $mime): array
    {
        $file = $fileId !== ''
            ? $this->drive->replaceFile($folderId, $fileId, $name, $contents, $mime)
            : $this->drive->uploadFile($folderId, $name, $contents, $mime);
        abort_if($file === null, 422, $this->drive->lastError() ?? 'Could not upload the report.');

        return $file;
    }

    private function readable(?string $path): bool
    {
        return filled($path) && Storage::disk('local')->exists((string) $path);
    }
}
