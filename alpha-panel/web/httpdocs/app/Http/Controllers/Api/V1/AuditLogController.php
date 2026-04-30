<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()
            ->with(['user:id,name,email', 'domain:id,fqdn'])
            ->when($request->input('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->input('action'), fn ($q, $v) => $q->where('action', $v))
            ->when($request->input('domain_id'), fn ($q, $v) => $q->where('domain_id', $v))
            ->orderByDesc('created_at');

        return response()->json($this->paginate($query));
    }

    public function optionUsers(): JsonResponse
    {
        $users = User::query()->whereHas('auditLogs')->orderBy('name')->get(['id', 'name', 'email']);

        return response()->json(['data' => $users]);
    }

    public function optionActions(): JsonResponse
    {
        $actions = AuditLog::query()->distinct()->pluck('action')->sort()->values();

        return response()->json(['data' => $actions]);
    }

    public function optionDomains(): JsonResponse
    {
        $domains = Domain::query()->whereHas('auditLogs')->orderBy('fqdn')->get(['id', 'fqdn']);

        return response()->json(['data' => $domains]);
    }
}
