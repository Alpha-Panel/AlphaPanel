<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MailDomain;
use App\Services\MailcowApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class MailSyncCommand extends Command
{
    protected $signature = 'panel:mail:sync
        {--domain= : Sync a specific domain only}
        {--dry-run : Preview changes without applying}';

    protected $description = 'Synchronize mail state between AlphaPanel and Mailcow';

    public function handle(MailcowApiService $api): int
    {
        if (! config('panel.mailcow.enabled')) {
            $this->warn('Mailcow is not enabled. Set MAILCOW_ENABLED=true in .env');

            return self::FAILURE;
        }

        if (! $api->testConnection()) {
            $this->error('Cannot connect to Mailcow API.');

            return self::FAILURE;
        }

        $this->info('Connected to Mailcow API.');

        $query = MailDomain::query();

        if ($domain = $this->option('domain')) {
            $query->where('mail_domain', $domain);
        }

        $mailDomains = $query->with('mailboxes')->get();

        if ($mailDomains->isEmpty()) {
            $this->info('No mail domains found to sync.');

            return self::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('[DRY RUN] No changes will be applied.');
            $this->newLine();
        }

        $summary = [];

        foreach ($mailDomains as $mailDomain) {
            $result = $this->syncDomain($api, $mailDomain, $isDryRun);
            $summary[] = $result;
        }

        $this->newLine();
        $this->table(
            ['Domain', 'Mailboxes Synced', 'Orphaned Local', 'Orphaned Remote'],
            $summary,
        );

        return self::SUCCESS;
    }

    /**
     * Sync a single mail domain's mailboxes with Mailcow.
     *
     * @return array{0: string, 1: int, 2: int, 3: int}
     */
    private function syncDomain(MailcowApiService $api, MailDomain $mailDomain, bool $isDryRun): array
    {
        $domain = $mailDomain->mail_domain;
        $synced = 0;
        $orphanedLocal = 0;
        $orphanedRemote = 0;

        try {
            $remoteMailboxes = $api->listMailboxes($domain);
        } catch (\Throwable $e) {
            $this->error("  Failed to fetch mailboxes for {$domain}: {$e->getMessage()}");

            return [$domain, 0, 0, 0];
        }

        // Index remote mailboxes by full_address (username field from Mailcow).
        $remoteByAddress = [];
        foreach ($remoteMailboxes as $remote) {
            $address = (string) ($remote['username'] ?? '');
            if ($address !== '') {
                $remoteByAddress[$address] = $remote;
            }
        }

        // Index local mailboxes by full_address.
        $localMailboxes = $mailDomain->mailboxes;
        $localByAddress = [];
        foreach ($localMailboxes as $localMailbox) {
            $localByAddress[$localMailbox->full_address] = $localMailbox;
        }

        // Update local records from remote state.
        foreach ($remoteByAddress as $address => $remote) {
            if (isset($localByAddress[$address])) {
                $localMailbox = $localByAddress[$address];

                $quotaUsedMb = (int) round(((int) ($remote['quota_used'] ?? 0)) / 1048576);
                $messagesCount = (int) ($remote['messages'] ?? 0);
                $lastLogin = $this->parseTimestamp($remote['last_imap_login'] ?? null);

                if ($isDryRun) {
                    $this->line("  [DRY RUN] {$address} — quota_used: {$quotaUsedMb} MB, messages: {$messagesCount}");
                } else {
                    $localMailbox->update([
                        'quota_used_mb' => $quotaUsedMb,
                        'messages_count' => $messagesCount,
                        'last_login_at' => $lastLogin,
                    ]);
                    $this->line("  OK  {$address} — synced");
                }

                $synced++;
            } else {
                $this->warn("  Orphaned remote mailbox: {$address} (exists in Mailcow but not in AlphaPanel)");
                $orphanedRemote++;
            }
        }

        // Detect local records that no longer exist in Mailcow.
        foreach ($localByAddress as $address => $localMailbox) {
            if (! isset($remoteByAddress[$address])) {
                $this->warn("  Orphaned local mailbox: {$address} (exists in AlphaPanel but not in Mailcow)");
                $orphanedLocal++;
            }
        }

        return [$domain, $synced, $orphanedLocal, $orphanedRemote];
    }

    /**
     * Parse a Mailcow timestamp (Unix epoch or null/zero) into a Carbon instance.
     */
    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        $timestamp = (int) $value;

        return $timestamp > 0 ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
