<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class UserController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json($this->paginate(User::query()->orderBy('name')));
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'username' => 'required|string|unique:users,username|max:50',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'admin' => 'boolean',
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $user = User::create($validated);

        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'user_created', 'summary' => $user->email, 'ip_address' => $request->ip()]);

        return response()->json(['data' => $user], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'email' => "sometimes|email|unique:users,email,{$user->id}",
            'password' => 'nullable|string|min:8',
            'admin' => 'boolean',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return response()->json(['data' => $user->fresh()]);
    }

    public function destroy(Request $request, User $user): Response
    {
        $this->ensureAdmin($request);
        abort_if($user->id === $request->user()->id, 422, 'Cannot delete yourself.');

        $user->delete();

        return response()->noContent();
    }

    private function ensureAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }
    }
}
