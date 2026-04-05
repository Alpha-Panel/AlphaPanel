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

class AcmeService
{
    private ?Api $client = null;

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

        $account = new DatabaseAcmeAccount($serverUrl);

        $this->client = new Api(
            account: $account,
            baseUrl: $serverUrl,
            email: $settings['email'],
        );

        return $this->client;
    }

    /**
     * Request a wildcard certificate using Cloudflare DNS-01 validation.
     */
    public function requestCertificateDnsCloudflare(Domain $domain, ?callable $onProgress = null): AcmeResult
    {
        $fqdn = $domain->fqdn;
        $domains = [$fqdn, "*.{$fqdn}"];

        Log::info("Requesting DNS-01 (Cloudflare) certificate for {$fqdn}.");

        return $this->performDns01Challenge(
            domain: $domain,
            domains: $domains,
            createTxtRecord: function (string $recordName, string $recordValue) use ($domain) {
                $zoneId = $this->cloudflareDns->getZoneId($domain->fqdn);
                $this->cloudflareDns->addRecord($zoneId, [
                    'type' => 'TXT',
                    'name' => $recordName,
                    'content' => $recordValue,
                    'ttl' => 60,
                ]);

                return ['zone_id' => $zoneId, 'name' => $recordName, 'value' => $recordValue];
            },
            deleteTxtRecord: function (array $context) {
                // Find and delete the TXT record
                $records = $this->cloudflareDns->listRecords($context['zone_id'], $context['name']);
                foreach ($records as $record) {
                    if (($record['type'] ?? '') === 'TXT' && ($record['content'] ?? '') === $context['value']) {
                        $this->cloudflareDns->deleteRecord($context['zone_id'], $record['id']);
                    }
                }
            },
            propagationWait: $this->getSettings()['dns_propagation_wait'],
            onProgress: $onProgress,
        );
    }

    /**
     * Request a wildcard certificate using local PowerDNS DNS-01 validation.
     */
    public function requestCertificateDnsLocal(Domain $domain, ?callable $onProgress = null): AcmeResult
    {
        $fqdn = $domain->fqdn;
        $domains = [$fqdn, "*.{$fqdn}"];

        Log::info("Requesting DNS-01 (Local PowerDNS) certificate for {$fqdn}.");

        $zone = $domain->dnsZone;
        if (! $zone) {
            return AcmeResult::failure("No local DNS zone found for {$fqdn}. Create a DNS zone first.");
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

        Log::info("Requesting HTTP-01 (webroot) certificate for {$fqdn}.");

        $settings = $this->getSettings();
        $webrootPath = $settings['webroot_path'];

        try {
            $client = $this->getClient();
            if ($onProgress) { $onProgress(20, __('Creating certificate order...')); }

            // Create ACME order
            $order = $client->order()->new($client->account()->url(), $domains);

            // Process HTTP-01 challenges
            if ($onProgress) { $onProgress(30, __('Setting up HTTP challenge files...')); }
            $challengeFiles = [];

            $authorizations = $client->domainValidation()->status($order);

            foreach ($authorizations as $authorization) {
                $challenge = null;
                foreach ($authorization['challenges'] as $ch) {
                    if ($ch['type'] === 'http-01') {
                        $challenge = $ch;
                        break;
                    }
                }

                if (! $challenge) {
                    return AcmeResult::failure("No HTTP-01 challenge available for authorization.");
                }

                // Write challenge file
                $token = $challenge['token'];
                $keyAuthorization = $client->domainValidation()->getKeyAuthorization($challenge);

                $challengePath = "{$webrootPath}/.well-known/acme-challenge";
                if (! File::isDirectory($challengePath)) {
                    File::makeDirectory($challengePath, 0755, true);
                }

                $filePath = "{$challengePath}/{$token}";
                File::put($filePath, $keyAuthorization);
                $challengeFiles[] = $filePath;

                // Start validation
                if ($onProgress) { $onProgress(40, __('Validating domain ownership via HTTP...')); }
                $client->domainValidation()->start($order, $challenge);
            }

            // Poll for completion
            if ($onProgress) { $onProgress(55, __('Waiting for validation...')); }
            $order = $this->pollOrderStatus($order, $settings['poll_timeout']);

            if ($order['status'] !== 'ready') {
                $this->cleanupFiles($challengeFiles);

                return AcmeResult::failure("ACME order not ready. Status: {$order['status']}");
            }

            // Generate key and CSR, finalize
            if ($onProgress) { $onProgress(70, __('Finalizing certificate...')); }
            $result = $this->finalizeOrder($order, $domains);

            // Cleanup challenge files
            $this->cleanupFiles($challengeFiles);

            Log::info("HTTP-01 certificate obtained for {$fqdn}.");

            return $result;
        } catch (\Throwable $e) {
            Log::error("HTTP-01 certificate request failed for {$fqdn}: {$e->getMessage()}");

            return AcmeResult::failure($e->getMessage());
        }
    }

    /**
     * Generate a self-signed certificate.
     * Moved from CertbotService — uses openssl directly.
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
     * Delete stale Caddy ACME lock files for a domain.
     * Moved from CertbotService.
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
     *
     * @param  callable(string $recordName, string $recordValue): array  $createTxtRecord
     * @param  callable(array $context): void  $deleteTxtRecord
     */
    private function performDns01Challenge(
        Domain $domain,
        array $domains,
        callable $createTxtRecord,
        callable $deleteTxtRecord,
        int $propagationWait,
        ?callable $onProgress = null,
    ): AcmeResult {
        $fqdn = $domain->fqdn;
        $createdRecords = [];

        try {
            $client = $this->getClient();
            if ($onProgress) { $onProgress(20, __('Creating certificate order...')); }

            // Create ACME order
            $order = $client->order()->new($client->account()->url(), $domains);

            // Get authorizations and DNS-01 challenges
            if ($onProgress) { $onProgress(30, __('Setting DNS challenge records...')); }
            $authorizations = $client->domainValidation()->status($order);

            foreach ($authorizations as $authorization) {
                $challenge = null;
                foreach ($authorization['challenges'] as $ch) {
                    if ($ch['type'] === 'dns-01') {
                        $challenge = $ch;
                        break;
                    }
                }

                if (! $challenge) {
                    return AcmeResult::failure("No DNS-01 challenge available for {$authorization['identifier']['value']}.");
                }

                // Get the DNS TXT record value
                $recordName = '_acme-challenge.'.$authorization['identifier']['value'];
                $recordValue = $client->domainValidation()->getKeyAuthorization($challenge);

                // Create TXT record via the provided callback
                $context = $createTxtRecord($recordName, $recordValue);
                $createdRecords[] = ['context' => $context, 'callback' => $deleteTxtRecord];
            }

            // Wait for DNS propagation
            if ($onProgress) { $onProgress(40, __('Waiting for DNS propagation (:seconds seconds)...', ['seconds' => $propagationWait])); }
            sleep($propagationWait);

            // Start validation for all challenges
            if ($onProgress) { $onProgress(55, __('Validating domain ownership...')); }
            foreach ($authorizations as $authorization) {
                foreach ($authorization['challenges'] as $ch) {
                    if ($ch['type'] === 'dns-01') {
                        $client->domainValidation()->start($order, $ch);
                    }
                }
            }

            // Poll for order completion
            $settings = $this->getSettings();
            $order = $this->pollOrderStatus($order, $settings['poll_timeout']);

            if ($order['status'] !== 'ready') {
                $this->cleanupDnsRecords($createdRecords);

                return AcmeResult::failure("ACME order not ready after polling. Status: {$order['status']}");
            }

            // Generate key, CSR, finalize
            if ($onProgress) { $onProgress(70, __('Finalizing certificate...')); }
            $result = $this->finalizeOrder($order, $domains);

            // Cleanup DNS records
            $this->cleanupDnsRecords($createdRecords);

            Log::info("DNS-01 certificate obtained for {$fqdn}.");

            return $result;
        } catch (\Throwable $e) {
            $this->cleanupDnsRecords($createdRecords);
            Log::error("DNS-01 certificate request failed for {$fqdn}: {$e->getMessage()}");

            return AcmeResult::failure($e->getMessage());
        }
    }

    /**
     * Poll ACME order status until ready or timeout.
     */
    private function pollOrderStatus(mixed $order, int $timeoutSeconds): mixed
    {
        $client = $this->getClient();
        $start = time();
        $interval = 3;

        while (time() - $start < $timeoutSeconds) {
            $order = $client->order()->get($order['url'] ?? $order['orderUrl'] ?? '');

            $status = $order['status'] ?? 'unknown';

            if (in_array($status, ['ready', 'valid', 'invalid'])) {
                return $order;
            }

            sleep($interval);
            // Gradual backoff
            $interval = min($interval + 2, 15);
        }

        return $order;
    }

    /**
     * Generate private key + CSR and finalize the ACME order.
     */
    private function finalizeOrder(mixed $order, array $domains): AcmeResult
    {
        $client = $this->getClient();
        $settings = $this->getSettings();

        // Generate private key
        $keyPem = $this->generatePrivateKey($settings['key_type'], $settings['key_length']);

        // Generate CSR
        $csrPem = $this->generateCsr($keyPem, $domains);

        // Finalize order
        $client->order()->finalize($order, $csrPem);

        // Poll until certificate is available
        $finalOrder = $this->pollOrderStatus($order, 60);

        if (($finalOrder['status'] ?? '') !== 'valid') {
            return AcmeResult::failure("Order finalization failed. Status: {$finalOrder['status']}");
        }

        // Download certificate
        $certificatePem = $client->certificate()->get($finalOrder);

        if (! $certificatePem) {
            return AcmeResult::failure('Failed to download certificate from ACME server.');
        }

        $isStaging = $settings['staging'];
        Log::info('Certificate obtained'.($isStaging ? ' (STAGING)' : ''));

        return AcmeResult::success($certificatePem, $keyPem);
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

            // Build OpenSSL config with SAN
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

            // Return DER-encoded CSR (most ACME servers expect this)
            $csrPem = File::get($csrFile);

            return $csrPem;
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
            // Fallback to config if table doesn't exist yet
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
     * Cleanup created DNS TXT records.
     */
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

    /**
     * Cleanup challenge files.
     */
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
