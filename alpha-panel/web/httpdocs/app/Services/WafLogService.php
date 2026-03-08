<?php

namespace App\Services;

use App\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class WafLogService
{
    private string $auditLogPath;

    public function __construct()
    {
        $composeRoot = rtrim((string) config('panel.compose_project_root', '/docker_compose_project_root'), '/');
        $this->auditLogPath = $composeRoot.'/frankenphp/coraza-logs/audit.log';
    }

    /**
     * @return array<int, array{
     *   ts: string|null,
     *   host: string,
     *   ip: string,
     *   method: string,
     *   uri: string,
     *   rule_id: string,
     *   message: string,
     *   action: string
     * }>
     */
    public function getDomainEntries(Domain $domain, array $filters = []): array
    {
        if (! is_file($this->auditLogPath)) {
            return [];
        }

        $maxLines = max(100, min((int) ($filters['max_lines'] ?? 4000), 30000));
        $lines = $this->tailLines($this->auditLogPath, $maxLines);

        $ipFilter = trim((string) ($filters['ip'] ?? ''));
        $ruleFilter = trim((string) ($filters['rule_id'] ?? ''));
        $queryFilter = trim((string) ($filters['q'] ?? ''));
        $blockedOnly = (bool) ($filters['blocked_only'] ?? false);
        $sinceIso = trim((string) ($filters['since'] ?? ''));
        $since = $sinceIso !== '' ? $this->safeDate($sinceIso) : null;

        $entries = collect();
        foreach ($lines as $line) {
            $entry = $this->parseLine($line);
            if ($entry === null) {
                continue;
            }

            if ($entry['host'] !== $domain->fqdn) {
                continue;
            }

            if ($since !== null && $entry['ts'] !== null) {
                $entryTs = $this->safeDate($entry['ts']);
                if ($entryTs !== null && $entryTs->lessThanOrEqualTo($since)) {
                    continue;
                }
            }

            if ($ipFilter !== '' && stripos($entry['ip'], $ipFilter) === false) {
                continue;
            }

            if ($ruleFilter !== '' && stripos($entry['rule_id'], $ruleFilter) === false) {
                continue;
            }

            if ($queryFilter !== '') {
                $haystack = implode(' ', [
                    $entry['uri'],
                    $entry['message'],
                    $entry['rule_id'],
                    $entry['action'],
                ]);
                if (stripos($haystack, $queryFilter) === false) {
                    continue;
                }
            }

            if ($blockedOnly && ! in_array($entry['action'], ['deny', 'block'], true)) {
                continue;
            }

            $entries->push($entry);
        }

        return $entries->sortByDesc(fn ($entry) => $entry['ts'] ?? '')->take(500)->values()->all();
    }

    /**
     * @return array<int, string>
     */
    private function tailLines(string $path, int $lines): array
    {
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return [];
        }

        $buffer = '';
        $chunkSize = 4096;
        $lineCount = 0;
        fseek($fp, 0, SEEK_END);
        $position = ftell($fp);

        while ($position > 0 && $lineCount <= $lines) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;
            fseek($fp, $position);
            $chunk = fread($fp, $readSize);
            if ($chunk === false) {
                break;
            }
            $buffer = $chunk.$buffer;
            $lineCount = substr_count($buffer, "\n");
        }

        fclose($fp);

        $allLines = preg_split("/\r\n|\n|\r/", trim($buffer)) ?: [];

        return array_slice($allLines, -$lines);
    }

    /**
     * @return array{
     *   ts: string|null,
     *   host: string,
     *   ip: string,
     *   method: string,
     *   uri: string,
     *   rule_id: string,
     *   message: string,
     *   action: string
     * }|null
     */
    private function parseLine(string $line): ?array
    {
        $decoded = json_decode(trim($line), true);
        if (! is_array($decoded)) {
            return null;
        }

        $host = $this->stringOrNull($decoded['transaction']['request']['headers']['Host'] ?? null)
            ?? $this->stringOrNull($decoded['transaction']['host_ip'] ?? null)
            ?? '';
        $ip = $this->stringOrNull($decoded['transaction']['client_ip'] ?? null) ?? '-';
        $uri = $this->stringOrNull($decoded['transaction']['request']['uri'] ?? null) ?? '-';
        $method = $this->stringOrNull($decoded['transaction']['request']['method'] ?? null) ?? '-';
        $ts = $this->stringOrNull($decoded['transaction']['time_stamp'] ?? null);

        $firstMessage = $decoded['transaction']['messages'][0] ?? [];
        $ruleId = $this->stringOrNull($firstMessage['details']['ruleId'] ?? null) ?? '-';
        $message = $this->stringOrNull($firstMessage['message'] ?? null) ?? '-';
        $action = strtolower($this->stringOrNull($firstMessage['details']['action'] ?? null) ?? 'log');

        return [
            'ts' => $this->normalizeDateString($ts),
            'host' => strtolower(trim($host)),
            'ip' => $ip,
            'method' => $method,
            'uri' => $uri,
            'rule_id' => $ruleId,
            'message' => $message,
            'action' => $action,
        ];
    }

    private function normalizeDateString(?string $value): ?string
    {
        $dt = $this->safeDate($value);

        return $dt?->toIso8601String();
    }

    private function safeDate(?string $value): ?CarbonImmutable
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
}
