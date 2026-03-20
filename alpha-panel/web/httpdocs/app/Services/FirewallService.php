<?php

namespace App\Services;

use App\Exceptions\PortainerException;
use App\Services\Portainer\ExecResult;
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

    public function __construct(
        private PortainerService $portainer,
    ) {}

    /**
     * Get structured firewall rules for INPUT and OUTPUT chains.
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
     * Add an iptables rule.
     *
     * @param  array{chain: string, action: string, protocol: string, source?: string|null, port?: int|null, comment?: string|null, position?: int|null}  $params
     */
    public function addRule(array $params): ExecResult
    {
        $chain = strtoupper($params['chain']);
        $action = strtoupper($params['action']);
        $protocol = strtolower($params['protocol']);
        $source = $params['source'] ?? null;
        $port = $params['port'] ?? null;
        $comment = $params['comment'] ?? null;
        $position = $params['position'] ?? null;

        $this->rejectDockerChain($chain);

        $command = ['iptables'];

        if ($position !== null) {
            $command = [...$command, '-I', $chain, (string) $position];
        } else {
            $command = [...$command, '-A', $chain];
        }

        if ($source !== null && $source !== '') {
            $command = [...$command, '-s', $source];
        }

        if ($protocol !== 'all') {
            $command = [...$command, '-p', $protocol];

            if ($port !== null && in_array($protocol, ['tcp', 'udp'], true)) {
                $command = [...$command, '-m', $protocol, '--dport', (string) $port];
            }
        }

        if ($comment !== null && $comment !== '') {
            $command = [...$command, '-m', 'comment', '--comment', $comment];
        }

        $command = [...$command, '-j', $action];

        $result = $this->execInFirewall($command);

        if ($result->isSuccessful()) {
            $this->persist();
            $this->clearCache();
        }

        return $result;
    }

    /**
     * Delete a rule by chain and rule number.
     */
    public function deleteRule(string $chain, int $ruleNumber): ExecResult
    {
        $chain = strtoupper($chain);
        $this->rejectDockerChain($chain);

        $rules = $this->getRules();
        $chainKey = strtolower($chain);
        $chainRules = $rules[$chainKey]['rules'] ?? [];

        foreach ($chainRules as $rule) {
            if ($rule['num'] === $ruleNumber && ! $rule['deletable']) {
                return new ExecResult(
                    exitCode: 1,
                    output: '',
                    errorOutput: "Rule #{$ruleNumber} in {$chain} is a system rule and cannot be deleted.",
                );
            }
        }

        $result = $this->execInFirewall(['iptables', '-D', $chain, (string) $ruleNumber]);

        if ($result->isSuccessful()) {
            $this->persist();
            $this->clearCache();
        }

        return $result;
    }

    /**
     * Change the default policy for a chain.
     */
    public function setPolicy(string $chain, string $policy): ExecResult
    {
        $chain = strtoupper($chain);
        $policy = strtoupper($policy);

        $this->rejectDockerChain($chain);

        $result = $this->execInFirewall(['iptables', '-P', $chain, $policy]);

        if ($result->isSuccessful()) {
            $this->persist();
            $this->clearCache();
        }

        return $result;
    }

    /**
     * Persist current iptables rules to disk so they survive container restarts.
     */
    public function persist(): void
    {
        try {
            $result = $this->execInFirewall(['sh', '-c', 'iptables-save > /etc/iptables/rules.v4']);

            if (! $result->isSuccessful()) {
                Log::warning('Firewall: failed to persist iptables rules', [
                    'error' => $result->errorOutput,
                ]);
            }
        } catch (PortainerException $e) {
            Log::warning('Firewall: failed to persist iptables rules', [
                'error' => $e->getMessage(),
            ]);
        }
    }

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

    /**
     * Generate warning messages based on current rule state.
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

    /**
     * Reject any operation on Docker-managed chains.
     *
     * @throws \InvalidArgumentException
     */
    private function rejectDockerChain(string $chain): void
    {
        foreach (self::DOCKER_CHAINS as $dockerChain) {
            if (str_contains(strtoupper($chain), $dockerChain)) {
                throw new \InvalidArgumentException("Cannot modify Docker-managed chain: {$chain}");
            }
        }
    }

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
     * Clear the cached rules.
     */
    private function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
