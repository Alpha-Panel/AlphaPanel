<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpersonationController extends ApiController
{
    public function start(Request $request, User $user, ImpersonationService $service): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $service->start($user);

        return response()->json(['message' => __('Now impersonating :name.', ['name' => $user->name])]);
    }

    public function stop(ImpersonationService $service): JsonResponse
    {
        $service->stop();

        return response()->json(['message' => __('Impersonation ended.')]);
    }
}
