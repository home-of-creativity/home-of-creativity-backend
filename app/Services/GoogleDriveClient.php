<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleDriveClient
{
    private const API = 'https://www.googleapis.com/drive/v3';

    public function __construct(private GoogleServiceAccount $auth) {}

    public function configured(): bool
    {
        return $this->auth->configured()
            && filled(config('services.google.drive_parent_folder_id'));
    }

    public function ensureFolderPath(string $parentId, string $company, string $taskFolderName): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return null;
        }

        try {
            $rootParent = $parentId !== ''
                ? $parentId
                : (string) config('services.google.drive_parent_folder_id');

            $companyFolderId = $this->findOrCreateFolder($token, $rootParent, $this->safeName($company));
            if ($companyFolderId === null) {
                return null;
            }

            return $this->findOrCreateFolder($token, $companyFolderId, $this->safeName($taskFolderName));
        } catch (Throwable $exception) {
            Log::warning('Google Drive ensureFolderPath failed.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
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

    private function findOrCreateFolder(string $token, string $parentId, string $name): ?string
    {
        $query = sprintf(
            "name = '%s' and '%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
            str_replace("'", "\\'", $name),
            str_replace("'", "\\'", $parentId),
        );

        $existing = Http::withToken($token)
            ->timeout(15)
            ->acceptJson()
            ->get(self::API.'/files', [
                'q' => $query,
                'fields' => 'files(id,name)',
                'pageSize' => 1,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ]);

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
            ->post(self::API.'/files?supportsAllDrives=true', [
                'name' => $name,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [$parentId],
            ]);

        if (! $created->successful()) {
            Log::warning('Google Drive folder create failed.', [
                'name' => $name,
                'status' => $created->status(),
                'body' => $created->body(),
            ]);

            return null;
        }

        $id = $created->json('id');

        return filled($id) ? (string) $id : null;
    }

    private function safeName(string $name): string
    {
        $trimmed = trim($name);

        return $trimmed !== '' ? mb_substr($trimmed, 0, 180) : 'untitled';
    }
}
