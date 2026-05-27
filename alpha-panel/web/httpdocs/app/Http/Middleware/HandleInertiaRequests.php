<?php

namespace App\Http\Middleware;

use App\Models\ZimbraServerSetting;
use App\Services\ImpersonationService;
use App\Services\Mail\MailSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @var array<string, array<string, string>>
     */
    private static array $translationCache = [];

    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $locale = app()->getLocale();
        $rtlLocales = config('app.rtl_locales', ['tr-gokturk']);
        $textDirection = in_array($locale, $rtlLocales, true) ? 'rtl' : 'ltr';

        return [
            ...parent::share($request),
            'auth' => fn () => $user ? [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'is_admin' => $user->isAdmin(),
                    'avatar_url' => sprintf(
                        'https://www.gravatar.com/avatar/%s?d=mp&s=200',
                        md5(strtolower(trim((string) $user->email))),
                    ),
                ],
                'permissions' => rescue(fn () => $user->getAllPermissions()->pluck('name')->toArray(), [], false),
                'roles' => rescue(fn () => $user->getRoleNames()->toArray(), [], false),
            ] : null,
            'impersonation' => function () {
                $service = app(ImpersonationService::class);
                if (! $service->isActive()) {
                    return null;
                }

                $impersonator = $service->impersonator();
                $target = $service->target();
                if ($impersonator === null || $target === null) {
                    return null;
                }

                return [
                    'active' => true,
                    'impersonator' => [
                        'id' => $impersonator->id,
                        'name' => $impersonator->name,
                        'username' => $impersonator->username,
                    ],
                    'target' => [
                        'id' => $target->id,
                        'name' => $target->name,
                        'username' => $target->username,
                    ],
                    'started_at' => $service->startedAt()?->toIso8601String(),
                    'stop_url' => route('impersonation.stop'),
                ];
            },
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info' => $request->session()->get('info'),
            ],
            'app' => [
                'name' => config('app.name'),
                'logo_url' => asset('img/AlphaPanel-dark.svg'),
                'links' => [
                    'file_manager' => config('services.file_manager_url'),
                    'jenkins' => config('services.jenkins_url'),
                    'n8n' => config('services.n8n_url'),
                ],
            ],
            'features' => function () {
                $settings = app(MailSettingsService::class);

                return [
                    'mail' => $settings->mailEnabled(),
                    'mailu' => $settings->mailuEnabled(),
                    'zimbra' => $settings->zimbraEnabled(),
                ];
            },
            'mail' => function () {
                $zimbra = ZimbraServerSetting::current();

                return [
                    'zimbra_default_host' => $zimbra?->default_mx_host
                        ?? config('panel.mail.zimbra.default_mx_host'),
                    'mail_domain' => config('panel.mail.domain'),
                    'mailu_api_token_set' => (bool) (config('services.mailu.api_token')
                        ?: cache()->has('mailu.api.token')),
                    'webmail_panel_url' => config('panel.mail.webmail_panel_url'),
                    'admin_panel_url' => config('panel.mail.admin_panel_url'),
                ];
            },
            'locale' => $locale,
            'text_direction' => $textDirection,
            'available_locales' => config('app.supported_locales', ['tr', 'tr-gokturk', 'gokturk-latin', 'az', 'en', 'de', 'es', 'fr', 'ru']),
            'rtl_locales' => $rtlLocales,
            'translations' => fn () => $this->loadLocaleTranslations($locale),
            'vapid_public_key' => config('webpush.vapid.public_key'),
            'update_available' => fn () => rescue(
                fn () => $user?->hasRole('admin') ? (bool) Cache::get('system:update_available', false) : false,
                false,
                false,
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function loadLocaleTranslations(string $locale): array
    {
        if (isset(self::$translationCache[$locale])) {
            return self::$translationCache[$locale];
        }

        $path = lang_path("{$locale}.json");

        if (! File::exists($path)) {
            self::$translationCache[$locale] = [];

            return self::$translationCache[$locale];
        }

        $decoded = json_decode((string) File::get($path), true);
        self::$translationCache[$locale] = is_array($decoded) ? $decoded : [];

        return self::$translationCache[$locale];
    }
}
