<?php

namespace App\Services;

use App\Enums\DomainType;
use App\Enums\IpAccessMode;
use App\Models\Domain;
use App\Models\DomainIpRule;
use App\Models\PhpVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class DomainConfigService
{
    private string $caddySitesBasePath;

    private string $apacheSitesBasePath;

    private string $letsEncryptBasePath;

    private string $selfSignedBasePath;

    public function __construct(
        private CertbotService $certbotService,
    ) {
        $this->caddySitesBasePath = config('panel.caddy_sites_base');
        $this->apacheSitesBasePath = config('panel.apache_sites_base');
        $this->letsEncryptBasePath = config('panel.letsencrypt_base');
        $this->selfSignedBasePath = config('panel.letsencrypt_selfsigned_base');
    }

    /**
     * Render configs without TLS (Phase 1 - before cert exists).
     */
    public function renderWithoutTls(Domain $domain): void
    {
        $this->ensureDirectories($domain);

        $this->writeCaddyConfig($domain, false);

        if ($domain->type === DomainType::ApacheReverseProxy) {
            $this->writeApacheConfig($domain);
            $this->writePhpFpmConfig($domain);
        }
    }

    /**
     * Regenerate only the Caddyfile for a domain, using the current cert state.
     * No exec calls — pure disk I/O. Safe to call on all domains at once.
     */
    public function regenerateCaddyConfig(Domain $domain): void
    {
        $this->writeCaddyConfig($domain, $this->certExists($domain));
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

        $this->writeUserIni($domain);
    }

    /**
     * Check if TLS certificate files exist for a domain.
     * For subdomains, checks the apex domain's wildcard certificate.
     * Checks both certbot live/ and self-signed selfsigned/ directories.
     */
    public function certExists(Domain $domain): bool
    {
        return $this->resolveCertPaths($domain) !== null;
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
        // Priority 1: DB-tracked active certificate — write PEM from DB to disk
        $domain->loadMissing('activeSslCertificate');

        if ($domain->activeSslCertificate) {
            $cert = $domain->activeSslCertificate;

            if ($cert->certificate_pem && $cert->private_key_pem) {
                $sslService = app(SslCertificateService::class);
                $diskPaths = $sslService->getActiveCertDiskPaths($domain, $cert);

                if (! $diskPaths) {
                    // Files not on disk yet — write them
                    $sslService->writeCertToDisk($domain, $cert);
                    $diskPaths = $sslService->getActiveCertDiskPaths($domain, $cert);
                }

                if ($diskPaths) {
                    return $diskPaths;
                }
            }
        }

        // Priority 2: For subdomains, check parent domain's active cert
        if ($domain->isSubdomain()) {
            $parentDomain = Domain::where('fqdn', $domain->getApexDomain())->first();
            if ($parentDomain?->activeSslCertificate) {
                $cert = $parentDomain->activeSslCertificate;

                if ($cert->certificate_pem && $cert->private_key_pem) {
                    $sslService = app(SslCertificateService::class);
                    $diskPaths = $sslService->getActiveCertDiskPaths($parentDomain, $cert);

                    if (! $diskPaths) {
                        $sslService->writeCertToDisk($parentDomain, $cert);
                        $diskPaths = $sslService->getActiveCertDiskPaths($parentDomain, $cert);
                    }

                    if ($diskPaths) {
                        return $diskPaths;
                    }
                }
            }
        }

        // Priority 3: Legacy disk-based fallback (for pre-migration certs)
        $certDomain = $domain->isSubdomain() ? $domain->getApexDomain() : $domain->fqdn;

        $liveCert = "{$this->letsEncryptBasePath}/{$certDomain}/fullchain.pem";
        $liveKey = "{$this->letsEncryptBasePath}/{$certDomain}/privkey.pem";
        if (File::exists($liveCert) && File::exists($liveKey)) {
            return ['cert' => $liveCert, 'key' => $liveKey];
        }

        $ssCert = "{$this->selfSignedBasePath}/{$certDomain}/fullchain.pem";
        $ssKey = "{$this->selfSignedBasePath}/{$certDomain}/privkey.pem";
        if (File::exists($ssCert) && File::exists($ssKey)) {
            return ['cert' => $ssCert, 'key' => $ssKey];
        }

        return null;
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

        if (in_array(strtolower($fqdn), array_map('strtolower', config('panel.system_reserved_domains', [])), true)) {
            Log::warning("Refusing to overwrite system-reserved domain Caddyfile: {$fqdn}");

            return;
        }
        $rootPath = $domain->getWebRootPath();
        $slug = str_replace('.', '-', $fqdn);

        $certPaths = $withTls ? $this->resolveCertPaths($domain) : null;

        if ($withTls && $certPaths === null) {
            Log::warning("TLS certs not found for {$fqdn}. Skipping TLS block.");
        }

        $lines = [];

        if ($certPaths !== null) {
            $lines = array_merge($lines, $this->renderTlsCaddyConfig(
                $domain, $fqdn, $rootPath, $slug, $certPaths['cert'], $certPaths['key'],
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

        $serverDirectives = $this->renderServerDirectives($domain, $fqdn, $rootPath, '    ');
        $lines = array_merge($lines, $this->wrapWithIpAccessControl($domain, $serverDirectives, '    '));

        if (! $isLegacy) {
            $lines[] = '    log {';
            $lines[] = "        output file /var/log/caddy/{$fqdn}.log";
            $lines[] = '        format console';
            $lines[] = '    }';
        }

        $lines[] = '}';
        $lines[] = '';

        // HTTP → HTTPS redirect (handle blocks ensure ACME challenge is served before redirect)
        $lines = array_merge($lines, $this->renderHttpRedirectWithAcme(
            $fqdn, "https://{$fqdn}{uri}", '    ', $domain,
        ));
        $lines[] = '';

        // WWW redirects
        if ($domain->enable_www_redirect && ! str_starts_with($fqdn, 'www.')) {
            $lines = array_merge($lines, $this->renderHttpRedirectWithAcme(
                "www.{$fqdn}", "https://{$fqdn}{uri}", '    ', $domain,
            ));
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
            $lines = array_merge($lines, $this->renderHttpRedirectWithAcme(
                $hostname, "https://{$fqdn}{uri}", '    ', $domain,
            ));
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

        $serverDirectives = $this->renderServerDirectives($domain, $fqdn, $rootPath, '    ');
        $lines = array_merge($lines, $this->wrapWithIpAccessControl($domain, $serverDirectives, '    '));

        if (! $isLegacy) {
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

        if ($domain->cors_enabled) {
            $corsLines = $this->renderCorsDirectives($domain, $indent);
            array_push($lines, ...$corsLines);
        }
    }

    /**
     * Render CORS header directives for a domain.
     *
     * Supports wildcard (*) or comma-separated list of specific origins.
     * Generates Caddy preflight handler + response headers.
     *
     * @return array<int, string>
     */
    private function renderCorsDirectives(Domain $domain, string $indent): array
    {
        $origins = trim($domain->cors_allowed_origins ?? '*');

        if ($origins === '' || $origins === '*') {
            return $this->renderWildcardCors($indent);
        }

        // Single specific origin — use exact match
        $origins = array_map('trim', explode(',', $origins));
        $firstOrigin = $origins[0];

        return $this->renderSpecificOriginCors($indent, $firstOrigin, $origins);
    }

    /**
     * @return array<int, string>
     */
    private function renderWildcardCors(string $indent): array
    {
        return [
            "{$indent}@cors_preflight method OPTIONS",
            "{$indent}handle @cors_preflight {",
            "{$indent}    header Access-Control-Allow-Origin *",
            "{$indent}    header Access-Control-Allow-Methods \"GET, POST, PUT, PATCH, DELETE, OPTIONS\"",
            "{$indent}    header Access-Control-Allow-Headers \"Content-Type, Authorization, X-Requested-With, Accept\"",
            "{$indent}    header Access-Control-Max-Age 86400",
            "{$indent}    respond \"\" 204",
            "{$indent}}",
            "{$indent}header Access-Control-Allow-Origin *",
        ];
    }

    /**
     * @param  array<int, string>  $origins
     * @return array<int, string>
     */
    private function renderSpecificOriginCors(string $indent, string $primary, array $origins): array
    {
        $lines = [];

        // Build origin matcher — for multiple origins, use expression matcher
        if (count($origins) === 1) {
            $lines[] = "{$indent}@cors_origin header Origin {$primary}";
        } else {
            $quoted = array_map(fn (string $o) => "'{$o}'", $origins);
            $list = implode(', ', $quoted);
            $lines[] = "{$indent}@cors_origin expression `{http.request.header.Origin} in ({$list})`";
        }

        $lines[] = "{$indent}@cors_preflight {";
        $lines[] = "{$indent}    method OPTIONS";
        $lines[] = "{$indent}    header Origin *";
        $lines[] = "{$indent}}";
        $lines[] = "{$indent}handle @cors_preflight {";
        $lines[] = "{$indent}    header Access-Control-Allow-Origin {http.request.header.Origin}";
        $lines[] = "{$indent}    header Access-Control-Allow-Methods \"GET, POST, PUT, PATCH, DELETE, OPTIONS\"";
        $lines[] = "{$indent}    header Access-Control-Allow-Headers \"Content-Type, Authorization, X-Requested-With, Accept\"";
        $lines[] = "{$indent}    header Access-Control-Max-Age 86400";
        $lines[] = "{$indent}    respond \"\" 204";
        $lines[] = "{$indent}}";
        $lines[] = "{$indent}header @cors_origin Access-Control-Allow-Origin {http.request.header.Origin}";

        return $lines;
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
     * Render an HTTP server block that serves ACME challenges and redirects everything else.
     *
     * Uses Caddy `handle` blocks to ensure ACME challenge path takes priority
     * over the HTTPS redirect. Without this, Caddy's directive ordering causes
     * `redir` to run before `handle_path`, breaking certbot webroot validation.
     *
     * @return array<int, string>
     */
    private function renderHttpRedirectWithAcme(string $serverName, string $redirectUrl, string $indent, Domain $domain): array
    {
        $lines = [];
        $lines[] = "{$serverName}:80 {";
        $this->appendCommonHeaderImports($lines, $indent, $domain);
        $lines[] = "{$indent}@acme path /.well-known/acme-challenge/*";
        $lines[] = "{$indent}handle @acme {";
        $lines[] = "{$indent}    root * /var/www/acme-challenge";
        $lines[] = "{$indent}    file_server";
        $lines[] = "{$indent}}";
        $lines[] = "{$indent}handle {";
        $lines[] = "{$indent}    redir {$redirectUrl}";
        $lines[] = "{$indent}}";
        $lines[] = '}';

        return $lines;
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
            $directives = $domain->custom_caddy_directives;

            // Defense-in-depth: block dangerous patterns even if validation was bypassed
            $blocked = ['import', '{env.', '{system.', 'exec', '{http.vars.'];
            $lower = strtolower($directives);
            foreach ($blocked as $pattern) {
                if (str_contains($lower, $pattern)) {
                    Log::warning("Blocked dangerous Caddy directive for {$fqdn}: contains '{$pattern}'");

                    return ["{$indent}# Custom directives blocked due to security policy"];
                }
            }

            $lines = [];
            foreach (explode("\n", $directives) as $line) {
                $trimmed = rtrim($line);
                $lines[] = $trimmed !== '' ? "{$indent}{$trimmed}" : '';
            }

            return $lines;
        }

        $lines = [];
        $isLegacy = $domain->type === DomainType::ApacheReverseProxy;

        // Docker service bindings (handle_path routes before main handler)
        $dockerBindingLines = $this->renderDockerServiceBindings($domain, $indent);
        $hasDockerBindings = ! empty($dockerBindingLines);

        if ($hasDockerBindings) {
            $lines = array_merge($lines, $dockerBindingLines);

            // Wrap main handler in handle block for mutual exclusion with handle_path blocks
            $lines[] = "{$indent}handle {";
            $hi = "{$indent}    ";

            if (! $isLegacy) {
                $lines[] = "{$hi}root * {$rootPath}";
            }

            $lines = array_merge($lines, $this->renderMainHandler($domain, $fqdn, $rootPath, $hi));

            if (! $isLegacy) {
                $lines[] = "{$hi}file_server";
            }

            $lines[] = "{$indent}}";
        } else {
            // No Docker bindings — flat directives
            if (! $isLegacy) {
                $lines[] = "{$indent}root * {$rootPath}";
            }

            $lines = array_merge($lines, $this->renderMainHandler($domain, $fqdn, $rootPath, $indent));

            if (! $isLegacy) {
                $lines[] = "{$indent}file_server";
            }
        }

        return $lines;
    }

    /**
     * Wrap server directives with IP access control if configured.
     *
     * Supports both domain-wide rules (path='') and path-scoped rules.
     *
     * @param  array<int, string>  $serverDirectives
     * @return array<int, string>
     */
    private function wrapWithIpAccessControl(Domain $domain, array $serverDirectives, string $indent): array
    {
        $domain->loadMissing('ipRules');
        $rules = $domain->ipRules;

        if ($domain->ip_access_mode === null || $domain->ip_access_mode === IpAccessMode::None || $rules->isEmpty()) {
            return $serverDirectives;
        }

        $globalIps = $rules->where('path', '')->pluck('ip_address')->values()->all();
        $pathGroups = $rules->where('path', '!=', '')->groupBy('path');

        if ($domain->ip_access_mode === IpAccessMode::Whitelist) {
            return $this->buildWhitelistConfig($serverDirectives, $indent, $globalIps, $pathGroups);
        }

        return $this->buildBlacklistConfig($serverDirectives, $indent, $globalIps, $pathGroups);
    }

    /**
     * Build Caddy whitelist config with path-scoped and global rules.
     *
     * @param  array<int, string>  $serverDirectives
     * @param  array<int, string>  $globalIps
     * @param  Collection<string, Collection<int, DomainIpRule>>  $pathGroups
     * @return array<int, string>
     */
    private function buildWhitelistConfig(array $serverDirectives, string $indent, array $globalIps, $pathGroups): array
    {
        $lines = [];
        $counter = 0;

        foreach ($pathGroups as $path => $rules) {
            $counter++;
            $pathIps = $rules->pluck('ip_address')->all();
            $allAllowed = array_values(array_unique(array_merge($globalIps, $pathIps)));
            $ipList = implode(' ', $allAllowed);

            $lines[] = "{$indent}@path_allow_{$counter} {";
            $lines[] = "{$indent}    path {$path}";
            $lines[] = "{$indent}    client_ip {$ipList}";
            $lines[] = "{$indent}}";
            $lines[] = "{$indent}handle @path_allow_{$counter} {";
            foreach ($serverDirectives as $directive) {
                $lines[] = $directive !== '' ? "    {$directive}" : '';
            }
            $lines[] = "{$indent}}";

            $lines[] = "{$indent}@path_deny_{$counter} path {$path}";
            $lines[] = "{$indent}handle @path_deny_{$counter} {";
            $lines[] = "{$indent}    respond \"Access denied\" 403";
            $lines[] = "{$indent}}";
        }

        if (! empty($globalIps)) {
            $ipList = implode(' ', $globalIps);
            $lines[] = "{$indent}@allowed client_ip {$ipList}";
            $lines[] = "{$indent}handle @allowed {";
            foreach ($serverDirectives as $directive) {
                $lines[] = $directive !== '' ? "    {$directive}" : '';
            }
            $lines[] = "{$indent}}";
            $lines[] = "{$indent}handle {";
            $lines[] = "{$indent}    respond \"Access denied\" 403";
            $lines[] = "{$indent}}";
        } else {
            $lines = array_merge($lines, $serverDirectives);
        }

        return $lines;
    }

    /**
     * Build Caddy blacklist config with path-scoped and global rules.
     *
     * @param  array<int, string>  $serverDirectives
     * @param  array<int, string>  $globalIps
     * @param  Collection<string, Collection<int, DomainIpRule>>  $pathGroups
     * @return array<int, string>
     */
    private function buildBlacklistConfig(array $serverDirectives, string $indent, array $globalIps, $pathGroups): array
    {
        $lines = [];
        $counter = 0;

        foreach ($pathGroups as $path => $rules) {
            $counter++;
            $pathIps = $rules->pluck('ip_address')->all();
            $ipList = implode(' ', $pathIps);

            $lines[] = "{$indent}@path_block_{$counter} {";
            $lines[] = "{$indent}    path {$path}";
            $lines[] = "{$indent}    client_ip {$ipList}";
            $lines[] = "{$indent}}";
            $lines[] = "{$indent}handle @path_block_{$counter} {";
            $lines[] = "{$indent}    respond \"Access denied\" 403";
            $lines[] = "{$indent}}";
        }

        if (! empty($globalIps)) {
            $ipList = implode(' ', $globalIps);
            $lines[] = "{$indent}@blocked client_ip {$ipList}";
            $lines[] = "{$indent}handle @blocked {";
            $lines[] = "{$indent}    respond \"Access denied\" 403";
            $lines[] = "{$indent}}";
        }

        return array_merge($lines, $serverDirectives);
    }

    /**
     * Render the main application handler (php_server or reverse_proxy to Apache).
     *
     * @return array<int, string>
     */
    private function renderMainHandler(Domain $domain, string $fqdn, string $rootPath, string $indent): array
    {
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
     * Render reverse proxy directives for Docker service bindings.
     *
     * @return array<int, string>
     */
    private function renderDockerServiceBindings(Domain $domain, string $indent): array
    {
        $bindings = $domain->dockerServiceBindings()
            ->with('dockerService')
            ->get();

        if ($bindings->isEmpty()) {
            return [];
        }

        $lines = [];

        foreach ($bindings as $binding) {
            $service = $binding->dockerService;
            if (! $service) {
                continue;
            }

            $containerName = $service->name;
            $port = $binding->container_port;
            $prefix = $binding->path_prefix;

            if ($prefix) {
                // Redirect exact path to include trailing slash
                $lines[] = "{$indent}redir {$prefix} {$prefix}/ 308";
                // Path-prefix based routing
                $lines[] = "{$indent}handle_path {$prefix}/* {";
                $lines[] = "{$indent}    reverse_proxy http://{$containerName}:{$port} {";
                $lines[] = "{$indent}        header_up Host {upstream_hostport}";
                $lines[] = "{$indent}        header_up X-Forwarded-For {client_ip}";
                $lines[] = "{$indent}        header_up X-Real-IP {client_ip}";
                $lines[] = "{$indent}        header_up X-Forwarded-Proto {scheme}";
                $lines[] = "{$indent}    }";
                $lines[] = "{$indent}}";
            } else {
                // Root-level reverse proxy (entire domain proxied to service)
                $lines[] = "{$indent}reverse_proxy http://{$containerName}:{$port} {";
                $lines[] = "{$indent}    header_up Host {upstream_hostport}";
                $lines[] = "{$indent}    header_up X-Forwarded-For {client_ip}";
                $lines[] = "{$indent}    header_up X-Real-IP {client_ip}";
                $lines[] = "{$indent}    header_up X-Forwarded-Proto {scheme}";
                $lines[] = "{$indent}}";
            }
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
        $poolUser = $domain->getEffectiveFtpUsername();
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
        $poolUser = $domain->getEffectiveFtpUsername();

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

        $this->writeUserIni($domain);
    }

    /**
     * Write a .user.ini with open_basedir restriction to the domain's web root.
     * The file is owned by root and made immutable with chattr +i so site owners cannot modify or delete it.
     */
    public function writeUserIni(Domain $domain): void
    {
        $iniPath = escapeshellarg("{$domain->getWebRootPath()}/.user.ini");
        $openBasedir = implode(':', [$domain->getBasePath(), '/tmp', '/dev/urandom']);

        $container = $domain->type === DomainType::CaddyWebServer
            ? 'frankenphp'
            : 'php-code-server';

        $portainer = app(PortainerService::class);

        // Unlock if already immutable (ignore errors for new domains)
        $portainer->execInContainer($container, [
            'sh', '-c', "chattr -i {$iniPath} 2>/dev/null || true",
        ]);

        // Write the .user.ini file — use printf to avoid literal newline issues in shell
        $result = $portainer->execInContainer($container, [
            'sh', '-c', "printf '; AlphaPanel -- DO NOT MODIFY\nopen_basedir = {$openBasedir}\n' > {$iniPath}",
        ]);

        if (! $result->isSuccessful()) {
            Log::error("Failed to write .user.ini for {$domain->fqdn}: {$result->errorOutput} {$result->output}");

            return;
        }

        // Set ownership to root and make read-only, then lock immutable
        $portainer->execInContainer($container, [
            'sh', '-c', "chown root:root {$iniPath} && chmod 444 {$iniPath} && chattr +i {$iniPath} 2>/dev/null || true",
        ]);

        Log::info("Wrote .user.ini for {$domain->fqdn} (open_basedir: {$openBasedir})");
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
