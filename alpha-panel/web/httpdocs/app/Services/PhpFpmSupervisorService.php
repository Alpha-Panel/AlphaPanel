<?php

namespace App\Services;

use App\Models\PhpVersion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class PhpFpmSupervisorService
{
    public function __construct(
        private PortainerService $portainer,
    ) {}

    public function enable(PhpVersion $phpVersion): void
    {
        $conf = $this->confPath($phpVersion);

        if (! File::exists($conf)) {
            File::copy($this->stubPath($phpVersion), $conf);
        }

        $ini = $this->phpIniPath($phpVersion);

        if (! File::exists($ini)) {
            File::copy($this->phpIniStubPath($phpVersion), $ini);
        }

        $this->reloadSupervisord();

        Log::info("PHP-FPM {$phpVersion->slug} enabled via supervisor");
    }

    public function disable(PhpVersion $phpVersion): void
    {
        $conf = $this->confPath($phpVersion);

        if (File::exists($conf)) {
            File::delete($conf);
        }

        $this->reloadSupervisord();

        Log::info("PHP-FPM {$phpVersion->slug} disabled via supervisor");
    }

    /**
     * Restart a running PHP-FPM process to pick up php.ini changes.
     */
    public function restartFpm(PhpVersion $phpVersion): void
    {
        $container = config('panel.php_code_server_container', 'php-code-server');
        $program = "php{$phpVersion->slug}-fpm";

        try {
            $this->portainer->execInContainer($container, ['supervisorctl', 'restart', $program]);

            Log::info("PHP-FPM {$phpVersion->slug} restarted via supervisor");
        } catch (\Throwable $e) {
            Log::error("Failed to restart PHP-FPM {$phpVersion->slug}: {$e->getMessage()}");

            throw $e;
        }
    }

    /**
     * Get the host-side path to a PHP version's custom php.ini file.
     */
    public function phpIniPath(PhpVersion $phpVersion): string
    {
        $root = config('panel.compose_project_root');

        return "{$root}/php-code-server/{$phpVersion->slug}/php.ini";
    }

    /**
     * Get the host-side path to the FrankenPHP php.ini file.
     */
    public function frankenPhpIniPath(): string
    {
        $root = config('panel.compose_project_root');

        return "{$root}/frankenphp/php.ini";
    }

    /**
     * Restart the FrankenPHP container to apply php.ini changes.
     */
    public function restartFrankenPhp(): void
    {
        $container = config('panel.frankenphp_container', 'frankenphp');

        try {
            $this->portainer->restartContainer($container);

            Log::info('FrankenPHP container restarted for php.ini changes');
        } catch (\Throwable $e) {
            Log::error("Failed to restart FrankenPHP container: {$e->getMessage()}");

            throw $e;
        }
    }

    private function confPath(PhpVersion $phpVersion): string
    {
        $root = config('panel.compose_project_root');

        return "{$root}/php-code-server/supervisor.d/php-fpm-{$phpVersion->slug}.conf";
    }

    private function stubPath(PhpVersion $phpVersion): string
    {
        $root = config('panel.compose_project_root');

        return "{$root}/php-code-server/supervisor.d/php-fpm-{$phpVersion->slug}.conf.stub";
    }

    private function phpIniStubPath(PhpVersion $phpVersion): string
    {
        $root = config('panel.compose_project_root');

        return "{$root}/php-code-server/{$phpVersion->slug}/php.ini.stub";
    }

    private function reloadSupervisord(): void
    {
        $container = config('panel.php_code_server_container', 'php-code-server');

        try {
            $this->portainer->execInContainer($container, ['supervisorctl', 'reread']);
            $this->portainer->execInContainer($container, ['supervisorctl', 'update']);

            Log::info('Supervisord reloaded in php-code-server container');
        } catch (\Throwable $e) {
            Log::error("Failed to reload supervisord in php-code-server: {$e->getMessage()}");

            throw $e;
        }
    }
}
