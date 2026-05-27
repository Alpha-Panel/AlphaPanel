<?php

namespace Tests\Unit;

use Tests\TestCase;
use ZipArchive;

/**
 * Exercises the standalone fm-worker.php script directly via the PHP CLI,
 * without runuser. This proves the worker logic (path traversal, list, read,
 * write, mkdir, delete, rename, chmod, compress, decompress) is correct
 * independently of the LocalDomainFileManagerService wrapper.
 */
class FileManagerWorkerTest extends TestCase
{
    private string $tmpRoot;

    private string $workerPath;

    private string $phpBin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workerPath = base_path('scripts/fm-worker.php');
        $this->assertFileExists($this->workerPath);

        $this->phpBin = $this->resolvePhpBinary();
        $this->tmpRoot = sys_get_temp_dir().'/fm-worker-test-'.bin2hex(random_bytes(6));

        if (! mkdir($this->tmpRoot, 0755, true)) {
            $this->fail("Failed to create tmp root: {$this->tmpRoot}");
        }
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpRoot);

        parent::tearDown();
    }

    public function test_list_returns_sorted_entries(): void
    {
        file_put_contents($this->tmpRoot.'/b.txt', 'b');
        file_put_contents($this->tmpRoot.'/a.txt', 'a');
        mkdir($this->tmpRoot.'/sub');

        $out = $this->invoke(['--action=list', '--path=']);
        $items = json_decode($out['stdout'], true);

        $this->assertSame(0, $out['exit']);
        $this->assertIsArray($items);
        $this->assertCount(3, $items);
        // Directories first, then files alphabetically.
        $this->assertSame('sub', $items[0]['name']);
        $this->assertSame('directory', $items[0]['type']);
        $this->assertSame('a.txt', $items[1]['name']);
        $this->assertSame('b.txt', $items[2]['name']);
    }

    public function test_write_then_read_roundtrip(): void
    {
        $payload = "hello\nworld";

        $w = $this->invoke(['--action=write', '--path=hello.txt'], $payload);
        $this->assertSame(0, $w['exit'], $w['stderr']);

        $r = $this->invoke(['--action=read', '--path=hello.txt']);
        $this->assertSame(0, $r['exit']);
        $this->assertSame($payload, $r['stdout']);
    }

    public function test_mkdir_and_delete(): void
    {
        $m = $this->invoke(['--action=mkdir', '--path=nested/inner']);
        $this->assertSame(0, $m['exit'], $m['stderr']);
        $this->assertDirectoryExists($this->tmpRoot.'/nested/inner');

        $d = $this->invoke(['--action=delete', '--paths='.json_encode(['nested'])]);
        $this->assertSame(0, $d['exit'], $d['stderr']);
        $this->assertDirectoryDoesNotExist($this->tmpRoot.'/nested');
    }

    public function test_rename(): void
    {
        file_put_contents($this->tmpRoot.'/old.txt', 'x');

        $r = $this->invoke(['--action=rename', '--from=old.txt', '--to=new.txt']);
        $this->assertSame(0, $r['exit'], $r['stderr']);
        $this->assertFileDoesNotExist($this->tmpRoot.'/old.txt');
        $this->assertFileExists($this->tmpRoot.'/new.txt');
    }

    public function test_chmod_changes_mode(): void
    {
        file_put_contents($this->tmpRoot.'/f.txt', 'x');
        chmod($this->tmpRoot.'/f.txt', 0644);

        $r = $this->invoke(['--action=chmod', '--path=f.txt', '--mode=600']);
        $this->assertSame(0, $r['exit'], $r['stderr']);
        $this->assertSame('0600', sprintf('%04o', fileperms($this->tmpRoot.'/f.txt') & 07777));
    }

    public function test_compress_then_decompress_roundtrip(): void
    {
        mkdir($this->tmpRoot.'/src');
        file_put_contents($this->tmpRoot.'/src/one.txt', 'one');
        file_put_contents($this->tmpRoot.'/src/two.txt', 'two');

        $c = $this->invoke([
            '--action=compress',
            '--paths='.json_encode(['src']),
            '--zip=archive.zip',
        ]);
        $this->assertSame(0, $c['exit'], $c['stderr']);
        $this->assertFileExists($this->tmpRoot.'/archive.zip');

        mkdir($this->tmpRoot.'/out');
        $d = $this->invoke([
            '--action=decompress',
            '--zip=archive.zip',
            '--dest=out',
        ]);
        $this->assertSame(0, $d['exit'], $d['stderr']);
        $this->assertFileExists($this->tmpRoot.'/out/src/one.txt');
        $this->assertSame('one', file_get_contents($this->tmpRoot.'/out/src/one.txt'));
        $this->assertSame('two', file_get_contents($this->tmpRoot.'/out/src/two.txt'));
    }

    public function test_path_traversal_is_rejected(): void
    {
        $r = $this->invoke(['--action=read', '--path=../etc/passwd']);
        // Normalized to "etc/passwd" → not found.
        $this->assertNotSame(0, $r['exit']);
    }

    public function test_zip_slip_entries_are_skipped(): void
    {
        $maliciousZip = $this->tmpRoot.'/evil.zip';
        $zip = new ZipArchive;
        $zip->open($maliciousZip, ZipArchive::CREATE);
        $zip->addFromString('../escape.txt', 'pwned');
        $zip->addFromString('safe.txt', 'fine');
        $zip->close();

        mkdir($this->tmpRoot.'/extract');
        $r = $this->invoke([
            '--action=decompress',
            '--zip=evil.zip',
            '--dest=extract',
        ]);
        $this->assertSame(0, $r['exit'], $r['stderr']);
        $this->assertFileExists($this->tmpRoot.'/extract/safe.txt');
        $this->assertFileDoesNotExist($this->tmpRoot.'/escape.txt');
    }

    public function test_exists_returns_correct_flag(): void
    {
        file_put_contents($this->tmpRoot.'/yes.txt', 'x');

        $r1 = $this->invoke(['--action=exists', '--path=yes.txt']);
        $this->assertSame('1', $r1['stdout']);

        $r2 = $this->invoke(['--action=exists', '--path=nope.txt']);
        $this->assertSame('0', $r2['stdout']);
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function invoke(array $extraArgs, ?string $stdin = null): array
    {
        $cmd = [
            $this->phpBin,
            $this->workerPath,
            '--root='.$this->tmpRoot,
        ];

        foreach ($extraArgs as $a) {
            $cmd[] = $a;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        $this->assertIsResource($process);

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function resolvePhpBinary(): string
    {
        foreach (['/usr/local/bin/php', '/usr/bin/php', PHP_BINARY] as $candidate) {
            if ($candidate && is_executable($candidate)) {
                return $candidate;
            }
        }

        $this->markTestSkipped('PHP CLI binary not found.');
    }

    private function rmrf(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        if (is_dir($path) && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $e) {
                if ($e === '.' || $e === '..') {
                    continue;
                }
                $this->rmrf($path.'/'.$e);
            }
            @rmdir($path);

            return;
        }

        @unlink($path);
    }
}
