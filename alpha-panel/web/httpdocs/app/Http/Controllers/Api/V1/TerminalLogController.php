<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\TerminalLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TerminalLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = TerminalLog::query()
            ->with('user:id,name')
            ->when($request->input('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->orderByDesc('created_at');

        return response()->json($this->paginate($query));
    }

    public function optionUsers(): JsonResponse
    {
        $users = User::query()->whereHas('terminalLogs')->orderBy('name')->get(['id', 'name', 'email']);

        return response()->json(['data' => $users]);
    }

    public function show(TerminalLog $log): JsonResponse
    {
        return response()->json(['data' => $log->load('user:id,name')]);
    }
}
