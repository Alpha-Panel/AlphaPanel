<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
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
        if (! $this->app->isLocal()) {
            $hotFile = public_path('hot');

            if (File::exists($hotFile)) {
                File::delete($hotFile);
            }
        }

        View::share('recaptcha_settings', (object) [
            'recaptchaV2_site_key' => (string) env('RECAPTCHA_V2_SITE_KEY', ''),
            'recaptchaV3_site_key' => (string) env('RECAPTCHA_V3_SITE_KEY', ''),
        ]);
    }
}
