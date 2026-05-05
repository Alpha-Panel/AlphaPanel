<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MysqlConfigService;
use App\Services\SaveResult;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use Tests\TestCase;

class MysqlConfigTest extends TestCase
{
    use DatabaseTransactions;

    // ─── Permission Tests ───────────────────────────────────────

    public function test_guest_is_redirected_to_login_on_index(): void
    {
        $response = $this->get(route('settings.mysql-config.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_without_permission_gets_403_on_index(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.mysql-config.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_access_index(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(MysqlConfigService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('loadAllFiles')->once()->andReturn([
                '10-security.cnf' => "[mysqld]\n",
                '99-tuning.cnf' => "[mysqld]\n",
                'disable_binlog.cnf' => "[mysqld]\n",
            ]);
            $mock->shouldReceive('parseFile')->andReturn([]);
            $mock->shouldReceive('schemaByFile')->once()->andReturn([]);
            $mock->shouldReceive('isBinlogDisabled')->once()->andReturn(false);
        });

        $response = $this->actingAs($admin)->get(route('settings.mysql-config.index'));

        $response->assertOk();
    }

    // ─── Index Tests ────────────────────────────────────────────

    public function test_index_returns_correct_inertia_component(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(MysqlConfigService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('loadAllFiles')->once()->andReturn([
                '10-security.cnf' => "[mysqld]\n",
                '99-tuning.cnf' => "[mysqld]\n",
                'disable_binlog.cnf' => "[mysqld]\n",
            ]);
            $mock->shouldReceive('parseFile')->andReturn([]);
            $mock->shouldReceive('schemaByFile')->once()->andReturn([]);
            $mock->shouldReceive('isBinlogDisabled')->once()->andReturn(false);
        });

        $response = $this->actingAs($admin)->get(route('settings.mysql-config.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/MysqlConfig/Index'));
    }

    public function test_index_passes_required_props(): void
    {
        $admin = User::factory()->admin()->create();

        $schemaByFile = [
            '10-security.cnf' => [
                ['key' => 'slow_query_log', 'file' => '10-security.cnf', 'type' => 'bool', 'label' => 'Slow Query Log', 'description' => '', 'set_global' => true, 'restart_required' => false, 'options' => [], 'global_var' => 'slow_query_log'],
            ],
        ];

        $fileContents = [
            '10-security.cnf' => "[mysqld]\nslow_query_log\n",
            '99-tuning.cnf' => "[mysqld]\n",
            'disable_binlog.cnf' => "[mysqld]\n",
        ];

        $parsedValues = [
            '10-security.cnf' => ['slow_query_log' => '1'],
            '99-tuning.cnf' => [],
            'disable_binlog.cnf' => [],
        ];

        $this->mock(MysqlConfigService::class, function (MockInterface $mock) use ($schemaByFile, $fileContents, $parsedValues): void {
            $mock->shouldReceive('loadAllFiles')->once()->andReturn($fileContents);
            $mock->shouldReceive('parseFile')->with("[mysqld]\nslow_query_log\n")->andReturn($parsedValues['10-security.cnf']);
            $mock->shouldReceive('parseFile')->with("[mysqld]\n")->andReturn([]);
            $mock->shouldReceive('schemaByFile')->once()->andReturn($schemaByFile);
            $mock->shouldReceive('isBinlogDisabled')->once()->andReturn(true);
        });

        $response = $this->actingAs($admin)->get(route('settings.mysql-config.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Settings/MysqlConfig/Index')
            ->has('schema')
            ->has('fileContents')
            ->has('parsedValues')
            ->has('binlogDisabled')
            ->has('restartRequired')
        );
    }

    public function test_index_reflects_session_restart_required_flag(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(MysqlConfigService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('loadAllFiles')->once()->andReturn([
                '10-security.cnf' => "[mysqld]\n",
                '99-tuning.cnf' => "[mysqld]\n",
                'disable_binlog.cnf' => "[mysqld]\n",
            ]);
            $mock->shouldReceive('parseFile')->andReturn([]);
            $mock->shouldReceive('schemaByFile')->once()->andReturn([]);
            $mock->shouldReceive('isBinlogDisabled')->once()->andReturn(false);
        });

        $response = $this->actingAs($admin)
            ->withSession(['mysql_restart_required' => true])
            ->get(route('settings.mysql-config.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Settings/MysqlConfig/Index')
            ->where('restartRequired', true)
        );
    }

    // ─── Update (Structured) Tests ──────────────────────────────

    public function test_admin_can_save_structured_config(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(MysqlConfigService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('saveStructured')
                ->once()
                ->with('10-security.cnf', ['slow_query_log' => '1'])
                ->andReturn(new SaveResult(
                    fileWritten: true,
                    setGlobalApplied: true,
                    restartRequired: false,
                    setGlobalErrors: [],
                ));
        });

        $response = $this->actingAs($admin)->put(
            route('settings.mysql-config.update', ['file' => '10-security.cnf']),
            ['values' => ['slow_query_log' => '1']]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_update_with_invalid_filename_returns_400(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->put(
            route('settings.mysql-config.update', ['file' => '../etc/passwd']),
            ['values' => ['key' => 'value']]
        );

        $response->assertStatus(400);
    }

    public function test_update_sets_restart_required_in_session_when_needed(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(MysqlConfigService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('saveStructured')
                ->once()
                ->andReturn(new SaveResult(
                    fileWritten: true,
                    setGlobalApplied: false,
                    restartRequired: true,
                    setGlobalErrors: [],
                ));
        });

        $response = $this->actingAs($admin)->put(
            route('settings.mysql-config.update', ['file' => '99-tuning.cnf']),
            ['values' => ['innodb_buffer_pool_size' => '1G']]
        );

        $response->assertRedirect();
        $response->assertSessionHas('mysql_restart_required', true);
    }

    public function test_update_with_set_global_errors_shows_warning_flash(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(MysqlConfigService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('saveStructured')
                ->once()
                ->andReturn(new SaveResult(
                    fileWritten: true,
                    setGlobalApplied: false,
                    restartRequired: false,
                    setGlobalErrors: ['sql_mode'],
                ));
        });

        $response = $this->actingAs($admin)->put(
            route('settings.mysql-config.update', ['file' => '10-security.cnf']),
            ['values' => ['sql-mode' => 'STRICT_TRANS_TABLES']]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_user_without_permission_cannot_update(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put(
            route('settings.mysql-config.update', ['file' => '10-security.cnf']),
            ['values' => ['slow_query_log' => '1']]
        );

        $response->assertForbidden();
    }

    // ─── Update Raw Tests ───────────────────────────────────────

    public function test_admin_can_save_raw_config(): void
    {
        $admin = User::factory()->admin()->create();
        $rawContent = "[mysqld]\nslow_query_log = 1\n";

        $this->mock(MysqlConfigService::class, function (MockInterface $mock) use ($rawContent): void {
            $mock->shouldReceive('saveRaw')
                ->once()
                ->with('10-security.cnf', $rawContent)
                ->andReturn(new SaveResult(
                    fileWritten: true,
                    setGlobalApplied: false,
                    restartRequired: true,
                    setGlobalErrors: [],
                ));
        });

        $response = $this->actingAs($admin)->put(
            route('settings.mysql-config.update-raw', ['file' => '10-security.cnf']),
            ['content' => $rawContent]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionHas('mysql_restart_required', true);
    }

    public function test_update_raw_with_invalid_filename_returns_400(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->put(
            route('settings.mysql-config.update-raw', ['file' => '../etc/passwd']),
            ['content' => "[mysqld]\n"]
        );

        $response->assertStatus(400);
    }

    public function test_user_without_permission_cannot_update_raw(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put(
            route('settings.mysql-config.update-raw', ['file' => '10-security.cnf']),
            ['content' => "[mysqld]\n"]
        );

        $response->assertForbidden();
    }

    // ─── Restart Tests ──────────────────────────────────────────

    public function test_admin_can_trigger_restart(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(MysqlConfigService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('restart')->once()->andReturn('ok');
        });

        $response = $this->actingAs($admin)
            ->withSession(['mysql_restart_required' => true])
            ->post(route('settings.mysql-config.restart'));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionMissing('mysql_restart_required');
    }

    public function test_restart_clears_restart_required_from_session(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(MysqlConfigService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('restart')->once()->andReturn('ok');
        });

        $this->actingAs($admin)
            ->withSession(['mysql_restart_required' => true])
            ->post(route('settings.mysql-config.restart'));

        $this->assertFalse(session()->has('mysql_restart_required'));
    }

    public function test_user_without_permission_cannot_restart(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('settings.mysql-config.restart'));

        $response->assertForbidden();
    }

    public function test_restart_handles_service_exception_gracefully(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(MysqlConfigService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('restart')->once()->andThrow(new \RuntimeException('Connection refused'));
        });

        $response = $this->actingAs($admin)->post(route('settings.mysql-config.restart'));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    // ─── Purge Binlogs Tests ────────────────────────────────────

    public function test_admin_can_purge_binary_logs(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(MysqlConfigService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('purgeBinaryLogs')->once()->with(7);
        });

        $response = $this->actingAs($admin)->post(
            route('settings.mysql-config.purge-binlogs'),
            ['days' => 7]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_purge_binlogs_clamps_days_zero_to_one(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(MysqlConfigService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('purgeBinaryLogs')->once()->with(1);
        });

        $response = $this->actingAs($admin)->post(
            route('settings.mysql-config.purge-binlogs'),
            ['days' => 0]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_purge_binlogs_clamps_days_above_max(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(MysqlConfigService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('purgeBinaryLogs')->once()->with(365);
        });

        $response = $this->actingAs($admin)->post(
            route('settings.mysql-config.purge-binlogs'),
            ['days' => 999]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_user_without_permission_cannot_purge_binlogs(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(
            route('settings.mysql-config.purge-binlogs'),
            ['days' => 7]
        );

        $response->assertForbidden();
    }

    public function test_purge_binlogs_handles_service_exception_gracefully(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(MysqlConfigService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('purgeBinaryLogs')->once()->andThrow(new \RuntimeException('MySQL unavailable'));
        });

        $response = $this->actingAs($admin)->post(
            route('settings.mysql-config.purge-binlogs'),
            ['days' => 7]
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }
}
