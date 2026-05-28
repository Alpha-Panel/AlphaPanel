<?php

namespace App\Services\Portainer;

use App\Exceptions\PortainerException;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class PortainerExecClient
{
    public function __construct(
        private PortainerHttpClient $http,
        private PortainerContainerClient $containers,
    ) {}

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
        $containerId = $this->containers->resolveContainerId($containerIdOrName);

        Log::info("Docker exec in {$containerIdOrName}: ".implode(' ', $command).($user ? " (user: {$user})" : ''));

        $payload = [
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Cmd' => $command,
            'Env' => ['COLUMNS=220'],
        ];

        if ($user !== null) {
            $payload['User'] = $user;
        }

        // Use docker-socket-proxy directly — bypasses Portainer's streaming proxy
        // which causes intermittent timeout issues on exec/start.
        $client = new Client([
            'connect_timeout' => 10,
            'timeout' => $timeout,
        ]);

        $createResponse = $client->post($this->http->directDockerApiUrl("/containers/{$containerId}/exec"), [
            'json' => $payload,
        ]);

        if ($createResponse->getStatusCode() >= 400) {
            throw new PortainerException("Failed to create exec instance: {$createResponse->getStatusCode()} {$createResponse->getBody()->getContents()}");
        }

        $execData = json_decode($createResponse->getBody()->getContents(), true);
        $execId = $execData['Id'] ?? null;

        if (! $execId) {
            throw new PortainerException('Failed to get exec ID from create response');
        }

        $startResponse = $client->post($this->http->directDockerApiUrl("/exec/{$execId}/start"), [
            'json' => [
                'Detach' => false,
                'Tty' => false,
            ],
            'timeout' => $timeout,
        ]);

        if ($startResponse->getStatusCode() >= 400) {
            throw new PortainerException("Failed to start exec instance: {$startResponse->getStatusCode()}");
        }

        $rawOutput = $startResponse->getBody()->getContents();
        $output = $this->http->demuxDockerStream($rawOutput);

        $inspectResponse = $client->get($this->http->directDockerApiUrl("/exec/{$execId}/json"), [
            'timeout' => 10,
        ]);

        $exitCode = -1;
        if ($inspectResponse->getStatusCode() < 400) {
            $inspectData = json_decode($inspectResponse->getBody()->getContents(), true);
            $exitCode = (int) ($inspectData['ExitCode'] ?? -1);
        }

        return new ExecResult(
            exitCode: $exitCode,
            output: $output['stdout'],
            errorOutput: $output['stderr'],
        );
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
        $payload = [
            'AttachStdin' => true,
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Tty' => true,
            'Cmd' => $command,
        ];

        if ($user !== null) {
            $payload['User'] = $user;
        }

        if ($workingDir !== null) {
            $payload['WorkingDir'] = $workingDir;
        }

        if ($env !== null) {
            $payload['Env'] = $env;
        }

        $response = $this->http->request()
            ->post($this->http->dockerApiUrl("/containers/{$containerId}/exec"), $payload);

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
        $base = rtrim($this->http->baseUrl(), '/');
        $scheme = str_starts_with($base, 'https') ? 'wss' : 'ws';
        $host = preg_replace('#^https?://#', '', $base);

        $query = http_build_query([
            'endpointId' => $this->http->endpointId(),
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
            'X-API-Key' => $this->http->apiKey(),
        ];
    }
}
