<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\MailcowApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class MailcowApiService
{
    /**
     * Build a configured HTTP client for the Mailcow API.
     */
    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('panel.mailcow.api_url'), '/'))
            ->withHeaders(['X-API-Key' => (string) config('panel.mailcow.api_key')])
            ->acceptJson()
            ->timeout(15)
            ->throw();
    }

    // -------------------------------------------------------------------------
    //  Response handling
    // -------------------------------------------------------------------------

    /**
     * Parse a Mailcow API response and throw on error.
     *
     * Mailcow returns varying formats:
     *  - Success: `[{"type":"success","msg":"..."}]` or raw data
     *  - Error:   `[{"type":"danger","msg":"..."}]`
     *
     * @return array<int|string, mixed>
     *
     * @throws MailcowApiException
     */
    private function handleResponse(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw new MailcowApiException('Mailcow API returned invalid JSON.');
        }

        // Detect the `[{"type":"danger","msg":"..."}]` error envelope.
        if (isset($body[0]['type']) && $body[0]['type'] === 'danger') {
            $messages = array_map(
                fn (array $entry): string => (string) ($entry['msg'] ?? 'Unknown error'),
                array_filter($body, fn (array $entry): bool => ($entry['type'] ?? '') === 'danger'),
            );

            throw new MailcowApiException(
                'Mailcow API error: '.implode('; ', $messages ?: ['Unknown error']),
            );
        }

        return $body;
    }

    // -------------------------------------------------------------------------
    //  Connection
    // -------------------------------------------------------------------------

    /**
     * Test connectivity to the Mailcow API.
     */
    public function testConnection(): bool
    {
        try {
            $response = $this->client()->get('/api/v1/get/status/containers');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    //  Domains
    // -------------------------------------------------------------------------

    /**
     * List all mail domains.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws MailcowApiException
     */
    public function listDomains(): array
    {
        $response = $this->client()->get('/api/v1/get/domain/all');

        return $this->handleResponse($response);
    }

    /**
     * Get details for a single domain.
     *
     * @return array<string, mixed>|null
     *
     * @throws MailcowApiException
     */
    public function getDomain(string $domain): ?array
    {
        $response = $this->client()->get("/api/v1/get/domain/{$domain}");
        $body = $response->json();

        if (! is_array($body) || $body === []) {
            return null;
        }

        return $body;
    }

    /**
     * Add a new mail domain.
     *
     * @return array<int|string, mixed>
     *
     * @throws MailcowApiException
     */
    public function addDomain(
        string $domain,
        int $maxMailboxes = 100,
        int $defQuota = 256,
        int $maxQuota = 10240,
        int $totalQuota = 10240,
    ): array {
        $response = $this->client()->post('/api/v1/add/domain', [
            'domain' => $domain,
            'active' => '1',
            'aliases' => '400',
            'mailboxes' => (string) $maxMailboxes,
            'defquota' => (string) $defQuota,
            'maxquota' => (string) $maxQuota,
            'quota' => (string) $totalQuota,
        ]);

        return $this->handleResponse($response);
    }

    /**
     * Update an existing mail domain.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<int|string, mixed>
     *
     * @throws MailcowApiException
     */
    public function updateDomain(string $domain, array $attributes): array
    {
        $response = $this->client()->post('/api/v1/edit/domain', [
            'attr' => $attributes,
            'items' => [$domain],
        ]);

        return $this->handleResponse($response);
    }

    /**
     * Delete a mail domain.
     *
     * @return array<int|string, mixed>
     *
     * @throws MailcowApiException
     */
    public function deleteDomain(string $domain): array
    {
        $response = $this->client()->post('/api/v1/delete/domain', [
            ['domain' => $domain],
        ]);

        return $this->handleResponse($response);
    }

    // -------------------------------------------------------------------------
    //  Mailboxes
    // -------------------------------------------------------------------------

    /**
     * List all mailboxes for a domain.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws MailcowApiException
     */
    public function listMailboxes(string $domain): array
    {
        $response = $this->client()->get("/api/v1/get/mailbox/all/{$domain}");

        return $this->handleResponse($response);
    }

    /**
     * Get details for a single mailbox.
     *
     * @return array<string, mixed>|null
     *
     * @throws MailcowApiException
     */
    public function getMailbox(string $email): ?array
    {
        $response = $this->client()->get("/api/v1/get/mailbox/{$email}");
        $body = $response->json();

        if (! is_array($body) || $body === []) {
            return null;
        }

        return $body;
    }

    /**
     * Create a new mailbox.
     *
     * @return array<int|string, mixed>
     *
     * @throws MailcowApiException
     */
    public function addMailbox(
        string $localPart,
        string $domain,
        string $password,
        string $name = '',
        int $quota = 256,
    ): array {
        $response = $this->client()->post('/api/v1/add/mailbox', [
            'local_part' => $localPart,
            'domain' => $domain,
            'name' => $name,
            'password' => $password,
            'password2' => $password,
            'quota' => (string) $quota,
            'active' => '1',
        ]);

        return $this->handleResponse($response);
    }

    /**
     * Update an existing mailbox.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<int|string, mixed>
     *
     * @throws MailcowApiException
     */
    public function updateMailbox(string $email, array $attributes): array
    {
        $response = $this->client()->post('/api/v1/edit/mailbox', [
            'attr' => $attributes,
            'items' => [$email],
        ]);

        return $this->handleResponse($response);
    }

    /**
     * Update a mailbox password.
     *
     * @return array<int|string, mixed>
     *
     * @throws MailcowApiException
     */
    public function updateMailboxPassword(string $email, string $password): array
    {
        return $this->updateMailbox($email, [
            'password' => $password,
            'password2' => $password,
        ]);
    }

    /**
     * Delete a mailbox.
     *
     * @return array<int|string, mixed>
     *
     * @throws MailcowApiException
     */
    public function deleteMailbox(string $email): array
    {
        $response = $this->client()->post('/api/v1/delete/mailbox', [$email]);

        return $this->handleResponse($response);
    }

    // -------------------------------------------------------------------------
    //  Aliases
    // -------------------------------------------------------------------------

    /**
     * List all aliases, optionally filtered by domain.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws MailcowApiException
     */
    public function listAliases(?string $domain = null): array
    {
        $response = $this->client()->get('/api/v1/get/alias/all');
        $aliases = $this->handleResponse($response);

        if ($domain !== null) {
            $suffix = '@'.$domain;

            $aliases = array_values(array_filter(
                $aliases,
                fn (array $alias): bool => str_ends_with((string) ($alias['address'] ?? ''), $suffix),
            ));
        }

        return $aliases;
    }

    /**
     * Create a new alias.
     *
     * @return array<int|string, mixed>
     *
     * @throws MailcowApiException
     */
    public function addAlias(string $address, string $goto, bool $active = true): array
    {
        $response = $this->client()->post('/api/v1/add/alias', [
            'address' => $address,
            'goto' => $goto,
            'active' => $active ? '1' : '0',
        ]);

        return $this->handleResponse($response);
    }

    /**
     * Update an existing alias.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<int|string, mixed>
     *
     * @throws MailcowApiException
     */
    public function updateAlias(int $id, array $attributes): array
    {
        $response = $this->client()->post('/api/v1/edit/alias', [
            'attr' => $attributes,
            'items' => [(string) $id],
        ]);

        return $this->handleResponse($response);
    }

    /**
     * Delete an alias.
     *
     * @return array<int|string, mixed>
     *
     * @throws MailcowApiException
     */
    public function deleteAlias(int $id): array
    {
        $response = $this->client()->post('/api/v1/delete/alias', [(string) $id]);

        return $this->handleResponse($response);
    }

    // -------------------------------------------------------------------------
    //  DKIM
    // -------------------------------------------------------------------------

    /**
     * Generate a DKIM key for a domain.
     *
     * @return array<int|string, mixed>
     *
     * @throws MailcowApiException
     */
    public function generateDkim(string $domain, int $keySize = 2048, string $selector = 'dkim'): array
    {
        $response = $this->client()->post('/api/v1/add/dkim', [
            'domains' => $domain,
            'dkim_selector' => $selector,
            'key_size' => (string) $keySize,
        ]);

        return $this->handleResponse($response);
    }

    /**
     * Get the DKIM public key TXT record value for a domain.
     *
     * @throws MailcowApiException
     */
    public function getDkimRecord(string $domain): ?string
    {
        $response = $this->client()->get("/api/v1/get/dkim/{$domain}");
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        return isset($body['dkim_txt']) && $body['dkim_txt'] !== ''
            ? (string) $body['dkim_txt']
            : null;
    }

    // -------------------------------------------------------------------------
    //  Quota usage
    // -------------------------------------------------------------------------

    /**
     * Get quota usage for a mailbox.
     *
     * @return array{quota: int, quota_used: int, messages: int, percent_used: float}
     *
     * @throws MailcowApiException
     */
    public function getQuotaUsage(string $email): array
    {
        $mailbox = $this->getMailbox($email);

        if ($mailbox === null) {
            throw new MailcowApiException("Mailbox not found: {$email}");
        }

        $quota = (int) ($mailbox['quota'] ?? 0);
        $quotaUsed = (int) ($mailbox['quota_used'] ?? 0);
        $messages = (int) ($mailbox['messages'] ?? 0);

        return [
            'quota' => $quota,
            'quota_used' => $quotaUsed,
            'messages' => $messages,
            'percent_used' => $quota > 0 ? round($quotaUsed / $quota * 100, 2) : 0.0,
        ];
    }
}
