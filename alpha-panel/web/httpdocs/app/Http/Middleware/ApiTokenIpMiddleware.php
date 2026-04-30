<?php

namespace App\Http\Middleware;

use App\Models\ApiTokenIpRule;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenIpMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $next($request);
        }

        $pat = PersonalAccessToken::findToken($token);

        if (! $pat) {
            return $next($request);
        }

        $rules = ApiTokenIpRule::where('personal_access_token_id', $pat->id)
            ->pluck('ip_cidr')
            ->toArray();

        if (empty($rules)) {
            return $next($request);
        }

        $clientIp = $request->ip() ?? '';

        foreach ($rules as $cidr) {
            if (IpUtils::checkIp($clientIp, $cidr)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'IP not allowed'], 403);
    }
}
