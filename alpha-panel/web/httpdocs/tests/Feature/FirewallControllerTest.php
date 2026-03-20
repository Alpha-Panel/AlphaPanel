<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Portainer\ExecResult;
use App\Services\PortainerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use Tests\TestCase;

class FirewallControllerTest extends TestCase
{
    use DatabaseTransactions;

    private string $sampleInputRules;

    private string $sampleOutputRules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sampleInputRules = implode("\n", [
            '-P INPUT ACCEPT',
            '-A INPUT -m state --state RELATED,ESTABLISHED -j ACCEPT',
            '-A INPUT -i lo -j ACCEPT',
            '-A INPUT -s 1.2.3.4/32 -p tcp -m tcp --dport 22 -m comment --comment "SSH" -j ACCEPT',
            '-A INPUT -p tcp -m tcp --dport 80 -j ACCEPT',
        ]);

        $this->sampleOutputRules = '-P OUTPUT ACCEPT';
    }

    private function mockFirewallRules(): void
    {
        $this->mock(PortainerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execInContainer')
                ->andReturnUsing(function (string $container, array $command): ExecResult {
                    $cmdString = implode(' ', $command);

                    if (str_contains($cmdString, 'iptables -S INPUT')) {
                        return new ExecResult(exitCode: 0, output: $this->sampleInputRules, errorOutput: '');
                    }

                    if (str_contains($cmdString, 'iptables -S OUTPUT')) {
                        return new ExecResult(exitCode: 0, output: $this->sampleOutputRules, errorOutput: '');
                    }

                    // Default success for add/delete/policy/persist commands
                    return new ExecResult(exitCode: 0, output: '', errorOutput: '');
                });
        });
    }

    public function test_admin_can_view_firewall_page(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mockFirewallRules();

        $response = $this->actingAs($admin)->get(route('security.firewall.index'));

        $response->assertOk();
    }

    public function test_non_admin_gets_403(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('security.firewall.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_get_firewall_data(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mockFirewallRules();

        $response = $this->actingAs($admin)->getJson(route('security.firewall.data'));

        $response->assertOk();
        $response->assertJsonStructure([
            'input' => ['policy', 'rules'],
            'output' => ['policy', 'rules'],
            'warnings',
            'container_online',
        ]);
        $response->assertJsonPath('input.policy', 'ACCEPT');
        $response->assertJsonPath('container_online', true);
    }

    public function test_admin_can_add_rule(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mockFirewallRules();

        $response = $this->actingAs($admin)->postJson(route('security.firewall.store'), [
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'port' => 443,
            'comment' => 'HTTPS',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_add_rule_with_invalid_chain_returns_422(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('security.firewall.store'), [
            'chain' => 'FORWARD',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'port' => 443,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('chain');
    }

    public function test_admin_can_delete_rule(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mockFirewallRules();

        $response = $this->actingAs($admin)->deleteJson(route('security.firewall.destroy'), [
            'chain' => 'INPUT',
            'rule_number' => 3,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_cannot_delete_protected_rule(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mockFirewallRules();

        // Rule 1 is the RELATED,ESTABLISHED rule which is not deletable
        $response = $this->actingAs($admin)->deleteJson(route('security.firewall.destroy'), [
            'chain' => 'INPUT',
            'rule_number' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_admin_can_change_policy(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mockFirewallRules();

        $response = $this->actingAs($admin)->putJson(route('security.firewall.policy'), [
            'chain' => 'INPUT',
            'policy' => 'DROP',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_invalid_policy_returns_422(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->putJson(route('security.firewall.policy'), [
            'chain' => 'INPUT',
            'policy' => 'REJECT',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('policy');
    }
}
