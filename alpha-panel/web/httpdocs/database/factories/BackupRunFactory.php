<?php

namespace Database\Factories;

use App\Models\BackupRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupRun>
 */
class BackupRunFactory extends Factory
{
    protected $model = BackupRun::class;

    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-7 days', 'now');
        $status = fake()->randomElement(['completed', 'failed']);

        return [
            'type' => fake()->randomElement(['web', 'mysql', 'manual']),
            'status' => $status,
            'file_name' => fake()->domainWord().'.tar.gz',
            'file_size_bytes' => fake()->numberBetween(1024 * 1024, 500 * 1024 * 1024),
            'drive_file_id' => $status === 'completed' ? fake()->uuid() : null,
            'error_message' => $status === 'failed' ? fake()->sentence() : null,
            'progress_percent' => $status === 'completed' ? 100 : fake()->numberBetween(0, 80),
            'started_at' => $startedAt,
            'finished_at' => fake()->dateTimeBetween($startedAt, 'now'),
            'triggered_by' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'completed',
            'progress_percent' => 100,
            'error_message' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'failed',
            'error_message' => 'Upload failed: quota exceeded',
        ]);
    }

    public function uploading(): static
    {
        return $this->state(fn (): array => [
            'status' => 'uploading',
            'progress_percent' => fake()->numberBetween(10, 90),
            'finished_at' => null,
        ]);
    }
}
