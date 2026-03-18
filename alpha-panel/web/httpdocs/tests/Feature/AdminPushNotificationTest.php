<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use App\Notifications\AdminPushNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminPushNotificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_view_push_composer_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.push-notifications.index'));

        $response->assertOk();
    }

    public function test_non_admin_cannot_view_push_composer_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('admin.push-notifications.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_send_push_to_all_users(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        User::factory()->count(2)->create();
        $expectedCount = User::count();

        $response = $this->actingAs($admin)->postJson(route('admin.push-notifications.send'), [
            'title' => 'Test Broadcast',
            'body' => 'This is a test push notification.',
            'target' => 'all',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('recipients_count', $expectedCount);

        Notification::assertSentTo($admin, AdminPushNotification::class);
    }

    public function test_admin_can_send_push_to_admins_only(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $regularUser = User::factory()->create();
        $expectedCount = User::where('admin', true)->count();

        $response = $this->actingAs($admin)->postJson(route('admin.push-notifications.send'), [
            'title' => 'Admin Only',
            'body' => 'Only for admins.',
            'target' => 'admins',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('recipients_count', $expectedCount);

        Notification::assertSentTo($admin, AdminPushNotification::class);
        Notification::assertNotSentTo($regularUser, AdminPushNotification::class);
    }

    public function test_admin_can_send_push_to_domain_owners(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $domainOwner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $domainOwner->id]);

        $response = $this->actingAs($admin)->postJson(route('admin.push-notifications.send'), [
            'title' => 'Domain Notice',
            'body' => 'Message for domain users.',
            'target' => 'domain',
            'domain_id' => $domain->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');

        Notification::assertSentTo($domainOwner, AdminPushNotification::class);
        Notification::assertNotSentTo($admin, AdminPushNotification::class);
    }

    public function test_non_admin_cannot_send_push_notifications(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('admin.push-notifications.send'), [
            'title' => 'Hack attempt',
            'body' => 'Should fail.',
            'target' => 'all',
        ]);

        $response->assertForbidden();
    }

    public function test_send_validates_required_fields(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('admin.push-notifications.send'), []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['title', 'body', 'target']);
    }

    public function test_send_requires_domain_id_for_domain_target(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('admin.push-notifications.send'), [
            'title' => 'Domain Notice',
            'body' => 'Message for domain users.',
            'target' => 'domain',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['domain_id']);
    }

    public function test_send_validates_url_format(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('admin.push-notifications.send'), [
            'title' => 'Test',
            'body' => 'Test body',
            'target' => 'all',
            'url' => 'not-a-valid-url',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['url']);
    }
}
