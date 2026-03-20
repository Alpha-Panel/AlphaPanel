<?php

namespace App\Console\Commands;

use App\Models\FtpUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

class MigrateFtpPasswords extends Command
{
    protected $signature = 'ftp:migrate-passwords {--dry-run : Show what would be done without making changes}';

    protected $description = 'Decrypt existing FTP passwords and generate SHA256 hashes for ProFTPD MySQL auth';

    public function handle(): int
    {
        $users = FtpUser::whereNotNull('encrypted_password')->get();

        if ($users->isEmpty()) {
            $this->info('No FTP users with passwords found.');

            return self::SUCCESS;
        }

        $this->info("Found {$users->count()} FTP user(s) to migrate.");

        $migrated = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                $plainPassword = Crypt::decryptString($user->getRawOriginal('encrypted_password'));
                $hash = '{sha256}'.base64_encode(hex2bin(hash('sha256', $plainPassword)));

                if ($this->option('dry-run')) {
                    $this->line("  [DRY RUN] {$user->username}: would set password");
                } else {
                    $user->updateQuietly(['password' => $hash]);
                    $this->line("  Migrated: {$user->username}");
                }

                $migrated++;
            } catch (\Exception $e) {
                $this->error("  Failed: {$user->username} — {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Migrated: {$migrated}, Failed: {$failed}");

        if ($this->option('dry-run')) {
            $this->warn('This was a dry run. No changes were made.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
