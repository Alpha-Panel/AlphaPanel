<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification as NotificationContract;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate('panel.users.impersonate', 'web');
    }

    public function test_user_without_permission_cannot_impersonate(): void
    {
        $actor = User::factory()->create(['admin' => false]);
        $target = User::factory()->create(['admin' => false]);

        $this->actingAs($actor)
            ->post(route('impersonation.start', $target))
            ->assertForbidden();

        $this->assertSame($actor->id, Auth::id());
    }

    public function test_user_with_permission_can_impersonate_non_admin(): void
    {
        $actor = User::factory()->create(['admin' => false]);
        $actor->givePermissionTo('panel.users.impersonate');
        $target = User::factory()->create(['admin' => false]);

        $this->actingAs($actor)
            ->post(route('impersonation.start', $target))
            ->assertRedirect(route('home'));

        $this->assertSame($target->id, Auth::id());
        $this->assertDatabaseHas('impersonation_sessions', [
            'impersonator_id' => $actor->id,
            'target_id' => $target->id,
            'ended_at' => null,
        ]);
    }

    public function test_user_with_permission_cannot_impersonate_admin(): void
    {
        $actor = User::factory()->create(['admin' => false]);
        $actor->givePermissionTo('panel.users.impersonate');
        $target = User::factory()->create(['admin' => true]);

        $this->actingAs($actor)
            ->post(route('impersonation.start', $target))
            ->assertForbidden();

        $this->assertSame($actor->id, Auth::id());
    }

    public function test_super_admin_can_impersonate_admin(): void
    {
        $actor = User::factory()->create(['admin' => true]);
        $target = User::factory()->create(['admin' => true]);

        $this->actingAs($actor)
            ->post(route('impersonation.start', $target))
            ->assertRedirect(route('home'));

        $this->assertSame($target->id, Auth::id());
    }

    public function test_cannot_impersonate_self(): void
    {
        $actor = User::factory()->create(['admin' => true]);

        $this->actingAs($actor)
            ->post(route('impersonation.start', $actor))
            ->assertForbidden();
    }

    public function test_cannot_chain_impersonate(): void
    {
        $actor = User::factory()->create(['admin' => true]);
        $first = User::factory()->create(['admin' => false]);
        $second = User::factory()->create(['admin' => false]);

        $this->actingAs($actor)->post(route('impersonation.start', $first));
        $this->assertSame($first->id, Auth::id());

        $this->post(route('impersonation.start', $second))->assertForbidden();
        $this->assertSame($first->id, Auth::id());
    }

    public function test_stop_restores_original_user(): void
    {
        $actor = User::factory()->create(['admin' => true]);
        $target = User::factory()->create(['admin' => false]);

        $this->actingAs($actor)->post(route('impersonation.start', $target));
        $this->assertSame($target->id, Auth::id());

        $this->post(route('impersonation.stop'))->assertRedirect(route('users.list'));

        $this->assertSame($actor->id, Auth::id());
        $this->assertDatabaseMissing('impersonation_sessions', [
            'impersonator_id' => $actor->id,
            'ended_at' => null,
        ]);
    }

    public function test_audit_log_records_impersonator_during_impersonation(): void
    {
        $actor = User::factory()->create(['admin' => true]);
        $target = User::factory()->create(['admin' => false]);

        $this->actingAs($actor)->post(route('impersonation.start', $target));

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'test.action',
            'summary' => 'Did a thing',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $target->id,
            'impersonator_id' => $actor->id,
            'action' => 'test.action',
        ]);
    }

    public function test_start_audit_entry_has_no_impersonator_id(): void
    {
        $actor = User::factory()->create(['admin' => true]);
        $target = User::factory()->create(['admin' => false]);

        $this->actingAs($actor)->post(route('impersonation.start', $target));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'impersonator_id' => null,
            'action' => 'impersonation.start',
        ]);
    }

    public function test_stop_audit_entry_has_no_impersonator_id(): void
    {
        $actor = User::factory()->create(['admin' => true]);
        $target = User::factory()->create(['admin' => false]);

        $this->actingAs($actor)->post(route('impersonation.start', $target));
        $this->post(route('impersonation.stop'));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'impersonator_id' => null,
            'action' => 'impersonation.stop',
        ]);
    }

    public function test_notifications_to_target_are_suppressed(): void
    {
        Notification::fake();

        $actor = User::factory()->create(['admin' => true]);
        $target = User::factory()->create(['admin' => false]);

        $this->actingAs($actor)->post(route('impersonation.start', $target));

        $target->refresh()->notify(new TestImpersonationPingNotification);

        Notification::assertNothingSentTo($target);
    }

    public function test_notifications_to_other_users_still_send(): void
    {
        Notification::fake();

        $actor = User::factory()->create(['admin' => true]);
        $target = User::factory()->create(['admin' => false]);
        $bystander = User::factory()->create(['admin' => false]);

        $this->actingAs($actor)->post(route('impersonation.start', $target));

        $bystander->notify(new TestImpersonationPingNotification);

        Notification::assertSentTo($bystander, TestImpersonationPingNotification::class);
    }

    public function test_inertia_shares_impersonation_prop_when_active(): void
    {
        $actor = User::factory()->create(['admin' => true]);
        $target = User::factory()->create(['admin' => false]);

        $this->actingAs($actor)->post(route('impersonation.start', $target));

        $response = $this->get(route('home'));
        $response->assertInertia(fn ($page) => $page
            ->where('impersonation.active', true)
            ->where('impersonation.impersonator.id', $actor->id)
            ->where('impersonation.target.id', $target->id)
        );
    }
}

class TestImpersonationPingNotification extends NotificationContract
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['msg' => 'ping'];
    }
}
