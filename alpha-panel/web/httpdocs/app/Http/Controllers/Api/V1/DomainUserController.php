<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DomainUserController extends ApiController
{
    public function index(Domain $domain): JsonResponse
    {
        $users = $domain->authorizedUsers()->get(['users.id', 'users.name', 'users.email']);

        return response()->json(['data' => $users]);
    }

    public function store(Request $request, Domain $domain): JsonResponse
    {
        $validated = $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $domain->authorizedUsers()->syncWithoutDetaching([$validated['user_id']]);
        $user = User::findOrFail($validated['user_id']);

        return response()->json(['data' => $user->only(['id', 'name', 'email'])], 201);
    }

    public function destroy(Domain $domain, User $user): Response
    {
        $domain->authorizedUsers()->detach($user->id);

        return response()->noContent();
    }
}
