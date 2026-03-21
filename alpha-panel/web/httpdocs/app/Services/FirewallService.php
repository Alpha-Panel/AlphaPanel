<?php

namespace App\Services;

use App\Models\FirewallPolicy;
use App\Models\FirewallRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class FirewallService
{
    /**
     * Map DB actions to UFW actions.
     *
     * @var array<string, string>
     */
    private const ACTION_MAP = [
        'ACCEPT' => 'allow',
        'DROP' => 'deny',
        'REJECT' => 'reject',
    ];

    /**
     * Map DB policy values to UFW default policy values.
     *
     * @var array<string, string>
     */
    private const POLICY_MAP = [
        'ACCEPT' => 'allow',
        'DROP' => 'deny',
    ];

    // =========================================================================
    // DB-Backed Methods (save to DB, generate script)
    // =========================================================================

    /**
     * Get all firewall rules from the database with policies.
     *
     * @param  string|null  $clientIp  The current user's IP for context-aware warnings.
     * @return array{
     *   input: array{policy: string, rules: Collection<int, FirewallRule>},
     *   output: array{policy: string, rules: Collection<int, FirewallRule>},
     *   pending_changes: bool,
     *   warnings: array<int, string>
     * }
     */
    public function getDbRules(?string $clientIp = null): array
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
            'warnings' => $this->generateDbWarnings($clientIp),
        ];
    }

    /**
     * Create a new firewall rule in the database with auto-positioned ordering.
     *
     * @param  array{chain: string, action: string, protocol?: string, sources?: array<int, string>|null, ports?: array<int, int>|null, comment?: string|null, enabled?: bool}  $params
     */
    public function addDbRule(array $params, int $userId): FirewallRule
    {
        $chain = strtoupper($params['chain']);

        $maxPosition = FirewallRule::where('chain', $chain)->max('position') ?? 0;

        return FirewallRule::create([
            'chain' => $chain,
            'action' => strtoupper($params['action']),
            'protocol' => strtolower($params['protocol'] ?? 'all'),
            'sources' => ! empty($params['sources']) ? $params['sources'] : null,
            'ports' => ! empty($params['ports']) ? $params['ports'] : null,
            'comment' => $params['comment'] ?? null,
            'position' => $maxPosition + 1,
            'enabled' => $params['enabled'] ?? true,
            'created_by' => $userId,
        ]);
    }

    /**
     * Create a single firewall rule storing sources and ports as JSON arrays.
     *
     * @param  array{chain: string, action: string, protocol?: string, sources?: array<int, string>|null, ports?: array<int, int>|null, comment?: string|null, enabled?: bool}  $params
     */
    public function addDbRules(array $params, int $userId): int
    {
        $this->addDbRule($params, $userId);

        return 1;
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
     * @param  array{chain?: string, action?: string, protocol?: string, sources?: array<int, string>|null, ports?: array<int, int>|null, comment?: string|null, enabled?: bool}  $params
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
        if (array_key_exists('sources', $params)) {
            $updateData['sources'] = ! empty($params['sources']) ? $params['sources'] : null;
        }
        if (array_key_exists('ports', $params)) {
            $updateData['ports'] = ! empty($params['ports']) ? $params['ports'] : null;
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
     * Check whether there are unapplied changes in the database.
     */
    public function hasPendingChanges(): bool
    {
        return (bool) Cache::get('firewall:pending_changes', false);
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
    // UFW Script Building (for manual execution on server)
    // =========================================================================

    /**
     * Build the complete UFW apply script for manual execution on the server.
     *
     * The user copies this script and runs it via SSH or VM Console.
     *
     * Script order:
     *   ① ufw --force reset (safe — only resets UFW chains, Docker untouched)
     *   ② Clean legacy iptables rules from INPUT/OUTPUT (migration safety)
     *   ③ Set default policies
     *   ④ User rules in DB position order (enabled only)
     *   ⑤ ufw --force enable
     *   ⑥ Ensure FORWARD chain stays ACCEPT for Docker
     */
    public function buildApplyScript(): string
    {
        $inputRules = FirewallRule::input()->enabled()->ordered()->get();
        $outputRules = FirewallRule::output()->enabled()->ordered()->get();
        $inputPolicy = FirewallPolicy::getPolicy('INPUT');
        $outputPolicy = FirewallPolicy::getPolicy('OUTPUT');

        $lines = [];

        // ① Reset UFW (safe — does NOT touch Docker's FORWARD/NAT chains)
        $lines[] = 'ufw --force reset';

        // ② Clean any legacy iptables rules from INPUT/OUTPUT chains
        //    (migration safety: removes old raw iptables rules that predate UFW)
        $lines[] = 'iptables -F INPUT';
        $lines[] = 'iptables -F OUTPUT';
        $lines[] = 'iptables -P INPUT ACCEPT';
        $lines[] = 'iptables -P OUTPUT ACCEPT';

        // ③ Set default policies
        $lines[] = 'ufw default '.(self::POLICY_MAP[$inputPolicy] ?? 'deny').' incoming';
        $lines[] = 'ufw default '.(self::POLICY_MAP[$outputPolicy] ?? 'allow').' outgoing';

        // ④ User rules in exact DB position order
        foreach ($inputRules as $rule) {
            foreach ($this->buildUfwCommands('INPUT', $rule) as $cmd) {
                $lines[] = $cmd;
            }
        }

        foreach ($outputRules as $rule) {
            foreach ($this->buildUfwCommands('OUTPUT', $rule) as $cmd) {
                $lines[] = $cmd;
            }
        }

        // ⑤ Enable UFW
        $lines[] = 'ufw --force enable';

        // ⑥ CRITICAL: Ensure FORWARD chain stays ACCEPT for Docker container networking
        $lines[] = 'iptables -P FORWARD ACCEPT';

        return implode("\n", $lines);
    }

    /**
     * Build UFW commands for a single FirewallRule.
     *
     * Expands: sources × ports
     *
     * Unlike iptables, UFW handles tcp+udp automatically when protocol is "all"
     * and a port is specified (no need to expand to separate tcp/udp rules).
     *
     * @return array<int, string>
     */
    private function buildUfwCommands(string $chain, FirewallRule $rule): array
    {
        $sources = $rule->sources ?? [null];
        $ports = $rule->ports ?? [null];

        $action = self::ACTION_MAP[$rule->action] ?? 'allow';
        $direction = $chain === 'OUTPUT' ? 'out' : 'in';

        $commands = [];

        foreach ($sources as $source) {
            foreach ($ports as $port) {
                $cmd = "ufw {$action} {$direction}";

                // Source/destination
                if ($source !== null && $source !== '') {
                    if ($chain === 'INPUT') {
                        $cmd .= ' from '.escapeshellarg($source);
                    } else {
                        // OUTPUT: "source" semantically means destination
                        $cmd .= ' to '.escapeshellarg($source);
                    }
                }

                // Port + protocol
                if ($port !== null) {
                    $proto = $rule->protocol;

                    if ($source !== null && $source !== '') {
                        // Full syntax: from/to + port
                        if ($chain === 'INPUT') {
                            $cmd .= ' to any port '.escapeshellarg((string) $port);
                        } else {
                            $cmd .= ' port '.escapeshellarg((string) $port);
                        }
                    } else {
                        // Short syntax: just port[/proto]
                        if ($proto !== null && $proto !== 'all') {
                            $cmd .= ' '.escapeshellarg($port.'/'.$proto);
                        } else {
                            $cmd .= ' '.escapeshellarg((string) $port);
                        }

                        // Skip the proto line below since it's embedded in port
                        $proto = null;
                    }

                    // Add proto qualifier when using full syntax
                    if ($proto !== null && $proto !== 'all') {
                        $cmd .= ' proto '.escapeshellarg($proto);
                    }
                } elseif ($source === null || $source === '') {
                    // No source, no port — this is a blanket rule (unusual)
                    // UFW needs at least something, skip if truly empty
                    continue;
                }

                // Comment (UFW >= 0.36, available on Debian bookworm)
                if ($rule->comment !== null && $rule->comment !== '') {
                    $cmd .= ' comment '.escapeshellarg($rule->comment);
                }

                $commands[] = $cmd;
            }
        }

        return $commands;
    }

    // =========================================================================
    // Warnings
    // =========================================================================

    /**
     * Generate warning messages based on DB rule state.
     *
     * @param  string|null  $clientIp  The current user's IP address.
     * @return array<int, string>
     */
    private function generateDbWarnings(?string $clientIp = null): array
    {
        $warnings = [];

        $inputPolicy = FirewallPolicy::getPolicy('INPUT');

        if ($inputPolicy === 'DROP') {
            $hasSshCovered = FirewallRule::input()
                ->enabled()
                ->whereJsonContains('ports', 22)
                ->where('action', 'ACCEPT')
                ->exists();

            // If no explicit port 22 rule, check for blanket IP allow (no port restriction)
            // that covers the user's current connection IP.
            if (! $hasSshCovered && $clientIp !== null) {
                $blanketRules = FirewallRule::input()
                    ->enabled()
                    ->where('action', 'ACCEPT')
                    ->whereNull('ports')
                    ->whereNotNull('sources')
                    ->get();

                foreach ($blanketRules as $rule) {
                    foreach ((array) $rule->sources as $source) {
                        if ($this->ipMatchesCidr($clientIp, $source)) {
                            $hasSshCovered = true;
                            break 2;
                        }
                    }
                }
            }

            if (! $hasSshCovered) {
                $warnings[] = 'SSH (port 22) ACCEPT rule not found in INPUT chain with DROP policy. You may lose remote access.';
            }
        }

        return $warnings;
    }

    /**
     * Check whether an IP address falls within a CIDR range (or matches exactly).
     */
    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
