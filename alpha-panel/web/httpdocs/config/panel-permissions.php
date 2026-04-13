<?php

/*
|--------------------------------------------------------------------------
| Permission Registry — Single Source of Truth
|--------------------------------------------------------------------------
|
| Every permission the application uses is defined here.
| The `panel:sync-permissions` artisan command reads this file
| and creates / updates the permissions table accordingly.
|
| Workflow:
|   1. Add a new permission entry below.
|   2. Run:  php artisan panel:sync-permissions
|   3. The new permission appears in the Role Management UI.
|
| Structure per group:
|   'group.key' => [
|       'label'       => 'Human-readable group name (translation key)',
|       'permissions'  => [
|           'permission.name' => 'Short description (translation key)',
|       ],
|   ],
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Panel Permissions — Global admin features
    |--------------------------------------------------------------------------
    */
    'panel' => [
        'label' => 'Panel Permissions',
        'permissions' => [
            'panel.users.view' => 'View user list',
            'panel.users.manage' => 'Create, edit and delete users',
            'panel.terminal.access' => 'Access web terminal',
            'panel.audit-logs.view' => 'View audit logs',
            'panel.terminal-logs.view' => 'View terminal session logs',
            'panel.crowdsec.view' => 'View CrowdSec decisions',
            'panel.crowdsec.manage' => 'Manage CrowdSec decisions',
            'panel.waf-rules.view' => 'View WAF rules',
            'panel.waf-rules.manage' => 'Create, edit and delete WAF rules',
            'panel.backups.view' => 'View backups',
            'panel.backups.manage' => 'Create and restore backups',
            'panel.phpmyadmin.access' => 'Access phpMyAdmin SSO',
            'panel.docker.actions' => 'Execute Docker container actions',
            'panel.domains.create' => 'Create new domains',
            'panel.domains.delete' => 'Delete domains',
            'panel.ftp-bans.view' => 'View FTP IP bans',
            'panel.ftp-bans.manage' => 'Manage FTP IP bans (ban/unban)',
            'panel.firewall.view' => 'View firewall rules',
            'panel.firewall.manage' => 'Manage firewall rules',
            'panel.system.updates' => 'View and manage system updates',
            'panel.docker-services.view' => 'View Docker services',
            'panel.docker-services.manage' => 'Create, edit and delete Docker services',
            'panel.php-versions.view' => 'View PHP versions',
            'panel.php-versions.manage' => 'Enable and disable PHP versions',
            'panel.security-settings.manage' => 'Manage login security settings (IP filter, captcha, honeypot)',
            'panel.alert-settings.manage' => 'Manage system alert threshold settings',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain Permissions — Per-domain feature access
    |--------------------------------------------------------------------------
    */
    'domain' => [
        'label' => 'Domain Permissions',
        'permissions' => [
            'domain.view' => 'View domain details',
            'domain.edit' => 'Edit domain settings',
            'domain.provision' => 'Provision / re-provision domain',
            'domain.ftp.manage' => 'Manage FTP accounts and fix permissions',
            'domain.ssl.manage' => 'Activate and manage SSL certificates',
            'domain.dns.view' => 'View DNS records',
            'domain.dns.manage' => 'Create, edit and delete DNS records',
            'domain.cloudflare.view' => 'View Cloudflare settings',
            'domain.cloudflare.manage' => 'Manage Cloudflare settings and cache',
            'domain.databases.view' => 'View databases',
            'domain.databases.manage' => 'Create, edit and delete databases',
            'domain.php.manage' => 'Manage PHP version and settings',
            'domain.supervisor.view' => 'View Supervisor processes',
            'domain.supervisor.manage' => 'Manage Supervisor processes',
            'domain.supervisor.artisan' => 'Run Artisan commands',
            'domain.cron-jobs.view' => 'View cron jobs',
            'domain.cron-jobs.manage' => 'Create, edit and delete cron jobs',
            'domain.packages.view' => 'View package manager',
            'domain.packages.manage' => 'Install and manage packages',
            'domain.files.view' => 'Browse and download files',
            'domain.files.manage' => 'Upload, edit and delete files',
            'domain.logs.view' => 'View domain logs',
            'domain.modsecurity.view' => 'View ModSecurity settings',
            'domain.modsecurity.manage' => 'Manage ModSecurity rules',
            'domain.terminal.access' => 'Open terminal for domain',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Roles
    |--------------------------------------------------------------------------
    |
    | Seeder and sync command use these to bootstrap / update default roles.
    | 'permissions' accepts:
    |   - '*'           → all permissions
    |   - 'domain.*'    → all domain.* permissions
    |   - Explicit list → specific permission names
    |
    */
    'default_roles' => [
        'Admin' => [
            'permissions' => '*',
        ],
        'Domain Manager' => [
            'permissions' => 'domain.*',
        ],
        'Domain Viewer' => [
            'permissions' => [
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
            ],
        ],
    ],

];
