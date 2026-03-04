<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\ManagedDatabase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ManagedDatabase>
 */
class ManagedDatabaseFactory extends Factory
{
    protected $model = ManagedDatabase::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'db_name' => $this->faker->unique()->userName().'_db',
            'created_by' => User::factory(),
        ];
    }
}
