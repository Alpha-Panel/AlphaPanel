<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ping_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/ping');
        $response->assertStatus(401);
    }

    public function test_ping_returns_panel_info(): void
    {
        $user = User::factory()->admin()->create();
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/ping');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['panel_version', 'php_version', 'timestamp']]);
    }
}
