<?php

namespace Database\Seeders;

use App\Models\PhpVersion;
use Illuminate\Database\Seeder;

class PhpVersionSeeder extends Seeder
{
    public function run(): void
    {
        $versions = [
            ['slug' => '8.0', 'fpm_pool_dir' => '/etc/php/8.0/fpm/pool.d', 'fpm_service_name' => 'php8.0-fpm', 'sort_order' => 80],
            ['slug' => '8.1', 'fpm_pool_dir' => '/etc/php/8.1/fpm/pool.d', 'fpm_service_name' => 'php8.1-fpm', 'sort_order' => 81],
            ['slug' => '8.2', 'fpm_pool_dir' => '/etc/php/8.2/fpm/pool.d', 'fpm_service_name' => 'php8.2-fpm', 'sort_order' => 82],
            ['slug' => '8.3', 'fpm_pool_dir' => '/etc/php/8.3/fpm/pool.d', 'fpm_service_name' => 'php8.3-fpm', 'sort_order' => 83],
            ['slug' => '8.4', 'fpm_pool_dir' => '/etc/php/8.4/fpm/pool.d', 'fpm_service_name' => 'php8.4-fpm', 'sort_order' => 84],
            ['slug' => '8.5', 'fpm_pool_dir' => '/etc/php/8.5/fpm/pool.d', 'fpm_service_name' => 'php8.5-fpm', 'sort_order' => 85],
        ];

        foreach ($versions as $version) {
            PhpVersion::updateOrCreate(
                ['slug' => $version['slug']],
                array_merge($version, ['is_enabled' => true]),
            );
        }
    }
}
