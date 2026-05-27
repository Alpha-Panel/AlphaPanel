<?php

use App\Http\Controllers\AcmeSettingController;
use App\Http\Controllers\AdminPushNotificationController;
use App\Http\Controllers\AlertSettingController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CrowdSecController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\DnsSettingController;
use App\Http\Controllers\DnsTemplateController;
use App\Http\Controllers\DockerHubController;
use App\Http\Controllers\DockerProjectController;
use App\Http\Controllers\DockerProjectDomainBindingController;
use App\Http\Controllers\DockerProjectFileController;
use App\Http\Controllers\DockerServiceController;
use App\Http\Controllers\DockerServiceDomainBindingController;
use App\Http\Controllers\DomainCloudflareController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\DomainCronJobController;
use App\Http\Controllers\DomainCustomConfController;
use App\Http\Controllers\DomainIpRuleController;
use App\Http\Controllers\DomainLogController;
use App\Http\Controllers\DomainModSecurityController;
use App\Http\Controllers\DomainPackageManagerController;
use App\Http\Controllers\DomainProvisionController;
use App\Http\Controllers\DomainSupervisorController;
use App\Http\Controllers\DomainUserController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\FirewallController;
use App\Http\Controllers\FtpBanController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\LoginIpFilterController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\MysqlConfigController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\OAuthController;
use App\Http\Controllers\PanelSslController;
use App\Http\Controllers\PhpSettingsController;
use App\Http\Controllers\PhpVersionController;
use App\Http\Controllers\PmaSsoController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SecuritySettingController;
use App\Http\Controllers\ServerStatsController;
use App\Http\Controllers\Settings\ApiTokenWebController;
use App\Http\Controllers\Settings\WebhookWebController;
use App\Http\Controllers\SslCertificateController;
use App\Http\Controllers\SystemUpdateController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\TerminalLogController;
use App\Http\Controllers\TwoFactorAuthController;
use App\Http\Controllers\UserAccountsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WafGlobalRuleController;
use App\Http\Controllers\WebAuthn\WebAuthnLoginController;
use App\Http\Controllers\WebAuthn\WebAuthnRegisterController;
use App\Http\Controllers\WebAuthnController;
use App\Http\Middleware\CheckLoginIp;
use App\Notifications\DomainNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;

Route::get('/manifest.json', [ManifestController::class, 'index'])->name('manifest');

// OAuth authorization code flow (no auth middleware — guest-facing)
Route::get('/oauth/authorize', [OAuthController::class, 'show'])->name('oauth.authorize');
Route::post('/oauth/authorize', [OAuthController::class, 'submit'])->name('oauth.authorize.submit');
Route::post('/oauth/check-user', [OAuthController::class, 'checkUser'])->name('oauth.check-user');
Route::post('/locale', function (Request $request) {
    $supportedLocales = config('app.supported_locales', ['tr', 'tr-gokturk', 'gokturk-latin', 'az', 'en', 'de', 'es', 'fr', 'ru']);

    $validated = $request->validate([
        'locale' => ['required', 'string', Rule::in($supportedLocales)],
    ]);

    $locale = $validated['locale'];
    $request->session()->put('locale', $locale);
    app()->setLocale($locale);

    $cookie = cookie('locale', $locale, 60 * 24 * 365, '/', null, null, false);

    if ($request->expectsJson()) {
        return response()->json(['locale' => app()->getLocale()])->cookie($cookie);
    }

    return back()->cookie($cookie);
})->name('locale.set');
/*
|--------------------------------------------------------------------------
| Authentication Routes (laravel/ui)
|--------------------------------------------------------------------------
*/
Auth::routes(['register' => false, 'confirm' => false]);
Route::post('login/methods', [LoginController::class, 'methods'])
    ->middleware('guest')
    ->name('login.methods');

/*
|--------------------------------------------------------------------------
| WebAuthn Routes
|--------------------------------------------------------------------------
*/
Route::post('webauthn/login/options', [WebAuthnLoginController::class, 'options'])
    ->middleware(['throttle:webauthn', CheckLoginIp::class])
    ->name('webauthn.login.options');
Route::post('webauthn/login', [WebAuthnLoginController::class, 'login'])
    ->middleware(['throttle:webauthn', CheckLoginIp::class])
    ->name('webauthn.login');

Route::middleware('auth')->group(function (): void {
    Route::post('webauthn/register/options', [WebAuthnRegisterController::class, 'options'])
        ->name('webauthn.register.options');
    Route::post('webauthn/register', [WebAuthnRegisterController::class, 'register'])
        ->name('webauthn.register');
});

