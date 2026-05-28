<?php

namespace App\Services;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\PhpVersion;
use App\Services\Domain\ApacheConfigRenderer;
use App\Services\Domain\CaddyConfigRenderer;
use App\Services\Domain\DomainDirectoryService;
use App\Services\Domain\PhpFpmConfigRenderer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Thin orchestrator over the focused Domain\* renderers.
 *
 * Public API is preserved exactly so all existing callers
 * (DomainController, ApplyChangesService, panel:apply, panel:render, jobs,
 * tests, etc.) keep working without changes.
 */
class DomainConfigService
{
    private CaddyConfigRenderer $caddy;

    private ApacheConfigRenderer $apache;

    private PhpFpmConfigRenderer $phpFpm;

    private DomainDirectoryService $directories;

    public function __construct(
        ?CaddyConfigRenderer $caddy = null,
        ?ApacheConfigRenderer $apache = null,
        ?PhpFpmConfigRenderer $phpFpm = null,
        ?DomainDirectoryService $directories = null,
    ) {
        // Resolve via container when null so `new DomainConfigService` keeps working
        // for tests that instantiate the service without arguments.
        $this->directories = $directories ?? app(DomainDirectoryService::class);
        $this->caddy = $caddy ?? app(CaddyConfigRenderer::class);
        $this->apache = $apache ?? app(ApacheConfigRenderer::class);
        $this->phpFpm = $phpFpm ?? app(PhpFpmConfigRenderer::class);
    }

    /**
     * Render configs without TLS (Phase 1 - before cert exists).
     */
    public function renderWithoutTls(Domain $domain): void
    {
        $this->ensureDirectories($domain);

        $this->caddy->writeCaddyConfig($domain, false);

        if ($domain->type === DomainType::ApacheReverseProxy) {
            $this->apache->writeApacheConfig($domain);
            $this->phpFpm->writePhpFpmConfig($domain);
        }
    }

    /**
     * Regenerate only the Caddyfile for a domain, using the current cert state.
     * No exec calls — pure disk I/O. Safe to call on all domains at once.
     */
    public function regenerateCaddyConfig(Domain $domain): void
    {
        $this->caddy->writeCaddyConfig($domain, $this->caddy->certExists($domain));
        $this->caddy->syncWebmailCaddyConfig($domain);
    }

    /**
     * Write or remove the webmail Caddyfile for mail.{fqdn} and webmail.{fqdn}.
     */
    public function syncWebmailCaddyConfig(Domain $domain): void
    {
        $this->caddy->syncWebmailCaddyConfig($domain);
    }

    /**
     * Render configs with TLS (Phase 2 - after cert provisioned).
     */
    public function renderWithTls(Domain $domain): void
    {
        $this->ensureDirectories($domain);
        $this->caddy->writeCaddyConfig($domain, true);

        if ($domain->type === DomainType::ApacheReverseProxy) {
            $this->apache->writeApacheConfig($domain);
            $this->phpFpm->writePhpFpmConfig($domain);
        }

        $this->writeUserIni($domain);
    }

    /**
     * Check if TLS certificate files exist for a domain.
     * For subdomains, checks the apex domain's wildcard certificate.
     * Checks both certbot live/ and self-signed selfsigned/ directories.
     */
    public function certExists(Domain $domain): bool
    {
        return $this->caddy->certExists($domain);
    }

    /**
     * Resolve the cert/key file paths for a domain.
     * DB-first lookup with disk-based fallback for pre-migration certs.
     * For subdomains, checks the parent domain's active certificate.
     *
     * @return array{cert: string, key: string}|null
     */
    public function resolveCertPaths(Domain $domain): ?array
    {
        return $this->caddy->resolveCertPaths($domain);
    }

    /**
     * Generate the PHP-FPM pool config for a legacy domain.
     * Always includes php_value lines (from PhpSetting or defaults).
     */
    public function writePhpFpmConfig(Domain $domain): void
    {
        $this->phpFpm->writePhpFpmConfig($domain);
    }

    /**
     * Ensure domain directories exist inside the php-code-server container.
     * Creates httpdocs, logs dirs and sets ownership to the FTP/pool user.
     */
    public function ensureDirectories(Domain $domain): void
    {
        $this->directories->ensureDirectories($domain);
    }

