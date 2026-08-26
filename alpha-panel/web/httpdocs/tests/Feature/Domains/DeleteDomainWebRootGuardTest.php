<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainMode;
use App\Enums\DomainType;
use App\Jobs\DeleteDomainJob;
use App\Models\Domain;
use App\Models\User;
use Database\Seeders\PhpVersionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

/**
 * DeleteDomainJob deletes the domain's web root permanently. `sharesWebRootWithParent()`
 * only ever compares a domain with its own parent, so these cover the two cases where a
 * *different* domain is still served from inside the directory being deleted.
 */
class DeleteDomainWebRootGuardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PhpVersionSeeder::class);
    }

    private function findBlocker(Domain $domain): ?Domain
    {
        $job = new DeleteDomainJob($domain);

        $method = new ReflectionMethod($job, 'findDomainServingFrom');

        return $method->invoke($job, $domain, $domain->getBasePath());
    }

    public function test_a_linked_addon_domain_blocks_deletion_of_its_link_target(): void
    {
        $owner = User::factory()->create();

        $target = Domain::factory()->create([
            'fqdn' => 'link-target-delete.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        Domain::factory()->create([
            'fqdn' => 'addon-of-target.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'mode' => DomainMode::Addon,
            'linked_domain_id' => $target->id,
        ]);

        // The FK is nullOnDelete, so nothing at the database level stops the delete —
        // without this guard the addon's live document root would be erased.
        $blocker = $this->findBlocker($target);

        $this->assertNotNull($blocker);
        $this->assertSame('addon-of-target.com', $blocker->fqdn);
    }

    public function test_a_literal_wildcard_subdomain_blocks_deletion_of_the_wildcard(): void
    {
        $owner = User::factory()->create();

        $apex = Domain::factory()->create([
            'fqdn' => 'slug-collision.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        // getSubdomainSlug() maps the '*' label to the literal string 'wildcard', so
        // both of these resolve to .../subdomains/wildcard.
        Domain::factory()->create([
            'fqdn' => 'wildcard.slug-collision.com',
            'owner_user_id' => $owner->id,
            'parent_domain_id' => $apex->id,
            'type' => DomainType::CaddyWebServer,
            'mode' => DomainMode::Subdomain,
        ]);

        $wildcard = Domain::factory()->create([
            'fqdn' => '*.slug-collision.com',
            'owner_user_id' => $owner->id,
            'parent_domain_id' => $apex->id,
            'type' => DomainType::CaddyWebServer,
            'mode' => DomainMode::WildcardSubdomain,
        ]);

        $blocker = $this->findBlocker($wildcard);

        $this->assertNotNull($blocker);
        $this->assertSame('wildcard.slug-collision.com', $blocker->fqdn);
    }

    public function test_an_unrelated_domain_does_not_block_deletion(): void
    {
        $owner = User::factory()->create();

        $target = Domain::factory()->create([
            'fqdn' => 'lonely-delete.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        Domain::factory()->create([
            'fqdn' => 'unrelated-neighbour.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $this->assertNull($this->findBlocker($target));
    }

    public function test_the_parent_does_not_block_deletion_of_its_own_subdomain(): void
    {
        $owner = User::factory()->create();

        $apex = Domain::factory()->create([
            'fqdn' => 'parent-not-blocking.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $subdomain = Domain::factory()->create([
            'fqdn' => 'api.parent-not-blocking.com',
            'owner_user_id' => $owner->id,
            'parent_domain_id' => $apex->id,
            'type' => DomainType::CaddyWebServer,
            'mode' => DomainMode::Subdomain,
        ]);

        // The apex lives one level up, not inside .../subdomains/api.
        $this->assertNull($this->findBlocker($subdomain));
    }
}
