<?php

namespace Tests\Feature;

use App\Enums\DomainType;
use App\Jobs\DeleteDomainJob;
use App\Jobs\ProvisionDomainJob;
use App\Models\Domain;
use App\Models\PhpVersion;
use App\Models\User;
use App\Services\CloudflareDnsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class DomainTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PhpVersionSeeder::class);
    }

    public function test_subdomain_root_path_derivation(): void
    {
        $owner = User::factory()->create();

        $parent = Domain::factory()->create([
            'fqdn' => 'example.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $subdomain = Domain::factory()->create([
            'fqdn' => 'api.example.com',
            'parent_domain_id' => $parent->id,
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $this->assertEquals('example.com', $subdomain->getApexDomain());
        $this->assertEquals('api', $subdomain->getSubdomainSlug());
        $this->assertEquals('/var/www/vhosts/example.com/subdomains/api', $subdomain->getBasePath());
        $this->assertEquals('/var/www/vhosts/example.com/subdomains/api/httpdocs/public', $subdomain->getWebRootPath());
    }

    public function test_parent_domain_root_path(): void
    {
        $owner = User::factory()->create();

        $domain = Domain::factory()->create([
            'fqdn' => 'example.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $this->assertNull($domain->getSubdomainSlug());
        $this->assertEquals('/var/www/vhosts/example.com', $domain->getBasePath());
        $this->assertEquals('/var/www/vhosts/example.com/httpdocs/public', $domain->getWebRootPath());
    }

    public function test_legacy_domain_root_path(): void
    {
        $owner = User::factory()->create();

        $phpVersion = PhpVersion::where('slug', '8.2')->first();

        $domain = Domain::factory()->create([
            'fqdn' => 'legacy.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::ApacheReverseProxy,
            'php_version_id' => $phpVersion->id,
        ]);

        $this->assertEquals('/var/www/vhosts/legacy.com/httpdocs', $domain->getWebRootPath());
    }

    public function test_legacy_subdomain_root_path(): void
    {
        $owner = User::factory()->create();

        $phpVersion = PhpVersion::where('slug', '8.3')->first();

        $parent = Domain::factory()->create([
            'fqdn' => 'example.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $subdomain = Domain::factory()->create([
            'fqdn' => 'blog.example.com',
            'parent_domain_id' => $parent->id,
            'owner_user_id' => $owner->id,
            'type' => DomainType::ApacheReverseProxy,
            'php_version_id' => $phpVersion->id,
        ]);

        $this->assertEquals('/var/www/vhosts/example.com/subdomains/blog/httpdocs', $subdomain->getWebRootPath());
    }

    public function test_custom_root_path_override(): void
    {
        $owner = User::factory()->create();

        $domain = Domain::factory()->create([
            'fqdn' => 'custom.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'root_path' => '/custom/path/public',
        ]);

        $this->assertEquals('/custom/path/public', $domain->getWebRootPath());
    }

    public function test_owner_can_update_domain_root_path(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $response = $this->actingAs($owner)->put(route('domains.update', $domain), [
            'fqdn' => $domain->fqdn,
            'type' => $domain->type->value,
            'root_path' => '/var/www/vhosts/custom-root/httpdocs/public',
            'enable_www_redirect' => (bool) $domain->enable_www_redirect,
            'enable_worker' => (bool) $domain->enable_worker,
            'worker_watch' => (bool) $domain->worker_watch,
        ]);

        $response->assertRedirect(route('domains.show', $domain));
        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'root_path' => '/var/www/vhosts/custom-root/httpdocs/public',
        ]);
        Queue::assertPushed(ProvisionDomainJob::class);
    }

    public function test_update_does_not_allow_ftp_password_change(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);
        $domain->ftpUser()->create([
            'username' => 'ftpuser'.$domain->id,
            'home_path' => "/var/www/vhosts/{$domain->fqdn}",
            'uid' => 20000 + $domain->id,
            'encrypted_password' => 'OldPassword123!',
        ]);

        $response = $this->actingAs($owner)->put(route('domains.update', $domain), [
            'fqdn' => $domain->fqdn,
            'type' => $domain->type->value,
            'ftp_password' => 'NewPassword123!',
        ]);

        $response->assertRedirect(route('domains.show', $domain));
        $domain->refresh()->load('ftpUser');

        $this->assertSame('OldPassword123!', $domain->ftpUser?->encrypted_password);
    }

    public function test_worker_num_is_ignored_when_worker_is_disabled_on_store(): void
    {
        Queue::fake();
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post(route('domains.store'), [
            'fqdn' => 'disabled-worker-create.com',
            'type' => 'caddy_web_server',
            'cloudflare_mode' => 'skip',
            'enable_www_redirect' => true,
            'enable_worker' => false,
            'worker_num' => 0,
            'worker_watch' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('domains', [
            'fqdn' => 'disabled-worker-create.com',
            'enable_worker' => false,
        ]);
    }

    public function test_worker_num_is_ignored_when_worker_is_disabled_on_update(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'enable_worker' => false,
            'worker_num' => null,
            'worker_watch' => false,
        ]);

        $response = $this->actingAs($owner)->put(route('domains.update', $domain), [
            'fqdn' => $domain->fqdn,
            'type' => $domain->type->value,
            'enable_www_redirect' => (bool) $domain->enable_www_redirect,
            'enable_worker' => false,
            'worker_num' => 0,
            'worker_watch' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('domains.show', $domain));
    }

    public function test_legacy_domain_requires_php_version_id(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post(route('domains.store'), [
            'fqdn' => 'needs-php.com',
            'type' => 'apache_reverse_proxy',
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
        ]);

        $response->assertSessionHasErrors('php_version_id');
        $this->assertDatabaseMissing('domains', ['fqdn' => 'needs-php.com']);
    }

    public function test_modern_domain_does_not_require_php_version_id(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post(route('domains.store'), [
            'fqdn' => 'modern-test.com',
            'type' => 'caddy_web_server',
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('domains', ['fqdn' => 'modern-test.com']);
    }

    public function test_modsecurity_settings_are_persisted_on_store(): void
    {
        Queue::fake();
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post(route('domains.store'), [
            'fqdn' => 'waf-store-test.com',
            'type' => 'caddy_web_server',
            'cloudflare_mode' => 'skip',
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
            'modsecurity_enabled' => true,
            'modsecurity_mode' => 'detection_only',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('domains', [
            'fqdn' => 'waf-store-test.com',
            'modsecurity_enabled' => true,
            'modsecurity_mode' => 'detection_only',
        ]);
    }

    public function test_disabling_modsecurity_clears_mode_on_update(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'modsecurity_enabled' => true,
            'modsecurity_mode' => 'active',
        ]);

        $response = $this->actingAs($owner)->put(route('domains.update', $domain), [
            'fqdn' => $domain->fqdn,
            'type' => $domain->type->value,
            'root_path' => $domain->root_path,
            'enable_www_redirect' => (bool) $domain->enable_www_redirect,
            'enable_worker' => (bool) $domain->enable_worker,
            'worker_watch' => (bool) $domain->worker_watch,
            'modsecurity_enabled' => false,
            'modsecurity_mode' => 'detection_only',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('domains.show', $domain));
        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'modsecurity_enabled' => false,
            'modsecurity_mode' => null,
        ]);
    }

    public function test_subdomain_creation_auto_enables_dns_sync_when_cloudflare_zone_exists(): void
    {
        Queue::fake();
        $owner = User::factory()->create();

        $parent = Domain::factory()->create([
            'fqdn' => 'example.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $this->mock(CloudflareDnsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getZoneSummary')
                ->once()
                ->andReturn([
                    'exists' => true,
                    'zone_id' => 'zone-1',
                    'zone_name' => 'example.com',
                    'status' => 'active',
                    'name_servers' => [],
                    'original_name_servers' => [],
                ]);
        });

        $response = $this->actingAs($owner)->post(route('domains.store'), [
            'fqdn' => 'api.example.com',
            'type' => 'caddy_web_server',
            'parent_domain_id' => $parent->id,
            'enable_www_redirect' => true,
            'enable_worker' => false,
            'worker_watch' => false,
            'create_dns_record' => false,
        ]);

        $response->assertSessionHasNoErrors();
        Queue::assertPushed(ProvisionDomainJob::class, fn (ProvisionDomainJob $job): bool => $job->createDnsRecord === true);
    }

    public function test_subdomain_creation_without_inherit_parent_root_path_keeps_default_subdomain_path(): void
    {
        Queue::fake();
        $owner = User::factory()->create();

        $parent = Domain::factory()->create([
            'fqdn' => 'example.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'root_path' => '/var/www/vhosts/example.com/httpdocs/custom',
        ]);

        $response = $this->actingAs($owner)->post(route('domains.store'), [
            'fqdn' => 'panel.example.com',
            'type' => 'caddy_web_server',
            'parent_domain_id' => $parent->id,
            'enable_www_redirect' => true,
            'enable_worker' => false,
            'worker_watch' => false,
            'create_dns_record' => false,
        ]);

        $response->assertSessionHasNoErrors();

        $subdomain = Domain::query()->where('fqdn', 'panel.example.com')->firstOrFail();
        $this->assertNull($subdomain->root_path);
        $this->assertSame('/var/www/vhosts/example.com/subdomains/panel/httpdocs/public', $subdomain->getWebRootPath());
    }

    public function test_subdomain_creation_with_inherit_parent_root_path_uses_parent_path(): void
    {
        Queue::fake();
        $owner = User::factory()->create();

        $parent = Domain::factory()->create([
            'fqdn' => 'example.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'root_path' => '/var/www/vhosts/example.com/httpdocs/shared',
        ]);

        $response = $this->actingAs($owner)->post(route('domains.store'), [
            'fqdn' => 'api.example.com',
            'type' => 'caddy_web_server',
            'parent_domain_id' => $parent->id,
            'inherit_parent_root_path' => true,
            'enable_www_redirect' => true,
            'enable_worker' => false,
            'worker_watch' => false,
            'create_dns_record' => false,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('domains', [
            'fqdn' => 'api.example.com',
            'parent_domain_id' => $parent->id,
            'root_path' => '/var/www/vhosts/example.com/httpdocs/shared',
        ]);
    }

    public function test_subdomain_creation_returns_json_success_without_redirect_when_json_is_expected(): void
    {
        Queue::fake();
        $owner = User::factory()->create();

        $parent = Domain::factory()->create([
            'fqdn' => 'example.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'cloudflare_enabled' => false,
        ]);

        $response = $this->actingAs($owner)->postJson(route('domains.store'), [
            'fqdn' => 'panel.example.com',
            'type' => 'caddy_web_server',
            'parent_domain_id' => $parent->id,
            'enable_www_redirect' => true,
            'enable_worker' => false,
            'worker_watch' => false,
            'create_dns_record' => false,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'queued' => true,
            'domain_fqdn' => 'panel.example.com',
        ]);

        $this->assertDatabaseHas('domains', [
            'fqdn' => 'panel.example.com',
            'parent_domain_id' => $parent->id,
            'owner_user_id' => $owner->id,
        ]);
        Queue::assertPushed(ProvisionDomainJob::class);
    }

    public function test_delete_request_passes_delete_dns_record_flag_to_job(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($owner)->delete(route('domains.destroy', $domain), [
            'delete_dns_record' => true,
        ]);

        $response->assertRedirect(route('domains.index'));
        Queue::assertPushed(DeleteDomainJob::class, fn (DeleteDomainJob $job): bool => $job->deleteDnsRecords === true);
    }

    public function test_owner_can_view_own_domain(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($owner)->get(route('domains.show', $domain));

        $response->assertOk();
    }

    public function test_non_owner_cannot_view_domain(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($other)->get(route('domains.show', $domain));

        $response->assertForbidden();
    }

    public function test_admin_can_view_any_domain(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($admin)->get(route('domains.show', $domain));

        $response->assertOk();
    }

    public function test_non_owner_cannot_delete_domain(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($other)->delete(route('domains.destroy', $domain));

        $response->assertForbidden();
        $this->assertDatabaseHas('domains', ['id' => $domain->id]);
    }

    public function test_admin_can_delete_any_domain(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($admin)->delete(route('domains.destroy', $domain));

        $response->assertRedirect(route('domains.index'));
        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
    }

    public function test_deleting_parent_domain_also_deletes_subdomains(): void
    {
        $owner = User::factory()->create();
        $parent = Domain::factory()->create([
            'fqdn' => 'example.com',
            'owner_user_id' => $owner->id,
        ]);
        $subdomain = Domain::factory()->subdomain($parent)->create();

        $response = $this->actingAs($owner)->delete(route('domains.destroy', $parent));

        $response->assertRedirect(route('domains.index'));
        $this->assertDatabaseMissing('domains', ['id' => $parent->id]);
        $this->assertDatabaseMissing('domains', ['id' => $subdomain->id]);
    }

    public function test_domain_index_only_shows_owned_domains(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        Domain::factory()->count(3)->create(['owner_user_id' => $owner->id]);
        Domain::factory()->count(2)->create(['owner_user_id' => $other->id]);

        $response = $this->actingAs($owner)->get(route('domains.index'));
        $response->assertOk();

        $jsonResponse = $this->actingAs($owner)->getJson(route('domains.json', ['draw' => 1, 'start' => 0, 'length' => 25]));
        $jsonResponse->assertOk();
        $jsonResponse->assertJsonCount(3, 'data');
    }

    public function test_admin_sees_all_domains(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();

        $existingCount = Domain::whereNull('parent_domain_id')->count();

        Domain::factory()->count(3)->create(['owner_user_id' => $owner->id]);
        Domain::factory()->count(2)->create(['owner_user_id' => $admin->id]);

        $response = $this->actingAs($admin)->get(route('domains.index'));
        $response->assertOk();

        $jsonResponse = $this->actingAs($admin)->getJson(route('domains.json', ['draw' => 1, 'start' => 0, 'length' => 100]));
        $jsonResponse->assertOk();
        $jsonResponse->assertJsonCount($existingCount + 5, 'data');
    }

    public function test_under_attack_statuses_endpoint_returns_status_for_owned_domain(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'fqdn' => 'owned-status-check.com',
            'owner_user_id' => $owner->id,
            'cloudflare_enabled' => true,
        ]);

        $this->mock(CloudflareDnsService::class, function (MockInterface $mock) use ($domain): void {
            $mock->shouldReceive('getZoneSummary')
                ->once()
                ->with($domain->fqdn)
                ->andReturn([
                    'exists' => true,
                    'zone_id' => 'zone-owned',
                    'zone_name' => $domain->fqdn,
                    'status' => 'active',
                    'name_servers' => [],
                    'original_name_servers' => [],
                ]);

            $mock->shouldReceive('getZoneSetting')
                ->once()
                ->with('zone-owned', 'security_level')
                ->andReturn(['value' => 'under_attack']);
        });

        $response = $this->actingAs($owner)
            ->getJson(route('domains.under-attack-statuses', ['domain_ids' => [$domain->id]]));

        $response->assertOk();
        $response->assertJsonPath("data.{$domain->id}", true);
    }

    public function test_under_attack_statuses_endpoint_does_not_expose_other_users_domains(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $ownedDomain = Domain::factory()->create([
            'fqdn' => 'owner-visible-status.com',
            'owner_user_id' => $owner->id,
            'cloudflare_enabled' => true,
        ]);

        $otherDomain = Domain::factory()->create([
            'fqdn' => 'other-hidden-status.com',
            'owner_user_id' => $other->id,
            'cloudflare_enabled' => true,
        ]);

        $this->mock(CloudflareDnsService::class, function (MockInterface $mock) use ($ownedDomain): void {
            $mock->shouldReceive('getZoneSummary')
                ->once()
                ->with($ownedDomain->fqdn)
                ->andReturn([
                    'exists' => true,
                    'zone_id' => 'zone-owner-only',
                    'zone_name' => $ownedDomain->fqdn,
                    'status' => 'active',
                    'name_servers' => [],
                    'original_name_servers' => [],
                ]);

            $mock->shouldReceive('getZoneSetting')
                ->once()
                ->with('zone-owner-only', 'security_level')
                ->andReturn(['value' => 'high']);
        });

        $response = $this->actingAs($owner)->getJson(route('domains.under-attack-statuses', [
            'domain_ids' => [$ownedDomain->id, $otherDomain->id],
        ]));

        $response->assertOk();
        $response->assertJsonPath("data.{$ownedDomain->id}", false);
        $response->assertJsonPath("data.{$otherDomain->id}", null);
    }

    public function test_unauthenticated_cannot_access_domains(): void
    {
        $response = $this->get(route('domains.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('admin.users.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_access_admin_routes(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
    }
}
