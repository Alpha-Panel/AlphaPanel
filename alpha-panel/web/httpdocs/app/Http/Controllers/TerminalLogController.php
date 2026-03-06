<?php

namespace App\Http\Controllers;

use App\Models\TerminalLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TerminalLogController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('TerminalLogs/Index');
    }

    public function json(Request $request): JsonResponse
    {
        $draw = (int) $request->input('draw', 1);
        $start = max(0, (int) $request->input('start', 0));
        $length = max(1, min((int) $request->input('length', 25), 100));
        $searchValue = trim((string) $request->input('search.value', ''));
        $userIds = $this->normalizeIntegerArray($request->input('user_ids', []));
        $sessionTypes = $this->normalizeStringArray($request->input('session_types', []));
        $dateFrom = $this->normalizeDateInput($request->input('date_from'), false);
        $dateTo = $this->normalizeDateInput($request->input('date_to'), true);

        $recordsTotal = TerminalLog::query()->count();

        $baseQuery = TerminalLog::query()->with('user:id,name,username,email');

        if ($dateFrom !== null) {
            $baseQuery->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $baseQuery->where('created_at', '<=', $dateTo);
        }

        if ($userIds !== []) {
            $baseQuery->whereIn('user_id', $userIds);
        }

        if ($sessionTypes !== []) {
            $baseQuery->whereIn('session_type', $sessionTypes);
        }

        $query = clone $baseQuery;

        if ($searchValue !== '') {
            $query->where(function (Builder $builder) use ($searchValue): void {
                $builder
                    ->where('command', 'like', "%{$searchValue}%")
                    ->orWhere('container_name', 'like', "%{$searchValue}%")
                    ->orWhere('session_id', 'like', "%{$searchValue}%")
                    ->orWhere('ip_address', 'like', "%{$searchValue}%")
                    ->orWhereHas('user', function (Builder $userQuery) use ($searchValue): void {
                        $userQuery
                            ->where('name', 'like', "%{$searchValue}%")
                            ->orWhere('username', 'like', "%{$searchValue}%");
                    });
            });
        }

        $recordsFiltered = (clone $query)->count();

        $logs = $query
            ->orderByDesc('created_at')
            ->skip($start)
            ->take($length)
            ->get();

        $data = $logs->map(fn (TerminalLog $log): array => [
            'id' => $log->id,
            'created_at' => $log->created_at?->format(config('app.display_datetime_format', 'd.m.Y H:i:s')) ?? '-',
            'user' => $log->user?->name ?? 'System',
            'session_type' => $log->session_type,
            'session_type_badge' => $this->sessionTypeBadge($log->session_type),
            'container_name' => $log->container_name,
            'command' => $log->command ?? '-',
            'has_output' => $log->output !== null && $log->output !== '',
            'source' => $this->formatSource($log->ip_address, $log->port),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function show(TerminalLog $terminalLog): JsonResponse
    {
        return response()->json([
            'id' => $terminalLog->id,
            'command' => $terminalLog->command,
            'output' => $terminalLog->output ?? '',
            'container_name' => $terminalLog->container_name,
            'session_type' => $terminalLog->session_type,
            'source' => $this->formatSource($terminalLog->ip_address, $terminalLog->port),
            'created_at' => $terminalLog->created_at?->format(config('app.display_datetime_format', 'd.m.Y H:i:s')),
            'user' => $terminalLog->user?->name ?? 'System',
        ]);
    }

    public function usersOptions(Request $request): JsonResponse
    {
        $searchValue = trim((string) $request->input('q', ''));

        $userIds = TerminalLog::query()
            ->select('user_id')
            ->distinct()
            ->pluck('user_id');

        $users = User::query()
            ->select(['id', 'name', 'username', 'email'])
            ->whereIn('id', $userIds)
            ->when($searchValue !== '', function (Builder $query) use ($searchValue): void {
                $query->where(function (Builder $builder) use ($searchValue): void {
                    $builder
                        ->where('name', 'like', "%{$searchValue}%")
                        ->orWhere('username', 'like', "%{$searchValue}%");
                });
            })
            ->orderBy('name')
            ->limit(25)
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $user): array => [
                'value' => $user->id,
                'label' => trim($user->name.' ('.$user->email.')'),
            ])->values(),
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

    private function sessionTypeBadge(string $type): string
    {
        return match ($type) {
            'ssh' => '<span class="inline-flex rounded-full bg-warning-500/15 px-2 py-0.5 text-xs font-semibold text-warning-700 dark:text-warning-300">SSH Host</span>',
            default => '<span class="inline-flex rounded-full bg-blue-light-500/15 px-2 py-0.5 text-xs font-semibold text-blue-light-700 dark:text-blue-light-300">'.ucfirst($type).'</span>',
        };
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

        try {
            $resolved = CarbonImmutable::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            return $isEnd ? $resolved->endOfDay() : $resolved->startOfDay();
        }

        return $resolved;
    }
}
