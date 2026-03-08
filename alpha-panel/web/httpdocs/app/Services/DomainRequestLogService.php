<?php

namespace App\Services;

use App\Enums\DomainType;
use App\Models\Domain;
use Carbon\CarbonImmutable;

class DomainRequestLogService
{
    public function __construct(private PortainerService $portainer) {}

    /**
     * @param  array{q?: string, ip?: string, since?: string, before?: string, limit?: int}  $filters
     * @return array<int, array{
     *   ts: string|null,
     *   type: string,
     *   level: string,
     *   ip: string,
     *   request: string,
     *   status: string,
     *   message: string,
     *   source: string
     * }>
     */
    public function getDomainEntries(Domain $domain, array $filters = []): array
    {
        $limit = max(50, min((int) ($filters['limit'] ?? 100), 250));
        $maxLines = max(2000, min($limit * 30, 30000));
        $search = trim((string) ($filters['q'] ?? ''));
        $ipFilter = trim((string) ($filters['ip'] ?? ''));
        $since = $this->safeDate(trim((string) ($filters['since'] ?? '')));
        $before = $this->safeDate(trim((string) ($filters['before'] ?? '')));

        $entries = [];
        foreach ($this->resolveLogTargets($domain) as $target) {
            $lines = $this->tailFile($target['container'], $target['path'], $maxLines);
            foreach ($lines as $line) {
                $parsed = $this->parseLogLine($line, $target['default_type'], $target['path']);
                if ($parsed === null) {
                    continue;
                }

                if ($since !== null && $parsed['ts'] !== null) {
                    $entryTs = $this->safeDate($parsed['ts']);
                    if ($entryTs !== null && $entryTs->lessThanOrEqualTo($since)) {
                        continue;
                    }
                }

                if ($before !== null && $parsed['ts'] !== null) {
                    $entryTs = $this->safeDate($parsed['ts']);
                    if ($entryTs !== null && $entryTs->greaterThanOrEqualTo($before)) {
                        continue;
                    }
                }

                if ($ipFilter !== '' && stripos($parsed['ip'], $ipFilter) === false) {
                    continue;
                }

                if ($search !== '') {
                    $haystack = implode(' ', [
                        $parsed['ip'],
                        $parsed['request'],
                        $parsed['status'],
                        $parsed['message'],
                        $parsed['source'],
                        $parsed['type'],
                    ]);

                    if (stripos($haystack, $search) === false) {
                        continue;
                    }
                }

                $entries[] = $parsed;
            }
        }

        usort($entries, static function (array $a, array $b): int {
            $left = (string) ($a['ts'] ?? '');
            $right = (string) ($b['ts'] ?? '');

            return strcmp($right, $left);
        });

        return array_slice($entries, 0, $limit);
    }

    /**
     * @return array<int, array{container: string, path: string, default_type: string}>
     */
    private function resolveLogTargets(Domain $domain): array
    {
        if ($domain->type === DomainType::ApacheReverseProxy) {
            $logsPath = $domain->getBasePath().'/logs';

            return [
                [
                    'container' => (string) config('panel.php_code_server_container', 'php-code-server'),
                    'path' => $logsPath.'/access.log',
                    'default_type' => 'access',
                ],
                [
                    'container' => (string) config('panel.php_code_server_container', 'php-code-server'),
                    'path' => $logsPath.'/error.log',
                    'default_type' => 'error',
                ],
            ];
        }

        return [
            [
                'container' => (string) config('panel.frankenphp_container', 'frankenphp'),
                'path' => '/var/log/caddy/'.$domain->fqdn.'.log',
                'default_type' => 'access',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function tailFile(string $container, string $path, int $maxLines): array
    {
        try {
            $result = $this->portainer->execInContainer($container, [
                'tail',
                '-n',
                (string) $maxLines,
                $path,
            ], timeout: 20);
        } catch (\Throwable) {
            return [];
        }

        if (! $result->isSuccessful()) {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", trim($result->output));
        if (! is_array($lines)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            $lines,
        ), static fn (string $line): bool => $line !== ''));
    }

    /**
     * @return array{
     *   ts: string|null,
     *   type: string,
     *   level: string,
     *   ip: string,
     *   request: string,
     *   status: string,
     *   message: string,
     *   source: string
     * }|null
     */
    private function parseLogLine(string $line, string $defaultType, string $source): ?array
    {
        $parsedJson = $this->parseJsonLine($line, $defaultType, $source);
        if ($parsedJson !== null) {
            return $parsedJson;
        }

        $apacheAccess = $this->parseApacheAccessLine($line, $source);
        if ($apacheAccess !== null) {
            return $apacheAccess;
        }

        $apacheError = $this->parseApacheErrorLine($line, $source);
        if ($apacheError !== null) {
            return $apacheError;
        }

        return [
            'ts' => $this->normalizeDateString($line),
            'type' => $defaultType,
            'level' => $defaultType === 'error' ? 'error' : 'info',
            'ip' => '-',
            'request' => '-',
            'status' => '-',
            'message' => $line,
            'source' => $source,
        ];
    }

    /**
     * @return array{
     *   ts: string|null,
     *   type: string,
     *   level: string,
     *   ip: string,
     *   request: string,
     *   status: string,
     *   message: string,
     *   source: string
     * }|null
     */
    private function parseJsonLine(string $line, string $defaultType, string $source): ?array
    {
        $decoded = json_decode($line, true);
        if (! is_array($decoded)) {
            return null;
        }

        $ip = $this->resolveJsonRequestIp($decoded);
        $method = $this->stringOrNull($decoded['request']['method'] ?? null) ?? '-';
        $uri = $this->stringOrNull($decoded['request']['uri'] ?? null) ?? '-';
        $statusCode = $this->stringOrNull($decoded['status'] ?? $decoded['status_code'] ?? null) ?? '-';
        $level = strtolower($this->stringOrNull($decoded['level'] ?? null) ?? ($defaultType === 'error' ? 'error' : 'info'));
        $message = $this->stringOrNull($decoded['msg'] ?? $decoded['message'] ?? null) ?? '-';

        return [
            'ts' => $this->normalizeDateString(
                $this->stringOrNull($decoded['ts'] ?? $decoded['time'] ?? $decoded['timestamp'] ?? null),
            ),
            'type' => str_contains($level, 'error') ? 'error' : $defaultType,
            'level' => $level,
            'ip' => $ip,
            'request' => trim($method.' '.$uri),
            'status' => $statusCode,
            'message' => $message,
            'source' => $source,
        ];
    }

    private function resolveJsonRequestIp(array $decoded): string
    {
        $ip = $this->stringOrNull($decoded['request']['client_ip'] ?? null)
            ?? $this->stringOrNull($decoded['request']['remote_ip'] ?? null)
            ?? $this->stringOrNull($decoded['remote_ip'] ?? null)
            ?? $this->extractRequestHeaderIp($decoded, 'CF-Connecting-IP')
            ?? $this->extractRequestHeaderIp($decoded, 'X-Forwarded-For', useFirstCsvToken: true);

        return $ip ?? '-';
    }

    private function extractRequestHeaderIp(array $decoded, string $headerName, bool $useFirstCsvToken = false): ?string
    {
        $headers = $decoded['request']['headers'] ?? null;
        if (! is_array($headers)) {
            return null;
        }

        $headerValue = null;
        foreach ($headers as $key => $value) {
            if (! is_string($key) || strcasecmp($key, $headerName) !== 0) {
                continue;
            }

            $headerValue = $value;
            break;
        }

        if ($headerValue === null) {
            return null;
        }

        $normalized = $this->normalizeHeaderValue($headerValue);
        if ($normalized === null) {
            return null;
        }

        if (! $useFirstCsvToken) {
            return $normalized;
        }

        $firstToken = trim(explode(',', $normalized)[0] ?? '');

        return $firstToken !== '' ? $firstToken : null;
    }

    private function normalizeHeaderValue(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $normalized = $this->normalizeHeaderValue($item);
                if ($normalized !== null) {
                    return $normalized;
                }
            }

            return null;
        }

        $stringValue = $this->stringOrNull($value);
        if ($stringValue === null) {
            return null;
        }

        $stringValue = trim($stringValue);

        return $stringValue !== '' ? $stringValue : null;
    }

