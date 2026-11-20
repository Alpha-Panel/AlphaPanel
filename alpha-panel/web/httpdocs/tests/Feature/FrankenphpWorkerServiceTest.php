<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Services\FrankenphpWorkerService;
use App\Services\Portainer\ExecResult;
use App\Services\PortainerService;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FrankenphpWorkerServiceTest extends TestCase
{
    private string $basePath;

    /** @var list<string> */
    private array $outsidePaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/alphapanel-worker-'.uniqid();

        File::makeDirectory($this->basePath.'/httpdocs/public', 0755, true);
        File::makeDirectory($this->basePath.'/httpdocs/storage/framework', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (['/httpdocs/storage/framework', '/httpdocs/public/frankenphp-worker.php'] as $possibleLink) {
            if (is_link($this->basePath.$possibleLink)) {
                @unlink($this->basePath.$possibleLink) || @rmdir($this->basePath.$possibleLink);
            }
        }

        File::deleteDirectory($this->basePath);

        foreach ($this->outsidePaths as $path) {
            is_dir($path) ? File::deleteDirectory($path) : File::delete($path);
        }

        parent::tearDown();
    }

    public function test_site_with_signal_aware_worker_is_restarted_through_the_signal_file(): void
    {
        $this->installSignalAwareWorker();

        $portainer = Mockery::mock(PortainerService::class);
        $portainer->shouldNotReceive('execInContainer');

        $mode = (new FrankenphpWorkerService($portainer))->restart($this->domain());

        $this->assertSame(FrankenphpWorkerService::MODE_ROLLING, $mode);
        $this->assertFileExists($this->signalPath());
    }

    public function test_repeated_signal_moves_the_signal_time_forward(): void
    {
        $this->installSignalAwareWorker();
        File::put($this->signalPath(), '');
        touch($this->signalPath(), time() - 3600);

        $this->service()->restart($this->domain());

        clearstatcache();

        $this->assertGreaterThan(time() - 60, filemtime($this->signalPath()));
    }

    public function test_site_with_stock_octane_worker_falls_back_to_frankenphp_reload(): void
    {
        File::put($this->workerScriptPath(), "<?php\n\nrequire __DIR__.'/../vendor/laravel/octane/bin/frankenphp-worker.php';\n");

        $portainer = Mockery::mock(PortainerService::class);
        $portainer->shouldReceive('execInContainer')
            ->once()
            ->with('frankenphp', ['frankenphp', 'reload', '--config', '/etc/frankenphp/Caddyfile'], Mockery::type('int'))
            ->andReturn(new ExecResult(0, '', ''));

        $mode = (new FrankenphpWorkerService($portainer))->restart($this->domain());

        $this->assertSame(FrankenphpWorkerService::MODE_RELOAD, $mode);
        $this->assertFileDoesNotExist($this->signalPath());
    }

    public function test_failed_reload_fallback_reports_the_command_error(): void
    {
        $portainer = Mockery::mock(PortainerService::class);
        $portainer->shouldReceive('execInContainer')->once()->andReturn(new ExecResult(1, '', 'adapting config failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('adapting config failed');

        (new FrankenphpWorkerService($portainer))->restart($this->domain());
    }

    public function test_missing_signal_directory_is_reported(): void
    {
        $this->installSignalAwareWorker();
        File::deleteDirectory($this->basePath.'/httpdocs/storage');

        $this->expectException(RuntimeException::class);

        $this->service()->restart($this->domain());
    }

    public function test_signal_file_planted_as_symlink_is_replaced_not_followed(): void
    {
        $this->installSignalAwareWorker();

        $victim = $this->outsidePath('.txt');
        File::put($victim, 'secret');
        touch($victim, time() - 3600);
        $this->symlinkOrSkip($victim, $this->signalPath());

        $this->service()->restart($this->domain());

        clearstatcache();

        $this->assertFalse(is_link($this->signalPath()));
        $this->assertSame('secret', File::get($victim));
        $this->assertLessThan(time() - 1800, filemtime($victim));
    }

    public function test_signal_directory_linked_outside_the_site_is_refused(): void
    {
        $this->installSignalAwareWorker();

        $outside = $this->outsidePath();
        File::makeDirectory($outside);
        File::deleteDirectory($this->basePath.'/httpdocs/storage/framework');
        $this->symlinkOrSkip($outside, $this->basePath.'/httpdocs/storage/framework');

        $refused = false;

        try {
            $this->service()->restart($this->domain());
        } catch (RuntimeException) {
            $refused = true;
        }

        $this->assertTrue($refused);
        $this->assertFileDoesNotExist($outside.'/worker.restart');
    }

    public function test_worker_script_linked_outside_the_site_is_not_read(): void
    {
        $outside = $this->outsidePath('.php');
        File::put($outside, File::get(public_path('frankenphp-worker.php')));
        $this->symlinkOrSkip($outside, $this->workerScriptPath());

        $this->assertFalse($this->service()->supportsRollingRestart($this->domain()));
    }

    private function service(): FrankenphpWorkerService
    {
        return new FrankenphpWorkerService(Mockery::mock(PortainerService::class));
    }

    private function domain(): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getBasePath')->andReturn($this->basePath);

        return $domain;
    }

    private function installSignalAwareWorker(): void
    {
        File::put($this->workerScriptPath(), File::get(public_path('frankenphp-worker.php')));
    }

    private function outsidePath(string $suffix = ''): string
    {
        return $this->outsidePaths[] = sys_get_temp_dir().'/alphapanel-outside-'.uniqid().$suffix;
    }

    private function symlinkOrSkip(string $target, string $link): void
    {
        if (! @symlink($target, $link)) {
            $this->markTestSkipped('Symlinks cannot be created on this machine.');
        }
    }

    private function workerScriptPath(): string
    {
        return $this->basePath.'/httpdocs/public/frankenphp-worker.php';
    }

    private function signalPath(): string
    {
        return $this->basePath.'/httpdocs/'.FrankenphpWorkerService::RESTART_SIGNAL;
    }
}
