<?php

namespace App\Services\Mail\Zimbra;

use App\Models\ZimbraServerSetting;
use App\Services\Mail\Exceptions\ZimbraAuthException;
use App\Services\Mail\Exceptions\ZimbraConnectionException;
use App\Services\Mail\Exceptions\ZimbraSoapFaultException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

/**
 * Thin SOAP client over Zimbra's Admin API. The XML envelope is hand-built
 * because the Admin WSDL is brittle; PHP's SoapClient struggles with it.
 *
 * All public methods return decoded arrays or null; SOAP faults are translated
 * to MailProvider exceptions so callers don't have to know about XML.
 */
class ZimbraSoapClient
{
    private const TOKEN_CACHE_KEY = 'mail.zimbra.token';

    private const NS_ZIMBRA = 'urn:zimbra';

    private const NS_ADMIN = 'urn:zimbraAdmin';

    public function __construct(private readonly HttpFactory $http) {}

    public function authenticate(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $setting = $this->setting();
        $resp = $this->soap('AuthRequest', [
            'name' => $setting->admin_user,
            'password' => $setting->admin_password ?? '',
        ], includeContext: false);

        if ($this->requiresTwoFactor($resp)) {
            throw new ZimbraAuthException(__('The Zimbra admin account has two-factor authentication enabled. The panel cannot answer a 2FA prompt; use a dedicated admin account without 2FA.'));
        }

        $token = $this->extractToken($resp);
        if ($token === null) {
            throw new ZimbraAuthException('Zimbra AuthRequest returned no auth token.');
        }
        Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addMinutes(50));

