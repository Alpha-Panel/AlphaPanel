<?php

namespace App\Jobs;

use App\Events\DatabaseProvisionProgress;
use App\Models\ManagedDatabase;
use App\Models\ManagedDatabaseUser;
use App\Services\MysqlAdminService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProvisionDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public ManagedDatabase $managedDatabase,
        public string $dbUser,
        public string $dbPassword,
        public int $userId,
    ) {}

    public function handle(MysqlAdminService $mysqlAdmin): void
    {
        $db = $this->managedDatabase;
        $domainId = $db->domain_id;

        try {
            DatabaseProvisionProgress::dispatch(
                $this->userId, $domainId, $db->db_name, 'Creating database...', 'progress',
            );

            $mysqlAdmin->createDatabase($db->db_name);

            DatabaseProvisionProgress::dispatch(
                $this->userId, $domainId, $db->db_name, 'Creating user...', 'progress',
            );

            $mysqlAdmin->createUser($this->dbUser, $this->dbPassword);
            $mysqlAdmin->grantPrivileges($db->db_name, $this->dbUser);

            ManagedDatabaseUser::create([
                'managed_database_id' => $db->id,
                'db_user' => $this->dbUser,
                'db_password_encrypted' => $this->dbPassword,
                'created_by' => $this->userId,
            ]);

            DatabaseProvisionProgress::dispatch(
                $this->userId, $domainId, $db->db_name, 'Database created successfully!', 'completed',
            );
        } catch (\InvalidArgumentException $e) {
            Log::error("Database provision rejected invalid identifier for database id {$db->id}");

            DatabaseProvisionProgress::dispatch(
                $this->userId, $domainId, $db->db_name, 'Failed: invalid database name or username.', 'failed',
            );
        } catch (\Throwable $e) {
            Log::error("Database provision failed for {$db->db_name}: {$e->getMessage()}");

            DatabaseProvisionProgress::dispatch(
                $this->userId, $domainId, $db->db_name, "Failed: {$e->getMessage()}", 'failed',
            );
        }
    }
}
