<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Domain;
use Illuminate\Support\Facades\Log;
use Throwable;

class MailcowDnsService
{
    public function __construct(
        private DnsProviderFactory $dnsProviderFactory,
        private MailcowApiService $mailcowApiService,
    ) {}

    /**
     * Create all necessary DNS records for mail on a domain.
     * Called when mail is enabled for a domain.
     *
     * @return array{created: list<string>, skipped: list<string>, errors: list<string>}
     */
    public function provisionMailDns(Domain $domain): array
    {
        $result = ['created' => [], 'skipped' => [], 'errors' => []];
        $dnsService = DnsProviderFactory::for($domain);
        $requiredRecords = $this->getRequiredRecords($domain);

        if ($dnsService instanceof CloudflareDnsService) {
            $this->provisionCloudflareRecords($dnsService, $domain, $requiredRecords, $result);
        } else {
            $this->provisionLocalRecords($dnsService, $domain, $requiredRecords, $result);
        }

        Log::info("Mail DNS provisioning for {$domain->fqdn}", $result);

        return $result;
    }

    /**
     * Remove all mail-related DNS records from a domain.
     * Called when mail is disabled.
     *
     * @return array{deleted: list<string>, errors: list<string>}
     */
    public function removeMailDns(Domain $domain): array
    {
        $result = ['deleted' => [], 'errors' => []];
        $dnsService = DnsProviderFactory::for($domain);

        if ($dnsService instanceof CloudflareDnsService) {
            $this->removeCloudflareRecords($dnsService, $domain, $result);
        } else {
            $this->removeLocalRecords($dnsService, $domain, $result);
        }

        Log::info("Mail DNS removal for {$domain->fqdn}", $result);

        return $result;
    }

    /**
     * Get the list of required mail DNS records for a domain.
     *
     * @return list<array{type: string, name: string, content: string, priority: int, ttl: int}>
     */
    public function getRequiredRecords(Domain $domain): array
    {
        $hostname = $this->getMailcowHostname();
        $fqdn = $domain->fqdn;

        $records = [
            [
                'type' => 'MX',
                'name' => $fqdn,
                'content' => $hostname,
                'priority' => 10,
                'ttl' => 3600,
            ],
            [
                'type' => 'TXT',
                'name' => $fqdn,
                'content' => "v=spf1 mx a:{$hostname} ~all",
                'priority' => 0,
                'ttl' => 3600,
            ],
            [
                'type' => 'TXT',
                'name' => "_dmarc.{$fqdn}",
                'content' => "v=DMARC1; p=quarantine; rua=mailto:postmaster@{$fqdn}",
                'priority' => 0,
                'ttl' => 3600,
            ],
            [
                'type' => 'CNAME',
                'name' => "autoconfig.{$fqdn}",
                'content' => $hostname,
                'priority' => 0,
                'ttl' => 3600,
            ],
            [
                'type' => 'CNAME',
                'name' => "autodiscover.{$fqdn}",
                'content' => $hostname,
                'priority' => 0,
                'ttl' => 3600,
            ],
        ];

        $dkimContent = $this->fetchDkimRecord($fqdn);

        if ($dkimContent !== null) {
            $records[] = [
                'type' => 'TXT',
                'name' => "dkim._domainkey.{$fqdn}",
                'content' => $dkimContent,
                'priority' => 0,
                'ttl' => 3600,
            ];
        }

        return $records;
    }

