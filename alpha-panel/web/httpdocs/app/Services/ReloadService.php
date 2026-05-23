<?php

namespace App\Services;

use App\Models\PhpVersion;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class ReloadService
{
    public function __construct(
        private PortainerService $portainer,
    ) {}

    /**
     * Reload Caddy by executing frankenphp reload inside the frankenphp container.
     *
     * Synchronous: waits for the reload command to finish and checks its exit code.
     * Detached mode hides WAF/CRS compile failures, leaving the panel reporting
     * success while Caddy continues serving stale config. Timeout is generous to
     * accommodate Coraza WAF + OWASP CRS compilation.
     */
    public function reloadCaddy(): bool
    {
        $container = (string) config('panel.frankenphp_container', 'frankenphp');
        $configPath = (string) config('panel.caddy_reload_config', '/etc/frankenphp/Caddyfile');
        $proxyUrl = rtrim((string) config('panel.docker_socket_proxy_url', 'http://docker-socket-proxy:2375'), '/');
        $reloadTimeout = (int) config('panel.caddy_reload_timeout', 300);

        try {
            $client = new Client(['connect_timeout' => 5, 'timeout' => $reloadTimeout]);

            $listResp = $client->get("{$proxyUrl}/containers/json", [
                'query' => ['filters' => json_encode(['name' => [$container]])],
            ]);
            $containers = json_decode($listResp->getBody()->getContents(), true);
            $containerId = $containers[0]['Id'] ?? null;

            if (! $containerId) {
                Log::error("Caddy reload: container '{$container}' not found.");

                return false;
            }

            $createResp = $client->post("{$proxyUrl}/containers/{$containerId}/exec", [
                'json' => [
                    'AttachStdout' => true,
                    'AttachStderr' => true,
                    'Cmd' => ['frankenphp', 'reload', '--config', $configPath],
                ],
            ]);
            $execId = json_decode($createResp->getBody()->getContents(), true)['Id'] ?? null;

            if (! $execId) {
                Log::error('Caddy reload: failed to obtain exec ID.');

                return false;
            }

            $client->post("{$proxyUrl}/exec/{$execId}/start", [
                'json' => ['Detach' => false, 'Tty' => false],
            ]);

            $inspectResp = $client->get("{$proxyUrl}/exec/{$execId}/json");
            $inspect = json_decode($inspectResp->getBody()->getContents(), true);
            $exitCode = $inspect['ExitCode'] ?? null;

            if ($exitCode !== 0) {
                Log::error("Caddy reload exited with code {$exitCode}; falling back to restart.");

                return $this->restartCaddy();
            }

            Log::info('Caddy reload completed successfully.');

            return true;
        } catch (\Exception $e) {
            Log::error("Caddy reload failed: {$e->getMessage()}; falling back to restart.");

            return $this->restartCaddy();
        }
    }

    /**
     * Restart the frankenphp container synchronously.
     *
     * Uses Docker Engine API (via docker-socket-proxy) to issue a container restart.
     * Waits for the restart to complete before returning. This is more reliable than
     * `frankenphp reload` because it guarantees the container re-reads all Caddyfile
     * imports (sites-enabled/*) from disk — reload can miss newly created site files
     * in some edge cases, and its detached execution makes failures invisible.
     *
     * The frankenphp container is separate from alpha_panel_web, so restarting it
     * does not affect the panel UI itself.
     */
    public function restartCaddy(): bool
    {
        $container = (string) config('panel.frankenphp_container', 'frankenphp');
        $proxyUrl = rtrim((string) config('panel.docker_socket_proxy_url', 'http://docker-socket-proxy:2375'), '/');
        $stopTimeout = (int) config('panel.frankenphp_restart_timeout', 5);

        try {
            $client = new Client(['connect_timeout' => 5, 'timeout' => 60]);

            $listResp = $client->get("{$proxyUrl}/containers/json", [
                'query' => [
                    'all' => 'true',
                    'filters' => json_encode(['name' => [$container]]),
                ],
            ]);
            $containers = json_decode($listResp->getBody()->getContents(), true);
            $containerId = $containers[0]['Id'] ?? null;

            if (! $containerId) {
                Log::error("Caddy restart: container '{$container}' not found.");

                return false;
            }

            $client->post("{$proxyUrl}/containers/{$containerId}/restart", [
                'query' => ['t' => $stopTimeout],
            ]);

            Log::info("Caddy restart completed (container={$container}).");

            return true;
        } catch (\Exception $e) {
            Log::error("Caddy restart failed: {$e->getMessage()}");

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
