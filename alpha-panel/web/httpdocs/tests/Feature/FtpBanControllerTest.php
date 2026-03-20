<?php

namespace Tests\Feature;

use App\Models\FtpBanWhitelist;
use App\Models\User;
use App\Services\Portainer\ExecResult;
use App\Services\PortainerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use Tests\TestCase;

class FtpBanControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function mockPortainerForBanInfo(string $output = ''): void
    {
        if ($output === '') {
            $output = implode("\n", [
                'ftpdctl: ban info',
                'Banned Hosts:',
                '  192.168.1.100  since 2026-03-20 10:00:00  rule \'MaxLoginAttempts\'',
                '  10.0.0.50  since 2026-03-20 11:30:00  rule \'MaxConnectionsPerHost\'',
                'Banned Users:',
                '  none',
            ]);
        }

        $this->mock(PortainerService::class, function (MockInterface $mock) use ($output): void {
            $mock->shouldReceive('execInContainer')
                ->andReturn(new ExecResult(exitCode: 0, output: $output, errorOutput: ''));
        });
    }

    public function test_admin_can_view_ftp_bans_page(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mockPortainerForBanInfo();

        $response = $this->actingAs($admin)->get(route('security.ftp-bans.index'));

        $response->assertOk();
    }

    public function test_non_admin_gets_403(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('security.ftp-bans.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_get_ban_data(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mockPortainerForBanInfo();

        $response = $this->actingAs($admin)->getJson(route('security.ftp-bans.data'));

        $response->assertOk();
        $response->assertJsonStructure([
            'bans',
            'whitelist',
        ]);
    }

    public function test_admin_can_ban_ip(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(PortainerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execInContainer')
                ->andReturn(new ExecResult(exitCode: 0, output: 'host banned', errorOutput: ''));
        });

        $response = $this->actingAs($admin)->postJson(route('security.ftp-bans.store'), [
            'ip' => '203.0.113.50',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_ban_invalid_ip_returns_validation_error(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('security.ftp-bans.store'), [
            'ip' => 'not-an-ip',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('ip');
    }

    public function test_admin_can_unban_ip(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(PortainerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execInContainer')
                ->andReturn(new ExecResult(exitCode: 0, output: 'host permitted', errorOutput: ''));
        });

        $response = $this->actingAs($admin)->deleteJson(route('security.ftp-bans.destroy'), [
            'ip' => '203.0.113.50',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_whitelisted_ip_cannot_be_banned(): void
    {
        $admin = User::factory()->admin()->create();

        FtpBanWhitelist::create([
            'ip_address' => '203.0.113.10',
            'note' => 'office IP',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->postJson(route('security.ftp-bans.store'), [
            'ip' => '203.0.113.10',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_admin_can_add_to_whitelist(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(PortainerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execInContainer')
                ->andReturn(new ExecResult(exitCode: 0, output: '', errorOutput: ''));
        });

        $response = $this->actingAs($admin)->postJson(route('security.ftp-bans.whitelist.store'), [
            'ip' => '198.51.100.25',
            'note' => 'trusted server',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);

        $this->assertDatabaseHas('ftp_ban_whitelist', [
            'ip_address' => '198.51.100.25',
            'note' => 'trusted server',
            'created_by' => $admin->id,
        ]);
    }

    public function test_admin_can_remove_from_whitelist(): void
    {
        $admin = User::factory()->admin()->create();

        FtpBanWhitelist::create([
            'ip_address' => '198.51.100.25',
            'note' => 'to be removed',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->deleteJson(route('security.ftp-bans.whitelist.destroy'), [
            'ip' => '198.51.100.25',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);

        $this->assertDatabaseMissing('ftp_ban_whitelist', [
            'ip_address' => '198.51.100.25',
        ]);
    }

    public function test_admin_can_get_ban_log(): void
    {
        $admin = User::factory()->admin()->create();

        $logOutput = implode("\n", [
            '2026-03-20 10:00:00 mod_ban/0.7: 192.168.1.100 banned by rule MaxLoginAttempts',
            '2026-03-20 11:30:00 mod_ban/0.7: 10.0.0.50 banned by rule MaxConnectionsPerHost',
        ]);

        $this->mock(PortainerService::class, function (MockInterface $mock) use ($logOutput): void {
            $mock->shouldReceive('execInContainer')
                ->andReturn(new ExecResult(exitCode: 0, output: $logOutput, errorOutput: ''));
        });

        $response = $this->actingAs($admin)->getJson(route('security.ftp-bans.log'));

        $response->assertOk();
        $response->assertJsonStructure([
            'entries',
        ]);
    }
}
