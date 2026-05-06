<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainMode;
use App\Enums\DomainType;
use App\Models\User;
use Database\Seeders\PhpVersionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WildcardCatchallAdminOnlyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PhpVersionSeeder::class);
        Queue::fake();
    }

    public function test_non_admin_user_cannot_create_wildcard_catchall(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('domains.store'), [
            'mode' => DomainMode::WildcardCatchall->value,
            'fqdn' => '*',
            'type' => DomainType::CaddyWebServer->value,
            'cloudflare_mode' => 'skip',
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('domains', [
            'fqdn' => '*',
            'mode' => DomainMode::WildcardCatchall->value,
        ]);
    }

    public function test_admin_user_can_create_wildcard_catchall(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('domains.store'), [
            'mode' => DomainMode::WildcardCatchall->value,
            'fqdn' => '*',
            'type' => DomainType::CaddyWebServer->value,
            'cloudflare_mode' => 'skip',
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('domains', [
            'fqdn' => '*',
            'mode' => DomainMode::WildcardCatchall->value,
        ]);
    }
}
