<?php

namespace App\Services;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\PhpVersion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class DomainConfigService
{
    private string $caddySitesBasePath;

    private string $apacheSitesBasePath;

    private string $letsEncryptBasePath;

    public function __construct()
    {
        $this->caddySitesBasePath = config('panel.caddy_sites_base');
        $this->apacheSitesBasePath = config('panel.apache_sites_base');
        $this->letsEncryptBasePath = config('panel.letsencrypt_base');
    }

    /**
     * Render configs without TLS (Phase 1 - before cert exists).
     */
    public function renderWithoutTls(Domain $domain): void
    {
        if ($domain->type === DomainType::ApacheReverseProxy) {
            $this->ensureDirectories($domain);
        }

        $this->writeCaddyConfig($domain, false);

        if ($domain->type === DomainType::ApacheReverseProxy) {
            $this->writeApacheConfig($domain);
            $this->writePhpFpmConfig($domain);
        }
    }

    /**
     * Render configs with TLS (Phase 2 - after cert provisioned).
     */
    public function renderWithTls(Domain $domain): void
    {
        $this->writeCaddyConfig($domain, true);

        if ($domain->type === DomainType::ApacheReverseProxy) {
            $this->writeApacheConfig($domain);
            $this->writePhpFpmConfig($domain);
        }
    }

    /**
     * Check if TLS certificate files exist for a domain.
     * For subdomains, checks the apex domain's wildcard certificate.
     */
    public function certExists(Domain $domain): bool
    {
        $certDomain = $domain->isSubdomain() ? $domain->getApexDomain() : $domain->fqdn;
        $certPath = "{$this->letsEncryptBasePath}/{$certDomain}/fullchain.pem";
        $keyPath = "{$this->letsEncryptBasePath}/{$certDomain}/privkey.pem";

        return File::exists($certPath) && File::exists($keyPath);
    }

    /**
     * Generate the per-vhost Caddyfile.
     *
     * Phase 1 (withTls=false): HTTP-only blocks serving the site on :80.
     * Phase 2 (withTls=true): Snippet definition + :80 redirects + :443 import.
     */
    protected function writeCaddyConfig(Domain $domain, bool $withTls): void
    {
        $fqdn = $domain->fqdn;
        $rootPath = $domain->getWebRootPath();
        $slug = str_replace('.', '-', $fqdn);

        $certDomain = $domain->isSubdomain() ? $domain->getApexDomain() : $fqdn;
        $certPath = "{$this->letsEncryptBasePath}/{$certDomain}/fullchain.pem";
        $keyPath = "{$this->letsEncryptBasePath}/{$certDomain}/privkey.pem";

        $hasCert = $withTls && File::exists($certPath) && File::exists($keyPath);

        if ($withTls && ! $hasCert) {
            Log::warning("TLS certs not found for {$fqdn}. Skipping TLS block.");
        }

        $lines = [];

        if ($hasCert) {
            $lines = array_merge($lines, $this->renderTlsCaddyConfig(
                $domain, $fqdn, $rootPath, $slug, $certPath, $keyPath,
            ));
        } else {
            $lines = array_merge($lines, $this->renderHttpOnlyCaddyConfig(
                $domain, $fqdn, $rootPath,
            ));
        }

        $content = implode("\n", $lines)."\n";
        $this->writeConfigFile("{$this->caddySitesBasePath}/{$fqdn}/Caddyfile", $content);
    }

