<?php

declare(strict_types=1);

namespace App\Services\Acme;

use App\Models\AcmeSetting;
use App\Models\Domain;
use App\Services\CloudflareDnsService;
use App\Services\LocalDnsService;
use App\Services\PortainerService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Rogierw\RwAcme\Api;
use Rogierw\RwAcme\DTO\AccountData;
use Rogierw\RwAcme\DTO\OrderData;
use Rogierw\RwAcme\Enums\AuthorizationChallengeEnum;

class AcmeService
{
    private ?Api $client = null;

    private ?DatabaseAcmeAccount $accountAdapter = null;

    public function __construct(
        private CloudflareDnsService $cloudflareDns,
        private LocalDnsService $localDns,
        private PortainerService $portainer,
    ) {}

    /**
     * Get or create the ACME API client.
     */
    public function getClient(): Api
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $settings = $this->getSettings();
        $serverUrl = $settings['staging']
            ? $settings['staging_server_url']
            : $settings['server_url'];

        // The rw-acme-client library appends '/directory' automatically,
        // so baseUrl must be the host without the trailing /directory path.
        $baseUrl = rtrim($serverUrl, '/');
        if (str_ends_with($baseUrl, '/directory')) {
            $baseUrl = substr($baseUrl, 0, -strlen('/directory'));
        }

        $this->accountAdapter = new DatabaseAcmeAccount($serverUrl);

        $this->client = new Api(
            staging: $settings['staging'],
            localAccount: $this->accountAdapter,
            baseUrl: $baseUrl,
        );

        // Attach Laravel's logger so rw-acme-client's internal ACME flow logs
        // (challenge status checks, validation attempts, HTTP responses) land
        // in laravel.log. Without this, HTTP-01 failures are opaque black boxes.
        $this->client->setLogger(Log::channel(config('logging.default')));

