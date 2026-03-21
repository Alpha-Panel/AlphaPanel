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
     * @return array{panel: array, mysql: array}
     */
    public function checkForUpdates(): array
    {
        $response = $this->request(60)->get('/check');

        $data = $response->json();

        Cache::put('system:latest_version_check', $data, now()->addHours(6));

        return $data;
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
