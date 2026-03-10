<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SyncPermissions extends Command
{
    protected $signature = 'panel:sync-permissions
                            {--clean : Remove permissions not listed in config}
                            {--scan : Scan routes for unregistered permission middleware}
                            {--dry-run : Show what would change without writing to database}';

    protected $description = 'Synchronize permissions and default roles from config/panel-permissions.php';

    public function handle(): int
    {
        $config = config('panel-permissions');

        if (! $config) {
            $this->error('Config file config/panel-permissions.php not found.');

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('⚡ DRY-RUN mode — no database changes will be made.');
            $this->newLine();
        }

        // Collect all permission names from config
        $configPermissions = $this->collectPermissions($config);
        $this->info("📋 Config defines {$configPermissions->count()} permissions.");

        // Sync permissions
        $created = $this->syncPermissions($configPermissions->keys()->all(), $dryRun);

        // Clean orphaned permissions
        if ($this->option('clean')) {
            $this->cleanOrphaned($configPermissions->keys()->all(), $dryRun);
        }

        // Sync default roles
        $this->syncDefaultRoles($config, $configPermissions->keys()->all(), $dryRun);

        // Scan routes for unregistered permissions
        if ($this->option('scan')) {
            $this->scanRoutes($configPermissions->keys()->all());
        }

        if (! $dryRun) {
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
            $this->newLine();
            $this->info('✅ Permission cache cleared.');
        }

        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * Collect all permissions from config groups.
     *
     * @return \Illuminate\Support\Collection<string, string>
     */
    private function collectPermissions(array $config): \Illuminate\Support\Collection
    {
        return collect($config)
            ->filter(fn ($group) => is_array($group) && isset($group['permissions']))
            ->flatMap(fn ($group) => $group['permissions']);
    }

    /**
     * Create missing permissions.
     *
     * @param  list<string>  $names
     */
    private function syncPermissions(array $names, bool $dryRun): int
    {
        $existing = Permission::where('guard_name', 'web')
            ->pluck('name')
            ->all();

        $missing = array_diff($names, $existing);

        if (count($missing) === 0) {
            $this->info('  ✓ All permissions already exist.');

            return 0;
        }

        $this->newLine();
        $this->info('🆕 Creating '.count($missing).' new permission(s):');

        foreach ($missing as $name) {
            $this->line("   + {$name}");

            if (! $dryRun) {
                Permission::create(['name' => $name, 'guard_name' => 'web']);
            }
        }

        return count($missing);
    }

    /**
     * Remove permissions not present in config.
     *
     * @param  list<string>  $configNames
     */
    private function cleanOrphaned(array $configNames, bool $dryRun): void
    {
        $orphaned = Permission::where('guard_name', 'web')
            ->whereNotIn('name', $configNames)
            ->get();

        if ($orphaned->isEmpty()) {
            $this->info('  ✓ No orphaned permissions found.');

            return;
        }

        $this->newLine();
        $this->warn("🧹 Found {$orphaned->count()} orphaned permission(s):");

        foreach ($orphaned as $perm) {
            $rolesUsing = $perm->roles()->count();
            $suffix = $rolesUsing > 0 ? " (assigned to {$rolesUsing} role(s))" : '';
            $this->line("   - {$perm->name}{$suffix}");
        }

        if (! $dryRun) {
            if ($this->confirm('Remove these orphaned permissions?', false)) {
                foreach ($orphaned as $perm) {
                    $perm->delete();
                }
                $this->info('  Orphaned permissions removed.');
            } else {
                $this->info('  Skipped orphan removal.');
            }
        }
    }

    /**
     * Sync default roles from config.
     *
     * @param  list<string>  $allPermissions
     */
    private function syncDefaultRoles(array $config, array $allPermissions, bool $dryRun): void
    {
        $defaultRoles = $config['default_roles'] ?? [];

        if (empty($defaultRoles)) {
            return;
        }

        $this->newLine();
        $this->info('👥 Syncing '.count($defaultRoles).' default role(s):');

        foreach ($defaultRoles as $roleName => $roleDef) {
            $resolved = $this->resolveRolePermissions($roleDef['permissions'], $allPermissions);

            if (! $dryRun) {
                $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
                $role->syncPermissions($resolved);
            }

            $this->line("   ✓ {$roleName} → {$this->countLabel(count($resolved))}");
        }
    }

    /**
     * Resolve wildcard permission patterns into actual permission names.
     *
     * @param  string|list<string>  $spec
     * @param  list<string>  $allPermissions
     * @return list<string>
     */
    private function resolveRolePermissions(string|array $spec, array $allPermissions): array
    {
        // '*' = everything
        if ($spec === '*') {
            return $allPermissions;
        }

        // 'domain.*' or 'panel.*' — prefix wildcard
        if (is_string($spec) && str_ends_with($spec, '.*')) {
            $prefix = substr($spec, 0, -1); // 'domain.'

            return array_values(array_filter(
                $allPermissions,
                fn (string $p): bool => str_starts_with($p, $prefix)
            ));
        }

        // Explicit list — may contain wildcards too
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

    /**
     * Scan route middleware for permission references not in config.
     *
     * @param  list<string>  $configNames
     */
    private function scanRoutes(array $configNames): void
    {
        $this->newLine();
        $this->info('🔍 Scanning routes for unregistered permissions...');

        $routePermissions = collect(Route::getRoutes()->getRoutes())
            ->flatMap(function ($route) {
                $perms = [];
                foreach ($route->middleware() as $mw) {
                    if (str_starts_with($mw, 'permission:')) {
                        $names = explode('|', substr($mw, 11));
                        $perms = array_merge($perms, $names);
                    }
                }

                return $perms;
            })
            ->unique()
            ->values();

        $unregistered = $routePermissions->diff($configNames);

        if ($unregistered->isEmpty()) {
            $this->info('  ✓ All route permissions are registered in config.');

            return;
        }

        $this->warn("  ⚠ Found {$unregistered->count()} permission(s) used in routes but missing from config:");
        foreach ($unregistered as $perm) {
            $this->line("    ⚠ {$perm}");
        }
        $this->line('  → Add them to config/panel-permissions.php and re-run this command.');
    }

    private function countLabel(int $count): string
    {
        return $count === 1 ? '1 permission' : "{$count} permissions";
    }
}
