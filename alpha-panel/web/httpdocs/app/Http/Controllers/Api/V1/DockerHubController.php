<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\DockerHubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DockerHubController extends ApiController
{
    public function __construct(private readonly DockerHubService $dockerHub) {}

    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|max:100']);
        $results = $this->dockerHub->search($request->input('q'));

        return response()->json(['data' => $results]);
    }

    public function popular(): JsonResponse
    {
        return response()->json(['data' => $this->dockerHub->getPopularImages()]);
    }

    public function tags(Request $request): JsonResponse
    {
        $request->validate(['image' => 'required|string']);

        return response()->json(['data' => $this->dockerHub->getTags($request->input('image'))]);
    }
}
