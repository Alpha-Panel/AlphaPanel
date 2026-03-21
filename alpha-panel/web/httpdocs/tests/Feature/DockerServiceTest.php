<?php

namespace Tests\Feature;

use App\Models\DockerService;
use App\Models\DockerServiceDomainBinding;
use App\Models\Domain;
use App\Models\User;
use App\Services\DockerServiceManager;
use App\Services\DomainConfigService;
use App\Services\ReloadService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use Tests\TestCase;

class DockerServiceTest extends TestCase
{
    use DatabaseTransactions;

    // ─── Permission Tests ───────────────────────────────────────

    public function test_non_admin_cannot_access_docker_services_index(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('docker-services.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_access_docker_services_index(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('docker-services.index'));

        $response->assertOk();
    }

    public function test_non_admin_cannot_store_docker_service(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('docker-services.store'), [
            'name' => 'ext-test',
            'image' => 'nginx',
        ]);

        $response->assertForbidden();
    }

    public function test_non_admin_cannot_delete_docker_service(): void
    {
        $user = User::factory()->create();
        $service = DockerService::factory()->create();

        $response = $this->actingAs($user)->delete(route('docker-services.destroy', $service));

        $response->assertForbidden();
    }

    public function test_non_admin_cannot_execute_docker_service_action(): void
    {
        $user = User::factory()->create();
        $service = DockerService::factory()->running()->create();

        $response = $this->actingAs($user)->postJson(route('docker-services.action', $service), [
            'action' => 'restart',
        ]);

        $response->assertForbidden();
    }

    // ─── CRUD Tests ─────────────────────────────────────────────

