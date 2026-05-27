<?php

namespace App\Services\Mail\Providers;

use App\Models\Domain;
use App\Services\Mail\Contracts\MailProviderInterface;
use App\Services\Mail\DTO\Alias;
use App\Services\Mail\DTO\DnsHints;
use App\Services\Mail\DTO\Mailbox;
use App\Services\Mail\Exceptions\MailboxNotFoundException;
use App\Services\Mail\Exceptions\MailProviderUnavailableException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Mailu REST Admin API client. Idempotent registerDomain — if the domain
 * already exists in Mailu the call is a no-op and we just relink locally.
 */
class MailuProvider implements MailProviderInterface
{
    private const TOKEN_CACHE_KEY = 'mailu.api.token';

    public function __construct(private readonly HttpFactory $http) {}

    public function key(): string
    {
        return 'mailu';
    }

    public function isManaged(): bool
    {
        return true;
    }

    public function registerDomain(Domain $domain): void
    {
        $existing = $this->safeRequest('GET', "domain/{$domain->fqdn}");
        if ($existing !== null) {
            Log::info('mail.mailu.domain.linked', ['fqdn' => $domain->fqdn]);

            return;
        }

        $this->client()->post('domain', [
            'name' => $domain->fqdn,
            'max_users' => -1,
            'max_aliases' => -1,
            'max_quota_bytes' => 0,
            'signup_enabled' => false,
            'comment' => 'managed-by-alphapanel',
        ])->throw();

        Log::info('mail.mailu.domain.created', ['fqdn' => $domain->fqdn]);
    }

    public function deregisterDomain(Domain $domain): void
    {
        $this->safeRequest('DELETE', "domain/{$domain->fqdn}");
    }

    public function listMailboxes(Domain $domain): Collection
    {
        $rows = $this->client()->get("domain/{$domain->fqdn}/users")->throw()->json();

        return collect($rows)->map(fn (array $row) => $this->hydrateMailbox($row));
    }

    public function findMailbox(Domain $domain, string $localPart): ?Mailbox
    {
        $address = "{$localPart}@{$domain->fqdn}";
        $row = $this->safeRequest('GET', "user/{$address}");

        return $row ? $this->hydrateMailbox($row) : null;
    }

    public function createMailbox(Domain $domain, string $localPart, string $password, array $options = []): Mailbox
    {
        $address = "{$localPart}@{$domain->fqdn}";
        $row = $this->safeRequest('GET', "user/{$address}");
        if ($row !== null) {
            $this->client()->patch("user/{$address}", [
                'raw_password' => $password,
            ])->throw();

            return $this->findMailbox($domain, $localPart)
                ?? throw new MailboxNotFoundException($address);
        }

        $this->client()->post('user', array_filter([
            'email' => $address,
            'raw_password' => $password,
            'displayed_name' => $options['display_name'] ?? null,
            'quota_bytes' => $options['quota_bytes'] ?? 0,
            'comment' => 'managed-by-alphapanel',
            'enabled' => true,
        ]))->throw();

        return $this->findMailbox($domain, $localPart)
            ?? throw new MailboxNotFoundException($address);
    }

    public function updateMailbox(Domain $domain, string $localPart, array $changes): Mailbox
    {
        $address = "{$localPart}@{$domain->fqdn}";
        $payload = array_filter([
            'displayed_name' => $changes['display_name'] ?? null,
            'quota_bytes' => $changes['quota_bytes'] ?? null,
            'enabled' => $changes['active'] ?? null,
        ], static fn ($v) => $v !== null);

        if ($payload !== []) {
            $this->client()->patch("user/{$address}", $payload)->throw();
        }

        return $this->findMailbox($domain, $localPart)
            ?? throw new MailboxNotFoundException($address);
    }

    public function setPassword(Domain $domain, string $localPart, string $newPassword): void
    {
        $address = "{$localPart}@{$domain->fqdn}";
        $this->client()->patch("user/{$address}", [
            'raw_password' => $newPassword,
        ])->throw();
    }

    public function setForwarding(Domain $domain, string $localPart, array $forwardTo, bool $keepLocal): void
    {
        $address = "{$localPart}@{$domain->fqdn}";
        $this->client()->patch("user/{$address}", [
            'forward_enabled' => $forwardTo !== [],
            'forward_destination' => $forwardTo,
            'forward_keep' => $keepLocal,
        ])->throw();
    }

    public function deleteMailbox(Domain $domain, string $localPart): void
    {
        $address = "{$localPart}@{$domain->fqdn}";
        $this->safeRequest('DELETE', "user/{$address}");
    }

    public function listAliases(Domain $domain): Collection
    {
        $rows = $this->client()->get("alias/destination/{$domain->fqdn}")->throw()->json();

        return collect($rows)->map(fn (array $row) => new Alias(
            address: $row['email'] ?? '',
            destination: implode(',', $row['destination'] ?? []),
            providerExternalId: (string) ($row['id'] ?? ''),
        ));
    }

