<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\CrowdSecService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrowdSecController extends ApiController
{
    public function __construct(private readonly CrowdSecService $crowdSec) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->crowdSec->getSummary()]);
    }

    public function details(): JsonResponse
    {
        return response()->json(['data' => $this->crowdSec->getDetails()]);
    }

    public function decisions(Request $request): JsonResponse
    {
        $page = (int) $request->input('page', 1);
        $perPage = min((int) $request->input('per_page', 25), 100);
        $search = $request->input('search');

        return response()->json(['data' => $this->crowdSec->getDecisionsPaginated($page, $perPage, $search)]);
    }
}
