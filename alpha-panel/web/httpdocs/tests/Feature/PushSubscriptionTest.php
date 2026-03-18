<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_store_push_subscription(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('user.push-subscription.store'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
            'keys' => [
                'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8p8REfWxA',
                'auth' => 'tBHItJI5svbpC7ZDjnb7aw',
            ],
            'content_encoding' => 'aesgcm',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'subscribed');
        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_type' => User::class,
            'subscribable_id' => $user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
        ]);
    }

    public function test_user_can_delete_push_subscription(): void
    {
        $user = User::factory()->create();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint';

        $user->updatePushSubscription($endpoint, 'p256dh-key', 'auth-key', 'aesgcm');

        $response = $this->actingAs($user)->deleteJson(route('user.push-subscription.destroy'), [
            'endpoint' => $endpoint,
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'unsubscribed');
        $this->assertDatabaseMissing('push_subscriptions', [
            'subscribable_id' => $user->id,
            'endpoint' => $endpoint,
        ]);
    }

    public function test_user_can_check_subscription_status(): void
    {
        $user = User::factory()->create();

        $responseEmpty = $this->actingAs($user)->getJson(route('user.push-subscription.status'));
        $responseEmpty->assertOk();
        $responseEmpty->assertJsonPath('subscribed', false);

        $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/test', 'key', 'auth', 'aesgcm');

        $responseSubscribed = $this->actingAs($user)->getJson(route('user.push-subscription.status'));
        $responseSubscribed->assertOk();
        $responseSubscribed->assertJsonPath('subscribed', true);
    }

    public function test_guest_cannot_manage_push_subscriptions(): void
    {
        $this->postJson(route('user.push-subscription.store'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test',
            'keys' => ['p256dh' => 'key', 'auth' => 'auth'],
        ])->assertUnauthorized();

        $this->deleteJson(route('user.push-subscription.destroy'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test',
        ])->assertUnauthorized();

        $this->getJson(route('user.push-subscription.status'))->assertUnauthorized();
    }

    public function test_store_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('user.push-subscription.store'), []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    }

    public function test_status_checks_specific_endpoint_when_provided(): void
    {
        $user = User::factory()->create();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/specific-browser';
        $otherEndpoint = 'https://fcm.googleapis.com/fcm/send/other-browser';

        $user->updatePushSubscription($endpoint, 'key', 'auth', 'aesgcm');

        // This endpoint exists — should return true
        $response = $this->actingAs($user)->getJson(
            route('user.push-subscription.status', ['endpoint' => $endpoint])
        );
        $response->assertOk();
        $response->assertJsonPath('subscribed', true);

        // Different endpoint — should return false
        $response = $this->actingAs($user)->getJson(
            route('user.push-subscription.status', ['endpoint' => $otherEndpoint])
        );
        $response->assertOk();
        $response->assertJsonPath('subscribed', false);

        // No endpoint param — should return true (any subscription exists)
        $response = $this->actingAs($user)->getJson(route('user.push-subscription.status'));
        $response->assertOk();
        $response->assertJsonPath('subscribed', true);
    }

    public function test_store_saves_device_info_from_user_agent(): void
    {
        $user = User::factory()->create();
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        $response = $this->actingAs($user)->postJson(route('user.push-subscription.store'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/device-test',
            'keys' => [
                'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8p8REfWxA',
                'auth' => 'tBHItJI5svbpC7ZDjnb7aw',
            ],
            'content_encoding' => 'aesgcm',
            'user_agent' => $userAgent,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/device-test',
            'browser_name' => 'Chrome',
            'os_name' => 'Windows',
            'device_type' => 'desktop',
            'user_agent' => $userAgent,
        ]);
    }
}
