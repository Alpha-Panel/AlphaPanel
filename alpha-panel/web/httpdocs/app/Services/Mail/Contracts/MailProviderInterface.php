<?php

namespace App\Services\Mail\Contracts;

use App\Models\Domain;
use App\Services\Mail\DTO\Alias;
use App\Services\Mail\DTO\DnsHints;
use App\Services\Mail\DTO\Mailbox;
use Illuminate\Support\Collection;

interface MailProviderInterface
{
    public function key(): string;

    public function isManaged(): bool;

    public function registerDomain(Domain $domain): void;

    public function deregisterDomain(Domain $domain): void;

    /** @return Collection<int, Mailbox> */
    public function listMailboxes(Domain $domain): Collection;

    public function findMailbox(Domain $domain, string $localPart): ?Mailbox;

    /** @param array<string, mixed> $options */
    public function createMailbox(Domain $domain, string $localPart, string $password, array $options = []): Mailbox;

    /** @param array<string, mixed> $changes */
    public function updateMailbox(Domain $domain, string $localPart, array $changes): Mailbox;

    public function setPassword(Domain $domain, string $localPart, string $newPassword): void;

    /** @param list<string> $forwardTo */
    public function setForwarding(Domain $domain, string $localPart, array $forwardTo, bool $keepLocal): void;

    public function deleteMailbox(Domain $domain, string $localPart): void;

    /** @return Collection<int, Alias> */
    public function listAliases(Domain $domain): Collection;

    public function createAlias(Domain $domain, string $fromLocalPart, string $toAddress): Alias;

    public function deleteAlias(Domain $domain, string $fromLocalPart): void;

    public function getDnsHints(Domain $domain): DnsHints;
}
