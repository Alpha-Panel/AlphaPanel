<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\BackupSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BackupIncrementalTest extends TestCase
{
    use DatabaseTransactions;

    public function test_update_settings_accepts_full_mode(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('backups.settings'), [
            'is_enabled' => true,
            'backup_retention_days' => 14,
            'backup_schedule' => 'daily',
            'backup_time' => '03:00',
            'backup_mode' => 'full',
        ]);

        $response->assertRedirect(route('backups.index'));

        $settings = BackupSetting::instance();
        $settings->refresh();
        $this->assertEquals('full', $settings->backup_mode);
    }

    public function test_update_settings_accepts_incremental_mode(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('backups.settings'), [
            'is_enabled' => true,
            'backup_retention_days' => 14,
            'backup_schedule' => 'daily',
            'backup_time' => '03:00',
            'backup_mode' => 'incremental',
        ]);

        $response->assertRedirect(route('backups.index'));

        $settings = BackupSetting::instance();
        $settings->refresh();
        $this->assertEquals('incremental', $settings->backup_mode);
    }

    public function test_update_settings_rejects_invalid_mode(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('backups.settings'), [
            'is_enabled' => true,
            'backup_retention_days' => 14,
            'backup_schedule' => 'daily',
            'backup_time' => '03:00',
            'backup_mode' => 'invalid',
        ]);

        $response->assertSessionHasErrors('backup_mode');
    }

    public function test_backup_mode_persists_across_requests(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('backups.settings'), [
            'is_enabled' => true,
            'backup_retention_days' => 7,
            'backup_schedule' => 'weekly',
            'backup_time' => '02:00',
            'backup_mode' => 'incremental',
        ]);

        $settings = BackupSetting::instance();
        $settings->refresh();
        $this->assertEquals('incremental', $settings->backup_mode);

        // Update again with full
        $this->actingAs($admin)->post(route('backups.settings'), [
            'is_enabled' => false,
            'backup_retention_days' => 30,
            'backup_schedule' => 'daily',
            'backup_time' => '04:00',
            'backup_mode' => 'full',
        ]);

        $settings->refresh();
        $this->assertEquals('full', $settings->backup_mode);
    }

    public function test_backup_mode_shown_in_index_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $settings = BackupSetting::instance();
        $settings->update(['backup_mode' => 'incremental']);

        $response = $this->actingAs($admin)->get(route('backups.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Backups/Index')
            ->where('settings.backup_mode', 'incremental')
        );
    }

    public function test_backup_mode_shown_in_recent_runs(): void
    {
        $admin = User::factory()->admin()->create();

        BackupRun::factory()->completed()->create(['backup_mode' => 'incremental']);

        $response = $this->actingAs($admin)->get(route('backups.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Backups/Index')
            ->has('recent_runs')
            ->where('recent_runs.0.backup_mode', 'incremental')
        );
    }

    public function test_backup_run_stores_mode(): void
    {
        $run = BackupRun::factory()->create(['backup_mode' => 'incremental']);

        $this->assertEquals('incremental', $run->backup_mode);
    }

    public function test_backup_run_defaults_to_full_mode(): void
    {
        $run = BackupRun::factory()->create();

        $this->assertEquals('full', $run->backup_mode);
    }
}
