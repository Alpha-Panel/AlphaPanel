<?php

namespace App\Services;

use App\Models\BackupSetting;
use Carbon\Carbon;
use Google\Client as GoogleClient;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Exception;
use Google\Service\Oauth2;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;

class GoogleDriveService
{
    private ?GoogleClient $client = null;

    private ?Drive $drive = null;

    private const FOLDER_MIME_TYPE = 'application/vnd.google-apps.folder';

    protected function bootClient(): GoogleClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->client = new GoogleClient;
        $this->client->setClientId(config('backup.google.client_id'));
        $this->client->setClientSecret(config('backup.google.client_secret'));
        $this->client->setScopes(config('backup.google.scopes'));
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');

        return $this->client;
    }

    protected function bootDrive(): Drive
    {
        if ($this->drive !== null) {
            return $this->drive;
        }

        $client = $this->bootClient();
        $settings = BackupSetting::instance();

        if (! $settings->isConnected()) {
            throw new \RuntimeException('Google Drive is not connected.');
        }

        $client->setAccessToken([
            'access_token' => $settings->google_access_token,
            'refresh_token' => $settings->google_refresh_token,
            'expires_in' => $settings->google_token_expires_at
                ? now()->diffInSeconds($settings->google_token_expires_at, false)
                : 0,
            'created' => $settings->google_token_expires_at
                ? $settings->google_token_expires_at->subHour()->getTimestamp()
                : 0,
        ]);

        $this->refreshTokenIfNeeded($settings);

        $this->drive = new Drive($client);

        return $this->drive;
    }

    // --- OAuth Flow ---

    public function getAuthUrl(string $state): string
    {
        $client = $this->bootClient();
        $client->setRedirectUri(route('backups.callback'));
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, email: ?string}
     */
    public function exchangeCode(string $code): array
    {
        $client = $this->bootClient();
        $client->setRedirectUri(route('backups.callback'));

        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new \RuntimeException('Google OAuth error: '.($token['error_description'] ?? $token['error']));
        }

        $client->setAccessToken($token);

        $email = null;

        try {
            $oauth2 = new Oauth2($client);
            $userInfo = $oauth2->userinfo->get();
            $email = $userInfo->getEmail();
        } catch (\Throwable $e) {
            Log::warning('Could not fetch Google user email', ['error' => $e->getMessage()]);
        }

        return [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? '',
            'expires_in' => $token['expires_in'] ?? 3600,
            'email' => $email,
        ];
    }

    // --- Token Management ---

    public function refreshTokenIfNeeded(?BackupSetting $settings = null): void
    {
        $settings ??= BackupSetting::instance();
        $client = $this->bootClient();

        if ($client->isAccessTokenExpired()) {
            $refreshToken = $settings->google_refresh_token;

            if (! $refreshToken) {
                throw new \RuntimeException('No refresh token available. Please reconnect Google Drive.');
            }

            try {
                $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
            } catch (\Throwable $e) {
                Log::error('Google token refresh failed', ['error' => $e->getMessage()]);
                $settings->update([
                    'google_access_token' => null,
                    'google_refresh_token' => null,
                    'google_token_expires_at' => null,
                    'connected_email' => null,
                ]);

                throw new \RuntimeException('Google Drive token refresh failed. Please reconnect.');
            }

            if (isset($newToken['error'])) {
                $settings->update([
                    'google_access_token' => null,
                    'google_refresh_token' => null,
                    'google_token_expires_at' => null,
                    'connected_email' => null,
                ]);

                throw new \RuntimeException('Google token refresh error: '.($newToken['error_description'] ?? $newToken['error']));
            }

            $settings->update([
                'google_access_token' => $newToken['access_token'],
                'google_token_expires_at' => now()->addSeconds($newToken['expires_in'] ?? 3600),
            ]);

            if (! empty($newToken['refresh_token'])) {
                $settings->update(['google_refresh_token' => $newToken['refresh_token']]);
            }

            $client->setAccessToken($newToken);
        }
    }

    // --- Drive Folder Operations ---

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function listFolders(?string $parentId = null): array
    {
        $drive = $this->bootDrive();

        $query = "mimeType='".self::FOLDER_MIME_TYPE."' and trashed = false";

        if ($parentId) {
            $query .= " and '{$parentId}' in parents";
        } else {
            $query .= " and 'root' in parents";
        }

        $response = $drive->files->listFiles([
            'q' => $query,
            'spaces' => 'drive',
            'fields' => 'files(id, name)',
            'orderBy' => 'name',
            'pageSize' => 100,
        ]);

        return collect($response->getFiles())->map(fn (DriveFile $file) => [
            'id' => $file->getId(),
            'name' => $file->getName(),
        ])->all();
    }

    /**
     * @return array{id: string, name: string}
     */
    public function createFolder(string $name, ?string $parentId = null): array
    {
        $drive = $this->bootDrive();

        $metadata = new DriveFile([
            'name' => $name,
            'mimeType' => self::FOLDER_MIME_TYPE,
            'parents' => [$parentId ?? 'root'],
        ]);

        $folder = $drive->files->create($metadata, ['fields' => 'id, name']);

        return [
            'id' => $folder->getId(),
            'name' => $folder->getName(),
        ];
    }

    /**
     * Navigate/create a nested folder path on Drive.
     * Port of drivebackup.py create_drive_folder().
     */
    public function findOrCreateFolderPath(string $path, string $rootFolderId): string
    {
        $drive = $this->bootDrive();
        $currentParent = $rootFolderId;

        foreach (array_filter(explode('/', $path)) as $folderName) {
            $query = "mimeType='".self::FOLDER_MIME_TYPE."' and trashed = false"
                ." and name='{$folderName}' and '{$currentParent}' in parents";

            $response = $drive->files->listFiles([
                'q' => $query,
                'spaces' => 'drive',
                'fields' => 'files(id, name)',
            ]);

            $files = $response->getFiles();

            if (count($files) > 0) {
                $currentParent = $files[0]->getId();
            } else {
                $metadata = new DriveFile([
                    'name' => $folderName,
                    'mimeType' => self::FOLDER_MIME_TYPE,
                    'parents' => [$currentParent],
                ]);

                $folder = $drive->files->create($metadata, ['fields' => 'id']);
                $currentParent = $folder->getId();
            }
        }

        return $currentParent;
    }

    // --- File Upload ---

    /**
     * Upload a file with chunked resumable upload.
     * Port of drivebackup.py upload_large_file().
     *
     * @return array{id: string, name: string, size: int}
     */
    public function uploadFile(string $localPath, string $folderId, ?callable $onProgress = null): array
    {
        $drive = $this->bootDrive();
        $client = $this->bootClient();

        $fileName = basename($localPath);
        $fileSize = filesize($localPath);
        $simpleThreshold = config('backup.simple_upload_threshold_mb', 5) * 1024 * 1024;

        // Simple/multipart upload for small files — avoids Content-Range header bug
        if ($fileSize <= $simpleThreshold) {
            $metadata = new DriveFile([
                'name' => $fileName,
                'parents' => [$folderId],
            ]);

            $result = $drive->files->create($metadata, [
                'data' => file_get_contents($localPath),
                'mimeType' => mime_content_type($localPath) ?: 'application/octet-stream',
                'uploadType' => 'multipart',
                'fields' => 'id, name, size',
            ]);

            if ($onProgress) {
                $onProgress(100, $fileName);
            }

            return [
                'id' => $result->getId(),
                'name' => $fileName,
                'size' => $fileSize,
            ];
        }

        // Chunked resumable upload for large files
        $chunkSize = config('backup.chunk_size_mb') * 1024 * 1024;

        $metadata = new DriveFile([
            'name' => $fileName,
            'parents' => [$folderId],
        ]);

        $client->setDefer(true);

        $request = $drive->files->create($metadata, ['fields' => 'id, name, size']);

        $media = new MediaFileUpload(
            $client,
            $request,
            mime_content_type($localPath) ?: 'application/octet-stream',
            null,
            true,
            $chunkSize
        );
        $media->setFileSize($fileSize);

        $handle = fopen($localPath, 'rb');
        $response = null;

        while (! feof($handle)) {
            $chunk = fread($handle, $chunkSize);

            try {
                $response = $media->nextChunk($chunk);
            } catch (Exception $e) {
                fclose($handle);
                $client->setDefer(false);

                throw $e;
            }

            if ($onProgress && $fileSize > 0) {
                $uploaded = ftell($handle);
                $percent = min(100, (int) round(($uploaded / $fileSize) * 100));
                $onProgress($percent, $fileName);
            }
        }

        fclose($handle);
        $client->setDefer(false);

        return [
            'id' => $response['id'] ?? '',
            'name' => $fileName,
            'size' => $fileSize,
        ];
    }

    // --- File Update / Delete ---

    /**
     * Update an existing file on Drive using chunked resumable upload.
     *
     * @return array{id: string, name: string, size: int}
     */
    public function updateFile(string $fileId, string $localPath, ?callable $onProgress = null): array
    {
        $drive = $this->bootDrive();
        $client = $this->bootClient();

        $fileName = basename($localPath);
        $fileSize = filesize($localPath);
        $simpleThreshold = config('backup.simple_upload_threshold_mb', 5) * 1024 * 1024;

        // Simple/multipart upload for small files — avoids Content-Range header bug
        if ($fileSize <= $simpleThreshold) {
            $metadata = new DriveFile;

            $result = $drive->files->update($fileId, $metadata, [
                'data' => file_get_contents($localPath),
                'mimeType' => mime_content_type($localPath) ?: 'application/octet-stream',
                'uploadType' => 'multipart',
                'fields' => 'id, name, size',
            ]);

            if ($onProgress) {
                $onProgress(100, $fileName);
            }

            return [
                'id' => $result->getId(),
                'name' => $fileName,
                'size' => $fileSize,
            ];
        }

        // Chunked resumable upload for large files
        $chunkSize = config('backup.chunk_size_mb') * 1024 * 1024;

        $metadata = new DriveFile;

        $client->setDefer(true);

        $request = $drive->files->update($fileId, $metadata, ['fields' => 'id, name, size']);

        $media = new MediaFileUpload(
            $client,
            $request,
            mime_content_type($localPath) ?: 'application/octet-stream',
            null,
            true,
            $chunkSize
        );
        $media->setFileSize($fileSize);

        $handle = fopen($localPath, 'rb');
        $response = null;

        while (! feof($handle)) {
            $chunk = fread($handle, $chunkSize);

            try {
                $response = $media->nextChunk($chunk);
            } catch (Exception $e) {
                fclose($handle);
                $client->setDefer(false);

                throw $e;
            }

            if ($onProgress && $fileSize > 0) {
                $uploaded = ftell($handle);
                $percent = min(100, (int) round(($uploaded / $fileSize) * 100));
                $onProgress($percent, $fileName);
            }
        }

        fclose($handle);
        $client->setDefer(false);

        return [
            'id' => $response['id'] ?? $fileId,
            'name' => $fileName,
            'size' => $fileSize,
        ];
    }

    /**
     * Delete a single file by ID.
     */
    public function deleteFileById(string $fileId): void
    {
        $drive = $this->bootDrive();
        $drive->files->delete($fileId);
    }

    /**
     * Download a file from Drive directly to a local path.
     */
    public function downloadFileToPath(string $fileId, string $localPath): void
    {
        $drive = $this->bootDrive();

        /** @var Response $response */
        $response = $drive->files->get($fileId, ['alt' => 'media']);
        $stream = $response->getBody();

        $dir = dirname($localPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $handle = fopen($localPath, 'wb');

        while (! $stream->eof()) {
            fwrite($handle, $stream->read(8192));
        }

        fclose($handle);
    }

    /**
     * Recursively list all files in a Drive folder tree.
     *
     * @return array<int, array{id: string, name: string, mimeType: string, size: int|null, path: string}>
     */
    public function listFilesRecursive(string $folderId, string $currentPath = ''): array
    {
        $drive = $this->bootDrive();
        $results = [];

        $query = "trashed = false and '{$folderId}' in parents";
        $response = $drive->files->listFiles([
            'q' => $query,
            'spaces' => 'drive',
            'fields' => 'files(id, name, mimeType, size)',
            'pageSize' => 1000,
        ]);

        foreach ($response->getFiles() as $file) {
            $filePath = $currentPath ? "{$currentPath}/{$file->getName()}" : $file->getName();

            if ($file->getMimeType() === self::FOLDER_MIME_TYPE) {
                $results = array_merge($results, $this->listFilesRecursive($file->getId(), $filePath));
            } else {
                $results[] = [
                    'id' => $file->getId(),
                    'name' => $file->getName(),
                    'mimeType' => $file->getMimeType(),
                    'size' => $file->getSize() ? (int) $file->getSize() : null,
                    'path' => $filePath,
                ];
            }
        }

        return $results;
    }

    // --- Storage Quota ---

    /**
     * Get Google Drive storage quota (usage and limit).
     *
     * @return array{usage: int, limit: int|null}
     */
    public function getStorageQuota(): array
    {
        $drive = $this->bootDrive();
        $about = $drive->about->get(['fields' => 'storageQuota']);
        $quota = $about->getStorageQuota();

        return [
            'usage' => (int) $quota->getUsage(),
            'limit' => $quota->getLimit() ? (int) $quota->getLimit() : null,
        ];
    }

    // --- File Browsing ---

    /**
     * List both files and folders in a given parent folder.
     *
     * @return array<int, array{id: string, name: string, mimeType: string, size: int|null, modifiedTime: string|null}>
     */
    public function listFilesAndFolders(?string $parentId = null): array
    {
        $drive = $this->bootDrive();

        $query = 'trashed = false';
        $query .= $parentId
            ? " and '{$parentId}' in parents"
            : " and 'root' in parents";

        $response = $drive->files->listFiles([
            'q' => $query,
            'spaces' => 'drive',
            'fields' => 'files(id, name, mimeType, size, modifiedTime)',
            'orderBy' => 'folder,name',
            'pageSize' => 200,
        ]);

        return collect($response->getFiles())->map(fn (DriveFile $file) => [
            'id' => $file->getId(),
            'name' => $file->getName(),
            'mimeType' => $file->getMimeType(),
            'size' => $file->getSize() ? (int) $file->getSize() : null,
            'modifiedTime' => $file->getModifiedTime(),
        ])->all();
    }

    /**
     * Get file metadata and a readable stream for download.
     *
     * @return array{name: string, mimeType: string, size: int, stream: StreamInterface}
     */
    public function downloadFile(string $fileId): array
    {
        $drive = $this->bootDrive();

        $file = $drive->files->get($fileId, ['fields' => 'id, name, mimeType, size']);

        /** @var Response $response */
        $response = $drive->files->get($fileId, ['alt' => 'media']);

        return [
            'name' => $file->getName(),
            'mimeType' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => (int) $file->getSize(),
            'stream' => $response->getBody(),
        ];
    }

    // --- Cleanup ---

    /**
     * Delete backups older than the given retention period.
     * Port of drivebackup.py remove_old_backups().
     *
     * @throws Exception
     */
    public function deleteOldBackups(string $folderId, int $retentionDays): int
    {
        $drive = $this->bootDrive();
        $cutoff = now()->subDays($retentionDays);
        $deleted = 0;

        $query = "mimeType!='".self::FOLDER_MIME_TYPE."' and trashed = false and '{$folderId}' in parents";

        $response = $drive->files->listFiles([
            'q' => $query,
            'spaces' => 'drive',
            'fields' => 'files(id, name, modifiedTime)',
            'pageSize' => 1000,
        ]);

        foreach ($response->getFiles() as $file) {
            $modifiedTime = Carbon::parse($file->getModifiedTime());

            if ($modifiedTime->lt($cutoff)) {
                Log::info('Deleting old backup from Drive', ['name' => $file->getName(), 'id' => $file->getId()]);
                $drive->files->delete($file->getId());
                $deleted++;
            }
        }

        // Also check subfolders (backup date folders)
        $folderQuery = "mimeType='".self::FOLDER_MIME_TYPE."' and trashed = false and '{$folderId}' in parents";
        $folderResponse = $drive->files->listFiles([
            'q' => $folderQuery,
            'spaces' => 'drive',
            'fields' => 'files(id, name, modifiedTime)',
            'pageSize' => 1000,
        ]);

        foreach ($folderResponse->getFiles() as $folder) {
            // Live mirror folder — never delete
            if ($folder->getName() === 'websites') {
                continue;
            }

            $modifiedTime = Carbon::parse($folder->getModifiedTime());

            if ($modifiedTime->lt($cutoff)) {
                Log::info('Deleting old backup folder from Drive', ['name' => $folder->getName()]);
                $drive->files->delete($folder->getId());
                $deleted++;
            }
        }

        return $deleted;
    }

    public function getUserEmail(): ?string
    {
        try {
            $client = $this->bootClient();
            $settings = BackupSetting::instance();

            $client->setAccessToken([
                'access_token' => $settings->google_access_token,
                'refresh_token' => $settings->google_refresh_token,
            ]);

            $this->refreshTokenIfNeeded($settings);

            $oauth2 = new Oauth2($client);
            $userInfo = $oauth2->userinfo->get();

            return $userInfo->getEmail();
        } catch (\Throwable $e) {
            Log::warning('Could not fetch Google user email', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
