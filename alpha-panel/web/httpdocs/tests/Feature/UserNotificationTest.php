<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\DomainNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
