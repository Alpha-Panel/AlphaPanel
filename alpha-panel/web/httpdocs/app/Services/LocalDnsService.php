<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DnsRecord;
use App\Models\DnsSetting;
use App\Models\DnsTemplate;
use App\Models\DnsZone;
use App\Models\Domain;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LocalDnsService
{
    /**
     * Create a DNS zone for a domain with SOA/NS records and optional template.
     */
    public function createZone(Domain $domain, ?DnsTemplate $template = null): DnsZone
    {
        $settings = DnsSetting::instance();

        return DB::transaction(function () use ($domain, $template, $settings): DnsZone {
            $zone = DnsZone::create([
                'domain_id' => $domain->id,
                'zone_name' => $domain->fqdn,
                'serial' => DnsZone::generateSerial(),
                'status' => 'active',
            ]);

            $pdnsDomainId = DB::table('pdns_domains')->insertGetId([
                'name' => $zone->zone_name,
                'type' => 'NATIVE',
                'master' => null,
                'last_check' => null,
                'notified_serial' => null,
                'account' => '',
            ]);

            DB::table('pdns_domainmetadata')->insert([
                'domain_id' => $pdnsDomainId,
                'kind' => 'SOA-EDIT-API',
                'content' => 'INCEPTION-INCREMENT',
            ]);

            $this->createSoaRecord($zone, $pdnsDomainId, $settings);
            $this->createNsRecords($zone, $pdnsDomainId, $settings);

            $effectiveTemplate = $template
                ?? ($settings->default_template_id ? DnsTemplate::find($settings->default_template_id) : null);

            if ($effectiveTemplate instanceof DnsTemplate) {
                $this->applyTemplate($zone, $effectiveTemplate, $this->buildTemplateVars($zone, $settings));
            }

            Log::info("DNS zone created for {$domain->fqdn}", [
                'zone_id' => $zone->id,
                'pdns_domain_id' => $pdnsDomainId,
            ]);

            return $zone;
        });
    }

    /**
     * Delete the DNS zone and all associated PowerDNS records for a domain.
     */
    public function deleteZone(Domain $domain): void
    {
        $zone = $domain->dnsZone;

        if (! $zone) {
            return;
        }

        DB::transaction(function () use ($zone, $domain): void {
            $pdnsDomainId = $this->getPdnsDomainId($zone);

            if ($pdnsDomainId !== null) {
                DB::table('pdns_records')->where('domain_id', $pdnsDomainId)->delete();
                DB::table('pdns_domainmetadata')->where('domain_id', $pdnsDomainId)->delete();
                DB::table('pdns_domains')->where('id', $pdnsDomainId)->delete();
            }

            $zone->records()->delete();
            $zone->delete();

            Log::info("DNS zone deleted for {$domain->fqdn}");
        });
    }

    /**
     * List DNS records for a zone with optional search filtering.
     */
    public function listRecords(DnsZone $zone, ?string $search = null): Collection
    {
        $query = $zone->records();

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('type')->orderBy('name')->get();
    }

    /**
     * Add a DNS record to both the application database and PowerDNS.
     *
     * @param  array<string, mixed>  $data
     */
    public function addRecord(DnsZone $zone, array $data): DnsRecord
    {
        return DB::transaction(function () use ($zone, $data): DnsRecord {
            $record = DnsRecord::create([
                'dns_zone_id' => $zone->id,
                'name' => $data['name'],
                'type' => $data['type'],
                'content' => $data['content'],
                'ttl' => $data['ttl'] ?? DnsSetting::instance()->default_ttl ?? 3600,
                'priority' => $data['priority'] ?? null,
                'is_managed' => $data['is_managed'] ?? false,
            ]);

            $pdnsDomainId = $this->getPdnsDomainId($zone);

            if ($pdnsDomainId !== null) {
                $this->createPdnsRecord($pdnsDomainId, [
                    'name' => $record->name,
                    'type' => $record->type,
                    'content' => $record->content,
                    'ttl' => $record->ttl,
                    'prio' => $record->priority ?? 0,
                ]);
            }

            $zone->incrementSerial();
            $this->updatePdnsSoaSerial($zone);

            return $record;
        });
    }

    /**
     * Update an existing DNS record in both databases.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateRecord(DnsRecord $record, array $data): DnsRecord
    {
        return DB::transaction(function () use ($record, $data): DnsRecord {
            $oldName = $record->name;
            $oldType = $record->type;
            $oldContent = $record->content;

            $record->update($data);

            $zone = $record->zone;
            $pdnsDomainId = $this->getPdnsDomainId($zone);

            if ($pdnsDomainId !== null) {
                DB::table('pdns_records')
                    ->where('domain_id', $pdnsDomainId)
                    ->where('name', $oldName)
                    ->where('type', $oldType)
                    ->where('content', $oldContent)
                    ->update([
                        'name' => $record->name,
                        'type' => $record->type,
                        'content' => $record->content,
                        'ttl' => $record->ttl,
                        'prio' => $record->priority ?? 0,
                    ]);
            }

            $zone->incrementSerial();
            $this->updatePdnsSoaSerial($zone);

            return $record->refresh();
        });
    }

    /**
     * Delete a DNS record from both databases.
     */
    public function deleteRecord(DnsRecord $record): void
    {
        DB::transaction(function () use ($record): void {
            $zone = $record->zone;
            $pdnsDomainId = $this->getPdnsDomainId($zone);

            if ($pdnsDomainId !== null) {
                DB::table('pdns_records')
                    ->where('domain_id', $pdnsDomainId)
                    ->where('name', $record->name)
                    ->where('type', $record->type)
                    ->where('content', $record->content)
                    ->delete();
            }

            $record->delete();

            $zone->incrementSerial();
            $this->updatePdnsSoaSerial($zone);
        });
    }

    /**
     * Full re-sync: rebuild all PowerDNS records from application records.
     *
     * Useful for repair/recovery when PowerDNS tables drift out of sync.
     */
    public function syncToPowerDns(DnsZone $zone): void
    {
        $pdnsDomainId = $this->getPdnsDomainId($zone);

        if ($pdnsDomainId === null) {
            Log::error("Cannot sync zone {$zone->zone_name}: no matching pdns_domains entry");

            return;
        }

        DB::transaction(function () use ($zone, $pdnsDomainId): void {
            DB::table('pdns_records')->where('domain_id', $pdnsDomainId)->delete();

            $settings = DnsSetting::instance();

            // Re-create SOA record in PowerDNS
            $this->createPdnsRecord($pdnsDomainId, [
                'name' => $zone->zone_name,
                'type' => 'SOA',
                'content' => $this->buildSoaContent($settings, $zone),
                'ttl' => $settings->soa_minimum_ttl ?? 3600,
                'prio' => 0,
            ]);

            // Re-create NS records in PowerDNS
            foreach ($settings->getNameservers() as $ns) {
                $this->createPdnsRecord($pdnsDomainId, [
                    'name' => $zone->zone_name,
                    'type' => 'NS',
                    'content' => $ns,
                    'ttl' => $settings->default_ttl ?? 3600,
                    'prio' => 0,
                ]);
            }

            // Re-create all application records in PowerDNS
            $zone->records->each(function (DnsRecord $record) use ($pdnsDomainId): void {
                $this->createPdnsRecord($pdnsDomainId, [
                    'name' => $record->name,
                    'type' => $record->type,
                    'content' => $record->content,
                    'ttl' => $record->ttl,
                    'prio' => $record->priority ?? 0,
                ]);
            });

            Log::info("PowerDNS records re-synced for zone {$zone->zone_name}");
        });
    }

    /**
     * Apply a DNS template to a zone, resolving placeholders.
     *
     * @param  array<string, string>  $vars
     */
    public function applyTemplate(DnsZone $zone, DnsTemplate $template, array $vars): void
    {
        $pdnsDomainId = $this->getPdnsDomainId($zone);

        $template->records->each(function ($templateRecord) use ($zone, $pdnsDomainId, $vars): void {
            $name = $this->resolvePlaceholder($templateRecord->name, $vars);
            $content = $this->resolvePlaceholder($templateRecord->content, $vars);

            DnsRecord::create([
                'dns_zone_id' => $zone->id,
                'name' => $name,
                'type' => $templateRecord->type,
                'content' => $content,
                'ttl' => $templateRecord->ttl,
                'priority' => $templateRecord->priority,
                'is_managed' => true,
            ]);

            if ($pdnsDomainId !== null) {
                $this->createPdnsRecord($pdnsDomainId, [
                    'name' => $name,
                    'type' => $templateRecord->type,
                    'content' => $content,
                    'ttl' => $templateRecord->ttl,
                    'prio' => $templateRecord->priority ?? 0,
                ]);
            }
        });
    }

    /**
     * Get a summary of the DNS zone for a domain.
     *
     * @return array{
     *     exists: bool,
     *     zone_name: string|null,
     *     serial: int|null,
     *     status: string|null,
     *     record_count: int,
     *     nameservers: list<string>
     * }
     */
    public function getZoneSummary(Domain $domain): array
    {
        $zone = $domain->dnsZone;

        if (! $zone) {
            return [
                'exists' => false,
                'zone_name' => null,
                'serial' => null,
                'status' => null,
                'record_count' => 0,
                'nameservers' => [],
            ];
        }

        $settings = DnsSetting::instance();

        return [
            'exists' => true,
            'zone_name' => $zone->zone_name,
            'serial' => $zone->serial,
            'status' => $zone->status,
            'record_count' => $zone->records()->count(),
            'nameservers' => $settings->getNameservers(),
        ];
    }

    /**
     * Look up the PowerDNS domain ID for a zone.
     */
    private function getPdnsDomainId(DnsZone $zone): ?int
    {
        $id = DB::table('pdns_domains')->where('name', $zone->zone_name)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Replace template placeholders with actual values.
     *
     * @param  array<string, string>  $vars
     */
    private function resolvePlaceholder(string $text, array $vars): string
    {
        $search = array_map(fn (string $key): string => "{{$key}}", array_keys($vars));
        $replace = array_values($vars);

        return str_replace($search, $replace, $text);
    }

    /**
     * Insert a record into the pdns_records table.
     *
     * @param  array<string, mixed>  $data
     */
    private function createPdnsRecord(int $pdnsDomainId, array $data): void
    {
        DB::table('pdns_records')->insert([
            'domain_id' => $pdnsDomainId,
            'name' => $data['name'],
            'type' => $data['type'],
            'content' => $data['content'],
            'ttl' => $data['ttl'],
            'prio' => $data['prio'] ?? 0,
            'disabled' => false,
            'ordername' => null,
            'auth' => true,
        ]);
    }

    /**
     * Build the SOA record content string.
     *
     * Format: {primary_ns} {admin_email} {serial} {refresh} {retry} {expire} {minimum_ttl}
     */
    private function buildSoaContent(DnsSetting $settings, DnsZone $zone): string
    {
        $adminEmail = str_replace('@', '.', $settings->soa_admin_email ?? 'hostmaster.example.com');

        return implode(' ', [
            $settings->ns1 ?? 'ns1.example.com',
            $adminEmail,
            $zone->serial,
            $settings->soa_refresh ?? 3600,
            $settings->soa_retry ?? 900,
            $settings->soa_expire ?? 604800,
            $settings->soa_minimum_ttl ?? 3600,
        ]);
    }

    /**
     * Create the SOA record in both application and PowerDNS tables.
     */
    private function createSoaRecord(DnsZone $zone, int $pdnsDomainId, DnsSetting $settings): void
    {
        $soaContent = $this->buildSoaContent($settings, $zone);

        DnsRecord::create([
            'dns_zone_id' => $zone->id,
            'name' => $zone->zone_name,
            'type' => 'SOA',
            'content' => $soaContent,
            'ttl' => $settings->soa_minimum_ttl ?? 3600,
            'priority' => null,
            'is_managed' => true,
        ]);

        $this->createPdnsRecord($pdnsDomainId, [
            'name' => $zone->zone_name,
            'type' => 'SOA',
            'content' => $soaContent,
            'ttl' => $settings->soa_minimum_ttl ?? 3600,
            'prio' => 0,
        ]);
    }

    /**
     * Create NS records in both application and PowerDNS tables.
     */
    private function createNsRecords(DnsZone $zone, int $pdnsDomainId, DnsSetting $settings): void
    {
        foreach ($settings->getNameservers() as $ns) {
            DnsRecord::create([
                'dns_zone_id' => $zone->id,
                'name' => $zone->zone_name,
                'type' => 'NS',
                'content' => $ns,
                'ttl' => $settings->default_ttl ?? 3600,
                'priority' => null,
                'is_managed' => true,
            ]);

            $this->createPdnsRecord($pdnsDomainId, [
                'name' => $zone->zone_name,
                'type' => 'NS',
                'content' => $ns,
                'ttl' => $settings->default_ttl ?? 3600,
                'prio' => 0,
            ]);
        }
    }

    /**
     * Update the SOA serial in the PowerDNS records table after a zone change.
     */
    private function updatePdnsSoaSerial(DnsZone $zone): void
    {
        $pdnsDomainId = $this->getPdnsDomainId($zone);

        if ($pdnsDomainId === null) {
            return;
        }

        $settings = DnsSetting::instance();

        DB::table('pdns_records')
            ->where('domain_id', $pdnsDomainId)
            ->where('type', 'SOA')
            ->update(['content' => $this->buildSoaContent($settings, $zone)]);
    }

    /**
     * Build the template variable map for placeholder resolution.
     *
     * @return array<string, string>
     */
    /**
     * Import DNS records from Cloudflare into a local zone.
     * Creates the zone if it doesn't exist, then copies all CF records.
     * SOA and NS records are created from local settings, not imported from CF.
     *
     * @param  array<int, object>  $cloudflareRecords  Records from CloudflareDnsService::listRecords()
     */
    /**
     * Sync DNS records from Cloudflare into a local zone.
     * Creates the zone if it doesn't exist. Skips records that already
     * exist locally (matched by name+type+content) to avoid duplicates.
     *
     * @param  array<int, object>  $cloudflareRecords  Records from CloudflareDnsService::listRecords()
     */
    public function importFromCloudflare(Domain $domain, array $cloudflareRecords): DnsZone
    {
        $zone = $domain->dnsZone;

        if (! $zone) {
            $zone = $this->createZone($domain);
        }

        $skipTypes = ['SOA', 'NS'];

        return DB::transaction(function () use ($zone, $cloudflareRecords, $skipTypes): DnsZone {
            $pdnsDomainId = $this->getPdnsDomainId($zone);
            $imported = 0;
            $skipped = 0;

            foreach ($cloudflareRecords as $cfRecord) {
                $type = strtoupper((string) ($cfRecord->type ?? ''));

                if (in_array($type, $skipTypes, true)) {
                    continue;
                }

                $name = (string) ($cfRecord->name ?? '');
                $content = (string) ($cfRecord->content ?? '');
                $ttl = (int) ($cfRecord->ttl ?? 3600);
                $priority = isset($cfRecord->priority) ? (int) $cfRecord->priority : null;

                if ($ttl === 1) {
                    $ttl = 3600;
                }

                // Skip if identical record already exists
                $exists = DnsRecord::query()
                    ->where('dns_zone_id', $zone->id)
                    ->where('name', $name)
                    ->where('type', $type)
                    ->where('content', $content)
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                $record = DnsRecord::create([
                    'dns_zone_id' => $zone->id,
                    'name' => $name,
                    'type' => $type,
                    'content' => $content,
                    'ttl' => $ttl,
                    'priority' => $priority,
                    'is_managed' => false,
                ]);

                if ($pdnsDomainId !== null) {
                    $this->createPdnsRecord($pdnsDomainId, [
                        'name' => $record->name,
                        'type' => $record->type,
                        'content' => $record->content,
                        'ttl' => $record->ttl,
                        'priority' => $record->priority,
                    ]);
                }

                $imported++;
            }

            if ($imported > 0) {
                $zone->incrementSerial();
                $this->updatePdnsSoaSerial($zone);
            }

            Log::info("Cloudflare sync to local zone: {$zone->zone_name}", [
                'imported' => $imported,
                'skipped_duplicates' => $skipped,
            ]);

            return $zone;
        });
    }

    private function buildTemplateVars(DnsZone $zone, DnsSetting $settings): array
    {
        return [
            'domain' => $zone->zone_name,
            'ip' => $settings->default_ip ?? '',
            'ns1' => $settings->ns1 ?? '',
            'ns2' => $settings->ns2 ?? '',
            'ns3' => $settings->ns3 ?? '',
            'ns4' => $settings->ns4 ?? '',
            'mail_server' => 'mail.'.$zone->zone_name,
            'soa_admin' => str_replace('@', '.', $settings->soa_admin_email ?? ''),
            'serial' => (string) $zone->serial,
            'refresh' => (string) ($settings->soa_refresh ?? 3600),
            'retry' => (string) ($settings->soa_retry ?? 900),
            'expire' => (string) ($settings->soa_expire ?? 604800),
            'minimum' => (string) ($settings->soa_minimum_ttl ?? 3600),
        ];
    }
}
