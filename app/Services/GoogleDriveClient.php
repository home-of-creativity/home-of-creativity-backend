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

    /**
     * @return array{folders: list<array{id: string, name: string}>, next_page_token: ?string}|null
     */
    public function listFolders(?string $parentId, ?string $pageToken = null): ?array
    {
        $this->lastError = null;
        if ($error = $this->configurationError()) {
            return $this->fail($error);
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return $this->fail('Google service account could not get an access token. Check the private key.');
        }

        $parent = trim((string) $parentId);
        $query = $parent === ''
            ? "mimeType = 'application/vnd.google-apps.folder' and trashed = false"
            : sprintf(
                "'%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
                str_replace("'", "\\'", $parent),
            );

        try {
            $response = Http::withToken($token)
                ->timeout(20)
                ->acceptJson()
                ->get(self::API.'/files', array_filter([
                    'q' => $query,
                    'fields' => 'nextPageToken,files(id,name)',
                    'pageSize' => 100,
                    'pageToken' => $pageToken ?: null,
                    'supportsAllDrives' => 'true',
                    'includeItemsFromAllDrives' => 'true',
                    'corpora' => 'allDrives',
                ], fn (mixed $value): bool => $value !== null && $value !== ''));

            if (! $response->successful()) {
                return $this->fail($this->googleErrorMessage($response, 'Google Drive could not list folders.'));
            }

            $folders = [];
            foreach ($response->json('files') ?? [] as $file) {
                if (! is_array($file) || ! filled($file['id'] ?? null)) {
                    continue;
                }
                $folders[] = [
                    'id' => (string) $file['id'],
                    'name' => (string) ($file['name'] ?? ''),
                ];
            }

            $next = $response->json('nextPageToken');
            usort($folders, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

            return [
                'folders' => $folders,
                'next_page_token' => filled($next) ? (string) $next : null,
            ];
        } catch (Throwable $exception) {
            return $this->fail('Google Drive listFolders failed: '.$exception->getMessage());
        }
    }

    public function createFolder(string $name, ?string $parentId = null): ?string
    {
        $name = $this->safeName($name);
        if ($name === '') {
            return $this->fail('Folder name is empty.');
        }

        $parent = trim((string) $parentId);
        if ($parent === '' || $parent === 'root') {
            $parent = $this->parentFolderId();
        }

        return $this->ensureFolderPath($parent, [$name]);
    }

    public function ensureFolderPath(string $parentId, array $segments): ?string
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

            $writeToken = $this->writeToken($token, $context);
            if ($writeToken === null) {
                return null;
            }

            $cursor = $context['id'];
            $created = false;
            foreach ($segments as $segment) {
                $raw = trim((string) $segment);
                if ($raw === '') {
                    continue;
                }

                $next = $this->findOrCreateFolder($writeToken, $cursor, $this->safeName($raw), $context['driveId']);
                if ($next === null) {
                    return null;
                }

                $cursor = $next;
                $created = true;
            }

            if (! $created) {
                return $this->fail('Google Drive folder path is empty.');
            }

            return $cursor;
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

    /**
     * Files dropped directly in HOC Clients are never a request delivery.
     *
     * @param  array{id?: string, parents?: list<string>}  $file
     */
    public function isDirectlyInHocClientRoot(array $file): bool
    {
        $root = $this->parentFolderId();
        if ($root === '') {
            return false;
        }

        $parents = array_values(array_filter(array_map(strval(...), $file['parents'] ?? [])));

        return in_array($root, $parents, true);
    }

    public function isHocClientRootId(string $folderId): bool
    {
        $root = $this->parentFolderId();

        return $root !== '' && $folderId === $root;
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
                'fields' => 'id,driveId,mimeType,owners(emailAddress)',
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
        $owners = $meta->json('owners');
        $ownerEmail = is_array($owners) ? ($owners[0]['emailAddress'] ?? null) : null;

        return [
            'id' => (string) $meta->json('id', $parentId),
            'driveId' => filled($driveId) ? (string) $driveId : null,
            'ownerEmail' => is_string($ownerEmail) && $ownerEmail !== '' ? $ownerEmail : null,
        ];
    }

    /**
     * @param  array{id: string, driveId: ?string, ownerEmail: ?string}  $context
     */
    private function writeToken(string $serviceToken, array $context): ?string
    {
        if (filled($context['driveId'])) {
            return $serviceToken;
        }

        $owner = $context['ownerEmail'];
        $technical = $this->auth->clientEmail();
        if ($owner === null || ($technical !== null && strcasecmp($owner, $technical) === 0)) {
            return $this->fail('This folder is owned by the technical service account, which has no storage. Choose a folder owned by the Google account that should hold the files.');
        }

        $token = $this->auth->accessTokenFor($owner);
        if ($token === null) {
            return $this->fail('Could not upload as '.$owner.'. Enable domain-wide delegation for the service account and allow the Drive scope so storage counts on that account.');
        }

        return $token;
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
            $this->fail($this->quotaHint($this->googleErrorMessage(
                $created,
                'Google Drive rejected folder create. Share the parent folder with the service account.',
            )));

            return null;
        }

        $id = $created->json('id');

        return filled($id) ? (string) $id : $this->fail('Google Drive created a folder without an id.');
    }

    /**
     * @return array{id: string, url: string}|null
     */
    public function uploadFile(string $folderId, string $name, string $contents, string $mime): ?array
    {
        $this->lastError = null;
        if ($error = $this->configurationError()) {
            return $this->fail($error);
        }
        if ($folderId === '' || $contents === '') {
            return $this->fail('Google Drive upload is missing a folder or a file.');
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return $this->fail('Google service account could not get an access token. Check the private key.');
        }

        $context = $this->parentContext($token, $folderId);
        if ($context === null) {
            return null;
        }
        $token = $this->writeToken($token, $context);
        if ($token === null) {
            return null;
        }

        $boundary = 'hoc_'.bin2hex(random_bytes(8));
        $meta = json_encode([
            'name' => $this->safeName($name),
            'parents' => [$folderId],
        ], JSON_UNESCAPED_UNICODE);
        $body = "--{$boundary}\r\n"
            ."Content-Type: application/json; charset=UTF-8\r\n\r\n"
            .$meta."\r\n"
            ."--{$boundary}\r\n"
            ."Content-Type: {$mime}\r\n\r\n"
            .$contents."\r\n"
            ."--{$boundary}--";

        $created = Http::withToken($token)
            ->withBody($body, 'multipart/related; boundary='.$boundary)
            ->timeout(60)
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id,webViewLink');

        if (! $created->successful()) {
            return $this->fail($this->quotaHint($this->googleErrorMessage($created, 'Google Drive rejected the file upload.')));
        }

        $id = $created->json('id');
        if (! filled($id)) {
            return $this->fail('Google Drive uploaded a file without an id.');
        }

        $link = $created->json('webViewLink');

        return [
            'id' => (string) $id,
            'url' => is_string($link) && $link !== '' ? $link : 'https://drive.google.com/file/d/'.$id.'/view',
        ];
    }

    private function quotaHint(string $message): string
    {
        if (! str_contains($message, 'storage quota')) {
            return $message;
        }

        return $message.' Create it inside the shared drive folder from GOOGLE_DRIVE_PARENT_FOLDER_ID. A service account cannot store files in its own My Drive.';
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
