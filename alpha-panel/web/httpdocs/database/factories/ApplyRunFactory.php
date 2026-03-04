<?php

namespace Database\Factories;

use App\Models\ApplyRun;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ApplyRun>
 */
class ApplyRunFactory extends Factory
{
    protected $model = ApplyRun::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'status' => $this->faker->randomElement(['pending', 'running', 'completed', 'failed']),
            'progress_percent' => $this->faker->numberBetween(0, 100),
            'message' => $this->faker->sentence(),
            'started_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'finished_at' => $this->faker->optional()->dateTimeBetween('-1 week', 'now'),
            'created_by' => User::factory(),
        ];
    }
}
