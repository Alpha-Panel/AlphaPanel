<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MailBootstrapCommand extends Command
{
    protected $signature = 'mail:bootstrap
        {--force : Re-issue the API token even if one already exists}';

    protected $description = 'Initialize the Mailu admin API token cache for the panel.';

    public function handle(HttpFactory $http): int
    {
        if (! filter_var(env('MAIL_ENABLED', false), FILTER_VALIDATE_BOOL)) {
            $this->error('MAIL_ENABLED is false. Set it to true in root .env first.');

            return self::FAILURE;
        }

        $base = rtrim((string) config('panel.mail.mailu_admin_url'), '/');
        $admin = 'admin@'.config('panel.base_domain');
        $password = (string) env('MAIL_ADMIN_PASSWORD');
        if ($password === '') {
            $this->error('MAIL_ADMIN_PASSWORD missing.');

            return self::FAILURE;
        }

        if (! $this->option('force') && Cache::has('mailu.api.token')) {
            $this->info('Mailu API token already cached. Use --force to re-issue.');

            return self::SUCCESS;
        }

        // Mailu admin reads API_TOKEN from its own env. We mirror MAIL_ADMIN_PASSWORD
        // as the shared bearer secret so the panel and Mailu agree without an extra round-trip.
        Cache::put('mailu.api.token', $password, now()->addYear());

        $this->info("Mailu admin: {$admin}");
        $this->info("Mailu API base: {$base}");
        $this->info('Mailu admin container must run with: API=true and API_TOKEN=${MAIL_ADMIN_PASSWORD}.');
        $this->info('The panel uses that same value as the Bearer token.');

        return self::SUCCESS;
    }
}
