<?php

namespace Tests\Feature;

use App\Exceptions\PortainerException;
use App\Models\PhpVersion;
use App\Services\Portainer\ExecResult;
use App\Services\PortainerService;
use App\Services\ReloadService;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\TestCase;

class ReloadServiceTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $dockerCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panel.frankenphp_container' => 'frankenphp',
            'panel.php_code_server_container' => 'php-code-server',
            'panel.caddy_reload_config' => '/etc/frankenphp/Caddyfile',
            'panel.docker_socket_proxy_url' => 'http://docker-socket-proxy:2375',
        ]);
    }

    public function test_reload_caddy_runs_frankenphp_reload_and_checks_its_exit_code(): void
    {
        $this->fakeDockerApi(execExitCode: 0);

        $this->assertTrue(app(ReloadService::class)->reloadCaddy());

        $this->assertSame(
            ['frankenphp', 'reload', '--config', '/etc/frankenphp/Caddyfile'],
            $this->dockerCall('/containers/abc123/exec')['options']['json']['Cmd'],
        );
        $this->assertFalse($this->dockerCall('/exec/execid1/start')['options']['json']['Detach']);
        $this->assertNotNull($this->dockerCall('/exec/execid1/json'));
        $this->assertNull($this->dockerCall('/containers/abc123/restart'));
    }

    public function test_reload_caddy_restarts_the_container_when_reload_exits_non_zero(): void
    {
        $this->fakeDockerApi(execExitCode: 1);

        $this->assertTrue(app(ReloadService::class)->reloadCaddy());

        $this->assertNotNull($this->dockerCall('/containers/abc123/restart'));
    }

    public function test_reload_caddy_returns_false_when_reload_and_restart_both_fail(): void
    {
        $mock = Mockery::mock(Client::class);
        $mock->shouldReceive('get')->twice()->andThrow(new \Exception('Connection refused'));

        $this->app->bind(Client::class, fn () => $mock);

        $this->assertFalse(app(ReloadService::class)->reloadCaddy());
    }

    public function test_reload_caddy_returns_false_when_container_not_found(): void
    {
        $this->fakeDockerApi(containers: []);

        $this->assertFalse(app(ReloadService::class)->reloadCaddy());

        $this->assertCount(1, $this->dockerCalls);
    }

    public function test_reload_caddy_uses_custom_reload_config_path(): void
    {
        config(['panel.caddy_reload_config' => '/custom/path/Caddyfile']);

        $this->fakeDockerApi(execExitCode: 0);

        $this->assertTrue(app(ReloadService::class)->reloadCaddy());

        $this->assertContains(
            '/custom/path/Caddyfile',
            $this->dockerCall('/containers/abc123/exec')['options']['json']['Cmd'],
        );
    }

    public function test_reload_apache(): void
    {
        $mock = $this->mock(PortainerService::class);
        $mock->shouldReceive('execInContainer')
            ->once()
            ->with('php-code-server', ['apachectl', 'graceful'], 90)
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
            ->with('php-code-server', ['service', 'php8.5-fpm', 'reload'], 90)
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

    /**
     * Stand in for the Docker API behind docker-socket-proxy and record every call.
     *
     * @param  list<array{Id: string}>  $containers
     */
    private function fakeDockerApi(int $execExitCode = 0, array $containers = [['Id' => 'abc123']]): void
    {
        $mock = Mockery::mock(Client::class);

        $mock->shouldReceive('get')->andReturnUsing(function (string $url, array $options = []) use ($execExitCode, $containers): Response {
            $this->dockerCalls[] = ['method' => 'GET', 'url' => $url, 'options' => $options];

            return new Response(200, [], json_encode(str_contains($url, '/exec/') ? ['ExitCode' => $execExitCode] : $containers));
        });

        $mock->shouldReceive('post')->andReturnUsing(function (string $url, array $options = []): Response {
            $this->dockerCalls[] = ['method' => 'POST', 'url' => $url, 'options' => $options];

            return new Response(200, [], json_encode(['Id' => 'execid1']));
        });

        $this->app->bind(Client::class, fn () => $mock);
    }

    /**
     * @return array{method: string, url: string, options: array<string, mixed>}|null
     */
    private function dockerCall(string $urlSuffix): ?array
    {
        foreach ($this->dockerCalls as $call) {
            if (str_ends_with($call['url'], $urlSuffix)) {
                return $call;
            }
        }

        return null;
    }
}
