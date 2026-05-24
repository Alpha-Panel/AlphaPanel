<?php

namespace App\Services\Mail\DTO;

final class Alias
{
    public function __construct(
        public readonly string $address,
        public readonly string $destination,
        public readonly ?string $providerExternalId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'address' => $this->address,
            'destination' => $this->destination,
            'provider_external_id' => $this->providerExternalId,
        ];
    }
}
