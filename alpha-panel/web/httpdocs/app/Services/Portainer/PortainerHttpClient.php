<?php

namespace App\Services\Portainer;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class PortainerHttpClient
{
    private string $baseUrl;

    private string $apiKey;

    private int $endpointId;

    private string $dockerSocketProxyUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('panel.portainer_url'), '/');
        $this->apiKey = (string) config('panel.portainer_api_key');
        $this->endpointId = (int) config('panel.portainer_endpoint_id', 1);
        $this->dockerSocketProxyUrl = rtrim((string) config('panel.docker_socket_proxy_url', 'http://docker-socket-proxy:2375'), '/');
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function endpointId(): int
    {
        return $this->endpointId;
    }

    /**
     * Build the full Docker API URL proxied through Portainer.
     */
    public function dockerApiUrl(string $path): string
    {
        return "{$this->baseUrl}/api/endpoints/{$this->endpointId}/docker{$path}";
    }

    /**
     * Build a direct Docker API URL via docker-socket-proxy (no Portainer layer).
     */
    public function directDockerApiUrl(string $path): string
    {
        return "{$this->dockerSocketProxyUrl}{$path}";
    }

    /**
     * Build the Portainer management API URL (not the Docker passthrough).
     */
    public function portainerApiUrl(string $path): string
    {
        return "{$this->baseUrl}{$path}";
    }

    /**
     * Get an authenticated HTTP client instance.
     */
    public function request(int $timeout = 30): PendingRequest
    {
        return Http::withHeaders([
            'X-API-Key' => $this->apiKey,
        ])->withOptions([
            'verify' => false,
        ])->connectTimeout(5)->timeout($timeout);
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
    public function demuxDockerStream(string $raw): array
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
    public function stripDockerStreamHeaders(string $raw): string
    {
        $result = $this->demuxDockerStream($raw);

        return $result['stdout'].$result['stderr'];
    }
}
