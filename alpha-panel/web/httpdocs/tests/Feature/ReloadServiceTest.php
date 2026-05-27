<?php

namespace Tests\Feature;

use App\Exceptions\PortainerException;
use App\Models\PhpVersion;
use App\Services\Portainer\ExecResult;
use App\Services\PortainerService;
use App\Services\ReloadService;
use Tests\TestCase;

class ReloadServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panel.caddy_admin_url' => 'http://frankenphp:2019',
            'panel.frankenphp_container' => 'frankenphp',
            'panel.php_code_server_container' => 'php-code-server',
            'panel.caddy_reload_config' => '/etc/frankenphp/Caddyfile',
        ]);
    }

    public function test_reload_caddy_runs_frankenphp_reload_via_detached_docker_exec(): void
    {
        // reloadCaddy() calls docker-socket-proxy directly (not via PortainerService)
        // using Detach=true so it returns immediately without waiting for WAF compilation.
        $mock = \Mockery::mock(\GuzzleHttp\Client::class);

        $listBody = $this->makeGuzzleStream(json_encode([['Id' => 'abc123']]));
        $createBody = $this->makeGuzzleStream(json_encode(['Id' => 'execid1']));
        $startBody = $this->makeGuzzleStream('');

        $listResp = \Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
        $listResp->shouldReceive('getBody')->andReturn($listBody);

        $createResp = \Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
        $createResp->shouldReceive('getBody')->andReturn($createBody);

        $startResp = \Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
        $startResp->shouldReceive('getBody')->andReturn($startBody);

        $mock->shouldReceive('get')->once()->andReturn($listResp);
        $mock->shouldReceive('post')->twice()->andReturn($createResp, $startResp);

        $this->app->bind(\GuzzleHttp\Client::class, fn () => $mock);

        $service = app(ReloadService::class);
        $result = $service->reloadCaddy();

        $this->assertTrue($result);
    }

    public function test_reload_caddy_returns_false_on_failure(): void
    {
        // reloadCaddy() catches all exceptions and returns false.
        $mock = \Mockery::mock(\GuzzleHttp\Client::class);
        $mock->shouldReceive('get')->andThrow(new \Exception('Connection refused'));

        $this->app->bind(\GuzzleHttp\Client::class, fn () => $mock);

        $service = app(ReloadService::class);
        $result = $service->reloadCaddy();

        $this->assertFalse($result);
    }

    public function test_reload_caddy_returns_false_when_container_not_found(): void
    {
        $mock = \Mockery::mock(\GuzzleHttp\Client::class);
        $emptyBody = $this->makeGuzzleStream(json_encode([]));
        $resp = \Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
        $resp->shouldReceive('getBody')->andReturn($emptyBody);
        $mock->shouldReceive('get')->once()->andReturn($resp);

        $this->app->bind(\GuzzleHttp\Client::class, fn () => $mock);

        $service = app(ReloadService::class);
        $result = $service->reloadCaddy();

        $this->assertFalse($result);
    }

    public function test_reload_caddy_uses_custom_reload_config_path(): void
    {
        config(['panel.caddy_reload_config' => '/custom/path/Caddyfile']);

        $capturedCmd = null;
        $mock = \Mockery::mock(\GuzzleHttp\Client::class);

        $listBody = $this->makeGuzzleStream(json_encode([['Id' => 'abc123']]));
        $createBody = $this->makeGuzzleStream(json_encode(['Id' => 'execid1']));
        $startBody = $this->makeGuzzleStream('');

        $listResp = \Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
        $listResp->shouldReceive('getBody')->andReturn($listBody);

        $createResp = \Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
        $createResp->shouldReceive('getBody')->andReturn($createBody);

        $startResp = \Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
        $startResp->shouldReceive('getBody')->andReturn($startBody);

        $mock->shouldReceive('get')->once()->andReturn($listResp);
        $mock->shouldReceive('post')->twice()
            ->andReturnUsing(function ($url, $options) use (&$capturedCmd, $createResp, $startResp) {
                if (str_contains($url, '/exec')) {
                    $capturedCmd = $options['json']['Cmd'] ?? null;

                    return $createResp;
                }

                return $startResp;
            });

        $this->app->bind(\GuzzleHttp\Client::class, fn () => $mock);

        $service = app(ReloadService::class);
        $result = $service->reloadCaddy();

        $this->assertTrue($result);
        $this->assertContains('/custom/path/Caddyfile', $capturedCmd ?? []);
    }

    private function makeGuzzleStream(string $content): \GuzzleHttp\Psr7\Stream
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $content);
        rewind($resource);

        return new \GuzzleHttp\Psr7\Stream($resource);
    }

    public function test_reload_apache(): void
    {
        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('execInContainer')
            ->once()
            ->with('php-code-server', ['apachectl', 'graceful'])
            ->andReturn(new ExecResult(exitCode: 0, output: 'OK', errorOutput: ''));

        $service = app(ReloadService::class);
        $result = $service->reloadApache();

        $this->assertTrue($result);
    }

    public function test_reload_apache_returns_false_on_failure(): void
    {
        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('execInContainer')
            ->once()
            ->andReturn(new ExecResult(exitCode: 1, output: '', errorOutput: 'Permission denied'));

        $service = app(ReloadService::class);
        $result = $service->reloadApache();

        $this->assertFalse($result);
    }

    public function test_reload_php_fpm(): void
    {
        $phpVersion = new PhpVersion;
        $phpVersion->fpm_service_name = 'php8.5-fpm';

        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('execInContainer')
            ->once()
            ->with('php-code-server', ['service', 'php8.5-fpm', 'reload'])
            ->andReturn(new ExecResult(exitCode: 0, output: 'OK', errorOutput: ''));

        $service = app(ReloadService::class);
        $result = $service->reloadPhpFpm($phpVersion);

        $this->assertTrue($result);
    }

    public function test_exec_returns_false_on_portainer_exception(): void
    {
        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('execInContainer')
            ->once()
            ->andThrow(new PortainerException('Connection refused'));

        $service = app(ReloadService::class);
        $result = $service->reloadApache();

        $this->assertFalse($result);
    }
}
