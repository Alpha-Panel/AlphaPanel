<?php

namespace App\Services;

use App\Models\FtpUser;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * File manager that executes every operation as the FTP user via runuser.
 *
 * The alpha_panel_web container creates a real system user for each FTP user
 * (entrypoint reads /etc/users.env on boot). The vhosts directory is bind-
 * mounted, so we can spawn a short-lived PHP CLI worker as that user with
 * `runuser -u {username} -- php fm-worker.php ...` and let the kernel enforce
 * ownership — no chown, no privilege juggling, no FTP protocol round trips.
 */
class LocalDomainFileManagerService
{
    private const RUNUSER_CANDIDATES = ['/usr/sbin/runuser', '/sbin/runuser', '/usr/bin/runuser'];

    private const PHP_CANDIDATES = ['/usr/local/bin/php', '/usr/bin/php'];

    private string $runuserBin;

    private string $phpBin;

    private string $username;

    private string $homedir;

    private string $workerPath;

    /**
     * Strictly enforced root that every FTP homedir must live under. Defense
     * in depth: even if a caller could craft a Domain/FtpUser with a forged
     * homedir, the worker would still receive a path outside this prefix and
     * refuse to operate. The worker enforces the same prefix independently.
     */
    private const VHOSTS_PREFIX = '/var/www/vhosts/';

    public function __construct(private readonly FtpUser $ftpUser)
    {
        $username = (string) ($ftpUser->username ?? '');
        $homedir = (string) ($ftpUser->homedir ?? '');

        // POSIX-portable usernames: lowercase letters, digits, underscore, hyphen.
        // First char must be letter or underscore; no leading hyphen (would be
        // misinterpreted as a runuser flag); no uppercase (ProFTPD/Linux conv.).
        if ($username === '' || ! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $username)) {
            throw new RuntimeException("Invalid FTP username: {$username}");
        }

        if ($homedir === '' || ! is_dir($homedir)) {
            throw new RuntimeException("FTP user homedir not accessible: {$homedir}");
        }

        $realHome = realpath($homedir);

        if ($realHome === false) {
            throw new RuntimeException("Failed to resolve homedir: {$homedir}");
        }

        // Tenant isolation: refuse anything outside the vhosts tree. Linux only
        // — tests run on Windows tmpdirs bypass this; production target is Linux.
        if (PHP_OS_FAMILY !== 'Windows' && ! str_starts_with($realHome, self::VHOSTS_PREFIX)) {
            throw new RuntimeException("Homedir outside allowed prefix: {$realHome}");
        }

        $this->username = $username;
        $this->homedir = rtrim($realHome, '/');
        $this->workerPath = base_path('scripts/fm-worker.php');

        if (! is_file($this->workerPath)) {
            throw new RuntimeException("File manager worker not found: {$this->workerPath}");
        }

