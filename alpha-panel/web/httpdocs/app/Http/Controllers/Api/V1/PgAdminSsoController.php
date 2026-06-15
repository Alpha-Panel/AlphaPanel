<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PgAdminSsoController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->isAdmin()) {
            return redirect(config('app.url').'/login');
        }

        $pgAdminUrl = config('services.pgadmin.url');
        abort_unless(filled($pgAdminUrl), 500, 'PGADMIN_URL is not configured.');

        $token = Str::random(64);
        $ttlSeconds = 86400;

        Cache::put("pgadmin:sso:{$token}", $user->email, $ttlSeconds);

        $cookie = cookie(
            name: 'pgadmin_sso',
            value: $token,
            minutes: (int) ($ttlSeconds / 60),
            path: '/',
            domain: config('services.pgadmin.cookie_domain'),
            secure: true,
            httpOnly: true,
            sameSite: 'Lax',
        );

        return redirect()->away((string) $pgAdminUrl)->withCookie($cookie);
    }
}
