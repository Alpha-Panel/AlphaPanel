<?php

namespace App\Console\Commands;

use App\Jobs\SslActivateJob;
use App\Models\AcmeSetting;
use App\Models\SslCertificate;
use App\Services\PortainerService;
use Illuminate\Console\Command;

class SslRenewCommand extends Command
{
    protected $signature = 'ssl:renew
        {--dry-run : Show which certificates would be renewed without actually renewing}
        {--force : Renew all auto-renewable certificates regardless of expiry}';

    protected $description = 'Renew SSL certificates that are expiring soon';

    public function handle(): int
    {
        $renewDays = AcmeSetting::tryInstance()?->auto_renew_days ?: 30;

        $query = SslCertificate::query()
            ->select('ssl_certificates.*')
            ->join('domains', 'domains.id', '=', 'ssl_certificates.domain_id')
            ->where('ssl_certificates.auto_renew', true)
            ->whereNotNull('ssl_certificates.certificate_pem')
            ->whereNotNull('ssl_certificates.not_after')
            ->where('domains.status', 'active')
            ->whereColumn('ssl_certificates.id', 'domains.active_ssl_certificate_id');

        if (! $this->option('force')) {
            $query->where('ssl_certificates.not_after', '<=', now()->addDays($renewDays));
        }

        $certificates = $query->with('domain')->get();

        if ($certificates->isEmpty()) {
            $this->info('No certificates need renewal.');

            return self::SUCCESS;
        }

        $this->info("Found {$certificates->count()} certificate(s) to renew (within {$renewDays} days of expiry).");

        $renewed = 0;
        $skipped = 0;

        foreach ($certificates as $cert) {
            $domain = $cert->domain;
            $daysLeft = (int) now()->diffInDays($cert->not_after, false);

            if ($this->option('dry-run')) {
                $this->line("  [DRY RUN] {$domain->fqdn} — expires in {$daysLeft} days ({$cert->not_after->format('Y-m-d')})");
                $renewed++;

                continue;
            }

            try {
                SslActivateJob::dispatch(
                    domain: $domain,
                    triggeredBy: null,
                    locale: config('app.locale', 'en'),
                    isRenewal: true,
                );

                $this->info("  Queued renewal for {$domain->fqdn} (expires in {$daysLeft} days).");
                $renewed++;
            } catch (\Throwable $e) {
                $this->error("  Failed to queue renewal for {$domain->fqdn}: {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("Summary: {$renewed} queued, {$skipped} skipped.");

        if ($renewed > 0 && ! $this->option('dry-run')) {
            $this->reloadMailuIfEnabled();
        }

        return $skipped > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * After a successful cert renewal, restart mailu-front so the bind-mounted
     * /certs/cert.pem and /certs/key.pem point at the new letsencrypt archive
     * inodes (Docker file-binds capture the inode at container start time, so
     * reload alone keeps serving the stale cert).
     */
    private function reloadMailuIfEnabled(): void
    {
        if (! filter_var(env('MAIL_ENABLED', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        try {
            $portainer = app(PortainerService::class);
            $portainer->restartContainer('mailu-front');
            $this->info('  Mailu front restarted to pick up renewed cert.');
        } catch (\Throwable $e) {
            $this->warn('  Mailu restart skipped: '.$e->getMessage());
        }
    }
}
