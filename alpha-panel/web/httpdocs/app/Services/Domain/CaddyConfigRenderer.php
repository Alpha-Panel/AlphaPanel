<?php

namespace App\Services\Domain;

use App\Enums\DomainType;
use App\Enums\IpAccessMode;
use App\Enums\SupervisorType;
use App\Models\Domain;
use App\Models\DomainIpRule;
use App\Rules\SafeCaddyDirectives;
use App\Services\SslCertificateService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CaddyConfigRenderer
{
    private string $caddySitesBasePath;

    private string $letsEncryptBasePath;

    private string $selfSignedBasePath;

    public function __construct(private DomainDirectoryService $directoryService)
    {
        $this->caddySitesBasePath = config('panel.caddy_sites_base');
        $this->letsEncryptBasePath = config('panel.letsencrypt_base');
        $this->selfSignedBasePath = config('panel.letsencrypt_selfsigned_base');
    }

    /**
     * Write or remove the webmail Caddyfile for mail.{fqdn} and webmail.{fqdn}.
     *
     * Created when mail_hosting=Local (Mailu). Removed otherwise.
     * Cert is resolved from the apex domain — a wildcard cert covers the subdomain.
     *
     * Both mail.{fqdn} and webmail.{fqdn} 308-redirect to the global Mailu hostname
     * (MAIL_HOSTNAME). Reverse-proxying the per-domain hostname directly causes a
     * redirect loop because Mailu's HOSTNAMES env only lists the global hostname,
     * so unknown hosts get bounced back to the canonical URL.
     */
    public function syncWebmailCaddyConfig(Domain $domain): void
    {
        $mailFqdn = 'mail.'.$domain->fqdn;
        $webmailFqdn = 'webmail.'.$domain->fqdn;
        $dir = "{$this->caddySitesBasePath}/{$mailFqdn}";
        $file = "{$dir}/Caddyfile";

        $globalHostname = (string) config('panel.mail.hostname');

        // Global hostname's directory is managed manually — never touch it.
        if ($mailFqdn === $globalHostname) {
            return;
        }

        // Remove when mail hosting is not local or no global hostname configured.
        if (! $domain->usesLocalMail() || $globalHostname === '') {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
                Log::info("Removed webmail Caddyfile directory: {$dir}");
            }

            return;
        }

        $certPaths = $this->resolveCertPaths($domain);
        $lines = [];

        if ($certPaths !== null) {
            $lines[] = "{$mailFqdn}:80, {$webmailFqdn}:80 {";
            $lines[] = "    redir https://{$globalHostname}{uri} 308";
            $lines[] = '}';
            $lines[] = '';
            $lines[] = "{$mailFqdn}:443, {$webmailFqdn}:443 {";
            $lines[] = "    tls {$certPaths['cert']} {$certPaths['key']}";
            $lines[] = "    redir https://{$globalHostname}{uri} 308";
            $lines[] = '}';
        } else {
            $lines[] = "{$mailFqdn}:80, {$webmailFqdn}:80 {";
            $lines[] = "    redir https://{$globalHostname}{uri} 308";
            $lines[] = '}';
        }

        $this->directoryService->writeConfigFile($file, implode("\n", $lines)."\n");
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
                // If the cert is owned by a different domain (e.g. a subdomain
                // inheriting an apex wildcard), resolve disk paths against the
                // owner so cert files are not duplicated per subdomain.
                $owner = $cert->domain_id === $domain->id ? $domain : ($cert->domain ?? $domain);
                $diskPaths = $sslService->getActiveCertDiskPaths($owner, $cert);

                if (! $diskPaths) {
                    // Files not on disk yet — write them under the owner's directory
                    $sslService->writeCertToDisk($owner, $cert);
                    $diskPaths = $sslService->getActiveCertDiskPaths($owner, $cert);
                }

                if ($diskPaths) {
                    return $diskPaths;
                }
            }
        }

        // Priority 2: For subdomains/wildcard-subdomains, check parent domain's active cert.
        // Use the FK relationship first so apex rename/recreation can't desync; fall back
        // to FQDN lookup only when the FK isn't set yet (legacy rows).
        if ($domain->hasParentLikeBehavior()) {
            $parentDomain = $domain->parentDomain
                ?: Domain::where('fqdn', $domain->getApexDomain())->first();
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

        // Priority 4: Panel default self-signed fallback.
        // Ensures Caddy can always serve HTTPS for any domain, even before a
        // domain-specific cert exists. Browsers will show a name mismatch
        // warning, but no SSL protocol error.
        $defaultDir = (string) config('panel.panel_default_cert_dir', '/etc/letsencrypt/selfsigned/_panel_default');
        $defaultCert = "{$defaultDir}/fullchain.pem";
        $defaultKey = "{$defaultDir}/privkey.pem";
        if (File::exists($defaultCert) && File::exists($defaultKey)) {
            return ['cert' => $defaultCert, 'key' => $defaultKey];
        }

        return null;
    }

    /**
     * Generate the per-vhost Caddyfile.
     *
     * Phase 1 (withTls=false): HTTP-only blocks serving the site on :80.
     * Phase 2 (withTls=true): Snippet definition + :80 redirects + :443 import.
     */
    public function writeCaddyConfig(Domain $domain, bool $withTls): void
    {
        $fqdn = $this->sanitizeConfigValue($domain->fqdn);

        if (in_array(strtolower($fqdn), array_map('strtolower', config('panel.system_reserved_domains', [])), true)) {
            Log::warning("Refusing to overwrite system-reserved domain Caddyfile: {$fqdn}");

            return;
        }
        $rootPath = $this->sanitizeConfigValue($domain->getWebRootPath());

        // Special rendering for wildcard and catch-all modes
        if ($domain->isWildcardSubdomain()) {
            $certPaths = $withTls ? $this->resolveCertPaths($domain) : null;
            $lines = $this->renderWildcardSubdomainConfig($domain, $fqdn, $rootPath, $certPaths);
            $content = implode("\n", $lines)."\n";
            $dir = $this->caddyDirFromFqdn($fqdn);
            $this->directoryService->writeConfigFile("{$this->caddySitesBasePath}/{$dir}/Caddyfile", $content);

            return;
        }

        if ($domain->isCatchall()) {
            $certPaths = $withTls ? $this->resolveCertPaths($domain) : null;
            $lines = $this->renderCatchallConfig($domain, $rootPath, $certPaths);
            $content = implode("\n", $lines)."\n";
            $this->directoryService->writeConfigFile("{$this->caddySitesBasePath}/wildcard/Caddyfile", $content);

            return;
        }

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
        $this->ensureCustomConf("{$this->caddySitesBasePath}/{$fqdn}");
        $this->directoryService->writeConfigFile("{$this->caddySitesBasePath}/{$fqdn}/Caddyfile", $content);
    }

    /**
     * Map an FQDN to its Caddy sites-enabled directory name.
     * Asterisks are not valid in filesystem paths, so wildcards get a safe name:
     *   *            -> wildcard
     *   *.example.com -> wildcard.example.com
     */
    public function caddyDirFromFqdn(string $fqdn): string
    {
        if ($fqdn === '*') {
            return 'wildcard';
        }

        if (str_starts_with($fqdn, '*.')) {
            return 'wildcard.'.substr($fqdn, 2);
        }

        return $fqdn;
    }

    /**
     * Get the absolute Caddy sites-enabled directory for a given FQDN.
     */
    public function caddySiteDirectoryPath(string $fqdn): string
    {
        return "{$this->caddySitesBasePath}/{$this->caddyDirFromFqdn($fqdn)}";
    }

    /**
     * Get the webmail Caddy directory path for a given FQDN.
     */
    public function caddyWebmailDirectoryPath(string $fqdn): string
    {
        return "{$this->caddySitesBasePath}/mail.{$fqdn}";
    }

    /**
     * Get the catch-all wildcard Caddy directory path.
     */
    public function caddyCatchallDirectoryPath(): string
    {
        return "{$this->caddySitesBasePath}/wildcard";
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
            $lines[] = '        format json';
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
            $hostname = $this->sanitizeConfigValue((string) $hostname);
            if ($hostname === '') {
                continue;
            }
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
        $lines[] = '    import custom.conf';
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

        $lines[] = '    import custom.conf';
        $lines[] = '}';

        // Additional :80 blocks for www and other hostnames so HTTP-01 validation
        // succeeds for every identifier included in the ACME order. Without these,
        // when AcmeService adds "www.{fqdn}" to the order (enable_www_redirect=true),
        // Let's Encrypt cannot validate www because Caddy has no :80 block for it.
        // Each extra block serves the ACME challenge path from the shared webroot
        // and returns a minimal placeholder for everything else.
        $extraHostnames = [];

        if ($domain->enable_www_redirect && ! str_starts_with($fqdn, 'www.')) {
            $extraHostnames[] = "www.{$fqdn}";
        }

        foreach (($domain->additional_hostnames ?? []) as $hostname) {
            $hostname = $this->sanitizeConfigValue((string) $hostname);
            if ($hostname !== '') {
                $extraHostnames[] = $hostname;
            }
        }

        foreach ($extraHostnames as $hostname) {
            $lines[] = '';
            $lines[] = "{$hostname}:80 {";
            $lines = array_merge($lines, $this->renderAcmeChallengePath('    '));
            $lines[] = '    respond 404';
            $lines[] = '}';
        }

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

        // Single specific origin — use exact match. Sanitize each origin so a
        // smuggled newline/brace/backtick can't escape the header directive or
        // the expression matcher (defense in depth on top of request validation).
        $origins = array_values(array_filter(array_map(
            fn (string $origin): string => $this->sanitizeConfigValue(trim($origin)),
            explode(',', $origins),
        ), static fn (string $origin): bool => $origin !== ''));

        if ($origins === []) {
            return $this->renderWildcardCors($indent);
        }

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
            "{$indent}handle /.well-known/acme-challenge/* {",
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
        // Log :80 traffic to the same per-domain file so HTTP-01 validation
        // requests from Let's Encrypt are captured for diagnostics.
        $lines[] = "{$indent}log {";
        $lines[] = "{$indent}    output file /var/log/caddy/{$domain->fqdn}.log";
        $lines[] = "{$indent}    format json";
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

            // Defense-in-depth: re-run the same denylist/allowlist used at the
            // validation layer so a directive that slipped past (legacy row,
            // direct DB write) is still refused before it reaches the config.
            if (! $this->customDirectivesAreSafe($directives)) {
                Log::warning("Blocked dangerous Caddy directives for {$fqdn} due to security policy");

                return ["{$indent}# Custom directives blocked due to security policy"];
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

        // Reverb WebSocket proxy — handle blocks for /app/* (WS) and /apps/* (HTTP API).
        // Caddy handle blocks are mutually exclusive, preventing php_server from matching
        // these paths even without an explicit matcher on the main handler.
        $reverbLines = $this->renderReverbProxyBlock($domain, $indent);
        $hasReverb = ! empty($reverbLines);
        if ($hasReverb) {
            $lines = array_merge($lines, $reverbLines);
            $lines[] = '';
        }

        // Docker service and project bindings (handle_path routes before main handler)
        $dockerBindingLines = array_merge(
            $this->renderDockerServiceBindings($domain, $indent),
            $this->renderDockerProjectBindings($domain, $indent),
        );
        $hasDockerBindings = ! empty($dockerBindingLines);

        if ($hasDockerBindings) {
            $lines = array_merge($lines, $dockerBindingLines);
        }

        if ($hasDockerBindings || $hasReverb) {
            // Wrap main handler in handle block for mutual exclusion with handle/handle_path blocks above.
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
            // No Docker bindings and no Reverb — flat directives
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
                $lines[] = "{$indent}        env MAX_REQUESTS ".($domain->worker_max_requests ?? 500);
                $lines[] = "{$indent}        max_consecutive_failures 10";
                if ($domain->worker_watch) {
                    $lines[] = "{$indent}        watch {$rootPath}";
                }
                $lines[] = "{$indent}    }";
                $lines[] = "{$indent}}";
            } else {
                $lines[] = "{$indent}php_server";
            }
        } elseif ($domain->type === DomainType::ApacheReverseProxy) {
            $forwardedPort = (int) ($domain->forwarded_port ?? 443);
            $lines[] = "{$indent}reverse_proxy http://php-code-server:80 {";
            $lines[] = "{$indent}    header_up Host {$fqdn}";
            $lines[] = "{$indent}    header_up X-Forwarded-Host {$fqdn}";
            $lines[] = "{$indent}    header_up X-Forwarded-Port {$forwardedPort}";
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
     * Render Caddy reverse_proxy block that forwards /app/* (WebSocket) and
     * /apps/* (HTTP broadcast API) to the per-site Reverb instance bound to
     * 127.0.0.1:{port} inside the frankenphp container. Returns an empty
     * array when the domain has never activated Reverb.
     *
     * @return array<int, string>
     */
    private function renderReverbProxyBlock(Domain $domain, string $indent): array
    {
        $domain->loadMissing('supervisors');

        $reverb = $domain->supervisors->firstWhere('type', SupervisorType::Reverb);

        if ($reverb === null || $reverb->reverb_port === null) {
            return [];
        }

        $port = (int) $reverb->reverb_port;
        $upstream = "http://127.0.0.1:{$port}";
        $in = "{$indent}    ";

        return [
            "{$indent}handle /app/* {",
            "{$in}reverse_proxy {$upstream} {",
            "{$in}    header_up Host {http.request.host}",
            "{$in}    header_up X-Real-IP {http.request.remote.host}",
            "{$in}    header_up X-Forwarded-For {http.request.remote.host}",
            "{$in}    header_up X-Forwarded-Proto {http.request.scheme}",
            "{$in}    header_up Upgrade {http.request.header.Upgrade}",
            "{$in}    header_up Connection {http.request.header.Connection}",
            "{$in}    transport http {",
            "{$in}        versions 1.1",
            "{$in}    }",
            "{$in}}",
            "{$indent}}",
            "{$indent}handle /apps/* {",
            "{$in}reverse_proxy {$upstream} {",
            "{$in}    header_up Host {http.request.host}",
            "{$in}    header_up X-Real-IP {http.request.remote.host}",
            "{$in}    header_up X-Forwarded-For {http.request.remote.host}",
            "{$in}    header_up X-Forwarded-Proto {http.request.scheme}",
            "{$in}}",
            "{$indent}}",
        ];
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
     * Render reverse proxy directives for Docker project domain bindings.
     *
     * @return array<int, string>
     */
    private function renderDockerProjectBindings(Domain $domain, string $indent): array
    {
        $bindings = $domain->dockerProjectBindings()
            ->with('dockerProject')
            ->get();

        if ($bindings->isEmpty()) {
            return [];
        }

        $lines = [];

        foreach ($bindings as $binding) {
            $project = $binding->dockerProject;
            if (! $project) {
                continue;
            }

            $containerName = $project->containerName($binding->service_name);
            $port = $binding->container_port;
            $prefix = $binding->path_prefix;

            if ($prefix) {
                $lines[] = "{$indent}redir {$prefix} {$prefix}/ 308";
                $lines[] = "{$indent}handle_path {$prefix}/* {";
                $lines[] = "{$indent}    reverse_proxy http://{$containerName}:{$port} {";
                $lines[] = "{$indent}        header_up Host {upstream_hostport}";
                $lines[] = "{$indent}        header_up X-Forwarded-For {client_ip}";
                $lines[] = "{$indent}        header_up X-Real-IP {client_ip}";
                $lines[] = "{$indent}        header_up X-Forwarded-Proto {scheme}";
                $lines[] = "{$indent}    }";
                $lines[] = "{$indent}}";
            } else {
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
     * @return array<int, string>
     */
    private function renderWildcardSubdomainConfig(Domain $domain, string $fqdn, string $rootPath, ?array $certPaths): array
    {
        $slug = str_replace(['.', '*'], ['-', 'wildcard'], $fqdn);
        $lines = [];

        if ($certPaths !== null) {
            // Single HTTPS block — wildcards can't use HTTP-01 so no :80 ACME challenge block
            $lines[] = "{$fqdn}:443 {";
            $this->appendCommonHeaderImports($lines, '    ', $domain);
            $lines[] = "    tls {$certPaths['cert']} {$certPaths['key']}";
            $lines[] = '    encode zstd br gzip';
            $serverDirectives = $this->renderServerDirectives($domain, $fqdn, $rootPath, '    ');
            $lines = array_merge($lines, $this->wrapWithIpAccessControl($domain, $serverDirectives, '    '));
            $lines[] = '    log {';
            $lines[] = "        output file /var/log/caddy/{$slug}.log";
            $lines[] = '        format json';
            $lines[] = '    }';
            $lines[] = '}';
        } else {
            // No cert — serve HTTP only
            $lines[] = "{$fqdn}:80 {";
            $serverDirectives = $this->renderServerDirectives($domain, $fqdn, $rootPath, '    ');
            $lines = array_merge($lines, $serverDirectives);
            $lines[] = '}';
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function renderCatchallConfig(Domain $domain, string $rootPath, ?array $certPaths): array
    {
        $lines = [];

        if ($certPaths !== null) {
            $lines[] = '* {';
            $this->appendCommonHeaderImports($lines, '    ', $domain);
            $lines[] = "    tls {$certPaths['cert']} {$certPaths['key']}";
            $lines[] = '    encode zstd br gzip';
            $serverDirectives = $this->renderServerDirectives($domain, 'catchall', $rootPath, '    ');
            $lines = array_merge($lines, $this->wrapWithIpAccessControl($domain, $serverDirectives, '    '));
            $lines[] = '    log {';
            $lines[] = '        output file /var/log/caddy/wildcard.log';
            $lines[] = '        format json';
            $lines[] = '    }';
            $lines[] = '}';
        } else {
            $lines[] = 'http:// {';
            $serverDirectives = $this->renderServerDirectives($domain, 'catchall', $rootPath, '    ');
            $lines = array_merge($lines, $serverDirectives);
            $lines[] = '}';
        }

        return $lines;
    }

    /**
     * Strip characters that carry structural meaning in a Caddyfile from a
     * value that is interpolated into a server block (fqdn, hostname, root path,
     * CORS origin). Valid hostnames, paths and origins never contain newlines,
     * braces or backticks, so removing them is non-destructive for good input
     * while neutralising directive-injection attempts that slip past validation.
     */
    private function sanitizeConfigValue(string $value): string
    {
        $sanitized = preg_replace('/[\r\n\0{}`]/', '', $value);

        return is_string($sanitized) ? trim($sanitized) : '';
    }

    /**
     * Run a block of custom Caddy directives through the shared SafeCaddyDirectives
     * policy. Returns false if the policy reports any violation.
     */
    private function customDirectivesAreSafe(string $directives): bool
    {
        $failed = false;
        (new SafeCaddyDirectives(strict: false))->validate(
            'custom_caddy_directives',
            $directives,
            function () use (&$failed): void {
                $failed = true;
            },
        );

        return ! $failed;
    }

    /**
     * Create an empty custom.conf in the domain's Caddy directory if it doesn't exist.
     * This file is imported by the generated Caddyfile and is never overwritten by the panel.
     */
    private function ensureCustomConf(string $dir): void
    {
        $path = "{$dir}/custom.conf";

        if (! File::exists($path)) {
            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }

            File::put($path, "# Custom Caddy directives — imported into this domain's server block.\n# The panel never overwrites this file.\n");
        }
    }
}
