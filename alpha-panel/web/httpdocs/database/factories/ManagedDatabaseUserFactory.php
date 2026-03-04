<?php

namespace Database\Factories;

use App\Models\ManagedDatabase;
use App\Models\ManagedDatabaseUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ManagedDatabaseUser>
 */
class ManagedDatabaseUserFactory extends Factory
{
    protected $model = ManagedDatabaseUser::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'managed_database_id' => ManagedDatabase::factory(),
            'db_user' => $this->faker->unique()->userName().'_user',
            'db_password_encrypted' => $this->faker->password(12, 20),
            'created_by' => User::factory(),
        ];
    }
}
