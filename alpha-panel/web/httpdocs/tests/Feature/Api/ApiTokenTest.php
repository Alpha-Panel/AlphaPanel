<?php

namespace Tests\Feature\Api;

use App\Models\ApiTokenIpRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_tokens(): void
    {
        $user = User::factory()->admin()->create();
        $user->createToken('my-token', ['domains:read']);

        $response = $this->withToken($user->createToken('test', ['*'])->plainTextToken)
            ->getJson('/api/v1/api-tokens');

        $response->assertStatus(200)->assertJsonStructure(['data']);
    }

    public function test_admin_can_create_token(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->withToken($user->createToken('main', ['*'])->plainTextToken)
            ->postJson('/api/v1/api-tokens', [
                'name' => 'AlphaCenter',
                'abilities' => ['domains:read', 'domains:write'],
                'expires_at' => null,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['token', 'id', 'name']]);
    }

    public function test_admin_can_delete_token(): void
    {
        $user = User::factory()->admin()->create();
        $main = $user->createToken('main', ['*']);
        $target = $user->createToken('target', ['domains:read']);

        $response = $this->withToken($main->plainTextToken)
            ->deleteJson('/api/v1/api-tokens/'.$target->accessToken->id);

        $response->assertStatus(204);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $target->accessToken->id]);
    }

    public function test_admin_can_add_ip_rule_to_token(): void
    {
        $user = User::factory()->admin()->create();
        $main = $user->createToken('main', ['*']);
        $target = $user->createToken('target', ['domains:read']);

        $response = $this->withToken($main->plainTextToken)
            ->postJson('/api/v1/api-tokens/'.$target->accessToken->id.'/ip-rules', [
                'ip_cidr' => '192.168.0.0/24',
                'description' => 'Office',
            ]);

        $response->assertStatus(201)->assertJsonPath('data.ip_cidr', '192.168.0.0/24');
    }
}
