<?php

namespace Tests\Unit;

use App\ApplyRunStatus;
use App\Models\ApplyRun;
use App\Models\User;
use App\Services\ApplyChangesService;
use App\Services\DomainAutoApplyService;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;
use Throwable;

class DomainAutoApplyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection()->getPdo();
        } catch (Throwable $throwable) {
            $this->markTestSkipped('Database is not reachable for unit tests: '.$throwable->getMessage());
        }

        $this->artisan('migrate:fresh');
    }

    public function test_it_creates_and_executes_an_apply_run(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(ApplyChangesService::class, function (MockInterface $mock) use ($admin): void {
            $mock->shouldReceive('execute')
                ->once()
                ->andReturnUsing(function (ApplyRun $applyRun) use ($admin): ApplyRun {
                    $this->assertSame($admin->id, $applyRun->requested_by);
                    $this->assertSame(ApplyRunStatus::Pending, $applyRun->status);

                    $applyRun->update([
                        'status' => ApplyRunStatus::Succeeded,
                        'finished_at' => now(),
                    ]);

                    return $applyRun->refresh();
                });
        });

        $service = $this->app->make(DomainAutoApplyService::class);
        $applyRun = $service->applyNow($admin->id);

        $this->assertSame(ApplyRunStatus::Succeeded, $applyRun->status);
        $this->assertDatabaseHas('apply_runs', [
            'id' => $applyRun->id,
            'requested_by' => $admin->id,
            'status' => ApplyRunStatus::Succeeded->value,
            'dry_run' => false,
        ]);
    }
}
