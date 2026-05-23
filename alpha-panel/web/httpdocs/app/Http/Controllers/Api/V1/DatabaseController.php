<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreManagedDatabaseRequest;
use App\Http\Requests\StoreManagedDatabaseUserRequest;
use App\Models\Domain;
use App\Models\ManagedDatabase;
use App\Models\ManagedDatabaseUser;
use App\Services\MysqlAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class DatabaseController extends ApiController
{
    public function index(Domain $domain): JsonResponse
    {
        $this->authorize('viewDb', $domain);

        $databases = ManagedDatabase::with('databaseUsers')
            ->where('domain_id', $domain->id)
            ->get();

        return response()->json(['data' => $databases]);
    }

    public function store(StoreManagedDatabaseRequest $request, Domain $domain, MysqlAdminService $mysql): JsonResponse
    {
        $this->authorize('manageDb', $domain);

        $validated = $request->validated();

        try {
            $mysql->createDatabase($validated['db_name']);
            $mysql->createUser($validated['db_user'], $validated['db_password']);
            $mysql->grantPrivileges($validated['db_name'], $validated['db_user']);
        } catch (\Throwable $e) {
            Log::error("DB provision failed: {$e->getMessage()}");

            return response()->json(['message' => __('MySQL operation failed: :error', ['error' => $e->getMessage()])], 500);
        }

        $db = ManagedDatabase::create([
            'domain_id' => $domain->id,
            'db_name' => $validated['db_name'],
        ]);

        ManagedDatabaseUser::create([
            'managed_database_id' => $db->id,
            'username' => $validated['db_user'],
        ]);

        return response()->json(['data' => $db->load('databaseUsers')], 201);
    }

    public function destroy(Request $request, Domain $domain, ManagedDatabase $database, MysqlAdminService $mysql): Response
    {
        $this->authorize('manageDb', $domain);
        abort_unless($database->domain_id === $domain->id, 404);

        foreach ($database->databaseUsers as $user) {
            try {
                $mysql->dropUser($user->username);
            } catch (\Throwable) {
            }
        }

        try {
            $mysql->dropDatabase($database->db_name);
        } catch (\Throwable) {
        }

        $database->delete();

        return response()->noContent();
    }

    public function storeUser(StoreManagedDatabaseUserRequest $request, Domain $domain, ManagedDatabase $database, MysqlAdminService $mysql): JsonResponse
    {
        $this->authorize('manageDb', $domain);
        abort_unless($database->domain_id === $domain->id, 404);

        $validated = $request->validated();

        $mysql->createUser($validated['db_user'], $validated['db_password']);
        $mysql->grantPrivileges($database->db_name, $validated['db_user']);

        $user = ManagedDatabaseUser::create([
            'managed_database_id' => $database->id,
            'username' => $validated['db_user'],
        ]);

        return response()->json(['data' => $user], 201);
    }

    public function updateUserPassword(Request $request, Domain $domain, ManagedDatabaseUser $user, MysqlAdminService $mysql): JsonResponse
    {
        $this->authorize('manageDb', $domain);
        $this->ensureUserBelongsToDomain($user, $domain);

        $validated = $request->validate(['password' => 'required|string|min:8']);
        $mysql->updateUserPassword($user->username, $validated['password']);

        return response()->json(['message' => __('Password updated.')]);
    }

    public function destroyUser(Request $request, Domain $domain, ManagedDatabaseUser $user, MysqlAdminService $mysql): Response
    {
        $this->authorize('manageDb', $domain);
        $this->ensureUserBelongsToDomain($user, $domain);

        try {
            $mysql->dropUser($user->username);
        } catch (\Throwable) {
        }

        $user->delete();

        return response()->noContent();
    }

    private function ensureUserBelongsToDomain(ManagedDatabaseUser $user, Domain $domain): void
    {
        $user->loadMissing('managedDatabase');
        abort_unless($user->managedDatabase && $user->managedDatabase->domain_id === $domain->id, 404);
    }
}
