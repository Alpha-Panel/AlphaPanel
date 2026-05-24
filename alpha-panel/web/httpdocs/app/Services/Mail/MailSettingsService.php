<?php

namespace App\Services\Mail;

use App\Models\ZimbraServerSetting;
use Illuminate\Support\Facades\Log;

class MailSettingsService
{
    public function mailuEnabled(): bool
    {
        return (bool) config('panel.features.mailu');
    }

    public function zimbraEnabled(): bool
    {
        if ((bool) config('panel.features.zimbra')) {
            return true;
        }

        return (bool) optional(ZimbraServerSetting::current())->enabled;
    }

    public function mailEnabled(): bool
    {
        return $this->mailuEnabled() || $this->zimbraEnabled();
    }

    /** @return array<string, mixed> */
    public function relayConfig(): array
    {
        $cfg = (array) config('panel.mail.relay');

        return [
            'host' => $cfg['host'] ?? null,
            'port' => $cfg['port'] ?? 587,
            'username' => $cfg['username'] ?? null,
            'password_set' => ! empty($cfg['password']),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function updateRelay(array $payload): void
    {
        // Relay env lives in `external-services/mailu.env` and root `.env`.
        // The actual write is performed by the installer / sysadmin script;
        // here we cache the canonical values so the UI reads the same as the
        // post-restart Mailu state.
        cache()->put('mail.relay.draft', $payload, now()->addDays(30));
        Log::info('mail.relay.updated', ['host' => $payload['host'] ?? null]);
    }

    /** @return array<string, mixed> */
    public function zimbraConfig(): array
    {
        $row = ZimbraServerSetting::current();
        if ($row === null) {
            $envCfg = (array) config('panel.mail.zimbra');

            return [
                'enabled' => (bool) ($envCfg['enabled'] ?? false),
                'admin_url' => $envCfg['admin_url'] ?? null,
                'admin_user' => $envCfg['admin_user'] ?? null,
                'admin_password_set' => ! empty($envCfg['admin_password']),
                'default_mx_host' => $envCfg['default_mx_host'] ?? null,
                'default_mx_priority' => $envCfg['default_mx_priority'] ?? 10,
                'default_spf_include' => $envCfg['default_spf_include'] ?? null,
                'verify_tls' => (bool) ($envCfg['verify_tls'] ?? true),
                'timeout_seconds' => $envCfg['timeout_seconds'] ?? 15,
                'last_health_check_at' => null,
                'last_health_status' => null,
            ];
        }

        return [
            'enabled' => $row->enabled,
            'admin_url' => $row->admin_url,
            'admin_user' => $row->admin_user,
            'admin_password_set' => ! empty($row->admin_password_encrypted),
            'default_mx_host' => $row->default_mx_host,
            'default_mx_priority' => $row->default_mx_priority,
            'default_spf_include' => $row->default_spf_include,
            'verify_tls' => $row->verify_tls,
            'timeout_seconds' => $row->timeout_seconds,
            'last_health_check_at' => $row->last_health_check_at?->toIso8601String(),
            'last_health_status' => $row->last_health_status,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function updateZimbra(array $payload): ZimbraServerSetting
    {
        $row = ZimbraServerSetting::query()->firstOrNew(['id' => 1]);
        $row->id = 1;
        $row->enabled = (bool) ($payload['enabled'] ?? false);
        $row->admin_url = (string) ($payload['admin_url'] ?? '');
        $row->admin_user = (string) ($payload['admin_user'] ?? '');
        if (! empty($payload['admin_password'])) {
            $row->admin_password = (string) $payload['admin_password'];
        }
        $row->default_mx_host = (string) ($payload['default_mx_host'] ?? '');
        $row->default_mx_priority = (int) ($payload['default_mx_priority'] ?? 10);
        $row->default_spf_include = $payload['default_spf_include'] ?? null;
        $row->verify_tls = (bool) ($payload['verify_tls'] ?? true);
        $row->timeout_seconds = (int) ($payload['timeout_seconds'] ?? 15);
        $row->save();

        ZimbraServerSetting::flushCurrent();

        return $row->refresh();
    }
}
