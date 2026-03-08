<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CrowdSecService
{
    /**
     * @return array{
     *   configured: bool,
     *   has_error: bool,
     *   lapi_online: bool,
     *   status_code: int|null,
     *   active_decisions: int,
     *   recent_alerts_24h: int,
     *   top_scenarios: array<int, array{name: string, count: int}>,
     *   last_sync_at: string
     * }
     */
    public function getSummary(): array
    {
        $baseUrl = $this->baseUrl();
        $apiKey = $this->apiKey();

        if ($baseUrl === '' || $apiKey === '') {
            return $this->emptySummary(false);
        }

        $health = $this->request('GET', '/health');
        $alertsResponse = $this->request('GET', '/v1/alerts', ['limit' => 200]);
        $decisionsResponse = $this->request('GET', '/v1/decisions', ['limit' => 200]);

        $alerts = is_array($alertsResponse['data']) ? $alertsResponse['data'] : [];
        $decisions = is_array($decisionsResponse['data']) ? $decisionsResponse['data'] : [];

        $summary = $this->emptySummary(true);
        $summary['lapi_online'] = $health['ok'];
        $summary['status_code'] = $health['status'];
        $summary['active_decisions'] = count($decisions);
        $summary['recent_alerts_24h'] = $this->countRecentAlerts($alerts);
        $summary['top_scenarios'] = $this->topScenarios($alerts, 5);
        $summary['has_error'] = ! $health['ok'];
        $summary['last_sync_at'] = now()->toIso8601String();

        return $summary;
    }

    /**
     * @return array{
     *   summary: array{
     *     configured: bool,
     *     has_error: bool,
     *     lapi_online: bool,
     *     status_code: int|null,
     *     active_decisions: int,
     *     recent_alerts_24h: int,
     *     top_scenarios: array<int, array{name: string, count: int}>,
     *     last_sync_at: string
     *   },
     *   recent_alerts: array<int, array{
     *     value: string,
     *     scenario: string,
     *     reason: string,
     *     created_at: string|null
     *   }>,
     *   active_decisions: array<int, array{
     *     value: string,
     *     scope: string,
     *     type: string,
     *     origin: string,
     *     scenario: string,
     *     until: string|null
     *   }>
     * }
     */
    public function getDetails(): array
    {
        $summary = $this->getSummary();
        if (! $summary['configured']) {
            return [
                'summary' => $summary,
                'recent_alerts' => [],
                'active_decisions' => [],
            ];
        }

        $alertsResponse = $this->request('GET', '/v1/alerts', ['limit' => 100]);
        $decisionsResponse = $this->request('GET', '/v1/decisions', ['limit' => 100]);

        $alerts = is_array($alertsResponse['data']) ? $alertsResponse['data'] : [];
        $decisions = is_array($decisionsResponse['data']) ? $decisionsResponse['data'] : [];

        return [
            'summary' => $summary,
            'recent_alerts' => $this->mapAlerts($alerts),
            'active_decisions' => $this->mapDecisions($decisions),
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   status: int|null,
     *   data: mixed
     * }
     */
    private function request(string $method, string $path, array $query = []): array
    {
        $url = rtrim($this->baseUrl(), '/').$path;
        $apiKey = $this->apiKey();

        try {
            $response = Http::timeout($this->timeoutSeconds())
                ->acceptJson()
                ->withHeaders([
                    'X-Api-Key' => $apiKey,
                    'x-api-key' => $apiKey,
                    'Authorization' => "Bearer {$apiKey}",
                ])
                ->send($method, $url, ['query' => $query]);

            $decoded = $response->json();
            $data = is_array($decoded) ? $decoded : null;

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            Log::warning('CrowdSec LAPI request failed: '.$e->getMessage(), [
                'url' => $url,
                'path' => $path,
            ]);

            return [
                'ok' => false,
                'status' => null,
                'data' => null,
            ];
        }
    }

    private function countRecentAlerts(array $alerts): int
    {
        $since = CarbonImmutable::now()->subDay();
        $count = 0;

        foreach ($alerts as $alert) {
            if (! is_array($alert)) {
                continue;
            }

            $createdAt = $this->parseDate(
                $this->stringOrNull($alert['created_at'] ?? null)
                ?? $this->stringOrNull($alert['timestamp'] ?? null)
            );

            if ($createdAt !== null && $createdAt->greaterThanOrEqualTo($since)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<int, array{name: string, count: int}>
     */
    private function topScenarios(array $alerts, int $limit): array
    {
        $counts = [];

        foreach ($alerts as $alert) {
            if (! is_array($alert)) {
                continue;
            }

            $name = $this->extractScenario($alert);
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        arsort($counts);

        $result = [];
        foreach (array_slice($counts, 0, $limit, true) as $name => $count) {
            $result[] = [
                'name' => $name,
                'count' => (int) $count,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{
     *   value: string,
     *   scenario: string,
     *   reason: string,
     *   created_at: string|null
     * }>
     */
    private function mapAlerts(array $alerts): array
    {
        return collect($alerts)
            ->filter(fn ($alert): bool => is_array($alert))
            ->map(function (array $alert): array {
                $created = $this->parseDate(
                    $this->stringOrNull($alert['created_at'] ?? null)
                    ?? $this->stringOrNull($alert['timestamp'] ?? null)
                );

                return [
                    'value' => $this->extractValue($alert),
                    'scenario' => $this->extractScenario($alert),
                    'reason' => $this->stringOrNull($alert['message'] ?? null)
                        ?? $this->stringOrNull($alert['reason'] ?? null)
                        ?? '-',
                    'created_at' => $created?->toIso8601String(),
                ];
            })
            ->take(50)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{
     *   value: string,
     *   scope: string,
     *   type: string,
     *   origin: string,
     *   scenario: string,
     *   until: string|null
     * }>
     */
    private function mapDecisions(array $decisions): array
    {
        return collect($decisions)
            ->filter(fn ($decision): bool => is_array($decision))
            ->map(function (array $decision): array {
                $until = $this->parseDate($this->stringOrNull($decision['until'] ?? null));

                return [
                    'value' => $this->stringOrNull($decision['value'] ?? null) ?? '-',
                    'scope' => $this->stringOrNull($decision['scope'] ?? null) ?? '-',
                    'type' => $this->stringOrNull($decision['type'] ?? null) ?? '-',
                    'origin' => $this->stringOrNull($decision['origin'] ?? null) ?? '-',
                    'scenario' => $this->stringOrNull($decision['scenario'] ?? null) ?? '-',
                    'until' => $until?->toIso8601String(),
                ];
            })
            ->take(100)
            ->values()
            ->all();
    }

    private function extractScenario(array $alert): string
    {
        return $this->stringOrNull($alert['scenario'] ?? null)
            ?? $this->stringOrNull($alert['scenario_name'] ?? null)
            ?? $this->stringOrNull($alert['events'][0]['meta']['scenario'] ?? null)
            ?? '-';
    }

    private function extractValue(array $alert): string
    {
        return $this->stringOrNull($alert['value'] ?? null)
            ?? $this->stringOrNull($alert['source']['value'] ?? null)
            ?? $this->stringOrNull($alert['events'][0]['source_ip'] ?? null)
            ?? '-';
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    private function baseUrl(): string
    {
        return trim((string) config('services.crowdsec.lapi_url', ''));
    }

    private function apiKey(): string
    {
        return trim((string) config('services.crowdsec.api_key', ''));
    }

    private function timeoutSeconds(): int
    {
        $value = (int) config('services.crowdsec.timeout', 5);

        return $value > 0 ? $value : 5;
    }

    /**
     * @return array{
     *   configured: bool,
     *   has_error: bool,
     *   lapi_online: bool,
     *   status_code: int|null,
     *   active_decisions: int,
     *   recent_alerts_24h: int,
     *   top_scenarios: array<int, array{name: string, count: int}>,
     *   last_sync_at: string
     * }
     */
    private function emptySummary(bool $configured): array
    {
        return [
            'configured' => $configured,
            'has_error' => !$configured,
            'lapi_online' => false,
            'status_code' => null,
            'active_decisions' => 0,
            'recent_alerts_24h' => 0,
            'top_scenarios' => [],
            'last_sync_at' => now()->toIso8601String(),
        ];
    }
}