    /**
     * Create global mail hostname DNS records (A records for mail.BASE_DOMAIN, webmail.BASE_DOMAIN).
     * Called once during initial mail setup.
     *
     * @return array{created: list<string>, skipped: list<string>, errors: list<string>}
     */
    public function provisionGlobalRecords(): array
    {
        $result = ['created' => [], 'skipped' => [], 'errors' => []];

        $publicIps = config('panel.server_public_ips', []);
        $ip = is_array($publicIps) && $publicIps !== [] ? (string) $publicIps[0] : '';

        if ($ip === '') {
            $result['errors'][] = 'No public IP configured in panel.server_public_ips';

            return $result;
        }

        $hostname = $this->getMailcowHostname();
        $baseDomain = $this->extractBaseDomain($hostname);

        if ($baseDomain === null) {
            $result['errors'][] = "Cannot determine base domain from mailcow hostname: {$hostname}";

            return $result;
        }

        $globalRecords = [
            ['type' => 'A', 'name' => $hostname, 'content' => $ip, 'priority' => 0, 'ttl' => 3600],
        ];

        $webmailDomain = (string) config('panel.mailcow.webmail_domain', '');

        if ($webmailDomain !== '' && $webmailDomain !== $hostname) {
            $globalRecords[] = ['type' => 'A', 'name' => $webmailDomain, 'content' => $ip, 'priority' => 0, 'ttl' => 3600];
        }

        // Find the domain model for the base domain to resolve the correct DNS provider.
        $zoneDomain = Domain::query()->where('fqdn', $baseDomain)->first();

        if (! $zoneDomain instanceof Domain) {
            // Fall back to Cloudflare directly if the base domain isn't managed as a Domain model.
            $this->provisionGlobalViaCloudflare($baseDomain, $globalRecords, $result);

            return $result;
        }

        $dnsService = DnsProviderFactory::for($zoneDomain);

        if ($dnsService instanceof CloudflareDnsService) {
            $this->provisionGlobalCloudflare($dnsService, $baseDomain, $globalRecords, $result);
        } else {
            $this->provisionGlobalLocal($dnsService, $zoneDomain, $globalRecords, $result);
        }

        Log::info('Global mail DNS provisioning', $result);

        return $result;
    }

    private function getMailcowHostname(): string
    {
        return (string) config('panel.mailcow.hostname', 'mail.example.com');
    }

    /**
     * Extract the base domain from a hostname (e.g. "mail.example.com" => "example.com").
     */
    private function extractBaseDomain(string $hostname): ?string
    {
        $parts = explode('.', $hostname);

        if (count($parts) < 2) {
            return null;
        }

        return implode('.', array_slice($parts, -2));
    }

