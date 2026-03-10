<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DomainCronJob;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DomainCronJobController extends Controller
{
    public function index(Domain $domain): Response
    {
        $this->authorize('update', $domain);

        $cronJobs = $domain->cronJobs()
            ->with('latestLog')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (DomainCronJob $job): array => [
                'id' => $job->id,
                'command' => $job->command,
                'schedule' => $job->schedule,
                'schedule_human' => $this->humanReadableSchedule($job->schedule),
                'description' => $job->description,
                'enabled' => $job->enabled,
                'created_at' => $job->created_at?->toDateTimeString(),
                'latest_log' => $job->latestLog ? [
                    'status' => $job->latestLog->status,
                    'started_at' => $job->latestLog->started_at?->toDateTimeString(),
                    'duration_ms' => $job->latestLog->duration_ms,
                    'exit_code' => $job->latestLog->exit_code,
                    'output' => $job->latestLog->output,
                ] : null,
            ]);

        return Inertia::render('Domains/CronJobs', [
            'domain' => [
                'id' => $domain->id,
                'fqdn' => $domain->fqdn,
            ],
            'cronJobs' => $cronJobs,
            'presets' => $this->schedulePresets(),
        ]);
    }

    public function store(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('update', $domain);

        $validated = $request->validate([
            'command' => ['required', 'string', 'max:500'],
            'schedule' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! CronExpression::isValidExpression($value)) {
                    $fail(__('The :attribute is not a valid cron expression.', ['attribute' => $attribute]));
                }
            }],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $cronJob = $domain->cronJobs()->create($validated);

        $this->createAuditLog(
            $request,
            $domain,
            'cron_job_created',
            "{$cronJob->schedule} {$cronJob->command}",
        );

        return response()->json([
            'status' => 'success',
            'message' => __('Cron job created successfully.'),
            'cron_job' => [
                'id' => $cronJob->id,
                'command' => $cronJob->command,
                'schedule' => $cronJob->schedule,
                'schedule_human' => $this->humanReadableSchedule($cronJob->schedule),
                'description' => $cronJob->description,
                'enabled' => $cronJob->enabled,
                'created_at' => $cronJob->created_at?->toDateTimeString(),
                'latest_log' => null,
            ],
        ], 201);
    }

    public function update(Request $request, Domain $domain, DomainCronJob $cronJob): JsonResponse
    {
        $this->authorize('update', $domain);
        $this->ensureBelongsToDomain($cronJob, $domain);

        $validated = $request->validate([
            'command' => ['required', 'string', 'max:500'],
            'schedule' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! CronExpression::isValidExpression($value)) {
                    $fail(__('The :attribute is not a valid cron expression.', ['attribute' => $attribute]));
                }
            }],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $cronJob->update($validated);

        $this->createAuditLog(
            $request,
            $domain,
            'cron_job_updated',
            "{$cronJob->schedule} {$cronJob->command}",
        );

        return response()->json([
            'status' => 'success',
            'message' => __('Cron job updated successfully.'),
            'cron_job' => [
                'id' => $cronJob->id,
                'command' => $cronJob->command,
                'schedule' => $cronJob->schedule,
                'schedule_human' => $this->humanReadableSchedule($cronJob->schedule),
                'description' => $cronJob->description,
                'enabled' => $cronJob->enabled,
            ],
        ]);
    }

    public function destroy(Request $request, Domain $domain, DomainCronJob $cronJob): JsonResponse
    {
        $this->authorize('update', $domain);
        $this->ensureBelongsToDomain($cronJob, $domain);

        $summary = "{$cronJob->schedule} {$cronJob->command}";
        $cronJob->delete();

        $this->createAuditLog($request, $domain, 'cron_job_deleted', $summary);

        return response()->json([
            'status' => 'success',
            'message' => __('Cron job deleted successfully.'),
        ]);
    }

    public function toggle(Request $request, Domain $domain, DomainCronJob $cronJob): JsonResponse
    {
        $this->authorize('update', $domain);
        $this->ensureBelongsToDomain($cronJob, $domain);

        $cronJob->update(['enabled' => ! $cronJob->enabled]);

        $action = $cronJob->enabled ? 'cron_job_enabled' : 'cron_job_disabled';

        $this->createAuditLog(
            $request,
            $domain,
            $action,
            "{$cronJob->schedule} {$cronJob->command}",
        );

        return response()->json([
            'status' => 'success',
            'message' => $cronJob->enabled
                ? __('Cron job enabled successfully.')
                : __('Cron job disabled successfully.'),
            'enabled' => $cronJob->enabled,
        ]);
    }

    public function logs(Domain $domain, DomainCronJob $cronJob): JsonResponse
    {
        $this->authorize('update', $domain);
        $this->ensureBelongsToDomain($cronJob, $domain);

        $logs = $cronJob->logs()
            ->orderByDesc('started_at')
            ->limit(50)
            ->get()
            ->map(fn ($log): array => [
                'id' => $log->id,
                'started_at' => $log->started_at?->toDateTimeString(),
                'finished_at' => $log->finished_at?->toDateTimeString(),
                'duration_ms' => $log->duration_ms,
                'status' => $log->status,
                'output' => $log->output,
                'exit_code' => $log->exit_code,
            ]);

        return response()->json(['logs' => $logs]);
    }

    private function ensureBelongsToDomain(DomainCronJob $cronJob, Domain $domain): void
    {
        if ($cronJob->domain_id !== $domain->id) {
            abort(404);
        }
    }

    private function createAuditLog(Request $request, Domain $domain, string $action, string $summary): void
    {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'domain_id' => $domain->id,
            'summary' => $summary,
            'ip_address' => $request->ip(),
            'port' => is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
        ]);
    }

    private function humanReadableSchedule(string $expression): string
    {
        return match ($expression) {
            '* * * * *' => __('Every minute'),
            '*/5 * * * *' => __('Every 5 minutes'),
            '*/10 * * * *' => __('Every 10 minutes'),
            '*/15 * * * *' => __('Every 15 minutes'),
            '*/30 * * * *' => __('Every 30 minutes'),
            '0 * * * *' => __('Every hour'),
            '0 */2 * * *' => __('Every 2 hours'),
            '0 */6 * * *' => __('Every 6 hours'),
            '0 */12 * * *' => __('Every 12 hours'),
            '0 0 * * *' => __('Daily at midnight'),
            '0 0 * * 0' => __('Weekly (Sunday midnight)'),
            '0 0 1 * *' => __('Monthly (1st at midnight)'),
            default => $expression,
        };
    }

    /** @return array<int, array{label: string, value: string}> */
    private function schedulePresets(): array
    {
        return [
            ['label' => __('Every minute'), 'value' => '* * * * *'],
            ['label' => __('Every 5 minutes'), 'value' => '*/5 * * * *'],
            ['label' => __('Every 10 minutes'), 'value' => '*/10 * * * *'],
            ['label' => __('Every 15 minutes'), 'value' => '*/15 * * * *'],
            ['label' => __('Every 30 minutes'), 'value' => '*/30 * * * *'],
            ['label' => __('Every hour'), 'value' => '0 * * * *'],
            ['label' => __('Every 2 hours'), 'value' => '0 */2 * * *'],
            ['label' => __('Every 6 hours'), 'value' => '0 */6 * * *'],
            ['label' => __('Every 12 hours'), 'value' => '0 */12 * * *'],
            ['label' => __('Daily at midnight'), 'value' => '0 0 * * *'],
            ['label' => __('Weekly (Sunday)'), 'value' => '0 0 * * 0'],
            ['label' => __('Monthly (1st)'), 'value' => '0 0 1 * *'],
        ];
    }
}
