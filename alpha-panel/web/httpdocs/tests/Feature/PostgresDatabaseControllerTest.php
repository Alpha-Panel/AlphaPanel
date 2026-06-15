<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\ManagedPostgresDatabase;
use App\Models\ManagedPostgresDatabaseUser;
use App\Models\User;
use App\Services\PostgresAdminService;
use Tests\TestCase;

class PostgresDatabaseControllerTest extends TestCase
{
    private function adminWithDomain(): array
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $domain = Domain::factory()->create(['owner_user_id' => $user->id]);

        return [$user, $domain];
    }

    private function nonAdminWithDomain(): array
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $user->id]);

        return [$user, $domain];
    }

    // ── json ─────────────────────────────────────────────────────────────────

    public function test_json_returns_pg_databases_for_domain_owner(): void
    {
        [$user, $domain] = $this->nonAdminWithDomain();
        ManagedPostgresDatabase::factory()->create(['domain_id' => $domain->id]);

        $response = $this->actingAs($user)->getJson(route('domains.postgres-databases.json', $domain));

        $response->assertOk()->assertJsonCount(1);
    }

    public function test_json_requires_authentication(): void
    {
        $domain = Domain::factory()->create();

        $this->getJson(route('domains.postgres-databases.json', $domain))->assertUnauthorized();
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_pg_database_and_user(): void
    {
        [$user, $domain] = $this->nonAdminWithDomain();

        $pgAdmin = $this->mock(PostgresAdminService::class);
        $pgAdmin->shouldReceive('createUser')->once()->with('testuser', 'password123!');
        $pgAdmin->shouldReceive('createDatabase')->once()->with('testdb', 'testuser');

        $response = $this->actingAs($user)->postJson(
            route('domains.postgres-databases.store', $domain),
            [
                'db_name' => 'testdb',
                'pg_user' => 'testuser',
                'pg_password' => 'password123!',
                'pg_password_confirmation' => 'password123!',
            ],
        );

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('managed_pg_databases', [
            'domain_id' => $domain->id,
            'db_name' => 'testdb',
        ]);
        $this->assertDatabaseHas('managed_pg_database_users', [
            'pg_user' => 'testuser',
        ]);
    }

    public function test_store_validates_unique_db_name(): void
    {
        [$user, $domain] = $this->nonAdminWithDomain();
        ManagedPostgresDatabase::factory()->create(['db_name' => 'existing_db']);

        $response = $this->actingAs($user)->postJson(
            route('domains.postgres-databases.store', $domain),
            [
                'db_name' => 'existing_db',
                'pg_user' => 'newuser',
                'pg_password' => 'password123!',
                'pg_password_confirmation' => 'password123!',
            ],
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['db_name']);
    }

    public function test_store_validates_password_confirmation(): void
    {
        [$user, $domain] = $this->nonAdminWithDomain();

        $response = $this->actingAs($user)->postJson(
            route('domains.postgres-databases.store', $domain),
            [
                'db_name' => 'mydb',
                'pg_user' => 'myuser',
                'pg_password' => 'password123!',
                'pg_password_confirmation' => 'different',
            ],
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['pg_password']);
    }

    // ── destroyDatabase ───────────────────────────────────────────────────────

    public function test_destroy_database_drops_pg_and_deletes_records(): void
    {
        [$user, $domain] = $this->nonAdminWithDomain();
        $db = ManagedPostgresDatabase::factory()->create(['domain_id' => $domain->id]);
        $dbUser = ManagedPostgresDatabaseUser::factory()->create(['managed_pg_database_id' => $db->id]);

        $pgAdmin = $this->mock(PostgresAdminService::class);
        $pgAdmin->shouldReceive('dropDatabase')->once()->with($db->db_name);
        $pgAdmin->shouldReceive('dropUser')->once()->with($dbUser->pg_user);

        $response = $this->actingAs($user)->deleteJson(
            route('domains.postgres-databases.destroy', ['domain' => $domain, 'pgDatabase' => $db]),
        );

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertModelMissing($db);
        $this->assertModelMissing($dbUser);
    }

    public function test_destroy_database_requires_domain_ownership(): void
    {
        [$user] = $this->nonAdminWithDomain();
        $otherDomain = Domain::factory()->create();
        $db = ManagedPostgresDatabase::factory()->create(['domain_id' => $otherDomain->id]);

        $this->actingAs($user)
            ->deleteJson(route('domains.postgres-databases.destroy', ['domain' => $otherDomain, 'pgDatabase' => $db]))
            ->assertForbidden();
    }

    // ── storeUser ─────────────────────────────────────────────────────────────

    public function test_store_user_adds_pg_user_to_database(): void
    {
        [$user, $domain] = $this->nonAdminWithDomain();
        $db = ManagedPostgresDatabase::factory()->create(['domain_id' => $domain->id]);

        $pgAdmin = $this->mock(PostgresAdminService::class);
        $pgAdmin->shouldReceive('createUser')->once()->with('extrauser', 'password123!');
        $pgAdmin->shouldReceive('grantPrivileges')->once()->with($db->db_name, 'extrauser');

        $response = $this->actingAs($user)->postJson(
            route('domains.postgres-databases.users.store', ['domain' => $domain, 'pgDatabase' => $db]),
            [
                'pg_user' => 'extrauser',
                'pg_password' => 'password123!',
                'pg_password_confirmation' => 'password123!',
            ],
        );

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('managed_pg_database_users', ['pg_user' => 'extrauser']);
    }

    // ── updateUserPassword ────────────────────────────────────────────────────

    public function test_update_user_password_changes_pg_password(): void
    {
        [$user, $domain] = $this->nonAdminWithDomain();
        $db = ManagedPostgresDatabase::factory()->create(['domain_id' => $domain->id]);
        $pgUser = ManagedPostgresDatabaseUser::factory()->create(['managed_pg_database_id' => $db->id]);

        $pgAdmin = $this->mock(PostgresAdminService::class);
        $pgAdmin->shouldReceive('changePassword')->once()->with($pgUser->pg_user, 'newpassword!');

        $response = $this->actingAs($user)->putJson(
            route('domains.postgres-databases.users.password', ['domain' => $domain, 'pgUser' => $pgUser]),
            [
                'pg_password' => 'newpassword!',
                'pg_password_confirmation' => 'newpassword!',
            ],
        );

        $response->assertOk()->assertJsonPath('status', 'success');
    }

    // ── destroyUser ───────────────────────────────────────────────────────────

    public function test_destroy_user_revokes_and_drops_pg_user(): void
    {
        [$user, $domain] = $this->nonAdminWithDomain();
        $db = ManagedPostgresDatabase::factory()->create(['domain_id' => $domain->id]);
        $pgUser = ManagedPostgresDatabaseUser::factory()->create(['managed_pg_database_id' => $db->id]);

        $pgAdmin = $this->mock(PostgresAdminService::class);
        $pgAdmin->shouldReceive('revokeFromDatabase')->once()->with($db->db_name, $pgUser->pg_user);
        $pgAdmin->shouldReceive('dropUser')->once()->with($pgUser->pg_user);

        $response = $this->actingAs($user)->deleteJson(
            route('domains.postgres-databases.users.destroy', ['domain' => $domain, 'pgUser' => $pgUser]),
        );

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertModelMissing($pgUser);
    }
}
