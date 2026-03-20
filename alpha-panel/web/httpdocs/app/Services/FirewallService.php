<?php

namespace App\Services;

use App\Exceptions\PortainerException;
use App\Jobs\ApplyFirewallRulesJob;
use App\Models\FirewallPolicy;
use App\Models\FirewallRule;
use App\Services\Portainer\ExecResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FirewallService
{
    private const CACHE_KEY = 'firewall:rules';

    private const CACHE_TTL = 15;

    /**
     * Docker-related chain names that should be filtered out.
     *
     * @var array<int, string>
     */
    private const DOCKER_CHAINS = [
        'DOCKER',
        'DOCKER-USER',
        'DOCKER-ISOLATION',
        'FORWARD',
    ];

    /**
     * Standard iptables actions that can be stored in the database.
     * Custom chain targets (UFW-*, f2b-*, etc.) are system-managed and should be skipped.
     *
     * @var array<int, string>
     */
    private const ALLOWED_ACTIONS = [
        'ACCEPT',
        'DROP',
        'REJECT',
        'RETURN',
        'LOG',
    ];

    public function __construct(
        private PortainerService $portainer,
    ) {}

    // =========================================================================
    // DB-Backed Methods (new "save to DB, then Apply" pattern)
    // =========================================================================

    /**
     * Get all firewall rules from the database with policies and live status.
     *
     * @return array{
     *   input: array{policy: string, rules: Collection<int, FirewallRule>},
     *   output: array{policy: string, rules: Collection<int, FirewallRule>},
     *   pending_changes: bool,
     *   live_status: array{container_online: bool, live_input_policy: string, live_output_policy: string},
     *   warnings: array<int, string>
     * }
     */
    public function getDbRules(): array
    {
        return [
            'input' => [
                'policy' => FirewallPolicy::getPolicy('INPUT'),
                'rules' => FirewallRule::input()->ordered()->with('creator')->get(),
            ],
            'output' => [
                'policy' => FirewallPolicy::getPolicy('OUTPUT'),
                'rules' => FirewallRule::output()->ordered()->with('creator')->get(),
            ],
            'pending_changes' => $this->hasPendingChanges(),
            'live_status' => $this->getLiveStatus(),
            'warnings' => $this->generateDbWarnings(),
        ];
    }

    /**
     * Create a new firewall rule in the database with auto-positioned ordering.
     *
     * @param  array{chain: string, action: string, protocol?: string, source?: string|null, port?: int|null, comment?: string|null, enabled?: bool}  $params
     */
    public function addDbRule(array $params, int $userId): FirewallRule
    {
        $chain = strtoupper($params['chain']);

        $maxPosition = FirewallRule::where('chain', $chain)->max('position') ?? 0;

        return FirewallRule::create([
            'chain' => $chain,
            'action' => strtoupper($params['action']),
            'protocol' => strtolower($params['protocol'] ?? 'all'),
            'source' => $params['source'] ?? null,
            'port' => $params['port'] ?? null,
            'comment' => $params['comment'] ?? null,
            'position' => $maxPosition + 1,
            'enabled' => $params['enabled'] ?? true,
            'created_by' => $userId,
        ]);
    }

    /**
     * Create multiple firewall rules from arrays of sources and ports.
     * Uses cartesian product when both sources and ports are provided.
     *
     * @param  array{chain: string, action: string, protocol?: string, sources?: array<int, string>|null, ports?: array<int, int>|null, comment?: string|null, enabled?: bool}  $params
     */
    public function addDbRules(array $params, int $userId): int
    {
        $sources = ! empty($params['sources']) ? $params['sources'] : [null];
        $ports = ! empty($params['ports']) ? $params['ports'] : [null];
        $count = 0;

        foreach ($sources as $source) {
            foreach ($ports as $port) {
                $this->addDbRule([
                    'chain' => $params['chain'],
                    'action' => $params['action'],
                    'protocol' => $params['protocol'] ?? 'all',
                    'source' => $source,
                    'port' => $port,
                    'comment' => $params['comment'] ?? null,
                    'enabled' => $params['enabled'] ?? true,
                ], $userId);

                $count++;
            }
        }

        return $count;
    }

    /**
     * Delete a firewall rule from the database by its ID.
     */
    public function deleteDbRule(int $id): void
    {
        $rule = FirewallRule::findOrFail($id);
        $rule->delete();
    }

    /**
     * Update an existing firewall rule in the database.
     *
     * @param  array{chain?: string, action?: string, protocol?: string, source?: string|null, port?: int|null, comment?: string|null, enabled?: bool}  $params
     */
    public function updateDbRule(int $id, array $params): FirewallRule
    {
        $rule = FirewallRule::findOrFail($id);

        $updateData = [];

        if (isset($params['chain'])) {
            $updateData['chain'] = strtoupper($params['chain']);
        }
        if (isset($params['action'])) {
            $updateData['action'] = strtoupper($params['action']);
        }
        if (array_key_exists('protocol', $params)) {
            $updateData['protocol'] = strtolower($params['protocol'] ?? 'all');
        }
        if (array_key_exists('source', $params)) {
            $updateData['source'] = $params['source'];
        }
        if (array_key_exists('port', $params)) {
            $updateData['port'] = $params['port'];
        }
        if (array_key_exists('comment', $params)) {
            $updateData['comment'] = $params['comment'];
        }
        if (array_key_exists('enabled', $params)) {
            $updateData['enabled'] = $params['enabled'];
        }

        $rule->update($updateData);

        return $rule->fresh('creator');
    }

    /**
     * Reorder firewall rules based on an ordered array of IDs.
     *
     * @param  array<int, int>  $orderedIds
     */
    public function reorderRules(array $orderedIds): void
    {
        foreach ($orderedIds as $position => $id) {
            FirewallRule::where('id', $id)->update(['position' => $position + 1]);
        }

        Cache::put('firewall:pending_changes', true);
    }

    /**
     * Dispatch the ApplyFirewallRulesJob to apply DB rules to iptables.
     */
    public function apply(): void
    {
        ApplyFirewallRulesJob::dispatch();
    }

    /**
     * Check whether there are unapplied changes in the database.
     */
    public function hasPendingChanges(): bool
    {
        return (bool) Cache::get('firewall:pending_changes', false);
    }

    /**
     * Import live iptables rules into the database (seed from current state).
     *
     * Skips Docker-managed rules, established/related rules, and loopback rules.
     * Returns the number of rules imported.
     */
    public function seedFromLive(int $userId): int
    {
        $count = 0;

        foreach (['INPUT', 'OUTPUT'] as $chain) {
            try {
                $result = $this->execInFirewall(['iptables', '-S', $chain]);
            } catch (PortainerException) {
                continue;
            }

            $lines = array_filter(array_map('trim', explode("\n", $result->output)));
            $position = (FirewallRule::where('chain', $chain)->max('position') ?? 0) + 1;

            foreach ($lines as $line) {
                if (! str_starts_with($line, "-A {$chain}")) {
                    continue;
                }

                if ($this->isDockerRelated($line)) {
                    continue;
                }

                if ($this->isEstablishedRelated($line) || str_contains($line, '-i lo') || str_contains($line, '-o lo')) {
                    continue;
                }

                $action = $this->extractFlag($line, '-j');
                $protocol = $this->extractFlag($line, '-p') ?? 'all';
                $source = $this->extractFlag($line, '-s');
                $port = $this->extractPort($line);
                $comment = $this->extractComment($line);

                if ($action === null) {
                    continue;
                }

                // Skip non-standard actions (UFW-*, f2b-*, custom chains)
                if (! in_array(strtoupper($action), self::ALLOWED_ACTIONS, true)) {
                    continue;
                }

                FirewallRule::create([
                    'chain' => $chain,
                    'action' => strtoupper($action),
                    'protocol' => strtolower($protocol),
                    'source' => $source,
                    'port' => $port,
                    'comment' => $comment,
                    'position' => $position,
                    'enabled' => true,
                    'created_by' => $userId,
                ]);

                $position++;
                $count++;
            }
        }

        return $count;
    }

    /**
     * Save the chain policy to the database.
     */
    public function setPolicy(string $chain, string $policy): void
    {
        FirewallPolicy::setPolicy($chain, $policy);
        Cache::put('firewall:pending_changes', true);
    }

    // =========================================================================
    // Live iptables Reading (preserved from original implementation)
    // =========================================================================

    /**
     * Get structured firewall rules directly from live iptables for INPUT and OUTPUT chains.
     *
     * @return array{
     *   input: array{policy: string, rules: array<int, array{num: int, action: string, protocol: string, source: string, port: int|null, comment: string|null, deletable: bool}>},
     *   output: array{policy: string, rules: array<int, array{num: int, action: string, protocol: string, source: string, port: int|null, comment: string|null, deletable: bool}>},
     *   warnings: array<int, string>,
     *   container_online: bool
     * }
     */
    public function getRules(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            try {
                $inputResult = $this->execInFirewall(['iptables', '-S', 'INPUT']);
                $outputResult = $this->execInFirewall(['iptables', '-S', 'OUTPUT']);

                $inputPolicy = $this->parsePolicy($inputResult->output, 'INPUT');
                $outputPolicy = $this->parsePolicy($outputResult->output, 'OUTPUT');

                $inputRules = $this->parseIptablesOutput($inputResult->output, 'INPUT');
                $outputRules = $this->parseIptablesOutput($outputResult->output, 'OUTPUT');

                $warnings = $this->generateWarnings([
                    'input' => ['policy' => $inputPolicy, 'rules' => $inputRules],
                    'output' => ['policy' => $outputPolicy, 'rules' => $outputRules],
                ]);

                return [
                    'input' => [
                        'policy' => $inputPolicy,
                        'rules' => $inputRules,
                    ],
                    'output' => [
                        'policy' => $outputPolicy,
                        'rules' => $outputRules,
                    ],
                    'warnings' => $warnings,
                    'container_online' => true,
                ];
            } catch (PortainerException $e) {
                Log::warning('Firewall: failed to retrieve iptables rules', [
                    'error' => $e->getMessage(),
                ]);

                return [
                    'input' => ['policy' => 'UNKNOWN', 'rules' => []],
                    'output' => ['policy' => 'UNKNOWN', 'rules' => []],
                    'warnings' => ['Firewall container is offline or unreachable.'],
                    'container_online' => false,
                ];
            }
        });
    }

    /**
     * Get the live container status and policies.
     *
     * @return array{container_online: bool, live_input_policy: string, live_output_policy: string}
     */
    public function getLiveStatus(): array
    {
        try {
            $inputResult = $this->execInFirewall(['iptables', '-S', 'INPUT']);
            $outputResult = $this->execInFirewall(['iptables', '-S', 'OUTPUT']);

            return [
                'container_online' => true,
                'live_input_policy' => $this->parsePolicy($inputResult->output, 'INPUT'),
                'live_output_policy' => $this->parsePolicy($outputResult->output, 'OUTPUT'),
            ];
        } catch (PortainerException $e) {
            Log::warning('Firewall: failed to get live status', [
                'error' => $e->getMessage(),
            ]);

            return [
                'container_online' => false,
                'live_input_policy' => 'UNKNOWN',
                'live_output_policy' => 'UNKNOWN',
            ];
        }
    }

    // =========================================================================
    // Warnings
    // =========================================================================

    /**
     * Generate warning messages based on DB rule state.
     *
     * @return array<int, string>
     */
    private function generateDbWarnings(): array
    {
        $warnings = [];

        $inputPolicy = FirewallPolicy::getPolicy('INPUT');

        if ($inputPolicy === 'DROP') {
            $hasSshAccept = FirewallRule::input()
                ->enabled()
                ->where('port', 22)
                ->where('action', 'ACCEPT')
                ->exists();

            if (! $hasSshAccept) {
                $warnings[] = 'SSH (port 22) ACCEPT rule not found in INPUT chain with DROP policy. You may lose remote access.';
            }
        }

        return $warnings;
    }

    /**
     * Generate warning messages based on live rule state.
     *
     * @param  array{input: array{policy: string, rules: array<int, array{num: int, action: string, protocol: string, source: string, port: int|null, comment: string|null, deletable: bool}>}, output: array{policy: string, rules: array<int, array{num: int, action: string, protocol: string, source: string, port: int|null, comment: string|null, deletable: bool}>}}  $rules
     * @return array<int, string>
     */
    private function generateWarnings(array $rules): array
    {
        $warnings = [];

        if ($rules['input']['policy'] === 'DROP') {
            $hasSshAccept = false;

            foreach ($rules['input']['rules'] as $rule) {
                if ($rule['port'] === 22 && $rule['action'] === 'ACCEPT') {
                    $hasSshAccept = true;
                    break;
                }
            }

            if (! $hasSshAccept) {
                $warnings[] = 'SSH (port 22) ACCEPT rule not found in INPUT chain with DROP policy. You may lose remote access.';
            }
        }

        return $warnings;
    }

    // =========================================================================
    // Parsing Helpers (preserved from original implementation)
    // =========================================================================

    /**
     * Parse `iptables -S <chain>` output into structured rule data.
     *
     * @return array<int, array{num: int, action: string, protocol: string, source: string, port: int|null, comment: string|null, deletable: bool}>
     */
    private function parseIptablesOutput(string $output, string $chain): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $output)));
        $rules = [];
        $num = 0;

        foreach ($lines as $line) {
            if (! str_starts_with($line, "-A {$chain}")) {
                continue;
            }

            if ($this->isDockerRelated($line)) {
                continue;
            }

            $num++;

            $action = $this->extractFlag($line, '-j');
            $protocol = $this->extractFlag($line, '-p') ?? 'all';
            $source = $this->extractFlag($line, '-s') ?? '0.0.0.0/0';
            $port = $this->extractPort($line);
            $comment = $this->extractComment($line);

            $isEstablished = $this->isEstablishedRelated($line);
            $isLoopback = str_contains($line, '-i lo');
            $deletable = ! $isEstablished && ! $isLoopback;

            $rules[] = [
                'num' => $num,
                'action' => $action ?? 'UNKNOWN',
                'protocol' => $protocol,
                'source' => $source,
                'port' => $port,
                'comment' => $comment,
                'deletable' => $deletable,
            ];
        }

        return $rules;
    }

    /**
     * Parse the default policy from `iptables -S` output.
     */
    private function parsePolicy(string $output, string $chain): string
    {
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if (preg_match('/^-P\s+'.preg_quote($chain, '/').'\s+(\S+)/', $line, $matches)) {
                return strtoupper($matches[1]);
            }
        }

        return 'ACCEPT';
    }

    // =========================================================================
    // Detection Helpers (preserved from original implementation)
    // =========================================================================

    /**
     * Check if a rule line references Docker-managed chains or interfaces.
     */
    private function isDockerRelated(string $line): bool
    {
        foreach (self::DOCKER_CHAINS as $chain) {
            if (str_contains($line, $chain)) {
                return true;
            }
        }

        if (preg_match('/\bbr-[a-f0-9]+\b/', $line)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a rule is for established/related connections.
     */
    private function isEstablishedRelated(string $line): bool
    {
        return str_contains($line, 'RELATED,ESTABLISHED')
            || str_contains($line, 'ESTABLISHED,RELATED')
            || str_contains($line, '--ctstate RELATED,ESTABLISHED')
            || str_contains($line, '--ctstate ESTABLISHED,RELATED')
            || str_contains($line, '--state RELATED,ESTABLISHED')
            || str_contains($line, '--state ESTABLISHED,RELATED');
    }

    /**
     * Extract a single-value flag from an iptables rule line.
     */
    private function extractFlag(string $line, string $flag): ?string
    {
        $escaped = preg_quote($flag, '/');

        if (preg_match('/'.$escaped.'\s+(\S+)/', $line, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract the destination port (--dport) from a rule line.
     */
    private function extractPort(string $line): ?int
    {
        if (preg_match('/--dport\s+(\d+)/', $line, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Extract the comment from a rule line.
     */
    private function extractComment(string $line): ?string
    {
        if (preg_match('/--comment\s+"([^"]*)"/', $line, $matches)) {
            return $matches[1];
        }

        if (preg_match('/--comment\s+(\S+)/', $line, $matches)) {
            return $matches[1];
        }

        return null;
    }

    // =========================================================================
    // Infrastructure
    // =========================================================================

    /**
     * Run a command inside the firewall-manager container via Portainer.
     *
     * @param  array<int, string>  $command
     */
    private function execInFirewall(array $command, int $timeout = 15): ExecResult
    {
        $container = (string) config('panel.firewall_container', 'firewall-manager');

        return $this->portainer->execInContainer($container, $command, $timeout);
    }

    /**
     * Clear the cached live rules.
     */
    private function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