    public function createAlias(Domain $domain, string $fromLocalPart, string $toAddress): Alias
    {
        $address = "{$fromLocalPart}@{$domain->fqdn}";
        $existing = $this->safeRequest('GET', "alias/{$address}");
        if ($existing === null) {
            $this->client()->post('alias', [
                'email' => $address,
                'destination' => [$toAddress],
                'wildcard' => false,
                'comment' => 'managed-by-alphapanel',
            ])->throw();
        } else {
            $this->client()->patch("alias/{$address}", [
                'destination' => [$toAddress],
            ])->throw();
        }

        return new Alias(address: $address, destination: $toAddress);
    }

    public function deleteAlias(Domain $domain, string $fromLocalPart): void
    {
        $address = "{$fromLocalPart}@{$domain->fqdn}";
        $this->safeRequest('DELETE', "alias/{$address}");
    }

    public function getDnsHints(Domain $domain): DnsHints
    {
        $base = (string) config('panel.base_domain');
        $mxHost = config('panel.mail.hostname') ?: 'mail.'.$base;

        $domainData = $this->safeRequest('GET', "domain/{$domain->fqdn}");
        $dkimSelector = 'dkim';
        $dkimPublicKey = null;

        if ($domainData !== null) {
            $dnsDkim = $domainData['dns_dkim'] ?? null;
            if ($dnsDkim === null) {
                // Generate DKIM keys on first access; POST is idempotent-safe when null
                $this->client()->post("domain/{$domain->fqdn}/dkim")->throw();
                $refreshed = $this->safeRequest('GET', "domain/{$domain->fqdn}");
                $dnsDkim = $refreshed['dns_dkim'] ?? null;
            }
            if ($dnsDkim !== null && preg_match('/\bp=([A-Za-z0-9+\/=]+)/', $dnsDkim, $m)) {
                $dkimPublicKey = $m[1];
            }
        }

        $txt = [
            ['name' => $domain->fqdn, 'content' => 'v=spf1 mx ~all'],
            ['name' => "_dmarc.{$domain->fqdn}", 'content' => 'v=DMARC1; p=quarantine; rua=mailto:postmaster@'.$domain->fqdn.'; fo=1'],
        ];
        if ($dkimPublicKey) {
            $txt[] = [
                'name' => "{$dkimSelector}._domainkey.{$domain->fqdn}",
                'content' => 'v=DKIM1; k=rsa; p='.$dkimPublicKey,
            ];
        }

        return new DnsHints(
            mx: [
                ['name' => $domain->fqdn, 'priority' => 10, 'content' => $mxHost.'.'],
            ],
            txt: $txt,
            dkimSelector: $dkimSelector,
            dkimPublicKey: $dkimPublicKey,
        );
    }

    private function client(): PendingRequest
    {
        $base = rtrim((string) config('panel.mail.mailu_api_base'), '/');

        return $this->http
            ->baseUrl($base)
            ->timeout(15)
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->token(),
                'Accept' => 'application/json',
            ])
            ->acceptJson();
    }

    private function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = (string) config('services.mailu.api_token');
        if ($token === '') {
            throw new MailProviderUnavailableException(
                'Mailu API token missing. Run `php artisan mail:bootstrap` first.'
            );
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addHour());

        return $token;
    }

    /**
     * GET-or-DELETE that swallows 404s. Returns response body for GET / null otherwise.
     *
     * @return array<string, mixed>|null
     */
    private function safeRequest(string $method, string $path): ?array
    {
        try {
            $resp = $this->client()->send($method, $path);
            if ($resp->status() === 404) {
                return null;
            }
            $resp->throw();

            return $method === 'GET' ? (array) $resp->json() : [];
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrateMailbox(array $row): Mailbox
    {
        $forwardDest = $row['forward_destination'] ?? [];
        $aliases = $row['allow_spoofing'] ?? [];

        return new Mailbox(
            address: (string) ($row['email'] ?? ''),
            displayName: isset($row['displayed_name']) && $row['displayed_name'] !== false ? (string) $row['displayed_name'] : null,
            quotaBytes: isset($row['quota_bytes']) && is_numeric($row['quota_bytes']) ? (int) $row['quota_bytes'] : null,
            quotaUsedBytes: isset($row['quota_bytes_used']) && is_numeric($row['quota_bytes_used']) ? (int) $row['quota_bytes_used'] : null,
            active: (bool) ($row['enabled'] ?? true),
            forwardTo: is_array($forwardDest) ? array_values(array_map('strval', $forwardDest)) : [],
            keepLocal: (bool) ($row['forward_keep'] ?? true),
            aliases: is_array($aliases) ? array_values(array_map('strval', $aliases)) : [],
            providerExternalId: isset($row['id']) ? (string) $row['id'] : null,
        );
    }
}
