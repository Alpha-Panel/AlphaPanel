<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\PortainerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TerminalController extends ApiController
{
    private const CACHE_PREFIX = 'terminal:';

    public function start(Request $request, PortainerService $portainer): JsonResponse
    {
        $request->validate([
            'container_id' => 'required|string',
            'container_name' => 'required|string',
        ]);

        $containerId = $request->input('container_id');
        $containerName = $request->input('container_name');
        $sessionId = Str::uuid()->toString();

        $exec = $portainer->createInteractiveExec($containerId, ['/bin/bash']);
        $execId = $exec['Id'];
        $wsToken = Str::random(40);

        Cache::put(self::CACHE_PREFIX.'ws:'.$wsToken, [
            'session_id' => $sessionId,
            'exec_id' => $execId,
            'ws_url' => $portainer->getExecWebSocketUrl($execId),
            'api_key' => $portainer->getExecWebSocketHeaders()['X-API-Key'],
            'container_name' => $containerName,
            'user_id' => $request->user()->id,
            'ip_address' => $request->ip(),
            'port' => $request->server('REMOTE_PORT'),
        ], now()->addSeconds(30));

        Log::info("[Terminal API] Session {$sessionId} for {$containerName}");

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'terminal_opened',
            'summary' => "API: Container: {$containerName}",
        ]);

        return response()->json([
            'session_id' => $sessionId,
            'ws_token' => $wsToken,
            'container_name' => $containerName,
        ]);
    }

    public function startDomain(Request $request, PortainerService $portainer): JsonResponse
    {
        $request->validate(['domain_id' => 'required|integer|exists:domains,id']);

        $domain = Domain::with('ftpUser')->findOrFail($request->input('domain_id'));
        $ftpUser = $domain->ftpUser;

        if (! $ftpUser) {
            return response()->json(['message' => __('No FTP user found for this domain.')], 422);
        }

        $containerName = 'frankenphp';
        $container = $portainer->findContainerByName($containerName);
        $containerId = $container['Id'];
        $sessionId = Str::uuid()->toString();
        $displayName = "{$domain->fqdn} ({$ftpUser->username})";

        $exec = $portainer->createInteractiveExec($containerId, ['/bin/bash'], $ftpUser->username, $ftpUser->homedir);
        $execId = $exec['Id'];
        $wsToken = Str::random(40);

        Cache::put(self::CACHE_PREFIX.'ws:'.$wsToken, [
            'session_id' => $sessionId,
            'exec_id' => $execId,
            'ws_url' => $portainer->getExecWebSocketUrl($execId),
            'api_key' => $portainer->getExecWebSocketHeaders()['X-API-Key'],
            'container_name' => $displayName,
            'user_id' => $request->user()->id,
            'ip_address' => $request->ip(),
            'port' => $request->server('REMOTE_PORT'),
        ], now()->addSeconds(30));

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'terminal_opened',
            'summary' => "API Domain: {$domain->fqdn} ({$ftpUser->username})",
        ]);

        return response()->json(['session_id' => $sessionId, 'ws_token' => $wsToken, 'container_name' => $displayName]);
    }

    public function startSsh(Request $request): JsonResponse
    {
        $sessionId = Str::uuid()->toString();
        $wsToken = Str::random(40);

        Cache::put(self::CACHE_PREFIX.'ws:'.$wsToken, [
            'type' => 'ssh',
            'session_id' => $sessionId,
            'container_name' => 'Host Terminal',
            'ssh_host' => config('panel.ssh_host'),
            'ssh_port' => config('panel.ssh_port'),
            'ssh_user' => config('panel.ssh_user'),
            'ssh_key_path' => config('panel.ssh_key_path'),
            'user_id' => $request->user()->id,
            'ip_address' => $request->ip(),
            'port' => $request->server('REMOTE_PORT'),
        ], now()->addSeconds(30));

        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'terminal_opened', 'summary' => 'API: Host Terminal (SSH)']);

        return response()->json(['session_id' => $sessionId, 'ws_token' => $wsToken, 'container_name' => 'Host Terminal']);
    }

    public function stop(Request $request): JsonResponse
    {
        $request->validate(['session_id' => 'required|string']);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'terminal_closed', 'summary' => 'Session: '.$request->input('session_id')]);

        return response()->json(['ok' => true]);
    }
}
