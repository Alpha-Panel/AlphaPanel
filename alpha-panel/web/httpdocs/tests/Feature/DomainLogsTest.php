<?php

namespace Tests\Feature;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\User;
use App\Services\DomainRequestLogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use Tests\TestCase;

class DomainLogsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_open_domain_logs_page(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $response = $this->actingAs($owner)->get(route('domains.logs.index', $domain));

        $response->assertOk();
    }

    public function test_owner_can_fetch_domain_logs_entries_json(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $this->mock(DomainRequestLogService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getDomainEntries')
                ->once()
                ->andReturn([
                    [
                        'ts' => now()->toIso8601String(),
                        'type' => 'access',
                        'level' => 'info',
                        'ip' => '127.0.0.1',
                        'request' => 'GET /',
                        'status' => '200',
                        'message' => 'sample',
                        'source' => '/var/log/caddy/example.com.log',
                    ],
                ]);
        });

        $response = $this->actingAs($owner)->get(route('domains.logs.entries', $domain));

        $response->assertOk();
        $response->assertJsonStructure([
            'entries' => [
                ['ts', 'type', 'level', 'ip', 'request', 'status', 'message', 'source'],
            ],
            'server_time',
        ]);
    }
}