    /**
     * @return array{
     *   ts: string|null,
     *   type: string,
     *   level: string,
     *   ip: string,
     *   request: string,
     *   status: string,
     *   message: string,
     *   source: string
     * }|null
     */
    private function parseApacheAccessLine(string $line, string $source): ?array
    {
        $pattern = '/^(?<ip>\S+) \S+ \S+ \[(?<time>[^\]]+)\] "(?<method>[A-Z]+) (?<uri>[^"]+?) (?<proto>[^"]+)" (?<status>\d{3}|-) (?<bytes>\S+)/';
        if (! preg_match($pattern, $line, $matches)) {
            return null;
        }

        return [
            'ts' => $this->normalizeDateString((string) ($matches['time'] ?? '')),
            'type' => 'access',
            'level' => 'info',
            'ip' => (string) ($matches['ip'] ?? '-'),
            'request' => trim(((string) ($matches['method'] ?? '-')).' '.((string) ($matches['uri'] ?? '-'))),
            'status' => (string) ($matches['status'] ?? '-'),
            'message' => $line,
            'source' => $source,
        ];
    }

    /**
     * @return array{
     *   ts: string|null,
     *   type: string,
     *   level: string,
     *   ip: string,
     *   request: string,
     *   status: string,
     *   message: string,
     *   source: string
     * }|null
     */
    private function parseApacheErrorLine(string $line, string $source): ?array
    {
        $timePattern = '/^\[(?<time>[^\]]+)\]\s+\[(?<module>[^\]]+)\]\s+\[pid\s+\d+(?::tid\s+\d+)?\](?:\s+\[client\s+(?<client>[^\]]+)\])?\s*(?<message>.*)$/';
        if (! preg_match($timePattern, $line, $matches)) {
            return null;
        }

        $client = trim((string) ($matches['client'] ?? ''));
        $ip = $client !== '' ? explode(':', $client)[0] : '-';

        return [
            'ts' => $this->normalizeDateString((string) ($matches['time'] ?? '')),
            'type' => 'error',
            'level' => strtolower((string) ($matches['module'] ?? 'error')),
            'ip' => $ip,
            'request' => '-',
            'status' => '-',
            'message' => trim((string) ($matches['message'] ?? $line)),
            'source' => $source,
        ];
    }

    private function normalizeDateString(?string $value): ?string
    {
        $date = $this->safeDate($value);
        if ($date !== null) {
            return $date->toIso8601String();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $patterns = [
            '/(?<ts>\d{4}-\d{2}-\d{2}T[^\s]+)/',
            '/(?<ts>\d{2}\/[A-Za-z]{3}\/\d{4}:\d{2}:\d{2}:\d{2} [+\-]\d{4})/',
            '/(?<ts>\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2})/',
            '/^\[(?<ts>[^\]]+)\]/',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $value, $matches)) {
                continue;
            }

            $candidate = $matches['ts'] ?? null;
            $candidateDate = $this->safeDate(is_string($candidate) ? $candidate : null);
            if ($candidateDate !== null) {
                return $candidateDate->toIso8601String();
            }
        }

        return null;
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
