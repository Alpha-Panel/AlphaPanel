<?php

namespace App\Providers;

use App\Models\SecuritySetting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super admin bypass — users with admin=true pass all permission/policy checks.
        Gate::before(fn ($user, $ability) => $user->isAdmin() ? true : null);

        if (! $this->app->isLocal()) {
            $hotFile = public_path('hot');

            if (File::exists($hotFile)) {
                File::delete($hotFile);
            }
        }

        // Dynamically enable/disable honeypot from database settings
        try {
            config()->set('honeypot.enabled', SecuritySetting::instance()->honeypot_enabled);
        } catch (\Throwable) {
            // Table may not exist yet (before migration)
        }
    }
}
