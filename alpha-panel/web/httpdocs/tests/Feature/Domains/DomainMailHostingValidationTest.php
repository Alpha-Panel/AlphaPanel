<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainMode;
use App\Enums\DomainType;
use App\Enums\MailHosting;
use App\Models\Domain;
use App\Models\User;
use Database\Seeders\PhpVersionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression: setting mail_hosting=local while MAIL_ENABLED=false used to
 * pass validation and stick a dead provider on the domain, producing a 500
 * the next time the user opened the Mail screen. The request layer now
 * rejects disabled providers up front.
 */
class DomainMailHostingValidationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PhpVersionSeeder::class);
        Queue::fake();
    }

    public function test_store_rejects_local_when_mailu_feature_off(): void
    {
        config()->set('panel.features.mailu', false);
        config()->set('panel.features.zimbra', false);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->post(route('domains.store'), [
            'mode' => DomainMode::Main->value,
            'fqdn' => 'mailoff-store.test',
            'type' => DomainType::CaddyWebServer->value,
            'cloudflare_mode' => 'skip',
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
            'mail_hosting' => MailHosting::Local->value,
        ]);

        $response->assertSessionHasErrors('mail_hosting');
        $this->assertDatabaseMissing('domains', ['fqdn' => 'mailoff-store.test']);
    }

    public function test_update_rejects_local_when_mailu_feature_off(): void
    {
        config()->set('panel.features.mailu', false);
        config()->set('panel.features.zimbra', false);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $domain = Domain::factory()->create([
            'fqdn' => 'mailoff-update.test',
            'mail_hosting' => MailHosting::Disabled->value,
            'owner_user_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->put(route('domains.update', $domain), [
            'fqdn' => $domain->fqdn,
            'type' => $domain->type->value,
            'mail_hosting' => MailHosting::Local->value,
        ]);

        $response->assertSessionHasErrors('mail_hosting');
        $domain->refresh();
        $this->assertSame(MailHosting::Disabled, $domain->mail_hosting);
    }

    public function test_store_accepts_remote_even_when_features_off(): void
    {
        // Remote needs no provider — it's just an MX hint stored on the row.
        config()->set('panel.features.mailu', false);
        config()->set('panel.features.zimbra', false);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->post(route('domains.store'), [
            'mode' => DomainMode::Main->value,
            'fqdn' => 'remote-ok.test',
            'type' => DomainType::CaddyWebServer->value,
            'cloudflare_mode' => 'skip',
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
            'mail_hosting' => MailHosting::Remote->value,
            'mail_remote_mx_host' => 'mx.example.com',
            'mail_remote_mx_priority' => 10,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('domains', ['fqdn' => 'remote-ok.test']);
    }
}
