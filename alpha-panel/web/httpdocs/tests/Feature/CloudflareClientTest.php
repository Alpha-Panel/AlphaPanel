<?php

namespace Tests\Feature;

use App\Exceptions\CloudflareException;
use App\Services\CloudflareClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class CloudflareClientTest extends TestCase
{
    private function makeClient(array $responses): CloudflareClient
    {
        config([
            'panel.cloudflare_api_token' => 'test-token',
            'panel.cloudflare_email' => null,
            'panel.cloudflare_api_key' => null,
        ]);

        $client = new CloudflareClient;

        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);
        $client->setHttpClient($httpClient);

        return $client;
    }

    private function makeClientWithApiKey(array $responses): CloudflareClient
    {
        config([
            'panel.cloudflare_api_token' => null,
            'panel.cloudflare_email' => 'test@example.com',
            'panel.cloudflare_api_key' => 'test-api-key',
        ]);

        $client = new CloudflareClient;

        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);
        $client->setHttpClient($httpClient);

        return $client;
    }

    public function test_get_returns_decoded_json(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'success' => true,
                'result' => [['id' => 'zone-123', 'name' => 'example.com']],
                'errors' => [],
            ])),
        ]);

        $response = $client->get('zones', ['name' => 'example.com']);

        $this->assertTrue($response['success']);
        $this->assertCount(1, $response['result']);
        $this->assertSame('zone-123', $response['result'][0]['id']);
    }

    public function test_post_returns_decoded_json(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'success' => true,
                'result' => ['id' => 'record-456'],
                'errors' => [],
            ])),
        ]);

        $response = $client->post('zones/zone-123/dns_records', [
            'type' => 'A',
            'name' => 'example.com',
            'content' => '1.2.3.4',
        ]);

        $this->assertTrue($response['success']);
        $this->assertSame('record-456', $response['result']['id']);
    }

    public function test_throws_on_api_error_response(): void
    {
        $client = $this->makeClient([
            new Response(403, [], json_encode([
                'success' => false,
                'errors' => [
                    ['code' => 9109, 'message' => 'Invalid access token'],
                ],
            ])),
        ]);

        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage('Invalid access token');

        $client->get('zones');
    }

    public function test_throws_on_invalid_json(): void
    {
        $client = $this->makeClient([
            new Response(200, [], 'not-json'),
        ]);

        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage('invalid JSON');

        $client->get('zones');
    }

    public function test_throws_when_no_credentials_configured(): void
    {
        config([
            'panel.cloudflare_api_token' => null,
            'panel.cloudflare_email' => null,
            'panel.cloudflare_api_key' => null,
        ]);

        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage('credentials');

        new CloudflareClient;
    }

    public function test_api_key_fallback_when_no_token(): void
    {
        $client = $this->makeClientWithApiKey([
            new Response(200, [], json_encode([
                'success' => true,
                'result' => [],
                'errors' => [],
            ])),
        ]);

        $response = $client->get('zones');
        $this->assertTrue($response['success']);
    }

    public function test_delete_returns_decoded_json(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'success' => true,
                'result' => ['id' => 'record-789'],
                'errors' => [],
            ])),
        ]);

        $response = $client->delete('zones/zone-123/dns_records/record-789');

        $this->assertTrue($response['success']);
    }

    public function test_patch_returns_decoded_json(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'success' => true,
                'result' => ['id' => 'setting-ssl', 'value' => 'full'],
                'errors' => [],
            ])),
        ]);

        $response = $client->patch('zones/zone-123/settings/ssl', ['value' => 'full']);

        $this->assertTrue($response['success']);
        $this->assertSame('full', $response['result']['value']);
    }

    public function test_put_returns_decoded_json(): void
    {
        $client = $this->makeClient([
            new Response(200, [], json_encode([
                'success' => true,
                'result' => ['id' => 'record-456', 'content' => '5.6.7.8'],
                'errors' => [],
            ])),
        ]);

        $response = $client->put('zones/zone-123/dns_records/record-456', [
            'type' => 'A',
            'name' => 'example.com',
            'content' => '5.6.7.8',
        ]);

        $this->assertTrue($response['success']);
        $this->assertSame('5.6.7.8', $response['result']['content']);
    }
}
