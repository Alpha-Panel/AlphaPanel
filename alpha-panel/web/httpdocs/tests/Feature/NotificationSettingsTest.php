<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\BackupNotification;
use App\Notifications\DomainNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_view_notification_settings_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('user.notification-settings.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('User/NotificationSettings')
            ->where('tab', 'preferences')
            ->has('preferences', count(NotificationType::cases()))
            ->has('types', count(NotificationType::cases()))
            ->has('subscriptions')
        );
    }

    public function test_user_can_view_devices_tab(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('user.notification-settings.devices'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('User/NotificationSettings')
            ->where('tab', 'devices')
            ->has('subscriptions')
        );
    }

    public function test_user_can_update_notification_preferences(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson(route('user.notification-settings.update'), [
            'preferences' => [
                ['type' => 'domain_notifications', 'database' => true, 'push' => false, 'mail' => true],
                ['type' => 'backup_notifications', 'database' => true, 'push' => true, 'mail' => false],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'saved');

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'type' => 'domain_notifications',
            'database' => true,
            'push' => false,
            'mail' => true,
        ]);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'type' => 'backup_notifications',
            'database' => true,
            'push' => true,
            'mail' => false,
        ]);
    }

    public function test_database_off_forces_push_and_mail_off(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson(route('user.notification-settings.update'), [
            'preferences' => [
                ['type' => 'domain_notifications', 'database' => false, 'push' => true, 'mail' => true],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'type' => 'domain_notifications',
            'database' => false,
            'push' => false,
            'mail' => false,
        ]);
    }

    public function test_default_preferences_when_no_records_exist(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('user.notification-settings.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('preferences.0.database', true)
            ->where('preferences.0.push', true)
            ->where('preferences.0.mail', false)
        );
    }

    public function test_user_can_remove_device_from_new_route(): void
    {
        $user = User::factory()->create();
        $subscription = $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/test', 'key', 'auth', 'aesgcm');

        $response = $this->actingAs($user)->deleteJson(route('user.notification-settings.destroy-device', ['pushSubscription' => $subscription->id]));

        $response->assertOk();
        $response->assertJsonPath('status', 'removed');
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_user_cannot_remove_other_users_device(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $subscription = $otherUser->updatePushSubscription('https://fcm.googleapis.com/fcm/send/other', 'key', 'auth', 'aesgcm');

        $response = $this->actingAs($user)->deleteJson(route('user.notification-settings.destroy-device', ['pushSubscription' => $subscription->id]));

        $response->assertForbidden();
        $this->assertDatabaseHas('push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_guest_cannot_access_notification_settings(): void
    {
        $this->get(route('user.notification-settings.index'))->assertRedirect(route('login'));
    }

    public function test_old_push_devices_route_redirects(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('user.push-devices.index'));

        $response->assertRedirect(route('user.notification-settings.devices'));
    }

    public function test_via_respects_database_off(): void
    {
        $user = User::factory()->create();

        NotificationPreference::query()->create([
            'user_id' => $user->id,
            'type' => NotificationType::DomainNotifications,
            'database' => false,
            'push' => false,
            'mail' => false,
        ]);

        $notification = new DomainNotification('info', 'Test', 'Test body');
        $channels = $notification->via($user);

        $this->assertEquals(['broadcast'], $channels);
    }

    public function test_via_respects_mail_on(): void
    {
        $user = User::factory()->create();

        NotificationPreference::query()->create([
            'user_id' => $user->id,
            'type' => NotificationType::DomainNotifications,
            'database' => true,
            'push' => false,
            'mail' => true,
        ]);

        $notification = new DomainNotification('info', 'Test', 'Test body');
        $channels = $notification->via($user);

        $this->assertContains('database', $channels);
        $this->assertContains('mail', $channels);
        $this->assertContains('broadcast', $channels);
    }

    public function test_via_defaults_without_preferences(): void
    {
        $user = User::factory()->create();

        $notification = new DomainNotification('info', 'Test', 'Test body');
        $channels = $notification->via($user);

        $this->assertContains('broadcast', $channels);
        $this->assertContains('database', $channels);
        $this->assertNotContains('mail', $channels);
    }

    public function test_invalid_notification_type_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson(route('user.notification-settings.update'), [
            'preferences' => [
                ['type' => 'invalid_type', 'database' => true, 'push' => true, 'mail' => false],
            ],
        ]);

        $response->assertUnprocessable();
    }

    public function test_update_is_idempotent(): void
    {
        $user = User::factory()->create();
        $payload = [
            'preferences' => [
                ['type' => 'domain_notifications', 'database' => true, 'push' => false, 'mail' => true],
            ],
        ];

        $this->actingAs($user)->putJson(route('user.notification-settings.update'), $payload)->assertOk();
        $this->actingAs($user)->putJson(route('user.notification-settings.update'), $payload)->assertOk();

        $this->assertDatabaseCount('notification_preferences', 1);
    }

    public function test_backup_notification_respects_preferences(): void
    {
        $user = User::factory()->create();

        NotificationPreference::query()->create([
            'user_id' => $user->id,
            'type' => NotificationType::BackupNotifications,
            'database' => false,
            'push' => false,
            'mail' => false,
        ]);

        $notification = new BackupNotification('info', 'Backup done', 'Backup completed');
        $channels = $notification->via($user);

        $this->assertEquals(['broadcast'], $channels);
    }
}
