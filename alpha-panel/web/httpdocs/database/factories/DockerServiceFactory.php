<?php

namespace Database\Factories;

use App\Enums\DockerServiceStatus;
use App\Enums\RestartPolicy;
use App\Models\DockerService;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DockerService>
 */
class DockerServiceFactory extends Factory
{
    protected $model = DockerService::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'ext-'.fake()->unique()->slug(2),
            'display_name' => fake()->words(2, true),
            'image' => fake()->randomElement(['nginx', 'redis', 'postgres', 'mysql', 'mongo', 'node']),
            'tag' => 'latest',
            'status' => DockerServiceStatus::Pending,
            'restart_policy' => RestartPolicy::UnlessStopped,
            'environment_variables' => [],
            'volumes' => [],
            'ports' => [],
            'resource_limits' => null,
            'networks' => [],
            'created_by' => User::factory(),
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => DockerServiceStatus::Running,
            'container_id' => fake()->sha256(),
        ]);
    }

    public function stopped(): static
    {
        return $this->state(fn (): array => [
            'status' => DockerServiceStatus::Stopped,
        ]);
    }
}
