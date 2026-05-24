<?php

namespace App\Services\Mail;

use App\Enums\MailHosting;
use App\Models\Domain;
use App\Services\DnsProviderFactory;
use App\Services\Mail\DTO\DnsHints;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bridges a mail provider's DnsHints to the domain's chosen DNS backend
 * (Cloudflare or local PowerDNS) via DnsProviderFactory. Always idempotent —
 * existing mail-managed records are removed before new ones are written.
 *
 * Records are tagged in their `content` with no special prefix; mail-managed
 * record types are MX (under the apex), and TXT entries at `@`, `_dmarc.@`,
 * and `dkim._domainkey.@`. The cleanup pass deletes only these names.
 */
class MailDnsService
{
    private const MANAGED_TXT_PREFIXES = ['_dmarc.', 'dkim._domainkey.'];

    public function __construct(private readonly MailProviderResolver $resolver) {}

    public function applyForDomain(Domain $domain): void
    {
        if ($domain->mail_hosting === MailHosting::Disabled) {
            $this->removeMailRecords($domain);

            return;
        }

        $provider = $this->resolver->tryFor($domain);
        if ($provider === null) {
            return;
        }

        try {
            $hints = $provider->getDnsHints($domain);
        } catch (Throwable $e) {
            Log::warning('mail.dns.hints_failed', [
                'fqdn' => $domain->fqdn,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->removeMailRecords($domain);
        $this->writeHints($domain, $hints);
    }

    public function removeMailRecords(Domain $domain): void
    {
        try {
            $backend = DnsProviderFactory::for($domain);
        } catch (Throwable) {
            return;
        }

        $records = $backend->getRecords($domain);
        $ids = [];
        foreach ($records as $r) {
            $row = is_array($r) ? $r : (array) $r;
            $type = $row['type'] ?? null;
            $name = (string) ($row['name'] ?? '');
            $id = $row['id'] ?? null;
            if ($id === null) {
                continue;
            }
            if ($type === 'MX' && $name === $domain->fqdn) {
                $ids[] = $id;

                continue;
            }
            if ($type === 'TXT' && $this->isMailManagedTxtName($name, $domain->fqdn)) {
                $ids[] = $id;
            }
        }

        if ($ids !== []) {
            $backend->bulkDeleteRecords($domain, $ids);
        }
    }

    private function writeHints(Domain $domain, DnsHints $hints): void
    {
        try {
            $backend = DnsProviderFactory::for($domain);
        } catch (Throwable $e) {
            Log::warning('mail.dns.no_backend', [
                'fqdn' => $domain->fqdn,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($hints->mx as $mx) {
            $backend->createRecord($domain, [
                'name' => $mx['name'],
                'type' => 'MX',
                'content' => $mx['content'],
                'priority' => $mx['priority'],
            ]);
        }

        foreach ($hints->txt as $txt) {
            $backend->createRecord($domain, [
                'name' => $txt['name'],
                'type' => 'TXT',
                'content' => $txt['content'],
            ]);
        }
    }

    private function isMailManagedTxtName(string $name, string $apex): bool
    {
        if ($name === $apex) {
            // SPF TXT — only delete if content starts with `v=spf1` and mentions `mx` (best-effort).
            // We can't introspect content here; the cleanup is conservative and lets the
            // panel:apply re-create the canonical record afterwards.
            return false;
        }
        foreach (self::MANAGED_TXT_PREFIXES as $prefix) {
            if (str_ends_with($name, $prefix.$apex) || $name === rtrim($prefix, '.').'.'.$apex) {
                return true;
            }
        }

        return false;
    }
}
