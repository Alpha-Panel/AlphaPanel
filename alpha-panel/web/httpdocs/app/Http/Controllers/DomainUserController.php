<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DomainUserController extends Controller
{
    public function index(Request $request, Domain $domain): Response
    {
        $this->authorize('view', $domain);

        $domain->loadMissing('owner:id,name,email');

        $authorizedUsers = $domain->authorizedUsers()
            ->select('users.id', 'users.name', 'users.email')
            ->get();

        $availableUsers = User::query()
            ->whereNotIn('id', $authorizedUsers->pluck('id')->push($domain->owner_user_id))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('Domains/Users', [
            'domain' => [
                'id' => $domain->id,
                'fqdn' => $domain->fqdn,
                'owner' => $domain->owner?->only('id', 'name', 'email'),
            ],
            'authorizedUsers' => $authorizedUsers,
            'availableUsers' => $availableUsers,
        ]);
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

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'domain_user_added',
            'domain_id' => $domain->id,
            'summary' => "{$user->name} ({$user->email}) added to {$domain->fqdn}",
        ]);

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

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => 'domain_user_removed',
            'domain_id' => $domain->id,
            'summary' => "{$user->name} ({$user->email}) removed from {$domain->fqdn}",
        ]);

        return response()->json([
            'status' => 'success',
            'message' => __('User removed from domain successfully.'),
        ]);
    }
}
