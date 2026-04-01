<?php

namespace Tests\Feature;

use App\Enums\DnsProvider;
use App\Models\DnsSetting;
use App\Models\Domain;
use App\Models\User;
use App\Services\CloudflareDnsService;
use Database\Seeders\PhpVersionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class DomainDnsProviderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhpVersionSeeder::class);
        Queue::fake();
    }

    public function test_domain_creation_with_local_dns_creates_zone(): void
    {
        $admin = User::factory()->admin()->create();

        $settings = DnsSetting::instance();
        $settings->update([
            'ns1' => 'ns1.test.com',
            'ns2' => 'ns2.test.com',
            'soa_admin_email' => 'admin.test.com',
        ]);

        $response = $this->actingAs($admin)
            ->post(route('domains.store'), [
                'fqdn' => 'local-dns-test.com',
                'type' => 'caddy_web_server',
                'dns_provider' => 'local',
                'cloudflare_mode' => 'skip',
                'enable_www_redirect' => true,
            ]);

        $response->assertRedirect();

        $domain = Domain::where('fqdn', 'local-dns-test.com')->first();
        $this->assertNotNull($domain);
        $this->assertEquals(DnsProvider::Local, $domain->dns_provider);
        $this->assertDatabaseHas('dns_zones', ['zone_name' => 'local-dns-test.com']);
    }

    public function test_domain_creation_with_cloudflare_provider(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(CloudflareDnsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ensureZoneExists')->once()->andReturn(true);
            $mock->shouldReceive('syncApexBootstrapRecords')->once()->andReturn(true);
        });

        $response = $this->actingAs($admin)
            ->post(route('domains.store'), [
                'fqdn' => 'cloudflare-test.com',
                'type' => 'caddy_web_server',
                'dns_provider' => 'cloudflare',
                'cloudflare_mode' => 'add',
                'dns_target_ip' => '1.2.3.4',
                'enable_www_redirect' => true,
            ]);

        $response->assertRedirect();

        $domain = Domain::where('fqdn', 'cloudflare-test.com')->first();
        $this->assertNotNull($domain);
        $this->assertEquals(DnsProvider::Cloudflare, $domain->dns_provider);
    }

    public function test_subdomain_inherits_parent_dns_provider(): void
    {
        $admin = User::factory()->admin()->create();

        $parent = Domain::factory()->create([
            'fqdn' => 'parent-inherit.com',
            'owner_user_id' => $admin->id,
            'dns_provider' => DnsProvider::Local,
        ]);

        $response = $this->actingAs($admin)
            ->post(route('domains.store'), [
                'fqdn' => 'sub.parent-inherit.com',
                'type' => 'caddy_web_server',
                'parent_domain_id' => $parent->id,
                'enable_www_redirect' => false,
            ]);

        $response->assertRedirect();

        $subdomain = Domain::where('fqdn', 'sub.parent-inherit.com')->first();
        $this->assertNotNull($subdomain);
        $this->assertEquals(DnsProvider::Local, $subdomain->dns_provider);
    }

    public function test_domain_model_helper_methods(): void
    {
        $localDomain = Domain::factory()->create(['dns_provider' => DnsProvider::Local]);
        $cfDomain = Domain::factory()->create(['dns_provider' => DnsProvider::Cloudflare]);

        $this->assertTrue($localDomain->usesLocalDns());
        $this->assertFalse($localDomain->usesCloudflare());

        $this->assertTrue($cfDomain->usesCloudflare());
        $this->assertFalse($cfDomain->usesLocalDns());
    }
}
