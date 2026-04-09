<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_authenticated_user_can_view_dashboard_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('home'));

        $response->assertOk();
    }

    public function test_dashboard_data_respects_non_admin_scope(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Domain::factory()->active()->create(['owner_user_id' => $user->id]);
        Domain::factory()->active()->create(['owner_user_id' => $otherUser->id]);

        $response = $this->actingAs($user)->getJson(route('dashboard.data'));

        $response->assertOk();
        $response->assertJsonPath('is_admin', false);
        $response->assertJsonPath('stats.total_domains', 1);
        $response->assertJsonPath('host_metrics', null);
        $response->assertJsonPath('docker_services', null);
        $response->assertJsonPath('mysql_monitor', null);
    }

    public function test_non_admin_cannot_trigger_docker_actions(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('dashboard.docker.action'), [
            'action' => 'restart',
            'container_id' => 'abc123',
            'container_name' => 'sample',
        ]);

        $response->assertForbidden();
    }
}
