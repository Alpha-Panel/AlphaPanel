<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\Permission\Models\Role;

class RoleController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json(['data' => Role::query()->with('permissions')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'name' => 'required|string|unique:roles,name|max:100',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
        ]);

        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'web']);

        if (! empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json(['data' => $role->load('permissions')], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'name' => "sometimes|string|unique:roles,name,{$role->id}|max:100",
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
        ]);

        if (isset($validated['name'])) {
            $role->update(['name' => $validated['name']]);
        }

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json(['data' => $role->fresh('permissions')]);
    }

    public function destroy(Request $request, Role $role): Response
    {
        $this->ensureAdmin($request);
        $role->delete();

        return response()->noContent();
    }

    private function ensureAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }
    }
}
