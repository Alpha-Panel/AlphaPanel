<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainMode;
use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\User;
use Database\Seeders\PhpVersionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AddonDomainSharesRootTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PhpVersionSeeder::class);
    }

    public function test_addon_domain_web_root_path_delegates_to_linked_domain(): void
    {
        $owner = User::factory()->create();

        $linked = Domain::factory()->create([
            'fqdn' => 'linked-root.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'root_path' => null,
        ]);

        $addon = Domain::factory()->create([
            'fqdn' => 'addon-shares-root.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'mode' => DomainMode::Addon,
            'linked_domain_id' => $linked->id,
        ]);

        $addon->load('linkedDomain');

        $this->assertSame(
            $linked->getWebRootPath(),
            $addon->getWebRootPath(),
        );
    }

    public function test_addon_domain_inherits_custom_root_path_from_linked_domain(): void
    {
        $owner = User::factory()->create();

        $linked = Domain::factory()->create([
            'fqdn' => 'custom-root-linked.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'root_path' => '/var/www/vhosts/custom-root-linked.com/httpdocs/shared',
        ]);

        $addon = Domain::factory()->create([
            'fqdn' => 'addon-custom-root.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'mode' => DomainMode::Addon,
            'linked_domain_id' => $linked->id,
        ]);

        $addon->load('linkedDomain');

        $this->assertSame(
            '/var/www/vhosts/custom-root-linked.com/httpdocs/shared',
            $addon->getWebRootPath(),
        );
    }

    public function test_deleting_linked_domain_nullifies_linked_domain_id_on_addon(): void
    {
        $owner = User::factory()->create();

        $linked = Domain::factory()->create([
            'fqdn' => 'to-be-deleted-linked.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        $addon = Domain::factory()->create([
            'fqdn' => 'addon-after-delete.com',
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
            'mode' => DomainMode::Addon,
            'linked_domain_id' => $linked->id,
        ]);

        $this->assertNotNull($addon->linked_domain_id);

        $linked->delete();
        $addon->refresh();

        $this->assertNull($addon->linked_domain_id);
    }
}