        return $token;
    }

    public function refreshToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    /** @return array<string, mixed>|null */
    public function getDomain(string $name): ?array
    {
        return $this->safeFetch('GetDomainRequest', [
            'domain' => ['by' => 'name', '_content' => $name],
        ], 'domain');
    }

    /** @param array<string, string> $attrs */
    public function createDomain(string $name, array $attrs = []): void
    {
        $this->soap('CreateDomainRequest', [
            'name' => $name,
        ] + $this->attrPayload($attrs));
    }

    public function deleteDomain(string $id): void
    {
        $this->soap('DeleteDomainRequest', [
            'id' => $id,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function searchAccounts(string $domain, int $limit = 500): array
    {
        $rows = $this->soap('SearchDirectoryRequest', [
            'domain' => $domain,
            'types' => 'accounts',
            'limit' => $limit,
        ]);

        return $this->extractList($rows, 'account');
    }

    /** @return list<array<string, mixed>> */
    public function searchAliases(string $domain, int $limit = 500): array
    {
        $rows = $this->soap('SearchDirectoryRequest', [
            'domain' => $domain,
            'types' => 'aliases',
            'limit' => $limit,
        ]);

        return $this->extractList($rows, 'alias');
    }

    /** @return array<string, mixed>|null */
    public function getAccount(string $address): ?array
    {
        return $this->safeFetch('GetAccountRequest', [
            'account' => ['by' => 'name', '_content' => $address],
        ], 'account');
    }

    /** @return array<string, mixed>|null */
    public function getAlias(string $address): ?array
    {
        $rows = $this->searchAliases(substr(strrchr($address, '@') ?: '', 1));
        foreach ($rows as $row) {
            if (($row['name'] ?? null) === $address) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string, string> $attrs */
    public function createAccount(string $address, string $password, array $attrs = []): void
    {
        $this->soap('CreateAccountRequest', [
            'name' => $address,
            'password' => $password,
        ] + $this->attrPayload($attrs));
    }

    /** @param array<string, string> $attrs */
    public function modifyAccount(string $id, array $attrs): void
    {
        $this->soap('ModifyAccountRequest', [
            'id' => $id,
        ] + $this->attrPayload($attrs));
    }

    public function setPassword(string $id, string $newPassword): void
    {
        $this->soap('SetPasswordRequest', [
            'id' => $id,
            'newPassword' => $newPassword,
        ]);
    }

    public function deleteAccount(string $id): void
    {
        $this->soap('DeleteAccountRequest', [
            'id' => $id,
        ]);
    }

    public function addAccountAlias(string $accountId, string $alias): void
    {
        $this->soap('AddAccountAliasRequest', [
            'id' => $accountId,
            'alias' => $alias,
        ]);
    }

    public function removeAccountAlias(string $accountId, string $alias): void
    {
        $this->soap('RemoveAccountAliasRequest', [
            'id' => $accountId,
            'alias' => $alias,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function soap(string $request, array $body, bool $includeContext = true, bool $isRetry = false): SimpleXMLElement
    {
        $setting = $this->setting();
        $envelope = $this->buildEnvelope($request, $body, $includeContext);

        try {
            $response = $this->http
                ->withOptions([
                    'verify' => $setting->verify_tls,
                    'connect_timeout' => max(5, (int) ($setting->timeout_seconds / 2)),
                ])
                ->timeout($setting->timeout_seconds)
                ->withBody($envelope, 'application/soap+xml')
                ->post($setting->admin_url);
        } catch (ConnectionException $e) {
            throw new ZimbraConnectionException($e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            try {
                $this->translateFault($response->body());
            } catch (ZimbraAuthException $e) {
                // A cached token can be revoked server-side (password/2FA change, logout); re-auth once.
                if (! $includeContext || $isRetry) {
                    throw $e;
                }

                return $this->soap($request, $body, true, true);
            }
        }

        $xml = $this->parseXml($response->body());

        return $this->bodyOf($xml);
    }

    /** @param array<string, mixed> $body */
    private function buildEnvelope(string $request, array $body, bool $includeContext): string
    {
        $token = $includeContext ? $this->authenticate() : null;
        $contextXml = $token
            ? '<context xmlns="'.self::NS_ZIMBRA.'"><authToken>'.htmlspecialchars($token, ENT_XML1).'</authToken></context>'
            : '';

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">'
            .'<soap:Header>'.$contextXml.'</soap:Header>'
            .'<soap:Body>'
            .'<'.$request.' xmlns="'.self::NS_ADMIN.'">'
            .$this->encodeBody($body)
            .'</'.$request.'>'
            .'</soap:Body>'
            .'</soap:Envelope>';
    }

    /** @param array<string, mixed> $body */
    private function encodeBody(array $body): string
    {
        $xml = '';
        foreach ($body as $key => $value) {
            $key = preg_replace('/__\d+$/', '', (string) $key);
            if (is_array($value)) {
                $attrString = '';
                $content = '';
                foreach ($value as $k => $v) {
                    if ($k === '_content') {
                        $content = htmlspecialchars((string) $v, ENT_XML1);

                        continue;
                    }
                    $attrString .= ' '.$k.'="'.htmlspecialchars((string) $v, ENT_XML1).'"';
                }
                $xml .= '<'.$key.$attrString.'>'.$content.'</'.$key.'>';

                continue;
            }
            $xml .= '<'.$key.'>'.htmlspecialchars((string) $value, ENT_XML1).'</'.$key.'>';
        }

        return $xml;
    }

    /** @param array<string, string> $attrs */
    private function attrPayload(array $attrs): array
    {
        $payload = [];
        $i = 0;
        foreach ($attrs as $name => $value) {
            $payload['a__'.($i++)] = ['n' => $name, '_content' => $value];
        }

        // `a__N` keys keep repeated <a> elements unique; encodeBody() strips the suffix.
        return $payload;
    }

    private function parseXml(string $body): SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            throw new ZimbraSoapFaultException('Zimbra returned non-XML body.');
        }

        return $xml;
    }

    private function translateFault(string $body): void
    {
        try {
            $xml = $this->parseXml($body);
            $fault = $xml->xpath('//*[local-name()="Fault"]');
            if ($fault) {
                $reasonNodes = $fault[0]->xpath('.//*[local-name()="Text"]');
                $reason = '';
                if ($reasonNodes && isset($reasonNodes[0])) {
                    $reason = trim((string) $reasonNodes[0]);
                }
                if ($reason === '') {
                    $reason = trim((string) ($fault[0]->faultstring ?? ''));
                }
                $codeNodes = $fault[0]->xpath('.//*[local-name()="Code" and namespace-uri()="urn:zimbra"]');
                $code = ($codeNodes && isset($codeNodes[0])) ? trim((string) $codeNodes[0]) : '';
                $detailNodes = $fault[0]->xpath('.//*[local-name()="Trace" and namespace-uri()="urn:zimbra"]');
                $trace = ($detailNodes && isset($detailNodes[0])) ? trim((string) $detailNodes[0]) : '';

                if ($reason === '') {
                    $reason = $code !== '' ? "Zimbra SOAP fault: {$code}" : 'Zimbra SOAP fault';
                }

                Log::warning('zimbra.fault', [
                    'code' => $code,
                    'reason' => $reason,
                    'trace' => $trace,
                    'raw' => mb_substr($body, 0, 2000),
                ]);

                if (in_array($code, ['account.AUTH_FAILED', 'service.AUTH_REQUIRED', 'service.AUTH_EXPIRED'], true)) {
                    Cache::forget(self::TOKEN_CACHE_KEY);
                    throw new ZimbraAuthException($reason);
                }
                throw new ZimbraSoapFaultException($reason, $code, $body);
            }
        } catch (ZimbraAuthException|ZimbraSoapFaultException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('zimbra.fault.parse_failed', [
                'error' => $e->getMessage(),
                'raw' => mb_substr($body, 0, 2000),
            ]);
        }
        throw new ZimbraSoapFaultException('Zimbra SOAP request failed.');
    }

    private function bodyOf(SimpleXMLElement $xml): SimpleXMLElement
    {
        $envelope = $xml->children('http://www.w3.org/2003/05/soap-envelope');
        $body = $envelope->Body ?? $xml->Body;

        return $body->children('urn:zimbraAdmin')[0] ?? $body;
    }

    private function extractToken(SimpleXMLElement $body): ?string
    {
        foreach ($body->children('urn:zimbraAdmin') as $child) {
            if ($child->getName() === 'authToken') {
                return (string) $child;
            }
        }

        return null;
    }

    /**
     * 2FA accounts get a half-authenticated token that admin requests later reject as expired.
     */
    private function requiresTwoFactor(SimpleXMLElement $body): bool
    {
        $nodes = $body->xpath('.//*[local-name()="twoFactorAuthRequired"]');

        return $nodes !== false && $nodes !== null && isset($nodes[0]) && strtolower(trim((string) $nodes[0])) === 'true';
    }

    /** @return array<string, mixed>|null */
    private function safeFetch(string $request, array $body, string $element): ?array
    {
        try {
            $resp = $this->soap($request, $body);
        } catch (ZimbraSoapFaultException $e) {
            $code = $e->faultCode ?? '';
            if (str_ends_with($code, 'NO_SUCH_ACCOUNT') || str_ends_with($code, 'NO_SUCH_DOMAIN')) {
                return null;
            }
            throw $e;
        }

        $items = $this->extractList($resp, $element);

        return $items[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private function extractList(SimpleXMLElement $body, string $element): array
    {
        $rows = [];
        foreach ($body->children('urn:zimbraAdmin') as $node) {
            if ($node->getName() !== $element) {
                continue;
            }
            $row = [];
            foreach ($node->attributes() as $attr => $val) {
                $row[$attr] = (string) $val;
            }
            foreach ($node->children('urn:zimbraAdmin') as $child) {
                if ($child->getName() === 'a') {
                    $row[(string) $child['n']] = (string) $child;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function setting(): ZimbraServerSetting
    {
        $setting = ZimbraServerSetting::current();
        if ($setting === null || ! $setting->enabled) {
            throw new ZimbraConnectionException('Zimbra server is not configured or disabled.');
        }

        return $setting;
    }
}
