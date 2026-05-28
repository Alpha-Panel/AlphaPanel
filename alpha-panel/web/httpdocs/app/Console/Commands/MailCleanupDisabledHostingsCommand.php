<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MailHosting;
use App\Models\Domain;
use App\Services\Mail\MailSettingsService;
use Illuminate\Console\Command;

class MailCleanupDisabledHostingsCommand extends Command
{
    protected $signature = 'mail:cleanup-disabled-hostings
        {--dry-run : List affected domains without updating}
        {--target=disabled : Hosting mode to set on affected domains (disabled or remote)}';

    protected $description = 'Reset domain mail_hosting to disabled when the chosen provider feature is off.';

    public function handle(MailSettingsService $mailSettings): int
    {
        $target = $this->parseTarget();
        if ($target === null) {
            return self::FAILURE;
        }

        $affected = collect(MailHosting::cases())
            ->filter(fn (MailHosting $h) => $h->requiresFeature() !== null && ! $mailSettings->isHostingAvailable($h))
            ->map(fn (MailHosting $h) => $h->value)
            ->values()
            ->all();

        if ($affected === []) {
            $this->info('All managed mail providers are enabled. Nothing to clean up.');

            return self::SUCCESS;
        }

        $query = Domain::query()->whereIn('mail_hosting', $affected);
        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No domains found with stale mail_hosting values: '.implode(', ', $affected));

            return self::SUCCESS;
        }

        $this->warn("Found {$count} domain(s) with mail_hosting in [".implode(', ', $affected).'].');

        if ($this->option('dry-run')) {
            (clone $query)
                ->select(['id', 'fqdn', 'mail_hosting'])
                ->orderBy('fqdn')
                ->chunk(100, function ($rows): void {
                    foreach ($rows as $row) {
                        $this->line(" - #{$row->id} {$row->fqdn} ({$row->mail_hosting->value})");
                    }
                });

            $this->info("Dry run: no changes applied. Re-run without --dry-run to set mail_hosting to '{$target->value}'.");

            return self::SUCCESS;
        }

        $updated = $query->update(['mail_hosting' => $target->value]);
        $this->info("Updated {$updated} domain(s) → mail_hosting='{$target->value}'.");

        return self::SUCCESS;
    }

    private function parseTarget(): ?MailHosting
    {
        $raw = (string) $this->option('target');
        $hosting = MailHosting::tryFrom($raw);

        if ($hosting === null || $hosting->requiresFeature() !== null) {
            $this->error("Invalid --target='{$raw}'. Allowed: disabled, remote.");

            return null;
        }

        return $hosting;
    }
}
