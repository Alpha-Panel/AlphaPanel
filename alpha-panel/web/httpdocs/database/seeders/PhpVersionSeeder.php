<?php

namespace Database\Seeders;

use App\Models\PhpVersion;
use Illuminate\Database\Seeder;

class PhpVersionSeeder extends Seeder
{
    public function run(): void
    {
        $versions = [
            ['slug' => '7.0', 'fpm_pool_dir' => '/etc/php/7.0/fpm/pool.d', 'fpm_service_name' => 'php7.0-fpm', 'sort_order' => 70, 'is_enabled' => false],
            ['slug' => '7.1', 'fpm_pool_dir' => '/etc/php/7.1/fpm/pool.d', 'fpm_service_name' => 'php7.1-fpm', 'sort_order' => 71, 'is_enabled' => false],
            ['slug' => '7.2', 'fpm_pool_dir' => '/etc/php/7.2/fpm/pool.d', 'fpm_service_name' => 'php7.2-fpm', 'sort_order' => 72, 'is_enabled' => false],
            ['slug' => '7.3', 'fpm_pool_dir' => '/etc/php/7.3/fpm/pool.d', 'fpm_service_name' => 'php7.3-fpm', 'sort_order' => 73, 'is_enabled' => false],
            ['slug' => '7.4', 'fpm_pool_dir' => '/etc/php/7.4/fpm/pool.d', 'fpm_service_name' => 'php7.4-fpm', 'sort_order' => 74, 'is_enabled' => false],
            ['slug' => '8.0', 'fpm_pool_dir' => '/etc/php/8.0/fpm/pool.d', 'fpm_service_name' => 'php8.0-fpm', 'sort_order' => 80, 'is_enabled' => true],
            ['slug' => '8.1', 'fpm_pool_dir' => '/etc/php/8.1/fpm/pool.d', 'fpm_service_name' => 'php8.1-fpm', 'sort_order' => 81, 'is_enabled' => true],
            ['slug' => '8.2', 'fpm_pool_dir' => '/etc/php/8.2/fpm/pool.d', 'fpm_service_name' => 'php8.2-fpm', 'sort_order' => 82, 'is_enabled' => false],
            ['slug' => '8.3', 'fpm_pool_dir' => '/etc/php/8.3/fpm/pool.d', 'fpm_service_name' => 'php8.3-fpm', 'sort_order' => 83, 'is_enabled' => false],
            ['slug' => '8.4', 'fpm_pool_dir' => '/etc/php/8.4/fpm/pool.d', 'fpm_service_name' => 'php8.4-fpm', 'sort_order' => 84, 'is_enabled' => false],
            ['slug' => '8.5', 'fpm_pool_dir' => '/etc/php/8.5/fpm/pool.d', 'fpm_service_name' => 'php8.5-fpm', 'sort_order' => 85, 'is_enabled' => false],
        ];

        foreach ($versions as $version) {
            PhpVersion::updateOrCreate(
                ['slug' => $version['slug']],
                $version,
            );
        }
    }
}