    /**
     * Render full TLS Caddyfile with snippet, :80 redirects, and :443 import.
     *
     * @return array<int, string>
     */
    private function renderTlsCaddyConfig(
        Domain $domain,
        string $fqdn,
        string $rootPath,
        string $slug,
        string $certPath,
        string $keyPath,
    ): array {
        $lines = [];

        $isLegacy = $domain->type === DomainType::ApacheReverseProxy;

        // Snippet definition
        $lines[] = "(reverse-proxy-{$slug}) {";
        $this->appendCommonHeaderImports($lines, '    ', $domain);
        $lines[] = "    tls {$certPath} {$keyPath}";
        $lines[] = '    encode zstd br gzip';
        $lines = array_merge($lines, $this->renderAcmeChallengePath('    '));

        if (! $isLegacy) {
            $lines[] = "    root * {$rootPath}";
        }

        $lines = array_merge($lines, $this->renderServerDirectives($domain, $fqdn, $rootPath, '    '));

        if (! $isLegacy) {
            $lines[] = '    file_server';
            $lines[] = '    log {';
            $lines[] = "        output file /var/log/caddy/{$fqdn}.log";
            $lines[] = '        format console';
            $lines[] = '    }';
        }

        $lines[] = '}';
        $lines[] = '';

        // HTTP → HTTPS redirect
        $lines[] = "{$fqdn}:80 {";
        $this->appendCommonHeaderImports($lines, '        ', $domain);
        $lines = array_merge($lines, $this->renderAcmeChallengePath('        '));
        $lines[] = "        redir https://{$fqdn}{uri}";
        $lines[] = '}';
        $lines[] = '';

        // WWW redirects
        if ($domain->enable_www_redirect && ! str_starts_with($fqdn, 'www.')) {
            $lines[] = "www.{$fqdn}:80 {";
            $this->appendCommonHeaderImports($lines, '    ', $domain);
            $lines = array_merge($lines, $this->renderAcmeChallengePath('    '));
            $lines[] = "    redir https://{$fqdn}{uri}";
            $lines[] = '}';
            $lines[] = '';

            $lines[] = "www.{$fqdn}:443 {";
            $lines[] = "    tls {$certPath} {$keyPath}";
            $lines[] = "    redir https://{$fqdn}{uri}";
            $lines[] = '}';
            $lines[] = '';
        }

        // Additional hostname redirects
        $additionalHostnames = $domain->additional_hostnames ?? [];
        foreach ($additionalHostnames as $hostname) {
            $lines[] = "{$hostname}:80 {";
            $this->appendCommonHeaderImports($lines, '    ', $domain);
            $lines = array_merge($lines, $this->renderAcmeChallengePath('    '));
            $lines[] = "    redir https://{$fqdn}{uri}";
            $lines[] = '}';
            $lines[] = '';

            $lines[] = "{$hostname}:443 {";
            $lines[] = "    tls {$certPath} {$keyPath}";
            $lines[] = "    redir https://{$fqdn}{uri}";
            $lines[] = '}';
            $lines[] = '';
        }

        // Main HTTPS block
        $lines[] = "{$fqdn}:443 {";

        if ($isLegacy) {
            $lines[] = '    handle_errors {';
            $lines[] = '        respond "Upstream Error {http.error.status_code}\n\nMessage: {http.error.message}\n\nCause:   {http.error.unwrap.message}" {http.error.status_code}';
            $lines[] = '    }';
            $lines[] = '';
        }

        $lines[] = "    import reverse-proxy-{$slug}";
        $lines[] = '}';

        return $lines;
    }

