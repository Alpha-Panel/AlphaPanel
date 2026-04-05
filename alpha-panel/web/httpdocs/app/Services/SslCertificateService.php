<?php

namespace App\Services;

use App\Enums\SslCertificateType;
use App\Models\Domain;
use App\Models\SslCertificate;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class SslCertificateService
{
    private string $activeCertBasePath;

    public function __construct(
        private DomainConfigService $domainConfigService,
        private ReloadService $reloadService,
    ) {
        $this->activeCertBasePath = config('panel.letsencrypt_custom_base');
    }

    /**
     * Split a fullchain PEM into server certificate and CA bundle.
     * The first certificate block is the server cert, the rest is the CA bundle.
     *
     * @return array{cert: string, ca_bundle: string|null}
     */
    public function splitFullchain(string $fullchainPem): array
    {
        // Match all PEM certificate blocks
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $fullchainPem, $matches);

        $certs = $matches[0] ?? [];

        if (count($certs) === 0) {
            return ['cert' => $fullchainPem, 'ca_bundle' => null];
        }

        $serverCert = $certs[0];
        $caBundle = count($certs) > 1
            ? implode("\n", array_slice($certs, 1))."\n"
            : null;

        return ['cert' => $serverCert, 'ca_bundle' => $caBundle];
    }

    /**
     * Build a fullchain PEM from server certificate and CA bundle.
     */
    public function buildFullchain(string $certPem, ?string $caBundlePem = null): string
    {
        if ($caBundlePem === null || trim($caBundlePem) === '') {
            return $certPem;
        }

        return trim($certPem)."\n".trim($caBundlePem)."\n";
    }

    /**
     * Parse metadata from X.509 PEM certificate content.
     *
     * @return array{common_name: ?string, issuer: ?string, not_before: ?string, not_after: ?string, san_domains: string[], fingerprint_sha256: ?string, is_wildcard: bool}
     */
    public function parseCertificatePem(string $certPem): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'cert_parse_');

        try {
            File::put($tmpFile, $certPem);

            return $this->parseCertificateFile($tmpFile);
        } finally {
            File::delete($tmpFile);
        }
    }

    /**
     * Parse metadata from an X.509 certificate file on disk.
     *
     * @return array{common_name: ?string, issuer: ?string, not_before: ?string, not_after: ?string, san_domains: string[], fingerprint_sha256: ?string, is_wildcard: bool}
     */
    public function parseCertificateFile(string $certPath): array
    {
        $result = Process::timeout(15)->run(implode(' ', [
            'openssl x509',
            '-in', escapeshellarg($certPath),
            '-noout',
            '-subject', '-issuer', '-dates', '-ext', 'subjectAltName',
            '-fingerprint', '-sha256',
        ]));

        if (! $result->successful()) {
            throw new RuntimeException("Failed to parse certificate: {$result->errorOutput()}");
        }

        $output = $result->output();

        $commonName = null;
        if (preg_match('/subject\s*=.*?CN\s*=\s*([^\n\/,]+)/i', $output, $m)) {
            $commonName = trim($m[1]);
        }

        $issuer = null;
        if (preg_match('/issuer\s*=.*?O\s*=\s*([^\n\/,]+)/i', $output, $m)) {
            $issuer = trim($m[1]);
        } elseif (preg_match('/issuer\s*=.*?CN\s*=\s*([^\n\/,]+)/i', $output, $m)) {
            $issuer = trim($m[1]);
        }

        $notBefore = null;
        if (preg_match('/notBefore\s*=\s*(.+)/i', $output, $m)) {
            $notBefore = trim($m[1]);
        }

        $notAfter = null;
        if (preg_match('/notAfter\s*=\s*(.+)/i', $output, $m)) {
            $notAfter = trim($m[1]);
        }

        $sanDomains = [];
        if (preg_match('/Subject Alternative Name:\s*\n\s*(.+)/i', $output, $m)) {
            preg_match_all('/DNS:([^\s,]+)/', trim($m[1]), $sanMatches);
            $sanDomains = $sanMatches[1] ?? [];
        }

        $fingerprint = null;
        if (preg_match('/sha256\s+Fingerprint\s*=\s*([A-Fa-f0-9:]+)/i', $output, $m)) {
            $fingerprint = trim($m[1]);
        }

        $isWildcard = ($commonName !== null && str_starts_with($commonName, '*.'));
        if (! $isWildcard) {
            foreach ($sanDomains as $san) {
                if (str_starts_with($san, '*.')) {
                    $isWildcard = true;
                    break;
                }
            }
        }

        return [
            'common_name' => $commonName,
            'issuer' => $issuer,
            'not_before' => $notBefore,
            'not_after' => $notAfter,
            'san_domains' => $sanDomains,
            'fingerprint_sha256' => $fingerprint,
            'is_wildcard' => $isWildcard,
        ];
    }

    /**
     * Validate that a private key matches a certificate by comparing public keys.
     * Works for all key types: RSA, EC (ECDSA), Ed25519, etc.
     */
    public function validateKeyMatchesCert(string $certPem, string $keyPem): bool
    {
        $certTmp = tempnam(sys_get_temp_dir(), 'cert_');
        $keyTmp = tempnam(sys_get_temp_dir(), 'key_');

        try {
            File::put($certTmp, $certPem);
            File::put($keyTmp, $keyPem);

            // Extract public key from certificate
            $certPubkey = Process::timeout(10)->run(
                'openssl x509 -noout -pubkey -in '.escapeshellarg($certTmp)
            );

            // Extract public key from private key (works for RSA, EC, Ed25519)
            $keyPubkey = Process::timeout(10)->run(
                'openssl pkey -pubout -in '.escapeshellarg($keyTmp).' 2>/dev/null'
            );

            if (! $certPubkey->successful() || ! $keyPubkey->successful()) {
                Log::warning('SSL key validation: openssl command failed', [
                    'cert_error' => $certPubkey->errorOutput(),
                    'key_error' => $keyPubkey->errorOutput(),
                ]);

                return false;
            }

            return trim($certPubkey->output()) === trim($keyPubkey->output());
        } finally {
            File::delete($certTmp);
            File::delete($keyTmp);
        }
    }

    /**
     * Validate that a CA bundle chains to the certificate.
     * Uses openssl verify to check the trust chain.
     */
    public function validateCaBundle(string $certPem, string $caBundlePem): bool
    {
        $certTmp = tempnam(sys_get_temp_dir(), 'cert_');
        $caTmp = tempnam(sys_get_temp_dir(), 'ca_');

        try {
            File::put($certTmp, $certPem);
            File::put($caTmp, $caBundlePem);

            $result = Process::timeout(10)->run(
                'openssl verify -CAfile '.escapeshellarg($caTmp).' '.escapeshellarg($certTmp)
            );

            // openssl verify returns 0 and outputs "{certfile}: OK" on success
            return $result->successful() && str_contains($result->output(), ': OK');
        } catch (\Exception $e) {
            Log::warning("CA bundle validation error: {$e->getMessage()}");

            return false;
        } finally {
            File::delete($certTmp);
            File::delete($caTmp);
        }
    }

    /**
     * Create an SslCertificate record from cert/key files on disk.
     * Reads the PEM content and stores it encrypted in the DB.
     */
    public function createFromDiskCert(
        Domain $domain,
        SslCertificateType $type,
        string $certPath,
        string $keyPath,
        ?string $validationMethod = null,
        ?string $label = null,
    ): SslCertificate {
        $fullchainPem = File::get($certPath);
        $keyPem = File::get($keyPath);

        // Split fullchain into server cert + CA bundle
        $parts = $this->splitFullchain($fullchainPem);
        $meta = $this->parseCertificatePem($parts['cert']);

        return SslCertificate::create([
            'domain_id' => $domain->id,
            'type' => $type,
            'label' => $label ?? $type->label().' - '.($meta['common_name'] ?? $domain->fqdn),
            'common_name' => $meta['common_name'],
            'issuer' => $meta['issuer'],
            'san_domains' => $meta['san_domains'],
            'private_key_pem' => $keyPem,
            'certificate_pem' => $parts['cert'],
            'ca_bundle_pem' => $parts['ca_bundle'],
            'validation_method' => $validationMethod,
            'not_before' => $meta['not_before'] ? Carbon::parse($meta['not_before']) : null,
            'not_after' => $meta['not_after'] ? Carbon::parse($meta['not_after']) : null,
            'fingerprint_sha256' => $meta['fingerprint_sha256'],
            'is_wildcard' => $meta['is_wildcard'],
            'auto_renew' => $type === SslCertificateType::LetsEncrypt,
        ]);
    }

    /**
     * Create an SslCertificate record directly from PEM strings.
     * Used by AcmeService which provides certificate data in memory.
     */
    public function createFromPem(
        Domain $domain,
        SslCertificateType $type,
        string $fullchainPem,
        string $keyPem,
        ?string $validationMethod = null,
        ?string $label = null,
    ): SslCertificate {
        $parts = $this->splitFullchain($fullchainPem);
        $meta = $this->parseCertificatePem($parts['cert']);

        return SslCertificate::create([
            'domain_id' => $domain->id,
            'type' => $type,
            'label' => $label ?? $type->label().' - '.($meta['common_name'] ?? $domain->fqdn),
            'common_name' => $meta['common_name'],
            'issuer' => $meta['issuer'],
            'san_domains' => $meta['san_domains'],
            'private_key_pem' => $keyPem,
            'certificate_pem' => $parts['cert'],
            'ca_bundle_pem' => $parts['ca_bundle'],
            'validation_method' => $validationMethod,
            'not_before' => $meta['not_before'] ? Carbon::parse($meta['not_before']) : null,
            'not_after' => $meta['not_after'] ? Carbon::parse($meta['not_after']) : null,
            'fingerprint_sha256' => $meta['fingerprint_sha256'],
            'is_wildcard' => $meta['is_wildcard'],
            'auto_renew' => $type === SslCertificateType::LetsEncrypt,
        ]);
    }

    /**
     * Store an uploaded certificate with validation and encrypted storage.
     */
    public function storeUploadedCert(
        Domain $domain,
        string $certPem,
        string $keyPem,
        ?string $caBundlePem = null,
        ?string $label = null,
    ): SslCertificate {
        if (! $this->validateKeyMatchesCert($certPem, $keyPem)) {
            throw new RuntimeException(__('The private key does not match the certificate.'));
        }

        // Parse metadata from the server certificate only (not CA bundle)
        $meta = $this->parseCertificatePem($certPem);

        return SslCertificate::create([
            'domain_id' => $domain->id,
            'type' => SslCertificateType::Custom,
            'label' => $label ?? 'Custom Certificate - '.($meta['common_name'] ?? $domain->fqdn),
            'common_name' => $meta['common_name'],
            'issuer' => $meta['issuer'],
            'san_domains' => $meta['san_domains'],
            'private_key_pem' => $keyPem,
            'certificate_pem' => $certPem,
            'ca_bundle_pem' => $caBundlePem,
            'validation_method' => null,
            'not_before' => $meta['not_before'] ? Carbon::parse($meta['not_before']) : null,
            'not_after' => $meta['not_after'] ? Carbon::parse($meta['not_after']) : null,
            'fingerprint_sha256' => $meta['fingerprint_sha256'],
            'is_wildcard' => $meta['is_wildcard'],
            'auto_renew' => false,
        ]);
    }

    /**
     * Generate a CSR and private key, store encrypted in DB.
     */
    public function generateCsr(
        Domain $domain,
        string $commonName,
        string $keyType,
        array $csrFields,
        array $sanDomains = [],
    ): SslCertificate {
        $tmpDir = sys_get_temp_dir().'/csr_'.uniqid();
        File::makeDirectory($tmpDir, 0700, true);

        try {
            $csrPath = "{$tmpDir}/request.csr";
            $keyPath = "{$tmpDir}/privkey.pem";

            // Build subject
            $subjectParts = [];
            if (! empty($csrFields['country'])) {
                $subjectParts[] = '/C='.$csrFields['country'];
            }
            if (! empty($csrFields['state'])) {
                $subjectParts[] = '/ST='.$csrFields['state'];
            }
            if (! empty($csrFields['city'])) {
                $subjectParts[] = '/L='.$csrFields['city'];
            }
            if (! empty($csrFields['organization'])) {
                $subjectParts[] = '/O='.$csrFields['organization'];
            }
            if (! empty($csrFields['organizational_unit'])) {
                $subjectParts[] = '/OU='.$csrFields['organizational_unit'];
            }
            $subjectParts[] = '/CN='.$commonName;
            $subject = implode('', $subjectParts);

            $keyArgs = $this->resolveKeyArgs($keyType);

            $command = ['openssl req -new -nodes'];
            $command[] = '-keyout '.escapeshellarg($keyPath);
            $command[] = '-out '.escapeshellarg($csrPath);
            $command[] = '-subj '.escapeshellarg($subject);

            foreach ($keyArgs as $arg) {
                $command[] = $arg;
            }

            // SAN support via config file
            if (! empty($sanDomains)) {
                $configPath = "{$tmpDir}/openssl.cnf";
                $this->writeOpenSslConfig($configPath, $sanDomains);
                $command[] = '-config '.escapeshellarg($configPath);
                $command[] = '-reqexts v3_req';
            }

            $result = Process::timeout(30)->run(implode(' ', $command));

            if (! $result->successful()) {
                throw new RuntimeException("CSR generation failed: {$result->errorOutput()}");
            }

            $keyPem = File::get($keyPath);
            $csrPem = File::get($csrPath);

            $certificate = SslCertificate::create([
                'domain_id' => $domain->id,
                'type' => SslCertificateType::Custom,
                'label' => 'CSR - '.$commonName,
                'common_name' => $commonName,
                'private_key_pem' => $keyPem,
                'csr_pem' => $csrPem,
                'san_domains' => ! empty($sanDomains) ? $sanDomains : null,
                'is_wildcard' => str_starts_with($commonName, '*.'),
                'auto_renew' => false,
            ]);

            Log::info("Generated CSR for {$commonName} (domain: {$domain->fqdn}, ID: {$certificate->id}).");

            return $certificate;
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }

    /**
     * Activate an SSL certificate for a domain.
     * Writes PEM content to disk for Caddy, updates domain FK, regenerates Caddyfile.
     */
    public function activate(Domain $domain, SslCertificate $certificate): void
    {
        if (! $certificate->certificate_pem || ! $certificate->private_key_pem) {
            throw new RuntimeException('Certificate or private key is missing.');
        }

        // For cross-domain activation (subdomain inheriting an apex wildcard),
        // the PEM files already live under the owning domain's directory —
        // DomainConfigService::resolveCertPaths() will point Caddy at them.
        if ($certificate->domain_id === $domain->id) {
            $this->writeCertToDisk($domain, $certificate);
        }

        $domain->update(['active_ssl_certificate_id' => $certificate->id]);

        // Force relationship reload — loadMissing won't refresh a stale cached relation
        $domain->setRelation('activeSslCertificate', $certificate);

        $this->domainConfigService->renderWithTls($domain);
        $this->reloadService->reloadCaddy();

        Log::info("Activated SSL certificate ID {$certificate->id} for {$domain->fqdn}.");
    }

    /**
     * Write a certificate's PEM content to disk files for Caddy.
     * Returns the directory path where files were written.
     */
    public function writeCertToDisk(Domain $domain, SslCertificate $certificate): string
    {
        $certDir = "{$this->activeCertBasePath}/{$domain->fqdn}/{$certificate->id}";

        if (! File::isDirectory($certDir)) {
            File::makeDirectory($certDir, 0755, true);
        }

        // Build fullchain: server cert + CA bundle (Caddy needs the full chain)
        $fullchain = $this->buildFullchain($certificate->certificate_pem, $certificate->ca_bundle_pem);
        File::put("{$certDir}/fullchain.pem", $fullchain);
        File::put("{$certDir}/privkey.pem", $certificate->private_key_pem);
        File::chmod("{$certDir}/privkey.pem", 0600);

        return $certDir;
    }

    /**
     * Get the disk path where an active cert's files should be.
     *
     * @return array{cert: string, key: string}|null
     */
    public function getActiveCertDiskPaths(Domain $domain, SslCertificate $certificate): ?array
    {
        $certDir = "{$this->activeCertBasePath}/{$domain->fqdn}/{$certificate->id}";
        $certPath = "{$certDir}/fullchain.pem";
        $keyPath = "{$certDir}/privkey.pem";

        if (File::exists($certPath) && File::exists($keyPath)) {
            return ['cert' => $certPath, 'key' => $keyPath];
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function resolveKeyArgs(string $keyType): array
    {
        return match ($keyType) {
            'rsa2048' => ['-newkey rsa:2048'],
            'rsa4096' => ['-newkey rsa:4096'],
            'ecdsa256' => ['-newkey ec -pkeyopt ec_paramgen_curve:prime256v1'],
            'ecdsa384' => ['-newkey ec -pkeyopt ec_paramgen_curve:secp384r1'],
            default => ['-newkey rsa:2048'],
        };
    }

    /**
     * @param  string[]  $sanDomains
     */
    private function writeOpenSslConfig(string $configPath, array $sanDomains): void
    {
        $sanEntries = [];
        foreach ($sanDomains as $i => $domain) {
            $sanEntries[] = 'DNS.'.($i + 1).' = '.$domain;
        }

        $config = implode("\n", [
            '[req]',
            'distinguished_name = req_distinguished_name',
            'req_extensions = v3_req',
            'prompt = no',
            '',
            '[req_distinguished_name]',
            'CN = placeholder',
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

        File::put($configPath, $config);
    }
}
