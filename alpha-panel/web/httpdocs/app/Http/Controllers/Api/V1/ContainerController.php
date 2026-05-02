<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\PortainerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContainerController extends ApiController
{
    public function index(Request $request, PortainerService $portainer): JsonResponse
    {
        $all = filter_var($request->query('all', 'false'), FILTER_VALIDATE_BOOLEAN);
        $name = $request->query('name');

        $filters = [];
        if ($name) {
            $filters['name'] = [$name];
        }

        $raw = $portainer->listContainers($filters, $all);

        $containers = collect($raw)->map(fn (array $c) => [
            'id' => $c['Id'],
            'short_id' => substr($c['Id'], 0, 12),
            'names' => array_map(fn (string $n) => ltrim($n, '/'), $c['Names'] ?? []),
            'image' => $c['Image'] ?? '',
            'state' => $c['State'] ?? '',
            'status' => $c['Status'] ?? '',
            'created' => $c['Created'] ?? null,
            'ports' => collect($c['Ports'] ?? [])->map(fn (array $p) => [
                'ip' => $p['IP'] ?? null,
                'private' => $p['PrivatePort'] ?? null,
                'public' => $p['PublicPort'] ?? null,
                'type' => $p['Type'] ?? null,
            ])->values()->all(),
            'labels' => $c['Labels'] ?? [],
        ])->values()->all();

        return response()->json(['data' => $containers]);
    }

    public function show(string $id, PortainerService $portainer): JsonResponse
    {
        $data = $portainer->inspectContainer($id);

        return response()->json(['data' => $data]);
    }
}
