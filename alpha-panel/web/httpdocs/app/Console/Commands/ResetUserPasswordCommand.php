<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class ResetUserPasswordCommand extends Command
{
    protected $signature = 'app:reset-password
                            {--user= : Email or username of the account (defaults to the only admin)}
                            {--password= : New password (prompted when omitted)}';

    protected $description = 'Reset a panel account password from the console';

    public function handle(): int
    {
        $identifier = $this->option('user');

        $user = $identifier
            ? $this->findUser($identifier)
            : $this->onlyAdmin();

        if (! $user instanceof User) {
            return self::FAILURE;
        }

        // Prompt only when the option is absent. An explicitly empty --password is an
        // error, not a reason to block on stdin — this runs under `docker exec`.
        $password = $this->option('password') ?? $this->secret('New password');

        if (! is_string($password) || trim($password) === '') {
            $this->error('Password cannot be empty.');

            return self::FAILURE;
        }

        $user->password = $password;

        // Fortify lowercases the login field before looking the account up
        // (config fortify.lowercase_usernames + CanonicalizeUsername), so an
        // account stored with any uppercase character can never sign in.
        $loginField = Fortify::username();
        $stored = (string) $user->{$loginField};
        $canonical = Str::lower($stored);

        if ($stored !== $canonical) {
            $user->{$loginField} = $canonical;
            $this->warn("Login field normalised: {$stored} -> {$canonical}");
        }

        $user->save();

        $this->info('Password updated.');
        $this->line("  sign in with: {$user->{$loginField}}");

        return self::SUCCESS;
    }

    private function findUser(string $identifier): ?User
    {
        $needle = Str::lower($identifier);

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$needle])
            ->orWhereRaw('LOWER(username) = ?', [$needle])
            ->first();

        if (! $user) {
            $this->error("No account matches \"{$identifier}\".");
            $this->line('Known accounts:');
            foreach (User::query()->get(['username', 'email']) as $known) {
                $this->line("  {$known->username} <{$known->email}>");
            }
        }

        return $user;
    }

    private function onlyAdmin(): ?User
    {
        $admins = User::query()->where('admin', true)->get();

        if ($admins->count() === 1) {
            return $admins->first();
        }

        $this->error($admins->isEmpty()
            ? 'No admin account exists. Create one with app:add-admin-user.'
            : 'Several admin accounts exist — pass --user to pick one:');

        foreach ($admins as $admin) {
            $this->line("  {$admin->username} <{$admin->email}>");
        }

        return null;
    }
}
