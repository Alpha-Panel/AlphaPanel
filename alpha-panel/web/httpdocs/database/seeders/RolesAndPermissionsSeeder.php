<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /** @var list<string> */
    private const PANEL_PERMISSIONS = [
        'panel.users.view',
        'panel.users.manage',
        'panel.terminal.access',
        'panel.audit-logs.view',
        'panel.terminal-logs.view',
        'panel.crowdsec.view',
        'panel.crowdsec.manage',
        'panel.waf-rules.view',
        'panel.waf-rules.manage',
        'panel.backups.view',
        'panel.backups.manage',
        'panel.phpmyadmin.access',
        'panel.docker.actions',
        'panel.domains.create',
        'panel.domains.delete',
    ];

    /** @var list<string> */
    private const DOMAIN_PERMISSIONS = [
        'domain.view',
        'domain.edit',
        'domain.provision',
        'domain.ftp.manage',
        'domain.ssl.manage',
        'domain.dns.view',
        'domain.dns.manage',
        'domain.cloudflare.view',
        'domain.cloudflare.manage',
        'domain.databases.view',
        'domain.databases.manage',
        'domain.php.manage',
        'domain.supervisor.view',
        'domain.supervisor.manage',
        'domain.supervisor.artisan',
        'domain.cron-jobs.view',
        'domain.cron-jobs.manage',
        'domain.packages.view',
        'domain.packages.manage',
        'domain.files.view',
        'domain.files.manage',
        'domain.logs.view',
        'domain.modsecurity.view',
        'domain.modsecurity.manage',
    ];

    /** @var list<string> */
    private const VIEWER_PERMISSIONS = [
        'domain.view',
        'domain.dns.view',
        'domain.cloudflare.view',
        'domain.databases.view',
        'domain.supervisor.view',
        'domain.cron-jobs.view',
        'domain.packages.view',
        'domain.files.view',
        'domain.logs.view',
        'domain.modsecurity.view',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $allPermissions = array_merge(self::PANEL_PERMISSIONS, self::DOMAIN_PERMISSIONS);

        foreach ($allPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $adminRole = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions($allPermissions);

        $domainManagerRole = Role::firstOrCreate(['name' => 'Domain Manager', 'guard_name' => 'web']);
        $domainManagerRole->syncPermissions(self::DOMAIN_PERMISSIONS);

        $domainViewerRole = Role::firstOrCreate(['name' => 'Domain Viewer', 'guard_name' => 'web']);
        $domainViewerRole->syncPermissions(self::VIEWER_PERMISSIONS);
    }
}
