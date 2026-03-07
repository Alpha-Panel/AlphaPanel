<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use App\Services\Portainer\ExecResult;
use App\Services\PortainerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DomainMaintenanceToolsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_supervisor_optimize_runs_clear_then_optimize_and_logs_audit(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
        ]);

        $this->mock(PortainerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execInContainer')
                ->once()
                ->with(
                    'frankenphp',
                    Mockery::on(function (mixed $command): bool {
                        if (! is_array($command) || count($command) < 3) {
                            return false;
                        }

                        if ($command[0] !== 'sh' || $command[1] !== '-lc' || ! is_string($command[2])) {
                            return false;
                        }

                        return str_contains($command[2], 'php artisan optimize:clear && php artisan optimize');
                    }),
                    300,
                )
                ->andReturn(new ExecResult(exitCode: 0, output: 'ok', errorOutput: ''));
        });

        $response = $this->actingAs($owner)->post(route('domains.supervisor.optimize', $domain));

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'domain_id' => $domain->id,
            'action' => 'laravel_optimize_refreshed',
        ]);
    }

    public function test_npm_install_runs_and_logs_audit(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
        ]);

        $this->mock(PortainerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execInContainer')
                ->once()
                ->with(
                    'php-code-server',
                    Mockery::on(function (mixed $command): bool {
                        if (! is_array($command) || count($command) < 3) {
                            return false;
                        }

                        return $command[0] === 'sh'
                            && $command[1] === '-lc'
                            && is_string($command[2])
                            && str_contains($command[2], 'npm install');
                    }),
                    1800,
                )
                ->andReturn(new ExecResult(exitCode: 0, output: 'installed', errorOutput: ''));
        });

        $response = $this->actingAs($owner)->post(route('domains.packages.npm.install', $domain));

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'domain_id' => $domain->id,
            'action' => 'npm_install_executed',
        ]);
    }

    public function test_composer_install_no_dev_runs_and_logs_audit(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
        ]);

        $this->mock(PortainerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execInContainer')
                ->once()
                ->with(
                    'php-code-server',
                    Mockery::on(function (mixed $command): bool {
                        if (! is_array($command) || count($command) < 3) {
                            return false;
                        }

                        return $command[0] === 'sh'
                            && $command[1] === '-lc'
                            && is_string($command[2])
                            && str_contains($command[2], 'composer install --no-interaction --no-dev');
                    }),
                    1800,
                )
                ->andReturn(new ExecResult(exitCode: 0, output: 'installed', errorOutput: ''));
        });

        $response = $this->actingAs($owner)->post(route('domains.packages.composer.install', $domain), [
            'no_dev' => true,
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'domain_id' => $domain->id,
            'action' => 'composer_install_executed',
        ]);
    }

    public function test_package_listing_endpoints_return_parsed_packages(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create([
            'owner_user_id' => $owner->id,
        ]);

        $this->mock(PortainerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execInContainer')
                ->once()
                ->andReturn(new ExecResult(
                    exitCode: 0,
                    output: json_encode([
                        'dependencies' => [
                            'axios' => ['version' => '1.8.0'],
                        ],
                    ], JSON_THROW_ON_ERROR),
                    errorOutput: '',
                ));

            $mock->shouldReceive('execInContainer')
                ->once()
                ->andReturn(new ExecResult(
                    exitCode: 0,
                    output: json_encode([
                        'installed' => [
                            ['name' => 'laravel/framework', 'version' => 'v12.0.0'],
                        ],
                    ], JSON_THROW_ON_ERROR),
                    errorOutput: '',
                ));
        });

        $npmResponse = $this->actingAs($owner)->get(route('domains.packages.npm.packages', $domain));
        $npmResponse->assertOk()
            ->assertJsonPath('packages.0.name', 'axios')
            ->assertJsonPath('packages.0.version', '1.8.0');

        $composerResponse = $this->actingAs($owner)->get(route('domains.packages.composer.packages', $domain));
        $composerResponse->assertOk()
            ->assertJsonPath('packages.0.name', 'laravel/framework')
            ->assertJsonPath('packages.0.version', 'v12.0.0');
    }
}
