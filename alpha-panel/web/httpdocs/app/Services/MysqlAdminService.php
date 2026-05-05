<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PDO;
use PDOException;

class MysqlAdminService
{
    private ?PDO $pdo = null;

    protected function connect(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = config('panel.db_admin_host');
        $user = config('panel.db_admin_user');
        $pass = config('panel.db_admin_pass');
        $port = config('panel.db_admin_port', 3306);

        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return $this->pdo;
    }

    /**
     * Sanitize an identifier (database name or username) to prevent SQL injection.
     */
    protected function sanitizeIdentifier(string $identifier): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);
    }

    /**
     * Create a MySQL database.
     *
     * @throws PDOException
     */
    public function createDatabase(string $dbName): void
    {
        $db = $this->connect();
        $safe = $this->sanitizeIdentifier($dbName);
        $db->exec("CREATE DATABASE `{$safe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        Log::info("Created MySQL database: {$safe}");
    }

    /**
     * Create a MySQL user with a password.
     *
     * @throws PDOException
     */
    public function createUser(string $username, string $password): void
    {
        $db = $this->connect();
        $safe = $this->sanitizeIdentifier($username);
        $stmt = $db->prepare("CREATE USER ?@'%' IDENTIFIED BY ?");
        $stmt->execute([$safe, $password]);
        Log::info("Created MySQL user: {$safe}");
    }

    /**
     * Grant all privileges on a database to a user.
     *
     * @throws PDOException
     */
    public function grantPrivileges(string $dbName, string $username): void
    {
        $db = $this->connect();
        $safeDb = $this->sanitizeIdentifier($dbName);
        $safeUser = $this->sanitizeIdentifier($username);
        $db->exec("GRANT ALL PRIVILEGES ON `{$safeDb}`.* TO '{$safeUser}'@'%' WITH GRANT OPTION");
        $db->exec('FLUSH PRIVILEGES');
        Log::info("Granted privileges on {$safeDb} to {$safeUser}");
    }

    /**
     * Drop a MySQL user.
     *
     * @throws PDOException
     */
    public function dropUser(string $username): void
    {
        $db = $this->connect();
        $safe = $this->sanitizeIdentifier($username);
        $db->exec("DROP USER IF EXISTS '{$safe}'@'%'");
        $db->exec('FLUSH PRIVILEGES');
        Log::info("Dropped MySQL user: {$safe}");
    }

    /**
     * Drop a MySQL database.
     *
     * @throws PDOException
     */
    public function dropDatabase(string $dbName): void
    {
        $db = $this->connect();
        $safe = $this->sanitizeIdentifier($dbName);
        $db->exec("DROP DATABASE IF EXISTS `{$safe}`");
        Log::info("Dropped MySQL database: {$safe}");
    }

    /**
     * Get current MySQL process list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProcessList(): array
    {
        $db = $this->connect();
        $stmt = $db->query('SHOW FULL PROCESSLIST');

        return $stmt->fetchAll();
    }

    /**
     * Change a MySQL user's password.
     *
     * @throws PDOException
     */
    public function changePassword(string $username, string $newPassword): void
    {
        $db = $this->connect();
        $safe = $this->sanitizeIdentifier($username);
        $stmt = $db->prepare("ALTER USER ?@'%' IDENTIFIED BY ?");
        $stmt->execute([$safe, $newPassword]);
        $db->exec('FLUSH PRIVILEGES');
        Log::info("Changed password for MySQL user: {$safe}");
    }

    /**
     * Set a MySQL global variable at runtime (no restart required).
     *
     * @throws PDOException
     */
    public function setGlobal(string $variable, string $value): void
    {
        $db = $this->connect();
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $variable);
        $stmt = $db->prepare("SET GLOBAL `{$safe}` = ?");
        $stmt->execute([$value]);
        Log::info("SET GLOBAL {$safe} applied");
    }

    /**
     * Purge binary logs older than the given number of days.
     *
     * @throws PDOException
     */
    public function purgeBinaryLogs(int $days): void
    {
        $db = $this->connect();
        $stmt = $db->prepare('PURGE BINARY LOGS BEFORE DATE_SUB(NOW(), INTERVAL ? DAY)');
        $stmt->execute([$days]);
        Log::info("Purged binary logs older than {$days} day(s)");
    }
}
