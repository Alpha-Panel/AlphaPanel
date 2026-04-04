<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\DomainConfigService;
use App\Services\ReloadService;
use Illuminate\Console\Command;

class PanelApplyConfigsCommand extends Command
{
    protected $signature = 'panel:apply
                            {--domain= : Regenerate config for a specific domain (FQDN)}
                            {--dry-run : Show what would be regenerated without writing}
                            {--no-reload : Skip Caddy/Apache reload after regeneration}';

    protected $description = 'Regenerate Caddyfile configs for all domains from the database and reload Caddy';

    public function handle(DomainConfigService $configService, ReloadService $reloadService): int
    {
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

        if ($failed === 0 && ! $this->option('dry-run') && ! $this->option('no-reload')) {
            $this->newLine();
            $this->info('Reloading Caddy...');
            $reloaded = $reloadService->reloadCaddy();
            $this->line($reloaded ? '  OK  Caddy reloaded.' : '  WARN  Caddy reload returned non-zero.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
