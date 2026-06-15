<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class PgAdminAuthController extends Controller
{
    public function check(Request $request): Response
    {
        $token = $request->cookie('pgadmin_sso');

        if ($token === null || $token === '') {
            return redirect(config('app.url').'/login');
        }

        /** @var string|null $email */
        $email = Cache::get("pgadmin:sso:{$token}");

        if ($email === null) {
            return redirect(config('app.url').'/login');
        }

        return response('OK', 200, ['X-Remote-User' => $email]);
    }
}
