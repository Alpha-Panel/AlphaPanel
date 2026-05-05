<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class MysqlConfigService
{
    public function __construct(
        private UpdateService $updateService,
        private MysqlAdminService $mysqlAdmin,
    ) {}

    /**
     * @return array<int, array{key: string, file: string, type: string, label: string, description: string, set_global: bool, restart_required: bool, options: array<string>, global_var: string}>
     */
    public function schema(): array
    {
        return [
            // ── 10-security.cnf ─────────────────────────────────────
            ['key' => 'skip-name-resolve', 'file' => '10-security.cnf', 'type' => 'bool', 'label' => 'Skip Name Resolve', 'description' => 'DNS lookup bypass (performance + security).', 'set_global' => false, 'restart_required' => true, 'options' => [], 'global_var' => ''],
            ['key' => 'secure_file_priv', 'file' => '10-security.cnf', 'type' => 'string', 'label' => 'Secure File Priv', 'description' => 'Restrict LOAD DATA / SELECT INTO OUTFILE to this path.', 'set_global' => false, 'restart_required' => true, 'options' => [], 'global_var' => ''],
            ['key' => 'sql-mode', 'file' => '10-security.cnf', 'type' => 'string', 'label' => 'SQL Mode', 'description' => 'Comma-separated SQL mode flags.', 'set_global' => true, 'restart_required' => false, 'options' => [], 'global_var' => 'sql_mode'],
            ['key' => 'slow_query_log', 'file' => '10-security.cnf', 'type' => 'bool', 'label' => 'Slow Query Log', 'description' => 'Enable slow query log.', 'set_global' => true, 'restart_required' => false, 'options' => [], 'global_var' => 'slow_query_log'],
            ['key' => 'slow_query_log_file', 'file' => '10-security.cnf', 'type' => 'string', 'label' => 'Slow Query Log File', 'description' => 'Path to the slow query log file.', 'set_global' => false, 'restart_required' => true, 'options' => [], 'global_var' => ''],
            ['key' => 'long_query_time', 'file' => '10-security.cnf', 'type' => 'int', 'label' => 'Long Query Time (s)', 'description' => 'Queries slower than this (seconds) are logged.', 'set_global' => true, 'restart_required' => false, 'options' => [], 'global_var' => 'long_query_time'],

            // ── 99-tuning.cnf ────────────────────────────────────────
            ['key' => 'default-time-zone', 'file' => '99-tuning.cnf', 'type' => 'string', 'label' => 'Default Timezone', 'description' => 'Server timezone offset, e.g. +03:00.', 'set_global' => false, 'restart_required' => true, 'options' => [], 'global_var' => ''],
            ['key' => 'innodb_buffer_pool_size', 'file' => '99-tuning.cnf', 'type' => 'size', 'label' => 'InnoDB Buffer Pool Size', 'description' => '35–50% of total RAM. Biggest single performance setting.', 'set_global' => true, 'restart_required' => false, 'options' => [], 'global_var' => 'innodb_buffer_pool_size'],
            ['key' => 'innodb_buffer_pool_instances', 'file' => '99-tuning.cnf', 'type' => 'int', 'label' => 'InnoDB Buffer Pool Instances', 'description' => '1 instance per 1 GB of buffer pool size.', 'set_global' => false, 'restart_required' => true, 'options' => [], 'global_var' => ''],
            ['key' => 'tmp_table_size', 'file' => '99-tuning.cnf', 'type' => 'size', 'label' => 'Tmp Table Size', 'description' => 'Max size for internal in-memory temp tables (per-connection).', 'set_global' => true, 'restart_required' => false, 'options' => [], 'global_var' => 'tmp_table_size'],
            ['key' => 'max_heap_table_size', 'file' => '99-tuning.cnf', 'type' => 'size', 'label' => 'Max Heap Table Size', 'description' => 'Max size for user-created MEMORY tables.', 'set_global' => true, 'restart_required' => false, 'options' => [], 'global_var' => 'max_heap_table_size'],
            ['key' => 'sort_buffer_size', 'file' => '99-tuning.cnf', 'type' => 'size', 'label' => 'Sort Buffer Size', 'description' => 'Per-connection buffer for ORDER BY operations.', 'set_global' => true, 'restart_required' => false, 'options' => [], 'global_var' => 'sort_buffer_size'],
            ['key' => 'join_buffer_size', 'file' => '99-tuning.cnf', 'type' => 'size', 'label' => 'Join Buffer Size', 'description' => 'Per-connection buffer for full JOIN scans.', 'set_global' => true, 'restart_required' => false, 'options' => [], 'global_var' => 'join_buffer_size'],
            ['key' => 'max_connections', 'file' => '99-tuning.cnf', 'type' => 'int', 'label' => 'Max Connections', 'description' => 'Maximum number of simultaneous client connections.', 'set_global' => true, 'restart_required' => false, 'options' => [], 'global_var' => 'max_connections'],
            ['key' => 'wait_timeout', 'file' => '99-tuning.cnf', 'type' => 'int', 'label' => 'Wait Timeout (s)', 'description' => 'Seconds before closing idle non-interactive connection.', 'set_global' => true, 'restart_required' => false, 'options' => [], 'global_var' => 'wait_timeout'],
            ['key' => 'interactive_timeout', 'file' => '99-tuning.cnf', 'type' => 'int', 'label' => 'Interactive Timeout (s)', 'description' => 'Seconds before closing idle interactive connection.', 'set_global' => true, 'restart_required' => false, 'options' => [], 'global_var' => 'interactive_timeout'],
            ['key' => 'innodb_flush_log_at_trx_commit', 'file' => '99-tuning.cnf', 'type' => 'select', 'label' => 'InnoDB Flush Log At Trx Commit', 'description' => '1=ACID-safe, 2=fast (may lose 1s on crash), 0=fastest.', 'set_global' => true, 'restart_required' => false, 'options' => ['0', '1', '2'], 'global_var' => 'innodb_flush_log_at_trx_commit'],
            ['key' => 'innodb_flush_method', 'file' => '99-tuning.cnf', 'type' => 'select', 'label' => 'InnoDB Flush Method', 'description' => 'O_DIRECT recommended for dedicated servers.', 'set_global' => false, 'restart_required' => true, 'options' => ['O_DIRECT', 'fsync', 'O_DSYNC', 'O_DIRECT_NO_FSYNC'], 'global_var' => ''],

            // ── disable_binlog.cnf ───────────────────────────────────
            ['key' => 'skip-log-bin', 'file' => 'disable_binlog.cnf', 'type' => 'bool', 'label' => 'Disable Binary Logging', 'description' => 'Disables binary log. Requires restart. Replication will not work when disabled.', 'set_global' => false, 'restart_required' => true, 'options' => [], 'global_var' => ''],
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function schemaByFile(): array
    {
        $byFile = [];
        foreach ($this->schema() as $param) {
            $byFile[$param['file']][] = $param;
        }

        return $byFile;
    }

    /**
     * @return array<string, string>
     */
    public function loadAllFiles(): array
    {
        $files = ['10-security.cnf', '99-tuning.cnf', 'disable_binlog.cnf'];
        $result = [];
        foreach ($files as $file) {
            try {
                $result[$file] = $this->updateService->getMysqlConfigFile($file);
            } catch (\Throwable $e) {
                Log::error("Failed to load MySQL config file {$file}", ['error' => $e->getMessage()]);
                $result[$file] = "[mysqld]\n# Failed to load\n";
            }
        }

        return $result;
    }

    /**
     * Parse a .cnf [mysqld] section into key=>value pairs.
     *
     * @return array<string, string>
     */
    public function parseFile(string $content): array
    {
        $values = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '[')) {
                continue;
            }
            if (str_contains($line, '=')) {
                [$key, $val] = explode('=', $line, 2);
                $values[trim($key)] = trim($val);
            } else {
                $values[$line] = '1';
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function buildFileContent(array $values, string $file): string
    {
        $schema = array_filter($this->schema(), fn ($p) => $p['file'] === $file);
        $lines = ['[mysqld]'];

        foreach ($schema as $param) {
            $key = $param['key'];
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $val = $values[$key];

            if ($param['type'] === 'bool') {
                if ($val) {
                    $lines[] = $key;
                }
            } else {
                $lines[] = "{$key} = {$val}";
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function saveStructured(string $file, array $values): SaveResult
    {
        $content = $this->buildFileContent($values, $file);
        $this->updateService->putMysqlConfigFile($file, $content);

        $restartRequired = false;
        $setGlobalErrors = [];
        $setGlobalApplied = false;

        $schema = array_filter($this->schema(), fn ($p) => $p['file'] === $file);

        foreach ($schema as $param) {
            $key = $param['key'];
            if (! array_key_exists($key, $values)) {
                continue;
            }
            if ($param['restart_required']) {
                $restartRequired = true;
            }
            if ($param['set_global'] && $param['global_var']) {
                try {
                    $this->mysqlAdmin->setGlobal($param['global_var'], (string) $values[$key]);
                    $setGlobalApplied = true;
                } catch (\Throwable $e) {
                    Log::warning("SET GLOBAL {$param['global_var']} failed", ['error' => $e->getMessage()]);
                    $setGlobalErrors[] = $param['global_var'];
                }
            }
        }

        return new SaveResult(
            fileWritten: true,
            setGlobalApplied: $setGlobalApplied,
            restartRequired: $restartRequired,
            setGlobalErrors: $setGlobalErrors,
        );
    }

    public function saveRaw(string $file, string $content): SaveResult
    {
        $this->updateService->putMysqlConfigFile($file, $content);

        return new SaveResult(
            fileWritten: true,
            setGlobalApplied: false,
            restartRequired: true,
            setGlobalErrors: [],
        );
    }

    public function restart(): string
    {
        return $this->updateService->restartMysql();
    }

    public function purgeBinaryLogs(int $days): void
    {
        $this->mysqlAdmin->purgeBinaryLogs($days);
    }

    public function isBinlogDisabled(): bool
    {
        try {
            $content = $this->updateService->getMysqlConfigFile('disable_binlog.cnf');
            $parsed = $this->parseFile($content);

            return array_key_exists('skip-log-bin', $parsed);
        } catch (\Throwable) {
            return false;
        }
    }
}
