<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\UpdateService;
use Illuminate\Http\JsonResponse;

class SystemUpdateController extends ApiController
{
    public function __construct(private readonly UpdateService $updateService) {}

    public function check(): JsonResponse
    {
        $result = $this->updateService->checkForUpdates();

        return response()->json(['data' => $result]);
    }

    public function updatePanel(): JsonResponse
    {
        $taskId = $this->updateService->updatePanel();

        return response()->json(['data' => ['task_id' => $taskId]]);
    }

    public function mysqlPrepare(): JsonResponse
    {
        $taskId = $this->updateService->prepareMysqlUpgrade('latest');

        return response()->json(['data' => ['task_id' => $taskId]]);
    }

    public function mysqlApply(): JsonResponse
    {
        $taskId = $this->updateService->applyMysqlUpgrade();

        return response()->json(['data' => ['task_id' => $taskId]]);
    }

    public function mysqlRollback(): JsonResponse
    {
        $taskId = $this->updateService->rollbackMysqlUpgrade();

        return response()->json(['data' => ['task_id' => $taskId]]);
    }

    public function mysqlCleanup(): JsonResponse
    {
        $this->updateService->cleanupMysqlBackup();

        return response()->json(['message' => __('Cleanup done.')]);
    }

    public function taskStatus(string $taskId): JsonResponse
    {
        $status = $this->updateService->getTaskStatus($taskId);

        return response()->json(['data' => $status]);
    }
}
