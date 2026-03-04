<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => $this->faker->randomElement(['created', 'updated', 'deleted', 'provisioned', 'failed']),
            'domain_id' => Domain::factory(),
            'summary' => $this->faker->sentence(),
            'ip_address' => $this->faker->ipv4(),
            'port' => $this->faker->numberBetween(1024, 65535),
        ];
    }
}
