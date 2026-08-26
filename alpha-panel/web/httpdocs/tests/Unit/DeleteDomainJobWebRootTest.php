<?php

namespace Tests\Unit;

use App\Enums\DomainMode;
use App\Jobs\DeleteDomainJob;
use App\Models\Domain;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

/**
 * `removeWebRoot()` performs an irreversible recursive delete of hosted files, so
 * every guard that stops it gets its own test.
 *
 * These exercise `deletableWebRootPath()`, the pure half of the guard chain: it
 * returns the path to delete, or null when a guard refuses. The cross-domain guard
 * needs the database and is covered by
 * tests/Feature/Domains/DeleteDomainWebRootGuardTest.php.
 */
class DeleteDomainJobWebRootTest extends TestCase
{
    private function makeDomain(string $fqdn, DomainMode $mode = DomainMode::Main): Domain
    {
        $domain = new Domain(['fqdn' => $fqdn, 'mode' => $mode]);
        $domain->owner_user_id = 1;

        return $domain;
    }

    private function deletablePath(Domain $domain): ?string
    {
        $job = new DeleteDomainJob($domain);

        $method = new ReflectionMethod($job, 'deletableWebRootPath');

        return $method->invoke($job, $domain);
    }

    public function test_it_returns_the_web_root_of_an_ordinary_domain(): void
    {
        Log::spy();

        $this->assertSame(
            '/var/www/vhosts/example.com',
            $this->deletablePath($this->makeDomain('example.com'))
        );
    }

    public function test_it_returns_the_subdomains_own_directory_not_the_parents(): void
    {
        Log::spy();

        $subdomain = $this->makeDomain('api.example.com', DomainMode::Subdomain);
        $subdomain->parent_domain_id = 1;
        $subdomain->setRelation('parentDomain', $this->makeDomain('example.com'));

        $this->assertSame(
            '/var/www/vhosts/example.com/subdomains/api',
            $this->deletablePath($subdomain)
        );
    }

    public function test_it_refuses_a_system_reserved_domain(): void
    {
        Log::spy();
        config(['panel.system_reserved_domains' => ['Panel.Example.Com']]);

        // Matched case-insensitively — the reserved list comes from .env.
        $this->assertNull($this->deletablePath($this->makeDomain('panel.example.com')));
    }

    public function test_it_refuses_the_shared_catchall_directory(): void
    {
        Log::spy();

        $this->assertNull(
            $this->deletablePath($this->makeDomain('*.example.com', DomainMode::WildcardCatchall))
        );
    }

    public function test_it_refuses_the_vhost_root_itself(): void
    {
        Log::spy();

        // An empty fqdn resolves to the vhost root, which would wipe every site.
        $this->assertNull($this->deletablePath($this->makeDomain('')));
    }

    public function test_it_refuses_a_path_containing_traversal_segments(): void
    {
        Log::spy();

        $this->assertNull($this->deletablePath($this->makeDomain('../../etc')));
    }
}
