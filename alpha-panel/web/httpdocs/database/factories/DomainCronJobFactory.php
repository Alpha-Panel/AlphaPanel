<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\DomainCronJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DomainCronJob>
 */
class DomainCronJobFactory extends Factory
{
    protected $model = DomainCronJob::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $schedules = [
            '* * * * *',
            '*/5 * * * *',
            '*/15 * * * *',
            '0 * * * *',
            '0 0 * * *',
            '0 0 * * 0',
        ];

        $commands = [
            'php artisan schedule:run',
            'php artisan queue:restart',
            'php cleanup.php',
            'curl -s https://example.com/ping',
            'php artisan cache:clear',
        ];

        return [
            'domain_id' => Domain::factory(),
            'command' => fake()->randomElement($commands),
            'schedule' => fake()->randomElement($schedules),
            'description' => fake()->optional(0.7)->sentence(3),
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'enabled' => false,
        ]);
    }
}
