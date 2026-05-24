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

        $token = Str::random(48);
        Cache::put('mailu.api.token', $token, now()->addYear());

        $this->info("Mailu admin: {$admin}");
        $this->info("Mailu API base: {$base}");
        $this->info('API token cached. Use it as `Authorization: Bearer <token>` against the Mailu admin REST API.');
        $this->warn('Reminder: register this token inside Mailu via `flask mailu admin-token issue` (Mailu CLI).');

        return self::SUCCESS;
    }
}
