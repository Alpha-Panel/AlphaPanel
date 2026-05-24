<?php

namespace App\Services\Mail\DTO;

final class Mailbox
{
    /** @param list<string> $forwardTo @param list<string> $aliases */
    public function __construct(
        public readonly string $address,
        public readonly ?string $displayName = null,
        public readonly ?int $quotaBytes = null,
        public readonly ?int $quotaUsedBytes = null,
        public readonly bool $active = true,
        public readonly array $forwardTo = [],
        public readonly bool $keepLocal = true,
        public readonly array $aliases = [],
        public readonly ?string $providerExternalId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'address' => $this->address,
            'display_name' => $this->displayName,
            'quota_bytes' => $this->quotaBytes,
            'quota_used_bytes' => $this->quotaUsedBytes,
            'active' => $this->active,
            'forward_to' => $this->forwardTo,
            'keep_local' => $this->keepLocal,
            'aliases' => $this->aliases,
            'provider_external_id' => $this->providerExternalId,
        ];
    }
}
