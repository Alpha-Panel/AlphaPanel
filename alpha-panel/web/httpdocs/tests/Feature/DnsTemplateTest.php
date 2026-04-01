<?php

namespace Tests\Feature;

use App\Models\DnsTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DnsTemplateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_template_list_page_loads(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->get(route('settings.dns-templates.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/DnsTemplates'));
    }

    public function test_template_creation_with_records(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->post(route('settings.dns-templates.store'), [
                'name' => 'Test Template',
                'records' => [
                    ['type' => 'A', 'name' => '{domain}', 'content' => '{ip}', 'ttl' => 3600, 'priority' => null],
                    ['type' => 'MX', 'name' => '{domain}', 'content' => 'mail.{domain}', 'ttl' => 3600, 'priority' => 10],
                ],
            ]);

        $response->assertRedirect(route('settings.dns-templates.index'));
        $this->assertDatabaseHas('dns_templates', ['name' => 'Test Template']);

        $template = DnsTemplate::where('name', 'Test Template')->first();
        $this->assertNotNull($template);
        $this->assertCount(2, $template->records);
    }

    public function test_template_update(): void
    {
        $admin = User::factory()->admin()->create();

        $template = DnsTemplate::create(['name' => 'Old Name']);
        $template->records()->create(['type' => 'A', 'name' => '{domain}', 'content' => '{ip}', 'ttl' => 3600]);

        $response = $this->actingAs($admin)
            ->put(route('settings.dns-templates.update', $template), [
                'name' => 'New Name',
                'records' => [
                    ['type' => 'AAAA', 'name' => '{domain}', 'content' => '::1', 'ttl' => 7200, 'priority' => null],
                ],
            ]);

        $response->assertRedirect(route('settings.dns-templates.index'));
        $template->refresh();
        $this->assertEquals('New Name', $template->name);
        $this->assertCount(1, $template->records);
        $this->assertEquals('AAAA', $template->records->first()->type);
    }

    public function test_template_deletion(): void
    {
        $admin = User::factory()->admin()->create();

        $template = DnsTemplate::create(['name' => 'To Delete']);

        $response = $this->actingAs($admin)
            ->delete(route('settings.dns-templates.destroy', $template));

        $response->assertRedirect(route('settings.dns-templates.index'));
        $this->assertDatabaseMissing('dns_templates', ['id' => $template->id]);
    }

    public function test_set_default_template(): void
    {
        $admin = User::factory()->admin()->create();

        $template1 = DnsTemplate::create(['name' => 'Template 1', 'is_default' => true]);
        $template2 = DnsTemplate::create(['name' => 'Template 2', 'is_default' => false]);

        $response = $this->actingAs($admin)
            ->post(route('settings.dns-templates.set-default', $template2));

        $response->assertRedirect(route('settings.dns-templates.index'));
        $template1->refresh();
        $template2->refresh();
        $this->assertFalse($template1->is_default);
        $this->assertTrue($template2->is_default);
    }

    public function test_template_creation_requires_name_and_records(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->post(route('settings.dns-templates.store'), [
                'name' => '',
                'records' => [],
            ]);

        $response->assertSessionHasErrors(['name', 'records']);
    }
}
