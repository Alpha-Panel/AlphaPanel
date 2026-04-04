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
     * Uses Docker exec with Detach=true so Docker returns HTTP 200 immediately and
     * the frankenphp reload process continues in the background. Coraza WAF + OWASP
     * CRS compilation during reload takes several minutes — we never wait for it.
     *
     * The call goes directly to docker-socket-proxy to avoid OPcache skew issues
     * that can occur when the Portainer service class is loaded from a stale worker.
     */
    public function reloadCaddy(): bool
    {
        $container = (string) config('panel.frankenphp_container', 'frankenphp');
        $configPath = (string) config('panel.caddy_reload_config', '/etc/frankenphp/Caddyfile');
        $proxyUrl = rtrim((string) config('panel.docker_socket_proxy_url', 'http://docker-socket-proxy:2375'), '/');

        try {
            $client = new \GuzzleHttp\Client(['connect_timeout' => 5, 'timeout' => 10]);

            // Resolve container name → ID
            $listResp = $client->get("{$proxyUrl}/containers/json", [
                'query' => ['filters' => json_encode(['name' => [$container]])],
            ]);
            $containers = json_decode($listResp->getBody()->getContents(), true);
            $containerId = $containers[0]['Id'] ?? null;

            if (! $containerId) {
                Log::error("Caddy reload: container '{$container}' not found.");

                return false;
            }

            // Create exec instance (no output attachment needed for fire-and-forget)
            $createResp = $client->post("{$proxyUrl}/containers/{$containerId}/exec", [
                'json' => [
                    'AttachStdout' => false,
                    'AttachStderr' => false,
                    'Cmd' => ['frankenphp', 'reload', '--config', $configPath],
                ],
            ]);
            $execId = json_decode($createResp->getBody()->getContents(), true)['Id'] ?? null;

            if (! $execId) {
                Log::error('Caddy reload: failed to obtain exec ID.');

                return false;
            }

            // Start with Detach=true — Docker returns HTTP 200 immediately.
            // The reload process keeps running inside the container.
            $client->post("{$proxyUrl}/exec/{$execId}/start", [
                'json' => ['Detach' => true, 'Tty' => false],
            ]);

            Log::info('Caddy reload triggered (detached).');

            return true;
        } catch (\Exception $e) {
            Log::error("Caddy reload failed: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Graceful reload of Apache (in php-code-server container).
     */
    public function reloadApache(): bool
    {
        return $this->execInContainer(
            config('panel.php_code_server_container', 'php-code-server'),
            ['apachectl', 'graceful'],
            timeout: 90,
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
            timeout: 90,
        );
    }

    /**
     * Execute a command inside a Docker container via Portainer.
     *
     * @param  array<int, string>  $command
     */
    private function execInContainer(string $container, array $command, int $timeout = 60): bool
    {
        try {
            $result = $this->portainer->execInContainer($container, $command, $timeout);

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
