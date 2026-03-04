<?php

namespace Tests\Feature;

use App\Jobs\SslActivateJob;
use App\Models\Domain;
use App\Models\User;
use App\Services\CloudflareDnsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_non_admin_cannot_access_audit_log_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('audit-logs.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_access_audit_log_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('audit-logs.index'));

        $response->assertOk();
    }

    public function test_dns_record_create_writes_audit_log(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
        ]);

        $this->mock(CloudflareDnsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getZoneId')->once()->andReturn('zone-1');
            $mock->shouldReceive('buildRecordData')->once()->andReturn([
                'type' => 'A',
                'name' => '@',
                'content' => '203.0.113.10',
                'ttl' => 1,
                'proxied' => false,
            ]);
            $mock->shouldReceive('addRecord')->once()->andReturnTrue();
        });

        $response = $this->actingAs($owner)->postJson(route('domains.dns.store', $domain), [
            'record_type' => 'A',
            'name' => '@',
            'content' => '203.0.113.10',
            'ttl' => 1,
            'proxied' => false,
        ]);

        $response->assertOk()->assertJson([
            'status' => 'success',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $owner->id,
            'action' => 'dns_created',
            'domain_id' => $domain->id,
        ]);
    }

    public function test_ssl_activate_request_writes_queued_audit_log(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
        ]);

        $response = $this->actingAs($owner)->post(route('domains.ssl.activate', $domain));

        $response->assertRedirect(route('domains.show', $domain));
        Queue::assertPushed(SslActivateJob::class);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $owner->id,
            'action' => 'ssl_queued',
            'domain_id' => $domain->id,
        ]);
    }
}
