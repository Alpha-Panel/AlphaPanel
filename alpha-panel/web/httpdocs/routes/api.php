<?php

use App\Http\Controllers\Api\V1\ApiTokenController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\CloudflareController;
use App\Http\Controllers\Api\V1\ContainerController;
use App\Http\Controllers\Api\V1\CronJobController;
use App\Http\Controllers\Api\V1\CrowdSecController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DatabaseController;
use App\Http\Controllers\Api\V1\DnsController;
use App\Http\Controllers\Api\V1\DockerBindingController;
use App\Http\Controllers\Api\V1\DockerHubController;
use App\Http\Controllers\Api\V1\DockerServiceController;
use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\DomainIpAccessController;
use App\Http\Controllers\Api\V1\DomainLogController;
use App\Http\Controllers\Api\V1\DomainUserController;
use App\Http\Controllers\Api\V1\FileManagerController;
use App\Http\Controllers\Api\V1\FirewallController;
use App\Http\Controllers\Api\V1\FtpBanController;
use App\Http\Controllers\Api\V1\HandshakeController;
use App\Http\Controllers\Api\V1\ImpersonationController;
use App\Http\Controllers\Api\V1\ModSecurityController;
use App\Http\Controllers\Api\V1\PackageManagerController;
use App\Http\Controllers\Api\V1\PhpSettingsController;
use App\Http\Controllers\Api\V1\PhpVersionController;
use App\Http\Controllers\Api\V1\PingController;
use App\Http\Controllers\Api\V1\PmaSsoApiController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SslController;
use App\Http\Controllers\Api\V1\SupervisorController;
use App\Http\Controllers\Api\V1\SystemUpdateController;
use App\Http\Controllers\Api\V1\TerminalController;
use App\Http\Controllers\Api\V1\TerminalLogController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WafRuleController;
use Illuminate\Support\Facades\Route;

