<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleDriveClient
{
    private const API = 'https://www.googleapis.com/drive/v3';

    private ?string $lastError = null;

    public function __construct(private GoogleServiceAccount $auth) {}

    public function configured(): bool
    {
        return $this->configurationError() === null;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function configurationError(): ?string
    {
        if (! $this->auth->configured()) {
            return 'Google service account JSON is missing or invalid.';
        }

        if (! filled($this->parentFolderId())) {
            return 'GOOGLE_DRIVE_PARENT_FOLDER_ID is missing.';
        }

        return null;
    }

    public function ensureFolderPath(string $parentId, string $company, string $taskFolderName): ?string
    {
        $this->lastError = null;

        if ($error = $this->configurationError()) {
            return $this->fail($error);
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return $this->fail('Google service account could not get an access token. Check the private key.');
        }

        try {
            $rootParent = $parentId !== '' ? $parentId : $this->parentFolderId();
            $context = $this->parentContext($token, $rootParent);
            if ($context === null) {
                return null;
            }

            $companyFolderId = $this->findOrCreateFolder(
                $token,
                $context['id'],
                $this->safeName($company),
                $context['driveId'],
            );
            if ($companyFolderId === null) {
                return null;
            }

            $taskFolderId = $this->findOrCreateFolder(
                $token,
                $companyFolderId,
                $this->safeName($taskFolderName),
                $context['driveId'],
            );

            return $taskFolderId ?? $companyFolderId;
        } catch (Throwable $exception) {
            return $this->fail('Google Drive ensureFolderPath failed: '.$exception->getMessage());
        }
    }

    /**
     * @return list<array{id: string, name: string, mimeType: string}>
     */
    public function listNewFiles(string $folderId): array
    {
        if (! $this->configured() || $folderId === '') {
            return [];
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return [];
        }

        try {
            $query = sprintf(
                "'%s' in parents and trashed = false and mimeType != 'application/vnd.google-apps.folder'",
                str_replace("'", "\\'", $folderId),
            );

            $response = Http::withToken($token)
                ->timeout(20)
                ->acceptJson()
                ->get(self::API.'/files', [
                    'q' => $query,
                    'fields' => 'files(id,name,mimeType)',
                    'pageSize' => 100,
                    'corpora' => 'allDrives',
                    'supportsAllDrives' => 'true',
                    'includeItemsFromAllDrives' => 'true',
                ]);

            if (! $response->successful()) {
                Log::warning('Google Drive listNewFiles failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $files = $response->json('files');
            if (! is_array($files)) {
                return [];
            }

            $result = [];
            foreach ($files as $file) {
                if (! is_array($file) || ! filled($file['id'] ?? null)) {
                    continue;
                }
                $result[] = [
                    'id' => (string) $file['id'],
                    'name' => (string) ($file['name'] ?? $file['id']),
                    'mimeType' => (string) ($file['mimeType'] ?? 'application/octet-stream'),
                ];
            }

            return $result;
        } catch (Throwable $exception) {
            Log::warning('Google Drive listNewFiles exception.', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    public function downloadFile(string $fileId): ?string
    {
        if (! $this->configured() || $fileId === '') {
            return null;
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return null;
        }

        try {
            $meta = Http::withToken($token)
                ->timeout(15)
                ->acceptJson()
                ->get(self::API.'/files/'.$fileId, [
                    'fields' => 'id,mimeType',
                    'supportsAllDrives' => 'true',
                ]);

            $mime = (string) $meta->json('mimeType', '');
            if (str_starts_with($mime, 'application/vnd.google-apps.')) {
                $exportMime = $mime === 'application/vnd.google-apps.document'
                    ? 'application/pdf'
                    : 'application/pdf';
                $response = Http::withToken($token)
                    ->timeout(60)
                    ->get(self::API.'/files/'.$fileId.'/export', [
                        'mimeType' => $exportMime,
                    ]);
            } else {
                $response = Http::withToken($token)
                    ->timeout(60)
                    ->get(self::API.'/files/'.$fileId, [
                        'alt' => 'media',
                        'supportsAllDrives' => 'true',
                    ]);
            }

            if (! $response->successful()) {
                Log::warning('Google Drive downloadFile failed.', [
                    'file_id' => $fileId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $body = $response->body();

            return $body !== '' ? $body : null;
        } catch (Throwable $exception) {
            Log::warning('Google Drive downloadFile exception.', [
                'file_id' => $fileId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{id: string, driveId: ?string}|null
     */
    private function parentContext(string $token, string $parentId): ?array
    {
        $meta = Http::withToken($token)
            ->timeout(15)
            ->acceptJson()
            ->get(self::API.'/files/'.$parentId, [
                'fields' => 'id,driveId,mimeType',
                'supportsAllDrives' => 'true',
            ]);

        if (! $meta->successful()) {
            $this->fail($this->googleErrorMessage(
                $meta,
                'Cannot open the parent Drive folder. Share Hoc Client with the service account as Content manager.',
            ));

            return null;
        }

        $driveId = $meta->json('driveId');

        return [
            'id' => (string) $meta->json('id', $parentId),
            'driveId' => filled($driveId) ? (string) $driveId : null,
        ];
    }

    private function findOrCreateFolder(string $token, string $parentId, string $name, ?string $driveId = null): ?string
    {
        $query = sprintf(
            "name = '%s' and '%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
            str_replace("'", "\\'", $name),
            str_replace("'", "\\'", $parentId),
        );

        $search = [
            'q' => $query,
            'fields' => 'files(id,name)',
            'pageSize' => 1,
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ];
        if ($driveId) {
            $search['corpora'] = 'drive';
            $search['driveId'] = $driveId;
        }

        $existing = Http::withToken($token)
            ->timeout(15)
            ->acceptJson()
            ->get(self::API.'/files', $search);

        if ($existing->successful()) {
            $id = data_get($existing->json(), 'files.0.id');
            if (filled($id)) {
                return (string) $id;
            }
        }

        $created = Http::withToken($token)
            ->timeout(20)
            ->acceptJson()
            ->asJson()
            ->post(self::API.'/files?supportsAllDrives=true&fields=id', [
                'name' => $name,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [$parentId],
            ]);

        if (! $created->successful()) {
            $this->fail($this->googleErrorMessage(
                $created,
                'Google Drive rejected folder create. Share the parent folder with the service account.',
            ));

            return null;
        }

        $id = $created->json('id');

        return filled($id) ? (string) $id : $this->fail('Google Drive created a folder without an id.');
    }

    private function parentFolderId(): string
    {
        return trim((string) config('services.google.drive_parent_folder_id'), " \t\n\r\"'");
    }

    private function googleErrorMessage(Response $response, string $fallback): string
    {
        $message = $response->json('error.message');
        $status = $response->json('error.status') ?? $response->status();

        return $fallback.(is_string($message) && $message !== '' ? ' '.$message : '').' ['.$status.']';
    }

    private function fail(string $message): null
    {
        $this->lastError = $message;
        Log::warning('Google Drive: '.$message);

        return null;
    }

    private function safeName(string $name): string
    {
        $trimmed = trim($name);

        return $trimmed !== '' ? mb_substr($trimmed, 0, 180) : 'untitled';
    }
}
