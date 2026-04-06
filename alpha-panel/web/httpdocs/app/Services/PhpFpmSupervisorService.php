<?php

namespace App\Services;

use App\Models\PhpVersion;
use Illuminate\Support\Facades\Log;

class PhpFpmSupervisorService
{
    public function __construct(
        private PortainerService $portainer,
    ) {}

    /**
     * Enable a PHP-FPM version by uncommenting its supervisor config and reloading.
     */
    public function enable(PhpVersion $phpVersion): void
    {
        $this->uncommentConf($phpVersion);
        $this->reloadSupervisord();

        Log::info("PHP-FPM {$phpVersion->slug} enabled via supervisor");
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

    /**
     * Disable a PHP-FPM version by commenting out its supervisor config and reloading.
     */
    public function disable(PhpVersion $phpVersion): void
    {
        $this->commentConf($phpVersion);
        $this->reloadSupervisord();

        Log::info("PHP-FPM {$phpVersion->slug} disabled via supervisor");
    }

    private function confPath(PhpVersion $phpVersion): string
    {
        $root = config('panel.compose_project_root');

        return "{$root}/php-code-server/supervisor.d/php-fpm-{$phpVersion->slug}.conf";
    }

    /**
     * Remove leading ; from each line to activate the supervisor program.
     */
    private function uncommentConf(PhpVersion $phpVersion): void
    {
        $path = $this->confPath($phpVersion);
        $content = file_get_contents($path);
        $content = preg_replace('/^;/m', '', $content);
        file_put_contents($path, $content);

        Log::info("PHP-FPM supervisor conf uncommented: php-fpm-{$phpVersion->slug}.conf");
    }

    /**
     * Add leading ; to each non-empty line to deactivate the supervisor program.
     */
    private function commentConf(PhpVersion $phpVersion): void
    {
        $path = $this->confPath($phpVersion);
        $content = file_get_contents($path);
        $content = preg_replace('/^(?!;)(.+)$/m', ';$1', $content);
        file_put_contents($path, $content);

        Log::info("PHP-FPM supervisor conf commented: php-fpm-{$phpVersion->slug}.conf");
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