        return $this->client;
    }

    /**
     * Ensure ACME account exists, create if needed.
     */
    private function ensureAccount(): AccountData
    {
        $client = $this->getClient();
        $settings = $this->getSettings();

        if ($client->account()->exists()) {
            return $client->account()->get();
        }

        Log::info('Creating new ACME account for '.($settings['staging'] ? 'staging' : 'production').'.');
        $accountData = $client->account()->create();

        // Store the account URL
        $this->accountAdapter?->storeAccountUrl($accountData->url, $settings['email']);

        return $accountData;
    }

    /**
     * Request a wildcard certificate using Cloudflare DNS-01 validation.
     */
    public function requestCertificateDnsCloudflare(Domain $domain, ?callable $onProgress = null): AcmeResult
    {
        $fqdn = $domain->fqdn;
        // Wildcards only make sense for apex domains — for subdomains we
        // request a single-host cert and write the ACME TXT into the apex zone.
        $domains = $domain->isSubdomain() ? [$fqdn] : [$fqdn, "*.{$fqdn}"];
        $apex = $domain->getApexDomain();

        Log::info("Requesting DNS-01 (Cloudflare) certificate for {$fqdn} via apex zone {$apex}.");

        return $this->performDns01Challenge(
            domain: $domain,
            domains: $domains,
            createTxtRecord: function (string $recordName, string $recordValue) use ($apex) {
                $zoneId = $this->cloudflareDns->getZoneId($apex);

                // Remove any stale TXT records with the same name left by a prior
                // failed attempt; Cloudflare rejects duplicates with error 81058.
                $existing = $this->cloudflareDns->listRecords($zoneId, $recordName);
                foreach ($existing as $record) {
                    if (strtoupper((string) ($record->type ?? '')) === 'TXT') {
                        $this->cloudflareDns->deleteRecord($zoneId, (string) ($record->id ?? ''));
                    }
                }

                $this->cloudflareDns->addRecord($zoneId, [
                    'type' => 'TXT',
                    'name' => $recordName,
                    'content' => $recordValue,
                    'ttl' => 60,
                ]);

                return ['zone_id' => $zoneId, 'name' => $recordName, 'value' => $recordValue];
            },
            deleteTxtRecord: function (array $context) {
                $records = $this->cloudflareDns->listRecords($context['zone_id'], $context['name']);
                foreach ($records as $record) {
                    if (strtoupper((string) ($record->type ?? '')) === 'TXT' && ($record->content ?? '') === $context['value']) {
                        $this->cloudflareDns->deleteRecord($context['zone_id'], (string) ($record->id ?? ''));
                    }
                }
            },
            propagationWait: $this->getSettings()['dns_propagation_wait'],
            onProgress: $onProgress,
            pollTimeout: $this->getSettings()['poll_timeout'],
        );
    }

    /**
     * Request a wildcard certificate using local PowerDNS DNS-01 validation.
     */
    public function requestCertificateDnsLocal(Domain $domain, ?callable $onProgress = null): AcmeResult
    {
        $fqdn = $domain->fqdn;
        // Wildcards only make sense for apex domains — for subdomains we
        // request a single-host cert and write the ACME TXT into the apex zone.
        $domains = $domain->isSubdomain() ? [$fqdn] : [$fqdn, "*.{$fqdn}"];

        $zoneOwner = $domain->isSubdomain()
            ? Domain::where('fqdn', $domain->getApexDomain())->first()
            : $domain;
        $zone = $zoneOwner?->dnsZone;

        Log::info("Requesting DNS-01 (Local PowerDNS) certificate for {$fqdn} via zone {$zoneOwner?->fqdn}.");

        if (! $zone) {
            return AcmeResult::failure("No local DNS zone found for {$domain->getApexDomain()}. Create a DNS zone first.");
        }

        return $this->performDns01Challenge(
            domain: $domain,
            domains: $domains,
            createTxtRecord: function (string $recordName, string $recordValue) use ($zone) {
                $record = $this->localDns->addRecord($zone, [
                    'name' => $recordName,
                    'type' => 'TXT',
                    'content' => $recordValue,
                    'ttl' => 60,
                ]);

                return ['record' => $record];
            },
            deleteTxtRecord: function (array $context) {
                if (isset($context['record'])) {
                    $this->localDns->deleteRecord($context['record']);
                }
            },
            propagationWait: $this->getSettings()['local_dns_wait'],
            onProgress: $onProgress,
            pollTimeout: $this->getSettings()['poll_timeout'],
        );
    }

    /**
     * Request a certificate using HTTP-01 webroot validation.
     * HTTP-01 cannot issue wildcard certificates.
     */
    public function requestCertificateHttp(Domain $domain, ?callable $onProgress = null): AcmeResult
    {
        $fqdn = $domain->fqdn;
        $domains = [$fqdn];

        if ($domain->enable_www_redirect && ! str_starts_with($fqdn, 'www.')) {
            $domains[] = "www.{$fqdn}";
        }

        Log::info("Requesting HTTP-01 (webroot) certificate for {$fqdn}.", [
            'identifiers' => $domains,
            'enable_www_redirect' => $domain->enable_www_redirect,
        ]);

        $settings = $this->getSettings();
        $webrootPath = $this->normalizeWebrootPath($settings['webroot_path']);

        try {
            $client = $this->getClient();
            $accountData = $this->ensureAccount();

            if ($onProgress) {
                $onProgress(20, __('Creating certificate order...'));
            }

            $order = $client->order()->new($accountData, $domains);

            Log::info("ACME order created for {$fqdn}.", [
                'order_id' => $order->id ?? null,
                'status' => $order->status ?? null,
                'validation_urls' => $order->domainValidationUrls ?? [],
            ]);

            if ($onProgress) {
                $onProgress(30, __('Setting up HTTP challenge files...'));
            }
            $challengeFiles = [];

            $validations = $client->domainValidation()->status($order);
            $httpAuths = $client->domainValidation()->getValidationData($validations, AuthorizationChallengeEnum::HTTP);

            Log::info("HTTP-01 challenge setup for {$fqdn}.", [
                'webroot_path' => $webrootPath,
                'auth_count' => count($httpAuths),
                'identifiers' => array_map(fn ($a) => $a['identifier'] ?? null, $httpAuths),
            ]);

            foreach ($httpAuths as $auth) {
                $challengePath = "{$webrootPath}/.well-known/acme-challenge";
                if (! File::isDirectory($challengePath)) {
                    File::makeDirectory($challengePath, 0755, true);
                }

                $filePath = "{$challengePath}/{$auth['filename']}";
                File::put($filePath, $auth['content']);
                // Ensure Caddy (potentially different UID in frankenphp container)
                // can read the challenge file via the shared bind mount.
                @chmod($filePath, 0644);
                $challengeFiles[] = $filePath;

                Log::info("HTTP-01 challenge file written for {$auth['identifier']}.", [
                    'path' => $filePath,
                    'exists' => File::exists($filePath),
                    'size' => File::exists($filePath) ? File::size($filePath) : null,
                    'perms' => File::exists($filePath) ? substr(sprintf('%o', fileperms($filePath)), -4) : null,
                    'content_preview' => substr($auth['content'], 0, 20).'...',
                ]);
            }

            // Start validation for each domain
            if ($onProgress) {
                $onProgress(40, __('Validating domain ownership via HTTP...'));
            }
            foreach ($validations as $validation) {
                if (! empty($validation->file)) {
                    $identifier = $validation->identifier['value'] ?? 'unknown';
                    Log::info("Triggering HTTP-01 validation for {$identifier}.");
                    $startResponse = $client->domainValidation()->start($accountData, $validation, AuthorizationChallengeEnum::HTTP, false);
                    Log::info("HTTP-01 start() response for {$identifier}.", [
                        'http_code' => $startResponse->getHttpResponseCode(),
                        'body' => $startResponse->getBody(),
                    ]);
                }
            }

            // Poll for all challenges to pass
            if ($onProgress) {
                $onProgress(55, __('Waiting for validation...'));
            }
            $pollTimeout = $this->getSettings()['poll_timeout'];
            if (! $this->pollUntilChallengesPassed($client, $order, $pollTimeout)) {
                // Capture final authorization state so we can see WHY validation
                // failed (which identifier, what error from Let's Encrypt).
                $finalStatus = $client->domainValidation()->status($order);
                foreach ($finalStatus as $s) {
                    Log::error("HTTP-01 final status for {$fqdn}.", [
                        'identifier' => $s->identifier ?? null,
                        'status' => $s->status ?? null,
                        'file' => $s->file ?? null,
                        'expires' => $s->expires ?? null,
                    ]);
                }

                $this->cleanupFiles($challengeFiles);

                return AcmeResult::failure('HTTP-01 domain validation failed. Ensure the domain is publicly accessible.');
            }

            // Refresh order status
            $order = $client->order()->get($order->id);

            // Finalize
            if ($onProgress) {
                $onProgress(70, __('Finalizing certificate...'));
            }
            $result = $this->finalizeOrder($order, $domains);

            $this->cleanupFiles($challengeFiles);

            Log::info("HTTP-01 certificate obtained for {$fqdn}.");

            return $result;
        } catch (\Throwable $e) {
            Log::error("HTTP-01 certificate request failed for {$fqdn}: {$e->getMessage()}");

            return AcmeResult::failure($e->getMessage());
        }
    }

    /**
     * Normalize the ACME webroot base path.
     *
     * The base path MUST point at the directory Caddy serves as the root for
     * `/.well-known/acme-challenge/*` (hard-coded to `/var/www/acme-challenge`
     * in DomainConfigService::renderAcmeChallengePath). This method strips any
     * trailing `.well-known/acme-challenge` subpath so a kirli setting like
     * `/var/www/html/.well-known/acme-challenge` does not cause the writer to
     * double-nest the path (which silently misses the bind-mounted directory
     * Caddy is reading from, producing 404s for every HTTP-01 challenge).
     */
    private function normalizeWebrootPath(?string $path): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return '/var/www/acme-challenge';
        }

        $path = rtrim($path, '/');
        $path = preg_replace('#/\.well-known/acme-challenge/?$#', '', $path);
        $path = rtrim((string) $path, '/');

        return $path === '' ? '/var/www/acme-challenge' : $path;
    }

    /**
     * Generate a self-signed certificate.
     */
    public function generateSelfSigned(Domain $domain): AcmeResult
    {
        $fqdn = $domain->fqdn;
        $settings = $this->getSettings();
        $keyArgs = $this->resolveKeyArgs($settings['key_type'], $settings['key_length']);

        Log::info("Generating self-signed certificate for {$fqdn}.");

        $tmpDir = sys_get_temp_dir().'/selfsigned_'.uniqid();

        try {
            File::makeDirectory($tmpDir, 0700, true);

            $keyPath = "{$tmpDir}/privkey.pem";
            $certPath = "{$tmpDir}/fullchain.pem";

            $command = implode(' ', [
                'openssl req -x509 -nodes',
                '-days 365',
                ...$keyArgs,
                '-keyout', escapeshellarg($keyPath),
                '-out', escapeshellarg($certPath),
                '-subj', escapeshellarg("/CN={$fqdn}"),
                '-addext', escapeshellarg("subjectAltName=DNS:{$fqdn},DNS:*.{$fqdn}"),
            ]);

            $result = Process::timeout(30)->run($command);

            if (! $result->successful()) {
                Log::error("Self-signed cert generation failed for {$fqdn}: {$result->errorOutput()}");

                return AcmeResult::failure("Self-signed certificate generation failed: {$result->errorOutput()}");
            }

            $fullchainPem = File::get($certPath);
            $privateKeyPem = File::get($keyPath);

            Log::info("Self-signed certificate generated for {$fqdn}.");

            return AcmeResult::success($fullchainPem, $privateKeyPem);
        } catch (\Throwable $e) {
            Log::error("Self-signed cert exception for {$fqdn}: {$e->getMessage()}");

            return AcmeResult::failure($e->getMessage());
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }

    /**
     * Ensure the panel default self-signed certificate exists on disk.
     *
     * This certificate is used as a last-resort TLS fallback so Caddy can always
     * serve HTTPS for a newly added domain — even before a domain-specific cert
     * exists or if self-signed generation fails. Browsers will show a name
     * mismatch warning, but no SSL protocol error.
     *
     * Idempotent: regenerates only if the cert is missing or has < 30 days left.
     *
     * @return array{cert_path: string, key_path: string}
     */
    public function ensurePanelDefaultSelfSigned(): array
    {
        $dir = (string) config('panel.panel_default_cert_dir', '/etc/letsencrypt/selfsigned/_panel_default');
        $certPath = "{$dir}/fullchain.pem";
        $keyPath = "{$dir}/privkey.pem";

        if ($this->isDefaultCertFresh($certPath, $keyPath)) {
            return ['cert_path' => $certPath, 'key_path' => $keyPath];
        }

        Log::info("Generating panel default self-signed certificate at {$dir}.");

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $settings = $this->getSettings();
        $keyArgs = $this->resolveKeyArgs($settings['key_type'], $settings['key_length']);

        $command = implode(' ', [
            'openssl req -x509 -nodes',
            '-days 3650',
            ...$keyArgs,
            '-keyout', escapeshellarg($keyPath),
            '-out', escapeshellarg($certPath),
            '-subj', escapeshellarg('/CN=alphapanel.local'),
            '-addext', escapeshellarg('subjectAltName=DNS:alphapanel.local,DNS:*.alphapanel.local,DNS:localhost'),
        ]);

        $result = Process::timeout(30)->run($command);

        if (! $result->successful()) {
            Log::error("Panel default self-signed generation failed: {$result->errorOutput()}");
            throw new \RuntimeException("Panel default self-signed generation failed: {$result->errorOutput()}");
        }

        File::chmod($keyPath, 0600);

        Log::info('Panel default self-signed certificate generated.');

        return ['cert_path' => $certPath, 'key_path' => $keyPath];
    }

    /**
     * Check whether the panel default certificate exists and has > 30 days of validity.
     */
    private function isDefaultCertFresh(string $certPath, string $keyPath): bool
    {
        if (! File::exists($certPath) || ! File::exists($keyPath)) {
            return false;
        }

        try {
            $pem = File::get($certPath);
            $parsed = openssl_x509_parse($pem);

            if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
                return false;
            }

            $daysLeft = (int) floor(((int) $parsed['validTo_time_t'] - time()) / 86400);

            return $daysLeft > 30;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Delete stale Caddy ACME lock files for a domain.
     */
    public function clearCaddyAcmeLocks(Domain $domain): void
    {
        $fqdn = $domain->fqdn;
        $container = (string) config('panel.frankenphp_container', 'frankenphp');

        try {
            $result = $this->portainer->execInContainer($container, [
                '/bin/sh', '-c',
                "find /data/caddy/locks -name '*{$fqdn}*' -type f -delete 2>/dev/null; true",
            ], 15);

            if ($result->isSuccessful()) {
                Log::info("Cleared Caddy ACME lock files for {$fqdn}.");
            } else {
                Log::warning("Could not clear Caddy ACME lock files for {$fqdn}: {$result->errorOutput}");
            }
        } catch (\Exception $e) {
            Log::warning("Could not clear Caddy ACME lock files for {$fqdn}: {$e->getMessage()}");
        }
    }

    /**
     * Perform a DNS-01 ACME challenge.
     */
    private function performDns01Challenge(
        Domain $domain,
        array $domains,
        callable $createTxtRecord,
        callable $deleteTxtRecord,
        int $propagationWait,
        ?callable $onProgress = null,
        int $pollTimeout = 300,
    ): AcmeResult {
        $fqdn = $domain->fqdn;
        $createdRecords = [];

        try {
            $client = $this->getClient();
            $accountData = $this->ensureAccount();

            if ($onProgress) {
                $onProgress(20, __('Creating certificate order...'));
            }

            $order = $client->order()->new($accountData, $domains);

            if ($onProgress) {
                $onProgress(30, __('Setting DNS challenge records...'));
            }

            $validations = $client->domainValidation()->status($order);
            $dnsAuths = $client->domainValidation()->getValidationData($validations, AuthorizationChallengeEnum::DNS);

            foreach ($dnsAuths as $auth) {
                $recordName = $auth['name'].'.'.$auth['identifier'];
                $recordValue = $auth['value'];

                $context = $createTxtRecord($recordName, $recordValue);
                $createdRecords[] = ['context' => $context, 'callback' => $deleteTxtRecord];
            }

            // Wait for DNS propagation
            if ($onProgress) {
                $onProgress(40, __('Waiting for DNS propagation (:seconds seconds)...', ['seconds' => $propagationWait]));
            }
            sleep($propagationWait);

            // Start validation for each domain
            if ($onProgress) {
                $onProgress(55, __('Validating domain ownership...'));
            }
            foreach ($validations as $validation) {
                if (! empty($validation->dns)) {
                    $client->domainValidation()->start($accountData, $validation, AuthorizationChallengeEnum::DNS, false);
                }
            }

            // Poll for all challenges to pass, respecting poll_timeout setting.
            // The library's allChallengesPassed() is hardcoded to 4 attempts;
            // we implement our own loop so poll_timeout is actually honoured.
            if (! $this->pollUntilChallengesPassed($client, $order, $pollTimeout)) {
                $this->cleanupDnsRecords($createdRecords);

                return AcmeResult::failure('DNS-01 domain validation failed. Check DNS records and propagation.');
            }

            // Refresh order status
            $order = $client->order()->get($order->id);

            // Finalize
            if ($onProgress) {
                $onProgress(70, __('Finalizing certificate...'));
            }
            $result = $this->finalizeOrder($order, $domains);

            // Defer TXT record cleanup: the job will call runCleanup() after the
            // certificate is written to disk and Caddy reloaded, so LE's resolvers
            // can still see the records if anything retries during finalization.
            $captured = $createdRecords;
            $result->withCleanup(function () use ($captured): void {
                $this->cleanupDnsRecords($captured);
            });

            Log::info("DNS-01 certificate obtained for {$fqdn}.");

            return $result;
        } catch (\Throwable $e) {
            $this->cleanupDnsRecords($createdRecords);
            Log::error("DNS-01 certificate request failed for {$fqdn}: {$e->getMessage()}");

            return AcmeResult::failure($e->getMessage());
        }
    }

    /**
     * Finalize the ACME order: generate key + CSR, submit, download certificate.
     */
    private function finalizeOrder(OrderData $order, array $domains): AcmeResult
    {
        $client = $this->getClient();
        $settings = $this->getSettings();

        // Generate private key for the certificate
        $keyPem = $this->generatePrivateKey($settings['key_type'], $settings['key_length']);

        // Generate CSR
        $csrPem = $this->generateCsr($keyPem, $domains);

        // Finalize order with CSR
        $finalized = $client->order()->finalize($order, $csrPem);

        if (! $finalized) {
            return AcmeResult::failure("Order finalization failed. Order status: {$order->status}");
        }

        // Re-fetch order to get certificate URL
        $order = $client->order()->get($order->id);

        if (! $order->isFinalized()) {
            // Poll until valid
            $attempts = 0;
            while (! $order->isFinalized() && $attempts < 20) {
                sleep(3);
                $order = $client->order()->get($order->id);
                $attempts++;
            }

            if (! $order->isFinalized()) {
                return AcmeResult::failure("Order not finalized after polling. Status: {$order->status}");
            }
        }

        // Download certificate bundle
        $bundle = $client->certificate()->getBundle($order);

        if (! $bundle->fullchain) {
            return AcmeResult::failure('Failed to download certificate from ACME server.');
        }

        $isStaging = $settings['staging'];
        Log::info('Certificate obtained'.($isStaging ? ' (STAGING)' : ''));

        return AcmeResult::success($bundle->fullchain, $keyPem);
    }

    /**
     * Generate a private key based on configured type and length.
     */
    private function generatePrivateKey(string $keyType, string $keyLength): string
    {
        $config = match (strtoupper($keyType)) {
            'EC' => [
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => match ($keyLength) {
                    'P-256', 'prime256v1' => 'prime256v1',
                    'P-384', 'secp384r1' => 'secp384r1',
                    default => 'secp384r1',
                },
            ],
            default => [
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'private_key_bits' => (int) $keyLength ?: 2048,
            ],
        };

        $key = openssl_pkey_new($config);

        if (! $key) {
            throw new \RuntimeException('Failed to generate private key: '.openssl_error_string());
        }

        openssl_pkey_export($key, $pem);

        return $pem;
    }

    /**
     * Generate a CSR for the given domains.
     */
    private function generateCsr(string $privateKeyPem, array $domains): string
    {
        $primaryDomain = $domains[0];

        $tmpDir = sys_get_temp_dir().'/acme_csr_'.uniqid();
        File::makeDirectory($tmpDir, 0700, true);

        try {
            $keyFile = "{$tmpDir}/key.pem";
            $csrFile = "{$tmpDir}/csr.pem";
            $configFile = "{$tmpDir}/openssl.cnf";

            File::put($keyFile, $privateKeyPem);

            $sanEntries = [];
            foreach ($domains as $i => $domain) {
                $sanEntries[] = 'DNS.'.($i + 1).' = '.$domain;
            }

            $config = implode("\n", [
                '[req]',
                'distinguished_name = req_distinguished_name',
                'req_extensions = v3_req',
                'prompt = no',
                '',
                '[req_distinguished_name]',
                "CN = {$primaryDomain}",
                '',
                '[v3_req]',
                'basicConstraints = CA:FALSE',
                'keyUsage = nonRepudiation, digitalSignature, keyEncipherment',
                'subjectAltName = @alt_names',
                '',
                '[alt_names]',
                ...array_values($sanEntries),
                '',
            ]);

            File::put($configFile, $config);

            $result = Process::timeout(15)->run(implode(' ', [
                'openssl req -new -sha256',
                '-key', escapeshellarg($keyFile),
                '-out', escapeshellarg($csrFile),
                '-config', escapeshellarg($configFile),
                '-reqexts v3_req',
            ]));

            if (! $result->successful()) {
                throw new \RuntimeException("CSR generation failed: {$result->errorOutput()}");
            }

            return File::get($csrFile);
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }

    /**
     * Get ACME settings from the database (with fallback to config).
     *
     * @return array{email: string, staging: bool, server_url: string, staging_server_url: string, key_type: string, key_length: string, dns_propagation_wait: int, local_dns_wait: int, poll_timeout: int, webroot_path: string, auto_renew_days: int}
     */
    private function getSettings(): array
    {
        try {
            $settings = AcmeSetting::instance();

            return [
                'email' => $settings->email ?: config('panel.certbot_email', ''),
                'staging' => $settings->staging,
                'server_url' => $settings->server_url ?: 'https://acme-v02.api.letsencrypt.org/directory',
                'staging_server_url' => $settings->staging_server_url ?: 'https://acme-staging-v02.api.letsencrypt.org/directory',
                'key_type' => $settings->key_type ?: 'EC',
                'key_length' => $settings->key_length ?: 'P-384',
                'dns_propagation_wait' => $settings->dns_propagation_wait ?: 60,
                'local_dns_wait' => $settings->local_dns_wait ?: 5,
                'poll_timeout' => $settings->poll_timeout ?: 300,
                'webroot_path' => $settings->webroot_path ?: '/var/www/acme-challenge',
                'auto_renew_days' => $settings->auto_renew_days ?: 30,
            ];
        } catch (\Throwable) {
            return [
                'email' => config('panel.certbot_email', ''),
                'staging' => config('panel.certbot_staging', false),
                'server_url' => 'https://acme-v02.api.letsencrypt.org/directory',
                'staging_server_url' => 'https://acme-staging-v02.api.letsencrypt.org/directory',
                'key_type' => 'EC',
                'key_length' => 'P-384',
                'dns_propagation_wait' => 60,
                'local_dns_wait' => 5,
                'poll_timeout' => 300,
                'webroot_path' => '/var/www/acme-challenge',
                'auto_renew_days' => 30,
            ];
        }
    }

    /**
     * Resolve openssl key generation arguments for self-signed certs.
     *
     * @return string[]
     */
    private function resolveKeyArgs(string $keyType, string $keyLength): array
    {
        return match (strtoupper($keyType)) {
            'EC' => match ($keyLength) {
                'P-256', 'prime256v1' => ['-newkey ec -pkeyopt ec_paramgen_curve:prime256v1'],
                'P-384', 'secp384r1' => ['-newkey ec -pkeyopt ec_paramgen_curve:secp384r1'],
                default => ['-newkey ec -pkeyopt ec_paramgen_curve:secp384r1'],
            },
            default => ['-newkey rsa:'.((int) $keyLength ?: 2048)],
        };
    }

    /**
     * Poll LE until all domain challenges pass or timeout is reached.
     * Replaces the library's allChallengesPassed() which is hardcoded to 4 retries.
     */
    private function pollUntilChallengesPassed(mixed $client, mixed $order, int $timeoutSeconds): bool
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $statuses = $client->domainValidation()->status($order);

            $allValid = true;
            foreach ($statuses as $status) {
                Log::info("Check {$status->identifier['type']} challenge of {$status->identifier['value']}.");
                if ($status->isInvalid()) {
                    if ($status->hasErrors()) {
                        Log::error("ACME validation error for {$status->identifier['value']}.", $status->getErrors());
                    }
                    return false;
                }
                if (! $status->isValid()) {
                    $allValid = false;
                }
            }

            if ($allValid) {
                return true;
            }

            Log::info('Challenge is not valid yet. Another attempt in 5 seconds.');
            sleep(5);
        }

        return false;
    }

    private function cleanupDnsRecords(array $records): void
    {
        foreach ($records as $record) {
            try {
                ($record['callback'])($record['context']);
            } catch (\Throwable $e) {
                Log::warning("Failed to cleanup DNS record: {$e->getMessage()}");
            }
        }
    }

    private function cleanupFiles(array $files): void
    {
        foreach ($files as $file) {
            try {
                File::delete($file);
            } catch (\Throwable $e) {
                Log::warning("Failed to cleanup challenge file: {$e->getMessage()}");
            }
        }
    }
}
