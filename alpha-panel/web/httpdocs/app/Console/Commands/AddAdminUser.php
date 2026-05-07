<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class AddAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:add-admin-user
                            {--name= : Admin display name}
                            {--username= : Admin username}
                            {--email= : Admin email address}
                            {--password= : Admin password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an admin user';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $username = $this->option('username') ?: $this->ask('Username');
        $email = $this->option('email') ?: $this->ask('Email');
        $password = $this->option('password') ?: $this->secret('Password');

        $user = User::create([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ]);

        $user->admin = true;
        $user->save();

        $this->info('Admin user created successfully.');
    }
}
