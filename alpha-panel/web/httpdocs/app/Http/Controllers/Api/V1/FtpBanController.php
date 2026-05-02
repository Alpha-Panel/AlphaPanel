<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\FtpBanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FtpBanController extends ApiController
{
    public function __construct(private readonly FtpBanService $ftpBan) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->ftpBan->getActiveBans()]);
    }

    public function data(): JsonResponse
    {
        return response()->json(['bans' => $this->ftpBan->getActiveBans()]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->ban($request);
    }

    public function destroyAll(Request $request): Response
    {
        $request->validate(['ip' => 'required|string']);
        $this->ftpBan->permitHost($request->input('ip'));

        return response()->noContent();
    }

    public function log(): JsonResponse
    {
        return response()->json(['data' => $this->ftpBan->getBanLog()]);
    }

    public function whitelist(): JsonResponse
    {
        return response()->json(['data' => $this->ftpBan->getWhitelist()]);
    }

    public function ban(Request $request): JsonResponse
    {
        $validated = $request->validate(['ip' => 'required|string']);
        $this->ftpBan->banHost($validated['ip']);

        return response()->json(['message' => __('IP banned.')], 201);
    }

    public function unban(Request $request): JsonResponse
    {
        $request->validate(['ip' => 'required|string']);
        $this->ftpBan->permitHost($request->input('ip'));

        return response()->json(['message' => __('IP unbanned.')]);
    }

    public function addWhitelist(Request $request): JsonResponse
    {
        $validated = $request->validate(['ip' => 'required|string', 'note' => 'nullable|string']);
        $this->ftpBan->addToWhitelist($validated['ip'], $validated['note'] ?? null, $request->user()->id);

        return response()->json(['message' => __('IP whitelisted.')], 201);
    }

    public function removeWhitelist(Request $request): Response
    {
        $request->validate(['ip' => 'required|string']);
        $this->ftpBan->removeFromWhitelist($request->input('ip'));

        return response()->noContent();
    }
}
