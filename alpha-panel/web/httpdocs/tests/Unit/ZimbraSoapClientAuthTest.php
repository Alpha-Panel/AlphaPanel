<?php

namespace Tests\Unit;

use App\Models\ZimbraServerSetting;
use App\Services\Mail\Exceptions\ZimbraAuthException;
use App\Services\Mail\Zimbra\ZimbraSoapClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZimbraSoapClientAuthTest extends TestCase
{
    private const SOAP_URL = 'https://zimbra.test:7071/service/admin/soap';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::put('zimbra.server.current', new ZimbraServerSetting([
            'enabled' => true,
            'admin_url' => self::SOAP_URL,
            'admin_user' => 'admin@panel.test',
            'verify_tls' => true,
            'timeout_seconds' => 15,
        ]), 60);
    }

    public function test_expired_cached_token_is_refreshed_and_request_retried(): void
    {
        Cache::put('mail.zimbra.token', 'stale-token', 60);
        Http::fake([
            self::SOAP_URL => Http::sequence()
                ->push($this->fault('service.AUTH_EXPIRED', 'auth credentials have expired'), 500)
                ->push($this->envelope('<AuthResponse xmlns="urn:zimbraAdmin"><authToken>fresh-token</authToken><lifetime>43200000</lifetime></AuthResponse>'))
                ->push($this->envelope('<GetDomainResponse xmlns="urn:zimbraAdmin"><domain id="abc" name="niyazi.org"/></GetDomainResponse>')),
        ]);

        $domain = app(ZimbraSoapClient::class)->getDomain('niyazi.org');

        $this->assertSame('abc', $domain['id'] ?? null);
        $this->assertSame('fresh-token', Cache::get('mail.zimbra.token'));
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '<GetDomainRequest')
            && str_contains($request->body(), '<authToken>fresh-token</authToken>'));
    }

    public function test_retry_happens_only_once(): void
    {
        Cache::put('mail.zimbra.token', 'stale-token', 60);
        $expired = $this->fault('service.AUTH_EXPIRED', 'auth credentials have expired');
        Http::fake([
            self::SOAP_URL => Http::sequence()
                ->push($expired, 500)
                ->push($this->envelope('<AuthResponse xmlns="urn:zimbraAdmin"><authToken>fresh-token</authToken></AuthResponse>'))
                ->push($expired, 500),
        ]);

        try {
            app(ZimbraSoapClient::class)->getDomain('niyazi.org');
            $this->fail('Expected ZimbraAuthException.');
        } catch (ZimbraAuthException $e) {
            $this->assertSame('auth credentials have expired', $e->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_two_factor_admin_account_fails_with_clear_message(): void
    {
        app()->setLocale('en');
        Http::fake([
            self::SOAP_URL => Http::response($this->envelope(
                '<AuthResponse xmlns="urn:zimbraAdmin"><authToken>half-token</authToken><twoFactorAuthRequired>true</twoFactorAuthRequired></AuthResponse>'
            )),
        ]);

        try {
            app(ZimbraSoapClient::class)->authenticate();
            $this->fail('Expected ZimbraAuthException.');
        } catch (ZimbraAuthException $e) {
            $this->assertStringContainsString('two-factor', $e->getMessage());
        }

        $this->assertNull(Cache::get('mail.zimbra.token'));
    }

    private function fault(string $code, string $reason): string
    {
        return $this->envelope(
            '<soap:Fault><soap:Code><soap:Value>soap:Sender</soap:Value></soap:Code>'
            .'<soap:Reason><soap:Text>'.$reason.'</soap:Text></soap:Reason>'
            .'<soap:Detail><Error xmlns="urn:zimbra"><Code>'.$code.'</Code></Error></soap:Detail></soap:Fault>'
        );
    }

    private function envelope(string $body): string
    {
        return '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Header/><soap:Body>'
            .$body.'</soap:Body></soap:Envelope>';
    }
}
