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

    public function test_reload_caddy_runs_frankenphp_reload_via_portainer_exec(): void
    {
        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('execInContainer')
            ->once()
            ->with('frankenphp', ['frankenphp', 'reload', '--config', '/etc/frankenphp/Caddyfile'])
            ->andReturn(new ExecResult(exitCode: 0, output: 'OK', errorOutput: ''));

        $service = app(ReloadService::class);
        $result = $service->reloadCaddy();

        $this->assertTrue($result);
    }

    public function test_reload_caddy_uses_custom_reload_config_path(): void
    {
        config([
            'panel.caddy_reload_config' => '/custom/path/Caddyfile',
        ]);

        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('execInContainer')
            ->once()
            ->with('frankenphp', ['frankenphp', 'reload', '--config', '/custom/path/Caddyfile'])
            ->andReturn(new ExecResult(exitCode: 0, output: 'OK', errorOutput: ''));

        $service = app(ReloadService::class);
        $result = $service->reloadCaddy();

        $this->assertTrue($result);
    }

    public function test_reload_caddy_returns_false_on_failure(): void
    {
        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('execInContainer')
            ->once()
            ->with('frankenphp', ['frankenphp', 'reload', '--config', '/etc/frankenphp/Caddyfile'])
            ->andReturn(new ExecResult(exitCode: 1, output: '', errorOutput: 'Reload failed'));

        $service = app(ReloadService::class);
        $result = $service->reloadCaddy();

        $this->assertFalse($result);
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
