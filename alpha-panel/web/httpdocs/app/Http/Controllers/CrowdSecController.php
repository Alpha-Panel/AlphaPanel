<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CrowdSecService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrowdSecController extends Controller
{
    public function index(Request $request, CrowdSecService $crowdSec): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        return Inertia::render('Security/CrowdSec', [
            'crowdsec' => $crowdSec->getDetails(),
        ]);
    }

    public function data(Request $request, CrowdSecService $crowdSec): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        return response()->json($crowdSec->getDetails());
    }

    public function decisions(Request $request, CrowdSecService $crowdSec): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(10, (int) $request->query('per_page', 50)));
        $search = $request->query('search');
        $search = is_string($search) && $search !== '' ? $search : null;

        return response()->json($crowdSec->getDecisionsPaginated($page, $perPage, $search));
    }
}
