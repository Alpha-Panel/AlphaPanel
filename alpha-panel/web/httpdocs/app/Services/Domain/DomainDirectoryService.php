<?php

namespace App\Services\Domain;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Services\PortainerService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class DomainDirectoryService
{
    /**
     * Ensure domain directories exist inside the php-code-server container.
     * Creates httpdocs, logs dirs and sets ownership to the FTP/pool user.
     */
    public function ensureDirectories(Domain $domain): void
    {
        $basePath = $domain->getBasePath();
        $webRoot = $domain->getWebRootPath();
        $logsPath = "{$basePath}/logs";
        $poolUser = $domain->getEffectiveFtpUsername();

        $portainer = app(PortainerService::class);

        // Create httpdocs and logs directories
        $portainer->execInContainer('php-code-server', [
            'mkdir', '-p', $webRoot, $logsPath,
        ]);

        // Owner: pool/FTP user. Group: www-data so Apache can read static
        // assets and traverse directories without per-file chown after every
        // PHP-generated upload.
        $portainer->execInContainer('php-code-server', [
            'chown', '-R', "{$poolUser}:www-data", $basePath,
        ]);

        // Ensure group has read+execute on every file/dir so Apache www-data
        // can serve static content the FPM pool generates.
        $portainer->execInContainer('php-code-server', [
            'chmod', '-R', 'g+rX', $basePath,
        ]);

        Log::info("Ensured directories for {$domain->fqdn}: {$webRoot}, {$logsPath} (owner: {$poolUser}:www-data)");

        $this->writeUserIni($domain);
    }

    /**
     * Write a .user.ini with open_basedir restriction to the domain's web root.
     * The file is owned by root and made immutable with chattr +i so site owners cannot modify or delete it.
     */
    public function writeUserIni(Domain $domain): void
    {
        $iniPath = escapeshellarg("{$domain->getWebRootPath()}/.user.ini");
        $openBasedir = implode(':', [$domain->getBasePath(), '/tmp', '/dev/urandom']);
        $escapedOpenBasedir = escapeshellarg($openBasedir);

        $container = $domain->type === DomainType::CaddyWebServer
            ? 'frankenphp'
            : 'php-code-server';

        $portainer = app(PortainerService::class);

        // Unlock if already immutable (ignore errors for new domains)
        $portainer->execInContainer($container, [
            'sh', '-c', "chattr -i {$iniPath} 2>/dev/null || true",
        ]);

        // Write the .user.ini file — use printf to avoid literal newline issues
        // in shell. The open_basedir value (fqdn-derived) is passed as a printf
        // argument via %s rather than interpolated into the format string, so it
        // cannot inject shell syntax even if a path contains metacharacters.
        $result = $portainer->execInContainer($container, [
            'sh', '-c', "printf '; AlphaPanel -- DO NOT MODIFY\nopen_basedir = %s\n' {$escapedOpenBasedir} > {$iniPath}",
        ]);

        if (! $result->isSuccessful()) {
            Log::error("Failed to write .user.ini for {$domain->fqdn}: {$result->errorOutput} {$result->output}");

            return;
        }

        // Set ownership to root and make read-only, then lock immutable
        $portainer->execInContainer($container, [
            'sh', '-c', "chown root:root {$iniPath} && chmod 444 {$iniPath} && chattr +i {$iniPath} 2>/dev/null || true",
        ]);

        Log::info("Wrote .user.ini for {$domain->fqdn} (open_basedir: {$openBasedir})");
    }

    /**
     * Atomically write a config file. fsync ensures the bytes hit the disk
     * before rename, so Caddy never sees a half-written file under high I/O.
     */
    public function writeConfigFile(string $filePath, string $content): void
    {
        $dir = dirname($filePath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $tempPath = $filePath.'.tmp.'.uniqid();

        $handle = @fopen($tempPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open temp config file: {$tempPath}");
        }

        try {
            if (@fwrite($handle, $content) === false) {
                throw new \RuntimeException("Failed to write temp config file: {$tempPath}");
            }
            @fflush($handle);
            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } finally {
            @fclose($handle);
        }

        if (! @rename($tempPath, $filePath)) {
            @unlink($tempPath);
            throw new \RuntimeException("Failed to move temp config file into place: {$filePath}");
        }

        Log::info("Configuration file written: {$filePath}");
    }
}
