<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CrowdSecController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\DomainCloudflareController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\DomainCronJobController;
use App\Http\Controllers\DomainLogController;
use App\Http\Controllers\DomainModSecurityController;
use App\Http\Controllers\DomainPackageManagerController;
use App\Http\Controllers\DomainProvisionController;
use App\Http\Controllers\DomainSupervisorController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PhpSettingsController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\TerminalLogController;
use App\Http\Controllers\TwoFactorAuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WafGlobalRuleController;
use App\Http\Controllers\WebAuthn\WebAuthnLoginController;
use App\Http\Controllers\WebAuthn\WebAuthnRegisterController;
use App\Http\Controllers\WebAuthnController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;

Route::get('/manifest.json', [App\Http\Controllers\ManifestController::class, 'index'])->name('manifest');
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
    ->name('webauthn.login.options');
Route::post('webauthn/login', [WebAuthnLoginController::class, 'login'])
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

    // Test notification (geçici - test sonrası silinecek)
    Route::get('test-notification', function () {
        auth()->user()->notify(new \App\Notifications\DomainNotification(
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

    // Domain Provisioning
    Route::post('domains/{domain}/provision', [DomainProvisionController::class, 'provision'])
        ->name('domains.provision');

    // SSL Activation / Renewal
    Route::post('domains/{domain}/ssl', [DomainController::class, 'sslActivate'])
        ->name('domains.ssl.activate');

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

    // Laravel Supervisor (per domain)
    Route::get('domains/{domain}/supervisor', [DomainSupervisorController::class, 'index'])->name('domains.supervisor.index');
    Route::post('domains/{domain}/supervisor', [DomainSupervisorController::class, 'update'])->name('domains.supervisor.update');
    Route::post('domains/{domain}/supervisor/restart', [DomainSupervisorController::class, 'restart'])->name('domains.supervisor.restart');
    Route::post('domains/{domain}/supervisor/workers/restart', [DomainSupervisorController::class, 'restartFrankenphpWorkers'])->name('domains.supervisor.workers.restart');
    Route::post('domains/{domain}/supervisor/optimize', [DomainSupervisorController::class, 'runOptimize'])->name('domains.supervisor.optimize');

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
    Route::delete('user/notifications/{notification}', [UserController::class, 'destroyNotification'])
        ->name('user.notifications.destroy');
    Route::post('user/security/2fa-confirm', [TwoFactorAuthController::class, 'confirm'])->name('user.two-factor.confirm');
    Route::post('user/security/webauthn', [WebAuthnController::class, 'list'])->name('user.security.webauthn');
    Route::post('user/security/webauthn/delete', [WebAuthnController::class, 'delete'])->name('user.security.webauthn.delete');
    Route::post('user/security/webauthn/rename', [WebAuthnController::class, 'rename'])->name('user.security.webauthn.rename');

    // Lock Screen
    Route::post('/lock-screen', [TwoFactorAuthController::class, 'lock'])->name('lockscreen');

    // Admin-only routes
    Route::middleware('admin')->group(function (): void {
        Route::post('/dashboard/docker-action', [HomeController::class, 'dockerAction'])
            ->name('dashboard.docker.action');

        Route::get('users', [\App\Http\Controllers\UserAccountsController::class, 'index'])->name('users.list');
        Route::get('users/json', [\App\Http\Controllers\UserAccountsController::class, 'json'])->name('users.json');
        Route::post('users', [\App\Http\Controllers\UserAccountsController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [\App\Http\Controllers\UserAccountsController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [\App\Http\Controllers\UserAccountsController::class, 'destroy'])->name('users.destroy');

        // Docker Terminal (WebSocket proxy via terminal:serve command on port 2999)
        Route::post('terminal/start', [TerminalController::class, 'start'])->name('terminal.start');
        Route::post('terminal/start-ssh', [TerminalController::class, 'startSsh'])->name('terminal.start-ssh');
        Route::post('terminal/stop', [TerminalController::class, 'stop'])->name('terminal.stop');

        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('audit-logs/json', [AuditLogController::class, 'json'])->name('audit-logs.json');
        Route::get('audit-logs/options/users', [AuditLogController::class, 'usersOptions'])->name('audit-logs.options.users');
        Route::get('audit-logs/options/actions', [AuditLogController::class, 'actionsOptions'])->name('audit-logs.options.actions');
        Route::get('audit-logs/options/domains', [AuditLogController::class, 'domainsOptions'])->name('audit-logs.options.domains');

        Route::get('terminal-logs', [TerminalLogController::class, 'index'])->name('terminal-logs.index');
        Route::get('terminal-logs/json', [TerminalLogController::class, 'json'])->name('terminal-logs.json');
        Route::get('terminal-logs/options/users', [TerminalLogController::class, 'usersOptions'])->name('terminal-logs.options.users');
        Route::get('terminal-logs/{terminalLog}', [TerminalLogController::class, 'show'])->name('terminal-logs.show');

        Route::get('security/crowdsec', [CrowdSecController::class, 'index'])->name('security.crowdsec.index');
        Route::get('security/crowdsec/data', [CrowdSecController::class, 'data'])->name('security.crowdsec.data');
        Route::get('security/waf-global-rules', [WafGlobalRuleController::class, 'index'])->name('security.waf-global.index');
        Route::post('security/waf-global-rules', [WafGlobalRuleController::class, 'store'])->name('security.waf-global.store');
        Route::put('security/waf-global-rules/{rule}', [WafGlobalRuleController::class, 'update'])->name('security.waf-global.update');
        Route::delete('security/waf-global-rules/{rule}', [WafGlobalRuleController::class, 'destroy'])->name('security.waf-global.destroy');

        // Google Drive Backup
        Route::prefix('backups')->name('backups.')->group(function (): void {
            Route::get('/', [\App\Http\Controllers\BackupController::class, 'index'])->name('index');
            Route::get('/connect', [\App\Http\Controllers\BackupController::class, 'connect'])->name('connect');
            Route::get('/callback', [\App\Http\Controllers\BackupController::class, 'callback'])->name('callback');
            Route::post('/disconnect', [\App\Http\Controllers\BackupController::class, 'disconnect'])->name('disconnect');
            Route::post('/settings', [\App\Http\Controllers\BackupController::class, 'updateSettings'])->name('settings');
            Route::get('/folders', [\App\Http\Controllers\BackupController::class, 'folders'])->name('folders');
            Route::post('/folder', [\App\Http\Controllers\BackupController::class, 'setFolder'])->name('folder');
            Route::post('/create-folder', [\App\Http\Controllers\BackupController::class, 'createFolder'])->name('create-folder');
            Route::post('/run', [\App\Http\Controllers\BackupController::class, 'run'])->name('run');
            Route::get('/history', [\App\Http\Controllers\BackupController::class, 'history'])->name('history');
        });
    });

    Route::get('/pma/domain/{domain}/database/{database}/sso', [\App\Http\Controllers\PmaSsoController::class, 'database'])
        ->name('pma.database.sso');

    // Genel (admin) SSO
    Route::get('/pma/admin/sso', [\App\Http\Controllers\PmaSsoController::class, 'admin'])
        ->name('pma.admin.sso');
});
