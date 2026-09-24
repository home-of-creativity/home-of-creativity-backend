<?php

namespace App\Actions;

use App\Models\ClientReport;
use App\Services\GoogleDriveClient;
use App\Support\ReportImage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class PublishClientReport
{
    public function __construct(private GoogleDriveClient $drive) {}

    public function handle(ClientReport $report): ClientReport
    {
        $report->loadMissing(['client', 'attachments']);
        $client = $report->client;
        abort_unless($client && filled($client->google_drive_folder_id), 422, 'Assign a Drive folder to this client first.');

        $folderId = $this->drive->ensureFolderPath((string) $client->google_drive_folder_id, [$report->title]);
        abort_if($folderId === null, 422, $this->drive->lastError() ?? 'Could not open the report folder.');

        $cover = filled($report->cover_path) ? Storage::disk('public')->path($report->cover_path) : null;
        $watermark = filled($report->watermark_path) ? Storage::disk('public')->path($report->watermark_path) : null;
        $pdf = Pdf::loadView('reports.client', [
            'title' => $report->title,
            'header' => $report->header,
            'footer' => $report->footer,
            'body' => $report->body,
            'cover' => $cover && is_file($cover) ? ReportImage::pdfPath($cover) : null,
            'watermark' => $watermark && is_file($watermark) ? ReportImage::pdfPath($watermark) : null,
        ])->setPaper('a4');

        $uploaded = $this->drive->uploadFile($folderId, $report->title.'.pdf', $pdf->output(), 'application/pdf');
        abort_if($uploaded === null, 422, $this->drive->lastError() ?? 'Could not upload the report.');

        $report->forceFill([
            'drive_file_id' => $uploaded['id'],
            'drive_url' => $uploaded['url'],
        ])->save();

        foreach ($report->attachments as $attachment) {
            $path = Storage::disk('public')->path($attachment->path);
            if (! is_file($path)) {
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
}
