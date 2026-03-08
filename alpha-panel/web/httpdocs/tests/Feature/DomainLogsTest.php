<?php

namespace Tests\Feature;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\User;
use App\Services\DomainRequestLogService;
use App\Services\Portainer\ExecResult;
use App\Services\PortainerService;
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

    public function test_domain_request_log_service_prefers_client_ip_from_caddy_json_line(): void
    {
        $domain = Domain::factory()->create([
            'type' => DomainType::CaddyWebServer,
            'fqdn' => 'client-ip-priority.test',
        ]);

        $logLine = json_encode([
            'ts' => '2026-03-08T12:34:56Z',
            'request' => [
                'client_ip' => '198.51.100.77',
                'remote_ip' => '172.64.0.10',
                'method' => 'GET',
                'uri' => '/',
            ],
            'status' => 200,
            'msg' => 'handled request',
        ], JSON_THROW_ON_ERROR);

        $portainer = $this->mock(PortainerService::class, function (MockInterface $mock) use ($logLine): void {
            $mock->shouldReceive('execInContainer')
                ->once()
                ->andReturn(new ExecResult(0, $logLine, ''));
        });

        $service = new DomainRequestLogService($portainer);

        $entries = $service->getDomainEntries($domain, ['limit' => 100]);

        $this->assertCount(1, $entries);
        $this->assertSame('198.51.100.77', $entries[0]['ip']);
    }

    public function test_domain_request_log_service_falls_back_to_remote_ip_when_client_ip_missing(): void
    {
        $domain = Domain::factory()->create([
            'type' => DomainType::CaddyWebServer,
            'fqdn' => 'remote-ip-fallback.test',
        ]);

        $logLine = json_encode([
            'ts' => '2026-03-08T12:34:56Z',
            'request' => [
                'remote_ip' => '172.64.0.11',
                'method' => 'GET',
                'uri' => '/health',
            ],
            'status' => 200,
            'msg' => 'handled request',
        ], JSON_THROW_ON_ERROR);

        $portainer = $this->mock(PortainerService::class, function (MockInterface $mock) use ($logLine): void {
            $mock->shouldReceive('execInContainer')
                ->once()
                ->andReturn(new ExecResult(0, $logLine, ''));
        });

        $service = new DomainRequestLogService($portainer);

        $entries = $service->getDomainEntries($domain, ['limit' => 100]);

        $this->assertCount(1, $entries);
        $this->assertSame('172.64.0.11', $entries[0]['ip']);
    }
}
