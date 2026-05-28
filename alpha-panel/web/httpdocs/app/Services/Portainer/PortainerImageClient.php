<?php

namespace App\Services\Portainer;

use App\Exceptions\PortainerException;
use App\Models\DockerProject;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class PortainerImageClient
{
    public function __construct(
        private PortainerHttpClient $http,
    ) {}

    /**
     * Pull a Docker image from the registry.
     */
    public function pullImage(string $image, string $tag = 'latest'): bool
    {
        Log::info("Portainer pulling image: {$image}:{$tag}");

        $response = $this->http->request(300)
            ->post($this->http->dockerApiUrl("/images/create?fromImage={$image}&tag={$tag}"));

        if ($response->successful()) {
            Log::info("Image {$image}:{$tag} pulled successfully.");

            return true;
        }

        Log::error("Failed to pull image {$image}:{$tag}: {$response->status()} {$response->body()}");

        return false;
    }

    /**
     * Inspect an image for config (env vars, exposed ports, volumes).
     *
     * @return array<string, mixed>
     */
    public function inspectImage(string $image): array
    {
        $response = $this->http->request()
            ->get($this->http->dockerApiUrl("/images/{$image}/json"));

        if (! $response->successful()) {
            throw new PortainerException("Failed to inspect image {$image}: {$response->status()} {$response->body()}");
        }

        return $response->json();
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
        $projectPath = $project->projectPath();
        $imageTag = $project->imageTag();

        Log::info("Building Docker image {$imageTag} from {$projectPath}");

        if (! is_dir($projectPath)) {
            throw new PortainerException("Project directory not found: {$projectPath}");
        }

        // Create a temporary tar of the build context
        $tempTar = tempnam(sys_get_temp_dir(), 'docker_build_').'.tar';

        try {
            $phar = new \PharData($tempTar);
            $phar->buildFromDirectory($projectPath);

            if ($onProgress) {
                $onProgress(20, __('Build context created. Sending to Docker...'));
            }

            $client = new Client([
                'connect_timeout' => 10,
                'timeout' => 600,
            ]);

            $response = $client->post($this->http->directDockerApiUrl('/build'), [
                'query' => [
                    't' => $imageTag,
                    'dockerfile' => 'Dockerfile',
                    'rm' => 'true',
                ],
                'headers' => [
                    'Content-Type' => 'application/x-tar',
                ],
                'body' => fopen($tempTar, 'r'),
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400) {
                throw new PortainerException("Docker build API returned {$statusCode}: ".$response->getBody()->getContents());
            }

            // Parse streaming NDJSON build output for errors
            $body = $response->getBody()->getContents();
            foreach (explode("\n", trim($body)) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (isset($decoded['error'])) {
                    throw new PortainerException('Docker build error: '.$decoded['error']);
                }
            }

            Log::info("Docker image {$imageTag} built successfully.");

            if ($onProgress) {
                $onProgress(90, __('Image built. Finalizing...'));
            }
        } finally {
            @unlink($tempTar);
        }
    }
}
