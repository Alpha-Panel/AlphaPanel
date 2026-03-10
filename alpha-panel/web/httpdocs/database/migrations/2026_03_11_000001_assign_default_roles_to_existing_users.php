<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure seeder has run first (creates permissions & roles)
        (new \Database\Seeders\RolesAndPermissionsSeeder)->run();

        $adminRole = Role::findByName('Admin', 'web');
        $domainManagerRole = Role::findByName('Domain Manager', 'web');

        \App\Models\User::query()->each(function ($user) use ($adminRole, $domainManagerRole): void {
            if ($user->roles()->count() > 0) {
                return;
            }

            $user->assignRole($user->isAdmin() ? $adminRole : $domainManagerRole);
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)
            ->delete();
    }
};
