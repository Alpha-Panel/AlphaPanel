<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpdateService
{
    private string $agentUrl;

    private string $agentSecret;

    public function __construct()
    {
        $this->agentUrl = rtrim((string) config('panel.update.agent_url'), '/');
        $this->agentSecret = (string) config('panel.update.agent_secret');
    }

    private function request(int $timeout = 30): PendingRequest
    {
        return Http::withToken($this->agentSecret)
            ->baseUrl($this->agentUrl)
            ->timeout($timeout)
            ->throw();
    }

    public function isAgentHealthy(): bool
    {
        try {
            $response = Http::baseUrl($this->agentUrl)
                ->timeout(5)
                ->get('/health');

            return $response->ok() && ($response->json('status') === 'ok');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{panel_update: array|null, mysql_update: array|null}
     */
    public function checkForUpdates(): array
    {
        $response = $this->request(60)->get('/check');

        $raw = $response->json();

        $data = $this->normalizeCheckResult($raw);

        Cache::put('system:latest_version_check', $data, now()->addHours(6));

        $hasUpdate = ! empty($data['panel_update']) || ! empty($data['mysql_update']);
        Cache::put('system:update_available', $hasUpdate, now()->addHours(6));

        return $data;
    }

    /**
     * Transform raw agent response to the format the frontend expects.
     *
     * @return array{panel_update: array|null, mysql_update: array|null}
     */
    private function normalizeCheckResult(array $raw): array
    {
        $panel = $raw['panel'] ?? [];
        $mysql = $raw['mysql'] ?? [];

        $panelUpdate = null;
        if (! empty($panel['update_available'])) {
            $panelUpdate = [
                'latest_version' => $panel['latest'] ?? $panel['latest_version'] ?? 'unknown',
                'release_notes' => $panel['release_notes'] ?? null,
                'release_url' => $panel['release_url'] ?? null,
            ];
        }

        $mysqlUpdate = null;
        if (! empty($mysql['minor_update_available']) || ! empty($mysql['major_upgrade_available'])) {
            $targetVersion = ! empty($mysql['major_upgrade_available'])
                ? ($mysql['latest_major'] ?? $mysql['latest_minor'] ?? 'unknown')
                : ($mysql['latest_minor'] ?? 'unknown');

            $mysqlUpdate = [
                'current_version' => $mysql['current'] ?? 'unknown',
                'target_version' => $targetVersion,
                'is_major' => ! empty($mysql['major_upgrade_available']),
            ];
        }

        return [
            'panel_update' => $panelUpdate,
            'mysql_update' => $mysqlUpdate,
        ];
    }

    /**
     * Read version.json from project root (mounted volume).
     *
     * @return array{version: string, channel: string, services: array}
     */
    public function getCurrentVersion(): array
    {
        $path = config('panel.compose_project_root').'/version.json';

        if (! file_exists($path)) {
            return ['version' => 'unknown', 'channel' => 'stable', 'services' => []];
        }

        return json_decode(file_get_contents($path), true) ?? [];
    }

    /**
     * Trigger panel code update. Returns task_id.
     */
    public function updatePanel(): string
    {
        $response = $this->request(10)->post('/update/panel');

        return $response->json('task_id');
    }

    /**
     * Trigger MySQL upgrade preparation (dump + test container).
     */
    public function prepareMysqlUpgrade(string $targetVersion): string
    {
        $response = $this->request(10)->post('/upgrade/mysql/prepare', [
            'target_version' => $targetVersion,
        ]);

        return $response->json('task_id');
    }

    /**
     * Apply the MySQL upgrade (swap data dirs, update .env).
     */
    public function applyMysqlUpgrade(): string
    {
        $response = $this->request(10)->post('/upgrade/mysql/apply');

        return $response->json('task_id');
    }

    /**
     * Rollback a failed MySQL upgrade.
     */
    public function rollbackMysqlUpgrade(): string
    {
        $response = $this->request(10)->post('/upgrade/mysql/rollback');

        return $response->json('task_id');
    }

    /**
     * Delete the MySQL data backup after successful upgrade.
     */
    public function cleanupMysqlBackup(): bool
    {
        try {
            $response = $this->request(30)->post('/upgrade/mysql/cleanup');

            return $response->json('status') === 'ok';
        } catch (\Throwable $e) {
            Log::error('Failed to cleanup MySQL backup', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array{task_id: string, percent: int, message: string, status: string}
     */
    public function getTaskStatus(string $taskId): array
    {
        $response = $this->request(10)->get("/status/{$taskId}/current");

        return $response->json();
    }
}
