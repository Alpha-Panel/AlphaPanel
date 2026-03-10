<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $config = config('panel-permissions');

        // Collect all permission names from config groups
        $allPermissions = collect($config)
            ->filter(fn ($group) => is_array($group) && isset($group['permissions']))
            ->flatMap(fn ($group) => array_keys($group['permissions']))
            ->all();

        // Create permissions (idempotent)
        foreach ($allPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Create default roles and assign permissions
        foreach ($config['default_roles'] ?? [] as $roleName => $roleDef) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $resolved = $this->resolvePermissions($roleDef['permissions'], $allPermissions);
            $role->syncPermissions($resolved);
        }
    }

    /**
     * @param  string|list<string>  $spec
     * @param  list<string>  $allPermissions
     * @return list<string>
     */
    private function resolvePermissions(string|array $spec, array $allPermissions): array
    {
        if ($spec === '*') {
            return $allPermissions;
        }

        if (is_string($spec) && str_ends_with($spec, '.*')) {
            $prefix = substr($spec, 0, -1);

            return array_values(array_filter(
                $allPermissions,
                fn (string $p): bool => str_starts_with($p, $prefix)
            ));
        }

        if (is_array($spec)) {
            $result = [];
            foreach ($spec as $item) {
                if (is_string($item) && str_ends_with($item, '.*')) {
                    $prefix = substr($item, 0, -1);
                    $result = array_merge(
                        $result,
                        array_filter($allPermissions, fn (string $p): bool => str_starts_with($p, $prefix))
                    );
                } else {
                    $result[] = $item;
                }
            }

            return array_values(array_unique($result));
        }

        return [];
    }
}
