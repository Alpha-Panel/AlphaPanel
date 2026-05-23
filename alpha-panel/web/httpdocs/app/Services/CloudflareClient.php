<?php

namespace App\Services;

use App\Exceptions\CloudflareException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class CloudflareClient
{
    private Client $http;

    public function __construct()
    {
        $apiToken = config('panel.cloudflare_api_token');
        $email = config('panel.cloudflare_email');
        $apiKey = config('panel.cloudflare_api_key');

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if (! empty($apiToken)) {
            $headers['Authorization'] = "Bearer {$apiToken}";
        } elseif (! empty($email) && ! empty($apiKey)) {
            $headers['X-Auth-Email'] = $email;
            $headers['X-Auth-Key'] = $apiKey;
        } else {
            throw new CloudflareException('Cloudflare API credentials are not configured (set CLOUDFLARE_API_TOKEN or CLOUDFLARE_EMAIL + CLOUDFLARE_APIKEY).');
        }

        $stack = HandlerStack::create();
        $stack->push(Middleware::retry(
            $this->retryDecider(),
            $this->retryDelay(),
        ));

        $this->http = new Client([
            'base_uri' => 'https://api.cloudflare.com/client/v4/',
            'headers' => $headers,
            'timeout' => 30,
            'handler' => $stack,
            'http_errors' => true,
        ]);
    }

    /**
     * Retry on Cloudflare 429 (rate limit) and transient 5xx errors. Cap at 5 attempts.
     */
    private function retryDecider(): \Closure
    {
        return function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?\Throwable $exception = null,
        ): bool {
            if ($retries >= 5) {
                return false;
            }

            if ($response instanceof ResponseInterface) {
                $status = $response->getStatusCode();
                if ($status === 429 || ($status >= 500 && $status < 600)) {
                    Log::warning("Cloudflare API {$status}; retrying (attempt ".($retries + 1).').');

                    return true;
                }
            }

            return false;
        };
    }

    /**
     * Honour Cloudflare Retry-After (seconds). Falls back to exponential backoff + jitter.
     */
    private function retryDelay(): \Closure
    {
        return function (int $retries, ?ResponseInterface $response = null): int {
            if ($response instanceof ResponseInterface && $response->hasHeader('Retry-After')) {
                $retryAfter = (int) $response->getHeaderLine('Retry-After');
                if ($retryAfter > 0) {
                    return min($retryAfter, 60) * 1000;
                }
            }

            $base = min(2 ** $retries, 30);

            return ($base * 1000) + random_int(0, 500);
        };
    }

    /**
     * Allow injecting a custom Guzzle client (for testing).
     */
    public function setHttpClient(Client $client): void
    {
        $this->http = $client;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    public function get(string $uri, array $query = []): array
    {
        return $this->request('GET', $uri, ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    public function post(string $uri, array $data = []): array
    {
        return $this->request('POST', $uri, ['json' => $data]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    public function put(string $uri, array $data = []): array
    {
        return $this->request('PUT', $uri, ['json' => $data]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    public function patch(string $uri, array $data = []): array
    {
        return $this->request('PATCH', $uri, ['json' => $data]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    public function delete(string $uri): array
    {
        return $this->request('DELETE', $uri);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    private function request(string $method, string $uri, array $options = []): array
    {
        try {
            $response = $this->http->request($method, $uri, $options);

            return $this->handleResponse($response);
        } catch (GuzzleException $e) {
            throw new CloudflareException("Cloudflare API request failed: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    private function handleResponse(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        if (! is_array($body)) {
            throw new CloudflareException('Cloudflare API returned invalid JSON.');
        }

        if (! ($body['success'] ?? false)) {
            $errors = $body['errors'] ?? [];
            $messages = array_map(
                fn (array $error) => "[{$error['code']}] {$error['message']}",
                $errors,
            );

            throw new CloudflareException(
                'Cloudflare API error: '.implode('; ', $messages ?: ['Unknown error']),
            );
        }

        return $body;
    }
}
