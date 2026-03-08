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
}
