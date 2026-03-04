<?php

namespace App\Services;

use Cloudflare\API\Adapter\Guzzle;
use Cloudflare\API\Auth\APIKey;
use Cloudflare\API\Configurations\FirewallRuleOptions;
use Cloudflare\API\Endpoints\DNS;
use Cloudflare\API\Endpoints\EndpointException;
use Cloudflare\API\Endpoints\Firewall;
use Cloudflare\API\Endpoints\Zones;
use Illuminate\Support\Facades\Log;
use Throwable;

class CloudflareDnsService
{
    private ?Guzzle $adapter = null;

    private ?DNS $dns = null;

    private ?Zones $zones = null;

    private ?Firewall $firewall = null;

    protected function boot(): void
    {
        if ($this->adapter !== null) {
            return;
        }

        $email = config('panel.cloudflare_email');
        $apiKey = config('panel.cloudflare_api_key');

        if (empty($email) || empty($apiKey)) {
            throw new \RuntimeException('Cloudflare API credentials are not configured (email + API key).');
        }

        $auth = new APIKey($email, $apiKey);
        $this->adapter = new Guzzle($auth);
        $this->zones = new Zones($this->adapter);
        $this->dns = new DNS($this->adapter);
        $this->firewall = new Firewall($this->adapter);
    }

    /**
     * Find the Cloudflare Zone ID for a domain.
     *
     * @throws EndpointException
     */
    public function getZoneId(string $domainName): string
    {
        $this->boot();

        return $this->zones->getZoneID($domainName);
    }

    /**
     * List all DNS records for a zone.
     *
     * @return array<int, object>
     *
     * @throws EndpointException
     */
    public function listRecords(string $zoneId, string $search = '', string $order = 'type', string $direction = 'asc'): array
    {
        $this->boot();

        $response = $this->dns->listRecords(
            $zoneId,
            name: $search,
            perPage: 5000,
            order: $order,
            direction: $direction,
            match: 'any',
        );

        return $response->result ?? [];
    }

    /**
     * Add a DNS record to a zone.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws EndpointException
     */
    public function addRecord(string $zoneId, array $data): bool
    {
        $this->boot();

        return $this->dns->addRecord(
            $zoneId,
            $data['type'],
            $data['name'],
            $data['content'],
            $data['ttl'] ?? 1,
            $data['proxied'] ?? false,
            priority: $data['priority'] ?? '',
            data: $data['data'] ?? [],
        );
    }

    /**
     * Update an existing DNS record.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws EndpointException
     */
    public function updateRecord(string $zoneId, string $recordId, array $data): \stdClass
    {
        $this->boot();

        return $this->dns->updateRecordDetails($zoneId, $recordId, $data);
    }

