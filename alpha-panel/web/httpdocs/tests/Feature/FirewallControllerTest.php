<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FirewallRule;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FirewallControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_view_firewall_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('security.firewall.index'));

        $response->assertOk();
    }

    public function test_non_admin_gets_403(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('security.firewall.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_add_firewall_rule(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('security.firewall.store'), [
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'sources' => ['192.168.1.100'],
            'ports' => [80],
            'comment' => 'test rule',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'count' => 1]);

        $rule = FirewallRule::where('created_by', $admin->id)->latest('id')->first();
        $this->assertNotNull($rule);
        $this->assertSame('INPUT', $rule->chain);
        $this->assertSame('ACCEPT', $rule->action);
        $this->assertSame('tcp', $rule->protocol);
        $this->assertSame(['192.168.1.100'], $rule->sources);
        $this->assertSame([80], $rule->ports);
        $this->assertSame('test rule', $rule->comment);
    }

    public function test_admin_can_add_rule_with_multiple_sources(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('security.firewall.store'), [
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'all',
            'sources' => ['192.168.1.1', '10.0.0.5', '203.0.113.50'],
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'count' => 1]);

        $rule = FirewallRule::where('created_by', $admin->id)->latest('id')->first();
        $this->assertNotNull($rule);
        $this->assertSame(['192.168.1.1', '10.0.0.5', '203.0.113.50'], $rule->sources);
        $this->assertNull($rule->ports);
    }

    public function test_admin_can_add_rule_with_multiple_ports(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('security.firewall.store'), [
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'ports' => [80, 443],
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'count' => 1]);

        $rule = FirewallRule::where('created_by', $admin->id)->latest('id')->first();
        $this->assertNotNull($rule);
        $this->assertNull($rule->sources);
        $this->assertSame([80, 443], $rule->ports);
    }

    public function test_admin_can_add_rule_with_sources_and_ports(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('security.firewall.store'), [
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'sources' => ['192.168.1.1', '10.0.0.5'],
            'ports' => [80, 443],
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'count' => 1]);

        $rule = FirewallRule::where('created_by', $admin->id)->latest('id')->first();
        $this->assertNotNull($rule);
        $this->assertSame(['192.168.1.1', '10.0.0.5'], $rule->sources);
        $this->assertSame([80, 443], $rule->ports);
    }

    public function test_admin_can_add_rule_with_no_source_no_port(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('security.firewall.store'), [
            'chain' => 'INPUT',
            'action' => 'DROP',
            'protocol' => 'all',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'count' => 1]);

        $rule = FirewallRule::where('created_by', $admin->id)->latest('id')->first();
        $this->assertNotNull($rule);
        $this->assertSame('INPUT', $rule->chain);
        $this->assertSame('DROP', $rule->action);
        $this->assertNull($rule->sources);
        $this->assertNull($rule->ports);
    }

    public function test_admin_can_update_firewall_rule(): void
    {
        $admin = User::factory()->admin()->create();

        $rule = FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'sources' => ['192.168.1.1'],
            'ports' => [80],
            'comment' => 'original',
            'position' => 1,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->putJson(route('security.firewall.update', ['rule' => $rule->id]), [
            'chain' => 'INPUT',
            'action' => 'DROP',
            'protocol' => 'tcp',
            'sources' => ['10.0.0.1', '10.0.0.2'],
            'ports' => [443, 8080],
            'comment' => 'updated',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $rule->refresh();
        $this->assertSame('DROP', $rule->action);
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $rule->sources);
        $this->assertSame([443, 8080], $rule->ports);
        $this->assertSame('updated', $rule->comment);
    }

    public function test_admin_can_get_data(): void
    {
        $admin = User::factory()->admin()->create();

        FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'ports' => [80],
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
            'ports' => [8080],
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

    public function test_admin_can_preview_commands(): void
    {
        $admin = User::factory()->admin()->create();

        FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'sources' => ['192.168.1.100'],
            'ports' => [80],
            'comment' => 'HTTP access',
            'position' => 1,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson(route('security.firewall.preview'));

        $response->assertOk();
        $response->assertJsonStructure(['script']);

        $script = $response->json('script');
        $this->assertStringContainsString('ufw --force reset', $script);
        $this->assertStringContainsString('ufw default', $script);
        $this->assertStringContainsString('ufw allow in', $script);
        $this->assertStringContainsString('192.168.1.100', $script);
        $this->assertStringContainsString('port', $script);
        $this->assertStringContainsString('80', $script);
        // Full script now includes the enable command
        $this->assertStringContainsString('ufw --force enable', $script);
        // Must include FORWARD ACCEPT for Docker safety
        $this->assertStringContainsString('iptables -P FORWARD ACCEPT', $script);
    }

    public function test_non_admin_cannot_preview_commands(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(route('security.firewall.preview'));

        $response->assertForbidden();
    }

    public function test_preview_contains_correct_ufw_commands_for_rules(): void
    {
        $admin = User::factory()->admin()->create();

        FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'all',
            'sources' => ['10.0.0.0/24'],
            'position' => 1,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'ports' => [443],
            'position' => 2,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'DROP',
            'protocol' => 'all',
            'sources' => ['5.6.7.8'],
            'position' => 3,
            'enabled' => false, // disabled — should NOT appear
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson(route('security.firewall.preview'));

        $script = $response->json('script');

        // Enabled rules should be present
        $this->assertStringContainsString('10.0.0.0/24', $script);
        $this->assertStringContainsString('443', $script);
        $this->assertStringContainsString('tcp', $script);

        // Disabled rule should NOT be present
        $this->assertStringNotContainsString('5.6.7.8', $script);
    }

    public function test_admin_can_reorder_rules(): void
    {
        $admin = User::factory()->admin()->create();

        $ruleA = FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'ports' => [80],
            'position' => 1,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        $ruleB = FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'ports' => [443],
            'position' => 2,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        $ruleC = FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'DROP',
            'protocol' => 'tcp',
            'ports' => [22],
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

    public function test_observer_marks_pending_changes(): void
    {
        $admin = User::factory()->admin()->create();

        Cache::forget('firewall:pending_changes');

        FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'ports' => [3306],
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
            'ports' => [443],
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
            'ports' => [443],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('action');
    }

    public function test_add_rule_with_invalid_source_ip_returns_422(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('security.firewall.store'), [
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'sources' => ['not-an-ip'],
            'ports' => [80],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('sources.0');
    }

    public function test_add_rule_with_invalid_port_returns_422(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('security.firewall.store'), [
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'ports' => [99999],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('ports.0');
    }

    public function test_admin_can_add_rule_with_cidr_source(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('security.firewall.store'), [
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'all',
            'sources' => ['10.10.0.0/24', '192.168.1.0/16'],
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'count' => 1]);

        $rule = FirewallRule::where('created_by', $admin->id)->latest('id')->first();
        $this->assertNotNull($rule);
        $this->assertSame(['10.10.0.0/24', '192.168.1.0/16'], $rule->sources);
    }

    public function test_admin_can_update_rule_clearing_sources_and_ports(): void
    {
        $admin = User::factory()->admin()->create();

        $rule = FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'sources' => ['192.168.1.1'],
            'ports' => [80],
            'comment' => 'test',
            'position' => 1,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        // Update with null sources/ports (clear them)
        $response = $this->actingAs($admin)->putJson(route('security.firewall.update', ['rule' => $rule->id]), [
            'chain' => 'INPUT',
            'action' => 'DROP',
            'protocol' => 'all',
            'sources' => null,
            'ports' => null,
            'comment' => 'cleared',
        ]);

        $response->assertOk();

        $rule->refresh();
        $this->assertSame('DROP', $rule->action);
        $this->assertSame('all', $rule->protocol);
        $this->assertNull($rule->sources);
        $this->assertNull($rule->ports);
        $this->assertSame('cleared', $rule->comment);
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

    // ── SSH Warning Suppression Tests ──────────────────────────────

    public function test_ssh_warning_shown_when_drop_policy_and_no_ssh_rule(): void
    {
        $admin = User::factory()->admin()->create();

        // Clean pre-existing INPUT ACCEPT rules (rolled back after test)
        FirewallRule::input()->where('action', 'ACCEPT')->delete();

        $this->actingAs($admin)->putJson(route('security.firewall.policy'), [
            'chain' => 'INPUT',
            'policy' => 'DROP',
        ])->assertOk();

        $response = $this->actingAs($admin)->getJson(route('security.firewall.data'));

        $response->assertOk();
        $warnings = $response->json('warnings');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('SSH', $warnings[0]);
    }

    public function test_ssh_warning_hidden_when_blanket_ip_allow_matches_client(): void
    {
        $admin = User::factory()->admin()->create();

        // Clean pre-existing INPUT ACCEPT rules (rolled back after test)
        FirewallRule::input()->where('action', 'ACCEPT')->delete();

        $this->actingAs($admin)->putJson(route('security.firewall.policy'), [
            'chain' => 'INPUT',
            'policy' => 'DROP',
        ])->assertOk();

        // Blanket allow (no ports) for the test client IP (127.0.0.1 in tests)
        FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'all',
            'sources' => ['127.0.0.1'],
            'ports' => null,
            'position' => 1,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson(route('security.firewall.data'));

        $response->assertOk();
        $warnings = $response->json('warnings');
        $this->assertEmpty($warnings);
    }

    public function test_ssh_warning_hidden_when_cidr_blanket_allow_matches_client(): void
    {
        $admin = User::factory()->admin()->create();

        // Clean pre-existing INPUT ACCEPT rules (rolled back after test)
        FirewallRule::input()->where('action', 'ACCEPT')->delete();

        $this->actingAs($admin)->putJson(route('security.firewall.policy'), [
            'chain' => 'INPUT',
            'policy' => 'DROP',
        ])->assertOk();

        // CIDR range that includes 127.0.0.1
        FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'all',
            'sources' => ['127.0.0.0/8'],
            'ports' => null,
            'position' => 1,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson(route('security.firewall.data'));

        $response->assertOk();
        $warnings = $response->json('warnings');
        $this->assertEmpty($warnings);
    }

    public function test_ssh_warning_still_shown_when_ip_allow_has_ports(): void
    {
        $admin = User::factory()->admin()->create();

        // Clean pre-existing INPUT ACCEPT rules (rolled back after test)
        FirewallRule::input()->where('action', 'ACCEPT')->delete();

        $this->actingAs($admin)->putJson(route('security.firewall.policy'), [
            'chain' => 'INPUT',
            'policy' => 'DROP',
        ])->assertOk();

        // IP allow with specific ports (NOT blanket) — should still warn
        FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'sources' => ['127.0.0.1'],
            'ports' => [80, 443],
            'position' => 1,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson(route('security.firewall.data'));

        $response->assertOk();
        $warnings = $response->json('warnings');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('SSH', $warnings[0]);
    }

    // ── Audit Logging Tests ────────────────────────────────────────

    public function test_store_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson(route('security.firewall.store'), [
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'sources' => ['192.168.1.100'],
            'ports' => [80],
            'comment' => 'audit test',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'firewall_rule_created',
        ]);
    }

    public function test_update_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();

        $rule = FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'ports' => [80],
            'position' => 1,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->putJson(route('security.firewall.update', ['rule' => $rule->id]), [
            'chain' => 'INPUT',
            'action' => 'DROP',
            'protocol' => 'tcp',
            'ports' => [443],
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'firewall_rule_updated',
        ]);

        $log = AuditLog::where('action', 'firewall_rule_updated')->latest('id')->first();
        $this->assertNotNull($log);
        $details = json_decode($log->details, true);
        $this->assertArrayHasKey('old', $details);
        $this->assertArrayHasKey('new', $details);
    }

    public function test_destroy_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();

        $rule = FirewallRule::create([
            'chain' => 'INPUT',
            'action' => 'ACCEPT',
            'protocol' => 'tcp',
            'ports' => [8080],
            'position' => 1,
            'enabled' => true,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->deleteJson(route('security.firewall.destroy'), [
            'id' => $rule->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'firewall_rule_deleted',
        ]);
    }

    public function test_policy_change_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson(route('security.firewall.policy'), [
            'chain' => 'INPUT',
            'policy' => 'DROP',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'firewall_policy_changed',
        ]);

        $log = AuditLog::where('action', 'firewall_policy_changed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('INPUT', $log->summary);
        $this->assertStringContainsString('DROP', $log->summary);
    }

    public function test_reorder_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();

        $ruleA = FirewallRule::create([
            'chain' => 'INPUT', 'action' => 'ACCEPT', 'protocol' => 'tcp',
            'ports' => [80], 'position' => 1, 'enabled' => true, 'created_by' => $admin->id,
        ]);
        $ruleB = FirewallRule::create([
            'chain' => 'INPUT', 'action' => 'ACCEPT', 'protocol' => 'tcp',
            'ports' => [443], 'position' => 2, 'enabled' => true, 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->putJson(route('security.firewall.reorder'), [
            'rules' => [$ruleB->id, $ruleA->id],
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'firewall_rules_reordered',
        ]);
    }

    public function test_toggle_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();

        $rule = FirewallRule::create([
            'chain' => 'INPUT', 'action' => 'ACCEPT', 'protocol' => 'tcp',
            'ports' => [80], 'position' => 1, 'enabled' => true, 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->putJson(route('security.firewall.toggle', ['rule' => $rule->id]), [
            'enabled' => false,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'firewall_rule_toggled',
        ]);

        $log = AuditLog::where('action', 'firewall_rule_toggled')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('disabled', $log->summary);
    }
}
