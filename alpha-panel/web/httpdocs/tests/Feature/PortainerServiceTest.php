<?php

namespace Tests\Feature;

use App\Exceptions\PortainerException;
use App\Services\PortainerService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortainerServiceTest extends TestCase
{
    private PortainerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panel.portainer_url' => 'https://portainer.test:8443',
            'panel.portainer_api_key' => 'test-api-key',
            'panel.portainer_endpoint_id' => 1,
        ]);

        $this->service = new PortainerService;
    }

    public function test_list_containers_returns_container_array(): void
    {
        Http::fake([
            'portainer.test:8443/api/endpoints/1/docker/containers/json*' => Http::response([
                ['Id' => 'abc123', 'Names' => ['/frankenphp']],
                ['Id' => 'def456', 'Names' => ['/php-code-server']],
            ]),
        ]);

        $containers = $this->service->listContainers();

        $this->assertCount(2, $containers);
        $this->assertSame('abc123', $containers[0]['Id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/containers/json')
                && $request->hasHeader('X-API-Key', 'test-api-key');
        });
    }

    public function test_list_containers_with_filters(): void
    {
        Http::fake([
            'portainer.test:8443/api/endpoints/1/docker/containers/json*' => Http::response([
                ['Id' => 'abc123', 'Names' => ['/frankenphp']],
            ]),
        ]);

        $containers = $this->service->listContainers(
            filters: ['name' => ['frankenphp']],
        );

        $this->assertCount(1, $containers);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'filters=');
        });
    }

    public function test_list_containers_throws_on_failure(): void
    {
        Http::fake([
            'portainer.test:8443/api/endpoints/1/docker/containers/json*' => Http::response('Unauthorized', 401),
        ]);

        $this->expectException(PortainerException::class);
        $this->expectExceptionMessage('Failed to list containers');

        $this->service->listContainers();
    }

    public function test_find_container_by_name_returns_first_match(): void
    {
        Http::fake([
            'portainer.test:8443/api/endpoints/1/docker/containers/json*' => Http::response([
                ['Id' => 'abc123', 'Names' => ['/frankenphp']],
            ]),
        ]);

        $container = $this->service->findContainerByName('frankenphp');

        $this->assertSame('abc123', $container['Id']);
    }

    public function test_find_container_by_name_throws_when_not_found(): void
    {
        Http::fake([
            'portainer.test:8443/api/endpoints/1/docker/containers/json*' => Http::response([]),
        ]);

        $this->expectException(PortainerException::class);
        $this->expectExceptionMessage('Container not found: nonexistent');

        $this->service->findContainerByName('nonexistent');
    }

    public function test_exec_in_container_full_flow(): void
    {
        Http::fake([
            // findContainerByName
            'portainer.test:8443/api/endpoints/1/docker/containers/json*' => Http::response([
                ['Id' => 'abc123def456', 'Names' => ['/frankenphp']],
            ]),
            // exec create
            'portainer.test:8443/api/endpoints/1/docker/containers/abc123def456/exec' => Http::response([
                'Id' => 'exec-id-789',
            ]),
            // exec start
            'portainer.test:8443/api/endpoints/1/docker/exec/exec-id-789/start' => Http::response('OK'),
            // exec inspect
            'portainer.test:8443/api/endpoints/1/docker/exec/exec-id-789/json' => Http::response([
                'ExitCode' => 0,
            ]),
        ]);

        $result = $this->service->execInContainer('frankenphp', ['caddy', 'reload']);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->exitCode);
    }

    public function test_exec_in_container_with_container_id_skips_lookup(): void
    {
        Http::fake([
            // exec create (using direct container ID)
            'portainer.test:8443/api/endpoints/1/docker/containers/abc123def456789012345678901234567890123456789012345678901234/exec' => Http::response([
                'Id' => 'exec-id-789',
            ]),
            // exec start
            'portainer.test:8443/api/endpoints/1/docker/exec/exec-id-789/start' => Http::response(''),
            // exec inspect
            'portainer.test:8443/api/endpoints/1/docker/exec/exec-id-789/json' => Http::response([
                'ExitCode' => 0,
            ]),
        ]);

        $result = $this->service->execInContainer(
            'abc123def456789012345678901234567890123456789012345678901234',
            ['ls'],
        );

        $this->assertTrue($result->isSuccessful());

        // Should NOT have called containers/json (no name lookup needed)
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/containers/json');
        });
    }

    public function test_exec_in_container_returns_nonzero_exit_code(): void
    {
        Http::fake([
            'portainer.test:8443/api/endpoints/1/docker/containers/json*' => Http::response([
                ['Id' => 'abc123', 'Names' => ['/frankenphp']],
            ]),
            'portainer.test:8443/api/endpoints/1/docker/containers/abc123/exec' => Http::response([
                'Id' => 'exec-fail',
            ]),
            'portainer.test:8443/api/endpoints/1/docker/exec/exec-fail/start' => Http::response('error output'),
            'portainer.test:8443/api/endpoints/1/docker/exec/exec-fail/json' => Http::response([
                'ExitCode' => 1,
            ]),
        ]);

        $result = $this->service->execInContainer('frankenphp', ['bad-command']);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(1, $result->exitCode);
    }

    public function test_create_and_run_container_full_flow(): void
    {
        Http::fake([
            // create
            'portainer.test:8443/api/endpoints/1/docker/containers/create' => Http::response([
                'Id' => 'new-container-123',
            ]),
            // start
            'portainer.test:8443/api/endpoints/1/docker/containers/new-container-123/start' => Http::response('', 204),
            // wait
            'portainer.test:8443/api/endpoints/1/docker/containers/new-container-123/wait' => Http::response([
                'StatusCode' => 0,
            ]),
            // logs
            'portainer.test:8443/api/endpoints/1/docker/containers/new-container-123/logs*' => Http::response('Certificate obtained successfully'),
            // delete (cleanup)
            'portainer.test:8443/api/endpoints/1/docker/containers/new-container-123*' => Http::response('', 204),
        ]);

        $result = $this->service->createAndRunContainer([
            'Image' => 'certbot/dns-cloudflare',
            'Cmd' => ['certonly', '--non-interactive'],
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->exitCode);
        $this->assertStringContainsString('Certificate obtained', $result->output);
    }

    public function test_create_and_run_container_cleans_up_on_failure(): void
    {
        Http::fake([
            'portainer.test:8443/api/endpoints/1/docker/containers/create' => Http::response([
                'Id' => 'fail-container-456',
            ]),
            'portainer.test:8443/api/endpoints/1/docker/containers/fail-container-456/start' => Http::response('', 204),
            'portainer.test:8443/api/endpoints/1/docker/containers/fail-container-456/wait' => Http::response([
                'StatusCode' => 1,
            ]),
            'portainer.test:8443/api/endpoints/1/docker/containers/fail-container-456/logs*' => Http::response('Error: invalid credentials'),
            'portainer.test:8443/api/endpoints/1/docker/containers/fail-container-456*' => Http::response('', 204),
        ]);

        $result = $this->service->createAndRunContainer([
            'Image' => 'certbot/dns-cloudflare',
            'Cmd' => ['certonly'],
        ]);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(1, $result->exitCode);

        // Verify cleanup DELETE was called
        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), 'fail-container-456');
        });
    }

    public function test_create_container_throws_on_api_error(): void
    {
        Http::fake([
            'portainer.test:8443/api/endpoints/1/docker/containers/create' => Http::response('Image not found', 404),
        ]);

        $this->expectException(PortainerException::class);
        $this->expectExceptionMessage('Failed to create container');

        $this->service->createAndRunContainer([
            'Image' => 'nonexistent-image',
        ]);
    }
}
