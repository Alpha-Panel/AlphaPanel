<?php

namespace App\Services\Mail\Providers;

use App\Models\Domain;
use App\Models\ZimbraServerSetting;
use App\Services\Mail\Contracts\MailProviderInterface;
use App\Services\Mail\DTO\Alias;
use App\Services\Mail\DTO\DnsHints;
use App\Services\Mail\DTO\Mailbox;
use App\Services\Mail\Exceptions\MailProviderUnavailableException;
use App\Services\Mail\Zimbra\ZimbraSoapClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Zimbra SOAP Admin API provider. Idempotent: registerDomain links an existing
 * Zimbra domain rather than erroring when it already exists. The panel never
 * deletes Zimbra-side objects unless explicitly requested.
 */
class ZimbraProvider implements MailProviderInterface
{
    public function __construct(private readonly ZimbraSoapClient $client) {}

    public function key(): string
    {
        return 'zimbra';
    }

    public function isManaged(): bool
    {
        return true;
    }

    public function registerDomain(Domain $domain): void
    {
        $existing = $this->client->getDomain($domain->fqdn);
        if ($existing !== null) {
            Log::info('mail.zimbra.domain.linked', [
                'fqdn' => $domain->fqdn,
                'zimbra_id' => $existing['id'] ?? null,
            ]);

            return;
        }

        $this->client->createDomain($domain->fqdn);
        Log::info('mail.zimbra.domain.created', ['fqdn' => $domain->fqdn]);
    }

    public function deregisterDomain(Domain $domain): void
    {
        // Intentional no-op: panel never deletes Zimbra-side data implicitly.
        Log::info('mail.zimbra.domain.unlinked', ['fqdn' => $domain->fqdn]);
    }

    public function listMailboxes(Domain $domain): Collection
    {
        $this->ensureDomainRegistered($domain);
        $rows = $this->client->searchAccounts($domain->fqdn);

        return collect($rows)->map(fn (array $row) => $this->hydrateMailbox($row));
    }

    public function findMailbox(Domain $domain, string $localPart): ?Mailbox
    {
        $row = $this->client->getAccount("{$localPart}@{$domain->fqdn}");

        return $row ? $this->hydrateMailbox($row) : null;
    }

    public function createMailbox(Domain $domain, string $localPart, string $password, array $options = []): Mailbox
    {
        $this->ensureDomainRegistered($domain);
        $address = "{$localPart}@{$domain->fqdn}";
        $existing = $this->client->getAccount($address);
        if ($existing !== null) {
            // Link existing account. Reset password only if explicitly requested via $options.
            if (! empty($options['force_password_reset']) && $password !== '') {
                $this->client->setPassword($existing['id'], $password);
            }
            Log::info('mail.zimbra.account.linked', ['address' => $address]);

            return $this->hydrateMailbox($this->client->getAccount($address));
        }

        $attrs = array_filter([
            'displayName' => $options['display_name'] ?? null,
            'zimbraMailQuota' => isset($options['quota_bytes']) ? (string) $options['quota_bytes'] : null,
        ], static fn ($v) => $v !== null);

        $this->client->createAccount($address, $password, $attrs);

        return $this->hydrateMailbox($this->client->getAccount($address));
    }

    public function updateMailbox(Domain $domain, string $localPart, array $changes): Mailbox
    {
        $address = "{$localPart}@{$domain->fqdn}";
        $existing = $this->client->getAccount($address);
        if ($existing === null) {
            throw new MailProviderUnavailableException("Mailbox {$address} not found in Zimbra.");
        }

        $attrs = array_filter([
            'displayName' => $changes['display_name'] ?? null,
            'zimbraMailQuota' => isset($changes['quota_bytes']) ? (string) $changes['quota_bytes'] : null,
            'zimbraAccountStatus' => isset($changes['active'])
                ? ($changes['active'] ? 'active' : 'locked')
                : null,
        ], static fn ($v) => $v !== null);

        if ($attrs !== []) {
            $this->client->modifyAccount($existing['id'], $attrs);
        }

        return $this->hydrateMailbox($this->client->getAccount($address));
    }

    public function setPassword(Domain $domain, string $localPart, string $newPassword): void
    {
        $address = "{$localPart}@{$domain->fqdn}";
        $existing = $this->client->getAccount($address);
        if ($existing === null) {
            throw new MailProviderUnavailableException("Mailbox {$address} not found in Zimbra.");
        }
        $this->client->setPassword($existing['id'], $newPassword);
    }

