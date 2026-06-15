<?php

namespace Database\Factories;

use App\Models\ManagedPostgresDatabase;
use App\Models\ManagedPostgresDatabaseUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagedPostgresDatabaseUser>
 */
class ManagedPostgresDatabaseUserFactory extends Factory
{
    protected $model = ManagedPostgresDatabaseUser::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'managed_pg_database_id' => ManagedPostgresDatabase::factory(),
            'pg_user' => $this->faker->unique()->userName().'_pguser',
            'pg_password_encrypted' => $this->faker->password(12, 20),
            'created_by' => User::factory(),
        ];
    }
}
