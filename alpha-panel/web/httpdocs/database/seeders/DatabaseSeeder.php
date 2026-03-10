<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PhpVersionSeeder::class,
            RolesAndPermissionsSeeder::class,
        ]);

        // Create default admin user from env
        $email = config('panel.admin_email', 'admin@example.com');

        if (! User::where('email', $email)->exists()) {
            User::create([
                'name' => config('panel.admin_name', 'Admin User'),
                'username' => config('panel.admin_username', 'admin'),
                'email' => $email,
                'password' => config('panel.admin_password', 'change-me-now'),
                'admin' => true,
            ]);
        }
    }
}