    private function fetchDkimRecord(string $fqdn): ?string
    {
        try {
            return $this->mailcowApiService->getDkimRecord($fqdn);
        } catch (Throwable $e) {
            Log::warning("Failed to fetch DKIM record for {$fqdn}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Provision mail DNS records via Cloudflare.
     *
     * @param  list<array{type: string, name: string, content: string, priority: int, ttl: int}>  $records
     * @param  array{created: list<string>, skipped: list<string>, errors: list<string>}  $result
     */
    private function provisionCloudflareRecords(
        CloudflareDnsService $dnsService,
        Domain $domain,
        array $records,
        array &$result,
    ): void {
        try {
            $zoneId = $dnsService->getZoneId($domain->getApexDomain());
        } catch (Throwable $e) {
            $result['errors'][] = "Failed to resolve Cloudflare zone for {$domain->getApexDomain()}: {$e->getMessage()}";

            return;
        }

        $existingRecords = $dnsService->listRecords($zoneId);

        foreach ($records as $record) {
            $label = "{$record['type']} {$record['name']}";

            if ($this->cloudflareRecordExists($existingRecords, $record)) {
                $result['skipped'][] = $label;

                continue;
            }

            try {
                $data = [
                    'type' => $record['type'],
                    'name' => $record['name'],
                    'content' => $record['content'],
                    'ttl' => $record['ttl'],
                    'proxied' => false,
                ];

                if ($record['priority'] > 0) {
                    $data['priority'] = $record['priority'];
                }

                $dnsService->addRecord($zoneId, $data);
                $result['created'][] = $label;
            } catch (Throwable $e) {
                $result['errors'][] = "{$label}: {$e->getMessage()}";
            }
        }
    }

    /**
     * Provision mail DNS records via local PowerDNS.
     *
     * @param  list<array{type: string, name: string, content: string, priority: int, ttl: int}>  $records
     * @param  array{created: list<string>, skipped: list<string>, errors: list<string>}  $result
     */
    private function provisionLocalRecords(
        LocalDnsService $dnsService,
        Domain $domain,
        array $records,
        array &$result,
    ): void {
        $zone = $domain->dnsZone;

        if (! $zone) {
            $result['errors'][] = "No local DNS zone found for {$domain->fqdn}";

            return;
        }

        $existingRecords = $dnsService->listRecords($zone);

        foreach ($records as $record) {
            $label = "{$record['type']} {$record['name']}";

            $exists = $existingRecords->contains(function ($existing) use ($record): bool {
                return $existing->type === $record['type']
                    && $existing->name === $record['name']
                    && $existing->content === $record['content'];
            });

            if ($exists) {
                $result['skipped'][] = $label;

                continue;
            }

            try {
                $dnsService->addRecord($zone, $record);
                $result['created'][] = $label;
            } catch (Throwable $e) {
                $result['errors'][] = "{$label}: {$e->getMessage()}";
            }
        }
    }

    /**
     * Remove mail DNS records via Cloudflare.
     *
     * @param  array{deleted: list<string>, errors: list<string>}  $result
     */
    private function removeCloudflareRecords(
        CloudflareDnsService $dnsService,
        Domain $domain,
        array &$result,
    ): void {
        try {
            $zoneId = $dnsService->getZoneId($domain->getApexDomain());
        } catch (Throwable $e) {
            $result['errors'][] = "Failed to resolve Cloudflare zone for {$domain->getApexDomain()}: {$e->getMessage()}";

            return;
        }

        $existingRecords = $dnsService->listRecords($zoneId);
        $fqdn = $domain->fqdn;

        foreach ($existingRecords as $record) {
            $recordId = (string) ($record->id ?? '');
            $recordType = strtoupper((string) ($record->type ?? ''));
            $recordName = (string) ($record->name ?? '');

            if ($recordId === '' || ! $this->isMailRecord($recordType, $recordName, $fqdn)) {
                continue;
            }

            $label = "{$recordType} {$recordName}";

            try {
                $dnsService->deleteRecord($zoneId, $recordId);
                $result['deleted'][] = $label;
            } catch (Throwable $e) {
                $result['errors'][] = "{$label}: {$e->getMessage()}";
            }
        }
    }

    /**
     * Remove mail DNS records via local PowerDNS.
     *
     * @param  array{deleted: list<string>, errors: list<string>}  $result
     */
    private function removeLocalRecords(
        LocalDnsService $dnsService,
        Domain $domain,
        array &$result,
    ): void {
        $zone = $domain->dnsZone;

        if (! $zone) {
            return;
        }

        $existingRecords = $dnsService->listRecords($zone);
        $fqdn = $domain->fqdn;

        foreach ($existingRecords as $record) {
            $recordType = strtoupper((string) ($record->type ?? ''));
            $recordName = (string) ($record->name ?? '');

            if (! $this->isMailRecord($recordType, $recordName, $fqdn)) {
                continue;
            }

            $label = "{$recordType} {$recordName}";

            try {
                $dnsService->deleteRecord($record);
                $result['deleted'][] = $label;
            } catch (Throwable $e) {
                $result['errors'][] = "{$label}: {$e->getMessage()}";
            }
        }
    }

    /**
     * Determine if a DNS record is a mail-related record for the given domain.
     */
    private function isMailRecord(string $type, string $name, string $fqdn): bool
    {
        // MX record on the domain itself
        if ($type === 'MX' && $name === $fqdn) {
            return true;
        }

        // SPF TXT record on the domain itself (starts with "v=spf1")
        if ($type === 'TXT' && $name === $fqdn) {
            // We will match this only when the content contains spf1 — but
            // since we only have name/type here, we match all TXT on the apex
            // conservatively. The caller should verify content if needed.
            // For removal this is acceptable since provisionMailDns only
            // creates the SPF TXT on the apex.
            return false; // Do not blindly remove all TXT on apex — too destructive.
        }

        // DKIM TXT record
        if ($type === 'TXT' && $name === "dkim._domainkey.{$fqdn}") {
            return true;
        }

        // DMARC TXT record
        if ($type === 'TXT' && $name === "_dmarc.{$fqdn}") {
            return true;
        }

        // Autoconfig/autodiscover CNAME records
        if ($type === 'CNAME' && ($name === "autoconfig.{$fqdn}" || $name === "autodiscover.{$fqdn}")) {
            return true;
        }

        return false;
    }

    /**
     * Check whether a record already exists in Cloudflare.
     *
     * @param  array<int, object>  $existingRecords
     * @param  array{type: string, name: string, content: string, priority: int, ttl: int}  $record
     */
    private function cloudflareRecordExists(array $existingRecords, array $record): bool
    {
        foreach ($existingRecords as $existing) {
            $existingType = strtoupper((string) ($existing->type ?? ''));
            $existingName = (string) ($existing->name ?? '');
            $existingContent = (string) ($existing->content ?? '');

            if ($existingType === $record['type']
                && $existingName === $record['name']
                && $existingContent === $record['content']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Provision global mail records via Cloudflare when the base domain is managed in Cloudflare
     * but not tracked as a Domain model.
     *
     * @param  list<array{type: string, name: string, content: string, priority: int, ttl: int}>  $records
     * @param  array{created: list<string>, skipped: list<string>, errors: list<string>}  $result
     */
    private function provisionGlobalViaCloudflare(string $baseDomain, array $records, array &$result): void
    {
        $dnsService = app(CloudflareDnsService::class);

        $this->provisionGlobalCloudflare($dnsService, $baseDomain, $records, $result);
    }

    /**
     * @param  list<array{type: string, name: string, content: string, priority: int, ttl: int}>  $records
     * @param  array{created: list<string>, skipped: list<string>, errors: list<string>}  $result
     */
    private function provisionGlobalCloudflare(
        CloudflareDnsService $dnsService,
        string $baseDomain,
        array $records,
        array &$result,
    ): void {
        try {
            $zoneId = $dnsService->getZoneId($baseDomain);
        } catch (Throwable $e) {
            $result['errors'][] = "Failed to resolve Cloudflare zone for {$baseDomain}: {$e->getMessage()}";

            return;
        }

        $existingRecords = $dnsService->listRecords($zoneId);

        foreach ($records as $record) {
            $label = "{$record['type']} {$record['name']}";

            if ($this->cloudflareRecordExists($existingRecords, $record)) {
                $result['skipped'][] = $label;

                continue;
            }

            try {
                $dnsService->addRecord($zoneId, [
                    'type' => $record['type'],
                    'name' => $record['name'],
                    'content' => $record['content'],
                    'ttl' => $record['ttl'],
                    'proxied' => false,
                ]);
                $result['created'][] = $label;
            } catch (Throwable $e) {
                $result['errors'][] = "{$label}: {$e->getMessage()}";
            }
        }
    }

    /**
     * @param  list<array{type: string, name: string, content: string, priority: int, ttl: int}>  $records
     * @param  array{created: list<string>, skipped: list<string>, errors: list<string>}  $result
     */
    private function provisionGlobalLocal(
        LocalDnsService $dnsService,
        Domain $zoneDomain,
        array $records,
        array &$result,
    ): void {
        $zone = $zoneDomain->dnsZone;

        if (! $zone) {
            $result['errors'][] = "No local DNS zone found for {$zoneDomain->fqdn}";

            return;
        }

        $existingRecords = $dnsService->listRecords($zone);

        foreach ($records as $record) {
            $label = "{$record['type']} {$record['name']}";

            $exists = $existingRecords->contains(function ($existing) use ($record): bool {
                return $existing->type === $record['type']
                    && $existing->name === $record['name']
                    && $existing->content === $record['content'];
            });

            if ($exists) {
                $result['skipped'][] = $label;

                continue;
            }

            try {
                $dnsService->addRecord($zone, $record);
                $result['created'][] = $label;
            } catch (Throwable $e) {
                $result['errors'][] = "{$label}: {$e->getMessage()}";
            }
        }
    }
}
