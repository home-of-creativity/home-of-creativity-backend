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
            return $this->auth->configurationError() ?? 'Google service account JSON is missing or invalid.';
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
     * @return list<array{id: string, name: string, mimeType: string, modifiedTime: ?string, md5Checksum: ?string, size: ?int}>
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
            $seen = [];
            $this->collectDownloadableFiles($token, $folderId, 0, true, $seen);

            $parentId = $this->immediateParentId($token, $folderId);
            $root = $this->parentFolderId();
            if (filled($parentId) && $parentId !== $folderId && $parentId !== $root) {
                $this->collectDownloadableFiles($token, $parentId, 0, false, $seen);
            }

            return array_values($seen);
        } catch (Throwable $exception) {
            Log::warning('Google Drive listNewFiles exception.', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array{id: string, name: string, mimeType: string, modifiedTime: ?string, md5Checksum: ?string, size: ?int, parents: list<string>}|null
     */
    public function fileMeta(string $fileId): ?array
    {
        if (! $this->configured() || $fileId === '') {
            return null;
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->connectTimeout(5)
                ->acceptJson()
                ->get(self::API.'/files/'.$fileId, [
                    'fields' => 'id,name,mimeType,modifiedTime,md5Checksum,size,parents,shortcutDetails',
                    'supportsAllDrives' => 'true',
                ]);

            if (! $response->successful()) {
                Log::warning('Google Drive fileMeta failed.', [
                    'file_id' => $fileId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $normalized = $this->normalizeListedFile($response->json() ?? []);
            if ($normalized === null) {
                return null;
            }

            $parents = $response->json('parents');
            $normalized['parents'] = is_array($parents)
                ? array_values(array_filter(array_map(strval(...), $parents)))
                : [];

            return $normalized;
        } catch (Throwable $exception) {
            Log::warning('Google Drive fileMeta exception.', [
                'file_id' => $fileId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function parentId(string $folderId): ?string
    {
        if (! $this->configured() || $folderId === '') {
            return null;
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return null;
        }

        return $this->immediateParentId($token, $folderId);
    }

    /**
     * @param  array{id?: string, parents?: list<string>}  $file
     */
    public function isUnderParentFolder(array $file): bool
    {
        $root = $this->parentFolderId();
        if ($root === '') {
            return true;
        }

        $id = (string) ($file['id'] ?? '');
        if ($id === $root) {
            return true;
        }

        $parents = array_values(array_filter(array_map(strval(...), $file['parents'] ?? [])));
        if (in_array($root, $parents, true)) {
            return true;
        }

        $cursor = $parents[0] ?? $id;
        for ($i = 0; $i < 8 && $cursor !== ''; $i++) {
            if ($cursor === $root) {
                return true;
            }

            $next = $this->parentId($cursor);
            if (! filled($next)) {
                return false;
            }

            if ($next === $root) {
                return true;
            }

            $cursor = $next;
        }

        return false;
    }

    public function startPageToken(): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return null;
        }

        $response = Http::withToken($token)
            ->timeout(15)
            ->connectTimeout(5)
            ->acceptJson()
            ->get(self::API.'/changes/startPageToken', [
                'supportsAllDrives' => 'true',
            ]);

        if (! $response->successful()) {
            return $this->fail($this->googleErrorMessage($response, 'Drive startPageToken failed.'));
        }

        $pageToken = $response->json('startPageToken');

        return filled($pageToken) ? (string) $pageToken : null;
    }

    /**
     * @return array{id: string, resourceId: string, expiration: ?string}|null
     */
    public function watchChanges(string $pageToken, string $address, string $channelId, string $channelToken): ?array
    {
        if (! $this->configured() || $pageToken === '' || $address === '') {
            return null;
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return null;
        }

        $response = Http::withToken($token)
            ->timeout(20)
            ->connectTimeout(5)
            ->acceptJson()
            ->asJson()
            ->post(self::API.'/changes/watch?'.http_build_query([
                'pageToken' => $pageToken,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ]), [
                'id' => $channelId,
                'type' => 'web_hook',
                'address' => $address,
                'token' => $channelToken,
                'expiration' => (string) (now()->addHours(20)->timestamp * 1000),
            ]);

        if (! $response->successful()) {
            return $this->fail($this->googleErrorMessage($response, 'Drive changes.watch failed.'));
        }

        $id = $response->json('id');
        $resourceId = $response->json('resourceId');
        if (! filled($id) || ! filled($resourceId)) {
            return $this->fail('Drive watch response missing channel ids.');
        }

        return [
            'id' => (string) $id,
            'resourceId' => (string) $resourceId,
            'expiration' => filled($response->json('expiration')) ? (string) $response->json('expiration') : null,
        ];
    }

    public function stopChannel(string $channelId, string $resourceId): void
    {
        if (! $this->configured() || $channelId === '' || $resourceId === '') {
            return;
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return;
        }

        Http::withToken($token)
            ->timeout(15)
            ->connectTimeout(5)
            ->acceptJson()
            ->asJson()
            ->post(self::API.'/channels/stop', [
                'id' => $channelId,
                'resourceId' => $resourceId,
            ]);
    }

    /**
     * @return array{newPageToken: string, files: list<array{id: string, name: string, mimeType: string, parents: list<string>}>}|null
     */
    public function listChanges(string $pageToken): ?array
    {
        if (! $this->configured() || $pageToken === '') {
            return null;
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return null;
        }

        $files = [];
        $cursor = $pageToken;
        $newPageToken = $pageToken;

        for ($page = 0; $page < 5 && $cursor !== ''; $page++) {
            $response = Http::withToken($token)
                ->timeout(20)
                ->connectTimeout(5)
                ->acceptJson()
                ->get(self::API.'/changes', [
                    'pageToken' => $cursor,
                    'pageSize' => 100,
                    'supportsAllDrives' => 'true',
                    'includeItemsFromAllDrives' => 'true',
                    'fields' => 'nextPageToken,newStartPageToken,changes(removed,fileId,file(id,name,mimeType,parents,trashed))',
                ]);

            if (! $response->successful()) {
                return $this->fail($this->googleErrorMessage($response, 'Drive changes.list failed.'));
            }

            foreach ($response->json('changes') ?? [] as $change) {
                if (! is_array($change) || ($change['removed'] ?? false) === true) {
                    continue;
                }

                $file = is_array($change['file'] ?? null) ? $change['file'] : [];
                if (($file['trashed'] ?? false) === true) {
                    continue;
                }

                $id = (string) ($file['id'] ?? $change['fileId'] ?? '');
                if ($id === '') {
                    continue;
                }

                $parents = $file['parents'] ?? [];
                $files[] = [
                    'id' => $id,
                    'name' => (string) ($file['name'] ?? ''),
                    'mimeType' => (string) ($file['mimeType'] ?? ''),
                    'parents' => is_array($parents)
                        ? array_values(array_filter(array_map(strval(...), $parents)))
                        : [],
                ];
            }

            $next = $response->json('nextPageToken');
            $start = $response->json('newStartPageToken');
            if (filled($start)) {
                $newPageToken = (string) $start;
            }

            $cursor = filled($next) ? (string) $next : '';
        }

        return [
            'newPageToken' => $newPageToken,
            'files' => $files,
        ];
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
            $resolved = $this->resolveDownloadableMeta($token, $fileId);
            if ($resolved === null) {
                return null;
            }

            $resolvedId = $resolved['id'];
            $mime = $resolved['mimeType'];
            $exportMime = $this->exportMimeFor($mime);

            if ($exportMime !== null) {
                $response = Http::withToken($token)
                    ->timeout(60)
                    ->get(self::API.'/files/'.$resolvedId.'/export', [
                        'mimeType' => $exportMime,
                        'supportsAllDrives' => 'true',
                    ]);
            } else {
                $response = Http::withToken($token)
                    ->timeout(60)
                    ->get(self::API.'/files/'.$resolvedId, [
                        'alt' => 'media',
                        'supportsAllDrives' => 'true',
                        'acknowledgeAbuse' => 'true',
                    ]);
            }

            if (! $response->successful()) {
                Log::warning('Google Drive downloadFile failed.', [
                    'file_id' => $fileId,
                    'resolved_id' => $resolvedId,
                    'mime' => $mime,
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
     * @param  array<string, array{id: string, name: string, mimeType: string, modifiedTime: ?string, md5Checksum: ?string, size: ?int}>  $seen
     */
    private function collectDownloadableFiles(string $token, string $folderId, int $depth, bool $recurse, array &$seen): void
    {
        if ($folderId === '' || $depth > 3 || count($seen) >= 200) {
            return;
        }

        $pageToken = null;

        do {
            $params = [
                'q' => sprintf("'%s' in parents and trashed = false", str_replace("'", "\\'", $folderId)),
                'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime,md5Checksum,size,shortcutDetails)',
                'pageSize' => 100,
                'corpora' => 'allDrives',
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ];
            if (is_string($pageToken) && $pageToken !== '') {
                $params['pageToken'] = $pageToken;
            }

            $response = Http::withToken($token)
                ->timeout(20)
                ->acceptJson()
                ->get(self::API.'/files', $params);

            if (! $response->successful()) {
                Log::warning('Google Drive listNewFiles failed.', [
                    'folder' => $folderId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return;
            }

            $files = $response->json('files');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (! is_array($file) || ! filled($file['id'] ?? null) || count($seen) >= 200) {
                        continue;
                    }

                    $mime = (string) ($file['mimeType'] ?? 'application/octet-stream');
                    if ($mime === 'application/vnd.google-apps.folder') {
                        if ($recurse) {
                            $this->collectDownloadableFiles($token, (string) $file['id'], $depth + 1, true, $seen);
                        }

                        continue;
                    }

                    $normalized = $this->normalizeListedFile($file);
                    if ($normalized === null || isset($seen[$normalized['id']])) {
                        continue;
                    }

                    $seen[$normalized['id']] = $normalized;
                }
            }

            $pageToken = $response->json('nextPageToken');
        } while (is_string($pageToken) && $pageToken !== '');
    }

    /**
     * @param  array<string, mixed>  $file
     * @return array{id: string, name: string, mimeType: string, modifiedTime: ?string, md5Checksum: ?string, size: ?int}|null
     */
    private function normalizeListedFile(array $file): ?array
    {
        $id = (string) ($file['id'] ?? '');
        $mime = (string) ($file['mimeType'] ?? 'application/octet-stream');
        $name = (string) ($file['name'] ?? $id);

        if ($mime === 'application/vnd.google-apps.shortcut') {
            $id = (string) data_get($file, 'shortcutDetails.targetId', '');
            $targetMime = data_get($file, 'shortcutDetails.targetMimeType');
            $mime = is_string($targetMime) && $targetMime !== ''
                ? $targetMime
                : 'application/octet-stream';
        }

        if ($id === '' || $mime === 'application/vnd.google-apps.folder' || $mime === 'application/vnd.google-apps.shortcut') {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name !== '' ? $name : $id,
            'mimeType' => $mime,
            'modifiedTime' => filled($file['modifiedTime'] ?? null) ? (string) $file['modifiedTime'] : null,
            'md5Checksum' => filled($file['md5Checksum'] ?? null) ? (string) $file['md5Checksum'] : null,
            'size' => isset($file['size']) && is_numeric($file['size']) ? (int) $file['size'] : null,
        ];
    }

    /**
     * @return array{id: string, mimeType: string}|null
     */
    private function resolveDownloadableMeta(string $token, string $fileId): ?array
    {
        $meta = Http::withToken($token)
            ->timeout(15)
            ->acceptJson()
            ->get(self::API.'/files/'.$fileId, [
                'fields' => 'id,mimeType,shortcutDetails',
                'supportsAllDrives' => 'true',
            ]);

        if (! $meta->successful()) {
            Log::warning('Google Drive file meta failed.', [
                'file_id' => $fileId,
                'status' => $meta->status(),
            ]);

            return null;
        }

        $mime = (string) $meta->json('mimeType', '');
        $resolvedId = (string) $meta->json('id', $fileId);

        if ($mime === 'application/vnd.google-apps.shortcut') {
            $targetId = (string) $meta->json('shortcutDetails.targetId', '');
            if ($targetId === '') {
                return null;
            }

            return $this->resolveDownloadableMeta($token, $targetId);
        }

        if ($mime === 'application/vnd.google-apps.folder') {
            return null;
        }

        return [
            'id' => $resolvedId !== '' ? $resolvedId : $fileId,
            'mimeType' => $mime !== '' ? $mime : 'application/octet-stream',
        ];
    }

    private function exportMimeFor(string $mime): ?string
    {
        return match ($mime) {
            'application/vnd.google-apps.document',
            'application/vnd.google-apps.spreadsheet',
            'application/vnd.google-apps.presentation' => 'application/pdf',
            'application/vnd.google-apps.drawing' => 'image/png',
            default => str_starts_with($mime, 'application/vnd.google-apps.') ? 'application/pdf' : null,
        };
    }

    private function immediateParentId(string $token, string $folderId): ?string
    {
        $meta = Http::withToken($token)
            ->timeout(15)
            ->acceptJson()
            ->get(self::API.'/files/'.$folderId, [
                'fields' => 'id,parents',
                'supportsAllDrives' => 'true',
            ]);

        if (! $meta->successful()) {
            return null;
        }

        $parent = data_get($meta->json(), 'parents.0');

        return filled($parent) ? (string) $parent : null;
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
