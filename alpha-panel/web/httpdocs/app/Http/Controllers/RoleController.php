<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
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

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->pluck('name')
            ->toArray();

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'permissions' => $permissions,
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

        $role->update(['name' => $validated['name']]);
        $role->syncPermissions($validated['permissions']);

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

    public function destroy(Role $role): JsonResponse
    {
        if ($role->users()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => __('Cannot delete a role that has users assigned to it.'),
            ], 422);
        }

        $role->delete();

        return response()->json([
            'status' => 'success',
            'message' => __('Role deleted successfully.'),
        ]);
    }
}
