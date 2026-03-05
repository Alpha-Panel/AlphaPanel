<?php

namespace App\Http\Controllers;

use App\Services\PortainerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TerminalController extends Controller
{
    private const CACHE_PREFIX = 'terminal:';

    /**
     * Create a Portainer exec session and return a short-lived WebSocket token.
     */
    public function start(Request $request, PortainerService $portainer): JsonResponse
    {
        $request->validate([
            'container_id' => 'required|string',
            'container_name' => 'required|string',
        ]);

        $containerId = $request->input('container_id');
        $containerName = $request->input('container_name');
        $sessionId = Str::uuid()->toString();

        $exec = $portainer->createInteractiveExec($containerId, ['/bin/sh']);
        $execId = $exec['Id'];

        // Short-lived token for WebSocket connection (30 s, single-use)
        $wsToken = Str::random(40);

        Cache::put(self::CACHE_PREFIX.'ws:'.$wsToken, [
            'session_id' => $sessionId,
            'exec_id' => $execId,
            'ws_url' => $portainer->getExecWebSocketUrl($execId),
            'api_key' => $portainer->getExecWebSocketHeaders()['X-API-Key'],
            'container_name' => $containerName,
            'user_id' => $request->user()->id,
        ], now()->addSeconds(30));

        Log::info("[Terminal] Created session {$sessionId} for {$containerName} (exec={$execId})");

        return response()->json([
            'session_id' => $sessionId,
            'ws_token' => $wsToken,
            'container_name' => $containerName,
        ]);
    }

    /**
     * Terminate a terminal session (informational — the proxy closes when the WS closes).
     */
    public function stop(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
        ]);

        Log::info('[Terminal] Stop requested for session '.$request->input('session_id'));

        return response()->json(['ok' => true]);
    }
}
