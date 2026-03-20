<?php

namespace Tests\Feature;

use App\Exceptions\PortainerException;
use App\Jobs\ApplyFirewallRulesJob;
use App\Models\FirewallRule;
use App\Models\User;
use App\Services\Portainer\ExecResult;
use App\Services\PortainerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class FirewallControllerTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Mock PortainerService to return empty/offline data so DB operations
     * don't attempt real container calls (used by getLiveStatus in getDbRules).
     */
    private function mockPortainerOffline(): void
    {
        $this->mock(PortainerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execInContainer')
                ->andThrow(new PortainerException('Container offline'));
        });
    }

    /**
     * Mock PortainerService to return sample iptables -S output for seeding.
     */
    private function mockPortainerForSeed(): void
    {
        $inputOutput = implode("\n", [
            '-P INPUT ACCEPT',
            '-A INPUT -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT',
            '-A INPUT -i lo -j ACCEPT',
            '-A INPUT -p tcp -m tcp --dport 80 -j ACCEPT',
            '-A INPUT -p tcp -m tcp --dport 443 -j ACCEPT',
        ]);

        $outputOutput = '-P OUTPUT ACCEPT';

        $this->mock(PortainerService::class, function (MockInterface $mock) use ($inputOutput, $outputOutput): void {
            $mock->shouldReceive('execInContainer')
                ->andReturnUsing(function (string $container, array $command) use ($inputOutput, $outputOutput): ExecResult {
                    $cmdString = implode(' ', $command);

                    if (str_contains($cmdString, 'iptables -S INPUT')) {
                        return new ExecResult(exitCode: 0, output: $inputOutput, errorOutput: '');
                    }

                    if (str_contains($cmdString, 'iptables -S OUTPUT')) {
                        return new ExecResult(exitCode: 0, output: $outputOutput, errorOutput: '');
                    }

                    return new ExecResult(exitCode: 0, output: '', errorOutput: '');
                });
        });
    }

    public function test_admin_can_view_firewall_page(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mockPortainerOffline();

        $response = $this->actingAs($admin)->get(route('security.firewall.index'));

        $response->assertOk();
    }

    public function test_non_admin_gets_403(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('security.firewall.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_add_rule(): void
    {
        $admin = User::factory()->admin()->create();

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
        $response->assertJsonStructure([
            'success',
            'rule' => ['id', 'chain', 'action', 'protocol', 'port', 'comment'],
        ]);

        $this->assertDatabaseHas('firewall_rules', [
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'port' => 443,
            'comment' => 'HTTPS',
            'created_by' => $admin->id,
        ]);
    }

    public function test_admin_can_get_data(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mockPortainerOffline();

        FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'port' => 80,
            'position' => 1,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson(route('security.firewall.data'));

        $response->assertOk();
        $response->assertJsonStructure([
            'input' => ['policy', 'rules'],
            'output' => ['policy', 'rules'],
            'pending_changes',
            'warnings',
        ]);
    }

    public function test_admin_can_delete_rule(): void
    {
        $admin = User::factory()->admin()->create();

        $rule = FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'port' => 8080,
            'position' => 1,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->deleteJson(route('security.firewall.destroy'), [
            'id' => $rule->id,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertDatabaseMissing('firewall_rules', [
            'id' => $rule->id,
        ]);
    }

    public function test_admin_can_change_policy(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->putJson(route('security.firewall.policy'), [
            'chain' => 'INPUT',
            'policy' => 'DROP',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('firewall_policies', [
            'chain' => 'INPUT',
            'policy' => 'DROP',
        ]);
    }

    public function test_apply_dispatches_job(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('security.firewall.apply'));

        $response->assertOk();
        $response->assertJson(['success' => true]);

        Queue::assertPushed(ApplyFirewallRulesJob::class);
    }

    public function test_admin_can_reorder_rules(): void
    {
        $admin = User::factory()->admin()->create();

        $ruleA = FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'port' => 80,
            'position' => 1,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        $ruleB = FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'port' => 443,
            'position' => 2,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        $ruleC = FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'DROP',
            'protocol' => 'tcp',
            'port' => 22,
            'position' => 3,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        // Reverse the order: C, A, B
        $response = $this->actingAs($admin)->putJson(route('security.firewall.reorder'), [
            'rules' => [$ruleC->id, $ruleA->id, $ruleB->id],
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('firewall_rules', ['id' => $ruleC->id, 'position' => 1]);
        $this->assertDatabaseHas('firewall_rules', ['id' => $ruleA->id, 'position' => 2]);
        $this->assertDatabaseHas('firewall_rules', ['id' => $ruleB->id, 'position' => 3]);
    }

    public function test_admin_can_seed_from_live(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mockPortainerForSeed();

        $response = $this->actingAs($admin)->postJson(route('security.firewall.seed'));

        $response->assertOk();
        $response->assertJson(['success' => true]);

        // The seed should have imported port 80 and 443 rules (skipping RELATED,ESTABLISHED and loopback)
        $this->assertTrue($response->json('imported') >= 2);

        $this->assertDatabaseHas('firewall_rules', [
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'port' => 80,
            'created_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('firewall_rules', [
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'port' => 443,
            'created_by' => $admin->id,
        ]);
    }

    public function test_observer_marks_pending_changes(): void
    {
        $admin = User::factory()->admin()->create();

        Cache::forget('firewall:pending_changes');

        FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'port' => 3306,
            'position' => 1,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        $this->assertTrue(Cache::get('firewall:pending_changes') === true);
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

    public function test_add_rule_with_invalid_action_returns_422(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('security.firewall.store'), [
            'chain' => 'INPUT',
            'action' => 'ALLOW',
            'protocol' => 'tcp',
            'port' => 443,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('action');
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
