<?php

namespace App\Http\Controllers;

use App\Enums\SupervisorType;
use App\Models\Domain;
use App\Models\DomainSupervisor;
use App\Services\SupervisorConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DomainSupervisorController extends Controller
{
    public function index(Domain $domain): Response
    {
        $this->authorize('update', $domain);

        $supervisors = $domain->supervisors()
            ->get()
            ->keyBy(fn (DomainSupervisor $s): string => $s->type->value);

        $processes = [];

        foreach (SupervisorType::cases() as $type) {
            $existing = $supervisors->get($type->value);

            $processes[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'enabled' => $existing?->enabled ?? false,
                'num_procs' => $existing?->num_procs ?? ($type === SupervisorType::Queue ? 3 : 1),
                'supports_num_procs' => $type->supportsNumProcs(),
            ];
        }

        return Inertia::render('Domains/Supervisors', [
            'domain' => [
                'id' => $domain->id,
                'fqdn' => $domain->fqdn,
            ],
            'processes' => $processes,
        ]);
    }

    public function update(Request $request, Domain $domain, SupervisorConfigService $configService): JsonResponse
    {
        $this->authorize('update', $domain);

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(array_column(SupervisorType::cases(), 'value'))],
            'enabled' => ['required', 'boolean'],
            'num_procs' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        $type = SupervisorType::from($validated['type']);

        $supervisor = DomainSupervisor::updateOrCreate(
            ['domain_id' => $domain->id, 'type' => $type],
            [
                'enabled' => $validated['enabled'],
                'num_procs' => $type->supportsNumProcs()
                    ? ($validated['num_procs'] ?? 1)
                    : 1,
            ],
        );

        try {
            $configService->syncSingle($supervisor);

            return response()->json([
                'status' => 'success',
                'message' => $validated['enabled']
                    ? __(':process enabled successfully.', ['process' => $type->label()])
                    : __(':process disabled successfully.', ['process' => $type->label()]),
                'enabled' => $supervisor->enabled,
                'num_procs' => $supervisor->num_procs,
            ]);
        } catch (\Throwable $e) {
            Log::error("Supervisor update failed for {$domain->fqdn}/{$type->value}: {$e->getMessage()}");

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to update supervisor configuration: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }
}
