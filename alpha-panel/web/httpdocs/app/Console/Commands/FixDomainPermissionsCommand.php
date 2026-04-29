<?php

namespace App\Console\Commands;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Services\PortainerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FixDomainPermissionsCommand extends Command
{
    protected $signature = 'domains:fix-perms
                            {--domain= : Fix only a specific domain (FQDN)}
                            {--dry-run : Show what would run without executing}';

    protected $description = 'Backfill ownership (user:www-data) and group read/execute permissions across all domain base paths';

    public function handle(PortainerService $portainer): int
    {
        $query = Domain::query()->with('ftpUser', 'parentDomain.ftpUser');

        if ($fqdn = $this->option('domain')) {
            $query->where('fqdn', $fqdn);
        }

        $domains = $query->get();

        if ($domains->isEmpty()) {
            $this->warn('No domains found.');

            return self::SUCCESS;
        }

        $this->info("Fixing permissions for {$domains->count()} domain(s)...");
        $this->newLine();

        $success = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($domains as $domain) {
            $username = $domain->getEffectiveFtpUsername();

            if (! $username) {
                $this->line("  <fg=yellow>SKIP</> {$domain->fqdn} — no FTP/pool user");
                $skipped++;

                continue;
            }

            $basePath = escapeshellarg($domain->getBasePath());
            $userIniPath = escapeshellarg("{$domain->getWebRootPath()}/.user.ini");
            $container = $domain->type === DomainType::ApacheReverseProxy
                ? 'php-code-server'
                : 'frankenphp';

            if ($this->option('dry-run')) {
                $this->line("  [DRY-RUN] {$domain->fqdn} — chown {$username}:www-data + chmod g+rX on {$domain->getBasePath()}");
                $success++;

                continue;
            }

            try {
                // Unlock immutable .user.ini before bulk chown so it doesn't fail
                $portainer->execInContainer($container, [
                    'sh', '-c', "chattr -i {$userIniPath} 2>/dev/null || true",
                ]);

                $chownResult = $portainer->execInContainer(
                    $container,
                    ['sh', '-c', "chown -R {$username}:www-data {$basePath}"],
                    300,
                );

                if (! $chownResult->isSuccessful()) {
                    throw new \RuntimeException(trim($chownResult->errorOutput) ?: trim($chownResult->output) ?: 'chown failed');
                }

                $chmodResult = $portainer->execInContainer(
                    $container,
                    ['sh', '-c', "chmod -R g+rX {$basePath}"],
                    300,
                );

                if (! $chmodResult->isSuccessful()) {
                    throw new \RuntimeException(trim($chmodResult->errorOutput) ?: trim($chmodResult->output) ?: 'chmod failed');
                }

                // Re-lock .user.ini (root-owned, immutable)
                $portainer->execInContainer($container, [
                    'sh', '-c', "chown root:root {$userIniPath} && chmod 444 {$userIniPath} && chattr +i {$userIniPath} 2>/dev/null || true",
                ]);

                $this->line("  <fg=green>OK</>   {$domain->fqdn}");
                $success++;
            } catch (\Throwable $e) {
                $this->line("  <fg=red>FAIL</> {$domain->fqdn} — {$e->getMessage()}");
                Log::error("domains:fix-perms failed for {$domain->fqdn}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done. Success: {$success}, Failed: {$failed}, Skipped: {$skipped}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
