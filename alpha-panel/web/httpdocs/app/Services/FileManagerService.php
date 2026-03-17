<?php

namespace App\Services;

use App\Models\FtpUser;
use FTP\Connection;
use Illuminate\Http\UploadedFile;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\StorageAttributes;
use RuntimeException;
use ZipArchive;

class FileManagerService
{
    private Filesystem $filesystem;

    /** @var array{host: string, port: int, username: string, password: string} */
    private array $ftpCredentials;

    public function __construct(
        private readonly FtpUser $ftpUser,
    ) {
        if (! $ftpUser->hasPassword()) {
            throw new RuntimeException('FTP user has no stored password. Please update the FTP password first.');
        }

        $this->ftpCredentials = [
            'host' => config('panel.ftp_host', 'ftp'),
            'port' => (int) config('panel.ftp_port', 21),
            'username' => $ftpUser->username,
            'password' => $ftpUser->encrypted_password,
        ];

        $options = FtpConnectionOptions::fromArray([
            ...$this->ftpCredentials,
            'root' => '/',
            'ssl' => config('panel.ftp_ssl', true),
            'passive' => true,
            'ignorePassiveAddress' => true,
            'timestampsOnUnixListingsEnabled' => true,
            'timeout' => 10,
        ]);

        $this->filesystem = new Filesystem(new FtpAdapter($options));
    }

    /**
     * Create a FileManagerService for the given FTP user.
     */
    public static function forUser(FtpUser $ftpUser): self
    {
        return new self($ftpUser);
    }

