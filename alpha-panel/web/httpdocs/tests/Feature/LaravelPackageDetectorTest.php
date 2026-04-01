<?php

namespace Tests\Feature;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\User;
use App\Services\LaravelPackageDetector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LaravelPackageDetectorTest extends TestCase
{
    use DatabaseTransactions;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/package-detector-test-'.uniqid();
        mkdir($this->tempDir.'/httpdocs', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function test_is_installed_returns_true_for_existing_package(): void
    {
        $domain = $this->createDomainWithComposerLock([
            ['name' => 'laravel/framework'],
            ['name' => 'laravel/reverb'],
            ['name' => 'laravel/pulse'],
        ]);

        $detector = new LaravelPackageDetector;

        $this->assertTrue($detector->isInstalled($domain, 'laravel/reverb'));
        $this->assertTrue($detector->isInstalled($domain, 'laravel/pulse'));
        $this->assertTrue($detector->isInstalled($domain, 'laravel/framework'));
    }

    public function test_is_installed_returns_false_for_missing_package(): void
    {
        $domain = $this->createDomainWithComposerLock([
            ['name' => 'laravel/framework'],
        ]);

        $detector = new LaravelPackageDetector;

        $this->assertFalse($detector->isInstalled($domain, 'laravel/reverb'));
        $this->assertFalse($detector->isInstalled($domain, 'laravel/horizon'));
    }

    public function test_is_installed_returns_false_when_no_composer_lock(): void
    {
        $domain = $this->createDomainWithRootPath();

        $detector = new LaravelPackageDetector;

        $this->assertFalse($detector->isInstalled($domain, 'laravel/framework'));
    }

    public function test_get_installed_packages_returns_correct_map(): void
    {
        $domain = $this->createDomainWithComposerLock([
            ['name' => 'laravel/framework'],
            ['name' => 'laravel/reverb'],
        ]);

        $detector = new LaravelPackageDetector;

        $result = $detector->getInstalledPackages($domain, [
            'laravel/framework',
            'laravel/reverb',
            'laravel/horizon',
        ]);

        $this->assertTrue($result['laravel/framework']);
        $this->assertTrue($result['laravel/reverb']);
        $this->assertFalse($result['laravel/horizon']);
    }

    public function test_is_octane_configured_checks_config_file(): void
    {
        $domain = $this->createDomainWithComposerLock([
            ['name' => 'laravel/octane'],
        ]);

        $detector = new LaravelPackageDetector;

        $this->assertFalse($detector->isOctaneConfigured($domain));

        mkdir($this->tempDir.'/httpdocs/config', 0755, true);
        file_put_contents($this->tempDir.'/httpdocs/config/octane.php', '<?php return [];');

        // Clear the in-memory cache by using a fresh instance
        $detector2 = new LaravelPackageDetector;

        $this->assertTrue($detector2->isOctaneConfigured($domain));
    }

    public function test_is_octane_configured_returns_false_without_package(): void
    {
        $domain = $this->createDomainWithComposerLock([
            ['name' => 'laravel/framework'],
        ]);

        mkdir($this->tempDir.'/httpdocs/config', 0755, true);
        file_put_contents($this->tempDir.'/httpdocs/config/octane.php', '<?php return [];');

        $detector = new LaravelPackageDetector;

        $this->assertFalse($detector->isOctaneConfigured($domain));
    }

    public function test_check_supervisor_requirements_queue_always_available(): void
    {
        $domain = $this->createDomainWithComposerLock([
            ['name' => 'laravel/framework'],
        ]);

        $detector = new LaravelPackageDetector;
        $result = $detector->checkSupervisorRequirements($domain);

        $this->assertTrue($result['queue']['available']);
        $this->assertNull($result['queue']['package']);
    }

    public function test_check_supervisor_requirements_reverb_depends_on_package(): void
    {
        $domainWithout = $this->createDomainWithComposerLock([
            ['name' => 'laravel/framework'],
        ]);

        $detector = new LaravelPackageDetector;
        $result = $detector->checkSupervisorRequirements($domainWithout);

        $this->assertFalse($result['reverb']['available']);
        $this->assertSame('laravel/reverb', $result['reverb']['package']);

        $domainWith = $this->createDomainWithComposerLock([
            ['name' => 'laravel/framework'],
            ['name' => 'laravel/reverb'],
        ], 'reverb-domain.com');

        $detector2 = new LaravelPackageDetector;
        $result2 = $detector2->checkSupervisorRequirements($domainWith);

        $this->assertTrue($result2['reverb']['available']);
    }

    public function test_check_supervisor_requirements_horizon_depends_on_package(): void
    {
        $domainWithout = $this->createDomainWithComposerLock([
            ['name' => 'laravel/framework'],
        ]);

        $detector = new LaravelPackageDetector;
        $result = $detector->checkSupervisorRequirements($domainWithout);

        $this->assertFalse($result['horizon']['available']);
        $this->assertSame('laravel/horizon', $result['horizon']['package']);
    }

    public function test_check_supervisor_requirements_pulse_depends_on_package(): void
    {
        $domainWith = $this->createDomainWithComposerLock([
            ['name' => 'laravel/framework'],
            ['name' => 'laravel/pulse'],
        ]);

        $detector = new LaravelPackageDetector;
        $result = $detector->checkSupervisorRequirements($domainWith);

        $this->assertTrue($result['pulse']['available']);
        $this->assertSame('laravel/pulse', $result['pulse']['package']);
    }

    public function test_invalid_composer_lock_returns_false(): void
    {
        $domain = $this->createDomainWithRootPath();
        file_put_contents($this->tempDir.'/httpdocs/composer.lock', 'not valid json');

        $detector = new LaravelPackageDetector;

        $this->assertFalse($detector->isInstalled($domain, 'laravel/framework'));
    }

    // ─── Helpers ───────────────────────────────────────────────

    /**
     * Create a domain with a temp root path and a composer.lock file.
     *
     * @param  list<array{name: string}>  $packages
     */
    private function createDomainWithComposerLock(array $packages, string $fqdn = 'detector-test.com'): Domain
    {
        $domain = $this->createDomainWithRootPath($fqdn);

        $composerLock = json_encode([
            'packages' => $packages,
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($this->tempDir.'/httpdocs/composer.lock', $composerLock);

        return $domain;
    }

    /**
     * Create a domain that points to the temp directory.
     */
    private function createDomainWithRootPath(string $fqdn = 'detector-test.com'): Domain
    {
        $owner = User::factory()->create();

        return Domain::factory()->create([
            'fqdn' => $fqdn,
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'root_path' => $this->tempDir.'/httpdocs/public',
        ]);
    }

    /**
     * Recursively delete a directory.
     */
    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($dir);
    }
}
