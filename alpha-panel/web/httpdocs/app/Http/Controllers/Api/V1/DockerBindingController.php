<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreDockerServiceDomainBindingRequest;
use App\Models\DockerServiceDomainBinding;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class DockerBindingController extends ApiController
{
    public function index(Domain $domain): JsonResponse
    {
        $bindings = $domain->dockerServiceBindings()->with('dockerService')->get();

        return response()->json(['data' => $bindings]);
    }

    public function store(StoreDockerServiceDomainBindingRequest $request, Domain $domain): JsonResponse
    {
        $binding = $domain->dockerServiceBindings()->create($request->validated());

        return response()->json(['data' => $binding->load('dockerService')], 201);
    }

    public function destroy(Domain $domain, DockerServiceDomainBinding $binding): Response
    {
        abort_unless($binding->domain_id === $domain->id, 404);
        $binding->delete();

        return response()->noContent();
    }
}
