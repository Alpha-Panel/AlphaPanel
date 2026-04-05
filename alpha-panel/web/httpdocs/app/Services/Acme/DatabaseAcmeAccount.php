<?php

declare(strict_types=1);

namespace App\Services\Acme;

use App\Models\AcmeAccount;
use Rogierw\RwAcme\Interfaces\AccountInterface;

class DatabaseAcmeAccount implements AccountInterface
{
    private ?AcmeAccount $account = null;

    public function __construct(
        private string $serverUrl,
    ) {
        $this->account = AcmeAccount::forServer($this->serverUrl);
    }

    public function exists(): bool
    {
        return $this->account !== null && $this->account->private_key_pem !== null;
    }

    public function getPrivateKey(): string
    {
        return $this->account?->private_key_pem ?? '';
    }

    public function getPublicKey(): string
    {
        return $this->account?->public_key_pem ?? '';
    }

    public function store(string $url, string $email, string $privateKey, string $publicKey): void
    {
        $this->account = AcmeAccount::updateOrCreate(
            ['server_url' => $this->serverUrl],
            [
                'account_url' => $url,
                'email' => $email,
                'private_key_pem' => $privateKey,
                'public_key_pem' => $publicKey,
            ],
        );
    }

    public function load(): array
    {
        if (! $this->exists()) {
            return [];
        }

        return [
            'url' => $this->account->account_url,
            'email' => $this->account->email,
            'privateKey' => $this->account->private_key_pem,
            'publicKey' => $this->account->public_key_pem,
        ];
    }

    public function getUrl(): string
    {
        return $this->account?->account_url ?? '';
    }
}
