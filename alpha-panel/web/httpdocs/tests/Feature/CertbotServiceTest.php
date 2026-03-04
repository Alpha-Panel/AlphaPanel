<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use App\Services\Portainer\RunResult;
use App\Services\PortainerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CertbotServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panel.portainer_url' => 'https://portainer.test:8443',
            'panel.portainer_api_key' => 'test-key',
            'panel.portainer_endpoint_id' => 1,
            'panel.certbot_email' => 'admin@test.com',
            'panel.portainer_certbot_image' => 'alphapanel-docker-certbot-init:latest',
            'panel.compose_project_root_host' => '/opt/alphapanel',
            'panel.letsencrypt_base' => '/etc/letsencrypt/live',
        ]);
    }

    public function test_request_certificate_success(): void
    {
        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('createAndRunContainer')
            ->once()
            ->withArgs(function (array $config, int $timeout) {
                return $config['Image'] === 'alphapanel-docker-certbot-init:latest'
                    && $config['Entrypoint'] === ['/bin/sh']
                    && $config['Cmd'][0] === '-lc'
                    && str_contains($config['Cmd'][1], 'certbot certonly')
                    && str_contains($config['Cmd'][1], '--dns-cloudflare')
                    && $timeout === 300;
            })
            ->andReturn(new RunResult(exitCode: 0, output: 'Certificate obtained successfully'));

        $domain = Domain::factory()->create([
            'fqdn' => 'example.com',
            'owner_user_id' => User::factory()->create()->id,
        ]);

        $service = app(\App\Services\CertbotService::class);
        $result = $service->requestCertificate($domain);

        $this->assertTrue($result);
    }

    public function test_request_certificate_includes_wildcard_domain(): void
    {
        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('createAndRunContainer')
            ->once()
            ->withArgs(function (array $config) {
                $cmd = $config['Cmd'][1] ?? '';

                return str_contains($cmd, "'example.com'")
                    && str_contains($cmd, "'*.example.com'");
            })
            ->andReturn(new RunResult(exitCode: 0, output: 'OK'));

        $domain = Domain::factory()->create([
            'fqdn' => 'example.com',
            'owner_user_id' => User::factory()->create()->id,
        ]);

        $service = app(\App\Services\CertbotService::class);
        $service->requestCertificate($domain);
    }

    public function test_request_certificate_uses_host_bind_mounts(): void
    {
        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('createAndRunContainer')
            ->once()
            ->withArgs(function (array $config) {
                $binds = $config['HostConfig']['Binds'] ?? [];

                return in_array('/opt/alphapanel/letsencrypt:/etc/letsencrypt', $binds)
                    && in_array('/opt/alphapanel/secrets/cloudflare.ini:/secrets/cloudflare.ini:ro', $binds)
                    && in_array('/opt/alphapanel/frankenphp/sites-enabled:/etc/frankenphp/sites-enabled', $binds)
                    && in_array('/opt/alphapanel/scripts/certbot:/opt/certbot-scripts:ro', $binds);
            })
            ->andReturn(new RunResult(exitCode: 0, output: 'OK'));

        $domain = Domain::factory()->create([
            'fqdn' => 'example.com',
            'owner_user_id' => User::factory()->create()->id,
        ]);

        $service = app(\App\Services\CertbotService::class);
        $service->requestCertificate($domain);
    }

    public function test_request_certificate_passes_environment_variables(): void
    {
        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('createAndRunContainer')
            ->once()
            ->withArgs(function (array $config) {
                $env = $config['Env'] ?? [];

                return in_array('ADMIN_EMAIL=admin@test.com', $env)
                    && in_array('CERTBOT_DOMAIN_ROOT=/etc/frankenphp/sites-enabled', $env)
                    && in_array('CERTBOT_UPDATE_CADDYFILES=1', $env)
                    && in_array('CERTBOT_USE_STAGING=0', $env);
            })
            ->andReturn(new RunResult(exitCode: 0, output: 'OK'));

        $domain = Domain::factory()->create([
            'fqdn' => 'example.com',
            'owner_user_id' => User::factory()->create()->id,
        ]);

        $service = app(\App\Services\CertbotService::class);
        $service->requestCertificate($domain);
    }

    public function test_request_certificate_returns_false_on_failure(): void
    {
        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('createAndRunContainer')
            ->once()
            ->andReturn(new RunResult(exitCode: 1, output: 'Error: invalid credentials'));

        $domain = Domain::factory()->create([
            'fqdn' => 'example.com',
            'owner_user_id' => User::factory()->create()->id,
        ]);

        $service = app(\App\Services\CertbotService::class);
        $result = $service->requestCertificate($domain);

        $this->assertFalse($result);
    }

    public function test_request_certificate_returns_false_on_exception(): void
    {
        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('createAndRunContainer')
            ->once()
            ->andThrow(new \App\Exceptions\PortainerException('Connection refused'));

        $domain = Domain::factory()->create([
            'fqdn' => 'example.com',
            'owner_user_id' => User::factory()->create()->id,
        ]);

        $service = app(\App\Services\CertbotService::class);
        $result = $service->requestCertificate($domain);

        $this->assertFalse($result);
    }
}
