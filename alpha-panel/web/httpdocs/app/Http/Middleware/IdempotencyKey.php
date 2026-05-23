<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key support for POST endpoints.
 *
 * Clients pass `Idempotency-Key: <unique-string>`. The first request for that
 * key is processed normally; the response (status + body) is cached for 24h
 * scoped to the authenticated user. Subsequent requests with the same key
 * replay the original response, preventing duplicate-resource creation when
 * the client retries on transient network failure.
 *
 * Skip rules:
 *  - No header → process normally (header is opt-in).
 *  - Non-mutating verbs (GET/HEAD/OPTIONS) → skip.
 *  - Unauthenticated → skip (keyed by user ID).
 */
class IdempotencyKey
{
    private const CACHE_PREFIX = 'idem:';

    private const TTL_SECONDS = 86_400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $user = $request->user();

        if ($key === null || $user === null || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = trim((string) $key);
        if ($key === '' || strlen($key) > 255) {
            return $next($request);
        }

        $cacheKey = self::CACHE_PREFIX.$user->id.':'.hash('sha256', $key);

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['status'], $cached['body'])) {
            return new JsonResponse(
                json_decode((string) $cached['body'], true),
                (int) $cached['status'],
                ['Idempotent-Replay' => 'true'],
            );
        }

        /** @var Response $response */
        $response = $next($request);

        if ($response->isSuccessful() || $response->isRedirection()) {
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getContent(),
            ], self::TTL_SECONDS);
        }

        return $response;
    }
}
