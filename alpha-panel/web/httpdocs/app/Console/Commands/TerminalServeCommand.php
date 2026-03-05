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

        // SSH session state
        $sessionType = 'portainer';
        $sshProcess = null;
        $sshPipes = [];
        $sshWsBuffer = '';

        // Register close/error at the top level so they always fire
        $conn->on('close', function () use (&$portainerConn, &$sessionId, &$sessionType, &$sshProcess, &$sshPipes, $loop) {
            Log::info("[TerminalServe] Browser disconnected (session={$sessionId})");
            if ($sessionType === 'ssh') {
                $this->cleanupSsh($sshProcess, $sshPipes, $loop);
            } else {
                $portainerConn?->close();
            }
        });

        $conn->on('error', function (\Throwable $e) use (&$portainerConn, &$sessionId, &$sessionType, &$sshProcess, &$sshPipes, $loop) {
            Log::warning("[TerminalServe] Browser error (session={$sessionId}): ".$e->getMessage());
            if ($sessionType === 'ssh') {
                $this->cleanupSsh($sshProcess, $sshPipes, $loop);
            } else {
                $portainerConn?->close();
            }
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
            &$sessionType,
            &$sshProcess,
            &$sshPipes,
            &$sshWsBuffer,
        ) {
            if ($handshakeDone) {
                if ($sessionType === 'ssh') {
                    // Decode WebSocket frames from browser, write payload to SSH stdin
                    $sshWsBuffer .= $chunk;
                    while (($frame = $this->decodeWsFrame($sshWsBuffer)) !== null) {
                        if ($frame['opcode'] === 0x08) {
                            $this->cleanupSsh($sshProcess, $sshPipes, $loop);
                            $conn->close();

                            return;
                        }
                        if (($frame['opcode'] === 0x01 || $frame['opcode'] === 0x02)
                            && isset($sshPipes[0]) && is_resource($sshPipes[0])) {
                            @fwrite($sshPipes[0], $frame['payload']);
                        }
                    }

                    return;
                }

                // Both handshakes complete — raw proxy (Portainer)
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
            $sessionType = $sessionData['type'] ?? 'portainer';
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

            // ── SSH session: spawn SSH process with PTY ──
            if ($sessionType === 'ssh') {
                Log::info("[TerminalServe] Session {$sessionId} ({$containerName}) — starting SSH bridge");
                $this->startSshBridge(
                    $conn, $loop, $sessionData, $wsKey, $sessionId,
                    $sshProcess, $sshPipes,
                );

                return;
            }

            // ── Portainer session: connect to Portainer WebSocket ──
            $portainerWsUrl = $sessionData['ws_url'];
            $portainerApiKey = $sessionData['api_key'];

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

    /**
     * Bridge the browser WebSocket to a host SSH process via PTY.
     *
     * @param  array<string, mixed>  $sessionData
     * @param  resource|null  $sshProcess
     * @param  array<int, resource>  $sshPipes
     */
    private function startSshBridge(
        ConnectionInterface $conn,
        mixed $loop,
        array $sessionData,
        string $wsKey,
        string $sessionId,
        mixed &$sshProcess,
        array &$sshPipes,
    ): void {
        // Complete browser WebSocket handshake immediately
        $accept = base64_encode(sha1($wsKey.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $conn->write(
            "HTTP/1.1 101 Switching Protocols\r\n".
            "Upgrade: websocket\r\n".
            "Connection: Upgrade\r\n".
            "Sec-WebSocket-Accept: {$accept}\r\n\r\n"
        );

        $sshHost = $sessionData['ssh_host'];
        $sshPort = (int) $sessionData['ssh_port'];
        $sshUser = $sessionData['ssh_user'];
        $sshKeyPath = $sessionData['ssh_key_path'];

        $cmd = sprintf(
            'ssh -tt -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -p %d -i %s %s@%s',
            $sshPort,
            escapeshellarg($sshKeyPath),
            escapeshellarg($sshUser),
            escapeshellarg($sshHost),
        );

        $env = getenv();
        $env['TERM'] = 'xterm-256color';
        $env['HOME'] = '/root';

        $process = @proc_open($cmd, [['pty'], ['pty'], ['pty']], $pipes, null, $env);

        if (! is_resource($process)) {
            Log::error("[TerminalServe] Failed to spawn SSH process for session {$sessionId}");
            $errMsg = "\033[31m[Failed to start SSH connection]\033[0m\r\n";
            $conn->write($this->encodeWsFrame($errMsg, 0x01));
            $conn->close();

            return;
        }

        $sshProcess = $process;
        $sshPipes = $pipes;

        stream_set_blocking($pipes[1], false);

        // Read SSH PTY output → encode as WebSocket frame → send to browser
        $loop->addReadStream($pipes[1], function ($stream) use ($conn, &$sshProcess, &$sshPipes, $loop, $sessionId) {
            $data = @fread($stream, 65535);
            if ($data === false || $data === '') {
                if (! is_resource($stream) || @feof($stream)) {
                    Log::info("[TerminalServe] SSH process ended (session={$sessionId})");
                    $msg = "\r\n\033[33m[SSH connection closed]\033[0m\r\n";
                    $conn->write($this->encodeWsFrame($msg, 0x01));
                    $this->cleanupSsh($sshProcess, $sshPipes, $loop);
                    $conn->close();
                }

                return;
            }

            $conn->write($this->encodeWsFrame($data));
        });

        Log::info("[TerminalServe] SSH bridge active for session {$sessionId}");
    }

    /**
     * Clean up SSH process and pipes.
     *
     * @param  resource|null  $process
     * @param  array<int, resource>  $pipes
     */
    private function cleanupSsh(mixed &$process, array &$pipes, mixed $loop): void
    {
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            $loop->removeReadStream($pipes[1]);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        if (is_resource($process)) {
            @proc_terminate($process, 9);
            @proc_close($process);
        }

        $process = null;
        $pipes = [];
    }

    /**
     * Encode data into a WebSocket frame (server → client, unmasked).
     */
    private function encodeWsFrame(string $payload, int $opcode = 0x02): string
    {
        $len = strlen($payload);
        $frame = chr(0x80 | $opcode); // FIN + opcode

        if ($len < 126) {
            $frame .= chr($len);
        } elseif ($len < 65536) {
            $frame .= chr(126).pack('n', $len);
        } else {
            $frame .= chr(127).pack('J', $len);
        }

        return $frame.$payload;
    }

    /**
     * Decode one WebSocket frame from the buffer (client → server, masked).
     *
     * Returns null if the buffer doesn't contain a complete frame yet.
     *
     * @return array{opcode: int, payload: string}|null
     */
    private function decodeWsFrame(string &$buffer): ?array
    {
        $len = strlen($buffer);
        if ($len < 2) {
            return null;
        }

        $byte1 = ord($buffer[0]);
        $byte2 = ord($buffer[1]);

        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 >> 7) & 1;
        $payloadLen = $byte2 & 0x7F;
        $offset = 2;

        if ($payloadLen === 126) {
            if ($len < 4) {
                return null;
            }
            $payloadLen = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if ($len < 10) {
                return null;
            }
            $payloadLen = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        if ($masked) {
            if ($len < $offset + 4) {
                return null;
            }
            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if ($len < $offset + $payloadLen) {
            return null;
        }

        $payload = substr($buffer, $offset, $payloadLen);

        if ($masked) {
            for ($i = 0; $i < $payloadLen; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }

        $buffer = substr($buffer, $offset + $payloadLen);

        return ['opcode' => $opcode, 'payload' => $payload];
    }
}