    /**
     * Render HTTP-only Caddyfile (Phase 1 — before cert exists).
     *
     * @return array<int, string>
     */
    private function renderHttpOnlyCaddyConfig(
        Domain $domain,
        string $fqdn,
        string $rootPath,
    ): array {
        $lines = [];

        $isLegacy = $domain->type === DomainType::ApacheReverseProxy;

        $lines[] = "{$fqdn}:80 {";
        $this->appendCommonHeaderImports($lines, '    ', $domain);
        $lines[] = '    encode zstd br gzip';
        $lines = array_merge($lines, $this->renderAcmeChallengePath('    '));

        if (! $isLegacy) {
            $lines[] = "    root * {$rootPath}";
        }

        $lines = array_merge($lines, $this->renderServerDirectives($domain, $fqdn, $rootPath, '    '));

        if (! $isLegacy) {
            $lines[] = '    file_server';
            $lines[] = '    log {';
            $lines[] = "        output file /var/log/caddy/{$fqdn}.log";
            $lines[] = '        format json';
            $lines[] = '    }';
        }

        $lines[] = '}';

        return $lines;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function appendCommonHeaderImports(array &$lines, string $indent, Domain $domain): void
    {
        $lines[] = "{$indent}import common-headers";

        $wafImport = $this->resolveWafImport($domain);
        if ($wafImport !== null) {
            $lines[] = "{$indent}{$wafImport}";
        }
    }

    private function resolveWafImport(Domain $domain): ?string
    {
        if (! $domain->modsecurity_enabled) {
            return null;
        }

        return $domain->modsecurity_mode === 'detection_only'
            ? 'import waf-common-detection-only'
            : 'import waf-common';
    }

    /**
     * Render ACME HTTP-01 challenge handler for webroot validation.
     *
     * @return array<int, string>
     */
    private function renderAcmeChallengePath(string $indent): array
    {
        return [
            "{$indent}handle_path /.well-known/acme-challenge/* {",
            "{$indent}    root * /var/www/acme-challenge",
            "{$indent}    file_server",
            "{$indent}}",
        ];
    }

    /**
     * Render the server-type-specific directives (php_server or reverse_proxy).
     *
     * @return array<int, string>
     */
    private function renderServerDirectives(Domain $domain, string $fqdn, string $rootPath, string $indent): array
    {
        // If bypass_reverse_proxy is enabled and custom directives exist, use them
        if ($domain->bypass_reverse_proxy && ! empty($domain->custom_caddy_directives)) {
            $lines = [];
            foreach (explode("\n", $domain->custom_caddy_directives) as $line) {
                $trimmed = rtrim($line);
                $lines[] = $trimmed !== '' ? "{$indent}{$trimmed}" : '';
            }

            return $lines;
        }

        $lines = [];

        if ($domain->type === DomainType::CaddyWebServer) {
            if ($domain->enable_worker) {
                $lines[] = "{$indent}php_server {";
                $lines[] = "{$indent}    root {$rootPath}";
                $lines[] = "{$indent}    worker {";
                $lines[] = "{$indent}        file frankenphp-worker.php";
                $lines[] = "{$indent}        num ".($domain->worker_num ?? 5);
                if ($domain->worker_watch) {
                    $lines[] = "{$indent}        watch {$rootPath}";
                }
                $lines[] = "{$indent}    }";
                $lines[] = "{$indent}}";
            } else {
                $lines[] = "{$indent}php_server";
            }
        } elseif ($domain->type === DomainType::ApacheReverseProxy) {
            $lines[] = "{$indent}reverse_proxy http://php-code-server:80 {";
            $lines[] = "{$indent}    header_up Host {$fqdn}";
            $lines[] = "{$indent}    header_up X-Forwarded-Host {$fqdn}";
            $lines[] = "{$indent}    header_up X-Forwarded-Port 80";
            $lines[] = "{$indent}    header_up X-Forwarded-Proto https";
            $lines[] = '';
            $lines[] = "{$indent}    header_up X-Forwarded-For  {client_ip}";
            $lines[] = "{$indent}    header_up X-Real-IP        {client_ip}";
            $lines[] = "{$indent}    header_up X-Remote-Addr    {client_ip}";
            $lines[] = "{$indent}    header_up CF-Connecting-IP {client_ip}";
            $lines[] = "{$indent}}";
        }

        return $lines;
    }

    /**
     * Generate Apache vhost for a legacy domain.
     */
    protected function writeApacheConfig(Domain $domain): void
    {
        if ($domain->type !== DomainType::ApacheReverseProxy) {
            return;
        }

        $fqdn = $domain->fqdn;
        $rootPath = $domain->getWebRootPath();
        $logPath = "{$domain->getBasePath()}/logs";

        $lines = [];
        $lines[] = '<VirtualHost *:80>';
        $lines[] = "        ServerName {$fqdn}";

        if ($domain->enable_www_redirect && ! str_starts_with($fqdn, 'www.')) {
            $lines[] = "        ServerAlias www.{$fqdn}";
        }

        $additionalHostnames = $domain->additional_hostnames ?? [];
        foreach ($additionalHostnames as $hostname) {
            $lines[] = "        ServerAlias {$hostname}";
        }

        $lines[] = '';
        $lines[] = '        ServerAdmin webmaster@localhost';
        $lines[] = "        DocumentRoot {$rootPath}";
        $lines[] = '';
        $lines[] = "        <Directory {$rootPath}>";
        $lines[] = '                Options -Indexes -MultiViews +ExecCGI';
        $lines[] = '                AllowOverride All';
        $lines[] = '                Require all granted';
        $lines[] = '        </Directory>';
        $lines[] = '';
        $lines[] = '        <FilesMatch \.php$>';
        $lines[] = "            SetHandler \"proxy:unix:/run/php/{$fqdn}.sock|fcgi://localhost\"";
        $lines[] = '        </FilesMatch>';
        $lines[] = '';
        $lines[] = "        ErrorLog {$logPath}/error.log";
        $lines[] = "        CustomLog {$logPath}/access.log combined";
        $lines[] = '</VirtualHost>';

        $content = implode("\n", $lines)."\n";
        $this->writeConfigFile("{$this->apacheSitesBasePath}/{$fqdn}.conf", $content);
    }

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
        $poolUser = $domain->ftpUser?->username ?? explode('.', $fqdn)[0];
        $webRoot = $domain->getWebRootPath();

        $lines = [];
        $lines[] = "[{$fqdn}]";
        $lines[] = "user = {$poolUser}";
        $lines[] = "group = {$poolUser}";
        $lines[] = "listen = /run/php/{$fqdn}.sock";
        $lines[] = 'listen.owner = www-data';
        $lines[] = 'listen.group = www-data';
        $lines[] = 'pm = dynamic';
        $lines[] = 'pm.max_children = 5';
        $lines[] = 'pm.start_servers = 2';
        $lines[] = 'pm.min_spare_servers = 1';
        $lines[] = 'pm.max_spare_servers = 3';
        $lines[] = "chdir = {$webRoot}";

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
        $this->writeConfigFile("{$fpmPoolDir}/{$fqdn}.conf", $content);
    }

