<?php

namespace App\Services;

use App\Models\PhpVersion;
use Illuminate\Database\Eloquent\Collection;
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
     * Get supervisor process statuses for all enabled PHP versions.
     *
     * @param  Collection<int, PhpVersion>  $versions
     * @return array<string, array{conf_exists: bool, status: string}>
     */
    public function getSupervisorStatuses(Collection $versions): array
    {
        $statuses = [];

        foreach ($versions as $version) {
            if (! $version->is_enabled) {
                continue;
            }
            $statuses[$version->slug] = [
                'conf_exists' => File::exists($this->confPath($version)),
                'status' => 'UNKNOWN',
            ];
        }

        if (empty($statuses)) {
            return $statuses;
        }

        try {
            $container = config('panel.php_code_server_container', 'php-code-server');
            $result = $this->portainer->execInContainer($container, ['supervisorctl', 'status'], 15);
            $output = $result->output."\n".$result->errorOutput;

            foreach (explode("\n", $output) as $line) {
                if (preg_match('/^(php([\d.]+)-fpm)\s+(\w+)/i', trim($line), $matches)) {
                    $slug = $matches[2];
                    if (isset($statuses[$slug])) {
                        $statuses[$slug]['status'] = strtoupper($matches[3]);
                    }
                }
            }
        } catch (\Throwable) {
            foreach ($statuses as &$s) {
                $s['status'] = 'UNREACHABLE';
            }
        }

        foreach ($statuses as &$s) {
            if (! $s['conf_exists']) {
                $s['status'] = 'CONF_MISSING';
            }
        }

        return $statuses;
    }

    /**
     * Recreate a PHP-FPM supervisor config from its stub file.
     */
    public function recreateConf(PhpVersion $phpVersion): void
    {
        $stub = $this->stubPath($phpVersion);
        $conf = $this->confPath($phpVersion);

        if (! File::exists($stub)) {
            throw new \RuntimeException("Supervisor stub not found for PHP {$phpVersion->slug}");
        }

        File::copy($stub, $conf);
        $this->reloadSupervisord();

        Log::info("PHP-FPM {$phpVersion->slug} supervisor config recreated from stub");
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
