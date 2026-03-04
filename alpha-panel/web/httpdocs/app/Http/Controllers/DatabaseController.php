<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreManagedDatabaseRequest;
use App\Http\Requests\StoreManagedDatabaseUserRequest;
use App\Http\Requests\UpdateManagedDatabaseUserPasswordRequest;
use App\Models\Domain;
use App\Models\ManagedDatabase;
use App\Models\ManagedDatabaseUser;
use App\Services\MysqlAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseController extends Controller
{
    public function index(Request $request, Domain $domain): Response
    {
        $this->authorize('manageDb', $domain);

        $databases = ManagedDatabase::with('databaseUsers')
            ->where('domain_id', $domain->id)
            ->get();

        return Inertia::render('Databases/Index', compact('domain', 'databases'));
    }

    public function json(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageDb', $domain);

        $databases = ManagedDatabase::with('databaseUsers')
            ->where('domain_id', $domain->id)
            ->get();

        return response()->json($databases);
    }

    public function store(StoreManagedDatabaseRequest $request, Domain $domain, MysqlAdminService $mysqlAdmin): JsonResponse
    {
        $this->authorize('manageDb', $domain);

        $validated = $request->validated();

        try {
            $mysqlAdmin->createDatabase($validated['db_name']);
            $mysqlAdmin->createUser($validated['db_user'], $validated['db_password']);
            $mysqlAdmin->grantPrivileges($validated['db_name'], $validated['db_user']);
        } catch (\Throwable $e) {
            Log::error("MySQL provision failed for {$validated['db_name']}: {$e->getMessage()}");

            return response()->json([
                'status' => 'error',
                'message' => __('MySQL operation failed: :error', ['error' => $e->getMessage()]),
            ], 500);
        }

        $managedDb = DB::transaction(function () use ($domain, $validated, $request) {
            $managedDb = ManagedDatabase::create([
                'domain_id' => $domain->id,
                'db_name' => $validated['db_name'],
                'created_by' => $request->user()->id,
            ]);

            ManagedDatabaseUser::create([
                'managed_database_id' => $managedDb->id,
                'db_user' => $validated['db_user'],
                'db_password_encrypted' => $validated['db_password'],
                'created_by' => $request->user()->id,
            ]);

            return $managedDb;
        });

        Log::info("Database {$validated['db_name']} provisioned for domain {$domain->fqdn}");

        return response()->json([
            'status' => 'success',
            'message' => __('Database and user created successfully.'),
            'database' => $managedDb->load('databaseUsers'),
        ]);
    }

    public function storeUser(StoreManagedDatabaseUserRequest $request, Domain $domain, ManagedDatabase $database, MysqlAdminService $mysqlAdmin): JsonResponse
    {
        $this->authorize('manageDb', $domain);
        abort_unless((int) $database->domain_id === (int) $domain->id, 404);

        $validated = $request->validated();

        try {
            $mysqlAdmin->createUser($validated['db_user'], $validated['db_password']);
            $mysqlAdmin->grantPrivileges($database->db_name, $validated['db_user']);

            ManagedDatabaseUser::create([
                'managed_database_id' => $database->id,
                'db_user' => $validated['db_user'],
                'db_password_encrypted' => $validated['db_password'],
                'created_by' => $request->user()->id,
            ]);

            return response()->json(['status' => 'success', 'message' => __('User created successfully.')]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function updateUserPassword(
        UpdateManagedDatabaseUserPasswordRequest $request,
        Domain $domain,
        ManagedDatabaseUser $user,
        MysqlAdminService $mysqlAdmin,
    ): JsonResponse {
        $this->authorize('manageDb', $domain);
        $user->loadMissing('managedDatabase');
        abort_unless((int) $user->managedDatabase?->domain_id === (int) $domain->id, 404);

        $validated = $request->validated();

        try {
            $mysqlAdmin->changePassword($user->db_user, $validated['db_password']);
            $user->update([
                'db_password_encrypted' => $validated['db_password'],
            ]);

            return response()->json(['status' => 'success', 'message' => 'Database user password updated.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroyDatabase(Request $request, Domain $domain, ManagedDatabase $database, MysqlAdminService $mysqlAdmin): JsonResponse
    {
        $this->authorize('manageDb', $domain);
        abort_unless((int) $database->domain_id === (int) $domain->id, 404);

        try {
            foreach ($database->databaseUsers as $dbUser) {
                $mysqlAdmin->dropUser($dbUser->db_user);
                $dbUser->delete();
            }

            $mysqlAdmin->dropDatabase($database->db_name);
            $database->delete();

            return response()->json(['status' => 'success', 'message' => 'Database deleted.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroyUser(Request $request, Domain $domain, ManagedDatabaseUser $user, MysqlAdminService $mysqlAdmin): JsonResponse
    {
        $this->authorize('manageDb', $domain);
        $user->loadMissing('managedDatabase');
        abort_unless((int) $user->managedDatabase?->domain_id === (int) $domain->id, 404);

        try {
            $mysqlAdmin->dropUser($user->db_user);
            $user->delete();

            return response()->json(['status' => 'success', 'message' => 'Database user deleted.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
