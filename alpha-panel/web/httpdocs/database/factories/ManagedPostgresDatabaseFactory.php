<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\ManagedPostgresDatabase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagedPostgresDatabase>
 */
class ManagedPostgresDatabaseFactory extends Factory
{
    protected $model = ManagedPostgresDatabase::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'db_name' => $this->faker->unique()->userName().'_pgdb',
            'created_by' => User::factory(),
        ];
    }
}
