<?php

namespace App\Services\Terminal;

use App\Models\TerminalLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\SocketServer;

/**
 * ReactPHP-based WebSocket proxy that bridges browser ↔ Portainer container exec
 * (or browser ↔ host SSH process). Designed to run persistently via supervisor.
 *
 * This server is decoupled from any Artisan command — invoke {@see self::run()}
 * from any entry point and the lifecycle is fully managed here.
 */
class TerminalProxyServer
{
    private const CACHE_PREFIX = 'terminal:';

    private ?\Closure $infoLogger = null;

    /**
     * Run the proxy server on the given TCP port. Blocks until the event loop stops.
     */
    public function run(int $port): int
    {
        $loop = Loop::get();
        $server = new SocketServer("0.0.0.0:{$port}", [], $loop);

        $this->logInfo("Terminal WebSocket proxy listening on 0.0.0.0:{$port}");
        Log::info("[TerminalServe] Listening on port {$port}");

        $server->on('connection', function (ConnectionInterface $conn) use ($loop): void {
            $this->handleBrowserConnection($conn, $loop);
        });

        $server->on('error', function (\Throwable $e): void {
            Log::error('[TerminalServe] Server error: '.$e->getMessage());
        });

        $shutdown = function (int $signal) use ($loop, $server): void {
            Log::info("[TerminalServe] Received signal {$signal}, shutting down.");
            $server->close();
            $loop->stop();
        };

        if (defined('SIGTERM')) {
            $loop->addSignal(SIGTERM, $shutdown);
        }
        if (defined('SIGINT')) {
            $loop->addSignal(SIGINT, $shutdown);
        }

        $loop->run();

        return 0;
    }

    /**
     * Register a callback that the server can use to emit human-facing log lines
     * (e.g. the Artisan command's $this->info()). Falls back to the Log facade.
     */
    public function setInfoLogger(?callable $logger): void
    {
        $this->infoLogger = $logger === null ? null : \Closure::fromCallable($logger);
    }

    private function logInfo(string $message): void
    {
        if ($this->infoLogger !== null) {
            ($this->infoLogger)($message);

            return;
        }

        Log::info($message);
    }

