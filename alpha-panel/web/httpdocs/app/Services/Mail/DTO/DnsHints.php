<?php

namespace App\Services\Mail\DTO;

final class DnsHints
{
    /**
     * @param list<array{name: string, priority: int, content: string}> $mx
     * @param list<array{name: string, content: string}> $txt
     */
    public function __construct(
        public readonly array $mx = [],
        public readonly array $txt = [],
        public readonly ?string $dkimSelector = null,
        public readonly ?string $dkimPublicKey = null,
    ) {}
}