    /**
     * Write a .user.ini with open_basedir restriction to the domain's web root.
     * The file is owned by root and made immutable with chattr +i so site owners cannot modify or delete it.
     */
    public function writeUserIni(Domain $domain): void
    {
        $this->directories->writeUserIni($domain);
    }

    /**
     * Remove the PHP-FPM pool config for a domain from a specific PHP version's pool directory.
     * Also restarts the FPM service so the removed pool is unloaded.
     */
    public function removePhpFpmConfig(string $fqdn, PhpVersion $version): void
    {
        $this->phpFpm->removePhpFpmConfig($fqdn, $version);
    }

    /**
     * Change the PHP version for a legacy domain.
     * Removes conf from old version dir, writes to new, restarts both FPM services.
     */
    public function changePhpVersion(Domain $domain, PhpVersion $newVersion): void
    {
        $this->phpFpm->changePhpVersion($domain, $newVersion);
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
        if (in_array(strtolower($fqdn), array_map('strtolower', config('panel.system_reserved_domains', [])), true)) {
            Log::warning("Refusing to remove system-reserved domain configs: {$fqdn}");

            return;
        }

        if ($fqdn === '*') {
            $catchallDir = $this->caddy->caddyCatchallDirectoryPath();
            if (File::isDirectory($catchallDir)) {
                File::deleteDirectory($catchallDir);
            }

            return;
        }

        $caddyDir = $this->caddy->caddySiteDirectoryPath($fqdn);
        if (File::isDirectory($caddyDir)) {
            File::deleteDirectory($caddyDir);
            Log::info("Removed Caddy config directory: {$caddyDir}");
        }

        $webmailDir = $this->caddy->caddyWebmailDirectoryPath($fqdn);
        if (File::isDirectory($webmailDir)) {
            File::deleteDirectory($webmailDir);
            Log::info("Removed webmail Caddy config directory: {$webmailDir}");
        }

        $apacheFile = $this->apache->apacheConfigPath($fqdn);
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
     * Generate the per-vhost Caddyfile.
     *
     * Phase 1 (withTls=false): HTTP-only blocks serving the site on :80.
     * Phase 2 (withTls=true): Snippet definition + :80 redirects + :443 import.
     */
    protected function writeCaddyConfig(Domain $domain, bool $withTls): void
    {
        $this->caddy->writeCaddyConfig($domain, $withTls);
    }

    /**
     * Generate Apache vhost for a legacy domain.
     */
    protected function writeApacheConfig(Domain $domain): void
    {
        $this->apache->writeApacheConfig($domain);
    }

    /**
     * Atomically write a config file. fsync ensures the bytes hit the disk
     * before rename, so Caddy never sees a half-written file under high I/O.
     */
    protected function writeConfigFile(string $filePath, string $content): void
    {
        $this->directories->writeConfigFile($filePath, $content);
    }

    /**
     * Reflection-test seam: kept as a private proxy so existing tests that
     * invoke this method via ReflectionMethod on DomainConfigService keep
     * passing. Production code paths route through CaddyConfigRenderer.
     *
     * @param  array{cert: string, key: string}|null  $certPaths
     * @return array<int, string>
     */
    private function renderWildcardSubdomainConfig(Domain $domain, string $fqdn, string $rootPath, ?array $certPaths): array
    {
        $method = new \ReflectionMethod($this->caddy, 'renderWildcardSubdomainConfig');

        return (array) $method->invoke($this->caddy, $domain, $fqdn, $rootPath, $certPaths);
    }

    /**
     * Reflection-test seam: kept as a private proxy so existing tests that
     * invoke this method via ReflectionMethod on DomainConfigService keep
     * passing. Production code paths route through CaddyConfigRenderer.
     *
     * @param  array{cert: string, key: string}|null  $certPaths
     * @return array<int, string>
     */
    private function renderCatchallConfig(Domain $domain, string $rootPath, ?array $certPaths): array
    {
        $method = new \ReflectionMethod($this->caddy, 'renderCatchallConfig');

        return (array) $method->invoke($this->caddy, $domain, $rootPath, $certPaths);
    }
}
