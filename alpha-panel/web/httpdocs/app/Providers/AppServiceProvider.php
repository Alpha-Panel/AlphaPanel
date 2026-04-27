<?php

namespace App\Providers;

use App\Listeners\SuppressNotificationsDuringImpersonation;
use App\Models\SecuritySetting;
use App\Services\ImpersonationService;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ImpersonationService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super admin bypass — users with admin=true pass all permission/policy checks.
        Gate::before(fn ($user, $ability) => $user->isAdmin() ? true : null);

        Event::listen(NotificationSending::class, SuppressNotificationsDuringImpersonation::class);

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