    public function setForwarding(Domain $domain, string $localPart, array $forwardTo, bool $keepLocal): void
    {
        $address = "{$localPart}@{$domain->fqdn}";
        $existing = $this->client->getAccount($address);
        if ($existing === null) {
            throw new MailProviderUnavailableException("Mailbox {$address} not found in Zimbra.");
        }
        $this->client->modifyAccount($existing['id'], [
            'zimbraPrefMailForwardingAddress' => implode(',', $forwardTo),
            'zimbraPrefMailLocalDeliveryDisabled' => $keepLocal ? 'FALSE' : 'TRUE',
            'zimbraFeatureMailForwardingEnabled' => 'TRUE',
        ]);
    }

    public function deleteMailbox(Domain $domain, string $localPart): void
    {
        $address = "{$localPart}@{$domain->fqdn}";
        $existing = $this->client->getAccount($address);
        if ($existing === null) {
            return;
        }
        $this->client->deleteAccount($existing['id']);
    }

    public function listAliases(Domain $domain): Collection
    {
        $this->ensureDomainRegistered($domain);
        $rows = $this->client->searchAliases($domain->fqdn);

        return collect($rows)->map(fn (array $row) => new Alias(
            address: (string) ($row['name'] ?? ''),
            destination: (string) ($row['targetName'] ?? ''),
            providerExternalId: $row['id'] ?? null,
        ));
    }

    public function createAlias(Domain $domain, string $fromLocalPart, string $toAddress): Alias
    {
        $this->ensureDomainRegistered($domain);
        $aliasAddress = "{$fromLocalPart}@{$domain->fqdn}";
        $target = $this->client->getAccount($toAddress);
        if ($target === null) {
            throw new MailProviderUnavailableException("Alias target {$toAddress} not found in Zimbra.");
        }
        $this->client->addAccountAlias($target['id'], $aliasAddress);

        return new Alias(address: $aliasAddress, destination: $toAddress);
    }

    public function deleteAlias(Domain $domain, string $fromLocalPart): void
    {
        $aliasAddress = "{$fromLocalPart}@{$domain->fqdn}";
        $alias = $this->client->getAlias($aliasAddress);
        if ($alias === null) {
            return;
        }
        $this->client->removeAccountAlias($alias['targetId'] ?? $alias['id'], $aliasAddress);
    }

    public function getDnsHints(Domain $domain): DnsHints
    {
        $setting = ZimbraServerSetting::current();
        $envCfg = (array) config('panel.mail.zimbra');

        $mxHost = $setting?->default_mx_host ?: ($envCfg['default_mx_host'] ?? null);
        $priority = $setting?->default_mx_priority ?: (int) ($envCfg['default_mx_priority'] ?? 10);
        $spfInclude = $setting?->default_spf_include ?? ($envCfg['default_spf_include'] ?? null);

        $mx = $mxHost
            ? [['name' => $domain->fqdn, 'priority' => $priority, 'content' => rtrim($mxHost, '.').'.']]
            : [];

        $txt = [];
        if ($spfInclude) {
            $txt[] = [
                'name' => $domain->fqdn,
                'content' => 'v=spf1 '.$spfInclude.' ~all',
            ];
        }

        return new DnsHints(mx: $mx, txt: $txt);
    }

    /**
     * Idempotent: ensures the FQDN is registered as a Zimbra domain before
     * issuing search/account calls. Without this, Zimbra returns a SOAP fault
     * (account.NO_SUCH_DOMAIN) when the panel knows about the domain but
     * Zimbra does not.
     */
    private function ensureDomainRegistered(Domain $domain): void
    {
        if ($this->client->getDomain($domain->fqdn) !== null) {
            return;
        }

        $this->client->createDomain($domain->fqdn);
        Log::info('mail.zimbra.domain.auto_created', ['fqdn' => $domain->fqdn]);
    }

    /** @param array<string, mixed> $row */
    private function hydrateMailbox(array $row): Mailbox
    {
        $forwardRaw = (string) ($row['zimbraPrefMailForwardingAddress'] ?? '');
        $forward = $forwardRaw !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $forwardRaw))))
            : [];

        return new Mailbox(
            address: (string) ($row['name'] ?? ''),
            displayName: $row['displayName'] ?? null,
            quotaBytes: isset($row['zimbraMailQuota']) ? (int) $row['zimbraMailQuota'] : null,
            quotaUsedBytes: isset($row['used']) ? (int) $row['used'] : null,
            active: ($row['zimbraAccountStatus'] ?? 'active') === 'active',
            forwardTo: $forward,
            keepLocal: ($row['zimbraPrefMailLocalDeliveryDisabled'] ?? 'FALSE') !== 'TRUE',
            aliases: array_values($row['aliases'] ?? []),
            providerExternalId: $row['id'] ?? null,
        );
    }
}
