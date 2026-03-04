<?php

namespace App\Http\Controllers;

use App\Services\PortainerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;

class TerminalController extends Controller
{
    private const CACHE_PREFIX = 'terminal:';

    /**
     * Start a new terminal session for a container.
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

        // Create interactive exec (TTY)
        $exec = $portainer->createInteractiveExec($containerId, ['/bin/sh']);
        $execId = $exec['Id'];

        // Store session in cache
        $sessionData = [
            'session_id' => $sessionId,
            'exec_id' => $execId,
            'container_id' => $containerId,
            'container_name' => $containerName,
            'user_id' => $request->user()->id,
            'status' => 'starting',
            'started_at' => now()->toISOString(),
        ];

        Cache::put(self::CACHE_PREFIX.$sessionId, $sessionData, now()->addMinutes(20));

        // Track user's active sessions
        $userSessionsKey = self::CACHE_PREFIX.'user:'.$request->user()->id;
        $userSessions = Cache::get($userSessionsKey, []);
        $userSessions[$sessionId] = [
            'container_name' => $containerName,
            'started_at' => now()->toISOString(),
        ];
        Cache::put($userSessionsKey, $userSessions, now()->addMinutes(20));

        // Start the terminal bridge in background as a real process.
        $phpBinary = (new PhpExecutableFinder)->find(false);
        $phpBinary = is_string($phpBinary) ? trim($phpBinary) : '';
        $phpBinary = $phpBinary !== '' ? $phpBinary : 'php';
        $artisanPath = base_path('artisan');
        $attachLogPath = storage_path('logs/terminal.log');
        $startCommand = sprintf(
            'nohup %s %s terminal:attach %s >> %s 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($artisanPath),
            escapeshellarg($sessionId),
            escapeshellarg($attachLogPath),
        );

        try {
            $result = Process::path(base_path())
                ->timeout(5)
                ->run(['sh', '-lc', $startCommand]);

            if ($result->failed()) {
                throw new \RuntimeException(trim($result->errorOutput()) ?: 'Failed to spawn terminal attach command.');
            }
        } catch (\Throwable $exception) {
            Cache::forget(self::CACHE_PREFIX.$sessionId);
            Cache::forget(self::CACHE_PREFIX.$sessionId.':input');
            Cache::forget(self::CACHE_PREFIX.$sessionId.':buffer');

            unset($userSessions[$sessionId]);
            Cache::put($userSessionsKey, $userSessions, now()->addMinutes(20));

            Log::error("[Terminal] Failed to spawn attach process for {$sessionId}: {$exception->getMessage()}");

            return response()->json([
                'message' => 'Terminal session could not be started.',
            ], 500);
        }

        Log::info("[Terminal] Started session {$sessionId} for container {$containerName}");

        return response()->json([
            'session_id' => $sessionId,
            'container_name' => $containerName,
        ]);
    }

    /**
     * Send input keystrokes to a terminal session.
     */
    public function input(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
            'data' => 'required|string',
        ]);

        $sessionId = $request->input('session_id');
        $cacheKey = self::CACHE_PREFIX.$sessionId;

        if (! Cache::has($cacheKey)) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        // Verify ownership
        $session = Cache::get($cacheKey);
        if ($session['user_id'] !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Append input to queue
        $inputKey = self::CACHE_PREFIX.$sessionId.':input';
        $inputs = Cache::get($inputKey, []);
        $inputs[] = $request->input('data');
        Cache::put($inputKey, $inputs, now()->addMinutes(5));

        return response()->json(['ok' => true]);
    }

    /**
     * Reconnect to an existing session (returns scrollback buffer).
     */
    public function reconnect(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
        ]);

        $sessionId = $request->input('session_id');
        $cacheKey = self::CACHE_PREFIX.$sessionId;

        $session = Cache::get($cacheKey);
        if (! $session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        if ($session['user_id'] !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $buffer = Cache::get(self::CACHE_PREFIX.$sessionId.':buffer', '');

        return response()->json([
            'session_id' => $sessionId,
            'container_name' => $session['container_name'],
            'buffer' => $buffer,
        ]);
    }

    /**
     * Stop a terminal session.
     */
    public function stop(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
        ]);

        $sessionId = $request->input('session_id');
        $cacheKey = self::CACHE_PREFIX.$sessionId;

        $session = Cache::get($cacheKey);
        if (! $session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        if ($session['user_id'] !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Remove cache entries — artisan process will detect and exit
        Cache::forget($cacheKey);
        Cache::forget(self::CACHE_PREFIX.$sessionId.':input');
        Cache::forget(self::CACHE_PREFIX.$sessionId.':buffer');

        // Remove from user sessions tracker
        $userSessionsKey = self::CACHE_PREFIX.'user:'.$request->user()->id;
        $userSessions = Cache::get($userSessionsKey, []);
        unset($userSessions[$sessionId]);
        Cache::put($userSessionsKey, $userSessions, now()->addMinutes(20));

        Log::info("[Terminal] Stopped session {$sessionId}");

        return response()->json(['ok' => true]);
    }

    /**
     * List active sessions for the current user.
     */
    public function sessions(Request $request): JsonResponse
    {
        $userSessionsKey = self::CACHE_PREFIX.'user:'.$request->user()->id;
        $userSessions = Cache::get($userSessionsKey, []);

        // Validate sessions still exist
        $activeSessions = [];
        foreach ($userSessions as $sessionId => $meta) {
            if (Cache::has(self::CACHE_PREFIX.$sessionId)) {
                $activeSessions[$sessionId] = $meta;
            }
        }

        // Update cache if stale entries were removed
        if (count($activeSessions) !== count($userSessions)) {
            Cache::put($userSessionsKey, $activeSessions, now()->addMinutes(20));
        }

        return response()->json(['sessions' => $activeSessions]);
    }
}
