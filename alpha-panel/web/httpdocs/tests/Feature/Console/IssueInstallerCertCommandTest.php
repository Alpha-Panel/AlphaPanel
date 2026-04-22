<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AcmeSetting;
use App\Services\Acme\AcmeResult;
use App\Services\Acme\AcmeService;
use App\Services\SslCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IssueInstallerCertCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_errors_when_token_file_missing(): void
    {
        $this->artisan('panel:issue-installer-cert', [
            '--base' => 'example.com',
            '--token-file' => '/nonexistent/cloudflare.ini',
            '--admin-email' => 'admin@example.com',
        ])->assertExitCode(1);
    }

    public function test_it_errors_when_required_options_missing(): void
    {
        $this->artisan('panel:issue-installer-cert')->assertExitCode(1);
    }

    public function test_it_errors_when_token_line_not_in_ini(): void
    {
        $tokenFile = tempnam(sys_get_temp_dir(), 'cf');
        file_put_contents($tokenFile, "# empty file\n");

        $this->artisan('panel:issue-installer-cert', [
            '--base' => 'example.com',
            '--token-file' => $tokenFile,
            '--admin-email' => 'admin@example.com',
        ])->assertExitCode(1);

        unlink($tokenFile);
    }

    public function test_it_returns_failure_when_acme_service_fails(): void
    {
        $tokenFile = tempnam(sys_get_temp_dir(), 'cf');
        file_put_contents($tokenFile, "dns_cloudflare_api_token = tkn\n");

        $acmeMock = Mockery::mock(AcmeService::class);
        $acmeMock->shouldReceive('requestCertificateDnsCloudflare')
            ->once()
            ->andReturn(new AcmeResult(success: false, error: 'dns propagation timeout'));
        $this->app->instance(AcmeService::class, $acmeMock);

        $this->artisan('panel:issue-installer-cert', [
            '--base' => 'example.com',
            '--token-file' => $tokenFile,
            '--admin-email' => 'admin@example.com',
        ])->assertExitCode(1);

        $this->assertSame('admin@example.com', AcmeSetting::instance()->email);
        $this->assertDatabaseHas('domains', ['fqdn' => 'example.com']);

        unlink($tokenFile);
    }

    public function test_it_persists_setting_and_domain_on_success(): void
    {
        $tokenFile = tempnam(sys_get_temp_dir(), 'cf');
        file_put_contents($tokenFile, "dns_cloudflare_api_token = tkn\n");

        $acmeMock = Mockery::mock(AcmeService::class);
        $acmeMock->shouldReceive('requestCertificateDnsCloudflare')
            ->once()
            ->andReturn(new AcmeResult(
                success: true,
                fullchainPem: "-----BEGIN CERTIFICATE-----\nFAKE\n-----END CERTIFICATE-----\n",
                privateKeyPem: "-----BEGIN PRIVATE KEY-----\nFAKE\n-----END PRIVATE KEY-----\n",
            ));
        $this->app->instance(AcmeService::class, $acmeMock);

        $sslMock = Mockery::mock(SslCertificateService::class);
        $sslMock->shouldReceive('createFromPem')->once()->andReturn(new \App\Models\SslCertificate);
        $sslMock->shouldReceive('activate')->once();
        $this->app->instance(SslCertificateService::class, $sslMock);

        $this->artisan('panel:issue-installer-cert', [
            '--base' => 'example.com',
            '--token-file' => $tokenFile,
            '--admin-email' => 'admin@example.com',
        ])->assertExitCode(0);

        $this->assertSame('admin@example.com', AcmeSetting::instance()->email);
        $this->assertFalse(AcmeSetting::instance()->staging);
        $this->assertDatabaseHas('domains', ['fqdn' => 'example.com', 'status' => 'active']);

        unlink($tokenFile);
    }
}
