<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\Server as SocketServer;

class TerminalServeCommand extends Command
{
    protected $signature = 'terminal:serve {--port=2999 : Port to listen on}';

    protected $description = 'Start the terminal WebSocket proxy server (run persistently via supervisor)';

    private const CACHE_PREFIX = 'terminal:';

    public function handle(): int
    {
        $port = (int) $this->option('port');
        $loop = Loop::get();

        $server = new SocketServer("0.0.0.0:{$port}", $loop);

        $this->info("Terminal WebSocket proxy listening on 0.0.0.0:{$port}");
        Log::info("[TerminalServe] Listening on port {$port}");

        $server->on('connection', function (ConnectionInterface $conn) use ($loop) {
            $this->handleBrowserConnection($conn, $loop);
        });

        $server->on('error', function (\Throwable $e) {
            Log::error('[TerminalServe] Server error: '.$e->getMessage());
        });

        $loop->run();

        return self::SUCCESS;
    }

    private function handleBrowserConnection(ConnectionInterface $conn, mixed $loop): void
    {
        $httpBuffer = '';
        $handshakeDone = false;
        $proxyActive = false;
        $portainerConn = null;
        $pendingBrowserData = '';
        $sessionId = 'unknown';

        // Register close/error at the top level so they always fire
        $conn->on('close', function () use (&$portainerConn, &$sessionId) {
            Log::info("[TerminalServe] Browser disconnected (session={$sessionId})");
            $portainerConn?->close();
        });

        $conn->on('error', function (\Throwable $e) use (&$portainerConn, &$sessionId) {
            Log::warning("[TerminalServe] Browser error (session={$sessionId}): ".$e->getMessage());
            $portainerConn?->close();
        });

        $conn->on('data', function (string $chunk) use (
            $conn,
            $loop,
            &$httpBuffer,
            &$handshakeDone,
            &$proxyActive,
            &$portainerConn,
            &$pendingBrowserData,
            &$sessionId,
        ) {
            if ($handshakeDone) {
                // Both handshakes complete — raw proxy
                if ($proxyActive && $portainerConn !== null) {
                    $portainerConn->write($chunk);
                } else {
                    // Portainer TCP open but its WS handshake pending; buffer
                    $pendingBrowserData .= $chunk;
                }

                return;
            }

            $httpBuffer .= $chunk;

            // Wait for full HTTP headers
            $headerEnd = strpos($httpBuffer, "\r\n\r\n");
            if ($headerEnd === false) {
                return;
            }

            $handshakeDone = true;

            $rawHeaders = substr($httpBuffer, 0, $headerEnd);
            $leftover = substr($httpBuffer, $headerEnd + 4);
            $lines = explode("\r\n", $rawHeaders);

            // Parse request line
            if (! preg_match('#^GET (.+) HTTP/1\.[01]#i', $lines[0] ?? '', $m)) {
                $conn->write("HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
                $conn->close();

                return;
            }

            // Extract token from query string
            parse_str(parse_url($m[1], PHP_URL_QUERY) ?? '', $query);
            $token = $query['token'] ?? '';

            // Validate token
            $cacheKey = self::CACHE_PREFIX.'ws:'.$token;
            $sessionData = Cache::get($cacheKey);

            if (! $sessionData) {
                $conn->write("HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n");
                $conn->close();
                Log::warning("[TerminalServe] Invalid/expired WS token: '{$token}'");

                return;
            }

            // Single-use token
            Cache::forget($cacheKey);

            $sessionId = $sessionData['session_id'];
            $portainerWsUrl = $sessionData['ws_url'];
            $portainerApiKey = $sessionData['api_key'];
            $containerName = $sessionData['container_name'];

            // Extract browser's Sec-WebSocket-Key
            $wsKey = '';
            foreach (array_slice($lines, 1) as $line) {
                if (stripos($line, 'Sec-WebSocket-Key:') === 0) {
                    $wsKey = trim(substr($line, strlen('Sec-WebSocket-Key:')));
                    break;
                }
            }

            if ($wsKey === '') {
                $conn->write("HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
                $conn->close();

                return;
            }

            Log::info("[TerminalServe] Session {$sessionId} ({$containerName}) — connecting to Portainer");

            // Parse Portainer WS URL for TCP connection
            $parsed = parse_url($portainerWsUrl);
            $wsScheme = $parsed['scheme'] ?? 'ws';     // ws | wss
            $pHost = $parsed['host'] ?? 'portainer';
            $pPort = $parsed['port'] ?? ($wsScheme === 'wss' ? 443 : 9000);
            $pPath = ($parsed['path'] ?? '/').
                     (isset($parsed['query']) ? '?'.$parsed['query'] : '');
            $tcpScheme = $wsScheme === 'wss' ? 'tls' : 'tcp';

            $connector = new Connector([
                'tls' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ], $loop);

            $connector->connect("{$tcpScheme}://{$pHost}:{$pPort}")->then(
                function (ConnectionInterface $pConn) use (
                    $conn,
                    &$portainerConn,
                    &$proxyActive,
                    &$pendingBrowserData,
                    $pHost,
                    $pPort,
                    $pPath,
                    $portainerApiKey,
                    $wsKey,
                    $leftover,
                    $sessionId,
                ) {
                    $portainerConn = $pConn;

                    // Send HTTP WebSocket upgrade to Portainer
                    $ourKey = base64_encode(random_bytes(16));
                    $pConn->write(
                        "GET {$pPath} HTTP/1.1\r\n".
                        "Host: {$pHost}:{$pPort}\r\n".
                        "Upgrade: websocket\r\n".
                        "Connection: Upgrade\r\n".
                        "Sec-WebSocket-Key: {$ourKey}\r\n".
                        "Sec-WebSocket-Version: 13\r\n".
                        "X-API-Key: {$portainerApiKey}\r\n\r\n"
                    );

                    // Read Portainer's 101 response
                    $pHeaderBuf = '';
                    $pHandshakeDone = false;

                    $pConn->on('data', function (string $data) use (
                        $conn,
                        $pConn,
                        &$pHeaderBuf,
                        &$pHandshakeDone,
                        &$proxyActive,
                        &$pendingBrowserData,
                        $wsKey,
                        $leftover,
                        $sessionId,
                    ) {
                        if ($pHandshakeDone) {
                            // Raw proxy: Portainer → browser
                            $conn->write($data);

                            return;
                        }

                        $pHeaderBuf .= $data;
                        $pos = strpos($pHeaderBuf, "\r\n\r\n");
                        if ($pos === false) {
                            return;
                        }

                        $responseHead = substr($pHeaderBuf, 0, $pos);
                        $pLeftover = substr($pHeaderBuf, $pos + 4);

                        if (! str_contains($responseHead, '101')) {
                            Log::error("[TerminalServe] Portainer rejected upgrade for {$sessionId}: {$responseHead}");
                            $conn->write("HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\n\r\n");
                            $conn->close();
                            $pConn->close();

                            return;
                        }

                        $pHandshakeDone = true;
                        $proxyActive = true;

                        // Complete browser handshake
                        $accept = base64_encode(
                            sha1($wsKey.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)
                        );
                        $conn->write(
                            "HTTP/1.1 101 Switching Protocols\r\n".
                            "Upgrade: websocket\r\n".
                            "Connection: Upgrade\r\n".
                            "Sec-WebSocket-Accept: {$accept}\r\n\r\n"
                        );

                        Log::info("[TerminalServe] Session {$sessionId} proxy active");

                        // Flush any leftover bytes
                        if ($pLeftover !== '') {
                            $conn->write($pLeftover);
                        }
                        if ($leftover !== '') {
                            $pConn->write($leftover);
                        }
                        if ($pendingBrowserData !== '') {
                            $pConn->write($pendingBrowserData);
                            $pendingBrowserData = '';
                        }
                    });

                    $pConn->on('close', function () use ($conn, $sessionId) {
                        Log::info("[TerminalServe] Portainer closed (session={$sessionId})");
                        $conn->close();
                    });

                    $pConn->on('error', function (\Throwable $e) use ($conn, $sessionId) {
                        Log::error("[TerminalServe] Portainer error (session={$sessionId}): ".$e->getMessage());
                        $conn->close();
                    });
                },
                function (\Throwable $e) use ($conn, $sessionId) {
                    Log::error("[TerminalServe] Failed to reach Portainer (session={$sessionId}): ".$e->getMessage());
                    $conn->write("HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\n\r\n");
                    $conn->close();
                }
            );
        });
    }
}
