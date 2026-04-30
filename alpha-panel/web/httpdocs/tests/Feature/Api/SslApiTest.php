<?php

namespace Tests\Feature\Api;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SslApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ssl_index_requires_domains_read(): void
    {
        $user = User::factory()->admin()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $user->id]);

        $this->withToken($user->createToken('t', ['domains:write'])->plainTextToken)
            ->getJson("/api/v1/domains/{$domain->id}/ssl")
            ->assertStatus(403);
    }

    public function test_ssl_index_returns_certificates(): void
    {
        $user = User::factory()->admin()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $user->id]);

        $this->withToken($user->createToken('t', ['domains:read'])->plainTextToken)
            ->getJson("/api/v1/domains/{$domain->id}/ssl")
            ->assertStatus(200)
            ->assertJsonStructure(['data']);
    }
}
