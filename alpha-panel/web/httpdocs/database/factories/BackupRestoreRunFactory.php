<?php

namespace Database\Factories;

use App\Models\BackupRestoreRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupRestoreRun>
 */
class BackupRestoreRunFactory extends Factory
{
    protected $model = BackupRestoreRun::class;

    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-7 days', 'now');
        $status = fake()->randomElement(['completed', 'failed']);

        return [
            'restore_type' => fake()->randomElement(['website', 'database']),
            'source_mode' => fake()->randomElement(['full', 'incremental']),
            'status' => $status,
            'target' => fake()->domainName(),
            'source_drive_folder_id' => fake()->uuid(),
            'source_drive_file_id' => $status === 'completed' ? fake()->uuid() : null,
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
            'error_message' => 'Restore failed: file not found',
        ]);
    }

    public function downloading(): static
    {
        return $this->state(fn (): array => [
            'status' => 'downloading',
            'progress_percent' => fake()->numberBetween(10, 50),
            'finished_at' => null,
        ]);
    }

    public function restoring(): static
    {
        return $this->state(fn (): array => [
            'status' => 'restoring',
            'progress_percent' => fake()->numberBetween(50, 90),
            'finished_at' => null,
        ]);
    }
}
