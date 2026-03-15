<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\DomainConfigService;
use Illuminate\Console\Command;

class ApplyUserIniCommand extends Command
{
    protected $signature = 'panel:apply-user-ini
                            {--domain= : Apply to a specific domain (FQDN)}
                            {--dry-run : Show what would be written without executing}';

    protected $description = 'Write .user.ini with open_basedir restrictions to all hosted domains';

    public function handle(DomainConfigService $configService): int
    {
        $query = Domain::query()->whereNull('parent_domain_id');

        if ($fqdn = $this->option('domain')) {
            $query->where('fqdn', $fqdn);
        }

        $domains = $query->get();

        if ($domains->isEmpty()) {
            $this->warn('No domains found.');

            return self::SUCCESS;
        }

        $this->info("Applying .user.ini to {$domains->count()} domain(s)...");
        $this->newLine();

        $success = 0;
        $failed = 0;

        foreach ($domains as $domain) {
            $iniPath = "{$domain->getWebRootPath()}/.user.ini";
            $openBasedir = implode(':', [$domain->getBasePath(), '/tmp', '/dev/urandom']);

            if ($this->option('dry-run')) {
                $this->line("  [DRY-RUN] {$domain->fqdn} -> {$iniPath}");
                $this->line("            open_basedir = {$openBasedir}");
                $success++;

                continue;
            }

            try {
                $configService->writeUserIni($domain);
                $this->line("  OK  {$domain->fqdn}");
                $success++;
            } catch (\Throwable $e) {
                $this->error("  FAIL  {$domain->fqdn}: {$e->getMessage()}");
                $failed++;
            }

            // Process subdomains
            foreach ($domain->subdomains as $subdomain) {
                $subIniPath = "{$subdomain->getWebRootPath()}/.user.ini";

                if ($this->option('dry-run')) {
                    $this->line("  [DRY-RUN] {$subdomain->fqdn} -> {$subIniPath}");
                    $success++;

                    continue;
                }

                try {
                    $configService->writeUserIni($subdomain);
                    $this->line("  OK  {$subdomain->fqdn}");
                    $success++;
                } catch (\Throwable $e) {
                    $this->error("  FAIL  {$subdomain->fqdn}: {$e->getMessage()}");
                    $failed++;
                }
            }
        }

        $this->newLine();
        $this->info("Done: {$success} succeeded, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
