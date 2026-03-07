<?php

namespace Tests\Unit;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\User;
use App\Services\DomainConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class DomainConfigRendererTest extends TestCase
{
    use DatabaseTransactions;

    protected DomainConfigService $service;

    protected string $caddySitesBasePath;

    protected string $apacheSitesBasePath;

    protected string $letsEncryptBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->caddySitesBasePath = '/tmp/test-caddy-sites';
        $this->apacheSitesBasePath = '/tmp/test-apache-sites';
        $this->letsEncryptBasePath = '/tmp/test-letsencrypt';

        config()->set('panel.caddy_sites_base', $this->caddySitesBasePath);
        config()->set('panel.apache_sites_base', $this->apacheSitesBasePath);
        config()->set('panel.letsencrypt_base', $this->letsEncryptBasePath);

        File::shouldReceive('isDirectory')->andReturn(true)->byDefault();
        File::shouldReceive('makeDirectory')->andReturn(true)->byDefault();
        File::shouldReceive('exists')->andReturn(false)->byDefault();
        File::shouldReceive('put')->andReturn(true)->byDefault();
        File::shouldReceive('move')->andReturn(true)->byDefault();
        Log::shouldReceive('info')->byDefault();
        Log::shouldReceive('warning')->byDefault();

        $this->service = new DomainConfigService;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_renderer_does_not_include_tls_before_cert_exists(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->make([
            'fqdn' => 'nocert.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'root_path' => null,
            'enable_www_redirect' => false,
            'additional_hostnames' => [],
            'enable_worker' => false,
        ]);

        $capturedContent = null;

        File::shouldReceive('put')
            ->once()
            ->with(Mockery::any(), Mockery::capture($capturedContent));
        File::shouldReceive('move')->once();

        $this->service->renderWithoutTls($domain);

        $this->assertNotNull($capturedContent);
        $this->assertStringNotContainsString('tls', $capturedContent);
        $this->assertStringContainsString('nocert.com:80', $capturedContent);
        $this->assertStringContainsString('php_server', $capturedContent);
        $this->assertStringContainsString('import common-headers', $capturedContent);
        $this->assertStringContainsString('encode zstd br gzip', $capturedContent);
        $this->assertStringContainsString('log {', $capturedContent);
    }

    public function test_renderer_does_not_include_tls_when_cert_files_missing(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->make([
            'fqdn' => 'missingcert.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'root_path' => null,
            'enable_www_redirect' => false,
            'additional_hostnames' => [],
            'enable_worker' => false,
        ]);

        File::shouldReceive('exists')->andReturn(false);

        $capturedContent = null;

        File::shouldReceive('put')
            ->once()
            ->with(Mockery::any(), Mockery::capture($capturedContent));
        File::shouldReceive('move')->once();

        Log::shouldReceive('warning')
            ->once()
            ->with(Mockery::pattern('/TLS certs not found for missingcert\.com/'));

        $this->service->renderWithTls($domain);

        $this->assertStringNotContainsString('tls', $capturedContent);
        $this->assertStringContainsString('missingcert.com:80', $capturedContent);
    }

    public function test_renderer_includes_tls_when_cert_files_exist(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->make([
            'fqdn' => 'hascert.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'root_path' => null,
            'enable_www_redirect' => false,
            'additional_hostnames' => [],
            'enable_worker' => false,
        ]);

        $certPath = "{$this->letsEncryptBasePath}/hascert.com/fullchain.pem";
        $keyPath = "{$this->letsEncryptBasePath}/hascert.com/privkey.pem";

        File::shouldReceive('exists')->with($certPath)->andReturn(true);
        File::shouldReceive('exists')->with($keyPath)->andReturn(true);

        $capturedContent = null;

        File::shouldReceive('put')
            ->once()
            ->with(Mockery::any(), Mockery::capture($capturedContent));
        File::shouldReceive('move')->once();

        $this->service->renderWithTls($domain);

        $this->assertStringContainsString('(reverse-proxy-hascert-com)', $capturedContent);
        $this->assertStringContainsString('tls', $capturedContent);
        $this->assertStringContainsString('fullchain.pem', $capturedContent);
        $this->assertStringContainsString('privkey.pem', $capturedContent);
        $this->assertStringContainsString('hascert.com:80', $capturedContent);
        $this->assertStringContainsString('redir https://hascert.com{uri}', $capturedContent);
        $this->assertStringContainsString('hascert.com:443', $capturedContent);
        $this->assertStringContainsString('import reverse-proxy-hascert-com', $capturedContent);
    }

    public function test_modern_domain_with_worker_generates_worker_block(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->make([
            'fqdn' => 'worker.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'root_path' => null,
            'enable_www_redirect' => false,
            'additional_hostnames' => [],
            'enable_worker' => true,
            'worker_num' => 8,
            'worker_watch' => true,
        ]);

        $capturedContent = null;

        File::shouldReceive('put')
            ->once()
            ->with(Mockery::any(), Mockery::capture($capturedContent));
        File::shouldReceive('move')->once();

        $this->service->renderWithoutTls($domain);

        $this->assertStringContainsString('worker {', $capturedContent);
        $this->assertStringContainsString('file frankenphp-worker.php', $capturedContent);
        $this->assertStringContainsString('num 8', $capturedContent);
        $this->assertStringContainsString('watch', $capturedContent);
    }

    public function test_modern_domain_without_worker_uses_simple_php_server(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->make([
            'fqdn' => 'simple.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'root_path' => null,
            'enable_www_redirect' => false,
            'additional_hostnames' => [],
            'enable_worker' => false,
        ]);

        $capturedContent = null;

        File::shouldReceive('put')
            ->once()
            ->with(Mockery::any(), Mockery::capture($capturedContent));
        File::shouldReceive('move')->once();

        $this->service->renderWithoutTls($domain);

        $this->assertStringContainsString('php_server', $capturedContent);
        $this->assertStringNotContainsString('worker {', $capturedContent);
    }

    public function test_renderer_includes_active_waf_import_when_modsecurity_is_enabled(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->make([
            'fqdn' => 'waf-active.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'enable_www_redirect' => false,
            'additional_hostnames' => [],
            'enable_worker' => false,
            'modsecurity_enabled' => true,
            'modsecurity_mode' => 'active',
        ]);

        $capturedContent = null;

        File::shouldReceive('put')
            ->once()
            ->with(Mockery::any(), Mockery::capture($capturedContent));
        File::shouldReceive('move')->once();

        $this->service->renderWithoutTls($domain);

        $this->assertStringContainsString("import common-headers\n    import waf-common", $capturedContent);
        $this->assertStringNotContainsString('import waf-common-detection-only', $capturedContent);
    }

    public function test_renderer_includes_detection_only_waf_import_when_mode_is_detection_only(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->make([
            'fqdn' => 'waf-detection-only.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'enable_www_redirect' => false,
            'additional_hostnames' => [],
            'enable_worker' => false,
            'modsecurity_enabled' => true,
            'modsecurity_mode' => 'detection_only',
        ]);

        $capturedContent = null;

        File::shouldReceive('put')
            ->once()
            ->with(Mockery::any(), Mockery::capture($capturedContent));
        File::shouldReceive('move')->once();

        $this->service->renderWithoutTls($domain);

        $this->assertStringContainsString('import waf-common-detection-only', $capturedContent);
        $this->assertStringNotContainsString("import waf-common\n", $capturedContent);
    }

    public function test_subdomain_path_in_caddy_config(): void
    {
        $this->seed(\Database\Seeders\PhpVersionSeeder::class);

        $owner = User::factory()->create();
        $fqdn = 'subpath-test-'.uniqid().'.com';
        $parent = Domain::factory()->create([
            'fqdn' => $fqdn,
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $subdomain = Domain::factory()->create([
            'fqdn' => "api.{$fqdn}",
            'parent_domain_id' => $parent->id,
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'enable_www_redirect' => false,
            'additional_hostnames' => [],
            'enable_worker' => false,
        ]);

        $capturedContent = null;

        File::shouldReceive('put')
            ->once()
            ->with(Mockery::any(), Mockery::capture($capturedContent));
        File::shouldReceive('move')->once();

        $this->service->renderWithoutTls($subdomain);

        $this->assertStringContainsString("api.{$fqdn}", $capturedContent);
        $this->assertStringContainsString("/var/www/vhosts/{$fqdn}/subdomains/api/httpdocs/public", $capturedContent);
    }

    public function test_subdomain_with_tls_uses_apex_domain_cert(): void
    {
        $this->seed(\Database\Seeders\PhpVersionSeeder::class);

        $owner = User::factory()->create();
        $fqdn = 'tlsapex-test-'.uniqid().'.com';
        $parent = Domain::factory()->create([
            'fqdn' => $fqdn,
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $subdomain = Domain::factory()->create([
            'fqdn' => "api.{$fqdn}",
            'parent_domain_id' => $parent->id,
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'enable_www_redirect' => false,
            'additional_hostnames' => [],
            'enable_worker' => false,
        ]);

        $apexCertPath = "{$this->letsEncryptBasePath}/{$fqdn}/fullchain.pem";
        $apexKeyPath = "{$this->letsEncryptBasePath}/{$fqdn}/privkey.pem";

        File::shouldReceive('exists')->with($apexCertPath)->andReturn(true);
        File::shouldReceive('exists')->with($apexKeyPath)->andReturn(true);

        $capturedContent = null;

        File::shouldReceive('put')
            ->once()
            ->with(Mockery::any(), Mockery::capture($capturedContent));
        File::shouldReceive('move')->once();

        $this->service->renderWithTls($subdomain);

        $this->assertStringContainsString("/{$fqdn}/fullchain.pem", $capturedContent);
        $this->assertStringNotContainsString("/api.{$fqdn}/fullchain.pem", $capturedContent);
        $this->assertStringContainsString("api.{$fqdn}:443", $capturedContent);
    }

    public function test_www_redirect_blocks_generated_with_tls(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->make([
            'fqdn' => 'wwwtest.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'root_path' => null,
            'enable_www_redirect' => true,
            'additional_hostnames' => [],
            'enable_worker' => false,
        ]);

        $certPath = "{$this->letsEncryptBasePath}/wwwtest.com/fullchain.pem";
        $keyPath = "{$this->letsEncryptBasePath}/wwwtest.com/privkey.pem";

        File::shouldReceive('exists')->with($certPath)->andReturn(true);
        File::shouldReceive('exists')->with($keyPath)->andReturn(true);

        $capturedContent = null;

        File::shouldReceive('put')
            ->once()
            ->with(Mockery::any(), Mockery::capture($capturedContent));
        File::shouldReceive('move')->once();

        $this->service->renderWithTls($domain);

        $this->assertStringContainsString('www.wwwtest.com:80', $capturedContent);
        $this->assertStringContainsString('www.wwwtest.com:443', $capturedContent);
        $this->assertStringContainsString('redir https://wwwtest.com{uri}', $capturedContent);
    }
}