    /**
     * Delete a DNS record.
     *
     */
    public function deleteRecord(string $zoneId, string $recordId): bool
    {
        $this->boot();

        return $this->dns->deleteRecord($zoneId, $recordId);
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
            'priority' => '',
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
     *
     */
    public function addSubdomainRecord(string $apexDomain, string $fqdn, string $ip, bool $proxied = false): bool
    {
        try {
            $zoneId = $this->getZoneId($apexDomain);

            return $this->ensureARecord($zoneId, $fqdn, $ip, $proxied);
        } catch (EndpointException $e) {
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
        } catch (\Throwable $exception) {
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
        } catch (\Throwable $e) {
            Log::error("Failed to delete DNS A record(s) for {$fqdn}: {$e->getMessage()}");

            return 0;
        }
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
    public function getZoneSummary(string $domainName): array
    {
        try {
            $this->boot();
            $zoneId = $this->zones->getZoneID($domainName);
            $zoneResponse = $this->zones->getZoneById($zoneId);
            $zone = $zoneResponse->result ?? null;

            if (! is_object($zone)) {
                return $this->emptyZoneSummary();
            }

            return [
                'exists' => true,
                'zone_id' => (string) ($zone->id ?? $zoneId),
                'zone_name' => (string) ($zone->name ?? $domainName),
                'status' => isset($zone->status) ? (string) $zone->status : null,
                'name_servers' => $this->normalizeStringList($zone->name_servers ?? []),
                'original_name_servers' => $this->normalizeStringList($zone->original_name_servers ?? []),
            ];
        } catch (\Throwable $e) {
            Log::info("Cloudflare zone lookup failed for {$domainName}: {$e->getMessage()}");

            return $this->emptyZoneSummary();
        }
    }

    public function getRecordDetails(string $zoneId, string $recordId): ?object
    {
        $this->boot();
        $record = $this->dns->getRecordDetails($zoneId, $recordId);

        return is_object($record) ? $record : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getZoneSetting(string $zoneId, string $setting): ?array
    {
        try {
            $this->boot();
            $response = $this->adapter->get("zones/{$zoneId}/settings/{$setting}");
            $body = json_decode((string) $response->getBody(), true);

            if (! is_array($body) || ! ($body['success'] ?? false)) {
                return null;
            }

            return is_array($body['result'] ?? null) ? $body['result'] : null;
        } catch (Throwable $exception) {
            Log::warning("Cloudflare setting fetch failed for {$zoneId}/{$setting}: {$exception->getMessage()}");

            return null;
        }
    }

    /**
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
     * @return array<string, mixed>|null
     */
    public function updateZoneSetting(string $zoneId, string $setting, mixed $value): ?array
    {
        try {
            $this->boot();
            $response = $this->adapter->patch("zones/{$zoneId}/settings/{$setting}", [
                'value' => $value,
            ]);
            $body = json_decode((string) $response->getBody(), true);

            if (! is_array($body) || ! ($body['success'] ?? false)) {
                return null;
            }

            return is_array($body['result'] ?? null) ? $body['result'] : null;
        } catch (Throwable $exception) {
            Log::warning("Cloudflare setting update failed for {$zoneId}/{$setting}: {$exception->getMessage()}");

            return null;
        }
    }

    public function purgeZoneCache(string $zoneId): bool
    {
        try {
            $this->boot();

            return $this->zones->cachePurgeEverything($zoneId);
        } catch (Throwable $exception) {
            Log::warning("Cloudflare cache purge failed for {$zoneId}: {$exception->getMessage()}");

            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDnssecStatus(string $zoneId): ?array
    {
        try {
            $this->boot();
            $response = $this->adapter->get("zones/{$zoneId}/dnssec");
            $body = json_decode((string) $response->getBody(), true);

            if (! is_array($body) || ! ($body['success'] ?? false)) {
                return null;
            }

            return is_array($body['result'] ?? null) ? $body['result'] : null;
        } catch (Throwable $exception) {
            Log::warning("Cloudflare DNSSEC fetch failed for {$zoneId}: {$exception->getMessage()}");

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateDnssecStatus(string $zoneId, string $status): ?array
    {
        try {
            $this->boot();
            $response = $this->adapter->patch("zones/{$zoneId}/dnssec", [
                'status' => $status,
            ]);
            $body = json_decode((string) $response->getBody(), true);

            if (! is_array($body) || ! ($body['success'] ?? false)) {
                return null;
            }

            return is_array($body['result'] ?? null) ? $body['result'] : null;
        } catch (Throwable $exception) {
            Log::warning("Cloudflare DNSSEC update failed for {$zoneId}: {$exception->getMessage()}");

            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listFirewallRules(string $zoneId): array
    {
        try {
            $this->boot();
            $response = $this->firewall->listFirewallRules($zoneId, perPage: 100);
            $rules = $response->result ?? [];

            if (! is_array($rules)) {
                return [];
            }

            return array_values(array_filter(array_map(function ($rule): ?array {
                if (! is_object($rule)) {
                    return null;
                }

                $filter = is_object($rule->filter ?? null) ? $rule->filter : null;

                return [
                    'id' => (string) ($rule->id ?? ''),
                    'action' => (string) ($rule->action ?? ''),
                    'description' => (string) ($rule->description ?? ''),
                    'priority' => isset($rule->priority) ? (int) $rule->priority : null,
                    'paused' => (bool) ($rule->paused ?? false),
                    'expression' => (string) ($filter?->expression ?? ''),
                ];
            }, $rules)));
        } catch (Throwable $exception) {
            Log::warning("Cloudflare firewall rules fetch failed for {$zoneId}: {$exception->getMessage()}");

            return [];
        }
    }

    public function createFirewallRule(
        string $zoneId,
        string $expression,
        string $action,
        ?string $description = null,
        ?int $priority = null,
    ): bool {
        try {
            $this->boot();
            $options = new FirewallRuleOptions;

            match ($action) {
                'allow' => $options->setActionAllow(),
                'challenge' => $options->setActionChallenge(),
                'js_challenge' => $options->setActionJsChallenge(),
                'log' => $options->setActionLog(),
                default => $options->setActionBlock(),
            };

            return $this->firewall->createFirewallRule($zoneId, $expression, $options, $description, $priority);
        } catch (Throwable $exception) {
            Log::warning("Cloudflare firewall rule create failed for {$zoneId}: {$exception->getMessage()}");

            return false;
        }
    }

    public function deleteFirewallRule(string $zoneId, string $ruleId): bool
    {
        try {
            $this->boot();

            return $this->firewall->deleteFirewallRule($zoneId, $ruleId);
        } catch (Throwable $exception) {
            Log::warning("Cloudflare firewall rule delete failed for {$zoneId}/{$ruleId}: {$exception->getMessage()}");

            return false;
        }
    }

    /**
     * Ensure an apex domain zone exists in Cloudflare.
     *
     * @throws \Throwable
     */
    public function ensureZoneExists(string $domainName): void
    {
        $this->boot();

        try {
            $this->zones->getZoneID($domainName);

            return;
        } catch (EndpointException) {
            // Zone does not exist yet, continue and create it.
        }

        $this->zones->addZone($domainName);
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
     * @throws EndpointException
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
     * @throws EndpointException
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
