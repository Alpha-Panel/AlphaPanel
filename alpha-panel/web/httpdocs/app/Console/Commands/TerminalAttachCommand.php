<?php

namespace App\Console\Commands;

use App\Events\TerminalOutput;
use App\Services\PortainerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use WebSocket\Client as WsClient;
use WebSocket\TimeoutException;

class TerminalAttachCommand extends Command
{
    protected $signature = 'terminal:attach {sessionId}';

    protected $description = 'Attach to a container shell via Portainer WebSocket and bridge I/O through Reverb';

    private const IDLE_TIMEOUT = 900; // 15 minutes

    private const CACHE_PREFIX = 'terminal:';

    private const BUFFER_MAX = 50000; // scrollback buffer max chars

    public function handle(PortainerService $portainer): int
    {
        $sessionId = $this->argument('sessionId');
        $cacheKey = self::CACHE_PREFIX.$sessionId;

        $session = Cache::get($cacheKey);
        if (! $session) {
            $this->error("Session {$sessionId} not found in cache.");

            return self::FAILURE;
        }

        $execId = $session['exec_id'];
        $wsUrl = $portainer->getExecWebSocketUrl($execId);
        $wsHeaders = $portainer->getExecWebSocketHeaders();

        Log::info("[Terminal] Attaching to session {$sessionId}, exec {$execId}");

        // Mark session as connected
        Cache::put($cacheKey, array_merge($session, ['status' => 'connected']), now()->addMinutes(20));

        try {
            $ws = new WsClient($wsUrl, [
                'timeout' => 1,
                'headers' => $wsHeaders,
                'context' => stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                ]),
            ]);

            $lastActivity = time();
            $buffer = '';

            while (true) {
                // Check if session was stopped (cache deleted)
                if (! Cache::has($cacheKey)) {
                    Log::info("[Terminal] Session {$sessionId} cache removed, exiting.");
                    break;
                }

                // Idle timeout
                if ((time() - $lastActivity) > self::IDLE_TIMEOUT) {
                    Log::info("[Terminal] Session {$sessionId} idle timeout.");
                    $this->broadcastOutput($sessionId, "\r\n\033[33m[Session timed out after 15 minutes of inactivity]\033[0m\r\n");
                    break;
                }

                // Read input from cache queue
                $inputKey = self::CACHE_PREFIX.$sessionId.':input';
                $inputs = Cache::pull($inputKey);
                if ($inputs) {
                    foreach ((array) $inputs as $input) {
                        $decoded = base64_decode($input);
                        if ($decoded !== false) {
                            $ws->send($decoded, 'binary');
                            $lastActivity = time();
                        }
                    }
                }

                // Read output from WebSocket
                try {
                    $data = $ws->receive();
                    if ($data !== null && $data !== '') {
                        $lastActivity = time();

                        // Broadcast to client via Reverb
                        $encoded = base64_encode($data);
                        $this->broadcastOutput($sessionId, $encoded);

                        // Append to scrollback buffer
                        $buffer .= $data;
                        if (strlen($buffer) > self::BUFFER_MAX) {
                            $buffer = substr($buffer, -self::BUFFER_MAX);
                        }
                        Cache::put(self::CACHE_PREFIX.$sessionId.':buffer', base64_encode($buffer), now()->addMinutes(20));
                    }
                } catch (TimeoutException) {
                    // Expected — no data available within timeout
                    usleep(20000); // 20ms
                } catch (\WebSocket\ConnectionException $e) {
                    Log::warning("[Terminal] WebSocket connection lost for {$sessionId}: {$e->getMessage()}");
                    $this->broadcastOutput($sessionId, base64_encode("\r\n\033[31m[Connection lost]\033[0m\r\n"));
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::error("[Terminal] Error in session {$sessionId}: {$e->getMessage()}");
            $this->broadcastOutput($sessionId, base64_encode("\r\n\033[31m[Error: {$e->getMessage()}]\033[0m\r\n"));
        } finally {
            if (isset($ws) && $ws->isConnected()) {
                try {
                    $ws->close();
                } catch (\Throwable) {
                    // ignore
                }
            }

            // Cleanup cache
            Cache::forget($cacheKey);
            Cache::forget(self::CACHE_PREFIX.$sessionId.':input');
            Cache::forget(self::CACHE_PREFIX.$sessionId.':buffer');

            Log::info("[Terminal] Session {$sessionId} ended.");
        }

        return self::SUCCESS;
    }

    private function broadcastOutput(string $sessionId, string $base64Output): void
    {
        event(new TerminalOutput($sessionId, $base64Output));
    }
}
