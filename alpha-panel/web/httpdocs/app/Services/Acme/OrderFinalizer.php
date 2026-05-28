<?php

declare(strict_types=1);

namespace App\Services\Acme;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Rogierw\RwAcme\DTO\OrderData;

/**
 * Handles the post-validation phase of an ACME order:
 *   - generates the certificate's private key
 *   - generates the CSR
 *   - submits the CSR to finalize the order
 *   - polls the order status until issuance completes
 *   - downloads the certificate bundle
 *   - polls outstanding domain validations until they all pass or timeout
 */
class OrderFinalizer
{
    public function __construct(
        private AcmeClientFactory $clientFactory,
    ) {}

    /**
     * Finalize the ACME order: generate key + CSR, submit, download certificate.
     *
     * @param  string[]  $domains
     */
    public function finalize(OrderData $order, array $domains): AcmeResult
    {
        $client = $this->clientFactory->getClient();
        $settings = $this->clientFactory->getSettings();

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
     * Poll LE until all domain challenges pass or timeout is reached.
     * Replaces the library's allChallengesPassed() which is hardcoded to 4 retries.
     */
    public function pollUntilChallengesPassed(mixed $client, mixed $order, int $timeoutSeconds): bool
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
     *
     * @param  string[]  $domains
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
}
