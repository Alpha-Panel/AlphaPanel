<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): Response
    {
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->withCount('users')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->toArray(),
                'users_count' => $role->users_count,
                'is_default' => in_array($role->name, ['Admin', 'Domain Manager', 'Domain Viewer'], true),
            ]);

        $config = config('panel-permissions', []);

        $permissionGroups = collect($config)
            ->filter(fn ($group) => is_array($group) && isset($group['permissions']))
            ->map(fn ($group) => [
                'label' => $group['label'],
                'permissions' => collect($group['permissions'])->map(fn ($desc, $name) => [
                    'name' => $name,
                    'description' => $desc,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'permissionGroups' => $permissionGroups,
        ]);
    }

    public function json(): JsonResponse
    {
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->get(['id', 'name']);

        return response()->json(['data' => $roles]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($validated['permissions']);

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'role_created',
            'summary' => "Created role \"{$role->name}\" with ".count($validated['permissions']).' permission(s).',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => __('Role created successfully.'),
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->toArray(),
                'users_count' => 0,
                'is_default' => false,
            ],
        ], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', "unique:roles,name,{$role->id}"],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $oldName = $role->name;
        $oldPermissions = $role->permissions->pluck('name')->sort()->values()->toArray();

        $role->update(['name' => $validated['name']]);
        $role->syncPermissions($validated['permissions']);

        $newPermissions = collect($validated['permissions'])->sort()->values()->toArray();
        $added = array_values(array_diff($newPermissions, $oldPermissions));
        $removed = array_values(array_diff($oldPermissions, $newPermissions));

        $changes = [];
        if ($oldName !== $validated['name']) {
            $changes[] = "renamed \"{$oldName}\" → \"{$validated['name']}\"";
        }
        if (count($added) > 0) {
            $changes[] = 'added '.count($added).' permission(s)';
        }
        if (count($removed) > 0) {
            $changes[] = 'removed '.count($removed).' permission(s)';
        }

        if (count($changes) > 0) {
            AuditLog::create([
                'user_id' => $request->user()?->id,
                'action' => 'role_updated',
                'summary' => "Updated role \"{$role->name}\": ".implode(', ', $changes).'.',
                'details' => json_encode(array_filter([
                    'added' => $added ?: null,
                    'removed' => $removed ?: null,
                ]), JSON_THROW_ON_ERROR),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => __('Role updated successfully.'),
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->toArray(),
                'users_count' => $role->users()->count(),
                'is_default' => in_array($role->name, ['Admin', 'Domain Manager', 'Domain Viewer'], true),
            ],
        ]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        if ($role->users()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => __('Cannot delete a role that has users assigned to it.'),
            ], 422);
        }

        $roleName = $role->name;
        $permissionCount = $role->permissions->count();

        $role->delete();

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'role_deleted',
            'summary' => "Deleted role \"{$roleName}\" ({$permissionCount} permission(s)).",
        ]);

        return response()->json([
            'status' => 'success',
            'message' => __('Role deleted successfully.'),
        ]);
    }
}
