<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Services\DomainRequestLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DomainLogController extends Controller
{
    public function index(Domain $domain): Response
    {
        $this->authorize('view', $domain);

        return Inertia::render('Domains/Logs', [
            'domain' => $domain,
        ]);
    }

    public function entries(Request $request, Domain $domain, DomainRequestLogService $logService): JsonResponse
    {
        $this->authorize('view', $domain);

        $entries = $logService->getDomainEntries($domain, [
            'q' => $request->string('q')->toString(),
            'since' => $request->string('since')->toString(),
            'max_lines' => $request->integer('max_lines', 1200),
        ]);

        return response()->json([
            'entries' => $entries,
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