/*
|--------------------------------------------------------------------------
| 2FA Verify (must be outside VerifyOTP-protected routes)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function (): void {
    Route::post('2fa-verify', [TwoFactorAuthController::class, 'verify'])->name('two-factor.verify');
    Route::get('otp-challenge', [TwoFactorAuthController::class, 'challenge'])->name('otp.challenge');
});

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function (): void {

    // Dashboard
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::get('/dashboard/data', [HomeController::class, 'data'])->name('dashboard.data');

    // Server stats (sidebar widget, admin-only)
    Route::get('/server-stats', [ServerStatsController::class, 'index'])->name('server-stats.index');

    // Test notification (geçici - test sonrası silinecek)
    Route::get('test-notification', function () {
        auth()->user()->notify(new DomainNotification(
            level: 'success',
            title: __('Realtime Test'),
            body: __('This notification was sent at :time.', ['time' => now()->format('H:i:s')]),
            icon: 'bx bx-check-circle',
        ));

        return response()->json(['status' => 'sent', 'time' => now()->format('H:i:s')]);
    })->name('test.notification');

    // Domains CRUD
    Route::resource('domains', DomainController::class)->names('domains');
    Route::get('domains-json', [DomainController::class, 'json'])->name('domains.json');
    Route::get('domains-under-attack-statuses', [DomainController::class, 'underAttackStatuses'])->name('domains.under-attack-statuses');
    Route::get('domains-search', [DomainController::class, 'search'])->name('domains.search');

    // Domain FTP Management
    Route::put('domains/{domain}/ftp', [DomainController::class, 'updateFtp'])
        ->name('domains.ftp.update');
    Route::post('domains/{domain}/ftp/fix-permissions', [DomainController::class, 'fixPermissions'])
        ->name('domains.ftp.fix-permissions');

    // Domain Provisioning
    Route::post('domains/{domain}/provision', [DomainProvisionController::class, 'provision'])
        ->name('domains.provision');

    // SSL Activation / Renewal (legacy — kept for backward compatibility)
    Route::post('domains/{domain}/ssl-legacy', [DomainController::class, 'sslActivate'])
        ->name('domains.ssl-legacy');

    // SSL Certificate Management
    Route::prefix('domains/{domain}/ssl')->name('domains.ssl.')->group(function (): void {
        Route::get('/', [SslCertificateController::class, 'index'])->name('index');
        Route::post('/letsencrypt', [SslCertificateController::class, 'storeLetsEncrypt'])->name('letsencrypt');
        Route::post('/self-signed', [SslCertificateController::class, 'storeSelfSigned'])->name('self-signed');
        Route::post('/csr', [SslCertificateController::class, 'generateCsr'])->name('csr');
        Route::get('/{certificate}/csr/download', [SslCertificateController::class, 'downloadCsr'])->name('csr.download');
        Route::post('/validate-key', [SslCertificateController::class, 'validateKey'])->name('validate-key');
        Route::post('/upload', [SslCertificateController::class, 'uploadCertificate'])->name('upload');
        Route::post('/{certificate}/activate', [SslCertificateController::class, 'activate'])->name('cert.activate');
        Route::post('/{certificate}/complete-csr', [SslCertificateController::class, 'completeCsr'])->name('complete-csr');
        Route::get('/{certificate}/show', [SslCertificateController::class, 'show'])->name('show');
        Route::get('/{certificate}/export', [SslCertificateController::class, 'export'])->name('export');
        Route::post('/import', [SslCertificateController::class, 'importPem'])->name('import');
        Route::delete('/{certificate}', [SslCertificateController::class, 'destroy'])->name('destroy');
        Route::post('/cancel', [SslCertificateController::class, 'cancelSslOperation'])->name('cancel');
    });

    // ModSecurity Management (per domain)
    Route::get('domains/{domain}/modsecurity', [DomainModSecurityController::class, 'index'])
        ->name('domains.modsecurity.index');
    Route::put('domains/{domain}/modsecurity', [DomainModSecurityController::class, 'update'])
        ->name('domains.modsecurity.update');
    Route::get('domains/{domain}/modsecurity/logs', [DomainModSecurityController::class, 'logs'])
        ->name('domains.modsecurity.logs');

    // Domain Logs (per domain / subdomain)
    Route::get('domains/{domain}/logs', [DomainLogController::class, 'index'])
        ->name('domains.logs.index');
    Route::get('domains/{domain}/logs/entries', [DomainLogController::class, 'entries'])
        ->name('domains.logs.entries');
    Route::post('domains/{domain}/logs/stream', [DomainLogController::class, 'streamStart'])
        ->name('domains.logs.stream.start');

    // DNS Management (per domain)
    Route::get('domains/{domain}/dns', [DnsController::class, 'index'])->name('domains.dns.index');
    Route::get('domains/{domain}/dns/json', [DnsController::class, 'listRecords'])->name('domains.dns.json');
    Route::post('domains/{domain}/dns', [DnsController::class, 'store'])->name('domains.dns.store');
    Route::delete('domains/{domain}/dns', [DnsController::class, 'destroy'])->name('domains.dns.destroy');
    Route::post('domains/{domain}/dns/switch-provider', [DnsController::class, 'switchProvider'])->name('domains.dns.switch-provider');
    Route::post('domains/{domain}/dns/bulk-destroy', [DnsController::class, 'bulkDestroy'])->name('domains.dns.bulk-destroy');

    // Cloudflare Management (per domain)
    Route::prefix('domains/{domain}/cloudflare')->name('domains.cloudflare.')->group(function (): void {
        Route::get('/manage', [DomainCloudflareController::class, 'manage'])->name('manage');
        Route::get('/summary', [DomainCloudflareController::class, 'summary'])->name('summary');
        Route::get('/settings', [DomainCloudflareController::class, 'settings'])->name('settings');
        Route::get('/dnssec-status', [DomainCloudflareController::class, 'dnssecStatus'])->name('dnssec.status');
        Route::get('/firewall-rules', [DomainCloudflareController::class, 'firewallRules'])->name('firewall-rules.index');
        Route::get('/', [DomainCloudflareController::class, 'status'])->name('status');
        Route::post('/sync', [DomainCloudflareController::class, 'sync'])->name('sync');
        Route::post('/add', [DomainCloudflareController::class, 'add'])->name('add');
        Route::post('/purge-cache', [DomainCloudflareController::class, 'purgeCache'])->name('purge-cache');
        Route::post('/setting', [DomainCloudflareController::class, 'updateSetting'])->name('setting');
        Route::post('/dnssec', [DomainCloudflareController::class, 'updateDnssec'])->name('dnssec');
        Route::post('/firewall-rules', [DomainCloudflareController::class, 'storeFirewallRule'])->name('firewall-rules.store');
        Route::delete('/firewall-rules/{ruleId}', [DomainCloudflareController::class, 'deleteFirewallRule'])->name('firewall-rules.delete');
    });

    // Database Management (per domain)
    Route::get('domains/{domain}/databases', [DatabaseController::class, 'index'])->name('domains.databases.index');
    Route::get('domains/{domain}/databases/json', [DatabaseController::class, 'json'])->name('domains.databases.json');
    Route::post('domains/{domain}/databases', [DatabaseController::class, 'store'])->name('domains.databases.store');
    Route::delete('domains/{domain}/databases/{database}', [DatabaseController::class, 'destroyDatabase'])
        ->name('domains.databases.destroy');
    Route::post('domains/{domain}/databases/{database}/users', [DatabaseController::class, 'storeUser'])
        ->name('domains.databases.users.store');
    Route::put('domains/{domain}/databases/users/{user}/password', [DatabaseController::class, 'updateUserPassword'])
        ->name('domains.databases.users.password');
    Route::delete('domains/{domain}/databases/users/{user}', [DatabaseController::class, 'destroyUser'])
        ->name('domains.databases.users.destroy');

    // PHP Settings (per domain)
    Route::get('domains/{domain}/php', [PhpSettingsController::class, 'index'])->name('domains.php.index');
    Route::put('domains/{domain}/php', [PhpSettingsController::class, 'update'])->name('domains.php.update');

    // Custom Caddy Config (per domain)
    Route::get('domains/{domain}/custom-conf', [DomainCustomConfController::class, 'show'])->name('domains.custom-conf.show');
    Route::put('domains/{domain}/custom-conf', [DomainCustomConfController::class, 'update'])->name('domains.custom-conf.update');

    // Laravel Supervisor (per domain)
    Route::get('domains/{domain}/supervisor', [DomainSupervisorController::class, 'index'])->name('domains.supervisor.index');
    Route::post('domains/{domain}/supervisor', [DomainSupervisorController::class, 'update'])->name('domains.supervisor.update');
    Route::post('domains/{domain}/supervisor/restart', [DomainSupervisorController::class, 'restart'])->name('domains.supervisor.restart');
    Route::post('domains/{domain}/supervisor/workers/restart', [DomainSupervisorController::class, 'restartFrankenphpWorkers'])->name('domains.supervisor.workers.restart');
    Route::post('domains/{domain}/supervisor/optimize', [DomainSupervisorController::class, 'runOptimize'])->name('domains.supervisor.optimize');
    Route::post('domains/{domain}/supervisor/artisan', [DomainSupervisorController::class, 'runArtisan'])->name('domains.supervisor.artisan');

    // Cron Jobs (per domain)
    Route::get('domains/{domain}/cron-jobs', [DomainCronJobController::class, 'index'])->name('domains.cron-jobs.index');
    Route::post('domains/{domain}/cron-jobs', [DomainCronJobController::class, 'store'])->name('domains.cron-jobs.store');
    Route::put('domains/{domain}/cron-jobs/{cronJob}', [DomainCronJobController::class, 'update'])->name('domains.cron-jobs.update');
    Route::delete('domains/{domain}/cron-jobs/{cronJob}', [DomainCronJobController::class, 'destroy'])->name('domains.cron-jobs.destroy');
    Route::post('domains/{domain}/cron-jobs/{cronJob}/toggle', [DomainCronJobController::class, 'toggle'])->name('domains.cron-jobs.toggle');
    Route::get('domains/{domain}/cron-jobs/{cronJob}/logs', [DomainCronJobController::class, 'logs'])->name('domains.cron-jobs.logs');

    // Package Manager (per domain)
    Route::get('domains/{domain}/package-manager', [DomainPackageManagerController::class, 'index'])->name('domains.packages.index');
    Route::get('domains/{domain}/package-manager/npm/packages', [DomainPackageManagerController::class, 'listNpmPackages'])->name('domains.packages.npm.packages');
    Route::post('domains/{domain}/package-manager/npm/install', [DomainPackageManagerController::class, 'npmInstall'])->name('domains.packages.npm.install');
    Route::post('domains/{domain}/package-manager/npm/build', [DomainPackageManagerController::class, 'npmBuild'])->name('domains.packages.npm.build');
    Route::post('domains/{domain}/package-manager/npm/audit-fix', [DomainPackageManagerController::class, 'npmAuditFix'])->name('domains.packages.npm.audit-fix');
    Route::get('domains/{domain}/package-manager/composer/packages', [DomainPackageManagerController::class, 'listComposerPackages'])->name('domains.packages.composer.packages');
    Route::post('domains/{domain}/package-manager/composer/install', [DomainPackageManagerController::class, 'composerInstall'])->name('domains.packages.composer.install');
    Route::post('domains/{domain}/package-manager/composer/update', [DomainPackageManagerController::class, 'composerUpdate'])->name('domains.packages.composer.update');
    Route::post('domains/{domain}/package-manager/composer/dump-autoload', [DomainPackageManagerController::class, 'composerDumpAutoload'])->name('domains.packages.composer.dump-autoload');

    // File Manager (per domain)
    Route::prefix('domains/{domain}/files')->name('domains.files.')->group(function (): void {
        Route::get('/', [FileManagerController::class, 'index'])->name('index');
        Route::get('/list', [FileManagerController::class, 'list'])->name('list');
        Route::get('/read', [FileManagerController::class, 'read'])->name('read');
        Route::post('/write', [FileManagerController::class, 'write'])->name('write');
        Route::post('/create-file', [FileManagerController::class, 'createFile'])->name('create-file');
        Route::post('/create-directory', [FileManagerController::class, 'createDirectory'])->name('create-directory');
        Route::post('/upload', [FileManagerController::class, 'upload'])->name('upload');
        Route::post('/delete', [FileManagerController::class, 'delete'])->name('delete');
        Route::post('/rename', [FileManagerController::class, 'rename'])->name('rename');
        Route::post('/chmod', [FileManagerController::class, 'chmod'])->name('chmod');
        Route::post('/compress', [FileManagerController::class, 'compress'])->name('compress');
        Route::post('/decompress', [FileManagerController::class, 'decompress'])->name('decompress');
        Route::get('/download', [FileManagerController::class, 'download'])->name('download');
    });

    // Fortify 2FA enable/disable (manually registered, ignoreRoutes active)
    Route::post('user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'store'])
        ->name('two-factor.enable');
    Route::delete('user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'destroy'])
        ->name('two-factor.disable');

    // User Security
    Route::get('user/security', [UserController::class, 'index'])->name('user.security');
    Route::get('user/notifications/all', [UserController::class, 'notificationsPage'])->name('user.notifications.page');
    Route::get('user/notifications', [UserController::class, 'notifications'])->name('user.notifications.index');
    Route::post('user/notifications/read-all', [UserController::class, 'markAllNotificationsAsRead'])
        ->name('user.notifications.read-all');
    Route::post('user/notifications/{notification}/read', [UserController::class, 'markNotificationAsRead'])
        ->name('user.notifications.read');
    Route::delete('user/notifications/all', [UserController::class, 'destroyAllNotifications'])
        ->name('user.notifications.destroy-all');
    Route::delete('user/notifications/{notification}', [UserController::class, 'destroyNotification'])
        ->name('user.notifications.destroy');
    Route::post('user/security/2fa-confirm', [TwoFactorAuthController::class, 'confirm'])->name('user.two-factor.confirm');
    Route::post('user/security/webauthn', [WebAuthnController::class, 'list'])->name('user.security.webauthn');
    Route::post('user/security/webauthn/delete', [WebAuthnController::class, 'delete'])->name('user.security.webauthn.delete');
    Route::post('user/security/webauthn/rename', [WebAuthnController::class, 'rename'])->name('user.security.webauthn.rename');
    Route::post('user/security/update-email', [UserController::class, 'updateEmail'])->name('user.security.update-email');
    Route::post('user/security/update-password', [UserController::class, 'updatePassword'])->name('user.security.update-password');

    // Push Subscription Management
    Route::get('user/push-subscription/status', [PushSubscriptionController::class, 'status'])
        ->name('user.push-subscription.status');
    Route::post('user/push-subscription', [PushSubscriptionController::class, 'store'])
        ->name('user.push-subscription.store');
    Route::delete('user/push-subscription', [PushSubscriptionController::class, 'destroy'])
        ->name('user.push-subscription.destroy');

    // Notification Settings
    Route::get('user/notification-settings', [NotificationSettingsController::class, 'index'])
        ->name('user.notification-settings.index');
    Route::get('user/notification-settings/devices', [NotificationSettingsController::class, 'devices'])
        ->name('user.notification-settings.devices');
    Route::put('user/notification-settings', [NotificationSettingsController::class, 'update'])
        ->name('user.notification-settings.update');
    Route::delete('user/notification-settings/devices/{pushSubscription}', [NotificationSettingsController::class, 'destroyDevice'])
        ->name('user.notification-settings.destroy-device');

    // Legacy redirect
    Route::get('user/push-devices', fn () => redirect()->route('user.notification-settings.devices'))
        ->name('user.push-devices.index');

    // Lock Screen
    Route::post('/lock-screen', [TwoFactorAuthController::class, 'lock'])->name('lockscreen');

    // ── Panel-Level Permission-Gated Routes ─────────────────

    Route::middleware('permission:panel.docker.actions')->group(function (): void {
        Route::post('/dashboard/docker-action', [HomeController::class, 'dockerAction'])
            ->name('dashboard.docker.action');
    });

    // Impersonation — `stop` MUST come before `{user}` or Laravel treats "stop" as a user slug.
    // Stop has no permission gate so impersonated users can always end their own session.
    Route::post('impersonate/stop', [ImpersonationController::class, 'destroy'])->name('impersonation.stop');
    Route::post('impersonate/{user}', [ImpersonationController::class, 'store'])
        ->middleware(['permission:panel.users.impersonate', 'throttle:10,1'])
        ->name('impersonation.start');

    Route::middleware('permission:panel.users.manage')->group(function (): void {
        Route::get('users', [UserAccountsController::class, 'index'])->name('users.list');
        Route::get('users/json', [UserAccountsController::class, 'json'])->name('users.json');
        Route::post('users', [UserAccountsController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserAccountsController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [UserAccountsController::class, 'destroy'])->name('users.destroy');

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('roles/json', [RoleController::class, 'json'])->name('roles.json');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    Route::middleware(['permission:panel.terminal.access', 'throttle:30,1'])->group(function (): void {
        Route::post('terminal/start', [TerminalController::class, 'start'])->name('terminal.start');
        Route::post('terminal/start-ssh', [TerminalController::class, 'startSsh'])->name('terminal.start-ssh');
        Route::post('terminal/stop', [TerminalController::class, 'stop'])->name('terminal.stop');
    });

    Route::middleware(['permission:domain.terminal.access', 'throttle:30,1'])->group(function (): void {
        Route::post('terminal/start-domain', [TerminalController::class, 'startDomain'])->name('terminal.start-domain');
    });

    Route::middleware('permission:panel.audit-logs.view')->group(function (): void {
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('audit-logs/json', [AuditLogController::class, 'json'])->name('audit-logs.json');
        Route::get('audit-logs/options/users', [AuditLogController::class, 'usersOptions'])->name('audit-logs.options.users');
        Route::get('audit-logs/options/actions', [AuditLogController::class, 'actionsOptions'])->name('audit-logs.options.actions');
        Route::get('audit-logs/options/domains', [AuditLogController::class, 'domainsOptions'])->name('audit-logs.options.domains');
    });

    Route::middleware('permission:panel.terminal-logs.view')->group(function (): void {
        Route::get('terminal-logs', [TerminalLogController::class, 'index'])->name('terminal-logs.index');
        Route::get('terminal-logs/json', [TerminalLogController::class, 'json'])->name('terminal-logs.json');
        Route::get('terminal-logs/options/users', [TerminalLogController::class, 'usersOptions'])->name('terminal-logs.options.users');
        Route::get('terminal-logs/{terminalLog}', [TerminalLogController::class, 'show'])->name('terminal-logs.show');
    });

    Route::middleware('permission:panel.crowdsec.view')->group(function (): void {
        Route::get('security/crowdsec', [CrowdSecController::class, 'index'])->name('security.crowdsec.index');
        Route::get('security/crowdsec/data', [CrowdSecController::class, 'data'])->name('security.crowdsec.data');
        Route::get('security/crowdsec/decisions', [CrowdSecController::class, 'decisions'])->name('security.crowdsec.decisions');
    });

    Route::middleware('permission:panel.waf-rules.view')->group(function (): void {
        Route::get('security/waf-global-rules', [WafGlobalRuleController::class, 'index'])->name('security.waf-global.index');
    });

    Route::middleware('permission:panel.waf-rules.manage')->group(function (): void {
        Route::post('security/waf-global-rules', [WafGlobalRuleController::class, 'store'])->name('security.waf-global.store');
        Route::put('security/waf-global-rules/{rule}', [WafGlobalRuleController::class, 'update'])->name('security.waf-global.update');
        Route::delete('security/waf-global-rules/{rule}', [WafGlobalRuleController::class, 'destroy'])->name('security.waf-global.destroy');
    });

    Route::middleware('permission:panel.ftp-bans.view')->group(function (): void {
        Route::get('security/ftp-bans', [FtpBanController::class, 'index'])->name('security.ftp-bans.index');
        Route::get('security/ftp-bans/data', [FtpBanController::class, 'data'])->name('security.ftp-bans.data');
        Route::get('security/ftp-bans/log', [FtpBanController::class, 'log'])->name('security.ftp-bans.log');
    });

    Route::middleware('permission:panel.ftp-bans.manage')->group(function (): void {
        Route::post('security/ftp-bans', [FtpBanController::class, 'store'])->name('security.ftp-bans.store');
        Route::delete('security/ftp-bans', [FtpBanController::class, 'destroy'])->name('security.ftp-bans.destroy');
        Route::post('security/ftp-bans/whitelist', [FtpBanController::class, 'whitelistStore'])->name('security.ftp-bans.whitelist.store');
        Route::delete('security/ftp-bans/whitelist', [FtpBanController::class, 'whitelistDestroy'])->name('security.ftp-bans.whitelist.destroy');
    });

    Route::middleware('permission:panel.firewall.view')->group(function (): void {
        Route::get('security/firewall', [FirewallController::class, 'index'])->name('security.firewall.index');
        Route::get('security/firewall/data', [FirewallController::class, 'data'])->name('security.firewall.data');
        Route::get('security/firewall/preview', [FirewallController::class, 'preview'])->name('security.firewall.preview');
    });

    Route::middleware('permission:panel.firewall.manage')->group(function (): void {
        Route::post('security/firewall', [FirewallController::class, 'store'])->name('security.firewall.store');
        Route::delete('security/firewall', [FirewallController::class, 'destroy'])->name('security.firewall.destroy');
        Route::put('security/firewall/policy', [FirewallController::class, 'policy'])->name('security.firewall.policy');
        Route::put('security/firewall/reorder', [FirewallController::class, 'reorder'])->name('security.firewall.reorder');
        Route::put('security/firewall/{rule}', [FirewallController::class, 'update'])->name('security.firewall.update');
        Route::put('security/firewall/{rule}/toggle', [FirewallController::class, 'toggle'])->name('security.firewall.toggle');
    });

    // System Updates
    Route::middleware('permission:panel.system.updates')->group(function (): void {
        Route::prefix('system/updates')->name('system.updates.')->group(function (): void {
            Route::get('/', [SystemUpdateController::class, 'index'])->name('index');
            Route::post('/check', [SystemUpdateController::class, 'check'])->name('check');
            Route::post('/panel', [SystemUpdateController::class, 'updatePanel'])->name('panel');
            Route::post('/mysql/prepare', [SystemUpdateController::class, 'prepareMysqlUpgrade'])->name('mysql.prepare');
            Route::post('/mysql/apply', [SystemUpdateController::class, 'applyMysqlUpgrade'])->name('mysql.apply');
            Route::post('/mysql/rollback', [SystemUpdateController::class, 'rollbackMysqlUpgrade'])->name('mysql.rollback');
            Route::post('/mysql/cleanup', [SystemUpdateController::class, 'cleanupMysqlBackup'])->name('mysql.cleanup');
            Route::get('/task/{taskId}', [SystemUpdateController::class, 'taskStatus'])->name('task.status');
        });
    });

    Route::middleware('permission:panel.backups.view')->group(function (): void {
        Route::prefix('backups')->name('backups.')->group(function (): void {
            Route::get('/', [BackupController::class, 'index'])->name('index');
            Route::get('/connect', [BackupController::class, 'connect'])->name('connect');
            Route::get('/callback', [BackupController::class, 'callback'])->name('callback');
            Route::get('/history', [BackupController::class, 'history'])->name('history');
            Route::get('/folders', [BackupController::class, 'folders'])->name('folders');
            Route::get('/drive-quota', [BackupController::class, 'driveQuota'])->name('drive-quota');
            Route::get('/drive-files', [BackupController::class, 'driveFiles'])->name('drive-files');
            Route::get('/drive-download/{fileId}', [BackupController::class, 'driveDownload'])->name('drive-download');
        });
    });

    Route::middleware('permission:panel.backups.manage')->group(function (): void {
        Route::prefix('backups')->name('backups.')->group(function (): void {
            Route::post('/disconnect', [BackupController::class, 'disconnect'])->name('disconnect');
            Route::post('/settings', [BackupController::class, 'updateSettings'])->name('settings');
            Route::post('/folder', [BackupController::class, 'setFolder'])->name('folder');
            Route::post('/create-folder', [BackupController::class, 'createFolder'])->name('create-folder');
            Route::post('/run', [BackupController::class, 'run'])->name('run');
            Route::post('/{backupRun}/cancel', [BackupController::class, 'cancel'])->name('cancel');
            Route::post('/restart', [BackupController::class, 'restart'])->name('restart');
        });
    });

    // PHP Versions
    Route::get('php-versions', [PhpVersionController::class, 'index'])
        ->middleware('permission:panel.php-versions.view')
        ->name('php-versions.index');
    Route::post('php-versions/{phpVersion}/toggle', [PhpVersionController::class, 'toggle'])
        ->middleware('permission:panel.php-versions.manage')
        ->name('php-versions.toggle');
    Route::get('php-versions/frankenphp/php-ini', [PhpVersionController::class, 'getFrankenPhpIni'])
        ->middleware('permission:panel.php-versions.manage')
        ->name('php-versions.frankenphp-ini');
    Route::put('php-versions/frankenphp/php-ini', [PhpVersionController::class, 'updateFrankenPhpIni'])
        ->middleware('permission:panel.php-versions.manage')
        ->name('php-versions.frankenphp-ini.update');
    Route::get('php-versions/{phpVersion}/php-ini', [PhpVersionController::class, 'getPhpIni'])
        ->middleware('permission:panel.php-versions.manage')
        ->name('php-versions.php-ini');
    Route::put('php-versions/{phpVersion}/php-ini', [PhpVersionController::class, 'updatePhpIni'])
        ->middleware('permission:panel.php-versions.manage')
        ->name('php-versions.php-ini.update');
    Route::post('php-versions/{phpVersion}/restart', [PhpVersionController::class, 'restart'])
        ->middleware('permission:panel.php-versions.manage')
        ->name('php-versions.restart');
    Route::post('php-versions/{phpVersion}/recreate-conf', [PhpVersionController::class, 'recreateConf'])
        ->middleware('permission:panel.php-versions.manage')
        ->name('php-versions.recreate-conf');

    // Docker Services
    Route::middleware('permission:panel.docker-services.view')->group(function (): void {
        Route::get('docker-services', [DockerServiceController::class, 'index'])->name('docker-services.index');
        Route::get('docker-services/create', [DockerServiceController::class, 'create'])->name('docker-services.create');
        Route::get('docker-services/{dockerService}', [DockerServiceController::class, 'show'])->name('docker-services.show');
        Route::get('docker-services/{dockerService}/edit', [DockerServiceController::class, 'edit'])->name('docker-services.edit');
        Route::get('docker-services/{dockerService}/logs', [DockerServiceController::class, 'logs'])->name('docker-services.logs');
        Route::get('docker-services/{dockerService}/stats', [DockerServiceController::class, 'stats'])->name('docker-services.stats');
    });

    Route::middleware('permission:panel.docker-services.manage')->group(function (): void {
        Route::post('docker-services', [DockerServiceController::class, 'store'])->name('docker-services.store');
        Route::put('docker-services/{dockerService}', [DockerServiceController::class, 'update'])->name('docker-services.update');
        Route::delete('docker-services/{dockerService}', [DockerServiceController::class, 'destroy'])->name('docker-services.destroy');
        Route::post('docker-services/{dockerService}/action', [DockerServiceController::class, 'action'])->name('docker-services.action');
        Route::post('docker-services/{dockerService}/sync-status', [DockerServiceController::class, 'syncStatus'])->name('docker-services.sync-status');
    });

    // Docker Hub API
    Route::middleware('permission:panel.docker-services.view')->prefix('docker-hub')->name('docker-hub.')->group(function (): void {
        Route::get('search', [DockerHubController::class, 'search'])->name('search');
        Route::get('popular', [DockerHubController::class, 'popular'])->name('popular');
        Route::get('tags', [DockerHubController::class, 'tags'])->name('tags');
        Route::get('image-config', [DockerHubController::class, 'imageConfig'])->name('image-config');
    });

    // Domain Docker Service Bindings
    Route::middleware('permission:panel.docker-services.manage')->group(function (): void {
        Route::get('domains/{domain}/docker-services', [DockerServiceDomainBindingController::class, 'index'])->name('domains.docker-services.index');
        Route::post('domains/{domain}/docker-services', [DockerServiceDomainBindingController::class, 'store'])->name('domains.docker-services.store');
        Route::delete('domains/{domain}/docker-services/{binding}', [DockerServiceDomainBindingController::class, 'destroy'])->name('domains.docker-services.destroy');
    });

    // Docker Projects
    Route::middleware('permission:panel.docker-services.view')->group(function (): void {
        Route::get('docker-projects', [DockerProjectController::class, 'index'])->name('docker-projects.index');
        Route::get('docker-projects/create', [DockerProjectController::class, 'create'])->name('docker-projects.create');
        Route::get('docker-projects/{dockerProject}', [DockerProjectController::class, 'show'])->name('docker-projects.show');
        Route::get('docker-projects/{dockerProject}/edit', [DockerProjectController::class, 'edit'])->name('docker-projects.edit');
        Route::get('docker-projects/{dockerProject}/logs', [DockerProjectController::class, 'logs'])->name('docker-projects.logs');
        Route::post('docker-projects/{dockerProject}/sync-status', [DockerProjectController::class, 'syncStatus'])->name('docker-projects.sync-status');
    });

    Route::middleware('permission:panel.docker-services.manage')->group(function (): void {
        Route::post('docker-projects', [DockerProjectController::class, 'store'])->name('docker-projects.store');
        Route::put('docker-projects/{dockerProject}', [DockerProjectController::class, 'update'])->name('docker-projects.update');
        Route::delete('docker-projects/{dockerProject}', [DockerProjectController::class, 'destroy'])->name('docker-projects.destroy');
        Route::post('docker-projects/{dockerProject}/action', [DockerProjectController::class, 'action'])->name('docker-projects.action');

        // Docker Project File Manager
        Route::get('docker-projects/{dockerProject}/files', [DockerProjectFileController::class, 'index'])->name('docker-projects.files.index');
        Route::get('docker-projects/{dockerProject}/files/list', [DockerProjectFileController::class, 'list'])->name('docker-projects.files.list');
        Route::get('docker-projects/{dockerProject}/files/read', [DockerProjectFileController::class, 'read'])->name('docker-projects.files.read');
        Route::post('docker-projects/{dockerProject}/files/write', [DockerProjectFileController::class, 'write'])->name('docker-projects.files.write');
        Route::post('docker-projects/{dockerProject}/files/create-file', [DockerProjectFileController::class, 'createFile'])->name('docker-projects.files.create-file');
        Route::post('docker-projects/{dockerProject}/files/create-directory', [DockerProjectFileController::class, 'createDirectory'])->name('docker-projects.files.create-directory');
        Route::post('docker-projects/{dockerProject}/files/upload', [DockerProjectFileController::class, 'upload'])->name('docker-projects.files.upload');
        Route::post('docker-projects/{dockerProject}/files/delete', [DockerProjectFileController::class, 'delete'])->name('docker-projects.files.delete');
        Route::post('docker-projects/{dockerProject}/files/rename', [DockerProjectFileController::class, 'rename'])->name('docker-projects.files.rename');
        Route::get('docker-projects/{dockerProject}/files/download', [DockerProjectFileController::class, 'download'])->name('docker-projects.files.download');
    });

    // Docker Project Domain Bindings
    Route::middleware('permission:panel.docker-services.manage')->group(function (): void {
        Route::get('domains/{domain}/docker-projects', [DockerProjectDomainBindingController::class, 'index'])->name('domains.docker-projects.index');
        Route::post('domains/{domain}/docker-projects', [DockerProjectDomainBindingController::class, 'store'])->name('domains.docker-projects.store');
        Route::delete('domains/{domain}/docker-projects/{binding}', [DockerProjectDomainBindingController::class, 'destroy'])->name('domains.docker-projects.destroy');
    });

    // Domain IP Access Control
    Route::get('domains/{domain}/ip-access', [DomainIpRuleController::class, 'index'])
        ->name('domains.ip-access.index');
    Route::put('domains/{domain}/ip-access/mode', [DomainIpRuleController::class, 'updateMode'])
        ->name('domains.ip-access.update-mode');
    Route::post('domains/{domain}/ip-access', [DomainIpRuleController::class, 'store'])
        ->name('domains.ip-access.store');
    Route::delete('domains/{domain}/ip-access/{rule}', [DomainIpRuleController::class, 'destroy'])
        ->name('domains.ip-access.destroy');

    // Domain User Management (shared access)
    Route::get('domains/{domain}/users', [DomainUserController::class, 'index'])->name('domains.users.index');
    Route::post('domains/{domain}/users', [DomainUserController::class, 'store'])->name('domains.users.store');
    Route::delete('domains/{domain}/users/{user}', [DomainUserController::class, 'destroy'])->name('domains.users.destroy');

    // Admin Push Notifications
    Route::middleware('admin')->group(function (): void {
        Route::get('admin/push-notifications', [AdminPushNotificationController::class, 'index'])
            ->name('admin.push-notifications.index');
        Route::post('admin/push-notifications/send', [AdminPushNotificationController::class, 'send'])
            ->name('admin.push-notifications.send');
    });

    Route::get('/pma/domain/{domain}/database/{database}/sso', [PmaSsoController::class, 'database'])
        ->name('pma.database.sso');

    // Genel (admin) SSO
    Route::get('/pma/admin/sso', [PmaSsoController::class, 'admin'])
        ->name('pma.admin.sso');

    // MySQL Configuration (admin)
    Route::middleware('permission:panel.mysql-config.manage')->prefix('settings/mysql-config')->name('settings.mysql-config.')->group(function (): void {
        Route::get('/', [MysqlConfigController::class, 'index'])->name('index');
        Route::put('{file}', [MysqlConfigController::class, 'update'])->where('file', '.+')->name('update');
        Route::put('{file}/raw', [MysqlConfigController::class, 'updateRaw'])->where('file', '.+')->name('update-raw');
        Route::post('restart', [MysqlConfigController::class, 'restart'])->name('restart');
        Route::post('purge-binlogs', [MysqlConfigController::class, 'purgeBinlogs'])->name('purge-binlogs');
    });

    // DNS Server Settings (admin)
    Route::middleware('permission:panel.dns-settings.manage')->prefix('settings/dns')->name('settings.dns.')->group(function (): void {
        Route::get('/', [DnsSettingController::class, 'index'])->name('index');
        Route::put('/', [DnsSettingController::class, 'update'])->name('update');
    });

    // ACME / Let's Encrypt Settings (admin)
    Route::middleware('permission:panel.acme-settings.manage')->prefix('settings/acme')->name('settings.acme.')->group(function (): void {
        Route::get('/', [AcmeSettingController::class, 'index'])->name('index');
        Route::put('/', [AcmeSettingController::class, 'update'])->name('update');
    });

    // Panel SSL Certificate (admin)
    Route::middleware('permission:panel.acme-settings.manage')->prefix('settings/panel-ssl')->name('settings.panel-ssl.')->group(function (): void {
        Route::get('/', [PanelSslController::class, 'index'])->name('index');
        Route::post('/issue', [PanelSslController::class, 'issue'])->name('issue');
        Route::post('/sync', [PanelSslController::class, 'sync'])->name('sync');
    });

    // DNS Templates (admin)
    Route::middleware('permission:panel.dns-templates.manage')->prefix('settings/dns-templates')->name('settings.dns-templates.')->group(function (): void {
        Route::get('/', [DnsTemplateController::class, 'index'])->name('index');
        Route::post('/', [DnsTemplateController::class, 'store'])->name('store');
        Route::get('/{dnsTemplate}', [DnsTemplateController::class, 'show'])->name('show');
        Route::put('/{dnsTemplate}', [DnsTemplateController::class, 'update'])->name('update');
        Route::delete('/{dnsTemplate}', [DnsTemplateController::class, 'destroy'])->name('destroy');
        Route::post('/{dnsTemplate}/default', [DnsTemplateController::class, 'setDefault'])->name('set-default');
    });

    // Login Security Settings (anti-bot + IP filter)
    Route::middleware('permission:panel.security-settings.manage')->prefix('settings/security')->name('settings.security.')->group(function (): void {
        Route::get('anti-bot', [SecuritySettingController::class, 'antiBot'])->name('anti-bot.index');
        Route::put('anti-bot', [SecuritySettingController::class, 'updateAntiBot'])->name('anti-bot.update');
        Route::get('login-ip-filter', [LoginIpFilterController::class, 'index'])->name('login-ip-filter.index');
        Route::put('login-ip-filter/mode', [LoginIpFilterController::class, 'updateMode'])->name('login-ip-filter.update-mode');
        Route::post('login-ip-filter', [LoginIpFilterController::class, 'store'])->name('login-ip-filter.store');
        Route::delete('login-ip-filter/{loginIpRule}', [LoginIpFilterController::class, 'destroy'])->name('login-ip-filter.destroy');
    });

    // System Alert Settings
    Route::middleware('permission:panel.alert-settings.manage')->prefix('settings/alerts')->name('settings.alerts.')->group(function (): void {
        Route::get('/', [AlertSettingController::class, 'index'])->name('index');
        Route::put('/', [AlertSettingController::class, 'update'])->name('update');
        Route::post('/run-check', [AlertSettingController::class, 'runCheck'])->name('run-check');
    });

    // API Tokens (admin)
    Route::middleware('admin')->prefix('settings/api-tokens')->name('settings.api-tokens.')->group(function (): void {
        Route::get('/', [ApiTokenWebController::class, 'index'])->name('index');
    });

    // Webhooks (admin)
    Route::middleware('admin')->prefix('settings/webhooks')->name('settings.webhooks.')->group(function (): void {
        Route::get('/', [WebhookWebController::class, 'index'])->name('index');
        Route::post('/', [WebhookWebController::class, 'store'])->name('store');
        Route::put('/{endpoint}', [WebhookWebController::class, 'update'])->name('update');
        Route::delete('/{endpoint}', [WebhookWebController::class, 'destroy'])->name('destroy');
        Route::post('/{endpoint}/test', [WebhookWebController::class, 'sendTest'])->name('test');
        Route::post('/{endpoint}/regenerate-secret', [WebhookWebController::class, 'regenerateSecret'])->name('regenerate');
    });

    // Mail hosting (Mailu + Zimbra)
    Route::prefix('mail')->name('mail.')->group(function (): void {
        // Settings are admin-accessible regardless of feature flags so admins
        // can bootstrap Mailu / Zimbra from the panel itself (chicken-and-egg).
        Route::middleware('admin')->prefix('settings')->name('settings.')->group(function (): void {
            Route::get('/', [\App\Http\Controllers\Mail\MailSettingsController::class, 'edit'])->name('edit');
            Route::put('/relay', [\App\Http\Controllers\Mail\MailSettingsController::class, 'updateRelay'])->name('relay.update');
            Route::put('/zimbra', [\App\Http\Controllers\Mail\MailSettingsController::class, 'updateZimbra'])->name('zimbra.update');
            Route::post('/zimbra/test', [\App\Http\Controllers\Mail\MailSettingsController::class, 'testZimbra'])->name('zimbra.test');
        });

        // Domains overview + mailbox/alias management — feature-flag gated.
        Route::middleware('mail.feature')->group(function (): void {
            Route::get('/', \App\Http\Controllers\Mail\MailIndexController::class)->name('index');

            Route::get('domains/{domain}', \App\Http\Controllers\Mail\DomainMailController::class)->name('domain');

            Route::prefix('domains/{domain}/mailboxes')->name('mailboxes.')->group(function (): void {
                Route::get('/', [\App\Http\Controllers\Mail\MailboxController::class, 'index'])->name('index');
                Route::get('/create', [\App\Http\Controllers\Mail\MailboxController::class, 'create'])->name('create');
                Route::post('/', [\App\Http\Controllers\Mail\MailboxController::class, 'store'])->name('store');
                Route::put('/{local}', [\App\Http\Controllers\Mail\MailboxController::class, 'update'])->name('update');
                Route::delete('/{local}', [\App\Http\Controllers\Mail\MailboxController::class, 'destroy'])->name('destroy');
                Route::post('/{local}/password', [\App\Http\Controllers\Mail\MailboxController::class, 'setPassword'])->name('password');
                Route::post('/{local}/forwarding', [\App\Http\Controllers\Mail\MailboxController::class, 'setForwarding'])->name('forwarding');
            });

            Route::prefix('domains/{domain}/aliases')->name('aliases.')->group(function (): void {
                Route::get('/', [\App\Http\Controllers\Mail\AliasController::class, 'index'])->name('index');
                Route::post('/', [\App\Http\Controllers\Mail\AliasController::class, 'store'])->name('store');
                Route::delete('/{local}', [\App\Http\Controllers\Mail\AliasController::class, 'destroy'])->name('destroy');
            });
        });
    });
});
