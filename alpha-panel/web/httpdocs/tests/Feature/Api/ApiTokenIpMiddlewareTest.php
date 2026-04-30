<?php

namespace Tests\Feature\Api;

use App\Models\ApiTokenIpRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenIpMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_allows_request_when_no_ip_rules_exist(): void
    {
        $user = User::factory()->admin()->create();
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/ping');

        $response->assertStatus(200);
    }

    public function test_allows_request_when_ip_matches_cidr(): void
    {
        $user = User::factory()->admin()->create();
        $pat = $user->createToken('test', ['*']);

        ApiTokenIpRule::create([
            'personal_access_token_id' => $pat->accessToken->id,
            'ip_cidr' => '127.0.0.1/32',
        ]);

        $response = $this->withToken($pat->plainTextToken)->getJson('/api/v1/ping');

        $response->assertStatus(200);
    }

    public function test_blocks_request_when_ip_does_not_match(): void
    {
        $user = User::factory()->admin()->create();
        $pat = $user->createToken('test', ['*']);

        ApiTokenIpRule::create([
            'personal_access_token_id' => $pat->accessToken->id,
            'ip_cidr' => '10.0.0.0/8',
        ]);

        $response = $this->withToken($pat->plainTextToken)->getJson('/api/v1/ping');

        // Test runner IP is 127.0.0.1 which is outside 10.0.0.0/8
        $response->assertStatus(403)
            ->assertJson(['message' => 'IP not allowed']);
    }
}
