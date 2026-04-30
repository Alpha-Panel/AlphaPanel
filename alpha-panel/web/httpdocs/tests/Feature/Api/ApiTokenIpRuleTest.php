<?php

namespace Tests\Feature\Api;

use App\Models\ApiTokenIpRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiTokenIpRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_ip_rule_belongs_to_token(): void
    {
        $user = User::factory()->admin()->create();
        $token = $user->createToken('test', ['*']);

        $rule = ApiTokenIpRule::create([
            'personal_access_token_id' => $token->accessToken->id,
            'ip_cidr' => '192.168.1.0/24',
            'description' => 'Office',
        ]);

        $this->assertInstanceOf(PersonalAccessToken::class, $rule->personalAccessToken);
        $this->assertEquals('192.168.1.0/24', $rule->ip_cidr);
    }
}
