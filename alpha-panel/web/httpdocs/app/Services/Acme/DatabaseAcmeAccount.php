<?php

declare(strict_types=1);

namespace App\Services\Acme;

use App\Models\AcmeAccount;
use Rogierw\RwAcme\Interfaces\AcmeAccountInterface;

class DatabaseAcmeAccount implements AcmeAccountInterface
{
    private ?AcmeAccount $account = null;

    public function __construct(
        private string $serverUrl,
    ) {
        $this->account = AcmeAccount::forServer($this->serverUrl);
    }

    public function exists(): bool
    {
        if ($this->account === null || empty($this->account->private_key_pem)) {
            return false;
        }

        // Library only supports RSA account keys. If an EC key was stored by
        // a previous buggy version, treat it as non-existent so a new RSA key
        // is generated on the next account operation.
        $privateKey = @openssl_pkey_get_private($this->account->private_key_pem);
        if ($privateKey === false) {
            return false;
        }

        $details = openssl_pkey_get_details($privateKey);
        if (! isset($details['rsa'])) {
            \Illuminate\Support\Facades\Log::warning(
                'Existing ACME account key is not RSA; will regenerate as RSA.',
                ['server_url' => $this->serverUrl]
            );

            return false;
        }

        return true;
    }

    public function getPrivateKey(): string
    {
        return $this->account?->private_key_pem ?? '';
    }

    public function getPublicKey(): string
    {
        return $this->account?->public_key_pem ?? '';
    }

    public function generateNewKeys(string $keyType = 'RSA'): bool
    {
        // The rogierw/rw-acme-client library only supports RSA account keys.
        // JsonWebKey::compute() reads $details['rsa']['e'] and $details['rsa']['n'],
        // which do not exist for EC keys. Certificate keys can still be EC
        // (configured separately in AcmeService), but the ACME account key
        // used for signing requests must be RSA.
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 4096,
        ]);

        if (! $key) {
            return false;
        }

        openssl_pkey_export($key, $privateKeyPem);
        $details = openssl_pkey_get_details($key);
        $publicKeyPem = $details['key'] ?? '';

        $this->account = AcmeAccount::updateOrCreate(
            ['server_url' => $this->serverUrl],
            [
                'private_key_pem' => $privateKeyPem,
                'public_key_pem' => $publicKeyPem,
            ],
        );

        return true;
    }

    /**
     * Store the ACME account URL after registration.
     */
    public function storeAccountUrl(string $accountUrl, string $email): void
    {
        if ($this->account) {
            $this->account->update([
                'account_url' => $accountUrl,
                'email' => $email,
            ]);
        }
    }
}
