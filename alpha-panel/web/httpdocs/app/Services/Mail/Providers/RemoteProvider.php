<?php

namespace App\Services\Mail\Providers;

use App\Models\Domain;
use App\Services\Mail\Contracts\MailProviderInterface;
use App\Services\Mail\DTO\Alias;
use App\Services\Mail\DTO\DnsHints;
use App\Services\Mail\DTO\Mailbox;
use App\Services\Mail\Exceptions\UnsupportedMailOperationException;
use Illuminate\Support\Collection;

/**
 * No-op provider for domains whose mail is hosted off-platform. The panel
 * never connects to the remote MX — it only publishes the MX record the
 * user supplied. Mailbox/alias operations are explicitly unsupported.
 */
class RemoteProvider implements MailProviderInterface
{
    public function key(): string
    {
        return 'remote';
    }

    public function isManaged(): bool
    {
        return false;
    }

    public function registerDomain(Domain $domain): void
    {
        // No-op — DNS update happens via MailDnsService elsewhere.
    }

    public function deregisterDomain(Domain $domain): void
    {
        // No-op.
    }

    public function listMailboxes(Domain $domain): Collection
    {
        return collect();
    }

    public function findMailbox(Domain $domain, string $localPart): ?Mailbox
    {
        return null;
    }

    public function createMailbox(Domain $domain, string $localPart, string $password, array $options = []): Mailbox
    {
        throw new UnsupportedMailOperationException(
            'Remote mail domains are managed outside this panel.'
        );
    }

    public function updateMailbox(Domain $domain, string $localPart, array $changes): Mailbox
    {
        throw new UnsupportedMailOperationException(
            'Remote mail domains are managed outside this panel.'
        );
    }

    public function setPassword(Domain $domain, string $localPart, string $newPassword): void
    {
        throw new UnsupportedMailOperationException(
            'Remote mail domains are managed outside this panel.'
        );
    }

    public function setForwarding(Domain $domain, string $localPart, array $forwardTo, bool $keepLocal): void
    {
        throw new UnsupportedMailOperationException(
            'Remote mail domains are managed outside this panel.'
        );
    }

    public function deleteMailbox(Domain $domain, string $localPart): void
    {
        throw new UnsupportedMailOperationException(
            'Remote mail domains are managed outside this panel.'
        );
    }

    public function listAliases(Domain $domain): Collection
    {
        return collect();
    }

    public function createAlias(Domain $domain, string $fromLocalPart, string $toAddress): Alias
    {
        throw new UnsupportedMailOperationException(
            'Remote mail domains are managed outside this panel.'
        );
    }

    public function deleteAlias(Domain $domain, string $fromLocalPart): void
    {
        throw new UnsupportedMailOperationException(
            'Remote mail domains are managed outside this panel.'
        );
    }

    public function getDnsHints(Domain $domain): DnsHints
    {
        $host = $domain->mail_remote_mx_host;
        if (! $host) {
            return new DnsHints();
        }

        $priority = $domain->mail_remote_mx_priority ?? 10;

        return new DnsHints(
            mx: [
                ['name' => $domain->fqdn, 'priority' => $priority, 'content' => $host],
            ],
        );
    }
}
