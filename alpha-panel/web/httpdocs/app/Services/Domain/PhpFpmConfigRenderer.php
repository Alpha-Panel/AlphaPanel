<?php

namespace App\Services\Domain;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\PhpVersion;
use App\Services\PortainerService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class PhpFpmConfigRenderer
{
    public function __construct(private DomainDirectoryService $directoryService) {}

    /**
     * Generate the PHP-FPM pool config for a legacy domain.
     * Always includes php_value lines (from PhpSetting or defaults).
     */
    public function writePhpFpmConfig(Domain $domain): void
    {
        if ($domain->type !== DomainType::ApacheReverseProxy || ! $domain->phpVersion) {
            return;
        }

        $fqdn = $domain->fqdn;
        $phpVersion = $domain->phpVersion;
        $fpmPoolDir = $phpVersion->fpm_pool_dir;
        $poolUser = $domain->getEffectiveFtpUsername();
        $webRoot = $domain->getWebRootPath();

        $lines = [];
        $lines[] = "[{$fqdn}]";
        $lines[] = "user = {$poolUser}";
        $lines[] = 'group = www-data';
        $lines[] = "listen = /run/php/{$fqdn}.sock";
        $lines[] = 'listen.owner = www-data';
        $lines[] = 'listen.group = www-data';
        if (version_compare($phpVersion->slug, '8.1', '>=')) {
            $lines[] = 'process.umask = 0022';
        }
        $lines[] = 'pm = dynamic';
        $lines[] = 'pm.max_children = 5';
        $lines[] = 'pm.start_servers = 2';
        $lines[] = 'pm.min_spare_servers = 1';
        $lines[] = 'pm.max_spare_servers = 3';
        $lines[] = "chdir = {$webRoot}";

        // Filesystem isolation — restrict PHP to domain root + essential paths
        $basePath = $domain->getBasePath();
        $openBasedir = implode(':', [$basePath, '/tmp', '/dev/urandom']);
        $lines[] = "php_admin_value[open_basedir] = {$openBasedir}";

        // PHP settings — use PhpSetting record or defaults
        $setting = $domain->phpSetting;
        $lines[] = 'php_value[display_errors] = '.($setting->display_errors ?? 'On');
        $lines[] = 'php_value[memory_limit] = '.($setting->memory_limit ?? '256M');
        $lines[] = 'php_value[post_max_size] = '.($setting->post_max_size ?? '256M');
        $lines[] = 'php_value[upload_max_filesize] = '.($setting->upload_max_filesize ?? '256M');
        $lines[] = 'php_value[max_execution_time] = '.($setting->max_execution_time ?? 3000);
        $lines[] = 'php_value[max_input_time] = '.($setting->max_input_time ?? 3000);
        $lines[] = 'php_value[max_input_vars] = '.($setting->max_input_vars ?? 3000);
        $lines[] = 'php_value[session.gc_maxlifetime] = '.($setting->session_gc_maxlifetime ?? 1440);
        $lines[] = 'php_value[session.cookie_lifetime] = '.($setting->session_cookie_lifetime ?? 1440);
        $lines[] = 'php_value[date.timezone] = '.($setting->date_timezone ?? 'Europe/Istanbul');
        $lines[] = 'php_value[opcache.enable] = '.($setting->opcache_enable ?? 'On');
        $lines[] = 'php_value[error_reporting] = '.($setting->error_reporting ?? 'E_ALL');
        $lines[] = 'php_value[allow_url_fopen] = '.($setting->allow_url_fopen ?? 'On');

        if ($setting?->disable_functions) {
            $lines[] = "php_admin_value[disable_functions] = {$setting->disable_functions}";
        }

        $content = implode("\n", $lines)."\n";
        $this->directoryService->writeConfigFile("{$fpmPoolDir}/{$fqdn}.conf", $content);
    }

    /**
     * Remove the PHP-FPM pool config for a domain from a specific PHP version's pool directory.
     * Also restarts the FPM service so the removed pool is unloaded.
     */
    public function removePhpFpmConfig(string $fqdn, PhpVersion $version): void
    {
        $confPath = "{$version->fpm_pool_dir}/{$fqdn}.conf";
        if (File::isFile($confPath)) {
            File::delete($confPath);
            Log::info("Removed FPM pool config: {$confPath}");
        }

        try {
            app(PortainerService::class)->execInContainer('php-code-server', [
                'supervisorctl', 'restart', $version->fpm_service_name,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to restart FPM {$version->fpm_service_name}: {$e->getMessage()}");
        }
    }

    /**
     * Change the PHP version for a legacy domain.
     * Removes conf from old version dir, writes to new, restarts both FPM services.
     */
    public function changePhpVersion(Domain $domain, PhpVersion $newVersion): void
    {
        $oldVersion = $domain->phpVersion;
        $fqdn = $domain->fqdn;

        // Remove conf from old version directory
        if ($oldVersion && $oldVersion->id !== $newVersion->id) {
            $this->removePhpFpmConfig($fqdn, $oldVersion);
        }

        // Update domain's PHP version
        $domain->php_version_id = $newVersion->id;
        $domain->save();
        $domain->load('phpVersion');

        // Write new FPM conf to new version directory
        $this->writePhpFpmConfig($domain);

        // Restart new FPM service (old one already restarted by removePhpFpmConfig)
        try {
            app(PortainerService::class)->execInContainer('php-code-server', [
                'supervisorctl', 'restart', $newVersion->fpm_service_name,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to restart new FPM {$newVersion->fpm_service_name}: {$e->getMessage()}");
        }

        Log::info("PHP version changed for {$fqdn}: {$oldVersion?->slug} → {$newVersion->slug}");
    }
}
