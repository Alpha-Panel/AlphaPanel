<?php

namespace Tests\Unit;

use App\Models\Domain;
use App\Models\ZimbraServerSetting;
use App\Services\Mail\Providers\ZimbraProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZimbraDomainProvisioningTest extends TestCase
{
    private const SOAP_URL = 'https://zimbra.test:7071/service/admin/soap';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::put('mail.zimbra.token', 'test-token', 60);
    }

    public function test_new_domain_is_created_with_panel_defaults(): void
    {
        $this->fakeSetting(inboundSmtpHost: 'mx.panel.test', timezone: 'Europe/Istanbul');
        $this->fakeZimbraWithoutDomain();

        app(ZimbraProvider::class)->registerDomain(new Domain(['fqdn' => 'niyazi.org']));

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return str_contains($body, '<CreateDomainRequest')
                && str_contains($body, '<name>niyazi.org</name>')
                && str_contains($body, '<a n="zimbraPublicServiceHostname">mail.niyazi.org</a>')
                && str_contains($body, '<a n="zimbraPublicServiceProtocol">https</a>')
                && str_contains($body, '<a n="zimbraDNSCheckHostname">mx.panel.test</a>')
                && str_contains($body, '<a n="zimbraPrefTimeZoneId">Europe/Istanbul</a>')
                && str_contains($body, '<a n="zimbraVirtualHostname">*.niyazi.org</a>')
                && ! str_contains($body, 'a__');
        });
    }

    public function test_inbound_smtp_host_falls_back_to_default_mx_host(): void
    {
        $this->fakeSetting(inboundSmtpHost: null, timezone: null);
        config(['panel.mail.zimbra.inbound_smtp_host' => null, 'panel.mail.zimbra.default_timezone' => null]);
        $this->fakeZimbraWithoutDomain();

        app(ZimbraProvider::class)->registerDomain(new Domain(['fqdn' => 'example.org']));

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return str_contains($body, '<CreateDomainRequest')
                && str_contains($body, '<a n="zimbraDNSCheckHostname">zimbra.panel.test</a>')
                && ! str_contains($body, 'zimbraPrefTimeZoneId');
        });
    }

    public function test_existing_domain_is_linked_without_create(): void
    {
        $this->fakeSetting(inboundSmtpHost: null, timezone: null);
        Http::fake([
            self::SOAP_URL => Http::response($this->envelope('<GetDomainResponse xmlns="urn:zimbraAdmin"><domain id="abc" name="niyazi.org"/></GetDomainResponse>')),
        ]);

        app(ZimbraProvider::class)->registerDomain(new Domain(['fqdn' => 'niyazi.org']));

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), 'CreateDomainRequest'));
    }

    private function fakeSetting(?string $inboundSmtpHost, ?string $timezone): void
    {
        $setting = new ZimbraServerSetting([
            'enabled' => true,
            'admin_url' => self::SOAP_URL,
            'admin_user' => 'admin@panel.test',
            'default_mx_host' => 'zimbra.panel.test',
            'inbound_smtp_host' => $inboundSmtpHost,
            'default_timezone' => $timezone,
            'verify_tls' => true,
            'timeout_seconds' => 15,
        ]);

        Cache::put('zimbra.server.current', $setting, 60);
    }

    private function fakeZimbraWithoutDomain(): void
    {
        $noSuchDomain = $this->envelope(
            '<soap:Fault><soap:Code><soap:Value>soap:Sender</soap:Value></soap:Code>'
            .'<soap:Reason><soap:Text>no such domain</soap:Text></soap:Reason>'
            .'<soap:Detail><Error xmlns="urn:zimbra"><Code>account.NO_SUCH_DOMAIN</Code></Error></soap:Detail></soap:Fault>'
        );

        Http::fake([
            self::SOAP_URL => Http::sequence()
                ->push($noSuchDomain, 500)
                ->push($this->envelope('<CreateDomainResponse xmlns="urn:zimbraAdmin"><domain id="new" name="x"/></CreateDomainResponse>')),
        ]);
    }

    private function envelope(string $body): string
    {
        return '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Header/><soap:Body>'
            .$body.'</soap:Body></soap:Envelope>';
    }
}
