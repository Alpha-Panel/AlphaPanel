<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DomainUserController extends Controller
{
    public function index(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('view', $domain);

        $authorizedUsers = $domain->authorizedUsers()
            ->select('users.id', 'users.name', 'users.email')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]);

        return response()->json(['data' => $authorizedUsers]);
    }

    public function store(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('update', $domain);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $userId = (int) $validated['user_id'];

        if ($domain->owner_user_id === $userId) {
            return response()->json([
                'status' => 'error',
                'message' => __('The domain owner is already authorized.'),
            ], 422);
        }

        if ($domain->authorizedUsers()->where('user_id', $userId)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => __('This user already has access to this domain.'),
            ], 422);
        }

        $domain->authorizedUsers()->attach($userId);

        $user = User::select('id', 'name', 'email')->findOrFail($userId);

        return response()->json([
            'status' => 'success',
            'message' => __('User added to domain successfully.'),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }

    public function destroy(Request $request, Domain $domain, User $user): JsonResponse
    {
        $this->authorize('update', $domain);

        $domain->authorizedUsers()->detach($user->id);

        return response()->json([
            'status' => 'success',
            'message' => __('User removed from domain successfully.'),
        ]);
    }
}
