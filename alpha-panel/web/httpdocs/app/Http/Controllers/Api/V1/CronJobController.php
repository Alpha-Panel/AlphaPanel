<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DomainCronJob;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CronJobController extends ApiController
{
    public function index(Domain $domain): JsonResponse
    {
        $jobs = $domain->cronJobs()
            ->with(['latestLog', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $jobs]);
    }

    public function store(Request $request, Domain $domain): JsonResponse
    {
        $validated = $request->validate($this->rules($request));
        $job = $domain->cronJobs()->create([...$validated, 'created_by' => $request->user()->id]);

        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'cron_job_created', 'domain_id' => $domain->id, 'summary' => "{$job->schedule} {$job->command}", 'ip_address' => $request->ip()]);

        return response()->json(['data' => $job->fresh(['latestLog', 'creator'])], 201);
    }

    public function update(Request $request, Domain $domain, DomainCronJob $job): JsonResponse
    {
        abort_unless($job->domain_id === $domain->id, 404);
        $this->ensureCanModify($request, $job);

        $validated = $request->validate($this->rules($request));
        $job->update($validated);

        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'cron_job_updated', 'domain_id' => $domain->id, 'summary' => "{$job->schedule} {$job->command}", 'ip_address' => $request->ip()]);

        return response()->json(['data' => $job->fresh()]);
    }

    public function destroy(Request $request, Domain $domain, DomainCronJob $job): Response
    {
        abort_unless($job->domain_id === $domain->id, 404);
        $this->ensureCanModify($request, $job);

        $job->delete();
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'cron_job_deleted', 'domain_id' => $domain->id, 'summary' => "{$job->schedule} {$job->command}", 'ip_address' => $request->ip()]);

        return response()->noContent();
    }

    public function toggle(Request $request, Domain $domain, DomainCronJob $job): JsonResponse
    {
        abort_unless($job->domain_id === $domain->id, 404);
        $this->ensureCanModify($request, $job);

        $job->update(['enabled' => ! $job->enabled]);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $job->enabled ? 'cron_job_enabled' : 'cron_job_disabled', 'domain_id' => $domain->id, 'summary' => "{$job->schedule} {$job->command}", 'ip_address' => $request->ip()]);

        return response()->json(['data' => ['enabled' => $job->enabled]]);
    }

    public function logs(Domain $domain, DomainCronJob $job): JsonResponse
    {
        abort_unless($job->domain_id === $domain->id, 404);

        $logs = $job->logs()->orderByDesc('started_at')->limit(50)->get();

        return response()->json(['data' => $logs]);
    }

    private function ensureCanModify(Request $request, DomainCronJob $job): void
    {
        if (! $request->user()->isAdmin() && $job->created_by !== $request->user()->id) {
            abort(403, __('You can only modify cron jobs you created.'));
        }
    }

    /** @return array<string, mixed> */
    private function rules(Request $request): array
    {
        $isAdmin = $request->user()->isAdmin();

        return [
            'command' => ['required', 'string', 'max:500', function (string $attr, mixed $value, \Closure $fail) use ($isAdmin): void {
                $cmd = (string) $value;
                if ($isAdmin) {
                    $blocked = ['rm -rf /', 'mkfs', 'dd if=', ':(){', 'chmod -R 777 /'];
                    foreach ($blocked as $p) {
                        if (str_contains($cmd, $p)) {
                            $fail(__('The command contains a blocked pattern.'));

                            return;
                        }
                    }
                } else {
                    $dangerous = ['`', '$(', '${', ';', '&&', '||', '|', '>', '<', "\n", "\r"];
                    foreach ($dangerous as $p) {
                        if (str_contains($cmd, $p)) {
                            $fail(__('The command contains a disallowed character or pattern.'));

                            return;
                        }
                    }
                    if (! str_starts_with(trim($cmd), 'php ') && ! str_starts_with(trim($cmd), 'php artisan ')) {
                        $fail(__('The command must start with "php" or "php artisan".'));
                    }
                }
            }],
            'schedule' => ['required', 'string', 'max:100', function (string $attr, mixed $value, \Closure $fail): void {
                if (! CronExpression::isValidExpression($value)) {
                    $fail(__('The :attribute is not a valid cron expression.', ['attribute' => $attr]));
                }
            }],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