    private function handleBrowserConnection(ConnectionInterface $conn, LoopInterface $loop): void
    {
        $httpBuffer = '';
        $handshakeDone = false;
        $proxyActive = false;
        $portainerConn = null;
        $pendingBrowserData = '';
        $sessionId = 'unknown';

        // Portainer resize state
        $execId = '';
        $portainerResizeBaseUrl = '';
        $portainerApiKey = '';
        $portainerEndpointId = 1;

        // Carry buffer for incomplete WebSocket frames from browser (Portainer path)
        $portainerCarryBuffer = '';

        // SSH session state
        $sessionType = 'portainer';
        $sshProcess = null;
        $sshPipes = [];
        $sshWsBuffer = '';
        $sshEphemeralKeyPath = null;
        $sshOutputSeen = false;

        // Terminal command logging
        $commandBuffer = '';
        $outputBuffer = '';
        $lastLogId = null;
        $userId = null;
        $containerName = 'unknown';
        $clientIp = null;
        $clientPort = null;

        // Register close/error at the top level so they always fire
        $conn->on('close', function () use (&$portainerConn, &$sessionId, &$sessionType, &$sshProcess, &$sshPipes, &$sshEphemeralKeyPath, $loop, &$outputBuffer, &$lastLogId): void {
            $this->flushOutput($outputBuffer, $lastLogId);
            Log::info("[TerminalServe] Browser disconnected (session={$sessionId})");
            if ($sessionType === 'ssh') {
                $this->cleanupSsh($sshProcess, $sshPipes, $loop, $sshEphemeralKeyPath);
            } else {
                $portainerConn?->close();
            }
        });

        $conn->on('error', function (\Throwable $e) use (&$portainerConn, &$sessionId, &$sessionType, &$sshProcess, &$sshPipes, &$sshEphemeralKeyPath, $loop): void {
            Log::warning("[TerminalServe] Browser error (session={$sessionId}): ".$e->getMessage());
            if ($sessionType === 'ssh') {
                $this->cleanupSsh($sshProcess, $sshPipes, $loop, $sshEphemeralKeyPath);
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
            &$sshEphemeralKeyPath,
            &$sshOutputSeen,
            &$commandBuffer,
            &$outputBuffer,
            &$lastLogId,
            &$userId,
            &$containerName,
            &$clientIp,
            &$clientPort,
            &$execId,
            &$portainerResizeBaseUrl,
            &$portainerApiKey,
            &$portainerEndpointId,
            &$portainerCarryBuffer,
        ): void {
            if ($handshakeDone) {
                if ($sessionType === 'ssh') {
                    // Decode WebSocket frames from browser, write payload to SSH stdin
                    $sshWsBuffer .= $chunk;
                    while (($frame = $this->decodeWsFrame($sshWsBuffer)) !== null) {
                        if ($frame['opcode'] === 0x08) {
                            $this->cleanupSsh($sshProcess, $sshPipes, $loop, $sshEphemeralKeyPath);
                            $conn->close();

                            return;
                        }
                        // Respond to PING with PONG so browser doesn't time out
                        if ($frame['opcode'] === 0x09) {
                            $conn->write($this->encodeWsFrame($frame['payload'], 0x0A));

                            continue;
                        }
                        if (($frame['opcode'] === 0x01 || $frame['opcode'] === 0x02)
                            && isset($sshPipes[0]) && is_resource($sshPipes[0])) {
                            // Intercept resize messages (text frames with JSON)
                            if ($frame['opcode'] === 0x01) {
                                $resize = $this->parseResizeMessage($frame['payload']);
                                if ($resize !== null) {
                                    @fwrite($sshPipes[0], sprintf("stty rows %d cols %d\n", $resize[1], $resize[0]));

                                    continue;
                                }
                            }

                            @fwrite($sshPipes[0], $frame['payload']);
                            $this->bufferCommand($commandBuffer, $frame['payload'], $userId, $sessionId, $sessionType, $containerName, $clientIp, $clientPort, $outputBuffer, $lastLogId);
                        }
                    }

                    return;
                }

                // Both handshakes complete — raw proxy (Portainer)
                if ($proxyActive && $portainerConn !== null) {
                    try {
                        $filtered = $this->filterResizeFrames($chunk, $execId, $portainerResizeBaseUrl, $portainerApiKey, $portainerEndpointId, $portainerCarryBuffer);
                        if ($filtered === '') {
                            return;
                        }
                        $this->captureFromWsFrames($commandBuffer, $filtered, $userId, $sessionId, $sessionType, $containerName, $clientIp, $clientPort, $outputBuffer, $lastLogId);
                        $portainerConn->write($filtered);
                    } catch (\Throwable $e) {
                        Log::error("[TerminalServe] Browser→Portainer error (session={$sessionId}): {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}");
                    }
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
            $userId = $sessionData['user_id'] ?? null;
            $clientIp = $sessionData['ip_address'] ?? null;
            $clientPort = $sessionData['port'] ?? null;

            // Store Portainer credentials for exec resize calls
            if ($sessionType !== 'ssh') {
                $execId = $sessionData['exec_id'] ?? '';
                $portainerResizeBaseUrl = rtrim((string) config('panel.portainer_url'), '/');
                $portainerApiKey = $sessionData['api_key'] ?? '';
                $portainerEndpointId = (int) config('panel.portainer_endpoint_id', 1);
            }

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
                    $sshProcess, $sshPipes, $sshEphemeralKeyPath, $sshOutputSeen, $outputBuffer, $lastLogId,
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
                    &$outputBuffer,
                    &$lastLogId,
                ): void {
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
                        &$outputBuffer,
                        &$lastLogId,
                    ): void {
                        if ($pHandshakeDone) {
                            // Raw proxy: Portainer → browser
                            $this->appendOutput($outputBuffer, $lastLogId, $data);
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

                    $pConn->on('close', function () use ($conn, $sessionId): void {
                        Log::info("[TerminalServe] Portainer closed (session={$sessionId})");
                        // Send WS close frame so browser gets code 1000 instead of 1006
                        try {
                            $conn->write($this->encodeWsFrame('', 0x08));
                        } catch (\Throwable) {
                        }
                        $conn->close();
                    });

                    $pConn->on('error', function (\Throwable $e) use ($conn, $sessionId): void {
                        Log::error("[TerminalServe] Portainer error (session={$sessionId}): ".$e->getMessage());
                        $conn->close();
                    });
                },
                function (\Throwable $e) use ($conn, $sessionId): void {
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
        LoopInterface $loop,
        array $sessionData,
        string $wsKey,
        string $sessionId,
        mixed &$sshProcess,
        array &$sshPipes,
        ?string &$sshEphemeralKeyPath,
        bool &$sshOutputSeen,
        string &$outputBuffer,
        ?int &$lastLogId,
    ): void {
        // Complete browser WebSocket handshake immediately
        $accept = base64_encode(sha1($wsKey.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $conn->write(
            "HTTP/1.1 101 Switching Protocols\r\n".
            "Upgrade: websocket\r\n".
            "Connection: Upgrade\r\n".
            "Sec-WebSocket-Accept: {$accept}\r\n\r\n"
        );

        $sshHost = is_string($sessionData['ssh_host'] ?? null)
            ? trim((string) $sessionData['ssh_host'])
            : '';
        $sshPort = (int) $sessionData['ssh_port'];
        $sshUser = $sessionData['ssh_user'];
        $sshKeyPath = is_string($sessionData['ssh_key_path'] ?? null)
            ? trim((string) $sessionData['ssh_key_path'])
            : '';

        $sshHostCandidates = $this->buildSshHostCandidates($sshHost);
        $resolvedSshHost = $this->resolveReachableSshHost($sshHostCandidates, $sshPort);

        if ($resolvedSshHost === null) {
            Log::error(sprintf(
                '[TerminalServe] No reachable SSH host on port %d (session=%s, candidates=%s)',
                $sshPort,
                $sessionId,
                implode(', ', $sshHostCandidates),
            ));
            $errMsg = sprintf(
                "\033[31m[SSH host unreachable on port %d: %s]\033[0m\r\n",
                $sshPort,
                implode(', ', $sshHostCandidates),
            );
            $this->sendWsMessageAndClose($conn, $loop, $errMsg);

            return;
        }

        $resolvedSshKeyPath = $this->resolveSshPrivateKeyPath($sshKeyPath);
        if ($resolvedSshKeyPath === null) {
            Log::error("[TerminalServe] SSH key not found/readable for session {$sessionId}");
            $errMsg = "\033[31m[SSH private key not found/readable]\033[0m\r\n";
            $this->sendWsMessageAndClose($conn, $loop, $errMsg);

            return;
        }

        $sshEphemeralKeyPath = $resolvedSshKeyPath;
        $sshTarget = sprintf('%s@%s', $sshUser, $resolvedSshHost);

        $cmd = sprintf(
            'ssh -tt -o BatchMode=yes -o IdentitiesOnly=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -p %d -i %s %s 2>&1',
            $sshPort,
            escapeshellarg($resolvedSshKeyPath),
            escapeshellarg($sshTarget),
        );

        $env = getenv();
        $env['TERM'] = 'xterm-256color';
        $env['HOME'] = '/root';

        $process = @proc_open($cmd, [['pty'], ['pty'], ['pty']], $pipes, null, $env);

        if (! is_resource($process)) {
            Log::error("[TerminalServe] Failed to spawn SSH process for session {$sessionId}");
            if ($sshEphemeralKeyPath !== null && $sshEphemeralKeyPath !== '') {
                @unlink($sshEphemeralKeyPath);
                $sshEphemeralKeyPath = null;
            }
            $errMsg = "\033[31m[Failed to start SSH connection]\033[0m\r\n";
            $this->sendWsMessageAndClose($conn, $loop, $errMsg);

            return;
        }

        $sshProcess = $process;
        $sshPipes = $pipes;

        stream_set_blocking($pipes[1], false);
        $sshOutputSeen = false;

        // Read SSH PTY output → encode as WebSocket frame → send to browser
        $loop->addReadStream($pipes[1], function ($stream) use ($conn, &$sshProcess, &$sshPipes, &$sshEphemeralKeyPath, &$sshOutputSeen, $loop, $sessionId, &$outputBuffer, &$lastLogId): void {
            $data = @fread($stream, 65535);
            if ($data === false || $data === '') {
                if (! is_resource($stream) || @feof($stream)) {
                    $status = is_resource($sshProcess) ? @proc_get_status($sshProcess) : false;
                    $exitCode = is_array($status) && isset($status['exitcode']) ? (int) $status['exitcode'] : null;

                    Log::info("[TerminalServe] SSH process ended (session={$sessionId}, exit_code=".($exitCode ?? -1).')');

                    if (! $sshOutputSeen) {
                        $msg = "\r\n\033[31m[SSH process closed before interactive session started]\033[0m\r\n";
                        $msg .= "\033[33m[Check host SSH daemon, firewall and authorized_keys]\033[0m\r\n";
                    } else {
                        $msg = "\r\n\033[33m[SSH connection closed]\033[0m\r\n";
                    }

                    $this->sendWsMessageAndClose($conn, $loop, $msg);
                    $this->cleanupSsh($sshProcess, $sshPipes, $loop, $sshEphemeralKeyPath);
                }

                return;
            }

            $sshOutputSeen = true;
            $this->appendOutput($outputBuffer, $lastLogId, $data);
            $conn->write($this->encodeWsFrame($data));
        });

        Log::info("[TerminalServe] SSH bridge active for session {$sessionId}");
    }

    /**
     * @return list<string>
     */
    private function buildSshHostCandidates(string $configuredHost): array
    {
        $candidates = [];

        foreach (explode(',', $configuredHost) as $host) {
            $trimmedHost = trim($host);
            if ($trimmedHost === '') {
                continue;
            }

            if (! in_array($trimmedHost, $candidates, true)) {
                $candidates[] = $trimmedHost;
            }
        }

        if (! in_array('host.docker.internal', $candidates, true)) {
            $candidates[] = 'host.docker.internal';
        }

        $defaultGateway = $this->resolveDefaultGatewayIp();
        if ($defaultGateway !== null && ! in_array($defaultGateway, $candidates, true)) {
            $candidates[] = $defaultGateway;
        }

        return $candidates;
    }

    /**
     * @param  list<string>  $hosts
     */
    private function resolveReachableSshHost(array $hosts, int $port): ?string
    {
        foreach ($hosts as $host) {
            if ($this->canConnectToHostPort($host, $port)) {
                return $host;
            }
        }

        return null;
    }

    private function canConnectToHostPort(string $host, int $port, float $timeoutSeconds = 1.5): bool
    {
        if ($port <= 0 || $port > 65535 || trim($host) === '') {
            return false;
        }

        $errno = 0;
        $errstr = '';
        $stream = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);

        if (! is_resource($stream)) {
            return false;
        }

        @fclose($stream);

        return true;
    }

    private function resolveDefaultGatewayIp(): ?string
    {
        $routes = @file('/proc/net/route', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($routes) || count($routes) < 2) {
            return null;
        }

        foreach (array_slice($routes, 1) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (! is_array($parts) || count($parts) < 3) {
                continue;
            }

            $destination = strtoupper($parts[1]);
            $gatewayHex = strtoupper($parts[2]);

            if ($destination !== '00000000' || strlen($gatewayHex) !== 8 || ! ctype_xdigit($gatewayHex)) {
                continue;
            }

            $ipParts = [];
            for ($index = 0; $index < 4; $index++) {
                $byteHex = substr($gatewayHex, $index * 2, 2);
                $ipParts[] = (string) hexdec($byteHex);
            }

            $ip = implode('.', array_reverse($ipParts));

            return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
        }

        return null;
    }

    private function sendWsMessageAndClose(ConnectionInterface $conn, LoopInterface $loop, string $message): void
    {
        $conn->write($this->encodeWsFrame($message, 0x01));

        if (method_exists($loop, 'addTimer')) {
            $loop->addTimer(0.15, function () use ($conn): void {
                $conn->close();
            });

            return;
        }

        $conn->close();
    }

    /**
     * Clean up SSH process and pipes.
     *
     * @param  resource|null  $process
     * @param  array<int, resource>  $pipes
     */
    private function cleanupSsh(mixed &$process, array &$pipes, LoopInterface $loop, ?string &$ephemeralKeyPath = null): void
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

        if ($ephemeralKeyPath !== null && $ephemeralKeyPath !== '') {
            @unlink($ephemeralKeyPath);
        }

        $process = null;
        $pipes = [];
        $ephemeralKeyPath = null;
    }

    /**
     * Resolve SSH private key path and copy it to a strict-permission temp file.
     */
    private function resolveSshPrivateKeyPath(string $configuredPath): ?string
    {
        $candidates = [];

        if ($configuredPath !== '') {
            $candidates[] = $configuredPath;
        }

        $fallbackPath = base_path('../ssh-keys/alphapanel_ed25519');
        if (! in_array($fallbackPath, $candidates, true)) {
            $candidates[] = $fallbackPath;
        }

        foreach ($candidates as $candidatePath) {
            $path = trim((string) $candidatePath);

            if ($path === '' || ! is_file($path) || ! is_readable($path)) {
                continue;
            }

            $keyContents = @file_get_contents($path);
            if ($keyContents === false || trim($keyContents) === '') {
                continue;
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'ap-ssh-key-');
            if ($tempPath === false) {
                continue;
            }

            if (@file_put_contents($tempPath, $keyContents) === false) {
                @unlink($tempPath);

                continue;
            }

            @chmod($tempPath, 0600);

            return $tempPath;
        }

        return null;
    }

    /**
     * Buffer keystrokes and log when Enter (\r) is pressed.
     */
    private function bufferCommand(
        string &$buffer,
        string $payload,
        ?int $userId,
        string $sessionId,
        string $sessionType,
        string $containerName,
        ?string $clientIp,
        mixed $clientPort,
        string &$outputBuffer,
        ?int &$lastLogId,
    ): void {
        for ($i = 0; $i < strlen($payload); $i++) {
            $char = $payload[$i];

            if ($char === "\r" || $char === "\n") {
                $command = trim($buffer);
                $buffer = '';

                if ($command !== '' && $userId !== null) {
                    // Flush output from previous command before logging new one
                    $this->flushOutput($outputBuffer, $lastLogId);

                    $lastLogId = $this->logTerminalCommand(
                        $userId, $sessionId, $sessionType, $containerName, $command, $clientIp, $clientPort,
                    );
                    $outputBuffer = '';
                }

                continue;
            }

            // Handle backspace
            if ($char === "\x7f" || $char === "\x08") {
                $buffer = mb_substr($buffer, 0, -1);

                continue;
            }

            // Ignore control characters (except printable ones)
            if (ord($char) < 32 && $char !== "\t") {
                continue;
            }

            $buffer .= $char;
        }
    }

    /**
     * Try to decode WebSocket frames from raw proxy data and capture commands.
     */
    private function captureFromWsFrames(
        string &$commandBuffer,
        string $chunk,
        ?int $userId,
        string $sessionId,
        string $sessionType,
        string $containerName,
        ?string $clientIp,
        mixed $clientPort,
        string &$outputBuffer,
        ?int &$lastLogId,
    ): void {
        // Make a copy to decode without mutating the forwarded data
        $tempBuffer = $chunk;

        while (($frame = $this->decodeWsFrame($tempBuffer)) !== null) {
            if ($frame['opcode'] === 0x01 || $frame['opcode'] === 0x02) {
                $this->bufferCommand(
                    $commandBuffer, $frame['payload'], $userId, $sessionId,
                    $sessionType, $containerName, $clientIp, $clientPort,
                    $outputBuffer, $lastLogId,
                );
            }
        }
    }

    /**
     * Append server output to buffer (max 50KB to avoid memory issues).
     */
    private function appendOutput(string &$outputBuffer, ?int $lastLogId, string $data): void
    {
        if ($lastLogId === null) {
            return;
        }

        // Strip ANSI escape codes for cleaner storage
        $clean = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $data) ?? $data;

        if (strlen($outputBuffer) < 51200) {
            $outputBuffer .= $clean;
        }
    }

    /**
     * Save buffered output to the most recent terminal log entry.
     */
    private function flushOutput(string &$outputBuffer, ?int &$lastLogId): void
    {
        if ($lastLogId === null || $outputBuffer === '') {
            return;
        }

        try {
            TerminalLog::query()
                ->whereKey($lastLogId)
                ->update(['output' => mb_substr(trim($outputBuffer), 0, 50000)]);
        } catch (\Throwable $e) {
            Log::warning("[TerminalServe] Failed to save output: {$e->getMessage()}");
        }

        $outputBuffer = '';
    }

    private function logTerminalCommand(
        int $userId,
        string $sessionId,
        string $sessionType,
        string $containerName,
        string $command,
        ?string $clientIp,
        mixed $clientPort,
    ): ?int {
        try {
            $log = TerminalLog::create([
                'user_id' => $userId,
                'session_id' => $sessionId,
                'session_type' => $sessionType,
                'container_name' => $containerName,
                'command' => mb_substr($command, 0, 5000),
                'ip_address' => $clientIp,
                'port' => is_numeric($clientPort) ? (int) $clientPort : null,
            ]);

            return $log->id;
        } catch (\Throwable $e) {
            Log::warning("[TerminalServe] Failed to log command: {$e->getMessage()}");

            return null;
        }
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

    /**
     * Parse a resize message from a WebSocket text frame payload.
     *
     * @return array{0: int, 1: int}|null [cols, rows] or null if not a resize message
     */
    private function parseResizeMessage(string $payload): ?array
    {
        $json = @json_decode($payload, true);

        if (! is_array($json) || ! isset($json['resize']) || ! is_array($json['resize']) || count($json['resize']) < 2) {
            return null;
        }

        $cols = (int) $json['resize'][0];
        $rows = (int) $json['resize'][1];

        if ($cols <= 0 || $rows <= 0) {
            return null;
        }

        return [$cols, $rows];
    }

    /**
     * Scan a raw chunk for WebSocket resize frames, handle them, and return the
     * chunk with resize frames stripped out so only data frames are forwarded.
     * Incomplete frames are held in $carryBuffer until the next chunk arrives.
     */
    private function filterResizeFrames(
        string $chunk,
        string $execId,
        string $portainerBaseUrl,
        string $portainerApiKey,
        int $portainerEndpointId,
        string &$carryBuffer = '',
    ): string {
        if ($execId === '' || $portainerBaseUrl === '') {
            $out = $carryBuffer.$chunk;
            $carryBuffer = '';

            return $out;
        }

        $result = '';
        $buffer = $carryBuffer.$chunk;
        $carryBuffer = '';

        while ($buffer !== '') {
            if (strlen($buffer) < 2) {
                // Too short to be a valid frame header — buffer for next chunk
                $carryBuffer = $buffer;
                break;
            }

            // Remember position before decoding
            $beforeLen = strlen($buffer);
            $tempBuffer = $buffer;
            $frame = $this->decodeWsFrame($tempBuffer);

            if ($frame === null) {
                // Incomplete frame — hold in carry buffer until next TCP chunk
                $carryBuffer = $buffer;
                break;
            }

            $consumedLen = $beforeLen - strlen($tempBuffer);
            $rawFrame = substr($buffer, 0, $consumedLen);
            $buffer = $tempBuffer;

            Log::debug(sprintf('[TerminalServe] WS frame browser→portainer: opcode=0x%02x payload=%d bytes', $frame['opcode'], strlen($frame['payload'])));

            // Swallow empty binary frames (browser keepalive heartbeats) — don't send to Portainer
            if ($frame['opcode'] === 0x02 && $frame['payload'] === '') {
                continue;
            }

            // Check for resize message (text frame with JSON)
            if ($frame['opcode'] === 0x01) {
                $resize = $this->parseResizeMessage($frame['payload']);
                if ($resize !== null) {
                    $this->handlePortainerResize($execId, $resize[0], $resize[1], $portainerBaseUrl, $portainerApiKey, $portainerEndpointId);

                    continue; // Strip this frame from forwarded data
                }
            }

            $result .= $rawFrame;
        }

        return $result;
    }

    /**
     * Call Docker exec resize API via Portainer to update PTY dimensions.
     */
    private function handlePortainerResize(
        string $execId,
        int $cols,
        int $rows,
        string $portainerBaseUrl,
        string $portainerApiKey,
        int $endpointId,
    ): void {
        $url = "{$portainerBaseUrl}/api/endpoints/{$endpointId}/docker/exec/{$execId}/resize?"
            .http_build_query(['h' => $rows, 'w' => $cols]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "X-API-Key: {$portainerApiKey}\r\nContent-Length: 0\r\n",
                'timeout' => 3,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        @file_get_contents($url, false, $context);
    }
}
