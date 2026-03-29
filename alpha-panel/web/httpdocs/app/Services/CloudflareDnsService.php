<?php

namespace App\Services;

use App\Exceptions\CloudflareException;
use Illuminate\Support\Facades\Log;
use Throwable;

class CloudflareDnsService
{
    private ?CloudflareClient $client = null;

    protected function boot(): void
    {
        if ($this->client !== null) {
            return;
        }

        $this->client = app(CloudflareClient::class);
    }

    /**
     * Find the Cloudflare Zone ID for a domain.
     *
     * @throws CloudflareException
     */
    public function getZoneId(string $domainName): string
    {
        $this->boot();

        $response = $this->client->get('zones', ['name' => $domainName]);
        $zones = $response['result'] ?? [];

        if (empty($zones)) {
            throw new CloudflareException("Zone not found for domain: {$domainName}");
        }

        return (string) $zones[0]['id'];
    }

    /**
     * List all DNS records for a zone.
     *
     * @return array<int, object>
     *
     * @throws CloudflareException
     */
    public function listRecords(string $zoneId, string $search = '', string $order = 'type', string $direction = 'asc'): array
    {
        $this->boot();

        $query = [
            'per_page' => 5000,
            'order' => $order,
            'direction' => $direction,
            'match' => 'any',
        ];

        if ($search !== '') {
            $query['name'] = $search;
        }

        $response = $this->client->get("zones/{$zoneId}/dns_records", $query);
        $records = $response['result'] ?? [];

        return array_map(
            fn (array $record): \stdClass => json_decode(json_encode($record)),
            $records,
        );
    }

    /**
     * Add a DNS record to a zone.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws CloudflareException
     */
    public function addRecord(string $zoneId, array $data): bool
    {
        $this->boot();

        $payload = [
            'type' => $data['type'],
            'name' => $data['name'],
            'content' => $data['content'],
            'ttl' => $data['ttl'] ?? 1,
            'proxied' => $data['proxied'] ?? false,
        ];

        if (! empty($data['priority'])) {
            $payload['priority'] = (int) $data['priority'];
        }

        if (! empty($data['data'])) {
            $payload['data'] = $data['data'];
        }

        $response = $this->client->post("zones/{$zoneId}/dns_records", $payload);

        return $response['success'] ?? false;
    }

    /**
     * Update an existing DNS record.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws CloudflareException
     */
    public function updateRecord(string $zoneId, string $recordId, array $data): \stdClass
    {
        $this->boot();

        $response = $this->client->put("zones/{$zoneId}/dns_records/{$recordId}", $data);

        return json_decode(json_encode($response['result'] ?? []));
    }