    /**
     * Ensure domain directories exist inside the php-code-server container.
     * Creates httpdocs, logs dirs and sets ownership to the FTP/pool user.
     */
    public function ensureDirectories(Domain $domain): void
    {
        $basePath = $domain->getBasePath();
        $webRoot = $domain->getWebRootPath();
        $logsPath = "{$basePath}/logs";
        $poolUser = $domain->ftpUser?->username ?? explode('.', $domain->fqdn)[0];

        $portainer = app(PortainerService::class);

        // Create httpdocs and logs directories
        $portainer->execInContainer('php-code-server', [
            'mkdir', '-p', $webRoot, $logsPath,
        ]);

        // Set ownership to the pool/FTP user
        $portainer->execInContainer('php-code-server', [
            'chown', '-R', "{$poolUser}:{$poolUser}", $basePath,
        ]);

        Log::info("Ensured directories for {$domain->fqdn}: {$webRoot}, {$logsPath} (owner: {$poolUser})");
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
        if ($oldVersion) {
            $oldConfPath = "{$oldVersion->fpm_pool_dir}/{$fqdn}.conf";
            if (File::isFile($oldConfPath)) {
                File::delete($oldConfPath);
                Log::info("Removed old FPM conf: {$oldConfPath}");
            }
        }

        // Update domain's PHP version
        $domain->php_version_id = $newVersion->id;
        $domain->save();
        $domain->load('phpVersion');

        // Write new FPM conf to new version directory
        $this->writePhpFpmConfig($domain);

        // Restart FPM services via Portainer
        $portainer = app(PortainerService::class);

        if ($oldVersion && $oldVersion->id !== $newVersion->id) {
            try {
                $portainer->execInContainer('php-code-server', [
                    'supervisorctl', 'restart', $oldVersion->fpm_service_name,
                ]);
            } catch (\Throwable $e) {
                Log::warning("Failed to restart old FPM {$oldVersion->fpm_service_name}: {$e->getMessage()}");
            }
        }

        try {
            $portainer->execInContainer('php-code-server', [
                'supervisorctl', 'restart', $newVersion->fpm_service_name,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to restart new FPM {$newVersion->fpm_service_name}: {$e->getMessage()}");
        }

        Log::info("PHP version changed for {$fqdn}: {$oldVersion?->slug} → {$newVersion->slug}");
    }

    /**
     * Remove all config files for a domain.
     */
    public function removeConfigs(Domain $domain): void
    {
        $this->removeConfigsByFqdn($domain->fqdn, $domain->phpVersion);
    }

    /**
     * Remove all config files by FQDN string (used for domain renames).
     */
    public function removeConfigsByFqdn(string $fqdn, ?PhpVersion $phpVersion = null): void
    {
        $caddyDir = "{$this->caddySitesBasePath}/{$fqdn}";
        if (File::isDirectory($caddyDir)) {
            File::deleteDirectory($caddyDir);
            Log::info("Removed Caddy config directory: {$caddyDir}");
        }

        $apacheFile = "{$this->apacheSitesBasePath}/{$fqdn}.conf";
        if (File::isFile($apacheFile)) {
            File::delete($apacheFile);
            Log::info("Removed Apache config: {$apacheFile}");
        }

        if ($phpVersion) {
            $fpmFile = "{$phpVersion->fpm_pool_dir}/{$fqdn}.conf";
            if (File::isFile($fpmFile)) {
                File::delete($fpmFile);
                Log::info("Removed FPM pool config: {$fpmFile}");
            }
        }
    }

    /**
     * Atomically write a config file.
     */
    protected function writeConfigFile(string $filePath, string $content): void
    {
        $dir = dirname($filePath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $tempPath = $filePath.'.tmp.'.uniqid();
        File::put($tempPath, $content);
        File::move($tempPath, $filePath);

        Log::info("Configuration file written: {$filePath}");
    }
}