    /**
     * Validate and normalize a path to prevent traversal attacks.
     */
    public function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);

        $parts = explode('/', $path);
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '..' || $part === '') {
                continue;
            }
            if ($part === '.') {
                continue;
            }
            $normalized[] = $part;
        }

        return implode('/', $normalized);
    }

    /**
     * List contents of a directory with proper symlink support.
     *
     * Flysystem's FTP adapter corrupts symlink names by including ' -> target'
     * in the path and treats them as files. This method cleans those entries
     * and resolves symlink paths so navigation into them works.
     *
     * @return array<int, array{name: string, path: string, type: string, size: int|null, lastModified: int|null, permissions: string|null}>
     */
    public function listDirectory(string $path = ''): array
    {
        $path = $this->normalizePath($path);

        // Resolve symlinks in path: if FTP can't list a symlink directly,
        // we resolve it to the real target path via the parent listing.
        $resolvedPath = $this->resolveSymlinksInPath($path);

        // Use raw FTP listing as primary source — includes hidden files via -a flag
        $rawData = $this->getRawListingData($resolvedPath);

        $items = [];

        foreach ($rawData as $name => $raw) {
            $itemPath = $path === '' ? $name : $path.'/'.$name;

            $items[] = [
                'name' => $name,
                'path' => $itemPath,
                'type' => $raw['type'],
                'size' => $raw['size'],
                'lastModified' => $raw['lastModified'],
                'permissions' => $raw['permissions'],
                'permissionsOctal' => $raw['permissionsOctal'],
            ];
        }

        usort($items, function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $items;
    }

    /**
     * Change file/directory permissions.
     */
    public function chmod(string $path, string $mode): bool
    {
        $path = $this->normalizePath($path);
        $resolvedPath = $this->resolveSymlinksInPath($path);
        $ftpPath = '/'.$resolvedPath;

        $conn = $this->openRawFtpConnection();

        try {
            return @ftp_site($conn, "CHMOD {$mode} {$ftpPath}");
        } finally {
            @ftp_close($conn);
        }
    }

    /**
     * Parse a Flysystem symlink entry into link name and target.
     *
     * Flysystem stores symlinks as "name -> /absolute/target" in the path.
     * basename() would incorrectly return the last segment of the target.
     *
     * @return array{0: string, 1: string} [linkName, target]
     */
    private function parseSymlinkEntry(string $itemPath): array
    {
        // The path from Flysystem may include parent prefix: "parent/httpdocs -> /var/www/..."
        // We need to split on ' -> ' and take basename of the left side.
        $parts = explode(' -> ', $itemPath, 2);
        $linkName = basename($parts[0]);
        $target = $parts[1] ?? '';

        return [$linkName, $target];
    }

    /**
     * Resolve symlinks in a path by checking parent directory listings.
     *
     * FTP servers often can't LIST a symlinked directory directly.
     * This method walks each path segment, detects symlinks from the parent
     * listing, and replaces them with the resolved target path.
     */
    private function resolveSymlinksInPath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        $segments = explode('/', $path);
        $resolvedSoFar = '';

        foreach ($segments as $segment) {
            // List the current resolved directory to find symlinks
            $target = $this->findSymlinkTarget($resolvedSoFar, $segment);

            if ($target !== null) {
                // Convert absolute target to relative FTP path
                $resolvedSoFar = $this->resolveSymlinkTarget($target, $resolvedSoFar, $segment);
            } else {
                $resolvedSoFar = $resolvedSoFar === '' ? $segment : $resolvedSoFar.'/'.$segment;
            }
        }

        return $resolvedSoFar;
    }

    /**
     * Find the symlink target for a named entry in a directory.
     */
    private function findSymlinkTarget(string $parentPath, string $name): ?string
    {
        try {
            foreach ($this->filesystem->listContents($parentPath, false) as $item) {
                $itemPath = $item->path();

                if (! str_contains($itemPath, ' -> ')) {
                    continue;
                }

                [$linkName, $target] = $this->parseSymlinkEntry($itemPath);

                if ($linkName === $name) {
                    return $target;
                }
            }
        } catch (\Throwable) {
            // Parent listing failed
        }

        return null;
    }

    /**
     * Resolve a symlink target to a relative FTP path.
     */
    private function resolveSymlinkTarget(string $target, string $currentResolved, string $segment): string
    {
        // Try to strip the FTP user's home_path to get a relative path
        $homePath = rtrim($this->ftpUser->home_path ?? '', '/');

        if ($homePath !== '' && str_starts_with($target, $homePath.'/')) {
            return ltrim(substr($target, strlen($homePath)), '/');
        }

        // Target might be relative already
        if (! str_starts_with($target, '/')) {
            return $currentResolved === ''
                ? $target
                : $currentResolved.'/'.$target;
        }

        // Absolute path we can't resolve — fall back to original segment
        return $currentResolved === '' ? $segment : $currentResolved.'/'.$segment;
    }

    /**
     * Get raw FTP listing data (permissions, timestamps, size, type) for a directory.
     *
     * @return array<string, array{type: string, permissions: string, permissionsOctal: string, lastModified: int|null, size: int|null}>
     */
    private function getRawListingData(string $path): array
    {
        try {
            $conn = $this->openRawFtpConnection();
        } catch (\Throwable) {
            return [];
        }

        try {
            $ftpPath = $path === '' ? '/' : '/'.$path;

            // Use -a flag to include hidden files (dotfiles).
            // vsftpd also needs force_dot_files=YES in its config.
            $rawList = @ftp_rawlist($conn, '-a '.$ftpPath);

            if ($rawList === false || $rawList === []) {
                $rawList = @ftp_rawlist($conn, $ftpPath) ?: [];
            }
        } finally {
            @ftp_close($conn);
        }

        $data = [];

        foreach ($rawList as $line) {
            // Standard Unix listing: perms links owner group size month day time/year name
            $line = preg_replace('#\s+#', ' ', trim($line), 8);
            $parts = explode(' ', $line, 9);

            if (count($parts) < 9) {
                continue;
            }

            [$perms, , , , $sizeStr, $month, $day, $timeOrYear, $nameField] = $parts;

            // Skip . and .. and total lines
            if ($nameField === '.' || $nameField === '..' || str_starts_with($perms, 'total')) {
                continue;
            }

            $isSymlink = str_starts_with($perms, 'l');
            $name = $isSymlink ? explode(' -> ', $nameField, 2)[0] : $nameField;
            $rwx = substr($perms, 1, 9);
            $octal = $this->permissionsToOctal($perms);
            $size = is_numeric($sizeStr) ? (int) $sizeStr : null;

            // Parse timestamp
            $lastModified = null;

            try {
                if (str_contains($timeOrYear, ':')) {
                    $dt = \DateTime::createFromFormat('M j H:i', "$month $day $timeOrYear");

                    // If the parsed date is in the future, it's from last year
                    if ($dt && $dt->getTimestamp() > time()) {
                        $dt->modify('-1 year');
                    }
                } else {
                    $dt = \DateTime::createFromFormat('M j Y', "$month $day $timeOrYear");
                }

                if ($dt) {
                    $lastModified = $dt->getTimestamp();
                }
            } catch (\Throwable) {
                // Ignore
            }

            $isDir = $perms[0] === 'd';
            $type = $isDir ? 'directory' : ($isSymlink ? 'directory' : 'file');

            $data[$name] = [
                'type' => $type,
                'permissions' => $rwx,
                'permissionsOctal' => $octal,
                'lastModified' => $lastModified,
                'size' => $size,
            ];
        }

        return $data;
    }

    /**
     * Convert a Unix permission string (e.g. "drwxr-xr-x") to octal (e.g. "0755").
     */
    private function permissionsToOctal(string $perms): string
    {
        // Skip first char (file type: d, l, -, etc.)
        $perms = substr($perms, 1, 9);

        if (strlen($perms) !== 9) {
            return '0000';
        }

        $octal = 0;
        $map = [
            0 => 0400, 1 => 0200, 2 => 0100, // owner r,w,x
            3 => 0040, 4 => 0020, 5 => 0010, // group r,w,x
            6 => 0004, 7 => 0002, 8 => 0001, // other r,w,x
        ];

        for ($i = 0; $i < 9; $i++) {
            $char = $perms[$i];

            if ($char !== '-') {
                $octal |= $map[$i];

                // Handle setuid/setgid/sticky
                if ($i === 2 && ($char === 's' || $char === 'S')) {
                    $octal |= 04000;
                }
                if ($i === 5 && ($char === 's' || $char === 'S')) {
                    $octal |= 02000;
                }
                if ($i === 8 && ($char === 't' || $char === 'T')) {
                    $octal |= 01000;
                }
            }
        }

        return sprintf('%04o', $octal);
    }

    /**
     * Open a raw FTP connection.
     */
    private function openRawFtpConnection(): Connection
    {
        $conn = config('panel.ftp_ssl', true)
            ? ftp_ssl_connect($this->ftpCredentials['host'], $this->ftpCredentials['port'], 10)
            : ftp_connect($this->ftpCredentials['host'], $this->ftpCredentials['port'], 10);

        if (! $conn) {
            throw new RuntimeException('Failed to connect to FTP server.');
        }

        if (! ftp_login($conn, $this->ftpCredentials['username'], $this->ftpCredentials['password'])) {
            @ftp_close($conn);
            throw new RuntimeException('FTP login failed.');
        }

        ftp_pasv($conn, true);

        // Match Flysystem's ignorePassiveAddress: use the control connection IP
        // instead of the PASV-returned IP (critical for Docker networking)
        ftp_set_option($conn, FTP_USEPASVADDRESS, false);

        return $conn;
    }

    /**
     * Read a file's contents.
     */
    public function readFile(string $path): string
    {
        $path = $this->normalizePath($path);

        return $this->filesystem->read($path);
    }

    /**
     * Write content to a file.
     */
    public function writeFile(string $path, string $content): void
    {
        $path = $this->normalizePath($path);
        $this->filesystem->write($path, $content);
    }

    /**
     * Create a directory.
     */
    public function createDirectory(string $path): void
    {
        $path = $this->normalizePath($path);
        $this->filesystem->createDirectory($path);
    }

    /**
     * Delete a file or directory.
     */
    public function delete(string $path): void
    {
        $path = $this->normalizePath($path);

        if ($this->filesystem->directoryExists($path)) {
            $this->filesystem->deleteDirectory($path);
        } else {
            $this->filesystem->delete($path);
        }
    }

    /**
     * Rename / move a file or directory.
     */
    public function rename(string $from, string $to): void
    {
        $from = $this->normalizePath($from);
        $to = $this->normalizePath($to);
        $this->filesystem->move($from, $to);
    }

    /**
     * Upload a file.
     */
    public function upload(string $directory, UploadedFile $file): string
    {
        $directory = $this->normalizePath($directory);
        $filename = $file->getClientOriginalName();
        $path = $directory ? "{$directory}/{$filename}" : $filename;

        $this->filesystem->write($path, $file->getContent());

        return $path;
    }

    /**
     * Get a file's contents as a stream for download.
     */
    public function readStream(string $path)
    {
        $path = $this->normalizePath($path);

        return $this->filesystem->readStream($path);
    }

    /**
     * Check if a path exists.
     */
    public function exists(string $path): bool
    {
        $path = $this->normalizePath($path);

        return $this->filesystem->fileExists($path) || $this->filesystem->directoryExists($path);
    }

    /**
     * Get the MIME type of a file.
     */
    public function mimeType(string $path): string
    {
        $path = $this->normalizePath($path);

        return $this->filesystem->mimeType($path);
    }

    /**
     * Compress files/directories into a zip archive and upload it.
     *
     * @param  array<int, string>  $paths
     */
    public function compress(array $paths, string $zipPath): void
    {
        $zipPath = $this->normalizePath($zipPath);
        $tempFile = tempnam(sys_get_temp_dir(), 'fm_zip_');

        try {
            $zip = new ZipArchive;

            if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Failed to create zip archive.');
            }

            foreach ($paths as $path) {
                $path = $this->normalizePath($path);
                $this->addToZip($zip, $path, basename($path));
            }

            $zip->close();

            $this->filesystem->write($zipPath, file_get_contents($tempFile));
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Recursively add a file or directory to a ZipArchive.
     */
    private function addToZip(ZipArchive $zip, string $remotePath, string $zipEntryPath): void
    {
        if ($this->filesystem->directoryExists($remotePath)) {
            $zip->addEmptyDir($zipEntryPath);

            $contents = $this->filesystem->listContents($remotePath, false);

            /** @var StorageAttributes $item */
            foreach ($contents as $item) {
                $childName = basename($item->path());
                $this->addToZip($zip, $item->path(), $zipEntryPath.'/'.$childName);
            }
        } else {
            $stream = $this->filesystem->readStream($remotePath);
            $contents = stream_get_contents($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $zip->addFromString($zipEntryPath, $contents);
        }
    }

    /**
     * Decompress a zip archive to a directory.
     */
    public function decompress(string $zipPath, string $destinationDir): void
    {
        $zipPath = $this->normalizePath($zipPath);
        $destinationDir = $this->normalizePath($destinationDir);
        $tempFile = tempnam(sys_get_temp_dir(), 'fm_unzip_');

        try {
            $stream = $this->filesystem->readStream($zipPath);
            file_put_contents($tempFile, stream_get_contents($stream));

            if (is_resource($stream)) {
                fclose($stream);
            }

            $zip = new ZipArchive;

            if ($zip->open($tempFile) !== true) {
                throw new RuntimeException('Failed to open zip archive.');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);

                if ($entryName === false) {
                    continue;
                }

                $targetPath = $destinationDir ? $destinationDir.'/'.$entryName : $entryName;
                $targetPath = $this->normalizePath($targetPath);

                if (str_ends_with($entryName, '/')) {
                    $this->filesystem->createDirectory($targetPath);
                } else {
                    $entryContent = $zip->getFromIndex($i);

                    if ($entryContent !== false) {
                        $this->filesystem->write($targetPath, $entryContent);
                    }
                }
            }

            $zip->close();
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Detect if a file is binary based on extension.
     */
    public function isBinaryFile(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $binaryExtensions = [
            'png', 'jpg', 'jpeg', 'gif', 'bmp', 'ico', 'svg', 'webp', 'avif',
            'mp3', 'mp4', 'avi', 'mkv', 'mov', 'wav', 'ogg', 'flac',
            'zip', 'tar', 'gz', 'bz2', 'rar', '7z', 'xz',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'exe', 'dll', 'so', 'dylib', 'bin', 'dat',
            'ttf', 'otf', 'woff', 'woff2', 'eot',
            'sqlite', 'db',
        ];

        return in_array($extension, $binaryExtensions);
    }

    /**
     * Get Monaco editor language identifier from file extension.
     */
    public function getEditorLanguage(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $languageMap = [
            'php' => 'php',
            'js' => 'javascript',
            'mjs' => 'javascript',
            'cjs' => 'javascript',
            'ts' => 'typescript',
            'tsx' => 'typescript',
            'jsx' => 'javascript',
            'json' => 'json',
            'html' => 'html',
            'htm' => 'html',
            'blade.php' => 'html',
            'css' => 'css',
            'scss' => 'scss',
            'less' => 'less',
            'xml' => 'xml',
            'yaml' => 'yaml',
            'yml' => 'yaml',
            'md' => 'markdown',
            'sql' => 'sql',
            'sh' => 'shell',
            'bash' => 'shell',
            'py' => 'python',
            'rb' => 'ruby',
            'go' => 'go',
            'rs' => 'rust',
            'java' => 'java',
            'c' => 'c',
            'cpp' => 'cpp',
            'h' => 'c',
            'vue' => 'html',
            'env' => 'ini',
            'ini' => 'ini',
            'conf' => 'ini',
            'log' => 'plaintext',
            'txt' => 'plaintext',
            'htaccess' => 'ini',
            'gitignore' => 'plaintext',
            'dockerfile' => 'dockerfile',
            'twig' => 'twig',
        ];

        if (str_ends_with($path, '.blade.php')) {
            return 'html';
        }

        return $languageMap[$extension] ?? 'plaintext';
    }
}
