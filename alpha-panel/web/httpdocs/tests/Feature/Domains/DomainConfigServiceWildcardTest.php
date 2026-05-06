<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainMode;
use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\User;
use App\Services\DomainConfigService;
use Database\Seeders\PhpVersionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

class DomainConfigServiceWildcardTest extends TestCase
{
    use DatabaseTransactions;

    private DomainConfigService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PhpVersionSeeder::class);

        // Use a temp directory for generated config files so tests are
        // hermetic and do not require the real Caddy directory.
        $tempBase = sys_get_temp_dir().'/alphapanel_tests_'.getmypid();
        config(['panel.caddy_sites_base' => $tempBase]);

        $this->service = app(DomainConfigService::class);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Call renderWildcardSubdomainConfig via Reflection (it is private).
     *
     * @param  array{cert: string, key: string}|null  $certPaths
     * @return array<int, string>
     */
    private function callRenderWildcardSubdomainConfig(Domain $domain, ?array $certPaths): array
    {
        $method = new ReflectionMethod(DomainConfigService::class, 'renderWildcardSubdomainConfig');

        return $method->invoke(
            $this->service,
            $domain,
            $domain->fqdn,
            $domain->getWebRootPath(),
            $certPaths,
        );
    }

    /**
     * Call renderCatchallConfig via Reflection (it is private).
     *
     * @param  array{cert: string, key: string}|null  $certPaths
     * @return array<int, string>
     */
    private function callRenderCatchallConfig(Domain $domain, ?array $certPaths): array
    {
        $method = new ReflectionMethod(DomainConfigService::class, 'renderCatchallConfig');

        return $method->invoke(
            $this->service,
            $domain,
            $domain->getWebRootPath(),
            $certPaths,
        );
    }

    private function buildWildcardSubdomain(): Domain
    {
        $owner = User::factory()->create();
        $parent = Domain::factory()->create([
            'fqdn' => 'example.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        return Domain::factory()->create([
            'fqdn' => '*.example.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'mode' => DomainMode::WildcardSubdomain,
            'parent_domain_id' => $parent->id,
        ]);
    }

    private function buildCatchallDomain(): Domain
    {
        $owner = User::factory()->admin()->create();

        return Domain::factory()->create([
            'fqdn' => '*',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'mode' => DomainMode::WildcardCatchall,
        ]);
    }

    // -----------------------------------------------------------------------
    // renderWildcardSubdomainConfig tests
    // -----------------------------------------------------------------------

    public function test_wildcard_subdomain_config_with_cert_opens_443_block(): void
    {
        $domain = $this->buildWildcardSubdomain();
        $certPaths = ['cert' => '/etc/ssl/certs/fullchain.pem', 'key' => '/etc/ssl/certs/privkey.pem'];

        $lines = $this->callRenderWildcardSubdomainConfig($domain, $certPaths);
        $content = implode("\n", $lines);

        $this->assertStringContainsString('*.example.com:443 {', $content);
    }

    public function test_wildcard_subdomain_config_with_cert_contains_tls_directive(): void
    {
        $domain = $this->buildWildcardSubdomain();
        $certPaths = ['cert' => '/etc/ssl/certs/fullchain.pem', 'key' => '/etc/ssl/certs/privkey.pem'];

        $lines = $this->callRenderWildcardSubdomainConfig($domain, $certPaths);
        $content = implode("\n", $lines);

        $this->assertStringContainsString('tls /etc/ssl/certs/fullchain.pem', $content);
    }

    public function test_wildcard_subdomain_config_with_cert_does_not_contain_port_80_block(): void
    {
        $domain = $this->buildWildcardSubdomain();
        $certPaths = ['cert' => '/etc/ssl/certs/fullchain.pem', 'key' => '/etc/ssl/certs/privkey.pem'];

        $lines = $this->callRenderWildcardSubdomainConfig($domain, $certPaths);
        $content = implode("\n", $lines);

        $this->assertStringNotContainsString(':80', $content);
    }

    public function test_wildcard_subdomain_config_without_cert_uses_port_80_block(): void
    {
        $domain = $this->buildWildcardSubdomain();

        $lines = $this->callRenderWildcardSubdomainConfig($domain, null);
        $content = implode("\n", $lines);

        $this->assertStringContainsString('*.example.com:80 {', $content);
        $this->assertStringNotContainsString(':443', $content);
    }

    // -----------------------------------------------------------------------
    // renderCatchallConfig tests
    // -----------------------------------------------------------------------

    public function test_catchall_config_with_cert_opens_https_catchall_block(): void
    {
        $domain = $this->buildCatchallDomain();
        $certPaths = ['cert' => '/etc/ssl/certs/fullchain.pem', 'key' => '/etc/ssl/certs/privkey.pem'];

        $lines = $this->callRenderCatchallConfig($domain, $certPaths);
        $content = implode("\n", $lines);

        $this->assertStringContainsString('https:// {', $content);
    }

    public function test_catchall_config_with_cert_does_not_contain_port_80(): void
    {
        $domain = $this->buildCatchallDomain();
        $certPaths = ['cert' => '/etc/ssl/certs/fullchain.pem', 'key' => '/etc/ssl/certs/privkey.pem'];

        $lines = $this->callRenderCatchallConfig($domain, $certPaths);
        $content = implode("\n", $lines);

        $this->assertStringNotContainsString(':80', $content);
        $this->assertStringNotContainsString('http://', $content);
    }

    public function test_catchall_config_without_cert_opens_http_catchall_block(): void
    {
        $domain = $this->buildCatchallDomain();

        $lines = $this->callRenderCatchallConfig($domain, null);
        $content = implode("\n", $lines);

        $this->assertStringContainsString('http:// {', $content);
        $this->assertStringNotContainsString('https:// {', $content);
    }
}
