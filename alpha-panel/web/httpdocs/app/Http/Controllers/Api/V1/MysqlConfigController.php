<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\MysqlConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MysqlConfigController extends ApiController
{
    public function __construct(private MysqlConfigService $service) {}

    public function index(): JsonResponse
    {
        $fileContents = $this->service->loadAllFiles();
        $parsedValues = [];
        foreach ($fileContents as $file => $content) {
            $parsedValues[$file] = $this->service->parseFile($content);
        }

        return response()->json([
            'data' => [
                'schema' => $this->service->schemaByFile(),
                'file_contents' => $fileContents,
                'parsed_values' => $parsedValues,
                'binlog_disabled' => $this->service->isBinlogDisabled(),
            ],
        ]);
    }

    public function show(string $file): JsonResponse
    {
        $allowedFiles = ['10-security.cnf', '99-tuning.cnf', 'disable_binlog.cnf'];
        if (! in_array($file, $allowedFiles, true)) {
            return response()->json(['message' => 'Invalid config file.'], 400);
        }

        try {
            $content = $this->service->loadAllFiles()[$file] ?? '';
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to load config file: '.$e->getMessage()], 500);
        }

        $schema = $this->service->schemaByFile()[$file] ?? [];
        $parsed = $this->service->parseFile($content);

        return response()->json([
            'data' => [
                'file' => $file,
                'content' => $content,
                'parsed_values' => $parsed,
                'schema' => $schema,
            ],
        ]);
    }

    public function update(Request $request, string $file): JsonResponse
    {
        $allowedFiles = ['10-security.cnf', '99-tuning.cnf', 'disable_binlog.cnf'];
        if (! in_array($file, $allowedFiles, true)) {
            return response()->json(['message' => 'Invalid config file.'], 400);
        }

        $validated = $request->validate([
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string', 'max:512'],
        ]);

        try {
            $result = $this->service->saveStructured($file, $validated['values']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Save failed: '.$e->getMessage()], 500);
        }

        return response()->json([
            'message' => $result->setGlobalErrors
                ? 'Saved with warnings. SET GLOBAL failed for: '.implode(', ', $result->setGlobalErrors)
                : 'MySQL configuration saved.',
            'data' => [
                'file_written' => $result->fileWritten,
                'set_global_applied' => $result->setGlobalApplied,
                'restart_required' => $result->restartRequired,
                'set_global_errors' => $result->setGlobalErrors,
            ],
        ]);
    }

    public function updateRaw(Request $request, string $file): JsonResponse
    {
        $allowedFiles = ['10-security.cnf', '99-tuning.cnf', 'disable_binlog.cnf'];
        if (! in_array($file, $allowedFiles, true)) {
            return response()->json(['message' => 'Invalid config file.'], 400);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:65535'],
        ]);

        try {
            $this->service->saveRaw($file, $validated['content']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Save failed: '.$e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'MySQL configuration saved.',
            'data' => ['restart_required' => true],
        ]);
    }

    public function restart(): JsonResponse
    {
        try {
            $taskId = $this->service->restart();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Restart failed: '.$e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'MySQL restart initiated.',
            'data' => ['task_id' => $taskId],
        ]);
    }

    public function purgeBinlogs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = $validated['days'] ?? 7;

        try {
            $this->service->purgeBinaryLogs($days);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Purge failed: '.$e->getMessage()], 500);
        }

        return response()->json(['message' => "Binary logs older than {$days} day(s) purged."]);
    }
}
