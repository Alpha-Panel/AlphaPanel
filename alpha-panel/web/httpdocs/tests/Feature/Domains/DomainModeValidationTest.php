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

class DomainModeValidationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PhpVersionSeeder::class);
        Queue::fake();
    }

    public function test_valid_main_domain_stores_successfully(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('domains.store'), [
            'mode' => DomainMode::Main->value,
            'fqdn' => 'validmain.com',
            'type' => DomainType::CaddyWebServer->value,
            'cloudflare_mode' => 'skip',
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('domains', [
            'fqdn' => 'validmain.com',
            'mode' => DomainMode::Main->value,
        ]);
    }

    public function test_valid_subdomain_with_parent_domain_id_stores_successfully(): void
    {
        $owner = User::factory()->create();
        $parent = Domain::factory()->create([
            'fqdn' => 'parent-sub-test.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $response = $this->actingAs($owner)->post(route('domains.store'), [
            'mode' => DomainMode::Subdomain->value,
            'fqdn' => 'api.parent-sub-test.com',
            'type' => DomainType::CaddyWebServer->value,
            'parent_domain_id' => $parent->id,
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
            'create_dns_record' => false,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('domains', [
            'fqdn' => 'api.parent-sub-test.com',
            'parent_domain_id' => $parent->id,
        ]);
    }

    public function test_valid_addon_with_linked_domain_id_stores_successfully(): void
    {
        $owner = User::factory()->create();
        $linked = Domain::factory()->create([
            'fqdn' => 'linked-domain.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $response = $this->actingAs($owner)->post(route('domains.store'), [
            'mode' => DomainMode::Addon->value,
            'fqdn' => 'addon-domain.com',
            'type' => DomainType::CaddyWebServer->value,
            'linked_domain_id' => $linked->id,
            'cloudflare_mode' => 'skip',
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('domains', [
            'fqdn' => 'addon-domain.com',
            'linked_domain_id' => $linked->id,
        ]);
    }

    public function test_valid_wildcard_subdomain_with_matching_parent_stores_successfully(): void
    {
        $owner = User::factory()->create();
        $parent = Domain::factory()->create([
            'fqdn' => 'wildcard-apex.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $response = $this->actingAs($owner)->post(route('domains.store'), [
            'mode' => DomainMode::WildcardSubdomain->value,
            'fqdn' => '*.wildcard-apex.com',
            'type' => DomainType::CaddyWebServer->value,
            'parent_domain_id' => $parent->id,
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
            'create_dns_record' => false,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('domains', ['fqdn' => '*.wildcard-apex.com']);
    }

    public function test_wildcard_subdomain_with_mismatched_parent_fails_validation(): void
    {
        $owner = User::factory()->create();
        $parent = Domain::factory()->create([
            'fqdn' => 'correct-apex.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $response = $this->actingAs($owner)->post(route('domains.store'), [
            'mode' => DomainMode::WildcardSubdomain->value,
            'fqdn' => '*.other-apex.com',
            'type' => DomainType::CaddyWebServer->value,
            'parent_domain_id' => $parent->id,
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
            'create_dns_record' => false,
        ]);

        $response->assertSessionHasErrors('fqdn');
        $errors = session('errors')->get('fqdn');
        $this->assertStringContainsString('Wildcard subdomain must match the apex', implode(' ', $errors));
        $this->assertDatabaseMissing('domains', ['fqdn' => '*.other-apex.com']);
    }

    public function test_wildcard_catchall_for_admin_user_stores_successfully(): void
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

    public function test_main_domain_with_invalid_fqdn_fails_validation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('domains.store'), [
            'mode' => DomainMode::Main->value,
            'fqdn' => 'invalid domain with spaces',
            'type' => DomainType::CaddyWebServer->value,
            'cloudflare_mode' => 'skip',
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
        ]);

        $response->assertSessionHasErrors('fqdn');
        $this->assertDatabaseMissing('domains', ['fqdn' => 'invalid domain with spaces']);
    }

    public function test_addon_without_linked_domain_id_fails_validation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('domains.store'), [
            'mode' => DomainMode::Addon->value,
            'fqdn' => 'addon-no-link.com',
            'type' => DomainType::CaddyWebServer->value,
            'cloudflare_mode' => 'skip',
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
        ]);

        $response->assertSessionHasErrors('linked_domain_id');
        $this->assertDatabaseMissing('domains', ['fqdn' => 'addon-no-link.com']);
    }

    public function test_invalid_mode_string_fails_validation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('domains.store'), [
            'mode' => 'not_a_real_mode',
            'fqdn' => 'some-domain.com',
            'type' => DomainType::CaddyWebServer->value,
            'cloudflare_mode' => 'skip',
            'enable_www_redirect' => false,
            'enable_worker' => false,
            'worker_watch' => false,
        ]);

        $response->assertSessionHasErrors('mode');
        $this->assertDatabaseMissing('domains', ['fqdn' => 'some-domain.com']);
    }
}
