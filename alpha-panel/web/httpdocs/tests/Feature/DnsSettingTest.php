<?php

namespace Tests\Feature;

use App\Models\DnsSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DnsSettingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dns_settings_page_loads_for_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->get(route('settings.dns.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/DnsSettings'));
    }

    public function test_dns_settings_update(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->put(route('settings.dns.update'), [
                'ns1' => 'ns1.test.com',
                'ns2' => 'ns2.test.com',
                'ns3' => null,
                'ns4' => null,
                'default_ip' => '1.2.3.4',
                'soa_admin_email' => 'admin.test.com',
                'soa_refresh' => 7200,
                'soa_retry' => 1800,
                'soa_expire' => 604800,
                'soa_minimum_ttl' => 3600,
                'default_ttl' => 3600,
                'default_template_id' => null,
            ]);

        $response->assertRedirect(route('settings.dns.index'));

        $settings = DnsSetting::instance();
        $this->assertEquals('ns1.test.com', $settings->ns1);
        $this->assertEquals('ns2.test.com', $settings->ns2);
        $this->assertEquals('1.2.3.4', $settings->default_ip);
        $this->assertEquals(7200, $settings->soa_refresh);
    }

    public function test_dns_settings_validation_rejects_invalid_data(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->put(route('settings.dns.update'), [
                'ns1' => '',
                'ns2' => '',
                'soa_admin_email' => '',
                'soa_refresh' => 10,
                'soa_retry' => 10,
                'soa_expire' => 100,
                'soa_minimum_ttl' => 10,
                'default_ttl' => 10,
            ]);

        $response->assertSessionHasErrors(['ns1', 'ns2', 'soa_admin_email', 'soa_refresh', 'soa_retry', 'soa_expire', 'soa_minimum_ttl', 'default_ttl']);
    }
}
