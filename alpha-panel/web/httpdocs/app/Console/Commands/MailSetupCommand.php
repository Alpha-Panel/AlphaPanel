<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MailcowApiService;
use App\Services\MailcowDnsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MailSetupCommand extends Command
{
    protected $signature = 'panel:mail:setup
        {--dry-run : Preview changes without applying}';

    protected $description = 'Initial Mailcow mail server setup — creates global DNS records and webmail proxy';

    public function __construct(
        private MailcowApiService $api,
        private MailcowDnsService $dnsService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('panel.mailcow.enabled')) {
            $this->warn('Mailcow is not enabled. Set MAILCOW_ENABLED=true in .env');

            return self::FAILURE;
        }

        if (! $this->api->testConnection()) {
            $this->error('Cannot connect to Mailcow API.');

            return self::FAILURE;
        }

        $this->info('Connected to Mailcow API.');
        $this->newLine();

        $isDryRun = (bool) $this->option('dry-run');

        // 1. Provision global DNS records
        $this->info('Provisioning global mail DNS records...');
        $dnsResult = $this->provisionDns($isDryRun);

        if ($dnsResult === false) {
            return self::FAILURE;
        }

        $this->newLine();

        // 2. Generate webmail Caddyfile
        $this->info('Generating webmail Caddyfile...');
        $caddyResult = $this->generateWebmailCaddyfile($isDryRun);

        if ($caddyResult === false) {
            return self::FAILURE;
        }

        // 3. Output next steps
        $this->newLine();
        $this->outputInstructions();

        return self::SUCCESS;
    }

    /**
     * Provision global DNS records for the mail hostname and webmail domain.
     */
    private function provisionDns(bool $isDryRun): bool
    {
        if ($isDryRun) {
            $hostname = (string) config('panel.mailcow.hostname', 'mail.example.com');
            $webmailDomain = (string) config('panel.mailcow.webmail_domain', '');

            $this->line("  [DRY RUN] Would create A record for {$hostname}");

            if ($webmailDomain !== '' && $webmailDomain !== $hostname) {
                $this->line("  [DRY RUN] Would create A record for {$webmailDomain}");
            }

            return true;
        }

        try {
            $result = $this->dnsService->provisionGlobalRecords();

            foreach ($result['created'] as $record) {
                $this->line("  Created: {$record}");
            }

            foreach ($result['skipped'] as $record) {
                $this->line("  Skipped (already exists): {$record}");
            }

            foreach ($result['errors'] as $error) {
                $this->error("  Error: {$error}");
            }

            if ($result['errors'] !== []) {
                $this->error('DNS provisioning completed with errors.');

                return false;
            }

            $this->info('DNS records provisioned successfully.');

            return true;
        } catch (\Throwable $e) {
            $this->error("DNS provisioning failed: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Generate the webmail reverse-proxy Caddyfile.
     */
    private function generateWebmailCaddyfile(bool $isDryRun): bool
    {
        $hostname = (string) config('panel.mailcow.hostname', 'mail.example.com');
        $webmailDomain = (string) config('panel.mailcow.webmail_domain', '');

        $caddySitesBase = (string) config('panel.caddy_sites_base');
        $caddyfilePath = "{$caddySitesBase}/_webmail/Caddyfile";

        $content = $this->renderWebmailCaddyfile($hostname, $webmailDomain);

        if ($isDryRun) {
            $this->line("  [DRY RUN] Would write Caddyfile to: {$caddyfilePath}");
            $this->newLine();
            $this->line($content);

            return true;
        }

        try {
            $dir = dirname($caddyfilePath);

            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }

            $tempPath = $caddyfilePath.'.tmp.'.uniqid();
            File::put($tempPath, $content);
            File::move($tempPath, $caddyfilePath);

            $this->info("  Caddyfile written to: {$caddyfilePath}");

            return true;
        } catch (\Throwable $e) {
            $this->error("Failed to write Caddyfile: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Render the webmail Caddyfile content.
     */
    private function renderWebmailCaddyfile(string $hostname, string $webmailDomain): string
    {
        $blocks = [];

        if ($webmailDomain !== '' && $webmailDomain !== $hostname) {
            $blocks[] = $this->renderCaddyBlock($webmailDomain, 'webmail');
        }

        $blocks[] = $this->renderCaddyBlock($hostname, 'mail');

        return "# Auto-generated by panel:mail:setup — do not edit manually\n".implode("\n", $blocks);
    }

    /**
     * Render a single Caddy reverse-proxy block for a mail domain.
     */
    private function renderCaddyBlock(string $domain, string $logName): string
    {
        return <<<CADDY

        {$domain}:443 {
            encode zstd br gzip
            reverse_proxy http://mailcow-nginx:8080 {
                header_up Host {http.request.host}
                header_up X-Real-IP {client_ip}
                header_up X-Forwarded-For {client_ip}
                header_up X-Forwarded-Proto https
            }
            log {
                output file /var/log/caddy/{$logName}.log
                format console
            }
        }
        CADDY;
    }

    /**
     * Output post-setup instructions.
     */
    private function outputInstructions(): void
    {
        $hostname = (string) config('panel.mailcow.hostname', 'mail.example.com');
        $webmailDomain = (string) config('panel.mailcow.webmail_domain', '');

        $this->info('Mail setup complete. Next steps:');
        $this->newLine();
        $this->line('  1. Wait for DNS propagation (check with: dig +short '.$hostname.')');
        $this->line('  2. Provision SSL certificates for the mail hostname:');
        $this->line("     - {$hostname}");

        if ($webmailDomain !== '' && $webmailDomain !== $hostname) {
            $this->line("     - {$webmailDomain}");
        }

        $this->line('  3. Reload Caddy to pick up the new configuration:');
        $this->line('     docker compose exec frankenphp caddy reload --config /etc/caddy/Caddyfile');
    }
}
