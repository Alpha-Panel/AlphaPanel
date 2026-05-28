<?php

declare(strict_types=1);

namespace App\Services\Acme;

use App\Models\Domain;
use App\Services\CloudflareDnsService;
use App\Services\LocalDnsService;
use App\Services\PortainerService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Rogierw\RwAcme\Api;
use Rogierw\RwAcme\DTO\AccountData;

/**
 * High-level ACME orchestrator.
 *
 * Composes the shared AcmeClientFactory plus per-challenge-type runners
 * (HTTP-01, DNS-01) and the OrderFinalizer. Holds non-ACME concerns that
 * live in the same domain space: self-signed cert generation (for fallback
 * + panel default) and Caddy ACME lock-file cleanup.
 *
 * Public method signatures are stable — used by SslActivateJob,
 * SslCertificateController, EnsureDefaultCertCommand, IssueInstallerCertCommand,
 * PanelApplyConfigsCommand, ProvisionDomainJob.
 */
class AcmeService
{
    public function __construct(
        private AcmeClientFactory $clientFactory,
        private Http01ChallengeRunner $http01,
        private Dns01ChallengeRunner $dns01,
        private CloudflareDnsService $cloudflareDns,
        private LocalDnsService $localDns,
        private PortainerService $portainer,
    ) {}

    /**
     * Get or create the ACME API client.
     *
     * Kept for backwards compatibility with callers that touch the client
     * directly (e.g. tests, low-level diagnostic commands).
     */
    public function getClient(): Api
    {
        return $this->clientFactory->getClient();
    }

    /**
     * Ensure ACME account exists, create if needed.
     */
    public function ensureAccount(): AccountData
    {
        return $this->clientFactory->ensureAccount();
    }

    /**
     * Request a wildcard certificate using Cloudflare DNS-01 validation.
     */
    public function requestCertificateDnsCloudflare(Domain $domain, ?callable $onProgress = null): AcmeResult
    {
        $this->clientFactory->resetClient();
        $fqdn = $domain->fqdn;
        // Wildcards only make sense for apex domains — for subdomains we
        // request a single-host cert and write the ACME TXT into the apex zone.
        $domains = $domain->isSubdomain() ? [$fqdn] : [$fqdn, "*.{$fqdn}"];
        $apex = $domain->getApexDomain();

        Log::info("Requesting DNS-01 (Cloudflare) certificate for {$fqdn} via apex zone {$apex}.");

        $settings = $this->clientFactory->getSettings();

        return $this->dns01->run(
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
            propagationWait: $settings['dns_propagation_wait'],
            onProgress: $onProgress,
            pollTimeout: $settings['poll_timeout'],
        );
    }

    /**
     * Request a wildcard certificate using local PowerDNS DNS-01 validation.
     */
    public function requestCertificateDnsLocal(Domain $domain, ?callable $onProgress = null): AcmeResult
    {
        $this->clientFactory->resetClient();
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

        $settings = $this->clientFactory->getSettings();

        return $this->dns01->run(
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
            propagationWait: $settings['local_dns_wait'],
            onProgress: $onProgress,
            pollTimeout: $settings['poll_timeout'],
        );
    }

    /**
     * Request a certificate using HTTP-01 webroot validation.
     * HTTP-01 cannot issue wildcard certificates.
     */
    public function requestCertificateHttp(Domain $domain, ?callable $onProgress = null): AcmeResult
    {
        $this->clientFactory->resetClient();

        return $this->http01->run($domain, $onProgress);
    }

    /**
     * Generate a self-signed certificate.
     */
    public function generateSelfSigned(Domain $domain): AcmeResult
    {
        $fqdn = $domain->fqdn;
        $settings = $this->clientFactory->getSettings();
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

        $settings = $this->clientFactory->getSettings();
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
}
