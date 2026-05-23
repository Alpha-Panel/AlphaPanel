<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\Acme\AcmeService;
use App\Services\DomainConfigService;
use App\Services\ReloadService;
use Illuminate\Console\Command;

class PanelApplyConfigsCommand extends Command
{
    protected $signature = 'panel:apply
                            {--domain= : Regenerate config for a specific domain (FQDN)}
                            {--dry-run : Show what would be regenerated without writing}
                            {--no-restart : Skip frankenphp container restart after regeneration}
                            {--no-reload : Alias for --no-restart (backwards compat)}';

    protected $description = 'Regenerate Caddyfile configs for all domains from the database and restart Caddy';

    public function handle(
        DomainConfigService $configService,
        ReloadService $reloadService,
        AcmeService $acmeService,
    ): int {
        // Ensure the panel default self-signed cert exists before any config
        // regeneration so renderWithTls() always has a valid fallback path.
        try {
            $acmeService->ensurePanelDefaultSelfSigned();
        } catch (\Throwable $e) {
            $this->warn("Panel default cert ensure failed: {$e->getMessage()}");
        }

        $query = Domain::query()->with(['phpVersion', 'activeSslCertificate', 'parentDomain']);

        if ($fqdn = $this->option('domain')) {
            $query->where('fqdn', $fqdn);
        }

        $domains = $query->get();

        if ($domains->isEmpty()) {
            $this->warn('No domains found.');

            return self::SUCCESS;
        }

        $this->info("Regenerating Caddyfile configs for {$domains->count()} domain(s)...");
        $this->newLine();

        $success = 0;
        $failed = 0;

        foreach ($domains as $domain) {
            if ($this->option('dry-run')) {
                $hasCert = $configService->certExists($domain);
                $this->line("  [DRY-RUN] {$domain->fqdn} — ".($hasCert ? 'TLS' : 'HTTP-only'));
                $success++;

                continue;
            }

            try {
                $configService->regenerateCaddyConfig($domain);
                $hasCert = $configService->certExists($domain);
                $this->line('  OK  '.$domain->fqdn.' — '.($hasCert ? 'TLS config' : 'HTTP-only config'));
                $success++;
            } catch (\Throwable $e) {
                $this->error("  FAIL  {$domain->fqdn}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done: {$success} regenerated, {$failed} failed.");

        $skipRestart = $this->option('no-restart') || $this->option('no-reload');
        $restartFailed = false;

        if ($failed === 0 && ! $this->option('dry-run') && ! $skipRestart) {
            $this->newLine();
            $this->info('Restarting Caddy...');
            $restarted = $reloadService->restartCaddy();
            if ($restarted) {
                $this->line('  OK  Caddy restarted.');
            } else {
                $this->error('  FAIL  Caddy restart failed — regenerated configs may not be live yet.');
                $restartFailed = true;
            }
        }

        return ($failed > 0 || $restartFailed) ? self::FAILURE : self::SUCCESS;
    }
}
