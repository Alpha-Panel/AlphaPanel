<?php

namespace App\Services;

use App\Exceptions\PortainerException;
use App\Services\Portainer\ExecResult;
use App\Services\Portainer\RunResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PortainerService
{
    private string $baseUrl;

    private string $apiKey;

    private int $endpointId;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('panel.portainer_url'), '/');
        $this->apiKey = (string) config('panel.portainer_api_key');
        $this->endpointId = (int) config('panel.portainer_endpoint_id', 1);
    }

    /**
     * Build the full Docker API URL proxied through Portainer.
     */
    private function dockerApiUrl(string $path): string
    {
        return "{$this->baseUrl}/api/endpoints/{$this->endpointId}/docker{$path}";
    }

    /**
     * Get an authenticated HTTP client instance.
     */
    private function request(int $timeout = 30): PendingRequest
    {
        return Http::withHeaders([
            'X-API-Key' => $this->apiKey,
        ])->withOptions([
            'verify' => false,
        ])->connectTimeout(5)->timeout($timeout);
    }

    /**
     * List containers with optional filters.
     *
     * @param  array<string, array<string>>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function listContainers(array $filters = [], bool $all = false): array
    {
        $query = ['all' => $all ? 'true' : 'false'];

        if ($filters) {
            $query['filters'] = json_encode($filters);
        }

        $response = $this->request()
            ->get($this->dockerApiUrl('/containers/json'), $query);

        if (! $response->successful()) {
            throw new PortainerException("Failed to list containers: {$response->status()} {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Find a running container by name.
     *
     * @return array<string, mixed>
     */
    public function findContainerByName(string $name): array
    {
        $containers = $this->listContainers(
            filters: ['name' => [$name]],
            all: false,
        );

        if (empty($containers)) {
            throw new PortainerException("Container not found: {$name}");
        }

        return $containers[0];
    }

    /**
     * Resolve a container name to its ID.
     */
    private function resolveContainerId(string $containerIdOrName): string
    {
        if (preg_match('/^[a-f0-9]{12,64}$/', $containerIdOrName)) {
            return $containerIdOrName;
        }

        $container = $this->findContainerByName($containerIdOrName);

        return $container['Id'];
    }

    /**
     * Start a stopped container via Docker API.
     */
    public function startContainer(string $containerIdOrName, int $timeout = 30): bool
    {
        $containerId = $this->resolveContainerId($containerIdOrName);

        Log::info("Portainer starting container: {$containerIdOrName}");

        $response = $this->request($timeout)
            ->post($this->dockerApiUrl("/containers/{$containerId}/start"));

        if ($response->successful() || $response->status() === 304) {
            Log::info("Container {$containerIdOrName} started successfully.");

            return true;
        }

        Log::error("Failed to start container {$containerIdOrName}: {$response->status()} {$response->body()}");

        return false;
    }

    /**
     * Stop a running container via Docker API.
     */
    public function stopContainer(string $containerIdOrName, int $timeout = 30): bool
    {
        $containerId = $this->resolveContainerId($containerIdOrName);

        Log::info("Portainer stopping container: {$containerIdOrName}");

        $response = $this->request($timeout)
            ->post($this->dockerApiUrl("/containers/{$containerId}/stop"), [
                't' => 10,
            ]);

        if ($response->successful() || $response->status() === 304) {
            Log::info("Container {$containerIdOrName} stopped successfully.");

            return true;
        }

        Log::error("Failed to stop container {$containerIdOrName}: {$response->status()} {$response->body()}");

        return false;
    }

    /**
     * Restart a container via Docker API.
     */
    public function restartContainer(string $containerIdOrName, int $timeout = 30): bool
    {
        $containerId = $this->resolveContainerId($containerIdOrName);

        Log::info("Portainer restarting container: {$containerIdOrName}");

        $response = $this->request($timeout)
            ->post($this->dockerApiUrl("/containers/{$containerId}/restart"), [
                't' => 10,
            ]);

        if ($response->successful()) {
            Log::info("Container {$containerIdOrName} restarted successfully.");

            return true;
        }

        Log::error("Failed to restart container {$containerIdOrName}: {$response->status()} {$response->body()}");

        return false;
    }

    /**
     * Execute a command inside a running container.
     *
     * @param  array<int, string>  $command
     */
    public function execInContainer(string $containerIdOrName, array $command, int $timeout = 60, ?string $user = null, int $retries = 1): ExecResult
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            try {
                return $this->doExecInContainer($containerIdOrName, $command, $timeout, $user);
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($attempt < $retries) {
                    Log::warning("Portainer exec attempt {$attempt}/{$retries} failed for {$containerIdOrName}, retrying in 3s: {$e->getMessage()}");
                    sleep(3);
                }
            }
        }

        throw $lastException instanceof PortainerException
            ? $lastException
            : new PortainerException($lastException->getMessage(), 0, $lastException);
    }

    /**
     * Internal exec implementation.
     *
     * @param  array<int, string>  $command
     */
    private function doExecInContainer(string $containerIdOrName, array $command, int $timeout, ?string $user): ExecResult
    {
        $containerId = $this->resolveContainerId($containerIdOrName);

        Log::info("Portainer exec in {$containerIdOrName}: ".implode(' ', $command).($user ? " (user: {$user})" : ''));

        $payload = [
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Cmd' => $command,
            'Env' => ['COLUMNS=220'],
        ];

        if ($user !== null) {
            $payload['User'] = $user;
        }

        $createResponse = $this->request($timeout)
            ->post($this->dockerApiUrl("/containers/{$containerId}/exec"), $payload);

        if (! $createResponse->successful()) {
            throw new PortainerException("Failed to create exec instance: {$createResponse->status()} {$createResponse->body()}");
        }

        $execId = $createResponse->json('Id');

        $startResponse = $this->request($timeout)
            ->post($this->dockerApiUrl("/exec/{$execId}/start"), [
                'Detach' => false,
                'Tty' => false,
            ]);

        if (! $startResponse->successful()) {
            throw new PortainerException("Failed to start exec instance: {$startResponse->status()} {$startResponse->body()}");
        }

        $rawOutput = $startResponse->body();
        $output = $this->demuxDockerStream($rawOutput);

        $inspectResponse = $this->request($timeout)
            ->get($this->dockerApiUrl("/exec/{$execId}/json"));

        $exitCode = $inspectResponse->successful()
            ? (int) $inspectResponse->json('ExitCode', -1)
            : -1;

        return new ExecResult(
            exitCode: $exitCode,
            output: $output['stdout'],
            errorOutput: $output['stderr'],
        );
    }

    /**
     * Create a one-shot container, run it, wait for completion, return output.
     *
     * @param  array<string, mixed>  $config
     */
    public function createAndRunContainer(array $config, int $timeout = 300): RunResult
    {
        $image = $config['Image'] ?? 'unknown';
        Log::info("Portainer creating container from image: {$image}");

        $createResponse = $this->request($timeout)
            ->post($this->dockerApiUrl('/containers/create'), $config);

        // If image not found locally, attempt to pull it from registry
        if ($createResponse->status() === 404 && str_contains($createResponse->body(), 'No such image')) {
            Log::info("Image {$image} not found locally, attempting to pull...");

            $pulled = $this->pullImage(
                ...array_pad(explode(':', $image, 2), 2, 'latest')
            );

            if (! $pulled) {
                throw new PortainerException("Failed to create container: image {$image} not found locally and pull failed");
            }

            $createResponse = $this->request($timeout)
                ->post($this->dockerApiUrl('/containers/create'), $config);
        }

        if (! $createResponse->successful()) {
            throw new PortainerException("Failed to create container: {$createResponse->status()} {$createResponse->body()}");
        }

        $containerId = $createResponse->json('Id');

        try {
            $startResponse = $this->request($timeout)
                ->post($this->dockerApiUrl("/containers/{$containerId}/start"));

            if (! $startResponse->successful() && $startResponse->status() !== 304) {
                throw new PortainerException("Failed to start container: {$startResponse->status()} {$startResponse->body()}");
            }

            $waitResponse = $this->request($timeout)
                ->post($this->dockerApiUrl("/containers/{$containerId}/wait"));

            $exitCode = $waitResponse->successful()
                ? (int) $waitResponse->json('StatusCode', -1)
                : -1;

            $logsResponse = $this->request($timeout)
                ->get($this->dockerApiUrl("/containers/{$containerId}/logs"), [
                    'stdout' => 'true',
                    'stderr' => 'true',
                ]);

            $output = $logsResponse->successful() ? $logsResponse->body() : '';

            return new RunResult(
                exitCode: $exitCode,
                output: $this->stripDockerStreamHeaders($output),
            );
        } finally {
            try {
                $this->request(10)
                    ->delete($this->dockerApiUrl("/containers/{$containerId}"), [
                        'force' => true,
                    ]);
            } catch (\Exception $e) {
                Log::warning("Failed to cleanup container {$containerId}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Get container resource stats (one-shot, non-streaming).
     *
     * @return array<string, mixed>
     */
    public function getContainerStats(string $containerIdOrName): array
    {
        $containerId = $this->resolveContainerId($containerIdOrName);

        $response = $this->request(10)
            ->get($this->dockerApiUrl("/containers/{$containerId}/stats"), [
                'stream' => 'false',
                'one-shot' => 'true',
            ]);

        if (! $response->successful()) {
            throw new PortainerException("Failed to get container stats: {$response->status()}");
        }

        return $response->json();
    }

    /**
     * Create an interactive exec instance with TTY (does not start it).
     *
     * @param  array<int, string>  $command
     * @return array{Id: string}
     */
    public function createInteractiveExec(string $containerId, array $command = ['/bin/sh']): array
    {
        $response = $this->request()
            ->post($this->dockerApiUrl("/containers/{$containerId}/exec"), [
                'AttachStdin' => true,
                'AttachStdout' => true,
                'AttachStderr' => true,
                'Tty' => true,
                'Cmd' => $command,
            ]);

        if (! $response->successful()) {
            throw new PortainerException("Failed to create interactive exec: {$response->status()} {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Get the WebSocket URL for an exec instance via Portainer.
     */
    public function getExecWebSocketUrl(string $execId): string
    {
        $base = rtrim($this->baseUrl, '/');
        $scheme = str_starts_with($base, 'https') ? 'wss' : 'ws';
        $host = preg_replace('#^https?://#', '', $base);

        $query = http_build_query([
            'endpointId' => $this->endpointId,
            'id' => $execId,
        ], encoding_type: PHP_QUERY_RFC3986);

        return "{$scheme}://{$host}/api/websocket/exec?{$query}";
    }

    /**
     * Get headers required for the exec WebSocket handshake via Portainer.
     *
     * @return array<string, string>
     */
    public function getExecWebSocketHeaders(): array
    {
        return [
            'X-API-Key' => $this->apiKey,
        ];
    }

    /**
     * Pull a Docker image from the registry.
     */
    public function pullImage(string $image, string $tag = 'latest'): bool
    {
        Log::info("Portainer pulling image: {$image}:{$tag}");

        $response = $this->request(300)
            ->post($this->dockerApiUrl("/images/create?fromImage={$image}&tag={$tag}"));

        if ($response->successful()) {
            Log::info("Image {$image}:{$tag} pulled successfully.");

            return true;
        }

        Log::error("Failed to pull image {$image}:{$tag}: {$response->status()} {$response->body()}");

        return false;
    }

    /**
     * Create a persistent container (not one-shot).
     *
     * @param  array<string, mixed>  $config  Docker container config
     * @return string Container ID
     */
    public function createPersistentContainer(array $config, ?string $name = null): string
    {
        $image = $config['Image'] ?? 'unknown';
        Log::info("Portainer creating persistent container from image: {$image}".($name ? " (name: {$name})" : ''));

        $url = '/containers/create';
        if ($name !== null) {
            $url .= '?name='.urlencode($name);
        }

        $response = $this->request()
            ->post($this->dockerApiUrl($url), $config);

        if (! $response->successful()) {
            throw new PortainerException("Failed to create persistent container: {$response->status()} {$response->body()}");
        }

        return $response->json('Id');
    }

    /**
     * Remove a container.
     */
    public function removeContainer(string $containerIdOrName, bool $force = false): bool
    {
        $containerId = $this->resolveContainerId($containerIdOrName);

        Log::info("Portainer removing container: {$containerIdOrName} (force: ".($force ? 'true' : 'false').')');

        $forceParam = $force ? 'true' : 'false';
        $response = $this->request()
            ->delete($this->dockerApiUrl("/containers/{$containerId}?force={$forceParam}&v=true"));

        if ($response->successful() || $response->status() === 404) {
            Log::info("Container {$containerIdOrName} removed successfully.");

            return true;
        }

        Log::error("Failed to remove container {$containerIdOrName}: {$response->status()} {$response->body()}");

        return false;
    }

    /**
     * Get container logs.
     */
    public function getContainerLogs(string $containerIdOrName, int $tail = 200, bool $timestamps = true): string
    {
        $containerId = $this->resolveContainerId($containerIdOrName);

        $timestampsParam = $timestamps ? 'true' : 'false';
        $response = $this->request()
            ->get($this->dockerApiUrl("/containers/{$containerId}/logs"), [
                'stdout' => 'true',
                'stderr' => 'true',
                'tail' => (string) $tail,
                'timestamps' => $timestampsParam,
            ]);

        if (! $response->successful()) {
            throw new PortainerException("Failed to get container logs: {$response->status()} {$response->body()}");
        }

        return $this->stripDockerStreamHeaders($response->body());
    }

    /**
     * Inspect a container for detailed info.
     *
     * @return array<string, mixed>
     */
    public function inspectContainer(string $containerIdOrName): array
    {
        $containerId = $this->resolveContainerId($containerIdOrName);

        $response = $this->request()
            ->get($this->dockerApiUrl("/containers/{$containerId}/json"));

        if (! $response->successful()) {
            throw new PortainerException("Failed to inspect container: {$response->status()} {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Inspect an image for config (env vars, exposed ports, volumes).
     *
     * @return array<string, mixed>
     */
    public function inspectImage(string $image): array
    {
        $response = $this->request()
            ->get($this->dockerApiUrl("/images/{$image}/json"));

        if (! $response->successful()) {
            throw new PortainerException("Failed to inspect image {$image}: {$response->status()} {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Connect a container to a network.
     */
    public function connectNetwork(string $containerId, string $networkName): bool
    {
        Log::info("Portainer connecting container {$containerId} to network: {$networkName}");

        $response = $this->request()
            ->post($this->dockerApiUrl("/networks/{$networkName}/connect"), [
                'Container' => $containerId,
            ]);

        if ($response->successful()) {
            Log::info("Container {$containerId} connected to network {$networkName} successfully.");

            return true;
        }

        Log::error("Failed to connect container {$containerId} to network {$networkName}: {$response->status()} {$response->body()}");

        return false;
    }

    /**
     * Demultiplex Docker stream output into stdout and stderr.
     *
     * Docker stream format: 8-byte header per frame
     * - Byte 0: stream type (0=stdin, 1=stdout, 2=stderr)
     * - Bytes 1-3: padding
     * - Bytes 4-7: frame size (big-endian uint32)
     *
     * @return array{stdout: string, stderr: string}
     */
    private function demuxDockerStream(string $raw): array
    {
        $stdout = '';
        $stderr = '';
        $offset = 0;
        $length = strlen($raw);

        while ($offset < $length) {
            if ($offset + 8 > $length) {
                $stdout .= substr($raw, $offset);
                break;
            }

            $header = unpack('Ctype/x3/Nsize', substr($raw, $offset, 8));

            if ($header === false || $offset + 8 + $header['size'] > $length) {
                $stdout .= substr($raw, $offset);
                break;
            }

            $frame = substr($raw, $offset + 8, $header['size']);
            $offset += 8 + $header['size'];

            match ($header['type']) {
                1 => $stdout .= $frame,
                2 => $stderr .= $frame,
                default => $stdout .= $frame,
            };
        }

        return ['stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Strip Docker stream headers from log output (simplified).
     */
    private function stripDockerStreamHeaders(string $raw): string
    {
        $result = $this->demuxDockerStream($raw);

        return $result['stdout'].$result['stderr'];
    }
}