    /**
     * Delete a DNS record.
     */
    public function deleteRecord(string $zoneId, string $recordId): bool
    {
        $this->boot();

        try {
            $this->client->delete("zones/{$zoneId}/dns_records/{$recordId}");

            return true;
        } catch (CloudflareException $e) {
            Log::warning("Cloudflare DNS record delete failed for {$zoneId}/{$recordId}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Get details for a specific DNS record.
     *
     * @throws CloudflareException
     */
    public function getRecordDetails(string $zoneId, string $recordId): ?object
    {
        $this->boot();

        $response = $this->client->get("zones/{$zoneId}/dns_records/{$recordId}");
        $result = $response['result'] ?? null;

        if (! is_array($result)) {
            return null;
        }

        return json_decode(json_encode($result));
    }

    /**
     * Build the data array for a DNS record from request input.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function buildRecordData(array $input): array
    {
        $type = $input['record_type'];
        $base = [
            'type' => $type,
            'name' => $input['name'],
            'content' => $input['content'],
            'ttl' => (int) ($input['ttl'] ?? 1),
            'proxied' => false,
        ];

        if (in_array($type, ['A', 'AAAA', 'CNAME'])) {
            $base['proxied'] = ! empty($input['proxied']);
        } elseif ($type === 'MX') {
            $base['priority'] = (int) ($input['priority'] ?? 10);
        } elseif ($type === 'SRV') {
            $base['name'] = ($input['service'] ?? '').'.'
                .($input['protocol'] ?? '').'.'
                .$input['name'];
            $base['data'] = [
                'priority' => $input['priority'] ?? 0,
                'service' => $input['service'] ?? '',
                'proto' => $input['protocol'] ?? '',
                'weight' => $input['weight'] ?? 0,
                'port' => $input['port'] ?? 0,
                'target' => $input['target'] ?? '',
                'name' => $input['name'],
            ];
            $base['priority'] = $input['priority'] ?? 0;
        } elseif ($type === 'CAA') {
            $base['data'] = [
                'flags' => $input['flags'] ?? 0,
                'tag' => $input['tag'] ?? 'issue',
                'value' => $input['content'],
            ];
        }

        return $base;
    }

    /**
     * Quickly add an A record for a subdomain.
     */
    public function addSubdomainRecord(string $apexDomain, string $fqdn, string $ip, bool $proxied = false): bool
    {
        try {
            $zoneId = $this->getZoneId($apexDomain);

            return $this->ensureARecord($zoneId, $fqdn, $ip, $proxied);
        } catch (CloudflareException $e) {
            Log::error("Failed to add DNS record for {$fqdn}: {$e->getMessage()}");

            return false;
        }
    }

    public function syncApexBootstrapRecords(string $apexDomain, string $targetIp, bool $proxied): bool
    {
        try {
            $zoneId = $this->getZoneId($apexDomain);
            $wwwDomain = "www.{$apexDomain}";

            $apexSynced = $this->ensureARecord($zoneId, $apexDomain, $targetIp, $proxied);
            $wwwSynced = $this->ensureARecord($zoneId, $wwwDomain, $targetIp, $proxied);
            $issueCaaSynced = $this->ensureCaaRecord($zoneId, $apexDomain, 'issue', 'letsencrypt.org');
            $issueWildCaaSynced = $this->ensureCaaRecord($zoneId, $apexDomain, 'issuewild', 'letsencrypt.org');

            return $apexSynced && $wwwSynced && $issueCaaSynced && $issueWildCaaSynced;
        } catch (Throwable $exception) {
            Log::warning("Cloudflare apex bootstrap DNS sync failed for {$apexDomain}: {$exception->getMessage()}");

            return false;
        }
    }

    public function deleteSubdomainARecords(string $apexDomain, string $fqdn): int
    {
        try {
            $zoneId = $this->getZoneId($apexDomain);
            $records = $this->listRecords($zoneId, $fqdn);

            $deletedCount = 0;

            foreach ($records as $record) {
                $recordId = (string) ($record->id ?? '');
                $recordType = strtoupper((string) ($record->type ?? ''));
                $recordName = trim((string) ($record->name ?? ''));

                if ($recordId === '' || $recordType !== 'A' || $recordName !== $fqdn) {
                    continue;
                }

                if ($this->deleteRecord($zoneId, $recordId)) {
                    $deletedCount++;
                }
            }

            return $deletedCount;
        } catch (Throwable $e) {
            Log::error("Failed to delete DNS A record(s) for {$fqdn}: {$e->getMessage()}");

            return 0;
        }
    }

    /**
     * Get a summary of the Cloudflare zone for a domain.
     *
     * @return array{
     *     exists: bool,
     *     zone_id: string|null,
     *     zone_name: string|null,
     *     status: string|null,
     *     name_servers: array<int, string>,
     *     original_name_servers: array<int, string>
     * }
     */
    public function getZoneSummary(string $domainName): array
    {
        try {
            $this->boot();
            $zoneId = $this->getZoneId($domainName);
            $response = $this->client->get("zones/{$zoneId}");
            $zone = $response['result'] ?? null;

            if (! is_array($zone)) {
                return $this->emptyZoneSummary();
            }

            return [
                'exists' => true,
                'zone_id' => (string) ($zone['id'] ?? $zoneId),
                'zone_name' => (string) ($zone['name'] ?? $domainName),
                'status' => isset($zone['status']) ? (string) $zone['status'] : null,
                'name_servers' => $this->normalizeStringList($zone['name_servers'] ?? []),
                'original_name_servers' => $this->normalizeStringList($zone['original_name_servers'] ?? []),
            ];
        } catch (Throwable $e) {
            Log::info("Cloudflare zone lookup failed for {$domainName}: {$e->getMessage()}");

            return $this->emptyZoneSummary();
        }
    }

    /**
     * Get a single zone setting value.
     *
     * @return array<string, mixed>|null
     */
    public function getZoneSetting(string $zoneId, string $setting): ?array
    {
        try {
            $this->boot();
            $response = $this->client->get("zones/{$zoneId}/settings/{$setting}");
            $result = $response['result'] ?? null;

            return is_array($result) ? $result : null;
        } catch (Throwable $exception) {
            Log::warning("Cloudflare setting fetch failed for {$zoneId}/{$setting}: {$exception->getMessage()}");

            return null;
        }
    }

    /**
     * Get multiple zone settings at once.
     *
     * @param  array<int, string>  $settings
     * @return array<string, mixed>
     */
    public function getZoneSettings(string $zoneId, array $settings): array
    {
        $result = [];

        foreach ($settings as $setting) {
            $result[$setting] = $this->getZoneSetting($zoneId, $setting);
        }

        return $result;
    }

    /**
     * Update a single zone setting.
     *
     * @return array<string, mixed>|null
     */
    public function updateZoneSetting(string $zoneId, string $setting, mixed $value): ?array
    {
        try {
            $this->boot();
            $response = $this->client->patch("zones/{$zoneId}/settings/{$setting}", [
                'value' => $value,
            ]);
            $result = $response['result'] ?? null;

            return is_array($result) ? $result : null;
        } catch (Throwable $exception) {
            Log::warning("Cloudflare setting update failed for {$zoneId}/{$setting}: {$exception->getMessage()}");

            return null;
        }
    }

    /**
     * Purge all cached content for a zone.
     */
    public function purgeZoneCache(string $zoneId): bool
    {
        try {
            $this->boot();
            $response = $this->client->post("zones/{$zoneId}/purge_cache", [
                'purge_everything' => true,
            ]);

            return $response['success'] ?? false;
        } catch (Throwable $exception) {
            Log::warning("Cloudflare cache purge failed for {$zoneId}: {$exception->getMessage()}");

            return false;
        }
    }

    /**
     * Get DNSSEC status for a zone.
     *
     * @return array<string, mixed>|null
     */
    public function getDnssecStatus(string $zoneId): ?array
    {
        try {
            $this->boot();
            $response = $this->client->get("zones/{$zoneId}/dnssec");
            $result = $response['result'] ?? null;

            return is_array($result) ? $result : null;
        } catch (Throwable $exception) {
            Log::warning("Cloudflare DNSSEC fetch failed for {$zoneId}: {$exception->getMessage()}");

            return null;
        }
    }

    /**
     * Update DNSSEC status for a zone.
     *
     * @return array<string, mixed>|null
     */
    public function updateDnssecStatus(string $zoneId, string $status): ?array
    {
        try {
            $this->boot();
            $response = $this->client->patch("zones/{$zoneId}/dnssec", [
                'status' => $status,
            ]);
            $result = $response['result'] ?? null;

            return is_array($result) ? $result : null;
        } catch (Throwable $exception) {
            Log::warning("Cloudflare DNSSEC update failed for {$zoneId}: {$exception->getMessage()}");

            return null;
        }
    }

    /**
     * List custom firewall rules for a zone (via Ruleset Engine API).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFirewallRules(string $zoneId): array
    {
        try {
            $this->boot();
            $rulesetId = $this->getCustomFirewallRulesetId($zoneId);

            if ($rulesetId === null) {
                return [];
            }

            $response = $this->client->get("zones/{$zoneId}/rulesets/{$rulesetId}");
            $rules = $response['result']['rules'] ?? [];

            if (! is_array($rules)) {
                return [];
            }

            return array_values(array_filter(array_map(function (array $rule): ?array {
                return [
                    'id' => (string) ($rule['id'] ?? ''),
                    'action' => (string) ($rule['action'] ?? ''),
                    'description' => (string) ($rule['description'] ?? ''),
                    'priority' => isset($rule['action_parameters']['priority'])
                        ? (int) $rule['action_parameters']['priority']
                        : null,
                    'paused' => ! ($rule['enabled'] ?? true),
                    'expression' => (string) ($rule['expression'] ?? ''),
                ];
            }, $rules)));
        } catch (Throwable $exception) {
            Log::warning("Cloudflare firewall rules fetch failed for {$zoneId}: {$exception->getMessage()}");

            return [];
        }
    }

    /**
     * Create a custom firewall rule for a zone (via Ruleset Engine API).
     */
    public function createFirewallRule(
        string $zoneId,
        string $expression,
        string $action,
        ?string $description = null,
        ?int $priority = null,
    ): bool {
        try {
            $this->boot();
            $rulesetId = $this->getCustomFirewallRulesetId($zoneId);

            // Map legacy action names to Ruleset Engine equivalents.
            $rulesetAction = match ($action) {
                'challenge', 'js_challenge' => 'managed_challenge',
                default => $action,
            };

            $ruleData = [
                'action' => $rulesetAction,
                'expression' => $expression,
            ];

            if ($description !== null) {
                $ruleData['description'] = $description;
            }

            if ($priority !== null) {
                $ruleData['action_parameters'] = ['priority' => $priority];
            }

            if ($rulesetId !== null) {
                $this->client->post("zones/{$zoneId}/rulesets/{$rulesetId}/rules", $ruleData);
            } else {
                // No custom firewall ruleset exists yet; create one with the first rule.
                $this->client->post("zones/{$zoneId}/rulesets", [
                    'name' => 'Custom Firewall Rules',
                    'kind' => 'zone',
                    'phase' => 'http_request_firewall_custom',
                    'rules' => [$ruleData],
                ]);
            }

            return true;
        } catch (Throwable $exception) {
            Log::warning("Cloudflare firewall rule create failed for {$zoneId}: {$exception->getMessage()}");

            return false;
        }
    }

    /**
     * Delete a custom firewall rule from a zone (via Ruleset Engine API).
     */
    public function deleteFirewallRule(string $zoneId, string $ruleId): bool
    {
        try {
            $this->boot();
            $rulesetId = $this->getCustomFirewallRulesetId($zoneId);

            if ($rulesetId === null) {
                Log::warning("Cloudflare firewall rule delete skipped for {$zoneId}/{$ruleId}: no custom firewall ruleset found.");

                return false;
            }

            $this->client->delete("zones/{$zoneId}/rulesets/{$rulesetId}/rules/{$ruleId}");

            return true;
        } catch (Throwable $exception) {
            Log::warning("Cloudflare firewall rule delete failed for {$zoneId}/{$ruleId}: {$exception->getMessage()}");

            return false;
        }
    }

    /**
     * Ensure an apex domain zone exists in Cloudflare.
     *
     * @throws Throwable
     */
    public function ensureZoneExists(string $domainName): void
    {
        $this->boot();

        try {
            $this->getZoneId($domainName);

            return;
        } catch (CloudflareException) {
            // Zone does not exist yet, continue and create it.
        }

        $this->client->post('zones', ['name' => $domainName]);
    }

    /**
     * Find the custom firewall ruleset ID for a zone.
     *
     * @throws CloudflareException
     */
    private function getCustomFirewallRulesetId(string $zoneId): ?string
    {
        $response = $this->client->get("zones/{$zoneId}/rulesets");
        $rulesets = $response['result'] ?? [];

        foreach ($rulesets as $ruleset) {
            if (($ruleset['phase'] ?? '') === 'http_request_firewall_custom') {
                return (string) $ruleset['id'];
            }
        }

        return null;
    }

    /**
     * @return array{
     *     exists: bool,
     *     zone_id: string|null,
     *     zone_name: string|null,
     *     status: string|null,
     *     name_servers: array<int, string>,
     *     original_name_servers: array<int, string>
     * }
     */
    private function emptyZoneSummary(): array
    {
        return [
            'exists' => false,
            'zone_id' => null,
            'zone_name' => null,
            'status' => null,
            'name_servers' => [],
            'original_name_servers' => [],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = array_map(fn ($item) => trim((string) $item), $value);

        return array_values(array_unique(array_filter($items, fn ($item) => $item !== '')));
    }

    /**
     * @throws CloudflareException
     */
    private function ensureARecord(string $zoneId, string $recordName, string $targetIp, bool $proxied): \stdClass|bool
    {
        $records = $this->listRecords($zoneId, $recordName);
        $existingRecordId = null;

        foreach ($records as $record) {
            if (! is_object($record)) {
                continue;
            }

            $recordId = trim((string) ($record->id ?? ''));
            $recordType = strtoupper(trim((string) ($record->type ?? '')));
            $name = trim((string) ($record->name ?? ''));

            if ($recordId === '' || $recordType !== 'A' || $name !== $recordName) {
                continue;
            }

            $existingRecordId = $recordId;
            break;
        }

        $payload = [
            'type' => 'A',
            'name' => $recordName,
            'content' => $targetIp,
            'ttl' => 1,
            'proxied' => $proxied,
        ];

        if ($existingRecordId !== null) {
            return $this->updateRecord($zoneId, $existingRecordId, $payload);
        }

        return $this->addRecord($zoneId, $payload);
    }

    /**
     * @throws CloudflareException
     */
    private function ensureCaaRecord(string $zoneId, string $recordName, string $tag, string $value, int $flags = 0): bool
    {
        $records = $this->listRecords($zoneId, $recordName);

        foreach ($records as $record) {
            if (! is_object($record)) {
                continue;
            }

            $recordType = strtoupper(trim((string) ($record->type ?? '')));
            $name = trim((string) ($record->name ?? ''));

            if ($recordType !== 'CAA' || $name !== $recordName) {
                continue;
            }

            $recordData = $record->data ?? null;
            if (! is_object($recordData)) {
                continue;
            }

            $recordTag = strtolower(trim((string) ($recordData->tag ?? '')));
            $recordValue = trim((string) ($recordData->value ?? ''));
            $recordFlags = (int) ($recordData->flags ?? -1);

            if ($recordTag === strtolower($tag) && $recordValue === $value && $recordFlags === $flags) {
                return true;
            }
        }

        return $this->addRecord($zoneId, [
            'type' => 'CAA',
            'name' => $recordName,
            'content' => $value,
            'ttl' => 1,
            'proxied' => false,
            'data' => [
                'flags' => $flags,
                'tag' => $tag,
                'value' => $value,
            ],
        ]);
    }
}
