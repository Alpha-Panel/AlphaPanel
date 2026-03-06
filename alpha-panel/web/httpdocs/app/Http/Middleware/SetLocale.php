<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = config('app.supported_locales', ['tr', 'tr-gokturk', 'gokturk-latin', 'az', 'en', 'de', 'es', 'fr', 'ru']);
        $queryLocale = $request->query('lang');

        if (is_string($queryLocale) && in_array($queryLocale, $supportedLocales, true)) {
            $request->session()->put('locale', $queryLocale);
        }

        $locale = $request->cookie('locale')
            ?? $request->session()->get('locale')
            ?? config('app.locale', 'en');

        if (! is_string($locale) || ! in_array($locale, $supportedLocales, true)) {
            $locale = (string) config('app.locale', 'en');
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
