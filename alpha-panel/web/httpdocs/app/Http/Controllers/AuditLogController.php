<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('AuditLogs/Index');
    }

    public function usersOptions(Request $request): JsonResponse
    {
        $searchValue = trim((string) $request->input('q', ''));
        $selectedIds = $this->normalizeIntegerArray($request->input('selected', []));

        $users = User::query()
            ->select(['id', 'name', 'username', 'email'])
            ->when($searchValue !== '', function (Builder $query) use ($searchValue): void {
                $query->where(function (Builder $builder) use ($searchValue): void {
                    $builder
                        ->where('name', 'like', "%{$searchValue}%")
                        ->orWhere('username', 'like', "%{$searchValue}%")
                        ->orWhere('email', 'like', "%{$searchValue}%");
                });
            })
            ->orderBy('name')
            ->limit(25)
            ->get();

        if ($selectedIds !== []) {
            $selectedUsers = User::query()
                ->select(['id', 'name', 'username', 'email'])
                ->whereIn('id', $selectedIds)
                ->get();

            $users = $selectedUsers->concat($users)->unique('id')->values();
        }

        $data = $users->map(fn (User $user): array => [
            'value' => $user->id,
            'label' => trim($user->name.' ('.$user->email.')'),
        ]);

        return response()->json([
            'data' => $data->values(),
        ]);
    }

    public function domainsOptions(Request $request): JsonResponse
    {
        $searchValue = trim((string) $request->input('q', ''));
        $selectedIds = $this->normalizeIntegerArray($request->input('selected', []));

        $domains = Domain::query()
            ->select(['id', 'fqdn'])
            ->when($searchValue !== '', fn (Builder $query) => $query->where('fqdn', 'like', "%{$searchValue}%"))
            ->orderBy('fqdn')
            ->limit(25)
            ->get();

        if ($selectedIds !== []) {
            $selectedDomains = Domain::query()
                ->select(['id', 'fqdn'])
                ->whereIn('id', $selectedIds)
                ->get();

            $domains = $selectedDomains->concat($domains)->unique('id')->values();
        }

        $data = $domains->map(fn (Domain $domain): array => [
            'value' => $domain->id,
            'label' => $domain->fqdn,
        ]);

        return response()->json([
            'data' => $data->values(),
        ]);
    }

    public function actionsOptions(Request $request): JsonResponse
    {
        $searchValue = trim((string) $request->input('q', ''));
        $selectedActions = $this->normalizeStringArray($request->input('selected', []));
        $knownActions = collect([
            'supervisor_restarted',
            'supervisor_restart_failed',
            'frankenphp_workers_restart_signaled',
            'frankenphp_workers_restart_failed',
            'laravel_optimize_refreshed',
            'laravel_optimize_refresh_failed',
            'npm_install_executed',
            'npm_install_failed',
            'npm_build_executed',
            'npm_build_failed',
            'npm_audit_fix_executed',
            'npm_audit_fix_failed',
            'composer_install_executed',
            'composer_install_failed',
            'composer_update_executed',
            'composer_update_failed',
            'composer_dump_autoload_executed',
            'composer_dump_autoload_failed',
            'artisan_command_executed',
            'artisan_command_failed',
            'ftp_permissions_fixed',
            'ftp_permissions_fix_failed',
            'role_created',
            'role_updated',
            'role_deleted',
        ]);

        $actions = AuditLog::query()
            ->select('action')
            ->distinct()
            ->when($searchValue !== '', fn (Builder $query) => $query->where('action', 'like', "%{$searchValue}%"))
            ->orderBy('action')
            ->limit(50)
            ->pluck('action')
            ->filter(fn ($action) => is_string($action) && $action !== '')
            ->map(fn ($action) => (string) $action)
            ->values();

        if ($searchValue !== '') {
            $searchNeedle = strtolower($searchValue);
            $knownActions = $knownActions->filter(
                fn (string $action): bool => str_contains(strtolower($action), $searchNeedle),
            )->values();
        }

        $actions = $knownActions
            ->concat($selectedActions)
            ->concat($actions)
            ->unique()
            ->values();

        $data = $actions->map(fn (string $action): array => [
            'value' => $action,
            'label' => ucwords(str_replace('_', ' ', $action)),
        ]);

        return response()->json([
            'data' => $data->values(),
        ]);
    }

    public function json(Request $request): JsonResponse
    {
        $draw = (int) $request->input('draw', 1);
        $start = max(0, (int) $request->input('start', 0));
        $length = max(1, min((int) $request->input('length', 25), 100));
        $searchValue = trim((string) $request->input('search.value', ''));
        $userIds = $this->normalizeIntegerArray($request->input('user_ids', []));
        $domainIds = $this->normalizeIntegerArray($request->input('domain_ids', []));
        $actions = $this->normalizeStringArray($request->input('actions', []));
        $dateFrom = $this->normalizeDateInput($request->input('date_from'), false);
        $dateTo = $this->normalizeDateInput($request->input('date_to'), true);

        $columnMap = ['created_at', 'user', 'action', 'domain', 'source', 'summary'];

        $recordsTotal = AuditLog::query()->count();

        $baseQuery = AuditLog::query()->with([
            'user:id,name,username,email',
            'domain:id,fqdn',
        ]);

        if ($dateFrom !== null) {
            $baseQuery->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $baseQuery->where('created_at', '<=', $dateTo);
        }

        if ($userIds !== []) {
            $baseQuery->whereIn('user_id', $userIds);
        }

        if ($domainIds !== []) {
            $baseQuery->whereIn('domain_id', $domainIds);
        }

        if ($actions !== []) {
            $baseQuery->whereIn('action', $actions);
        }

        $query = clone $baseQuery;

        if ($searchValue !== '') {
            $query->where(function ($builder) use ($searchValue): void {
                $builder
                    ->where('action', 'like', "%{$searchValue}%")
                    ->orWhere('summary', 'like', "%{$searchValue}%")
                    ->orWhereHas('user', function ($userQuery) use ($searchValue): void {
                        $userQuery
                            ->where('name', 'like', "%{$searchValue}%")
                            ->orWhere('username', 'like', "%{$searchValue}%")
                            ->orWhere('email', 'like', "%{$searchValue}%");
                    })
                    ->orWhereHas('domain', fn ($domainQuery) => $domainQuery->where('fqdn', 'like', "%{$searchValue}%"))
                    ->orWhere('ip_address', 'like', "%{$searchValue}%")
                    ->orWhereRaw('CAST(port AS CHAR) like ?', ["%{$searchValue}%"])
                    ->orWhereRaw("CONCAT(COALESCE(ip_address, ''), ':', COALESCE(port, '')) like ?", ["%{$searchValue}%"]);
            });
        }

        $recordsFiltered = (clone $query)->count();

        $orderColumn = (int) $request->input('order.0.column', 0);
        $orderDir = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $sortColumn = $columnMap[$orderColumn] ?? 'created_at';

        if ($sortColumn === 'user') {
            $query->orderBy(
                User::query()
                    ->select('name')
                    ->whereColumn('users.id', 'audit_logs.user_id'),
                $orderDir,
            );
            $query->orderBy('audit_logs.created_at', 'desc');
        } elseif ($sortColumn === 'domain') {
            $query->orderBy(
                Domain::query()
                    ->select('fqdn')
                    ->whereColumn('domains.id', 'audit_logs.domain_id'),
                $orderDir,
            );
            $query->orderBy('audit_logs.created_at', 'desc');
        } elseif ($sortColumn === 'source') {
            $query->orderBy('audit_logs.ip_address', $orderDir);
            $query->orderBy('audit_logs.port', $orderDir);
            $query->orderBy('audit_logs.created_at', 'desc');
        } elseif (in_array($sortColumn, ['created_at', 'action', 'summary'], true)) {
            $query->orderBy("audit_logs.{$sortColumn}", $orderDir);
        } else {
            $query->orderBy('audit_logs.created_at', 'desc');
        }

        $logs = $query
            ->skip($start)
            ->take($length)
            ->get();

        $data = $logs->map(fn (AuditLog $log): array => [
            'id' => $log->id,
            'created_at' => $log->created_at?->format(config('app.display_datetime_format', 'd.m.Y H:i:s')) ?? '-',
            'user' => $log->user?->name ?? 'System',
            'action' => $log->action,
            'action_badge' => $this->actionBadge($log->action),
            'domain' => $log->domain?->fqdn ?? '-',
            'domain_show_url' => $log->domain ? route('domains.show', $log->domain) : null,
            'ip_address' => $log->ip_address ?? '-',
            'port' => $log->port,
            'source' => $this->formatSource($log->ip_address, $log->port),
            'summary' => $log->summary ?? '-',
            'details' => $log->details,
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    private function formatSource(?string $ipAddress, mixed $port): string
    {
        if (! is_string($ipAddress) || trim($ipAddress) === '') {
            return '-';
        }

        if (is_numeric($port)) {
            return trim($ipAddress).':'.(int) $port;
        }

        return trim($ipAddress);
    }

    /**
     * @return array<int, int>
     */
    private function normalizeIntegerArray(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = array_map(static fn ($value): int => (int) $value, $values);

        return array_values(array_unique(array_filter($normalized, static fn (int $value): bool => $value > 0)));
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringArray(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = array_map(static fn ($value): string => trim((string) $value), $values);

        return array_values(array_unique(array_filter($normalized, static fn (string $value): bool => $value !== '')));
    }

    private function normalizeDateInput(mixed $value, bool $isEnd): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);

        try {
            $resolved = CarbonImmutable::parse($trimmed);
        } catch (\Throwable) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            return $isEnd ? $resolved->endOfDay() : $resolved->startOfDay();
        }

        return $resolved;
    }

    private function actionBadge(string $action): string
    {
        $label = ucwords(str_replace('_', ' ', $action));

        if (str_contains($action, 'failed')) {
            return '<span class="inline-flex rounded-full bg-error-500/15 px-2 py-0.5 text-xs font-semibold text-error-700 dark:text-error-300">'.$label.'</span>';
        }

        if (str_starts_with($action, 'dns_')) {
            return '<span class="inline-flex rounded-full bg-blue-light-500/15 px-2 py-0.5 text-xs font-semibold text-blue-light-700 dark:text-blue-light-300">'.$label.'</span>';
        }

        if (str_starts_with($action, 'ssl_')) {
            return '<span class="inline-flex rounded-full bg-warning-500/15 px-2 py-0.5 text-xs font-semibold text-warning-700 dark:text-warning-300">'.$label.'</span>';
        }

        if (str_starts_with($action, 'docker_')) {
            return '<span class="inline-flex rounded-full bg-blue-light-500/15 px-2 py-0.5 text-xs font-semibold text-blue-light-700 dark:text-blue-light-300">'.$label.'</span>';
        }

        if (str_starts_with($action, 'terminal_')) {
            return '<span class="inline-flex rounded-full bg-warning-500/15 px-2 py-0.5 text-xs font-semibold text-warning-700 dark:text-warning-300">'.$label.'</span>';
        }

        if (str_starts_with($action, 'frankenphp_')) {
            return '<span class="inline-flex rounded-full bg-blue-light-500/15 px-2 py-0.5 text-xs font-semibold text-blue-light-700 dark:text-blue-light-300">'.$label.'</span>';
        }

        if (str_starts_with($action, 'supervisor_')) {
            return '<span class="inline-flex rounded-full bg-purple-500/15 px-2 py-0.5 text-xs font-semibold text-purple-700 dark:text-purple-300">'.$label.'</span>';
        }

        if (in_array($action, ['deleted', 'renamed'], true)) {
            return '<span class="inline-flex rounded-full bg-error-500/15 px-2 py-0.5 text-xs font-semibold text-error-700 dark:text-error-300">'.$label.'</span>';
        }

        return '<span class="inline-flex rounded-full bg-success-500/15 px-2 py-0.5 text-xs font-semibold text-success-700 dark:text-success-300">'.$label.'</span>';
    }
}