    public function test_admin_can_view_create_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('docker-services.create'));

        $response->assertOk();
    }

    public function test_admin_can_store_docker_service(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(DockerServiceManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('deploy')->once();
        });

        $response = $this->actingAs($admin)->post(route('docker-services.store'), [
            'name' => 'ext-test-nginx',
            'display_name' => 'Test Nginx',
            'image' => 'nginx',
            'tag' => 'latest',
            'restart_policy' => 'unless-stopped',
            'environment_variables' => [],
            'volumes' => [],
            'ports' => [],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('docker_services', [
            'name' => 'ext-test-nginx',
            'image' => 'nginx',
            'tag' => 'latest',
            'created_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'docker_service_deployed',
        ]);
    }

    public function test_store_logs_failure_audit_on_deploy_error(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(DockerServiceManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('deploy')->once()->andThrow(new \RuntimeException('Connection refused'));
        });

        $response = $this->actingAs($admin)->post(route('docker-services.store'), [
            'name' => 'ext-fail-svc',
            'display_name' => 'Failing Service',
            'image' => 'redis',
            'tag' => 'latest',
            'restart_policy' => 'always',
            'environment_variables' => [],
            'volumes' => [],
            'ports' => [],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'docker_service_deploy_failed',
        ]);
    }

    public function test_admin_can_view_docker_service_show(): void
    {
        $admin = User::factory()->admin()->create();
        $service = DockerService::factory()->running()->create();

        $response = $this->actingAs($admin)->get(route('docker-services.show', $service));

        $response->assertOk();
    }

    public function test_admin_can_view_docker_service_edit(): void
    {
        $admin = User::factory()->admin()->create();
        $service = DockerService::factory()->create();

        $response = $this->actingAs($admin)->get(route('docker-services.edit', $service));

        $response->assertOk();
    }

    public function test_admin_can_update_docker_service(): void
    {
        $admin = User::factory()->admin()->create();
        $service = DockerService::factory()->create([
            'display_name' => 'Old Name',
            'restart_policy' => 'always',
        ]);

        $response = $this->actingAs($admin)->put(route('docker-services.update', $service), [
            'name' => $service->name,
            'display_name' => 'New Name',
            'restart_policy' => 'unless-stopped',
            'environment_variables' => [],
            'volumes' => [],
            'ports' => [],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('docker_services', [
            'id' => $service->id,
            'display_name' => 'New Name',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'docker_service_updated',
        ]);
    }

    public function test_admin_can_destroy_docker_service(): void
    {
        $admin = User::factory()->admin()->create();
        $service = DockerService::factory()->running()->create();

        $this->mock(DockerServiceManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('remove')->once();
        });

        $response = $this->actingAs($admin)->delete(route('docker-services.destroy', $service));

        $response->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'docker_service_removed',
        ]);
    }

    public function test_destroy_logs_failure_audit_on_remove_error(): void
    {
        $admin = User::factory()->admin()->create();
        $service = DockerService::factory()->running()->create();

        $this->mock(DockerServiceManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('remove')->once()->andThrow(new \RuntimeException('Container busy'));
        });

        $response = $this->actingAs($admin)->delete(route('docker-services.destroy', $service));

        $response->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'docker_service_remove_failed',
        ]);
    }

    // ─── Validation Tests ───────────────────────────────────────

    public function test_store_requires_name_and_image(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('docker-services.store'), []);

        $response->assertSessionHasErrors(['name', 'image']);
    }

    public function test_store_rejects_duplicate_name(): void
    {
        $admin = User::factory()->admin()->create();
        $existing = DockerService::factory()->create(['name' => 'ext-taken']);

        $this->mock(DockerServiceManager::class);

        $response = $this->actingAs($admin)->post(route('docker-services.store'), [
            'name' => 'ext-taken',
            'display_name' => 'Duplicate',
            'image' => 'nginx',
            'tag' => 'latest',
            'restart_policy' => 'always',
            'environment_variables' => [],
            'volumes' => [],
            'ports' => [],
        ]);

        $response->assertSessionHasErrors(['name']);
    }

    // ─── Action Tests ───────────────────────────────────────────

    public function test_admin_can_start_docker_service(): void
    {
        $admin = User::factory()->admin()->create();
        $service = DockerService::factory()->stopped()->create();

        $this->mock(DockerServiceManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('start')->once();
        });

        $response = $this->actingAs($admin)->postJson(route('docker-services.action', $service), [
            'action' => 'start',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'docker_service_start',
        ]);
    }

    public function test_admin_can_stop_docker_service(): void
    {
        $admin = User::factory()->admin()->create();
        $service = DockerService::factory()->running()->create();

        $this->mock(DockerServiceManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('stop')->once();
        });

        $response = $this->actingAs($admin)->postJson(route('docker-services.action', $service), [
            'action' => 'stop',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'docker_service_stop',
        ]);
    }

    public function test_admin_can_restart_docker_service(): void
    {
        $admin = User::factory()->admin()->create();
        $service = DockerService::factory()->running()->create();

        $this->mock(DockerServiceManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('restart')->once();
        });

        $response = $this->actingAs($admin)->postJson(route('docker-services.action', $service), [
            'action' => 'restart',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'docker_service_restart',
        ]);
    }

    public function test_action_rejects_invalid_action(): void
    {
        $admin = User::factory()->admin()->create();
        $service = DockerService::factory()->running()->create();

        $response = $this->actingAs($admin)->postJson(route('docker-services.action', $service), [
            'action' => 'explode',
        ]);

        $response->assertUnprocessable();
    }

    public function test_action_logs_failure_audit_on_error(): void
    {
        $admin = User::factory()->admin()->create();
        $service = DockerService::factory()->running()->create();

        $this->mock(DockerServiceManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('restart')->once()->andThrow(new \RuntimeException('Timeout'));
        });

        $response = $this->actingAs($admin)->postJson(route('docker-services.action', $service), [
            'action' => 'restart',
        ]);

        $response->assertStatus(500);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'docker_service_restart_failed',
        ]);
    }

    // ─── Logs & Stats Tests ─────────────────────────────────────

    public function test_admin_can_get_docker_service_logs(): void
    {
        $admin = User::factory()->admin()->create();
        $service = DockerService::factory()->running()->create();

        $this->mock(DockerServiceManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getLogs')->once()->with(\Mockery::type(DockerService::class), 200)->andReturn('log output here');
        });

        $response = $this->actingAs($admin)->getJson(route('docker-services.logs', $service));

        $response->assertOk()->assertJson(['logs' => 'log output here']);
    }

    public function test_admin_can_get_docker_service_stats(): void
    {
        $admin = User::factory()->admin()->create();
        $service = DockerService::factory()->running()->create();

        $this->mock(DockerServiceManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getStats')->once()->andReturn([
                'cpu_percent' => 1.5,
                'memory_usage' => 52428800,
                'memory_limit' => 536870912,
                'memory_percent' => 9.77,
                'network_rx' => 1024,
                'network_tx' => 2048,
            ]);
        });

        $response = $this->actingAs($admin)->getJson(route('docker-services.stats', $service));

        $response->assertOk()->assertJsonStructure([
            'stats' => ['cpu_percent', 'memory_usage', 'memory_limit', 'memory_percent'],
        ]);
    }

    // ─── Domain Binding Tests ───────────────────────────────────

    public function test_admin_can_view_domain_docker_bindings(): void
    {
        $admin = User::factory()->admin()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $admin->id]);

        $response = $this->actingAs($admin)->get(route('domains.docker-services.index', $domain));

        $response->assertOk();
    }

    public function test_admin_can_bind_docker_service_to_domain(): void
    {
        $admin = User::factory()->admin()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $admin->id]);
        $service = DockerService::factory()->running()->create();

        $this->mock(DomainConfigService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('renderWithTls')->once();
        });
        $this->mock(ReloadService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reloadCaddy')->once();
        });

        $response = $this->actingAs($admin)->post(route('domains.docker-services.store', $domain), [
            'docker_service_id' => $service->id,
            'container_port' => 8080,
            'path_prefix' => null,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('docker_service_domain_bindings', [
            'domain_id' => $domain->id,
            'docker_service_id' => $service->id,
            'container_port' => 8080,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'docker_service_bound',
            'domain_id' => $domain->id,
        ]);
    }

    public function test_admin_can_unbind_docker_service_from_domain(): void
    {
        $admin = User::factory()->admin()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $admin->id]);
        $service = DockerService::factory()->running()->create();
        $binding = DockerServiceDomainBinding::create([
            'domain_id' => $domain->id,
            'docker_service_id' => $service->id,
            'container_port' => 3000,
        ]);

        $this->mock(DomainConfigService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('renderWithTls')->once();
        });
        $this->mock(ReloadService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reloadCaddy')->once();
        });

        $response = $this->actingAs($admin)->delete(route('domains.docker-services.destroy', [$domain, $binding]));

        $response->assertRedirect();

        $this->assertDatabaseMissing('docker_service_domain_bindings', [
            'id' => $binding->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'docker_service_unbound',
            'domain_id' => $domain->id,
        ]);
    }

    public function test_non_admin_cannot_bind_docker_service_to_domain(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $user->id]);
        $service = DockerService::factory()->running()->create();

        $response = $this->actingAs($user)->post(route('domains.docker-services.store', $domain), [
            'docker_service_id' => $service->id,
            'container_port' => 80,
        ]);

        $response->assertForbidden();
    }
}
