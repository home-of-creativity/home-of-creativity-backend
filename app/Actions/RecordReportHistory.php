<?php

namespace App\Actions;

use App\Models\ClientReport;
use App\Models\ClientReportVersion;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * The report's version history. Autosave sends the Word file every few seconds, so saves by
 * the same person within SESSION_MINUTES of each other fold into one row whose copy of the
 * file is replaced each time, like Google Docs. Publishing and restoring each keep their own copy.
 */
class RecordReportHistory
{
    public const SESSION_MINUTES = 10;

    /** Older editing sessions keep their row but lose their Word copy. */
    public const KEEP_SESSION_COPIES = 60;

    private const DISK = 'local';

    public function saved(ClientReport $report, ?User $user, bool $created = false): ClientReportVersion
    {
        /** @var ClientReportVersion|null $last */
        $last = $report->versions()->latest('id')->first();
        $open = ! $created
            && $last !== null
            && in_array($last->event, [ClientReportVersion::CREATED, ClientReportVersion::EDITED], true)
            && $last->user_id === $user?->id
            && $last->updated_at?->gt(now()->subMinutes(self::SESSION_MINUTES));

        if ($open) {
            $last->forceFill([
                'title' => $report->title,
                'body' => $report->body,
                'saves' => $last->saves + 1,
            ]);
            $this->snapshot($report, $last);
            $last->save();

            return $last;
        }

        $version = $this->record($report, $user, $created ? ClientReportVersion::CREATED : ClientReportVersion::EDITED, null, true);
        $this->prune($report);

        return $version;
    }

    public function published(ClientReport $report, ?User $user): ClientReportVersion
    {
        return $this->record($report, $user, ClientReportVersion::PUBLISHED, array_filter([
            'drive_document_url' => $report->drive_document_url,
        ]), true);
    }

    /** @param  list<string>  $names */
    public function attachments(ClientReport $report, ?User $user, string $event, array $names): void
    {
        if ($names !== []) {
            $this->record($report, $user, $event, ['files' => array_values($names)], false);
        }
    }

    /** Keeps a delete row on the client's history; the report folder and its copies go with the report. */
    public function deleted(ClientReport $report, ?User $user): void
    {
        $report->versions()->update(['document_path' => null]);
        $this->record($report, $user, ClientReportVersion::DELETED, null, false);
    }

    /** Makes an older copy the current Word file and records the restore as a new version. */
    public function restore(ClientReportVersion $version, ?User $user): ClientReport
    {
        $report = $version->report;
        abort_if($report === null, 404, 'This report was deleted.');
        abort_unless($this->exists($version->document_path), 404, 'This version no longer has its Word file.');

        $path = 'reports/'.$report->id.'/report.docx';
        Storage::disk(self::DISK)->put($path, (string) Storage::disk(self::DISK)->get((string) $version->document_path));
        // The stored PDF belongs to the replaced file; the next save or publish makes a new one.
        if (filled($report->pdf_path)) {
            Storage::disk(self::DISK)->delete($report->pdf_path);
        }
        $report->forceFill([
            'title' => $version->title,
            'body' => $version->body ?? $report->body,
            'document_path' => $path,
            'pdf_path' => null,
        ])->save();

        $this->record($report, $user, ClientReportVersion::RESTORED, [
            'from_version' => $version->id,
            'from_event' => $version->event,
            'from_at' => $version->updated_at?->toIso8601String(),
        ], true);

        return $report->fresh(['attachments', 'client']) ?? $report;
    }

    /** @param  array<string, mixed>|null  $detail */
    private function record(ClientReport $report, ?User $user, string $event, ?array $detail, bool $withCopy): ClientReportVersion
    {
        $version = $report->versions()->create([
            'client_id' => $report->client_id,
            'user_id' => $user?->id,
            'event' => $event,
            'title' => $report->title,
            'body' => $withCopy ? $report->body : null,
            'detail' => $detail ?: null,
            'started_at' => now(),
        ]);
        if ($withCopy) {
            $this->snapshot($report, $version);
            $version->save();
        }

        return $version;
    }

    private function snapshot(ClientReport $report, ClientReportVersion $version): void
    {
        if (! $this->exists($report->document_path)) {
            return;
        }
        $bytes = (string) Storage::disk(self::DISK)->get((string) $report->document_path);
        $path = 'reports/'.$report->id.'/versions/'.$version->id.'.docx';
        Storage::disk(self::DISK)->put($path, $bytes);
        $version->forceFill(['document_path' => $path, 'size' => strlen($bytes)]);
    }

    private function prune(ClientReport $report): void
    {
        $sessions = fn () => $report->versions()
            ->whereIn('event', [ClientReportVersion::CREATED, ClientReportVersion::EDITED])
            ->whereNotNull('document_path');
        $keep = $sessions()->latest('id')->limit(self::KEEP_SESSION_COPIES)->pluck('id');

        $sessions()
            ->whereNotIn('id', $keep)
            ->get()
            ->each(function (ClientReportVersion $old): void {
                Storage::disk(self::DISK)->delete((string) $old->document_path);
                $old->forceFill(['document_path' => null])->save();
            });
    }

    private function exists(?string $path): bool
    {
        return filled($path) && Storage::disk(self::DISK)->exists((string) $path);
    }
}
