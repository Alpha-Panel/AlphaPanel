<?php

namespace Tests\Feature;

use App\Jobs\BackupUploadJob;
use App\Models\BackupRun;
use App\Models\BackupSetting;
use App\Models\User;
use App\Services\GoogleDriveService;
use GuzzleHttp\Psr7\Stream;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use DatabaseTransactions;

    public function test_non_admin_cannot_access_backup_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('backups.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_view_backup_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('backups.index'));

        $response->assertOk();
    }

    public function test_backup_page_shows_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('backups.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Backups/Index')
            ->has('settings')
            ->has('recent_runs')
        );
    }

    public function test_connect_redirects_to_google_oauth(): void
    {
        config([
            'backup.google.client_id' => 'test-client-id',
            'backup.google.client_secret' => 'test-client-secret',
        ]);

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('backups.connect'));

        $response->assertRedirect();
        $this->assertStringContains('accounts.google.com', $response->headers->get('Location') ?? '');
    }

    public function test_callback_rejects_invalid_state(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('backups.callback', [
            'state' => 'invalid-state',
            'code' => 'some-code',
        ]));

        $response->assertRedirect(route('backups.index'));
        $response->assertSessionHas('error');
    }

    public function test_callback_rejects_missing_code(): void
    {
        $admin = User::factory()->admin()->create();

        $this->withSession(['google_oauth_state' => 'valid-state']);

        $response = $this->actingAs($admin)->get(route('backups.callback', [
            'state' => 'valid-state',
        ]));

        $response->assertRedirect(route('backups.index'));
        $response->assertSessionHas('error');
    }

    public function test_disconnect_clears_tokens(): void
    {
        $admin = User::factory()->admin()->create();

        $settings = BackupSetting::instance();
        $settings->update([
            'google_access_token' => 'test-access-token',
            'google_refresh_token' => 'test-refresh-token',
            'connected_email' => 'test@gmail.com',
            'drive_folder_id' => 'folder-123',
            'drive_folder_name' => 'Backups',
        ]);

        $response = $this->actingAs($admin)->post(route('backups.disconnect'));

        $response->assertRedirect(route('backups.index'));

        $settings->refresh();
        $this->assertNull($settings->google_access_token);
        $this->assertNull($settings->google_refresh_token);
        $this->assertNull($settings->connected_email);
        $this->assertNull($settings->drive_folder_id);
    }

    public function test_non_admin_cannot_disconnect(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('backups.disconnect'));

        $response->assertForbidden();
    }

    public function test_update_settings_validates_input(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('backups.settings'), [
            'is_enabled' => true,
            'backup_retention_days' => 0,
        ]);

        $response->assertSessionHasErrors('backup_retention_days');
    }

    public function test_update_settings_persists_values(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('backups.settings'), [
            'is_enabled' => true,
            'backup_retention_days' => 30,
            'backup_schedule' => 'daily',
            'backup_time' => '03:00',
        ]);

        $response->assertRedirect(route('backups.index'));

        $settings = BackupSetting::instance();
        $settings->refresh();
        $this->assertTrue($settings->is_enabled);
        $this->assertEquals(30, $settings->backup_retention_days);
    }

    public function test_update_settings_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('backups.settings'), [
            'is_enabled' => true,
            'backup_retention_days' => 14,
            'backup_schedule' => 'daily',
            'backup_time' => '03:00',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'backup_settings_updated',
        ]);
    }

    public function test_run_backup_requires_google_connection(): void
    {
        $admin = User::factory()->admin()->create();

        $settings = BackupSetting::instance();
        $settings->update([
            'google_access_token' => null,
            'google_refresh_token' => null,
            'connected_email' => null,
            'drive_folder_id' => null,
        ]);

        $response = $this->actingAs($admin)->post(route('backups.run'));

        $response->assertRedirect(route('backups.index'));
        $response->assertSessionHas('error');
    }

    public function test_set_folder_validates_input(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('backups.folder'), [
            'drive_folder_id' => '',
            'drive_folder_name' => '',
        ]);

        $response->assertSessionHasErrors(['drive_folder_id', 'drive_folder_name']);
    }

    public function test_set_folder_persists_and_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('backups.folder'), [
            'drive_folder_id' => 'folder-abc-123',
            'drive_folder_name' => 'My Backups',
        ]);

        $response->assertRedirect(route('backups.index'));

        $settings = BackupSetting::instance();
        $this->assertEquals('folder-abc-123', $settings->drive_folder_id);
        $this->assertEquals('My Backups', $settings->drive_folder_name);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'backup_folder_changed',
        ]);
    }

    public function test_backup_history_returns_recent_runs(): void
    {
        $admin = User::factory()->admin()->create();

        $existingCount = BackupRun::count();
        BackupRun::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get(route('backups.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('recent_runs', $existingCount + 3)
            ->has('expired_count')
        );
    }

    public function test_expired_runs_excluded_from_index_and_counted(): void
    {
        $admin = User::factory()->admin()->create();

        $settings = BackupSetting::instance();
        $settings->update(['backup_retention_days' => 10]);

        // Recent run (within retention)
        $recent = BackupRun::factory()->completed()->create(['started_at' => now()->subDays(5)]);
        // Expired run (beyond retention)
        $expired = BackupRun::factory()->completed()->create(['started_at' => now()->subDays(15)]);

        $response = $this->actingAs($admin)->get(route('backups.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('expired_count', fn ($count) => $count >= 1)
        );

        // Recent run must appear, expired must not
        $runIds = collect($response->viewData('page')['props']['recent_runs'])
            ->pluck('id');

        $this->assertContains($recent->id, $runIds->toArray());
        $this->assertNotContains($expired->id, $runIds->toArray());
    }

    public function test_history_endpoint_returns_expired_runs(): void
    {
        $admin = User::factory()->admin()->create();

        $settings = BackupSetting::instance();
        $settings->update(['backup_retention_days' => 10]);

        $expired = BackupRun::factory()->completed()->create(['started_at' => now()->subDays(15)]);
        BackupRun::factory()->completed()->create(['started_at' => now()->subDays(3)]);

        $response = $this->actingAs($admin)->getJson(route('backups.history'));

        $response->assertOk();
        $response->assertJsonStructure(['data', 'current_page', 'last_page', 'total']);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($expired->id, $ids->toArray());
    }

    public function test_disconnect_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();

        $settings = BackupSetting::instance();
        $settings->update([
            'google_refresh_token' => 'test-token',
            'connected_email' => 'test@gmail.com',
        ]);

        $this->actingAs($admin)->post(route('backups.disconnect'));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'backup_google_disconnected',
        ]);
    }

    public function test_cancel_active_backup(): void
    {
        $admin = User::factory()->admin()->create();

        $run = BackupRun::factory()->uploading()->create([
            'triggered_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->post(route('backups.cancel', $run));

        $response->assertRedirect(route('backups.index'));
        $response->assertSessionHas('success');

        $run->refresh();
        $this->assertEquals('cancelled', $run->status);
        $this->assertNotNull($run->finished_at);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'backup_cancelled',
        ]);
    }

    public function test_cancel_rejects_completed_backup(): void
    {
        $admin = User::factory()->admin()->create();

        $run = BackupRun::factory()->completed()->create();

        $response = $this->actingAs($admin)->post(route('backups.cancel', $run));

        $response->assertRedirect(route('backups.index'));
        $response->assertSessionHas('error');
    }

    public function test_restart_cancels_active_and_dispatches_new(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();

        $settings = BackupSetting::instance();
        $settings->update([
            'google_access_token' => 'test-token',
            'google_refresh_token' => 'test-refresh',
            'connected_email' => 'test@gmail.com',
            'drive_folder_id' => 'folder-123',
            'drive_folder_name' => 'Backups',
        ]);

        $existingRun = BackupRun::factory()->uploading()->create();

        $response = $this->actingAs($admin)->post(route('backups.restart'));

        $response->assertRedirect(route('backups.index'));
        $response->assertSessionHas('success');

        $existingRun->refresh();
        $this->assertEquals('cancelled', $existingRun->status);

        Queue::assertPushed(BackupUploadJob::class);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'backup_restarted',
        ]);
    }

    public function test_restart_requires_google_connection(): void
    {
        $admin = User::factory()->admin()->create();

        $settings = BackupSetting::instance();
        $settings->update([
            'google_access_token' => null,
            'google_refresh_token' => null,
            'connected_email' => null,
            'drive_folder_id' => null,
            'drive_folder_name' => null,
        ]);

        $response = $this->actingAs($admin)->post(route('backups.restart'));

        $response->assertRedirect(route('backups.index'));
        $response->assertSessionHas('error');
    }

    public function test_non_admin_cannot_cancel_backup(): void
    {
        $user = User::factory()->create();

        $run = BackupRun::factory()->uploading()->create();

        $response = $this->actingAs($user)->post(route('backups.cancel', $run));

        $response->assertForbidden();
    }

    public function test_drive_quota_endpoint_returns_json(): void
    {
        $admin = User::factory()->admin()->create();

        $mock = Mockery::mock(GoogleDriveService::class);
        $mock->shouldReceive('refreshTokenIfNeeded')->andReturnNull();
        $mock->shouldReceive('getStorageQuota')->once()->andReturn([
            'usage' => 5368709120,
            'limit' => 16106127360,
        ]);
        $this->app->instance(GoogleDriveService::class, $mock);

        $response = $this->actingAs($admin)->getJson(route('backups.drive-quota'));

        $response->assertOk();
        $response->assertJson([
            'usage' => 5368709120,
            'limit' => 16106127360,
        ]);
    }

    public function test_drive_files_endpoint_returns_json(): void
    {
        $admin = User::factory()->admin()->create();

        $mock = Mockery::mock(GoogleDriveService::class);
        $mock->shouldReceive('refreshTokenIfNeeded')->andReturnNull();
        $mock->shouldReceive('listFilesAndFolders')->once()->with('folder-123')->andReturn([
            ['id' => 'f1', 'name' => 'mysql', 'mimeType' => 'application/vnd.google-apps.folder', 'size' => null, 'modifiedTime' => '2026-03-19T10:00:00.000Z'],
            ['id' => 'f2', 'name' => 'backup.tar.gz', 'mimeType' => 'application/gzip', 'size' => 1048576, 'modifiedTime' => '2026-03-19T10:30:00.000Z'],
        ]);
        $this->app->instance(GoogleDriveService::class, $mock);

        $response = $this->actingAs($admin)->getJson(route('backups.drive-files', ['parent_id' => 'folder-123']));

        $response->assertOk();
        $response->assertJsonCount(2, 'files');
        $response->assertJsonPath('files.0.name', 'mysql');
        $response->assertJsonPath('files.1.name', 'backup.tar.gz');
    }

    public function test_drive_download_streams_file(): void
    {
        $admin = User::factory()->admin()->create();

        $streamContent = 'fake-file-content';
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $streamContent);
        rewind($stream);
        $psrStream = new Stream($stream);

        $mock = Mockery::mock(GoogleDriveService::class);
        $mock->shouldReceive('refreshTokenIfNeeded')->andReturnNull();
        $mock->shouldReceive('downloadFile')->once()->with('file-abc-123')->andReturn([
            'name' => 'database.tar.gz',
            'mimeType' => 'application/gzip',
            'size' => strlen($streamContent),
            'stream' => $psrStream,
        ]);
        $this->app->instance(GoogleDriveService::class, $mock);

        $response = $this->actingAs($admin)->get(route('backups.drive-download', 'file-abc-123'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/gzip');
    }

    public function test_non_admin_cannot_access_drive_quota(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(route('backups.drive-quota'));

        $response->assertForbidden();
    }

    public function test_non_admin_cannot_access_drive_files(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(route('backups.drive-files'));

        $response->assertForbidden();
    }

    public function test_non_admin_cannot_download_drive_file(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('backups.drive-download', 'some-file-id'));

        $response->assertForbidden();
    }

    /**
     * Helper to check string containment (PHPUnit 11 compatible).
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'."
        );
    }
}
