<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\AuditLog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('webauthn', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        $this->registerTwoFactorAuditListeners();
    }

    private function registerTwoFactorAuditListeners(): void
    {
        $events = [
            TwoFactorAuthenticationEnabled::class => ['two_factor_enabled', 'Enabled two-factor authentication'],
            TwoFactorAuthenticationConfirmed::class => ['two_factor_confirmed', 'Confirmed two-factor authentication'],
            TwoFactorAuthenticationDisabled::class => ['two_factor_disabled', 'Disabled two-factor authentication'],
            RecoveryCodesGenerated::class => ['two_factor_recovery_codes_generated', 'Regenerated 2FA recovery codes'],
            RecoveryCodeReplaced::class => ['two_factor_recovery_code_used', 'Used a 2FA recovery code'],
        ];

        foreach ($events as $eventClass => [$action, $summary]) {
            Event::listen($eventClass, function ($event) use ($action, $summary): void {
                if (! isset($event->user) || ! $event->user) {
                    return;
                }

                AuditLog::create([
                    'user_id' => $event->user->id,
                    'action' => $action,
                    'summary' => $summary,
                ]);
            });
        }
    }
}
