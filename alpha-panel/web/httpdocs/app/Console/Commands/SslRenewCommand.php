<?php

namespace App\Console\Commands;

use App\Jobs\SslActivateJob;
use App\Models\AcmeSetting;
use App\Models\SslCertificate;
use Illuminate\Console\Command;

class SslRenewCommand extends Command
{
    protected $signature = 'ssl:renew
        {--dry-run : Show which certificates would be renewed without actually renewing}
        {--force : Renew all auto-renewable certificates regardless of expiry}';

    protected $description = 'Renew SSL certificates that are expiring soon';

    public function handle(): int
    {
        $renewDays = 30;

        try {
            $renewDays = AcmeSetting::instance()->auto_renew_days ?: 30;
        } catch (\Throwable) {
            // Table may not exist yet
        }

        $query = SslCertificate::query()
            ->where('auto_renew', true)
            ->whereNotNull('certificate_pem')
            ->whereNotNull('not_after')
            ->whereHas('domain', fn ($q) => $q->where('status', 'active'));

        if (! $this->option('force')) {
            $query->where('not_after', '<=', now()->addDays($renewDays));
        }

        // Only get the active certificate per domain (the one pointed to by active_ssl_certificate_id)
        $certificates = $query->get()->filter(function (SslCertificate $cert) {
            return $cert->domain
                && $cert->domain->active_ssl_certificate_id === $cert->id;
        });

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

        return $skipped > 0 ? self::FAILURE : self::SUCCESS;
    }
}
