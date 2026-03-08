<?php

namespace Tests\Feature;

use App\Enums\DomainType;
use App\Jobs\ProvisionDomainJob;
use App\Models\Domain;
use App\Models\User;
use App\Services\WafLogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class DomainModSecurityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_open_modsecurity_page(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $response = $this->actingAs($owner)->get(route('domains.modsecurity.index', $domain));

        $response->assertOk();
    }

    public function test_owner_can_switch_modsecurity_mode_to_active(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'modsecurity_enabled' => true,
            'modsecurity_mode' => 'detection_only',
        ]);

        $response = $this->actingAs($owner)->put(route('domains.modsecurity.update', $domain), [
            'modsecurity_enabled' => true,
            'modsecurity_mode' => 'active',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('domains.modsecurity.index', $domain));
        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'modsecurity_enabled' => true,
            'modsecurity_mode' => 'active',
        ]);
        Queue::assertPushed(ProvisionDomainJob::class);
    }

    public function test_modsecurity_mode_is_required_when_enabled(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $response = $this->actingAs($owner)->put(route('domains.modsecurity.update', $domain), [
            'modsecurity_enabled' => true,
        ]);

        $response->assertSessionHasErrors('modsecurity_mode');
    }

    public function test_owner_can_fetch_modsecurity_logs_json(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $this->mock(WafLogService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getDomainEntries')
                ->once()
                ->andReturn([
                    [
                        'ts' => now()->toIso8601String(),
                        'host' => 'example.com',
                        'ip' => '127.0.0.1',
                        'method' => 'GET',
                        'uri' => '/test',
                        'rule_id' => '949110',
                        'message' => 'test',
                        'action' => 'deny',
                    ],
                ]);
        });

        $response = $this->actingAs($owner)->get(route('domains.modsecurity.logs', $domain));

        $response->assertOk();
        $response->assertJsonStructure([
            'entries' => [
                ['ts', 'host', 'ip', 'method', 'uri', 'rule_id', 'message', 'action'],
            ],
            'server_time',
        ]);
    }
}
