<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;

class PingController extends ApiController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'panel_version' => config('app.version', '1.0.0'),
                'php_version' => PHP_VERSION,
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
