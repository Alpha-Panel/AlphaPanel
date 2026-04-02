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
     * Reload Caddy by executing frankenphp reload inside the frankenphp container.
     *
     * Note: caddy_main_config is the Caddyfile path inside alpha_panel_web (/etc/frankenphp-container/Caddyfile).
     * Inside the frankenphp container the same file is mounted at /etc/frankenphp/Caddyfile,
     * so we use caddy_reload_config (which defaults to the frankenphp container path).
     */
    public function reloadCaddy(): bool
    {
        $container = (string) config('panel.frankenphp_container', 'frankenphp');
        $configPath = (string) config('panel.caddy_reload_config', '/etc/frankenphp/Caddyfile');

        return $this->execInContainer($container, [
            'frankenphp', 'reload', '--config', $configPath,
        ]);
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
            $result = $this->portainer->execInContainer($container, $command, retries: 2);

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