// ── Auth (no token required) ─────────────────────────────────────────────
Route::prefix('v1/auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

Route::prefix('v1')->middleware(['auth:sanctum', 'api.token.ip'])->group(function (): void {

    // ── Handshake ──────────────────────────────────────────────────────────
    Route::post('/handshake/webhook', [HandshakeController::class, 'registerWebhook']);

    // ── PMA SSO ────────────────────────────────────────────────────────────
    Route::get('/pma/sso/admin', [PmaSsoApiController::class, 'admin'])->middleware('ability:*');
    Route::get('/pma/domains/{domain}/databases/{database}/pma-sso', [PmaSsoApiController::class, 'database'])->middleware('ability:databases:read');

    // ── Ping ────────────────────────────────────────────────────────────────
    Route::get('/ping', PingController::class);

    // ── Dashboard ───────────────────────────────────────────────────────────
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('abilities:*');
    Route::post('/dashboard/docker-action', [DashboardController::class, 'dockerAction'])->middleware('ability:docker:write');

    // ── Domains ─────────────────────────────────────────────────────────────
    Route::prefix('domains')->group(function (): void {
        Route::get('/', [DomainController::class, 'index'])->middleware('ability:domains:read');
        Route::post('/', [DomainController::class, 'store'])->middleware('ability:domains:write');
        Route::get('/search', [DomainController::class, 'search'])->middleware('ability:domains:read');
        Route::get('/under-attack-statuses', [DomainController::class, 'underAttackStatuses'])->middleware('ability:domains:read');

        Route::prefix('{domain}')->group(function (): void {
            Route::get('/', [DomainController::class, 'show'])->middleware('ability:domains:read');
            Route::put('/', [DomainController::class, 'update'])->middleware('ability:domains:write');
            Route::delete('/', [DomainController::class, 'destroy'])->middleware('ability:domains:write');
            Route::post('/provision', [DomainController::class, 'provision'])->middleware('ability:domains:write');
            Route::put('/ftp', [DomainController::class, 'updateFtp'])->middleware('ability:domains:write');
            Route::post('/ftp/fix-permissions', [DomainController::class, 'fixFtpPermissions'])->middleware('ability:domains:write');

            // SSL
            Route::prefix('ssl')->group(function (): void {
                Route::get('/', [SslController::class, 'index'])->middleware('ability:domains:read');
                Route::post('/letsencrypt', [SslController::class, 'requestLetsEncrypt'])->middleware('ability:domains:write');
                Route::post('/self-signed', [SslController::class, 'generateSelfSigned'])->middleware('ability:domains:write');
                Route::post('/csr', [SslController::class, 'generateCsr'])->middleware('ability:domains:write');
                Route::post('/validate-key', [SslController::class, 'validateKey'])->middleware('ability:domains:read');
                Route::post('/upload', [SslController::class, 'upload'])->middleware('ability:domains:write');
                Route::post('/import', [SslController::class, 'import'])->middleware('ability:domains:write');
                Route::post('/cancel', [SslController::class, 'cancel'])->middleware('ability:domains:write');
                Route::prefix('{cert}')->group(function (): void {
                    Route::get('/csr/download', [SslController::class, 'downloadCsr'])->middleware('ability:domains:read');
                    Route::post('/activate', [SslController::class, 'activate'])->middleware('ability:domains:write');
                    Route::post('/complete-csr', [SslController::class, 'completeCsr'])->middleware('ability:domains:write');
                    Route::get('/show', [SslController::class, 'show'])->middleware('ability:domains:read');
                    Route::get('/export', [SslController::class, 'export'])->middleware('ability:domains:read');
                    Route::delete('/', [SslController::class, 'destroy'])->middleware('ability:domains:write');
                });
            });

            // DNS
            Route::prefix('dns')->group(function (): void {
                Route::get('/', [DnsController::class, 'index'])->middleware('ability:dns:read');
                Route::post('/', [DnsController::class, 'store'])->middleware('ability:dns:write');
                Route::delete('/', [DnsController::class, 'destroy'])->middleware('ability:dns:write');
                Route::post('/switch-provider', [DnsController::class, 'switchProvider'])->middleware('ability:dns:write');
                Route::post('/bulk-destroy', [DnsController::class, 'bulkDestroy'])->middleware('ability:dns:write');
            });

            // Cloudflare
            Route::prefix('cloudflare')->group(function (): void {
                Route::get('/', [CloudflareController::class, 'index'])->middleware('ability:dns:read');
                Route::get('/summary', [CloudflareController::class, 'summary'])->middleware('ability:dns:read');
                Route::get('/settings', [CloudflareController::class, 'settings'])->middleware('ability:dns:read');
                Route::get('/dnssec-status', [CloudflareController::class, 'dnssecStatus'])->middleware('ability:dns:read');
                Route::get('/firewall-rules', [CloudflareController::class, 'firewallRules'])->middleware('ability:security:read');
                Route::post('/sync', [CloudflareController::class, 'sync'])->middleware('ability:dns:write');
                Route::post('/add', [CloudflareController::class, 'add'])->middleware('ability:dns:write');
                Route::post('/purge-cache', [CloudflareController::class, 'purgeCache'])->middleware('ability:dns:write');
                Route::post('/setting', [CloudflareController::class, 'updateSetting'])->middleware('ability:dns:write');
                Route::post('/dnssec', [CloudflareController::class, 'toggleDnssec'])->middleware('ability:dns:write');
                Route::post('/firewall-rules', [CloudflareController::class, 'storeFirewallRule'])->middleware('ability:security:write');
                Route::delete('/firewall-rules/{ruleId}', [CloudflareController::class, 'destroyFirewallRule'])->middleware('ability:security:write');
            });

            // Databases
            Route::prefix('databases')->group(function (): void {
                Route::get('/', [DatabaseController::class, 'index'])->middleware('ability:databases:read');
                Route::post('/', [DatabaseController::class, 'store'])->middleware('ability:databases:write');
                Route::delete('/{database}', [DatabaseController::class, 'destroy'])->middleware('ability:databases:write');
                Route::post('/{database}/users', [DatabaseController::class, 'storeUser'])->middleware('ability:databases:write');
                Route::put('/users/{user}/password', [DatabaseController::class, 'updateUserPassword'])->middleware('ability:databases:write');
                Route::delete('/users/{user}', [DatabaseController::class, 'destroyUser'])->middleware('ability:databases:write');
            });

            // File Manager
            Route::prefix('files')->group(function (): void {
                Route::get('/', [FileManagerController::class, 'index'])->middleware('ability:files:read');
                Route::get('/list', [FileManagerController::class, 'list'])->middleware('ability:files:read');
                Route::get('/read', [FileManagerController::class, 'read'])->middleware('ability:files:read');
                Route::post('/write', [FileManagerController::class, 'write'])->middleware('ability:files:write');
                Route::post('/create-file', [FileManagerController::class, 'createFile'])->middleware('ability:files:write');
                Route::post('/create-directory', [FileManagerController::class, 'createDirectory'])->middleware('ability:files:write');
                Route::post('/upload', [FileManagerController::class, 'upload'])->middleware('ability:files:write');
                Route::post('/delete', [FileManagerController::class, 'delete'])->middleware('ability:files:write');
                Route::post('/rename', [FileManagerController::class, 'rename'])->middleware('ability:files:write');
                Route::post('/chmod', [FileManagerController::class, 'chmod'])->middleware('ability:files:write');
                Route::post('/compress', [FileManagerController::class, 'compress'])->middleware('ability:files:write');
                Route::post('/decompress', [FileManagerController::class, 'decompress'])->middleware('ability:files:write');
                Route::get('/download', [FileManagerController::class, 'download'])->middleware('ability:files:read');
            });

            // PHP Settings
            Route::get('/php', [PhpSettingsController::class, 'show'])->middleware('ability:system:read');
            Route::put('/php', [PhpSettingsController::class, 'update'])->middleware('ability:system:write');

            // Supervisor
            Route::prefix('supervisor')->group(function (): void {
                Route::get('/', [SupervisorController::class, 'index'])->middleware('ability:domains:read');
                Route::post('/', [SupervisorController::class, 'store'])->middleware('ability:domains:write');
                Route::post('/restart', [SupervisorController::class, 'restart'])->middleware('ability:domains:write');
                Route::post('/workers/restart', [SupervisorController::class, 'restartWorkers'])->middleware('ability:domains:write');
                Route::post('/optimize', [SupervisorController::class, 'optimize'])->middleware('ability:domains:write');
                Route::post('/artisan', [SupervisorController::class, 'artisan'])->middleware('ability:domains:write');
            });

            // Cron Jobs
            Route::prefix('cron-jobs')->group(function (): void {
                Route::get('/', [CronJobController::class, 'index'])->middleware('ability:domains:read');
                Route::post('/', [CronJobController::class, 'store'])->middleware('ability:domains:write');
                Route::put('/{job}', [CronJobController::class, 'update'])->middleware('ability:domains:write');
                Route::delete('/{job}', [CronJobController::class, 'destroy'])->middleware('ability:domains:write');
                Route::post('/{job}/toggle', [CronJobController::class, 'toggle'])->middleware('ability:domains:write');
                Route::get('/{job}/logs', [CronJobController::class, 'logs'])->middleware('ability:domains:read');
            });

            // Package Manager
            Route::prefix('package-manager')->group(function (): void {
                Route::get('/', [PackageManagerController::class, 'index'])->middleware('ability:domains:read');
                Route::get('/npm/packages', [PackageManagerController::class, 'npmPackages'])->middleware('ability:domains:read');
                Route::post('/npm/install', [PackageManagerController::class, 'npmInstall'])->middleware('ability:domains:write');
                Route::post('/npm/build', [PackageManagerController::class, 'npmBuild'])->middleware('ability:domains:write');
                Route::post('/npm/audit-fix', [PackageManagerController::class, 'npmAuditFix'])->middleware('ability:domains:write');
                Route::get('/composer/packages', [PackageManagerController::class, 'composerPackages'])->middleware('ability:domains:read');
                Route::post('/composer/install', [PackageManagerController::class, 'composerInstall'])->middleware('ability:domains:write');
                Route::post('/composer/update', [PackageManagerController::class, 'composerUpdate'])->middleware('ability:domains:write');
                Route::post('/composer/dump-autoload', [PackageManagerController::class, 'composerDumpAutoload'])->middleware('ability:domains:write');
            });

            // ModSecurity
            Route::prefix('modsecurity')->group(function (): void {
                Route::get('/', [ModSecurityController::class, 'show'])->middleware('ability:security:read');
                Route::put('/', [ModSecurityController::class, 'update'])->middleware('ability:security:write');
                Route::get('/logs', [ModSecurityController::class, 'logs'])->middleware('ability:security:read');
            });

            // IP Access
            Route::prefix('ip-access')->group(function (): void {
                Route::get('/', [DomainIpAccessController::class, 'index'])->middleware('ability:security:read');
                Route::put('/mode', [DomainIpAccessController::class, 'updateMode'])->middleware('ability:security:write');
                Route::post('/', [DomainIpAccessController::class, 'store'])->middleware('ability:security:write');
                Route::delete('/{rule}', [DomainIpAccessController::class, 'destroy'])->middleware('ability:security:write');
            });

            // Domain Logs
            Route::prefix('logs')->group(function (): void {
                Route::get('/', [DomainLogController::class, 'index'])->middleware('ability:domains:read');
                Route::get('/entries', [DomainLogController::class, 'entries'])->middleware('ability:domains:read');
                Route::post('/stream', [DomainLogController::class, 'stream'])->middleware('ability:domains:read');
            });

            // Domain Users
            Route::prefix('users')->group(function (): void {
                Route::get('/', [DomainUserController::class, 'index'])->middleware('ability:users:read');
                Route::post('/', [DomainUserController::class, 'store'])->middleware('ability:users:write');
                Route::delete('/{user}', [DomainUserController::class, 'destroy'])->middleware('ability:users:write');
            });

            // Domain Docker Bindings
            Route::prefix('docker-services')->group(function (): void {
                Route::get('/', [DockerBindingController::class, 'index'])->middleware('ability:docker:read');
                Route::post('/', [DockerBindingController::class, 'store'])->middleware('ability:docker:write');
                Route::delete('/{binding}', [DockerBindingController::class, 'destroy'])->middleware('ability:docker:write');
            });

            // Domain Terminal
            Route::post('/terminal/start', [TerminalController::class, 'startDomain'])->middleware('ability:terminal:access');
        });
    });

    // ── Containers (live, via Portainer) ────────────────────────────────────
    Route::prefix('containers')->group(function (): void {
        Route::get('/', [ContainerController::class, 'index'])->middleware('ability:docker:read');
        Route::get('/{id}', [ContainerController::class, 'show'])->middleware('ability:docker:read');
    });

    // ── Docker Services ──────────────────────────────────────────────────────
    Route::prefix('docker-services')->group(function (): void {
        Route::get('/', [DockerServiceController::class, 'index'])->middleware('ability:docker:read');
        Route::post('/', [DockerServiceController::class, 'store'])->middleware('ability:docker:write');
        Route::get('/{service}', [DockerServiceController::class, 'show'])->middleware('ability:docker:read');
        Route::put('/{service}', [DockerServiceController::class, 'update'])->middleware('ability:docker:write');
        Route::delete('/{service}', [DockerServiceController::class, 'destroy'])->middleware('ability:docker:write');
        Route::post('/{service}/action', [DockerServiceController::class, 'action'])->middleware('ability:docker:write');
        Route::post('/{service}/sync-status', [DockerServiceController::class, 'syncStatus'])->middleware('ability:docker:write');
        Route::get('/{service}/logs', [DockerServiceController::class, 'logs'])->middleware('ability:docker:read');
        Route::get('/{service}/stats', [DockerServiceController::class, 'stats'])->middleware('ability:docker:read');
    });

    // ── Docker Hub ───────────────────────────────────────────────────────────
    Route::prefix('docker-hub')->group(function (): void {
        Route::get('/search', [DockerHubController::class, 'search'])->middleware('ability:docker:read');
        Route::get('/popular', [DockerHubController::class, 'popular'])->middleware('ability:docker:read');
        Route::get('/tags', [DockerHubController::class, 'tags'])->middleware('ability:docker:read');
        Route::get('/image-config', [DockerHubController::class, 'imageConfig'])->middleware('ability:docker:read');
    });

    // ── Terminal ─────────────────────────────────────────────────────────────
    Route::prefix('terminal')->group(function (): void {
        Route::post('/start', [TerminalController::class, 'start'])->middleware('ability:terminal:access');
        Route::post('/start-ssh', [TerminalController::class, 'startSsh'])->middleware('ability:terminal:access');
        Route::post('/start-domain', [TerminalController::class, 'startDomain'])->middleware('ability:terminal:access');
        Route::post('/stop', [TerminalController::class, 'stop'])->middleware('ability:terminal:access');
    });

    // ── Security — Firewall ───────────────────────────────────────────────────
    Route::prefix('security/firewall')->group(function (): void {
        Route::get('/', [FirewallController::class, 'index'])->middleware('ability:security:read');
        Route::get('/data', [FirewallController::class, 'data'])->middleware('ability:security:read');
        Route::get('/preview', [FirewallController::class, 'preview'])->middleware('ability:security:read');
        Route::post('/', [FirewallController::class, 'store'])->middleware('ability:security:write');
        Route::put('/{rule}', [FirewallController::class, 'update'])->middleware('ability:security:write');
        Route::put('/{rule}/toggle', [FirewallController::class, 'toggle'])->middleware('ability:security:write');
        Route::delete('/', [FirewallController::class, 'destroyAll'])->middleware('ability:security:write');
        Route::put('/policy', [FirewallController::class, 'updatePolicy'])->middleware('ability:security:write');
        Route::put('/reorder', [FirewallController::class, 'reorder'])->middleware('ability:security:write');
    });

    // ── Security — FTP Bans ───────────────────────────────────────────────────
    Route::prefix('security/ftp-bans')->group(function (): void {
        Route::get('/', [FtpBanController::class, 'index'])->middleware('ability:security:read');
        Route::get('/data', [FtpBanController::class, 'data'])->middleware('ability:security:read');
        Route::get('/log', [FtpBanController::class, 'log'])->middleware('ability:security:read');
        Route::post('/', [FtpBanController::class, 'store'])->middleware('ability:security:write');
        Route::delete('/', [FtpBanController::class, 'destroyAll'])->middleware('ability:security:write');
        Route::post('/whitelist', [FtpBanController::class, 'addWhitelist'])->middleware('ability:security:write');
        Route::delete('/whitelist', [FtpBanController::class, 'removeWhitelist'])->middleware('ability:security:write');
    });

    // ── Security — CrowdSec ───────────────────────────────────────────────────
    Route::prefix('security/crowdsec')->group(function (): void {
        Route::get('/', [CrowdSecController::class, 'index'])->middleware('ability:security:read');
        Route::get('/data', [CrowdSecController::class, 'data'])->middleware('ability:security:read');
        Route::get('/decisions', [CrowdSecController::class, 'decisions'])->middleware('ability:security:read');
    });

    // ── Security — WAF Rules ──────────────────────────────────────────────────
    Route::prefix('security/waf-global-rules')->group(function (): void {
        Route::get('/', [WafRuleController::class, 'index'])->middleware('ability:security:read');
        Route::post('/', [WafRuleController::class, 'store'])->middleware('ability:security:write');
        Route::put('/{rule}', [WafRuleController::class, 'update'])->middleware('ability:security:write');
        Route::delete('/{rule}', [WafRuleController::class, 'destroy'])->middleware('ability:security:write');
    });

    // ── Backups ───────────────────────────────────────────────────────────────
    Route::prefix('backups')->group(function (): void {
        Route::get('/', [BackupController::class, 'index'])->middleware('ability:backups:read');
        Route::get('/history', [BackupController::class, 'history'])->middleware('ability:backups:read');
        Route::get('/folders', [BackupController::class, 'folders'])->middleware('ability:backups:read');
        Route::get('/drive-quota', [BackupController::class, 'driveQuota'])->middleware('ability:backups:read');
        Route::get('/drive-files', [BackupController::class, 'driveFiles'])->middleware('ability:backups:read');
        Route::get('/drive-download/{fileId}', [BackupController::class, 'driveDownload'])->middleware('ability:backups:read');
        Route::post('/run', [BackupController::class, 'run'])->middleware('ability:backups:write');
        Route::post('/{run}/cancel', [BackupController::class, 'cancel'])->middleware('ability:backups:write');
        Route::post('/restart', [BackupController::class, 'restart'])->middleware('ability:backups:write');
        Route::post('/settings', [BackupController::class, 'updateSettings'])->middleware('ability:backups:write');
        Route::post('/folder', [BackupController::class, 'setFolder'])->middleware('ability:backups:write');
        Route::post('/create-folder', [BackupController::class, 'createFolder'])->middleware('ability:backups:write');
        Route::post('/disconnect', [BackupController::class, 'disconnect'])->middleware('ability:backups:write');
    });

    // ── PHP Versions ──────────────────────────────────────────────────────────
    Route::prefix('php-versions')->group(function (): void {
        Route::get('/', [PhpVersionController::class, 'index'])->middleware('ability:system:read');
        Route::post('/{version}/toggle', [PhpVersionController::class, 'toggle'])->middleware('ability:system:write');
        Route::get('/frankenphp/php-ini', [PhpVersionController::class, 'frankenphpIni'])->middleware('ability:system:read');
        Route::put('/frankenphp/php-ini', [PhpVersionController::class, 'updateFrankenphpIni'])->middleware('ability:system:write');
        Route::get('/{version}/php-ini', [PhpVersionController::class, 'phpIni'])->middleware('ability:system:read');
        Route::put('/{version}/php-ini', [PhpVersionController::class, 'updatePhpIni'])->middleware('ability:system:write');
    });

    // ── System Updates ────────────────────────────────────────────────────────
    Route::prefix('system/updates')->group(function (): void {
        Route::get('/', [SystemUpdateController::class, 'index'])->middleware('ability:system:read');
        Route::post('/check', [SystemUpdateController::class, 'check'])->middleware('ability:system:write');
        Route::post('/panel', [SystemUpdateController::class, 'updatePanel'])->middleware('ability:system:write');
        Route::post('/mysql/prepare', [SystemUpdateController::class, 'mysqlPrepare'])->middleware('ability:system:write');
        Route::post('/mysql/apply', [SystemUpdateController::class, 'mysqlApply'])->middleware('ability:system:write');
        Route::post('/mysql/rollback', [SystemUpdateController::class, 'mysqlRollback'])->middleware('ability:system:write');
        Route::post('/mysql/cleanup', [SystemUpdateController::class, 'mysqlCleanup'])->middleware('ability:system:write');
        Route::get('/task/{taskId}', [SystemUpdateController::class, 'taskStatus'])->middleware('ability:system:read');
    });

    // ── Users & Roles ─────────────────────────────────────────────────────────
    Route::prefix('users')->group(function (): void {
        Route::get('/', [UserController::class, 'index'])->middleware('ability:users:read');
        Route::post('/', [UserController::class, 'store'])->middleware('ability:users:write');
        Route::put('/{user}', [UserController::class, 'update'])->middleware('ability:users:write');
        Route::delete('/{user}', [UserController::class, 'destroy'])->middleware('ability:users:write');
    });

    Route::prefix('roles')->group(function (): void {
        Route::get('/', [RoleController::class, 'index'])->middleware('ability:users:read');
        Route::post('/', [RoleController::class, 'store'])->middleware('ability:users:write');
        Route::put('/{role}', [RoleController::class, 'update'])->middleware('ability:users:write');
        Route::delete('/{role}', [RoleController::class, 'destroy'])->middleware('ability:users:write');
    });

    // ── Impersonation ─────────────────────────────────────────────────────────
    Route::post('/impersonate/{user}', [ImpersonationController::class, 'start'])->middleware('ability:users:write');
    Route::post('/impersonate/stop', [ImpersonationController::class, 'stop'])->middleware('ability:users:write');

    // ── Audit & Terminal Logs ─────────────────────────────────────────────────
    Route::prefix('audit-logs')->group(function (): void {
        Route::get('/', [AuditLogController::class, 'index'])->middleware('ability:system:read');
        Route::get('/options/users', [AuditLogController::class, 'optionUsers'])->middleware('ability:system:read');
        Route::get('/options/actions', [AuditLogController::class, 'optionActions'])->middleware('ability:system:read');
        Route::get('/options/domains', [AuditLogController::class, 'optionDomains'])->middleware('ability:system:read');
    });

    Route::prefix('terminal-logs')->group(function (): void {
        Route::get('/', [TerminalLogController::class, 'index'])->middleware('ability:system:read');
        Route::get('/options/users', [TerminalLogController::class, 'optionUsers'])->middleware('ability:system:read');
        Route::get('/{log}', [TerminalLogController::class, 'show'])->middleware('ability:system:read');
    });

    // ── Settings ──────────────────────────────────────────────────────────────
    Route::prefix('settings')->group(function (): void {
        Route::get('/dns', [SettingsController::class, 'dns'])->middleware('ability:settings:read');
        Route::put('/dns', [SettingsController::class, 'updateDns'])->middleware('ability:settings:write');
        Route::get('/acme', [SettingsController::class, 'acme'])->middleware('ability:settings:read');
        Route::put('/acme', [SettingsController::class, 'updateAcme'])->middleware('ability:settings:write');
        Route::get('/dns-templates', [SettingsController::class, 'dnsTemplates'])->middleware('ability:settings:read');
        Route::post('/dns-templates', [SettingsController::class, 'storeDnsTemplate'])->middleware('ability:settings:write');
        Route::get('/dns-templates/{template}', [SettingsController::class, 'showDnsTemplate'])->middleware('ability:settings:read');
        Route::put('/dns-templates/{template}', [SettingsController::class, 'updateDnsTemplate'])->middleware('ability:settings:write');
        Route::delete('/dns-templates/{template}', [SettingsController::class, 'destroyDnsTemplate'])->middleware('ability:settings:write');
        Route::post('/dns-templates/{template}/default', [SettingsController::class, 'setDefaultDnsTemplate'])->middleware('ability:settings:write');
        Route::get('/security/anti-bot', [SettingsController::class, 'antiBot'])->middleware('ability:settings:read');
        Route::put('/security/anti-bot', [SettingsController::class, 'updateAntiBot'])->middleware('ability:settings:write');
        Route::get('/security/login-ip-filter', [SettingsController::class, 'loginIpFilter'])->middleware('ability:settings:read');
        Route::put('/security/login-ip-filter/mode', [SettingsController::class, 'updateLoginIpFilterMode'])->middleware('ability:settings:write');
        Route::post('/security/login-ip-filter', [SettingsController::class, 'storeLoginIpRule'])->middleware('ability:settings:write');
        Route::delete('/security/login-ip-filter/{rule}', [SettingsController::class, 'destroyLoginIpRule'])->middleware('ability:settings:write');
        Route::get('/alerts', [SettingsController::class, 'alerts'])->middleware('ability:settings:read');
        Route::put('/alerts', [SettingsController::class, 'updateAlerts'])->middleware('ability:settings:write');
        Route::post('/alerts/run-check', [SettingsController::class, 'runAlertCheck'])->middleware('ability:settings:write');
    });

    // ── API Token Management ──────────────────────────────────────────────────
    Route::prefix('api-tokens')->group(function (): void {
        Route::get('/', [ApiTokenController::class, 'index']);
        Route::post('/', [ApiTokenController::class, 'store']);
        Route::delete('/{tokenId}', [ApiTokenController::class, 'destroy']);
        Route::get('/{tokenId}/ip-rules', [ApiTokenController::class, 'ipRules']);
        Route::post('/{tokenId}/ip-rules', [ApiTokenController::class, 'storeIpRule']);
        Route::delete('/{tokenId}/ip-rules/{rule}', [ApiTokenController::class, 'destroyIpRule']);
    });
});
