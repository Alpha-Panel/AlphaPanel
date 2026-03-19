<?php

namespace Tests\Feature;

use App\Jobs\BackupRestoreJob;
use App\Models\BackupRestoreRun;
use App\Models\BackupSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use DatabaseTransactions;

    private function setupConnectedDrive(): void
    {
        $settings = BackupSetting::instance();
        $settings->update([
            'google_access_token' => 'test-token',
            'google_refresh_token' => 'test-refresh',
            'connected_email' => 'test@gmail.com',
            'drive_folder_id' => 'folder-123',
            'drive_folder_name' => 'Backups',
        ]);
    }

    public function test_non_admin_cannot_restore(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('backups.restore'), [
            'restore_type' => 'website',
            'source_mode' => 'full',
            'target' => 'example.com',
        ]);

        $response->assertForbidden();
    }

    public function test_restore_requires_google_connection(): void
    {
        $admin = User::factory()->admin()->create();

        $settings = BackupSetting::instance();
        $settings->update([
            'google_access_token' => null,
            'google_refresh_token' => null,
        ]);

        $response = $this->actingAs($admin)->post(route('backups.restore'), [
            'restore_type' => 'website',
            'source_mode' => 'full',
            'target' => 'example.com',
        ]);

        $response->assertRedirect(route('backups.index'));
        $response->assertSessionHas('error');
    }

    public function test_restore_validates_restore_type(): void
    {
        $admin = User::factory()->admin()->create();
        $this->setupConnectedDrive();

        $response = $this->actingAs($admin)->post(route('backups.restore'), [
            'restore_type' => 'invalid',
            'source_mode' => 'full',
            'target' => 'example.com',
        ]);

        $response->assertSessionHasErrors('restore_type');
    }

    public function test_restore_validates_source_mode(): void
    {
        $admin = User::factory()->admin()->create();
        $this->setupConnectedDrive();

        $response = $this->actingAs($admin)->post(route('backups.restore'), [
            'restore_type' => 'website',
            'source_mode' => 'invalid',
            'target' => 'example.com',
        ]);

        $response->assertSessionHasErrors('source_mode');
    }

    public function test_restore_validates_target_required(): void
    {
        $admin = User::factory()->admin()->create();
        $this->setupConnectedDrive();

        $response = $this->actingAs($admin)->post(route('backups.restore'), [
            'restore_type' => 'website',
            'source_mode' => 'full',
            'target' => '',
        ]);

        $response->assertSessionHasErrors('target');
    }

    public function test_restore_dispatches_job(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $this->setupConnectedDrive();

        $response = $this->actingAs($admin)->post(route('backups.restore'), [
            'restore_type' => 'website',
            'source_mode' => 'full',
            'target' => 'example.com',
            'source_drive_folder_id' => 'folder-abc',
            'source_drive_file_id' => 'file-xyz',
        ]);

        $response->assertRedirect(route('backups.index'));
        $response->assertSessionHas('success');

        Queue::assertPushed(BackupRestoreJob::class);

        $this->assertDatabaseHas('backup_restore_runs', [
            'restore_type' => 'website',
            'source_mode' => 'full',
            'target' => 'example.com',
            'status' => 'pending',
            'triggered_by' => $admin->id,
        ]);
    }

    public function test_restore_creates_audit_log(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $this->setupConnectedDrive();

        $this->actingAs($admin)->post(route('backups.restore'), [
            'restore_type' => 'database',
            'source_mode' => 'full',
            'target' => 'my_database',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'backup_restore_started',
        ]);
    }

    public function test_restore_prevents_concurrent_restores(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $this->setupConnectedDrive();

        BackupRestoreRun::factory()->restoring()->create();

        $response = $this->actingAs($admin)->post(route('backups.restore'), [
            'restore_type' => 'website',
            'source_mode' => 'full',
            'target' => 'example.com',
        ]);

        $response->assertRedirect(route('backups.index'));
        $response->assertSessionHas('error');
    }

    public function test_cancel_restore(): void
    {
        $admin = User::factory()->admin()->create();

        $restoreRun = BackupRestoreRun::factory()->restoring()->create([
            'triggered_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->post(route('backups.restore.cancel', $restoreRun));

        $response->assertRedirect(route('backups.index'));
        $response->assertSessionHas('success');

        $restoreRun->refresh();
        $this->assertEquals('cancelled', $restoreRun->status);
        $this->assertNotNull($restoreRun->finished_at);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'backup_restore_cancelled',
        ]);
    }

    public function test_cancel_restore_rejects_completed(): void
    {
        $admin = User::factory()->admin()->create();

        $restoreRun = BackupRestoreRun::factory()->completed()->create();

        $response = $this->actingAs($admin)->post(route('backups.restore.cancel', $restoreRun));

        $response->assertRedirect(route('backups.index'));
        $response->assertSessionHas('error');
    }

    public function test_non_admin_cannot_cancel_restore(): void
    {
        $user = User::factory()->create();

        $restoreRun = BackupRestoreRun::factory()->restoring()->create();

        $response = $this->actingAs($user)->post(route('backups.restore.cancel', $restoreRun));

        $response->assertForbidden();
    }

    public function test_restore_history_endpoint(): void
    {
        $admin = User::factory()->admin()->create();

        BackupRestoreRun::factory()->count(3)->create();

        $response = $this->actingAs($admin)->getJson(route('backups.restore-history'));

        $response->assertOk();
        $response->assertJsonPath('total', 3);
    }

    public function test_non_admin_cannot_access_restore_history(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(route('backups.restore-history'));

        $response->assertForbidden();
    }

    public function test_index_shows_recent_restore_runs(): void
    {
        $admin = User::factory()->admin()->create();

        BackupRestoreRun::factory()->completed()->create([
            'target' => 'example.com',
            'restore_type' => 'website',
        ]);

        $response = $this->actingAs($admin)->get(route('backups.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Backups/Index')
            ->has('recent_restore_runs', 1)
            ->where('recent_restore_runs.0.target', 'example.com')
        );
    }

    public function test_restore_with_database_type(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $this->setupConnectedDrive();

        $response = $this->actingAs($admin)->post(route('backups.restore'), [
            'restore_type' => 'database',
            'source_mode' => 'full',
            'target' => 'my_database',
            'source_drive_file_id' => 'file-db-123',
        ]);

        $response->assertRedirect(route('backups.index'));

        $this->assertDatabaseHas('backup_restore_runs', [
            'restore_type' => 'database',
            'target' => 'my_database',
        ]);

        Queue::assertPushed(BackupRestoreJob::class);
    }

    public function test_restore_with_incremental_mode(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $this->setupConnectedDrive();

        $response = $this->actingAs($admin)->post(route('backups.restore'), [
            'restore_type' => 'website',
            'source_mode' => 'incremental',
            'target' => 'example.com',
            'source_drive_folder_id' => 'folder-inc-123',
        ]);

        $response->assertRedirect(route('backups.index'));

        $this->assertDatabaseHas('backup_restore_runs', [
            'restore_type' => 'website',
            'source_mode' => 'incremental',
            'target' => 'example.com',
        ]);
    }
}
