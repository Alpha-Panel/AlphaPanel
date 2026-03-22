<?php

namespace Tests\Feature;

use App\Enums\IpAccessMode;
use App\Models\Domain;
use App\Models\DomainIpRule;
use App\Models\User;
use App\Services\DomainConfigService;
use App\Services\ReloadService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DomainIpAccessTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->domain = Domain::factory()->active()->create();

        $this->mock(DomainConfigService::class, function ($mock): void {
            $mock->shouldReceive('renderWithTls')->zeroOrMoreTimes();
        });

        $this->mock(ReloadService::class, function ($mock): void {
            $mock->shouldReceive('reloadCaddy')->zeroOrMoreTimes();
        });
    }

    // ─── Index ───────────────────────────────────────────────────

    public function test_admin_can_view_ip_access_page(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('domains.ip-access.index', $this->domain));

        $response->assertOk();
    }

    public function test_guest_cannot_access_ip_access_page(): void
    {
        $response = $this->get(route('domains.ip-access.index', $this->domain));

        $response->assertRedirect(route('login'));
    }

    // ─── Mode Update ─────────────────────────────────────────────

    public function test_can_update_mode_to_whitelist(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson(route('domains.ip-access.update-mode', $this->domain), [
                'ip_access_mode' => 'whitelist',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('domains', [
            'id' => $this->domain->id,
            'ip_access_mode' => 'whitelist',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'ip_access_mode_updated',
            'domain_id' => $this->domain->id,
        ]);
    }

    public function test_can_update_mode_to_blacklist(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson(route('domains.ip-access.update-mode', $this->domain), [
                'ip_access_mode' => 'blacklist',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('domains', [
            'id' => $this->domain->id,
            'ip_access_mode' => 'blacklist',
        ]);
    }

    public function test_can_update_mode_back_to_none(): void
    {
        $this->domain->update(['ip_access_mode' => IpAccessMode::Whitelist]);

        $response = $this->actingAs($this->admin)
            ->putJson(route('domains.ip-access.update-mode', $this->domain), [
                'ip_access_mode' => 'none',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('domains', [
            'id' => $this->domain->id,
            'ip_access_mode' => 'none',
        ]);
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson(route('domains.ip-access.update-mode', $this->domain), [
                'ip_access_mode' => 'invalid_mode',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('ip_access_mode');
    }

    // ─── IP Rule CRUD ────────────────────────────────────────────

    public function test_can_add_ip_rule_with_single_ip(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('domains.ip-access.store', $this->domain), [
                'ip_address' => '192.168.1.1',
                'note' => 'Office IP',
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['message', 'rule']);

        $this->assertDatabaseHas('domain_ip_rules', [
            'domain_id' => $this->domain->id,
            'ip_address' => '192.168.1.1',
            'note' => 'Office IP',
            'created_by' => $this->admin->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'ip_rule_added',
            'domain_id' => $this->domain->id,
        ]);
    }

    public function test_can_add_ip_rule_with_cidr(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('domains.ip-access.store', $this->domain), [
                'ip_address' => '10.0.0.0/8',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('domain_ip_rules', [
            'domain_id' => $this->domain->id,
            'ip_address' => '10.0.0.0/8',
        ]);
    }

    public function test_invalid_ip_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('domains.ip-access.store', $this->domain), [
                'ip_address' => 'not-an-ip',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('ip_address');
    }

    public function test_empty_ip_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('domains.ip-access.store', $this->domain), [
                'ip_address' => '',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('ip_address');
    }

    public function test_duplicate_ip_is_rejected(): void
    {
        DomainIpRule::create([
            'domain_id' => $this->domain->id,
            'ip_address' => '192.168.1.1',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('domains.ip-access.store', $this->domain), [
                'ip_address' => '192.168.1.1',
            ]);

        $response->assertUnprocessable();
    }

    public function test_can_add_rule_with_optional_note(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('domains.ip-access.store', $this->domain), [
                'ip_address' => '172.16.0.0/12',
                'note' => null,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('domain_ip_rules', [
            'domain_id' => $this->domain->id,
            'ip_address' => '172.16.0.0/12',
            'note' => null,
        ]);
    }

    public function test_can_delete_ip_rule(): void
    {
        $rule = DomainIpRule::create([
            'domain_id' => $this->domain->id,
            'ip_address' => '192.168.1.100',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson(route('domains.ip-access.destroy', [$this->domain, $rule]));

        $response->assertOk();

        $this->assertDatabaseMissing('domain_ip_rules', [
            'id' => $rule->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'ip_rule_removed',
            'domain_id' => $this->domain->id,
        ]);
    }

    // ─── Path-Based Rules ─────────────────────────────────────────

    public function test_can_add_ip_rule_with_path(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('domains.ip-access.store', $this->domain), [
                'ip_address' => '10.0.0.1',
                'path' => '/admin*',
                'note' => 'Admin only',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('domain_ip_rules', [
            'domain_id' => $this->domain->id,
            'ip_address' => '10.0.0.1',
            'path' => '/admin*',
            'note' => 'Admin only',
        ]);
    }

    public function test_can_add_same_ip_with_different_paths(): void
    {
        DomainIpRule::create([
            'domain_id' => $this->domain->id,
            'ip_address' => '10.0.0.1',
            'path' => '/admin*',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('domains.ip-access.store', $this->domain), [
                'ip_address' => '10.0.0.1',
                'path' => '/api/*',
            ]);

        $response->assertOk();

        $this->assertDatabaseCount('domain_ip_rules', 2);
    }

    public function test_duplicate_ip_with_same_path_is_rejected(): void
    {
        DomainIpRule::create([
            'domain_id' => $this->domain->id,
            'ip_address' => '10.0.0.1',
            'path' => '/admin*',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('domains.ip-access.store', $this->domain), [
                'ip_address' => '10.0.0.1',
                'path' => '/admin*',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('ip_address');
    }

    public function test_path_must_start_with_slash(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('domains.ip-access.store', $this->domain), [
                'ip_address' => '10.0.0.1',
                'path' => 'admin*',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('path');
    }

    public function test_empty_path_stores_as_domain_wide(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('domains.ip-access.store', $this->domain), [
                'ip_address' => '10.0.0.1',
                'path' => null,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('domain_ip_rules', [
            'domain_id' => $this->domain->id,
            'ip_address' => '10.0.0.1',
            'path' => '',
        ]);
    }

    public function test_path_with_invalid_characters_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('domains.ip-access.store', $this->domain), [
                'ip_address' => '10.0.0.1',
                'path' => '/admin;drop',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('path');
    }

    public function test_same_ip_allowed_as_global_and_path_scoped(): void
    {
        DomainIpRule::create([
            'domain_id' => $this->domain->id,
            'ip_address' => '10.0.0.1',
            'path' => '',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('domains.ip-access.store', $this->domain), [
                'ip_address' => '10.0.0.1',
                'path' => '/admin*',
            ]);

        $response->assertOk();

        $this->assertDatabaseCount('domain_ip_rules', 2);
    }

    // ─── Config Generation ───────────────────────────────────────

    public function test_mode_update_triggers_config_regeneration(): void
    {
        $this->mock(DomainConfigService::class, function ($mock): void {
            $mock->shouldReceive('renderWithTls')->once();
        });

        $this->mock(ReloadService::class, function ($mock): void {
            $mock->shouldReceive('reloadCaddy')->once();
        });

        $this->actingAs($this->admin)
            ->putJson(route('domains.ip-access.update-mode', $this->domain), [
                'ip_access_mode' => 'whitelist',
            ]);
    }

    public function test_rule_addition_triggers_config_regeneration(): void
    {
        $this->mock(DomainConfigService::class, function ($mock): void {
            $mock->shouldReceive('renderWithTls')->once();
        });

        $this->mock(ReloadService::class, function ($mock): void {
            $mock->shouldReceive('reloadCaddy')->once();
        });

        $this->actingAs($this->admin)
            ->postJson(route('domains.ip-access.store', $this->domain), [
                'ip_address' => '10.0.0.1',
            ]);
    }

    public function test_rule_deletion_triggers_config_regeneration(): void
    {
        $rule = DomainIpRule::create([
            'domain_id' => $this->domain->id,
            'ip_address' => '10.0.0.1',
            'created_by' => $this->admin->id,
        ]);

        $this->mock(DomainConfigService::class, function ($mock): void {
            $mock->shouldReceive('renderWithTls')->once();
        });

        $this->mock(ReloadService::class, function ($mock): void {
            $mock->shouldReceive('reloadCaddy')->once();
        });

        $this->actingAs($this->admin)
            ->deleteJson(route('domains.ip-access.destroy', [$this->domain, $rule]));
    }
}
