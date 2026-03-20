<?php

namespace App\Jobs;

use App\Models\FirewallPolicy;
use App\Models\FirewallRule;
use App\Services\PortainerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ApplyFirewallRulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(PortainerService $portainer): void
    {
        $container = (string) config('panel.firewall_container', 'firewall-manager');

        try {
            // 1. Backup current rules
            $portainer->execInContainer($container, ['sh', '-c', 'iptables-save > /etc/iptables/rules.v4.bak'], 15);

            // 2. Get rules and policies from DB
            $inputRules = FirewallRule::input()->enabled()->ordered()->get();
            $outputRules = FirewallRule::output()->enabled()->ordered()->get();
            $inputPolicy = FirewallPolicy::getPolicy('INPUT');
            $outputPolicy = FirewallPolicy::getPolicy('OUTPUT');

            // 3. Build a single shell script — ALL commands in ONE exec call
            //
            //    CRITICAL ORDERING:
            //    ① ACCEPT policy first (safety net — no lockout during flush)
            //    ② Flush chains
            //    ③ System rules (RELATED,ESTABLISHED + loopback)
            //    ④ User rules in exact DB position order
            //    ⑤ Desired policy LAST (only after all ACCEPT rules are in place)
            //    ⑥ Persist
            //
            //    Commands are separated by NEWLINES, NOT &&.
            //    Each command runs independently — a single failed rule
            //    must NOT prevent subsequent rules from being applied.
            $lines = [];

            // ① Safety: set ACCEPT policy before flush
            //    Prevents lockout if the old policy was DROP
            $lines[] = 'iptables -P INPUT ACCEPT';
            $lines[] = 'iptables -P OUTPUT ACCEPT';

            // ② Flush only INPUT and OUTPUT (preserve Docker/FORWARD chains)
            $lines[] = 'iptables -F INPUT';
            $lines[] = 'iptables -F OUTPUT';

            // ③ Essential system rules (always present, before user rules)
            $lines[] = 'iptables -A INPUT -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT';
            $lines[] = 'iptables -A INPUT -i lo -j ACCEPT';
            $lines[] = 'iptables -A OUTPUT -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT';
            $lines[] = 'iptables -A OUTPUT -o lo -j ACCEPT';

            // ④ User rules in exact DB position order (position ASC)
            foreach ($inputRules as $rule) {
                foreach ($this->buildIptablesCommands('INPUT', $rule) as $cmd) {
                    $lines[] = $this->commandToShell($cmd);
                }
            }

            foreach ($outputRules as $rule) {
                foreach ($this->buildIptablesCommands('OUTPUT', $rule) as $cmd) {
                    $lines[] = $this->commandToShell($cmd);
                }
            }

            // ⑤ Set desired policies LAST — all user ACCEPT rules are already in place
            $lines[] = 'iptables -P INPUT '.escapeshellarg($inputPolicy);
            $lines[] = 'iptables -P OUTPUT '.escapeshellarg($outputPolicy);

            // ⑥ Persist
            $lines[] = 'iptables-save > /etc/iptables/rules.v4';

            // Execute: NEWLINE separated — each command runs regardless of previous
            $script = implode("\n", $lines);

            Log::info('ApplyFirewallRulesJob: executing script', [
                'input_rules' => $inputRules->count(),
                'output_rules' => $outputRules->count(),
                'input_policy' => $inputPolicy,
                'output_policy' => $outputPolicy,
                'total_commands' => count($lines),
                'script' => $script,
            ]);

            $portainer->execInContainer($container, ['sh', '-c', $script], 30);

            // 4. Clear flags
            Cache::forget('firewall:pending_changes');
            Cache::forget('firewall:rules');

            Log::info('ApplyFirewallRulesJob: rules applied successfully');
        } catch (\Throwable $e) {
            Log::error('ApplyFirewallRulesJob: failed to apply rules, attempting rollback', [
                'error' => $e->getMessage(),
            ]);

            // Attempt rollback from backup
            try {
                $portainer->execInContainer($container, ['sh', '-c', 'iptables-restore < /etc/iptables/rules.v4.bak'], 15);
                Log::info('ApplyFirewallRulesJob: rolled back to previous rules');
            } catch (\Throwable $rollbackError) {
                Log::error('ApplyFirewallRulesJob: rollback also failed', [
                    'error' => $rollbackError->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Build iptables command arrays from a FirewallRule.
     *
     * Expands: sources × protocols × ports
     *
     * When protocol is "all" and ports are specified, generates both
     * tcp and udp rules for each port (iptables requires -p tcp/udp for --dport).
     * When protocol is "all" and no ports, generates a single rule without -p flag.
     *
     * @return array<int, array<int, string>>
     */
    private function buildIptablesCommands(string $chain, FirewallRule $rule): array
    {
        $sources = $rule->sources ?? [null];
        $ports = $rule->ports ?? [null];
        $hasPorts = ! (count($ports) === 1 && $ports[0] === null);

        // Determine effective protocols
        // "all" with ports → expand to tcp + udp (iptables needs -p for --dport)
        // "all" without ports → no -p flag (true any-protocol match)
        if ($rule->protocol === 'all' && $hasPorts) {
            $protocols = ['tcp', 'udp'];
        } elseif ($rule->protocol === 'all') {
            $protocols = [null];
        } else {
            $protocols = [$rule->protocol];
        }

        $commands = [];

        foreach ($sources as $source) {
            foreach ($protocols as $protocol) {
                foreach ($ports as $port) {
                    $cmd = ['iptables', '-A', $chain];

                    if ($source !== null && $source !== '') {
                        $cmd = [...$cmd, '-s', $source];
                    }

                    if ($protocol !== null) {
                        $cmd = [...$cmd, '-p', $protocol];

                        if ($port !== null && in_array($protocol, ['tcp', 'udp'], true)) {
                            $cmd = [...$cmd, '--dport', (string) $port];
                        }
                    }

                    if ($rule->comment !== null && $rule->comment !== '') {
                        $cmd = [...$cmd, '-m', 'comment', '--comment', $rule->comment];
                    }

                    $cmd = [...$cmd, '-j', $rule->action];

                    $commands[] = $cmd;
                }
            }
        }

        return $commands;
    }

    /**
     * Convert a command array to a safe shell string.
     */
    private function commandToShell(array $cmd): string
    {
        return implode(' ', array_map('escapeshellarg', $cmd));
    }
}
