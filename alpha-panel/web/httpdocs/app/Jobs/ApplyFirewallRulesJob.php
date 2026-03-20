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

            // 2. Get rules from DB
            $inputRules = FirewallRule::input()->enabled()->ordered()->get();
            $outputRules = FirewallRule::output()->enabled()->ordered()->get();

            // 3. Flush INPUT and OUTPUT chains
            foreach (['INPUT', 'OUTPUT'] as $chain) {
                $portainer->execInContainer($container, ['iptables', '-F', $chain], 10);
            }

            // 4. Re-add essential system rules for both chains
            $portainer->execInContainer($container, ['iptables', '-A', 'INPUT', '-m', 'conntrack', '--ctstate', 'RELATED,ESTABLISHED', '-j', 'ACCEPT'], 10);
            $portainer->execInContainer($container, ['iptables', '-A', 'INPUT', '-i', 'lo', '-j', 'ACCEPT'], 10);
            $portainer->execInContainer($container, ['iptables', '-A', 'OUTPUT', '-m', 'conntrack', '--ctstate', 'RELATED,ESTABLISHED', '-j', 'ACCEPT'], 10);
            $portainer->execInContainer($container, ['iptables', '-A', 'OUTPUT', '-o', 'lo', '-j', 'ACCEPT'], 10);

            // 5. Apply policies
            $inputPolicy = FirewallPolicy::getPolicy('INPUT');
            $outputPolicy = FirewallPolicy::getPolicy('OUTPUT');
            $portainer->execInContainer($container, ['iptables', '-P', 'INPUT', $inputPolicy], 10);
            $portainer->execInContainer($container, ['iptables', '-P', 'OUTPUT', $outputPolicy], 10);

            // 6. Apply rules in order (expand sources × ports per rule)
            foreach ($inputRules as $rule) {
                foreach ($this->buildIptablesCommands('INPUT', $rule) as $cmd) {
                    $portainer->execInContainer($container, $cmd, 10);
                }
            }

            foreach ($outputRules as $rule) {
                foreach ($this->buildIptablesCommands('OUTPUT', $rule) as $cmd) {
                    $portainer->execInContainer($container, $cmd, 10);
                }
            }

            // 7. Persist
            $portainer->execInContainer($container, ['sh', '-c', 'iptables-save > /etc/iptables/rules.v4'], 15);

            // 8. Clear flags
            Cache::forget('firewall:pending_changes');
            Cache::forget('firewall:rules');

            Log::info('ApplyFirewallRulesJob: rules applied successfully', [
                'input_rules' => $inputRules->count(),
                'output_rules' => $outputRules->count(),
                'input_policy' => $inputPolicy,
                'output_policy' => $outputPolicy,
            ]);
        } catch (\Throwable $e) {
            Log::error('ApplyFirewallRulesJob: failed to apply rules', [
                'error' => $e->getMessage(),
            ]);

            // Attempt rollback
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
     * Build iptables command arrays from a FirewallRule, expanding sources × ports.
     *
     * @return array<int, array<int, string>>
     */
    private function buildIptablesCommands(string $chain, FirewallRule $rule): array
    {
        $sources = $rule->sources ?? [null];
        $ports = $rule->ports ?? [null];
        $commands = [];

        foreach ($sources as $source) {
            foreach ($ports as $port) {
                $cmd = ['iptables', '-A', $chain];

                if ($source !== null && $source !== '') {
                    $cmd = [...$cmd, '-s', $source];
                }

                if ($rule->protocol !== 'all') {
                    $cmd = [...$cmd, '-p', $rule->protocol];

                    if ($port !== null && in_array($rule->protocol, ['tcp', 'udp'], true)) {
                        $cmd = [...$cmd, '-m', $rule->protocol, '--dport', (string) $port];
                    }
                }

                if ($rule->comment !== null && $rule->comment !== '') {
                    $cmd = [...$cmd, '-m', 'comment', '--comment', $rule->comment];
                }

                $cmd = [...$cmd, '-j', $rule->action];

                $commands[] = $cmd;
            }
        }

        return $commands;
    }
}