        $this->runuserBin = $this->resolveBinary(self::RUNUSER_CANDIDATES, 'runuser');
        $this->phpBin = $this->resolveBinary(self::PHP_CANDIDATES, 'php');
    }

    private function resolveBinary(array $candidates, string $name): string
    {
        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        throw new RuntimeException("Required binary not found: {$name}");
    }

    public static function forUser(FtpUser $ftpUser): self
    {
        return new self($ftpUser);
    }

    public function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        $parts = explode('/', $path);
        $out = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            $out[] = $part;
        }

        return implode('/', $out);
    }

    /**
     * @return array<int, array{name: string, path: string, type: string, size: int|null, lastModified: int|null, permissions: string, permissionsOctal: string}>
     */
    public function listDirectory(string $path = ''): array
    {
        $json = $this->runWorker('list', ['path' => $path]);
        $items = json_decode($json, true);

        if (! is_array($items)) {
            throw new RuntimeException('Worker returned invalid list payload.');
        }

        return $items;
    }

    public function readFile(string $path): string
    {
        return $this->runWorker('read', ['path' => $path]);
    }

    public function writeFile(string $path, string $content): void
    {
        $this->runWorker('write', ['path' => $path], stdin: $content);
    }

    public function createDirectory(string $path): void
    {
        $this->runWorker('mkdir', ['path' => $path]);
    }

    public function delete(string $path): void
    {
        $this->runWorker('delete', ['paths' => json_encode([$path])]);
    }

    public function rename(string $from, string $to): void
    {
        $this->runWorker('rename', ['from' => $from, 'to' => $to]);
    }

    public function upload(string $directory, UploadedFile $file): string
    {
        $directory = $this->normalizePath($directory);
        $filename = $file->getClientOriginalName();
        $relative = $directory === '' ? $filename : $directory.'/'.$filename;

        $tmpPath = $file->getRealPath();

        if (! is_string($tmpPath) || $tmpPath === '') {
            throw new RuntimeException('Uploaded file has no temp path.');
        }

        // /tmp default is chmod 1777; the FTP user can read the tmp file.
        // If umask blocks it, widen the temp file mode before the move.
        @chmod($tmpPath, 0644);

        $this->runWorker('move-uploaded', [
            'src' => $tmpPath,
            'dest' => $relative,
        ]);

        return $relative;
    }

    /** @return resource */
    public function readStream(string $path)
    {
        $content = $this->runWorker('read', ['path' => $path]);

        $fp = fopen('php://temp', 'r+b');

        if ($fp === false) {
            throw new RuntimeException('Failed to allocate stream buffer.');
        }

        fwrite($fp, $content);
        rewind($fp);

        return $fp;
    }

    public function exists(string $path): bool
    {
        $out = $this->runWorker('exists', ['path' => $path]);

        return trim($out) === '1';
    }

    public function mimeType(string $path): string
    {
        // mime detection on the local fs uses finfo against the path; for
        // worker isolation we read the first chunk via worker and infer with
        // finfo from the buffer. Keep it simple: read the file (small for
        // editor) and use the binary check map. Most callers only use this
        // for download Content-Type which can stay generic.
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $known = [
            'txt' => 'text/plain', 'html' => 'text/html', 'htm' => 'text/html',
            'css' => 'text/css', 'js' => 'application/javascript',
            'json' => 'application/json', 'xml' => 'application/xml',
            'pdf' => 'application/pdf',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
            'zip' => 'application/zip', 'tar' => 'application/x-tar',
            'gz' => 'application/gzip',
        ];

        return $known[$extension] ?? 'application/octet-stream';
    }

    public function chmod(string $path, string $mode): bool
    {
        try {
            $this->runWorker('chmod', ['path' => $path, 'mode' => $mode]);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @param  array<int, string>  $paths
     */
    public function compress(array $paths, string $zipPath): void
    {
        $this->runWorker('compress', [
            'paths' => json_encode(array_values($paths)),
            'zip' => $zipPath,
        ]);
    }

    public function decompress(string $zipPath, string $destinationDir): void
    {
        $this->runWorker('decompress', [
            'zip' => $zipPath,
            'dest' => $destinationDir,
        ]);
    }

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

        return in_array($extension, $binaryExtensions, true);
    }

    public function getEditorLanguage(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $languageMap = [
            'php' => 'php',
            'js' => 'javascript', 'mjs' => 'javascript', 'cjs' => 'javascript', 'jsx' => 'javascript',
            'ts' => 'typescript', 'tsx' => 'typescript',
            'json' => 'json', 'html' => 'html', 'htm' => 'html',
            'css' => 'css', 'scss' => 'scss', 'less' => 'less',
            'xml' => 'xml', 'yaml' => 'yaml', 'yml' => 'yaml',
            'md' => 'markdown', 'sql' => 'sql',
            'sh' => 'shell', 'bash' => 'shell',
            'py' => 'python', 'rb' => 'ruby', 'go' => 'go', 'rs' => 'rust',
            'java' => 'java', 'c' => 'c', 'cpp' => 'cpp', 'h' => 'c',
            'vue' => 'html', 'env' => 'ini', 'ini' => 'ini', 'conf' => 'ini',
            'log' => 'plaintext', 'txt' => 'plaintext',
            'htaccess' => 'ini', 'gitignore' => 'plaintext',
            'dockerfile' => 'dockerfile', 'twig' => 'twig',
        ];

        if (str_ends_with($path, '.blade.php')) {
            return 'html';
        }

        return $languageMap[$extension] ?? 'plaintext';
    }

    /**
     * @param  array<string, string>  $args
     */
    private function buildCommand(string $action, array $args): array
    {
        $cmd = [
            $this->runuserBin,
            '-u', $this->username,
            '--',
            $this->phpBin,
            $this->workerPath,
            '--root='.$this->homedir,
            '--action='.$action,
        ];

        foreach ($args as $key => $value) {
            $cmd[] = '--'.$key.'='.$value;
        }

        return $cmd;
    }

    /**
     * Spawn the worker, optionally piping stdin, return stdout.
     *
     * @param  array<string, string>  $args
     */
    private function runWorker(string $action, array $args, ?string $stdin = null): string
    {
        $cmd = $this->buildCommand($action, $args);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException("Failed to spawn worker for action: {$action}");
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], true);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $msg = trim($stderr) !== '' ? trim($stderr) : "exit code {$exitCode}";
            throw new RuntimeException("File manager worker failed ({$action}): {$msg}");
        }

        return $stdout;
    }
}
