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
     *
     * Single-source-of-truth: DnsZone and DnsRecord are mapped directly onto
     * PowerDNS's native `domains` and `records` tables, so one Eloquent create
     * is sufficient — no dual writes.
     */
    public function createZone(Domain $domain, ?DnsTemplate $template = null): DnsZone
    {
        $settings = DnsSetting::instance();

        return DB::connection('powerdns')->transaction(function () use ($domain, $template, $settings): DnsZone {
            $zone = DnsZone::create([
                'name' => $domain->fqdn,
                'type' => 'NATIVE',
            ]);

            DB::connection('powerdns')->table('domainmetadata')->insert([
                'domain_id' => $zone->id,
                'kind' => 'SOA-EDIT-API',
                'content' => 'INCEPTION-INCREMENT',
            ]);

            $this->createSoaRecord($zone, $settings);
            $this->createNsRecords($zone, $settings);

            $effectiveTemplate = $template
                ?? ($settings->default_template_id ? DnsTemplate::find($settings->default_template_id) : null);

            if ($effectiveTemplate instanceof DnsTemplate) {
                $this->applyTemplate($zone, $effectiveTemplate, $this->buildTemplateVars($zone, $settings));
            }

            Log::info("DNS zone created for {$domain->fqdn}", [
                'zone_id' => $zone->id,
            ]);

            return $zone;
        });
    }

    /**
     * Delete the DNS zone (and all records + metadata via ON DELETE CASCADE).
     */
    public function deleteZone(Domain $domain): void
    {
        $zone = $domain->dnsZone;

        if (! $zone) {
            return;
        }

        $zone->delete();

        Log::info("DNS zone deleted for {$domain->fqdn}");
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
     * Add a DNS record.
     *
     * @param  array<string, mixed>  $data
     */
    public function addRecord(DnsZone $zone, array $data): DnsRecord
    {
        return DB::connection('powerdns')->transaction(function () use ($zone, $data): DnsRecord {
            $record = DnsRecord::create([
                'domain_id' => $zone->id,
                'name' => $data['name'],
                'type' => $data['type'],
                'content' => $data['content'],
                'ttl' => $data['ttl'] ?? DnsSetting::instance()->default_ttl ?? 3600,
                'prio' => $data['priority'] ?? $data['prio'] ?? 0,
            ]);

            $zone->incrementSerial();

            return $record;
        });
    }

    /**
     * Update an existing DNS record.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateRecord(DnsRecord $record, array $data): DnsRecord
    {
        return DB::connection('powerdns')->transaction(function () use ($record, $data): DnsRecord {
            $updates = [
                'name' => $data['name'] ?? $record->name,
                'type' => $data['type'] ?? $record->type,
                'content' => $data['content'] ?? $record->content,
                'ttl' => $data['ttl'] ?? $record->ttl,
                'prio' => $data['priority'] ?? $data['prio'] ?? $record->prio ?? 0,
            ];

            $record->update($updates);

            if ($record->zone) {
                $record->zone->incrementSerial();
            }

            return $record->refresh();
        });
    }

    /**
     * Delete a DNS record.
     */
    public function deleteRecord(DnsRecord $record): void
    {
        DB::connection('powerdns')->transaction(function () use ($record): void {
            $zone = $record->zone;
            $record->delete();

            if ($zone) {
                $zone->incrementSerial();
            }
        });
    }

    /**
     * Apply a DNS template to a zone, resolving placeholders.
     *
     * @param  array<string, string>  $vars
     */
    public function applyTemplate(DnsZone $zone, DnsTemplate $template, array $vars): void
    {
        $template->records->each(function ($templateRecord) use ($zone, $vars): void {
            DnsRecord::create([
                'domain_id' => $zone->id,
                'name' => $this->resolvePlaceholder($templateRecord->name, $vars),
                'type' => $templateRecord->type,
                'content' => $this->resolvePlaceholder($templateRecord->content, $vars),
                'ttl' => $templateRecord->ttl,
                'prio' => $templateRecord->priority ?? 0,
            ]);
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
     * Sync DNS records from Cloudflare into a local zone.
     *
     * Creates the zone if it doesn't exist. Skips records that already
     * exist locally (matched by name+type+content) and SOA/NS records
     * (those come from local settings).
     *
     * @param  array<int, object>  $cloudflareRecords  Records from CloudflareDnsService::listRecords()
     */
    public function importFromCloudflare(Domain $domain, array $cloudflareRecords): DnsZone
    {
        $zone = $domain->dnsZone ?? $this->createZone($domain);

        $skipTypes = ['SOA', 'NS'];

        return DB::connection('powerdns')->transaction(function () use ($zone, $cloudflareRecords, $skipTypes): DnsZone {
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
                $priority = isset($cfRecord->priority) ? (int) $cfRecord->priority : 0;

                if ($ttl === 1) {
                    $ttl = 3600;
                }

                $exists = DnsRecord::query()
                    ->where('domain_id', $zone->id)
                    ->where('name', $name)
                    ->where('type', $type)
                    ->where('content', $content)
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                DnsRecord::create([
                    'domain_id' => $zone->id,
                    'name' => $name,
                    'type' => $type,
                    'content' => $content,
                    'ttl' => $ttl,
                    'prio' => $priority,
                ]);

                $imported++;
            }

            if ($imported > 0) {
                $zone->incrementSerial();
            }

            Log::info("Cloudflare sync to local zone: {$zone->zone_name}", [
                'imported' => $imported,
                'skipped_duplicates' => $skipped,
            ]);

            return $zone;
        });
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
     * Build the SOA record content string.
     *
     * Format: {primary_ns} {admin_email} {serial} {refresh} {retry} {expire} {minimum_ttl}
     */
    private function buildSoaContent(DnsSetting $settings, DnsZone $zone, int $serial): string
    {
        $adminEmail = str_replace('@', '.', $settings->soa_admin_email ?? 'hostmaster.example.com');

        return implode(' ', [
            $settings->ns1 ?? 'ns1.example.com',
            $adminEmail,
            $serial,
            $settings->soa_refresh ?? 3600,
            $settings->soa_retry ?? 900,
            $settings->soa_expire ?? 604800,
            $settings->soa_minimum_ttl ?? 3600,
        ]);
    }

    /**
     * Create the SOA record for a freshly created zone.
     */
    private function createSoaRecord(DnsZone $zone, DnsSetting $settings): void
    {
        $serial = DnsZone::generateSerial();

        DnsRecord::create([
            'domain_id' => $zone->id,
            'name' => $zone->name,
            'type' => 'SOA',
            'content' => $this->buildSoaContent($settings, $zone, $serial),
            'ttl' => $settings->soa_minimum_ttl ?? 3600,
            'prio' => 0,
        ]);
    }

    /**
     * Create NS records for a freshly created zone.
     */
    private function createNsRecords(DnsZone $zone, DnsSetting $settings): void
    {
        foreach ($settings->getNameservers() as $ns) {
            DnsRecord::create([
                'domain_id' => $zone->id,
                'name' => $zone->name,
                'type' => 'NS',
                'content' => $ns,
                'ttl' => $settings->default_ttl ?? 3600,
                'prio' => 0,
            ]);
        }
    }

    /**
     * Build the template variable map for placeholder resolution.
     *
     * @return array<string, string>
     */
    private function buildTemplateVars(DnsZone $zone, DnsSetting $settings): array
    {
        return [
            'domain' => $zone->name,
            'ip' => $settings->default_ip ?? '',
            'ns1' => $settings->ns1 ?? '',
            'ns2' => $settings->ns2 ?? '',
            'ns3' => $settings->ns3 ?? '',
            'ns4' => $settings->ns4 ?? '',
            'mail_server' => 'mail.'.$zone->name,
            'soa_admin' => str_replace('@', '.', $settings->soa_admin_email ?? ''),
            'serial' => (string) $zone->serial,
            'refresh' => (string) ($settings->soa_refresh ?? 3600),
            'retry' => (string) ($settings->soa_retry ?? 900),
            'expire' => (string) ($settings->soa_expire ?? 604800),
            'minimum' => (string) ($settings->soa_minimum_ttl ?? 3600),
        ];
    }
}
