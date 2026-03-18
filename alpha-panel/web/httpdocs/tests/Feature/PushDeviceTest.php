<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PushDeviceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_view_push_devices_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('user.push-devices.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('User/PushDevices')
            ->has('subscriptions')
        );
    }

    public function test_user_sees_only_own_subscriptions(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/user-endpoint', 'key1', 'auth1', 'aesgcm');
        $otherUser->updatePushSubscription('https://fcm.googleapis.com/fcm/send/other-endpoint', 'key2', 'auth2', 'aesgcm');

        $response = $this->actingAs($user)->get(route('user.push-devices.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('User/PushDevices')
            ->has('subscriptions', 1)
            ->where('subscriptions.0.endpoint', 'https://fcm.googleapis.com/fcm/send/user-endpoint')
        );
    }

    public function test_user_can_remove_own_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/test', 'key', 'auth', 'aesgcm');

        $response = $this->actingAs($user)->deleteJson(route('user.push-devices.destroy', ['pushSubscription' => $subscription->id]));

        $response->assertOk();
        $response->assertJsonPath('status', 'removed');
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_user_cannot_remove_other_users_subscription(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $subscription = $otherUser->updatePushSubscription('https://fcm.googleapis.com/fcm/send/other', 'key', 'auth', 'aesgcm');

        $response = $this->actingAs($user)->deleteJson(route('user.push-devices.destroy', ['pushSubscription' => $subscription->id]));

        $response->assertForbidden();
        $this->assertDatabaseHas('push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_guest_cannot_access_push_devices(): void
    {
        $this->get(route('user.push-devices.index'))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_delete_push_device(): void
    {
        $user = User::factory()->create();
        $subscription = $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/test', 'key', 'auth', 'aesgcm');

        $this->deleteJson(route('user.push-devices.destroy', ['pushSubscription' => $subscription->id]))->assertUnauthorized();
    }

    public function test_subscription_list_does_not_expose_keys(): void
    {
        $user = User::factory()->create();
        $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/test', 'secret-p256dh-key', 'secret-auth-key', 'aesgcm');

        $response = $this->actingAs($user)->get(route('user.push-devices.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('User/PushDevices')
            ->has('subscriptions', 1)
            ->missing('subscriptions.0.public_key')
            ->missing('subscriptions.0.auth_token')
        );
    }
}
