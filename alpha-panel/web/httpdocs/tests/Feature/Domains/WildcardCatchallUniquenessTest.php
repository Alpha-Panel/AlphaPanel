<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainMode;
use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\User;
use Database\Seeders\PhpVersionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WildcardCatchallUniquenessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PhpVersionSeeder::class);
        Queue::fake();
    }

    public function test_first_wildcard_catchall_domain_can_be_created_by_admin(): void
    {
        $admin = User::factory()->admin()->create();

        // Ensure no existing catch-all is present
        Domain::where('fqdn', '*')->delete();

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

    public function test_second_wildcard_catchall_is_rejected_with_uniqueness_error(): void
    {
        $admin = User::factory()->admin()->create();

        // Ensure no existing catch-all, then create the first one
        Domain::where('fqdn', '*')->delete();

        Domain::factory()->create([
            'fqdn' => '*',
            'mode' => DomainMode::WildcardCatchall,
            'owner_user_id' => $admin->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $response = $this->actingAs($admin)->post(route('domains.store'), [
            'mode' => DomainMode::WildcardCatchall->value,
            'fqdn' => '*',
            'type' => DomainType::CaddyWebServer->value,
            'cloudflare_mode' => 'skip',
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
        ]);

        $response->assertStatus(422);
        $errors = session('errors')->getBag('default')->all();
        $allErrors = implode(' ', $errors);
        $this->assertStringContainsString('Wildcard catch-all already defined on this server', $allErrors);
    }
}
