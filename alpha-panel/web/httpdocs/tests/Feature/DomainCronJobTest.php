<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\DomainCronJob;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DomainCronJobTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_view_cron_jobs_page(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($owner)->get(route('domains.cron-jobs.index', $domain));

        $response->assertOk();
    }

    public function test_other_user_cannot_view_cron_jobs_page(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($other)->get(route('domains.cron-jobs.index', $domain));

        $response->assertForbidden();
    }

    public function test_admin_can_view_any_domain_cron_jobs(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($admin)->get(route('domains.cron-jobs.index', $domain));

        $response->assertOk();
    }

    public function test_owner_can_create_cron_job(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($owner)->postJson(route('domains.cron-jobs.store', $domain), [
            'command' => 'php artisan schedule:run',
            'schedule' => '*/5 * * * *',
            'description' => 'Run scheduler every 5 minutes',
        ]);

        $response->assertCreated();
        $response->assertJson(['status' => 'success']);
        $this->assertDatabaseHas('domain_cron_jobs', [
            'domain_id' => $domain->id,
            'command' => 'php artisan schedule:run',
            'schedule' => '*/5 * * * *',
            'description' => 'Run scheduler every 5 minutes',
            'enabled' => true,
        ]);
    }

    public function test_cron_job_requires_command(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($owner)->postJson(route('domains.cron-jobs.store', $domain), [
            'schedule' => '*/5 * * * *',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('command');
    }

    public function test_cron_job_requires_valid_schedule(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($owner)->postJson(route('domains.cron-jobs.store', $domain), [
            'command' => 'php test.php',
            'schedule' => 'invalid-cron',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('schedule');
    }

    public function test_owner_can_update_cron_job(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);
        $cronJob = DomainCronJob::factory()->create(['domain_id' => $domain->id]);

        $response = $this->actingAs($owner)->putJson(
            route('domains.cron-jobs.update', [$domain, $cronJob]),
            [
                'command' => 'php artisan cache:clear',
                'schedule' => '0 0 * * *',
                'description' => 'Clear cache daily',
            ],
        );

        $response->assertOk();
        $response->assertJson(['status' => 'success']);
        $this->assertDatabaseHas('domain_cron_jobs', [
            'id' => $cronJob->id,
            'command' => 'php artisan cache:clear',
            'schedule' => '0 0 * * *',
        ]);
    }

    public function test_owner_can_delete_cron_job(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);
        $cronJob = DomainCronJob::factory()->create(['domain_id' => $domain->id]);

        $response = $this->actingAs($owner)->deleteJson(
            route('domains.cron-jobs.destroy', [$domain, $cronJob]),
        );

        $response->assertOk();
        $response->assertJson(['status' => 'success']);
        $this->assertDatabaseMissing('domain_cron_jobs', ['id' => $cronJob->id]);
    }

    public function test_owner_can_toggle_cron_job(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);
        $cronJob = DomainCronJob::factory()->create([
            'domain_id' => $domain->id,
            'enabled' => true,
        ]);

        $response = $this->actingAs($owner)->postJson(
            route('domains.cron-jobs.toggle', [$domain, $cronJob]),
        );

        $response->assertOk();
        $response->assertJson(['status' => 'success', 'enabled' => false]);

        $this->assertDatabaseHas('domain_cron_jobs', [
            'id' => $cronJob->id,
            'enabled' => false,
        ]);
    }

    public function test_toggle_disabled_to_enabled(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);
        $cronJob = DomainCronJob::factory()->disabled()->create([
            'domain_id' => $domain->id,
        ]);

        $response = $this->actingAs($owner)->postJson(
            route('domains.cron-jobs.toggle', [$domain, $cronJob]),
        );

        $response->assertOk();
        $response->assertJson(['enabled' => true]);
    }

    public function test_cannot_update_cron_job_of_another_domain(): void
    {
        $owner = User::factory()->create();
        $domain1 = Domain::factory()->create(['owner_user_id' => $owner->id]);
        $domain2 = Domain::factory()->create(['owner_user_id' => $owner->id]);
        $cronJob = DomainCronJob::factory()->create(['domain_id' => $domain2->id]);

        $response = $this->actingAs($owner)->putJson(
            route('domains.cron-jobs.update', [$domain1, $cronJob]),
            [
                'command' => 'php test.php',
                'schedule' => '* * * * *',
            ],
        );

        $response->assertNotFound();
    }

    public function test_other_user_cannot_create_cron_job(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($other)->postJson(route('domains.cron-jobs.store', $domain), [
            'command' => 'php test.php',
            'schedule' => '* * * * *',
        ]);

        $response->assertForbidden();
    }

    public function test_owner_can_view_cron_job_logs(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);
        $cronJob = DomainCronJob::factory()->create(['domain_id' => $domain->id]);

        $response = $this->actingAs($owner)->getJson(
            route('domains.cron-jobs.logs', [$domain, $cronJob]),
        );

        $response->assertOk();
        $response->assertJsonStructure(['logs']);
    }

    public function test_command_max_length_is_500(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($owner)->postJson(route('domains.cron-jobs.store', $domain), [
            'command' => str_repeat('a', 501),
            'schedule' => '* * * * *',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('command');
    }

    public function test_description_is_optional(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($owner)->postJson(route('domains.cron-jobs.store', $domain), [
            'command' => 'php test.php',
            'schedule' => '* * * * *',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('domain_cron_jobs', [
            'domain_id' => $domain->id,
            'description' => null,
        ]);
    }

    public function test_creates_audit_log_on_cron_job_creation(): void
    {
        $owner = User::factory()->create();
        $domain = Domain::factory()->create(['owner_user_id' => $owner->id]);

        $this->actingAs($owner)->postJson(route('domains.cron-jobs.store', $domain), [
            'command' => 'php test.php',
            'schedule' => '*/5 * * * *',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $owner->id,
            'action' => 'cron_job_created',
            'domain_id' => $domain->id,
        ]);
    }
}
