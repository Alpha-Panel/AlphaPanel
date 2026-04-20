<?php

namespace Tests\Feature;

use App\Enums\DomainType;
use App\Enums\SupervisorType;
use App\Models\Domain;
use App\Models\DomainSupervisor;
use App\Models\User;
use App\Services\ReverbPortAllocator;
use Database\Seeders\PhpVersionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class ReverbPortAllocatorTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PhpVersionSeeder::class);

        config()->set('panel.reverb_port_range.min', 8000);
        config()->set('panel.reverb_port_range.max', 8999);
    }

    public function test_allocates_the_minimum_port_when_none_are_used(): void
    {
        $supervisor = $this->makeReverbSupervisor();

        $port = app(ReverbPortAllocator::class)->allocate($supervisor);

        $this->assertSame(8000, $port);
        $this->assertSame(8000, $supervisor->fresh()->reverb_port);
    }

    public function test_skips_ports_already_held_by_other_supervisors(): void
    {
        $this->makeReverbSupervisor(['reverb_port' => 8000]);
        $this->makeReverbSupervisor(['reverb_port' => 8001]);

        $new = $this->makeReverbSupervisor();

        $port = app(ReverbPortAllocator::class)->allocate($new);

        $this->assertSame(8002, $port);
    }

    public function test_returns_existing_port_without_reallocating(): void
    {
        $supervisor = $this->makeReverbSupervisor(['reverb_port' => 8500]);

        $port = app(ReverbPortAllocator::class)->allocate($supervisor);

        $this->assertSame(8500, $port);
    }

    public function test_throws_when_range_is_exhausted(): void
    {
        config()->set('panel.reverb_port_range.min', 8000);
        config()->set('panel.reverb_port_range.max', 8001);

        $this->makeReverbSupervisor(['reverb_port' => 8000]);
        $this->makeReverbSupervisor(['reverb_port' => 8001]);

        $this->expectException(RuntimeException::class);

        app(ReverbPortAllocator::class)->allocate($this->makeReverbSupervisor());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeReverbSupervisor(array $attributes = []): DomainSupervisor
    {
        $owner = User::factory()->create();

        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => DomainType::CaddyWebServer,
        ]);

        return DomainSupervisor::create(array_merge([
            'domain_id' => $domain->id,
            'type' => SupervisorType::Reverb,
            'enabled' => false,
            'num_procs' => 1,
        ], $attributes));
    }
}
