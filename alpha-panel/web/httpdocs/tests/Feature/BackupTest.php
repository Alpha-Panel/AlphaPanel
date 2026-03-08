<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\BackupSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
        ]);

        $response->assertRedirect(route('backups.index'));

        $settings = BackupSetting::instance();
        $this->assertTrue($settings->is_enabled);
        $this->assertEquals(30, $settings->backup_retention_days);
    }

    public function test_update_settings_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('backups.settings'), [
            'is_enabled' => true,
            'backup_retention_days' => 14,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'backup_settings_updated',
        ]);
    }

    public function test_run_backup_requires_google_connection(): void
    {
        $admin = User::factory()->admin()->create();

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

        BackupRun::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get(route('backups.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('recent_runs', 3)
        );
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
