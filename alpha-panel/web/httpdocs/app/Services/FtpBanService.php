<?php

namespace App\Services;

use App\Models\FtpBanWhitelist;
use App\Services\Portainer\ExecResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FtpBanService
{
    private const CACHE_KEY = 'ftp:active_bans';

    private const CACHE_TTL = 30;

    public function __construct(
        private readonly PortainerService $portainer,
    ) {}

    /**
     * Get currently active FTP bans, enforcing the whitelist automatically.
     *
     * @return array<int, array{ip: string, since: string|null, rule: string|null}>
     */
    public function getActiveBans(): array
    {
        /** @var array<int, array{ip: string, since: string|null, rule: string|null}> $bans */
        $bans = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $result = $this->execInFtpContainer(['ftpdctl', 'ban', 'info']);

            if (! $result->isSuccessful()) {
                Log::warning('FtpBanService: ftpdctl ban info failed', [
                    'exitCode' => $result->exitCode,
                    'error' => $result->errorOutput,
                ]);

                return [];
            }

            return $this->parseBanInfo($result->output);
        });

        $this->enforceWhitelist($bans);

        return $bans;
    }

    /**
     * Ban a host IP via ftpdctl. Throws if the IP is whitelisted.
     *
     * @throws \RuntimeException
     */
    public function banHost(string $ip): ExecResult
    {
        if (FtpBanWhitelist::isWhitelisted($ip)) {
            throw new \RuntimeException("Cannot ban whitelisted IP: {$ip}");
        }

        $result = $this->execInFtpContainer(['ftpdctl', 'ban', 'host', $ip]);

        $this->clearCache();

        Log::info('FtpBanService: banned host', ['ip' => $ip, 'exitCode' => $result->exitCode]);

        app(\App\Services\WebhookService::class)->dispatch('ftp.banned', ['ip' => $ip]);

        return $result;
    }

    /**
     * Permit (unban) a host IP via ftpdctl.
     */
    public function permitHost(string $ip): ExecResult
    {
        $result = $this->execInFtpContainer(['ftpdctl', 'permit', 'host', $ip]);

        $this->clearCache();

        Log::info('FtpBanService: permitted host', ['ip' => $ip, 'exitCode' => $result->exitCode]);

        return $result;
    }

    /**
     * Read the FTP ban log file.
     *
     * @return array<int, array{timestamp: string|null, message: string}>
     */
    public function getBanLog(int $lines = 100): array
    {
        $result = $this->execInFtpContainer(['tail', '-n', (string) $lines, '/var/log/proftpd/ban.log']);

        if (! $result->isSuccessful()) {
            Log::warning('FtpBanService: failed to read ban log', [
                'exitCode' => $result->exitCode,
                'error' => $result->errorOutput,
            ]);

            return [];
        }

        return $this->parseBanLog($result->output);
    }

    /**
     * Compare active bans against the whitelist and auto-permit any matches.
     *
     * @param  array<int, array{ip: string, since: string|null, rule: string|null}>  &$bans
     */
    public function enforceWhitelist(array &$bans): int
    {
        if (empty($bans)) {
            return 0;
        }

        $whitelistedIps = FtpBanWhitelist::pluck('ip_address')->all();

        if (empty($whitelistedIps)) {
            return 0;
        }

        $permitted = 0;

        foreach ($bans as $index => $ban) {
            if (in_array($ban['ip'], $whitelistedIps, true)) {
                try {
                    $this->execInFtpContainer(['ftpdctl', 'permit', 'host', $ban['ip']]);
                    unset($bans[$index]);
                    $permitted++;

                    Log::info('FtpBanService: auto-permitted whitelisted IP', ['ip' => $ban['ip']]);
                } catch (\Throwable $e) {
                    Log::warning('FtpBanService: failed to auto-permit whitelisted IP', [
                        'ip' => $ban['ip'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($permitted > 0) {
            $bans = array_values($bans);
            $this->clearCache();
        }

        return $permitted;
    }

    /**
     * Get all whitelist entries with their creators.
     *
     * @return Collection<int, FtpBanWhitelist>
     */
    public function getWhitelist(): Collection
    {
        return FtpBanWhitelist::with('creator')->get();
    }

    /**
     * Add an IP to the whitelist and auto-permit if currently banned.
     */
    public function addToWhitelist(string $ip, ?string $note, int $userId): FtpBanWhitelist
    {
        $entry = FtpBanWhitelist::create([
            'ip_address' => $ip,
            'note' => $note,
            'created_by' => $userId,
        ]);

        // Auto-permit if the IP is currently banned
        try {
            $this->execInFtpContainer(['ftpdctl', 'permit', 'host', $ip]);
            $this->clearCache();
        } catch (\Throwable $e) {
            Log::warning('FtpBanService: failed to auto-permit newly whitelisted IP', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('FtpBanService: added to whitelist', ['ip' => $ip, 'userId' => $userId]);

        return $entry;
    }

    /**
     * Remove an IP from the whitelist.
     */
    public function removeFromWhitelist(string $ip): void
    {
        FtpBanWhitelist::where('ip_address', $ip)->delete();

        Log::info('FtpBanService: removed from whitelist', ['ip' => $ip]);
    }

    /**
     * Parse the output of `ftpdctl ban info` into structured data.
     *
     * @return array<int, array{ip: string, since: string|null, rule: string|null}>
     */
    private function parseBanInfo(string $output): array
    {
        $bans = [];

        try {
            $lines = array_map('trim', explode("\n", $output));
            $inHostSection = false;

            foreach ($lines as $line) {
                if ($line === '' || str_starts_with($line, 'ftpdctl:')) {
                    continue;
                }

                // Detect the "Banned Hosts:" section
                if (str_contains(strtolower($line), 'banned hosts')) {
                    $inHostSection = true;

                    continue;
                }

                // A new section header ends the host section
                if ($inHostSection && str_contains($line, ':') && ! preg_match('/^\d/', $line)) {
                    // Check if it looks like a section header (no IP at start)
                    if (preg_match('/^[A-Z]/', $line)) {
                        $inHostSection = false;

                        continue;
                    }
                }

                if (! $inHostSection) {
                    continue;
                }

                // Try to extract an IP address from the line
                if (preg_match('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}|[0-9a-fA-F:]+)/', $line, $matches)) {
                    $ip = $matches[1];

                    // Try to extract a timestamp (common formats)
                    $since = null;
                    if (preg_match('/since\s+(.+?)(?:\s*$|\s*\|)/i', $line, $timeMatch)) {
                        $since = trim($timeMatch[1]);
                    }

                    // Try to extract rule name
                    $rule = null;
                    if (preg_match('/rule\s*[:\']?\s*(.+?)(?:\s*$|\s*\|)/i', $line, $ruleMatch)) {
                        $rule = trim($ruleMatch[1]);
                    }

                    $bans[] = [
                        'ip' => $ip,
                        'since' => $since,
                        'rule' => $rule,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('FtpBanService: failed to parse ban info output', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $bans;
    }

    /**
     * Parse ban log lines into structured entries.
     *
     * @return array<int, array{timestamp: string|null, message: string}>
     */
    private function parseBanLog(string $output): array
    {
        $entries = [];
        $lines = array_filter(explode("\n", $output), fn (string $line): bool => trim($line) !== '');

        foreach ($lines as $line) {
            $timestamp = null;
            $message = trim($line);

            // Common syslog-style: "2026-03-20 12:34:56 ..." or "Mar 20 12:34:56 ..."
            if (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+(.*)$/', $message, $matches)) {
                $timestamp = $matches[1];
                $message = $matches[2];
            } elseif (preg_match('/^([A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})\s+(.*)$/', $message, $matches)) {
                $timestamp = $matches[1];
                $message = $matches[2];
            }

            $entries[] = [
                'timestamp' => $timestamp,
                'message' => $message,
            ];
        }

        return $entries;
    }

    /**
     * Run a command inside the FTP server container via Portainer.
     *
     * @param  array<int, string>  $command
     */
    private function execInFtpContainer(array $command): ExecResult
    {
        return $this->portainer->execInContainer(
            $this->containerName(),
            $command,
        );
    }

    private function containerName(): string
    {
        return (string) config('panel.ftp_container', 'ftp-server');
    }

    private function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
