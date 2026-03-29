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
    private string $customBasePath;

    private string $csrBasePath;

    public function __construct(
        private DomainConfigService $domainConfigService,
        private ReloadService $reloadService,
    ) {
        $this->customBasePath = config('panel.letsencrypt_custom_base');
        $this->csrBasePath = config('panel.letsencrypt_csr_base');
    }

    /**
     * Parse metadata from an X.509 certificate file on disk.
     *
     * @return array{common_name: ?string, issuer: ?string, not_before: ?string, not_after: ?string, san_domains: string[], fingerprint_sha256: ?string, is_wildcard: bool}
     */
    public function parseCertificate(string $certPath): array
    {
        $result = Process::timeout(15)->run(implode(' ', [
            'openssl x509',
            '-in', escapeshellarg($certPath),
            '-noout',
            '-subject', '-issuer', '-dates', '-ext', 'subjectAltName',
            '-fingerprint', '-sha256',
        ]));

        if (! $result->successful()) {
            throw new RuntimeException("Failed to parse certificate at {$certPath}: {$result->errorOutput()}");
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
            $sanLine = trim($m[1]);
            preg_match_all('/DNS:([^\s,]+)/', $sanLine, $sanMatches);
            $sanDomains = $sanMatches[1] ?? [];
        }

        $fingerprint = null;
        if (preg_match('/sha256\s+Fingerprint\s*=\s*([A-Fa-f0-9:]+)/i', $output, $m)) {
            $fingerprint = trim($m[1]);
        }

        $isWildcard = false;
        if ($commonName !== null && str_starts_with($commonName, '*.')) {
            $isWildcard = true;
        }
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
     * Validate that a private key matches a certificate by comparing their modulus.
     */
    public function validateKeyMatchesCert(string $certPem, string $keyPem): bool
    {
        $certTmp = tempnam(sys_get_temp_dir(), 'cert_');
        $keyTmp = tempnam(sys_get_temp_dir(), 'key_');

        try {
            File::put($certTmp, $certPem);
            File::put($keyTmp, $keyPem);

            $certModulus = Process::timeout(10)->run(
                'openssl x509 -noout -modulus -in '.escapeshellarg($certTmp).' | openssl md5'
            );

            $keyModulus = Process::timeout(10)->run(
                'openssl rsa -noout -modulus -in '.escapeshellarg($keyTmp).' 2>/dev/null | openssl md5'
            );

            if (! $certModulus->successful() || ! $keyModulus->successful()) {
                // Try EC key if RSA modulus failed
                $keyModulus = Process::timeout(10)->run(
                    'openssl ec -noout -text -in '.escapeshellarg($keyTmp).' 2>/dev/null | openssl md5'
                );

                $certModulus = Process::timeout(10)->run(
                    'openssl x509 -noout -text -in '.escapeshellarg($certTmp).' 2>/dev/null | openssl md5'
                );

                if (! $certModulus->successful() || ! $keyModulus->successful()) {
                    return false;
                }
            }

            return trim($certModulus->output()) === trim($keyModulus->output());
        } finally {
            File::delete($certTmp);
            File::delete($keyTmp);
        }
    }

    /**
     * Create an SslCertificate record from an existing cert/key pair on disk.
     */
    public function createFromDiskCert(
        Domain $domain,
        SslCertificateType $type,
        string $certPath,
        string $keyPath,
        ?string $validationMethod = null,
        ?string $label = null,
    ): SslCertificate {
        $meta = $this->parseCertificate($certPath);

        if ($label === null) {
            $label = $type->label().' - '.($meta['common_name'] ?? $domain->fqdn);
        }

        return SslCertificate::create([
            'domain_id' => $domain->id,
            'type' => $type,
            'label' => $label,
            'common_name' => $meta['common_name'],
            'issuer' => $meta['issuer'],
            'san_domains' => $meta['san_domains'],
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'validation_method' => $validationMethod,
            'not_before' => $meta['not_before'] ? Carbon::parse($meta['not_before']) : null,
            'not_after' => $meta['not_after'] ? Carbon::parse($meta['not_after']) : null,
            'fingerprint_sha256' => $meta['fingerprint_sha256'],
            'is_wildcard' => $meta['is_wildcard'],
            'auto_renew' => $type === SslCertificateType::LetsEncrypt,
        ]);
    }

    /**
     * Store an uploaded certificate, validate it, and create an SslCertificate record.
     */
    public function storeUploadedCert(
        Domain $domain,
        string $certPem,
        string $keyPem,
        ?string $caBundlePem = null,
        ?string $label = null,
    ): SslCertificate {
        if (! $this->validateKeyMatchesCert($certPem, $keyPem)) {
            throw new RuntimeException('The private key does not match the certificate.');
        }

        // Create the record first to get an ID for the storage directory
        $certificate = SslCertificate::create([
            'domain_id' => $domain->id,
            'type' => SslCertificateType::Custom,
            'label' => $label ?? 'Custom Certificate - '.$domain->fqdn,
            'auto_renew' => false,
        ]);

        $certDir = "{$this->customBasePath}/{$domain->fqdn}/{$certificate->id}";

        try {
            if (! File::isDirectory($certDir)) {
                File::makeDirectory($certDir, 0755, true);
            }

            $certPath = "{$certDir}/fullchain.pem";
            $keyPath = "{$certDir}/privkey.pem";

            // Build fullchain: cert + CA bundle
            $fullchainPem = $caBundlePem !== null
                ? trim($certPem)."\n".trim($caBundlePem)."\n"
                : $certPem;

            File::put($certPath, $fullchainPem);
            File::put($keyPath, $keyPem);
            File::chmod($keyPath, 0600);

            $caBundlePath = null;
            if ($caBundlePem !== null) {
                $caBundlePath = "{$certDir}/ca-bundle.pem";
                File::put($caBundlePath, $caBundlePem);
            }

            // Parse metadata from the stored cert
            $meta = $this->parseCertificate($certPath);

            $certificate->update([
                'common_name' => $meta['common_name'],
                'issuer' => $meta['issuer'],
                'san_domains' => $meta['san_domains'],
                'cert_path' => $certPath,
                'key_path' => $keyPath,
                'ca_bundle_path' => $caBundlePath,
                'not_before' => $meta['not_before'] ? Carbon::parse($meta['not_before']) : null,
                'not_after' => $meta['not_after'] ? Carbon::parse($meta['not_after']) : null,
                'fingerprint_sha256' => $meta['fingerprint_sha256'],
                'is_wildcard' => $meta['is_wildcard'],
                'label' => $label ?? $this->generateLabel(SslCertificateType::Custom, $meta['common_name'], $domain->fqdn),
            ]);

            Log::info("Stored uploaded certificate for {$domain->fqdn} (ID: {$certificate->id}).");

            return $certificate->refresh();
        } catch (\Exception $e) {
            // Clean up on failure
            if (File::isDirectory($certDir)) {
                File::deleteDirectory($certDir);
            }
            $certificate->delete();

            throw $e;
        }
    }

    /**
     * Generate a CSR and private key for a domain.
     */
    public function generateCsr(
        Domain $domain,
        string $commonName,
        string $keyType,
        array $csrFields,
        array $sanDomains = [],
    ): SslCertificate {
        // Create the record first to get an ID
        $certificate = SslCertificate::create([
            'domain_id' => $domain->id,
            'type' => SslCertificateType::Custom,
            'label' => 'CSR - '.$commonName,
            'common_name' => $commonName,
            'is_wildcard' => str_starts_with($commonName, '*.'),
            'auto_renew' => false,
        ]);

        $csrDir = "{$this->csrBasePath}/{$domain->fqdn}/{$certificate->id}";

        try {
            if (! File::isDirectory($csrDir)) {
                File::makeDirectory($csrDir, 0755, true);
            }

            $csrPath = "{$csrDir}/request.csr";
            $keyPath = "{$csrDir}/privkey.pem";

            // Build the subject string from CSR fields
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

            // Resolve openssl key arguments
            $keyArgs = $this->resolveKeyArgs($keyType);

            // Build the openssl command
            $command = ['openssl req -new -nodes'];
            $command[] = '-keyout '.escapeshellarg($keyPath);
            $command[] = '-out '.escapeshellarg($csrPath);
            $command[] = '-subj '.escapeshellarg($subject);

            foreach ($keyArgs as $arg) {
                $command[] = $arg;
            }

            // Handle SAN domains
            $configPath = null;
            if (! empty($sanDomains)) {
                $configPath = "{$csrDir}/openssl.cnf";
                $this->writeOpenSslConfig($configPath, $sanDomains);
                $command[] = '-config '.escapeshellarg($configPath);
                $command[] = '-reqexts v3_req';
            }

            $result = Process::timeout(30)->run(implode(' ', $command));

            if (! $result->successful()) {
                throw new RuntimeException("CSR generation failed: {$result->errorOutput()}");
            }

            File::chmod($keyPath, 0600);

            // Update the record with generated paths
            $certificate->update([
                'csr_path' => $csrPath,
                'key_path' => $keyPath,
                'san_domains' => $sanDomains,
            ]);

            Log::info("Generated CSR for {$commonName} (domain: {$domain->fqdn}, ID: {$certificate->id}).");

            return $certificate->refresh();
        } catch (\Exception $e) {
            // Clean up on failure
            if (File::isDirectory($csrDir)) {
                File::deleteDirectory($csrDir);
            }
            $certificate->delete();

            throw $e;
        }
    }

    /**
     * Activate an SSL certificate for a domain.
     * Updates the domain's active certificate and regenerates the Caddyfile with TLS.
     */
    public function activate(Domain $domain, SslCertificate $certificate): void
    {
        $domain->update(['active_ssl_certificate_id' => $certificate->id]);

        $this->domainConfigService->renderWithTls($domain);
        $this->reloadService->reloadCaddy();

        Log::info("Activated SSL certificate ID {$certificate->id} for {$domain->fqdn}.");
    }

    /**
     * Generate a human-readable label for a certificate.
     */
    private function generateLabel(SslCertificateType $type, ?string $commonName, string $fqdn): string
    {
        return $type->label().' - '.($commonName ?? $fqdn);
    }

    /**
     * Resolve openssl key generation arguments from a key type string.
     *
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
     * Write an OpenSSL configuration file with SAN support for CSR generation.
     *
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
