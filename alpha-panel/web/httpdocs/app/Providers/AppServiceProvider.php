<?php

namespace App\Providers;

use App\Listeners\SuppressNotificationsDuringImpersonation;
use App\Models\BackupRun;
use App\Models\DockerService;
use App\Models\Domain;
use App\Models\DomainCronJobLog;
use App\Models\SecuritySetting;
use App\Models\SslCertificate;
use App\Observers\BackupRunObserver;
use App\Observers\DockerServiceObserver;
use App\Observers\DomainCronJobObserver;
use App\Observers\DomainObserver;
use App\Observers\SslObserver;
use App\Services\ImpersonationService;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
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

        Domain::observe(DomainObserver::class);
        SslCertificate::observe(SslObserver::class);
        BackupRun::observe(BackupRunObserver::class);
        DockerService::observe(DockerServiceObserver::class);
        DomainCronJobLog::observe(DomainCronJobObserver::class);

        if (! $this->app->isLocal()) {
            $hotFile = public_path('hot');

            if (File::exists($hotFile)) {
                File::delete($hotFile);
            }
        }

        // Dynamically enable/disable honeypot from database settings.
        // tryInstance() returns null if the table is missing (e.g. fresh
        // install before the first migration run); honeypot stays at
        // whatever the config file declares in that case.
        if ($setting = SecuritySetting::tryInstance()) {
            config()->set('honeypot.enabled', $setting->honeypot_enabled);
        }

        $this->configureScramble();
    }

    /**
     * Configure Scramble OpenAPI generator: serve UI at /api/docs and document Sanctum Bearer auth.
     */
    private function configureScramble(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        Scramble::registerUiRoute('api/docs');
        Scramble::registerJsonSpecificationRoute('api/docs.json');

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                        ->setDescription('Sanctum personal access token. Generate at Settings > API Tokens.')
                );
            });
    }
}
