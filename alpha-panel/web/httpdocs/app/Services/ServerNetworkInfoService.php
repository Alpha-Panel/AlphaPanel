<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class ServerNetworkInfoService
{
    /**
     * @return array{public: array<int, string>, private: array<int, string>}
     */
    public function getServerIpAddresses(): array
    {
        $configuredPublic = $this->normalizeIpList(config('panel.server_public_ips', []));
        $configuredPrivate = $this->normalizeIpList(config('panel.server_private_ips', []));

        if ($configuredPublic !== [] || $configuredPrivate !== []) {
            return [
                'public' => $configuredPublic,
                'private' => $configuredPrivate,
            ];
        }

        $fallback = ['public' => [], 'private' => []];
        $fallbackIp = trim((string) config('panel.server_ip', ''));

        if ($this->isValidIpv4($fallbackIp) && $fallbackIp !== '127.0.0.1') {
            if ($this->isPublicIp($fallbackIp)) {
                $fallback['public'][] = $fallbackIp;
            } else {
                $fallback['private'][] = $fallbackIp;
            }
        }

        if ($this->isRunningInsideContainer()) {
            return $fallback;
        }

        $detected = $this->detectIpsFromHost();

        return [
            'public' => array_values(array_unique(array_merge($fallback['public'], $detected['public']))),
            'private' => array_values(array_unique(array_merge($fallback['private'], $detected['private']))),
        ];
    }

    /**
     * @return array{public: array<int, string>, private: array<int, string>}
     */
    private function detectIpsFromHost(): array
    {
        try {
            $result = Process::timeout(5)->run(['sh', '-lc', 'ip -o -4 addr show scope global']);
        } catch (\Throwable) {
            return ['public' => [], 'private' => []];
        }

        if ($result->failed()) {
            return ['public' => [], 'private' => []];
        }

        $public = [];
        $private = [];
        $lines = preg_split('/\r?\n/', trim($result->output())) ?: [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (! preg_match('/^\d+:\s+([a-zA-Z0-9._-]+)\s+inet\s+(\d+\.\d+\.\d+\.\d+)\/\d+/', $line, $matches)) {
                continue;
            }

            $interface = $matches[1];
            $ip = $matches[2];

            if ($this->isIgnoredInterface($interface) || ! $this->isValidIpv4($ip)) {
                continue;
            }

            if ($this->isPublicIp($ip)) {
                $public[] = $ip;
            } else {
                $private[] = $ip;
            }
        }

        return [
            'public' => array_values(array_unique($public)),
            'private' => array_values(array_unique($private)),
        ];
    }

    private function isIgnoredInterface(string $interface): bool
    {
        return str_starts_with($interface, 'lo')
            || str_starts_with($interface, 'docker')
            || str_starts_with($interface, 'br-')
            || str_starts_with($interface, 'veth');
    }

    private function isRunningInsideContainer(): bool
    {
        if (is_file('/.dockerenv')) {
            return true;
        }

        $cgroup = @file_get_contents('/proc/1/cgroup');

        if (! is_string($cgroup) || $cgroup === '') {
            return false;
        }

        return str_contains($cgroup, 'docker')
            || str_contains($cgroup, 'kubepods')
            || str_contains($cgroup, 'containerd');
    }

    private function isValidIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeIpList(mixed $value): array
    {
        $list = [];

        if (is_array($value)) {
            $list = $value;
        } elseif (is_string($value) && $value !== '') {
            $list = explode(',', $value);
        }

        $ips = array_map(fn ($ip) => trim((string) $ip), $list);

        return array_values(array_unique(array_filter($ips, fn ($ip) => $this->isValidIpv4($ip))));
    }
}
