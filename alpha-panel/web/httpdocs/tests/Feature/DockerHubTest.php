<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PortainerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class DockerHubTest extends TestCase
{
    use DatabaseTransactions;

    public function test_non_admin_cannot_search_docker_hub(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(route('docker-hub.search', ['query' => 'nginx']));

        $response->assertForbidden();
    }

    public function test_admin_can_search_docker_hub(): void
    {
        $admin = User::factory()->admin()->create();

        Http::fake([
            'hub.docker.com/v2/search/repositories*' => Http::response([
                'count' => 1,
                'results' => [[
                    'repo_name' => 'nginx',
                    'short_description' => 'Official NGINX image',
                    'star_count' => 18000,
                    'pull_count' => 1000000000,
                    'is_official' => true,
                ]],
            ]),
        ]);

        $response = $this->actingAs($admin)->getJson(route('docker-hub.search', ['query' => 'nginx']));

        $response->assertOk()->assertJsonStructure([
            'results' => [['name', 'description', 'star_count', 'pull_count', 'is_official']],
            'count',
        ]);
    }

    public function test_admin_can_get_popular_images(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->getJson(route('docker-hub.popular'));

        $response->assertOk()->assertJsonStructure([
            'images' => [['name', 'description', 'icon', 'category']],
        ]);
    }

    public function test_admin_can_get_image_tags(): void
    {
        $admin = User::factory()->admin()->create();

        Http::fake([
            'hub.docker.com/v2/repositories/library/nginx/tags*' => Http::response([
                'count' => 2,
                'results' => [
                    ['name' => 'latest', 'last_updated' => '2026-03-20T00:00:00Z', 'full_size' => 50000000, 'digest' => 'sha256:abc'],
                    ['name' => 'alpine', 'last_updated' => '2026-03-19T00:00:00Z', 'full_size' => 20000000, 'digest' => 'sha256:def'],
                ],
            ]),
        ]);

        $response = $this->actingAs($admin)->getJson(route('docker-hub.tags', ['image' => 'nginx']));

        $response->assertOk()->assertJsonStructure([
            'results' => [['name', 'last_updated', 'full_size']],
            'count',
        ]);
    }

    public function test_admin_can_get_image_config(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(PortainerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('inspectImage')->once()->with('nginx')->andReturn([
                'Config' => [
                    'Env' => ['PATH=/usr/local/sbin:/usr/local/bin', 'NGINX_VERSION=1.25.0'],
                    'ExposedPorts' => ['80/tcp' => new \stdClass],
                    'Volumes' => ['/etc/nginx/conf.d' => new \stdClass],
                ],
            ]);
        });

        $response = $this->actingAs($admin)->getJson(route('docker-hub.image-config', ['image' => 'nginx']));

        $response->assertOk()->assertJsonStructure([
            'env', 'exposed_ports', 'volumes',
        ]);
    }

    public function test_search_handles_docker_hub_failure_gracefully(): void
    {
        $admin = User::factory()->admin()->create();

        Http::fake([
            'hub.docker.com/v2/search/repositories*' => Http::response(null, 500),
        ]);

        $response = $this->actingAs($admin)->getJson(route('docker-hub.search', ['query' => 'nginx']));

        $response->assertOk()->assertJson(['results' => [], 'count' => 0]);
    }

    public function test_search_requires_query_parameter(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->getJson(route('docker-hub.search'));

        $response->assertUnprocessable();
    }
}
