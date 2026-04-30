<?php

namespace Tests\Feature\Api;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_domains_requires_authentication(): void
    {
        $this->getJson('/api/v1/domains')->assertStatus(401);
    }

    public function test_list_domains_requires_correct_ability(): void
    {
        $user = User::factory()->admin()->create();
        $this->withToken($user->createToken('t', ['domains:write'])->plainTextToken)
            ->getJson('/api/v1/domains')
            ->assertStatus(403);
    }

    public function test_list_domains_returns_paginated_domains(): void
    {
        $user = User::factory()->admin()->create();
        Domain::factory(3)->create(['owner_user_id' => $user->id]);

        $this->withToken($user->createToken('t', ['domains:read'])->plainTextToken)
            ->getJson('/api/v1/domains')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['total', 'current_page', 'last_page', 'per_page']]);
    }

    public function test_show_domain_returns_domain_data(): void
    {
        $user = User::factory()->admin()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $user->id]);

        $this->withToken($user->createToken('t', ['domains:read'])->plainTextToken)
            ->getJson("/api/v1/domains/{$domain->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $domain->id)
            ->assertJsonPath('data.fqdn', $domain->fqdn);
    }

    public function test_delete_domain_requires_write_ability(): void
    {
        $user = User::factory()->admin()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $user->id]);

        $this->withToken($user->createToken('t', ['domains:read'])->plainTextToken)
            ->deleteJson("/api/v1/domains/{$domain->id}")
            ->assertStatus(403);
    }

    public function test_search_domains_returns_results(): void
    {
        $user = User::factory()->admin()->create();

        $this->withToken($user->createToken('t', ['domains:read'])->plainTextToken)
            ->getJson('/api/v1/domains/search?q=example')
            ->assertStatus(200)
            ->assertJsonStructure(['data']);
    }
}
