<?php

namespace App\Services\Portainer;

use App\Exceptions\PortainerException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PortainerContainerClient
{
    public function __construct(
        private PortainerHttpClient $http,
        private PortainerImageClient $images,
    ) {}

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

        $response = $this->http->request()
            ->get($this->http->dockerApiUrl('/containers/json'), $query);

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
     * Resolve a container name to its ID using docker-socket-proxy directly.
     */
    public function resolveContainerId(string $containerIdOrName): string
    {
        if (preg_match('/^[a-f0-9]{12,64}$/', $containerIdOrName)) {
            return $containerIdOrName;
        }

        // Use docker-socket-proxy directly for faster, more reliable resolution
        $response = Http::connectTimeout(5)->timeout(10)
            ->get($this->http->directDockerApiUrl('/containers/json'), [
                'filters' => json_encode(['name' => [$containerIdOrName]]),
            ]);

        if ($response->successful()) {
            $containers = $response->json();
            if (! empty($containers)) {
                return $containers[0]['Id'];
            }
        }

        // Fallback to Portainer
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

        $response = $this->http->request($timeout)
            ->post($this->http->dockerApiUrl("/containers/{$containerId}/start"));

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

        $response = $this->http->request($timeout)
            ->post($this->http->dockerApiUrl("/containers/{$containerId}/stop"), [
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

        $response = $this->http->request($timeout)
            ->post($this->http->dockerApiUrl("/containers/{$containerId}/restart"), [
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
     * Create a one-shot container, run it, wait for completion, return output.
     *
     * @param  array<string, mixed>  $config
     */
    public function createAndRunContainer(array $config, int $timeout = 300): RunResult
    {
        $image = $config['Image'] ?? 'unknown';
        Log::info("Portainer creating container from image: {$image}");

        $createResponse = $this->http->request($timeout)
            ->post($this->http->dockerApiUrl('/containers/create'), $config);

        // If image not found locally, attempt to pull it from registry
        if ($createResponse->status() === 404 && str_contains($createResponse->body(), 'No such image')) {
            Log::info("Image {$image} not found locally, attempting to pull...");

            $pulled = $this->images->pullImage(
                ...array_pad(explode(':', $image, 2), 2, 'latest')
            );

            if (! $pulled) {
                throw new PortainerException("Failed to create container: image {$image} not found locally and pull failed");
            }

            $createResponse = $this->http->request($timeout)
                ->post($this->http->dockerApiUrl('/containers/create'), $config);
        }

        if (! $createResponse->successful()) {
            throw new PortainerException("Failed to create container: {$createResponse->status()} {$createResponse->body()}");
        }

        $containerId = $createResponse->json('Id');

        try {
            $startResponse = $this->http->request($timeout)
                ->post($this->http->dockerApiUrl("/containers/{$containerId}/start"));

            if (! $startResponse->successful() && $startResponse->status() !== 304) {
                throw new PortainerException("Failed to start container: {$startResponse->status()} {$startResponse->body()}");
            }

            $waitResponse = $this->http->request($timeout)
                ->post($this->http->dockerApiUrl("/containers/{$containerId}/wait"));

            $exitCode = $waitResponse->successful()
                ? (int) $waitResponse->json('StatusCode', -1)
                : -1;

            $logsResponse = $this->http->request($timeout)
                ->get($this->http->dockerApiUrl("/containers/{$containerId}/logs"), [
                    'stdout' => 'true',
                    'stderr' => 'true',
                ]);

            $output = $logsResponse->successful() ? $logsResponse->body() : '';

            return new RunResult(
                exitCode: $exitCode,
                output: $this->http->stripDockerStreamHeaders($output),
            );
        } finally {
            try {
                $this->http->request(10)
                    ->delete($this->http->dockerApiUrl("/containers/{$containerId}"), [
                        'force' => true,
                    ]);
            } catch (\Exception $e) {
                Log::warning("Failed to cleanup container {$containerId}: {$e->getMessage()}", [
                    'container_id' => $containerId,
                    'exception' => $e,
                ]);
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

        $response = $this->http->request(10)
            ->get($this->http->dockerApiUrl("/containers/{$containerId}/stats"), [
                'stream' => 'false',
                'one-shot' => 'true',
            ]);

        if (! $response->successful()) {
            throw new PortainerException("Failed to get container stats: {$response->status()}");
        }

        return $response->json();
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

        $response = $this->http->request()
            ->post($this->http->dockerApiUrl($url), $config);

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
        $response = $this->http->request()
            ->delete($this->http->dockerApiUrl("/containers/{$containerId}?force={$forceParam}&v=true"));

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
        $response = $this->http->request()
            ->get($this->http->dockerApiUrl("/containers/{$containerId}/logs"), [
                'stdout' => 'true',
                'stderr' => 'true',
                'tail' => (string) $tail,
                'timestamps' => $timestampsParam,
            ]);

        if (! $response->successful()) {
            throw new PortainerException("Failed to get container logs: {$response->status()} {$response->body()}");
        }

        return $this->http->stripDockerStreamHeaders($response->body());
    }

    /**
     * Inspect a container for detailed info.
     *
     * @return array<string, mixed>
     */
    public function inspectContainer(string $containerIdOrName): array
    {
        $containerId = $this->resolveContainerId($containerIdOrName);

        $response = $this->http->request()
            ->get($this->http->dockerApiUrl("/containers/{$containerId}/json"));

        if (! $response->successful()) {
            throw new PortainerException("Failed to inspect container: {$response->status()} {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Connect a container to a network.
     */
    public function connectNetwork(string $containerId, string $networkName): bool
    {
        Log::info("Portainer connecting container {$containerId} to network: {$networkName}");

        $response = $this->http->request()
            ->post($this->http->dockerApiUrl("/networks/{$networkName}/connect"), [
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
     * Create a Portainer compose stack from a YAML string.
     *
     * @return array<string, mixed>
     */
    public function createStack(string $name, string $composeContent): array
    {
        Log::info("Portainer creating stack: {$name}");

        $response = $this->http->request(300)
            ->post($this->http->portainerApiUrl("/api/stacks/create/standalone/string?endpointId={$this->http->endpointId()}"), [
                'Name' => $name,
                'StackFileContent' => $composeContent,
                'Env' => [],
            ]);

        if (! $response->successful()) {
            throw new PortainerException("Failed to create stack {$name}: {$response->status()} {$response->body()}");
        }

        Log::info("Stack {$name} created successfully.");

        return $response->json();
    }

    /**
     * Update an existing Portainer stack.
     *
     * @return array<string, mixed>
     */
    public function updateStack(int $stackId, string $composeContent): array
    {
        Log::info("Portainer updating stack ID: {$stackId}");

        $response = $this->http->request(300)
            ->put($this->http->portainerApiUrl("/api/stacks/{$stackId}?endpointId={$this->http->endpointId()}"), [
                'StackFileContent' => $composeContent,
                'Env' => [],
                'Prune' => false,
                'PullImage' => true,
            ]);

        if (! $response->successful()) {
            throw new PortainerException("Failed to update stack {$stackId}: {$response->status()} {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Delete a Portainer stack and its containers.
     */
    public function removeStack(int $stackId): void
    {
        Log::info("Portainer removing stack ID: {$stackId}");

        $response = $this->http->request(120)
            ->delete($this->http->portainerApiUrl("/api/stacks/{$stackId}?endpointId={$this->http->endpointId()}"));

        if (! $response->successful() && $response->status() !== 404) {
            throw new PortainerException("Failed to remove stack {$stackId}: {$response->status()} {$response->body()}");
        }

        Log::info("Stack {$stackId} removed successfully.");
    }

    /**
     * Start a stopped Portainer stack.
     */
    public function startStack(int $stackId): void
    {
        Log::info("Portainer starting stack ID: {$stackId}");

        $response = $this->http->request(120)
            ->post($this->http->portainerApiUrl("/api/stacks/{$stackId}/start?endpointId={$this->http->endpointId()}"));

        if (! $response->successful()) {
            throw new PortainerException("Failed to start stack {$stackId}: {$response->status()} {$response->body()}");
        }
    }

    /**
     * Stop a running Portainer stack.
     */
    public function stopStack(int $stackId): void
    {
        Log::info("Portainer stopping stack ID: {$stackId}");

        $response = $this->http->request(120)
            ->post($this->http->portainerApiUrl("/api/stacks/{$stackId}/stop?endpointId={$this->http->endpointId()}"));

        if (! $response->successful()) {
            throw new PortainerException("Failed to stop stack {$stackId}: {$response->status()} {$response->body()}");
        }
    }

    /**
     * List containers belonging to a Docker Compose project by label.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listContainersByProject(string $projectName): array
    {
        return $this->listContainers(
            filters: ['label' => ["com.docker.compose.project={$projectName}"]],
            all: true,
        );
    }

    /**
     * Connect all containers of a compose project to a Docker network.
     */
    public function connectProjectContainersToNetwork(string $projectName, string $network = 'vhost_network'): void
    {
        $containers = $this->listContainersByProject($projectName);

        foreach ($containers as $container) {
            $id = $container['Id'] ?? '';
            if (! $id) {
                continue;
            }

            try {
                $this->connectNetwork($id, $network);
            } catch (\Exception $e) {
                $names = implode(', ', $container['Names'] ?? [$id]);
                Log::warning("Failed to connect container {$names} to {$network}: {$e->getMessage()}");
            }
        }
    }
}
