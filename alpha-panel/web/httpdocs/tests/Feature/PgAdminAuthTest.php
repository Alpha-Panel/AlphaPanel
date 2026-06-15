<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PgAdminAuthTest extends TestCase
{
    public function test_auth_endpoint_rejects_request_with_no_cookie(): void
    {
        $response = $this->get('/api/pgadmin/auth');

        $response->assertRedirect('/login');
    }

    public function test_auth_endpoint_rejects_expired_or_unknown_token(): void
    {
        $response = $this->withCookie('pgadmin_sso', 'invalid-token')
            ->get('/api/pgadmin/auth');

        $response->assertRedirect('/login');
    }

    public function test_auth_endpoint_accepts_valid_cached_token(): void
    {
        Cache::put('pgadmin:sso:validtoken123', 'admin@example.com', 3600);

        $response = $this->withCookie('pgadmin_sso', 'validtoken123')
            ->get('/api/pgadmin/auth');

        $response->assertOk();
        $response->assertHeader('X-Remote-User', 'admin@example.com');
    }

    public function test_sso_redirect_requires_authentication(): void
    {
        $response = $this->get('/api/pgadmin/sso');

        $response->assertRedirect('/login');
    }

    public function test_sso_redirect_requires_admin_role(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/api/pgadmin/sso');

        $response->assertRedirect('/login');
    }

    public function test_sso_redirect_mints_token_and_sets_cookie_for_admin(): void
    {
        config(['services.pgadmin.url' => 'https://pg.example.com:8443']);
        config(['services.pgadmin.cookie_domain' => '.example.com']);

        $user = User::factory()->create();
        $user->assignRole('Admin');

        $response = $this->actingAs($user)->get('/api/pgadmin/sso');

        $response->assertRedirect('https://pg.example.com:8443');
        $this->assertNotNull($response->headers->getCookies()[0] ?? null);

        $cookieName = $response->headers->getCookies()[0]->getName();
        $this->assertSame('pgadmin_sso', $cookieName);
    }
}
