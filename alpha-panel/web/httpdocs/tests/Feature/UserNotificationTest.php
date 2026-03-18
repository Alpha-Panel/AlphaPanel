<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\DomainNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserNotificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_notifications_page_returns_paginated_notifications(): void
    {
        $user = User::factory()->create();

        for ($index = 1; $index <= 25; $index++) {
            $this->createNotification($user, "Test Notification {$index}");
        }

        $response = $this->actingAs($user)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->get(route('user.notifications.page'));

        $response->assertOk();
        $response->assertHeader('X-Inertia', 'true');
        $response->assertJsonPath('component', 'User/Notifications');
        $response->assertJsonPath('props.notifications.current_page', 1);
        $response->assertJsonPath('props.notifications.last_page', 2);
        $this->assertCount(20, $response->json('props.notifications.data'));
    }

    public function test_user_can_mark_notification_as_read_and_delete_notification(): void
    {
        $user = User::factory()->create();
        $notificationId = $this->createNotification($user, 'Read and delete notification');

        $markReadResponse = $this->actingAs($user)->post(route('user.notifications.read', $notificationId));
        $markReadResponse->assertOk();
        $markReadResponse->assertJsonPath('status', 'success');
        $this->assertDatabaseMissing('notifications', [
            'id' => $notificationId,
            'read_at' => null,
        ]);

        $deleteResponse = $this->actingAs($user)->delete(route('user.notifications.destroy', $notificationId));
        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('status', 'success');
        $this->assertDatabaseMissing('notifications', [
            'id' => $notificationId,
        ]);
    }

    public function test_user_can_delete_all_notifications(): void
    {
        $user = User::factory()->create();

        for ($index = 1; $index <= 5; $index++) {
            $this->createNotification($user, "Notification {$index}");
        }

        $this->assertSame(5, $user->notifications()->count());

        $response = $this->actingAs($user)->delete(route('user.notifications.destroy-all'));

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('unread_count', 0);
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_delete_all_only_deletes_own_notifications(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        for ($index = 1; $index <= 3; $index++) {
            $this->createNotification($user, "User notification {$index}");
        }

        for ($index = 1; $index <= 2; $index++) {
            $this->createNotification($other, "Other notification {$index}");
        }

        $response = $this->actingAs($user)->delete(route('user.notifications.destroy-all'));

        $response->assertOk();
        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(2, $other->notifications()->count());
    }

    public function test_user_cannot_delete_another_users_notification(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $notificationId = $this->createNotification($owner, 'Owner notification');

        $response = $this->actingAs($other)->delete(route('user.notifications.destroy', $notificationId));

        $response->assertForbidden();
        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
        ]);
    }

    private function createNotification(User $user, string $title): string
    {
        $notification = $user->notifications()->create([
            'id' => Str::uuid()->toString(),
            'type' => DomainNotification::class,
            'data' => [
                'level' => 'info',
                'title' => $title,
                'body' => 'Body',
                'domain_id' => null,
                'url' => null,
                'icon' => 'bx bx-bell',
            ],
        ]);

        return (string) $notification->id;
    }
}
