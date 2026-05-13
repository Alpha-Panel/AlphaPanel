<?php

declare(strict_types=1);

namespace App\Services\Acme;

class AcmeResult
{
    private ?\Closure $cleanupCallback = null;

    public function __construct(
        public readonly bool $success,
        public readonly ?string $certificatePem = null,
        public readonly ?string $privateKeyPem = null,
        public readonly ?string $fullchainPem = null,
        public readonly ?string $caBundlePem = null,
        public readonly ?string $error = null,
    ) {}

    public static function success(string $fullchainPem, string $privateKeyPem): self
    {
        // Split fullchain into server cert + CA bundle
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $fullchainPem, $matches);
        $certs = $matches[0] ?? [];

        $certificatePem = $certs[0] ?? $fullchainPem;
        $caBundlePem = count($certs) > 1
            ? implode("\n", array_slice($certs, 1))."\n"
            : null;

        return new self(
            success: true,
            certificatePem: $certificatePem,
            privateKeyPem: $privateKeyPem,
            fullchainPem: $fullchainPem,
            caBundlePem: $caBundlePem,
        );
    }

    public static function failure(string $error): self
    {
        return new self(
            success: false,
            error: $error,
        );
    }

    /**
     * Attach a cleanup callback to run after the certificate is fully stored.
     * Called by the job after storeCertAndActivate completes, not at validation time.
     */
    public function withCleanup(\Closure $callback): self
    {
        $this->cleanupCallback = $callback;

        return $this;
    }

    public function runCleanup(): void
    {
        if ($this->cleanupCallback !== null) {
            ($this->cleanupCallback)();
        }
    }
}
