<?php

namespace Tests\Feature;

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
            'panel.caddy_main_config' => '/etc/frankenphp/Caddyfile',
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

    public function test_reload_caddy_tries_default_config_path_when_configured_path_fails(): void
    {
        config([
            'panel.caddy_main_config' => '/etc/frankenphp-container/Caddyfile',
        ]);

        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('execInContainer')
            ->once()
            ->with('frankenphp', ['frankenphp', 'reload', '--config', '/etc/frankenphp-container/Caddyfile'])
            ->andReturn(new ExecResult(exitCode: 1, output: '', errorOutput: 'Invalid config path'));
        $mock->shouldReceive('execInContainer')
            ->once()
            ->with('frankenphp', ['frankenphp', 'reload', '--config', '/etc/frankenphp/Caddyfile'])
            ->andReturn(new ExecResult(exitCode: 0, output: 'OK', errorOutput: ''));

        $service = app(ReloadService::class);
        $result = $service->reloadCaddy();

        $this->assertTrue($result);
    }

    public function test_reload_caddy_returns_false_when_all_reload_commands_fail(): void
    {
        config([
            'panel.caddy_main_config' => '/etc/frankenphp-container/Caddyfile',
        ]);

        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('execInContainer')
            ->once()
            ->with('frankenphp', ['frankenphp', 'reload', '--config', '/etc/frankenphp-container/Caddyfile'])
            ->andReturn(new ExecResult(exitCode: 1, output: '', errorOutput: 'Invalid config path'));
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
            ->andThrow(new \App\Exceptions\PortainerException('Connection refused'));

        $service = app(ReloadService::class);
        $result = $service->reloadApache();

        $this->assertFalse($result);
    }
}
