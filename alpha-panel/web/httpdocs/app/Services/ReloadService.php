<?php

namespace App\Services;

use App\Models\PhpVersion;
use Illuminate\Support\Facades\Log;

class ReloadService
{
    public function __construct(
        private PortainerService $portainer,
    ) {}

    /**
     * Reload Caddy by executing frankenphp reload inside the container.
     */
    public function reloadCaddy(): bool
    {
        $container = (string) config('panel.frankenphp_container', 'frankenphp');
        $configuredConfigPath = (string) config('panel.caddy_main_config', '/etc/frankenphp/Caddyfile');
        $defaultConfigPath = '/etc/frankenphp/Caddyfile';
        $commands = [
            ['frankenphp', 'reload', '--config', $configuredConfigPath],
        ];

        if ($configuredConfigPath !== $defaultConfigPath) {
            $commands[] = ['frankenphp', 'reload', '--config', $defaultConfigPath];
        }

        foreach ($commands as $command) {
            if ($this->execInContainer($container, $command)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Graceful reload of Apache (in php-code-server container).
     */
    public function reloadApache(): bool
    {
        return $this->execInContainer(
            config('panel.php_code_server_container', 'php-code-server'),
            ['apachectl', 'graceful'],
        );
    }

    /**
     * Reload a specific PHP-FPM service.
     */
    public function reloadPhpFpm(PhpVersion $phpVersion): bool
    {
        return $this->execInContainer(
            config('panel.php_code_server_container', 'php-code-server'),
            ['service', $phpVersion->fpm_service_name, 'reload'],
        );
    }

    /**
     * Execute a command inside a Docker container via Portainer.
     *
     * @param  array<int, string>  $command
     */
    private function execInContainer(string $container, array $command): bool
    {
        try {
            $result = $this->portainer->execInContainer($container, $command);

            if ($result->isSuccessful()) {
                Log::info('Portainer exec succeeded: '.implode(' ', $command));

                return true;
            }

            Log::error('Portainer exec failed: '.$result->errorOutput);

            return false;
        } catch (\Exception $e) {
            Log::error("Portainer exec exception: {$e->getMessage()}");

            return false;
        }
    }
}
