<?php

namespace App\Services;

use App\Models\DockerProject;
use App\Services\Portainer\ExecResult;
use App\Services\Portainer\PortainerContainerClient;
use App\Services\Portainer\PortainerExecClient;
use App\Services\Portainer\PortainerHttpClient;
use App\Services\Portainer\PortainerImageClient;
use App\Services\Portainer\RunResult;

class PortainerService
{
    private PortainerHttpClient $http;

    private PortainerImageClient $images;

    private PortainerContainerClient $containers;

    private PortainerExecClient $exec;

    public function __construct()
    {
        $this->http = new PortainerHttpClient;
        $this->images = new PortainerImageClient($this->http);
        $this->containers = new PortainerContainerClient($this->http, $this->images);
        $this->exec = new PortainerExecClient($this->http, $this->containers);
    }

    /**
     * List containers with optional filters.
     *
     * @param  array<string, array<string>>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function listContainers(array $filters = [], bool $all = false): array
    {
        return $this->containers->listContainers($filters, $all);
    }

    /**
     * Find a running container by name.
     *
     * @return array<string, mixed>
     */
    public function findContainerByName(string $name): array
    {
        return $this->containers->findContainerByName($name);
    }

    /**
     * Start a stopped container via Docker API.
     */
    public function startContainer(string $containerIdOrName, int $timeout = 30): bool
    {
        return $this->containers->startContainer($containerIdOrName, $timeout);
    }

    /**
     * Stop a running container via Docker API.
     */
    public function stopContainer(string $containerIdOrName, int $timeout = 30): bool
    {
        return $this->containers->stopContainer($containerIdOrName, $timeout);
    }

    /**
     * Restart a container via Docker API.
     */
    public function restartContainer(string $containerIdOrName, int $timeout = 30): bool
    {
        return $this->containers->restartContainer($containerIdOrName, $timeout);
    }

    /**
     * Execute a command inside a running container.
     *
     * @param  array<int, string>  $command
     */
    public function execInContainer(string $containerIdOrName, array $command, int $timeout = 60, ?string $user = null, int $retries = 1): ExecResult
    {
        return $this->exec->execInContainer($containerIdOrName, $command, $timeout, $user, $retries);
    }

    /**
     * Create a one-shot container, run it, wait for completion, return output.
     *
     * @param  array<string, mixed>  $config
     */
    public function createAndRunContainer(array $config, int $timeout = 300): RunResult
    {
        return $this->containers->createAndRunContainer($config, $timeout);
    }

    /**
     * Get container resource stats (one-shot, non-streaming).
     *
     * @return array<string, mixed>
     */
    public function getContainerStats(string $containerIdOrName): array
    {
        return $this->containers->getContainerStats($containerIdOrName);
    }

    /**
     * Create an interactive exec instance with TTY (does not start it).
     *
     * @param  array<int, string>  $command
     * @param  array<int, string>|null  $env  Environment variables as ["KEY=value", ...]
     * @return array{Id: string}
     */
    public function createInteractiveExec(
        string $containerId,
        array $command = ['/bin/sh'],
        ?string $user = null,
        ?string $workingDir = null,
        ?array $env = null,
    ): array {
        return $this->exec->createInteractiveExec($containerId, $command, $user, $workingDir, $env);
    }

    /**
     * Get the WebSocket URL for an exec instance via Portainer.
     */
    public function getExecWebSocketUrl(string $execId): string
    {
        return $this->exec->getExecWebSocketUrl($execId);
    }

    /**
     * Get headers required for the exec WebSocket handshake via Portainer.
     *
     * @return array<string, string>
     */
    public function getExecWebSocketHeaders(): array
    {
        return $this->exec->getExecWebSocketHeaders();
    }

    /**
     * Pull a Docker image from the registry.
     */
    public function pullImage(string $image, string $tag = 'latest'): bool
    {
        return $this->images->pullImage($image, $tag);
    }

    /**
     * Create a persistent container (not one-shot).
     *
     * @param  array<string, mixed>  $config  Docker container config
     * @return string Container ID
     */
    public function createPersistentContainer(array $config, ?string $name = null): string
    {
        return $this->containers->createPersistentContainer($config, $name);
    }

    /**
     * Remove a container.
     */
    public function removeContainer(string $containerIdOrName, bool $force = false): bool
    {
        return $this->containers->removeContainer($containerIdOrName, $force);
    }

    /**
     * Get container logs.
     */
    public function getContainerLogs(string $containerIdOrName, int $tail = 200, bool $timestamps = true): string
    {
        return $this->containers->getContainerLogs($containerIdOrName, $tail, $timestamps);
    }

    /**
     * Inspect a container for detailed info.
     *
     * @return array<string, mixed>
     */
    public function inspectContainer(string $containerIdOrName): array
    {
        return $this->containers->inspectContainer($containerIdOrName);
    }

    /**
     * Inspect an image for config (env vars, exposed ports, volumes).
     *
     * @return array<string, mixed>
     */
    public function inspectImage(string $image): array
    {
        return $this->images->inspectImage($image);
    }

    /**
     * Connect a container to a network.
     */
    public function connectNetwork(string $containerId, string $networkName): bool
    {
        return $this->containers->connectNetwork($containerId, $networkName);
    }

    /**
     * Create a Portainer compose stack from a YAML string.
     *
     * @return array<string, mixed>
     */
    public function createStack(string $name, string $composeContent): array
    {
        return $this->containers->createStack($name, $composeContent);
    }

    /**
     * Update an existing Portainer stack.
     *
     * @return array<string, mixed>
     */
    public function updateStack(int $stackId, string $composeContent): array
    {
        return $this->containers->updateStack($stackId, $composeContent);
    }

    /**
     * Delete a Portainer stack and its containers.
     */
    public function removeStack(int $stackId): void
    {
        $this->containers->removeStack($stackId);
    }

    /**
     * Start a stopped Portainer stack.
     */
    public function startStack(int $stackId): void
    {
        $this->containers->startStack($stackId);
    }

    /**
     * Stop a running Portainer stack.
     */
    public function stopStack(int $stackId): void
    {
        $this->containers->stopStack($stackId);
    }

    /**
     * List containers belonging to a Docker Compose project by label.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listContainersByProject(string $projectName): array
    {
        return $this->containers->listContainersByProject($projectName);
    }

    /**
     * Connect all containers of a compose project to a Docker network.
     */
    public function connectProjectContainersToNetwork(string $projectName, string $network = 'vhost_network'): void
    {
        $this->containers->connectProjectContainersToNetwork($projectName, $network);
    }

    /**
     * Build a Docker image from a project directory via docker-socket-proxy.
     *
     * Requires BUILD: 1 on the docker-socket-proxy service.
     *
     * @param  callable(int, string): void|null  $onProgress
     */
    public function buildImage(DockerProject $project, ?callable $onProgress = null): void
    {
        $this->images->buildImage($project, $onProgress);
    }
}
