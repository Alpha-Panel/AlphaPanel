<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserAccountsController extends Controller
{
    public function index(): Response
    {
        $roles = Role::where('guard_name', 'web')->get(['id', 'name']);

        return Inertia::render('Users/Index', [
            'availableRoles' => $roles,
        ]);
    }

    public function json(Request $request): JsonResponse
    {
        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $searchValue = $request->input('search.value', '');

        $columnMap = ['name', 'username', 'email', 'admin', 'owned_domains_count', 'created_at', 'actions'];

        $baseQuery = User::query()->where('id', '!=', $request->user()->id);

        $recordsTotal = (clone $baseQuery)->count();

        $query = clone $baseQuery;

        if ($searchValue !== '') {
            $query->where(function ($q) use ($searchValue) {
                $q->where('name', 'like', "%{$searchValue}%")
                    ->orWhere('username', 'like', "%{$searchValue}%")
                    ->orWhere('email', 'like', "%{$searchValue}%");
            });
        }

        $recordsFiltered = (clone $query)->count();

        $orderColumn = (int) $request->input('order.0.column', 0);
        $orderDir = $request->input('order.0.dir', 'asc') === 'desc' ? 'desc' : 'asc';

        if (isset($columnMap[$orderColumn]) && in_array($columnMap[$orderColumn], ['name', 'username', 'email', 'admin', 'created_at'])) {
            $query->orderBy($columnMap[$orderColumn], $orderDir);
        } else {
            $query->latest();
        }

        $users = $query->withCount('ownedDomains')->skip($start)->take($length)->get();

        $service = app(ImpersonationService::class);
        $actor = $request->user();

        $data = $users->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'admin' => $user->admin,
            'admin_badge' => $user->admin
                ? '<span class="badge bg-success">'.__('Admin').'</span>'
                : '<span class="badge bg-secondary">'.__('User').'</span>',
            'roles' => $user->getRoleNames()->toArray(),
            'owned_domains_count' => $user->owned_domains_count,
            'created_at' => $user->created_at?->format(config('app.display_datetime_format', 'd.m.Y H:i:s')) ?? '-',
            'update_url' => route('users.update', $user),
            'destroy_url' => route('users.destroy', $user),
            'impersonate_url' => route('impersonation.start', $user),
            'can_impersonate' => $service->canImpersonate($actor, $user),
        ]);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'admin' => ['boolean'],
            'role' => ['nullable', 'string', 'exists:roles,name'],
        ]);

        $adminValue = (bool) ($validated['admin'] ?? false);
        $roleName = $validated['role'] ?? null;
        unset($validated['role'], $validated['admin']);

        $user = User::create($validated);

        if (auth()->user()->isAdmin()) {
            $user->admin = $adminValue;
            $user->save();
        }

        if ($roleName) {
            $user->assignRole($roleName);
        } else {
            $user->assignRole($adminValue && auth()->user()->isAdmin() ? 'Admin' : 'Domain Manager');
        }

        return response()->json(['success' => true, 'user' => $user->only(['id', 'name', 'username', 'email'])]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => __('You cannot edit your own account here.')], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', Rule::unique('users')->ignore($user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'admin' => ['boolean'],
            'role' => ['nullable', 'string', 'exists:roles,name'],
        ]);

        $adminValue = (bool) ($validated['admin'] ?? false);
        $roleName = $validated['role'] ?? null;
        unset($validated['role'], $validated['admin']);

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $user->update($validated);

        if (auth()->user()->isAdmin()) {
            $user->admin = $adminValue;
            $user->save();
        }

        if ($roleName) {
            $user->syncRoles([$roleName]);
        }

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => __('You cannot delete your own account.')], 403);
        }

        // Transfer domains to current admin
        $user->ownedDomains()->update(['owner_user_id' => $request->user()->id]);

        $user->delete();

        return response()->json(['success' => true]);
    }
}
