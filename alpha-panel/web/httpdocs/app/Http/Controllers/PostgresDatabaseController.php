<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreManagedPostgresDatabaseRequest;
use App\Http\Requests\StoreManagedPostgresDatabaseUserRequest;
use App\Http\Requests\UpdateManagedPostgresDatabaseUserPasswordRequest;
use App\Models\Domain;
use App\Models\ManagedPostgresDatabase;
use App\Models\ManagedPostgresDatabaseUser;
use App\Services\PostgresAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PostgresDatabaseController extends Controller
{
    public function json(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('viewDb', $domain);

        $databases = ManagedPostgresDatabase::with('pgDatabaseUsers')
            ->where('domain_id', $domain->id)
            ->get();

        return response()->json($databases);
    }

    public function store(StoreManagedPostgresDatabaseRequest $request, Domain $domain, PostgresAdminService $pgAdmin): JsonResponse
    {
        $this->authorize('manageDb', $domain);

        $validated = $request->validated();

        try {
            $pgAdmin->createUser($validated['pg_user'], $validated['pg_password']);
            $pgAdmin->createDatabase($validated['db_name'], $validated['pg_user']);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('Invalid database name or username.'),
            ], 422);
        } catch (\Throwable $e) {
            Log::error("PostgreSQL provision failed for {$validated['db_name']}: {$e->getMessage()}");

            return response()->json([
                'status' => 'error',
                'message' => __('PostgreSQL operation failed: :error', ['error' => $e->getMessage()]),
            ], 500);
        }

        $managedDb = DB::transaction(function () use ($domain, $validated, $request) {
            $managedDb = ManagedPostgresDatabase::create([
                'domain_id' => $domain->id,
                'db_name' => $validated['db_name'],
                'created_by' => $request->user()->id,
            ]);

            ManagedPostgresDatabaseUser::create([
                'managed_pg_database_id' => $managedDb->id,
                'pg_user' => $validated['pg_user'],
                'pg_password_encrypted' => $validated['pg_password'],
                'created_by' => $request->user()->id,
            ]);

            return $managedDb;
        });

        Log::info("PostgreSQL database {$validated['db_name']} provisioned for domain {$domain->fqdn}");

        return response()->json([
            'status' => 'success',
            'message' => __('Database and user created successfully.'),
            'database' => $managedDb->load('pgDatabaseUsers'),
        ]);
    }

    public function storeUser(StoreManagedPostgresDatabaseUserRequest $request, Domain $domain, ManagedPostgresDatabase $pgDatabase, PostgresAdminService $pgAdmin): JsonResponse
    {
        $this->authorize('manageDb', $domain);
        abort_unless((int) $pgDatabase->domain_id === (int) $domain->id, 404);

        $validated = $request->validated();

        try {
            $pgAdmin->createUser($validated['pg_user'], $validated['pg_password']);
            $pgAdmin->grantPrivileges($pgDatabase->db_name, $validated['pg_user']);

            ManagedPostgresDatabaseUser::create([
                'managed_pg_database_id' => $pgDatabase->id,
                'pg_user' => $validated['pg_user'],
                'pg_password_encrypted' => $validated['pg_password'],
                'created_by' => $request->user()->id,
            ]);

            return response()->json(['status' => 'success', 'message' => __('User created successfully.')]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => __('Invalid database username.')], 422);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function updateUserPassword(
        UpdateManagedPostgresDatabaseUserPasswordRequest $request,
        Domain $domain,
        ManagedPostgresDatabaseUser $pgUser,
        PostgresAdminService $pgAdmin,
    ): JsonResponse {
        $this->authorize('manageDb', $domain);
        $pgUser->loadMissing('managedPostgresDatabase');
        abort_unless((int) $pgUser->managedPostgresDatabase?->domain_id === (int) $domain->id, 404);

        $validated = $request->validated();

        try {
            $pgAdmin->changePassword($pgUser->pg_user, $validated['pg_password']);
            $pgUser->update(['pg_password_encrypted' => $validated['pg_password']]);

            return response()->json(['status' => 'success', 'message' => 'Database user password updated.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroyDatabase(Request $request, Domain $domain, ManagedPostgresDatabase $pgDatabase, PostgresAdminService $pgAdmin): JsonResponse
    {
        $this->authorize('manageDb', $domain);
        abort_unless((int) $pgDatabase->domain_id === (int) $domain->id, 404);

        try {
            // Drop the database first so owner users no longer own anything
            $pgAdmin->dropDatabase($pgDatabase->db_name);

            foreach ($pgDatabase->pgDatabaseUsers as $pgUser) {
                $pgAdmin->dropUser($pgUser->pg_user);
                $pgUser->delete();
            }

            $pgDatabase->delete();

            return response()->json(['status' => 'success', 'message' => 'Database deleted.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroyUser(Request $request, Domain $domain, ManagedPostgresDatabaseUser $pgUser, PostgresAdminService $pgAdmin): JsonResponse
    {
        $this->authorize('manageDb', $domain);
        $pgUser->loadMissing('managedPostgresDatabase');
        abort_unless((int) $pgUser->managedPostgresDatabase?->domain_id === (int) $domain->id, 404);

        try {
            $pgAdmin->revokeFromDatabase($pgUser->managedPostgresDatabase->db_name, $pgUser->pg_user);
            $pgAdmin->dropUser($pgUser->pg_user);
            $pgUser->delete();

            return response()->json(['status' => 'success', 'message' => 'Database user deleted.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
