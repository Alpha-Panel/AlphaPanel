<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\PhpVersion;
use App\Models\User;
use App\Services\PhpFpmSupervisorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use Tests\TestCase;

class PhpVersionManagementTest extends TestCase
{
    use DatabaseTransactions;

    // ─── Permission Tests ───────────────────────────────────────

    public function test_non_admin_cannot_access_php_versions_index(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('php-versions.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_access_php_versions_index(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('php-versions.index'));

        $response->assertOk();
    }

    public function test_non_admin_cannot_toggle_php_version(): void
    {
        $user = User::factory()->create();
        $phpVersion = PhpVersion::factory()->create(['is_enabled' => false]);

        $response = $this->actingAs($user)->postJson(route('php-versions.toggle', $phpVersion));

        $response->assertForbidden();
    }

    // ─── Index Page Tests ───────────────────────────────────────

    public function test_index_returns_all_php_versions_with_domain_counts(): void
    {
        $admin = User::factory()->admin()->create();

        $phpA = PhpVersion::factory()->create(['is_enabled' => true, 'sort_order' => 10]);
        $phpB = PhpVersion::factory()->create(['is_enabled' => false, 'sort_order' => 20]);

        Domain::factory()->create([
            'owner_user_id' => $admin->id,
            'type' => \App\Enums\DomainType::ApacheReverseProxy,
            'php_version_id' => $phpA->id,
        ]);
        Domain::factory()->create([
            'owner_user_id' => $admin->id,
            'type' => \App\Enums\DomainType::ApacheReverseProxy,
            'php_version_id' => $phpA->id,
        ]);

        $response = $this->actingAs($admin)->get(route('php-versions.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('PhpVersions/Index')
            ->has('versions', fn ($versions) => $versions
                ->each(fn ($version) => $version
                    ->has('id')
                    ->has('slug')
                    ->has('is_enabled')
                    ->has('domains_count')
                    ->etc()
                )
            )
        );
    }

    public function test_index_renders_correct_inertia_component(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('php-versions.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('PhpVersions/Index'));
    }

    // ─── Toggle Tests ───────────────────────────────────────────

    public function test_admin_can_enable_disabled_php_version(): void
    {
        $admin = User::factory()->admin()->create();
        $phpVersion = PhpVersion::factory()->create(['is_enabled' => false]);

        $this->mock(PhpFpmSupervisorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enable')->once();
        });

        $response = $this->actingAs($admin)->postJson(route('php-versions.toggle', $phpVersion));

        $response->assertOk();
        $response->assertJson([
            'status' => 'success',
            'is_enabled' => true,
        ]);

        $this->assertDatabaseHas('php_versions', [
            'id' => $phpVersion->id,
            'is_enabled' => true,
        ]);
    }

    public function test_admin_can_disable_php_version_with_zero_domains(): void
    {
        $admin = User::factory()->admin()->create();
        $phpVersion = PhpVersion::factory()->create(['is_enabled' => true]);

        $this->mock(PhpFpmSupervisorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('disable')->once();
        });

        $response = $this->actingAs($admin)->postJson(route('php-versions.toggle', $phpVersion));

        $response->assertOk();
        $response->assertJson([
            'status' => 'success',
            'is_enabled' => false,
        ]);

        $this->assertDatabaseHas('php_versions', [
            'id' => $phpVersion->id,
            'is_enabled' => false,
        ]);
    }

    public function test_cannot_disable_php_version_with_assigned_domains(): void
    {
        $admin = User::factory()->admin()->create();
        $phpVersion = PhpVersion::factory()->create(['is_enabled' => true]);

        Domain::factory()->create([
            'owner_user_id' => $admin->id,
            'type' => \App\Enums\DomainType::ApacheReverseProxy,
            'php_version_id' => $phpVersion->id,
        ]);

        $this->mock(PhpFpmSupervisorService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('disable');
            $mock->shouldNotReceive('enable');
        });

        $response = $this->actingAs($admin)->postJson(route('php-versions.toggle', $phpVersion));

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'error',
        ]);

        $this->assertDatabaseHas('php_versions', [
            'id' => $phpVersion->id,
            'is_enabled' => true,
        ]);
    }

    public function test_toggle_handles_service_exception_gracefully(): void
    {
        $admin = User::factory()->admin()->create();
        $phpVersion = PhpVersion::factory()->create(['is_enabled' => false]);

        $this->mock(PhpFpmSupervisorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enable')->once()->andThrow(new \RuntimeException('Connection refused'));
        });

        $response = $this->actingAs($admin)->postJson(route('php-versions.toggle', $phpVersion));

        $response->assertStatus(500);
        $response->assertJson([
            'status' => 'error',
        ]);

        $this->assertDatabaseHas('php_versions', [
            'id' => $phpVersion->id,
            'is_enabled' => false,
        ]);
    }

    // ─── Audit Log Tests ────────────────────────────────────────

    public function test_toggle_creates_audit_log_entry(): void
    {
        $admin = User::factory()->admin()->create();
        $phpVersion = PhpVersion::factory()->create(['is_enabled' => false]);

        $this->mock(PhpFpmSupervisorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enable')->once();
        });

        $response = $this->actingAs($admin)->postJson(route('php-versions.toggle', $phpVersion));

        $response->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'php_version_enabled',
        ]);
    }

    public function test_failed_toggle_creates_failure_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $phpVersion = PhpVersion::factory()->create(['is_enabled' => false]);

        $this->mock(PhpFpmSupervisorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enable')->once()->andThrow(new \RuntimeException('Timeout'));
        });

        $response = $this->actingAs($admin)->postJson(route('php-versions.toggle', $phpVersion));

        $response->assertStatus(500);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'php_version_toggle_failed',
        ]);
    }
}
