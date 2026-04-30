<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

abstract class ApiController extends Controller
{
    protected function paginate(mixed $query, int $perPage = 25): array
    {
        $perPage = min((int) request()->input('per_page', $perPage), 100);
        $paginator = $query->paginate($perPage);

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
