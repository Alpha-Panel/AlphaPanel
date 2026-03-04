<?php

namespace Database\Factories;

use App\Models\PhpVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PhpVersion>
 */
class PhpVersionFactory extends Factory
{
    protected $model = PhpVersion::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $version = $this->faker->unique()->numberBetween(80, 85);
        $slug = '8.'.($version % 10);
        $fpmVersion = 'php'.$version.'-fpm';

        return [
            'slug' => $slug,
            'fpm_pool_dir' => "/etc/php/{$slug}/fpm/pool.d",
            'fpm_service_name' => $fpmVersion,
            'is_enabled' => $this->faker->boolean(90), // 90% chance to be enabled
            'sort_order' => $version,
        ];
    }
}
