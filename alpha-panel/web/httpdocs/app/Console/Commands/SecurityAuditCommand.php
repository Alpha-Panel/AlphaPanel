<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class SecurityAuditCommand extends Command
{
    protected $signature = 'panel:security-audit';

    protected $description = 'Run composer audit and npm audit to check for known vulnerabilities';

    public function handle(): int
    {
        $hasIssues = false;

        $hasIssues = $this->runComposerAudit() || $hasIssues;
        $hasIssues = $this->runNpmAudit() || $hasIssues;

        $this->newLine();

        if ($hasIssues) {
            $this->error('Security audit found vulnerabilities. Check logs for details.');
        } else {
            $this->info('All clear — no known vulnerabilities found.');
        }

        return $hasIssues ? self::FAILURE : self::SUCCESS;
    }

    private function runComposerAudit(): bool
    {
        $process = new Process(['composer', 'audit', '--format=json'], base_path());
        $process->setTimeout(120);
        $process->run();

        $result = json_decode($process->getOutput(), true);
        $advisories = $result['advisories'] ?? [];
        $count = count($advisories);

        if ($count > 0) {
            Log::warning("Composer audit: {$count} package(s) with advisories", [
                'packages' => array_keys($advisories),
            ]);
            $this->warn("  Composer: {$count} package(s) with known vulnerabilities");

            foreach ($advisories as $package => $items) {
                foreach ($items as $advisory) {
                    $title = $advisory['title'] ?? 'Unknown';
                    $cve = $advisory['cve'] ?? 'no CVE';
                    $this->line("    - {$package}: {$title} ({$cve})");
                }
            }

            return true;
        }

        $this->info('  Composer: No known vulnerabilities');

        return false;
    }

    private function runNpmAudit(): bool
    {
        $process = new Process(['npm', 'audit', '--json'], base_path());
        $process->setTimeout(120);
        $process->run();

        $result = json_decode($process->getOutput(), true);
        $vulns = $result['metadata']['vulnerabilities'] ?? [];
        $total = array_sum(array_values($vulns));

        if ($total > 0) {
            $high = $vulns['high'] ?? 0;
            $critical = $vulns['critical'] ?? 0;
            $moderate = $vulns['moderate'] ?? 0;
            $low = $vulns['low'] ?? 0;

            Log::warning("npm audit: {$total} vulnerabilities", $vulns);
            $this->warn("  npm: {$total} vulnerabilities (critical: {$critical}, high: {$high}, moderate: {$moderate}, low: {$low})");

            return true;
        }

        $this->info('  npm: No known vulnerabilities');

        return false;
    }
}
