<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\RunArtisanCommandRequest;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\PhpFpmSupervisorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupervisorController extends ApiController
{
    public function index(Domain $domain, PhpFpmSupervisorService $supervisor): JsonResponse
    {
        $status = $supervisor->getStatus($domain);

        return response()->json(['data' => $status]);
    }

    public function store(Request $request, Domain $domain, PhpFpmSupervisorService $supervisor): JsonResponse
    {
        $validated = $request->validate([
            'worker_num' => 'required|integer|min:1|max:256',
            'enable_worker' => 'boolean',
            'worker_watch' => 'boolean',
            'worker_max_requests' => 'nullable|integer|min:0',
        ]);

        $domain->update($validated);
        $supervisor->apply($domain);

        return response()->json(['data' => $domain->fresh()]);
    }

    public function restart(Request $request, Domain $domain, PhpFpmSupervisorService $supervisor): JsonResponse
    {
        $supervisor->restart($domain);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'supervisor_restarted', 'domain_id' => $domain->id, 'summary' => $domain->fqdn, 'ip_address' => $request->ip()]);

        return response()->json(['message' => __('Supervisor restarted.')]);
    }

    public function restartWorkers(Request $request, Domain $domain, PhpFpmSupervisorService $supervisor): JsonResponse
    {
        $supervisor->restartWorkers($domain);

        return response()->json(['message' => __('Workers restarted.')]);
    }

    public function optimize(Request $request, Domain $domain, PhpFpmSupervisorService $supervisor): JsonResponse
    {
        $supervisor->optimize($domain);

        return response()->json(['message' => __('Optimized.')]);
    }

    public function artisan(RunArtisanCommandRequest $request, Domain $domain, PhpFpmSupervisorService $supervisor): JsonResponse
    {
        $output = $supervisor->runArtisan($domain, $request->input('command'));

        return response()->json(['data' => ['output' => $output]]);
    }
}
