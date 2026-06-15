<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PDO;
use PDOException;
use Throwable;

class PostgresAdminService
{
    private ?PDO $pdo = null;

    protected function connect(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = config('panel.pg_admin_host');
        $user = config('panel.pg_admin_user');
        $pass = config('panel.pg_admin_pass');
        $port = config('panel.pg_admin_port', 5432);

        $this->pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        return $this->pdo;
    }

    private function connectTo(string $dbName): PDO
    {
        $host = config('panel.pg_admin_host');
        $user = config('panel.pg_admin_user');
        $pass = config('panel.pg_admin_pass');
        $port = config('panel.pg_admin_port', 5432);

        return new PDO("pgsql:host={$host};port={$port};dbname={$dbName}", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * Validate a PostgreSQL identifier and return it unchanged.
     *
     * @throws \InvalidArgumentException when the identifier is empty, too long, or malformed
     */
    protected function sanitizeIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid PostgreSQL identifier.');
        }

        return $identifier;
    }

    public function roleExists(string $username): bool
    {
        $safe = $this->sanitizeIdentifier($username);
        $stmt = $this->connect()->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?');
        $stmt->execute([$safe]);

        return (bool) $stmt->fetchColumn();
    }

    public function databaseExists(string $dbName): bool
    {
        $safe = $this->sanitizeIdentifier($dbName);
        $stmt = $this->connect()->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $stmt->execute([$safe]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Create a PostgreSQL user (role) with password.
     *
     * @throws PDOException|\RuntimeException
     */
    public function createUser(string $username, string $password): void
    {
        $safe = $this->sanitizeIdentifier($username);

        if ($this->roleExists($safe)) {
            throw new \RuntimeException("PostgreSQL role \"{$safe}\" already exists. Drop it via pgAdmin (Roles → right-click → Delete/Drop) or choose a different username.");
        }

        $db = $this->connect();
        $db->exec("CREATE USER \"{$safe}\" WITH PASSWORD ".$db->quote($password));
        Log::info("Created PostgreSQL user: {$safe}");
    }

    /**
     * Create a PostgreSQL database owned by the given user and grant schema access.
     *
     * Grants ALL on the public schema to the owner, required in PostgreSQL 15+
     * where the PUBLIC role no longer has CREATE on public by default.
     *
     * @throws PDOException|\RuntimeException
     */
    public function createDatabase(string $dbName, string $ownerUser): void
    {
        $safeDb = $this->sanitizeIdentifier($dbName);
        $safeOwner = $this->sanitizeIdentifier($ownerUser);

        if ($this->databaseExists($safeDb)) {
            throw new \RuntimeException("PostgreSQL database \"{$safeDb}\" already exists. Drop it via pgAdmin or choose a different name.");
        }

        $db = $this->connect();
        $db->exec("CREATE DATABASE \"{$safeDb}\" OWNER \"{$safeOwner}\"");
        Log::info("Created PostgreSQL database: {$safeDb} (owner: {$safeOwner})");

        // Grant public schema access in the new database (needed in PG 15+)
        $dbConn = $this->connectTo($safeDb);
        $dbConn->exec("GRANT ALL ON SCHEMA public TO \"{$safeOwner}\"");
    }

    /**
     * Grant access on an existing database to an additional user.
     *
     * @throws PDOException
     */
    public function grantPrivileges(string $dbName, string $username): void
    {
        $safeDb = $this->sanitizeIdentifier($dbName);
        $safeUser = $this->sanitizeIdentifier($username);
        $db = $this->connect();
        $db->exec("GRANT CONNECT ON DATABASE \"{$safeDb}\" TO \"{$safeUser}\"");

        $dbConn = $this->connectTo($safeDb);
        $dbConn->exec("GRANT ALL ON SCHEMA public TO \"{$safeUser}\"");
        $dbConn->exec("GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO \"{$safeUser}\"");
        $dbConn->exec("GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO \"{$safeUser}\"");
        Log::info("Granted privileges on {$safeDb} to {$safeUser}");
    }

    /**
     * Revoke all privileges a user holds on a specific database before dropping.
     */
    public function revokeFromDatabase(string $dbName, string $username): void
    {
        $safeDb = $this->sanitizeIdentifier($dbName);
        $safeUser = $this->sanitizeIdentifier($username);

        try {
            $dbConn = $this->connectTo($safeDb);
            $dbConn->exec("REVOKE ALL ON ALL TABLES IN SCHEMA public FROM \"{$safeUser}\"");
            $dbConn->exec("REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM \"{$safeUser}\"");
            $dbConn->exec("REVOKE ALL ON SCHEMA public FROM \"{$safeUser}\"");
        } catch (Throwable) {
            // Database may not exist or user has no objects — safe to continue
        }

        try {
            $db = $this->connect();
            $db->exec("REVOKE ALL ON DATABASE \"{$safeDb}\" FROM \"{$safeUser}\"");
        } catch (Throwable) {
            // Ignore if already gone
        }

        Log::info("Revoked privileges on {$safeDb} from {$safeUser}");
    }

    /**
     * Terminate all connections to a database, then drop it.
     *
     * @throws PDOException
     */
    public function dropDatabase(string $dbName): void
    {
        $safe = $this->sanitizeIdentifier($dbName);
        $db = $this->connect();

        $stmt = $db->prepare(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()'
        );
        $stmt->execute([$dbName]);

        $db->exec("DROP DATABASE IF EXISTS \"{$safe}\"");
        Log::info("Dropped PostgreSQL database: {$safe}");
    }

    /**
     * Drop a PostgreSQL role (user).
     *
     * @throws PDOException
     */
    public function dropUser(string $username): void
    {
        $safe = $this->sanitizeIdentifier($username);
        $db = $this->connect();
        $db->exec("DROP ROLE IF EXISTS \"{$safe}\"");
        Log::info("Dropped PostgreSQL role: {$safe}");
    }

    /**
     * Change a PostgreSQL user's password.
     *
     * @throws PDOException
     */
    public function changePassword(string $username, string $newPassword): void
    {
        $safe = $this->sanitizeIdentifier($username);
        $db = $this->connect();
        $db->exec("ALTER USER \"{$safe}\" WITH PASSWORD ".$db->quote($newPassword));
        Log::info("Changed password for PostgreSQL user: {$safe}");
    }
}
