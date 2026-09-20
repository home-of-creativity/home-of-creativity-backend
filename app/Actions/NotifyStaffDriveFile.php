<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Models\DriveDelivery;
use App\Models\ServiceRequest;
use App\Services\GoogleDriveClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class NotifyStaffDriveFile
{
    public function __construct(
        private NotifyEmployees $notifyEmployees,
        private GoogleDriveClient $drive,
    ) {}

    public function handle(ServiceRequest $request, string $text, ?DriveDelivery $delivery = null): void
    {
        $attachment = $this->attachment($delivery);

        try {
            foreach ($this->professions($request) as $profession) {
                $this->notifyEmployees->handle($request, $profession, $text, null, $attachment);
            }
        } finally {
            if ($attachment !== null) {
                $relative = $attachment['relative'] ?? null;
                if (is_string($relative) && $relative !== '') {
                    Storage::disk('local')->delete($relative);
                }
            }
        }
    }

    /**
     * @return array{path: string, mime?: string|null, name?: string|null, relative?: string}|null
     */
    private function attachment(?DriveDelivery $delivery): ?array
    {
        if ($delivery === null || ! filled($delivery->drive_file_id) || ! $this->drive->configured()) {
            return null;
        }

        try {
            $binary = $this->drive->downloadFile((string) $delivery->drive_file_id);
        } catch (Throwable $exception) {
            Log::warning('Staff Drive notify download failed.', [
                'file' => $delivery->drive_file_id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! is_string($binary) || $binary === '') {
            return null;
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string) ($delivery->name ?: 'file'))) ?: 'file';
        $relative = 'drive-staff-notify/'.$delivery->drive_file_id.'-'.$safeName;
        Storage::disk('local')->put($relative, $binary);

        return [
            'path' => Storage::disk('local')->path($relative),
            'relative' => $relative,
            'mime' => $delivery->mime_type,
            'name' => $delivery->name,
        ];
    }

    /**
     * @return list<EmployeeProfession>
     */
    private function professions(ServiceRequest $request): array
    {
        $map = [
            'design' => EmployeeProfession::Design,
            'content' => EmployeeProfession::Content,
            'programming' => EmployeeProfession::Web,
            'photography' => EmployeeProfession::Media,
        ];
        $found = [EmployeeProfession::Sales];
        foreach ($request->plannedDepartments() as $department) {
            if (isset($map[$department]) && ! in_array($map[$department], $found, true)) {
                $found[] = $map[$department];
            }
        }

        return $found;
    }
}
