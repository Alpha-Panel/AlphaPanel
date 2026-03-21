<?php

namespace App\Http\Controllers;

use App\Services\DockerHubService;
use App\Services\PortainerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DockerHubController extends Controller
{
    public function __construct(
        private DockerHubService $dockerHub,
        private PortainerService $portainer,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:2|max:100',
            'page' => 'integer|min:1',
        ]);

        $results = $this->dockerHub->search(
            $request->input('query'),
            $request->integer('page', 1),
        );

        return response()->json($results);
    }

    public function popular(Request $request): JsonResponse
    {
        return response()->json([
            'images' => $this->dockerHub->getPopularImages(),
        ]);
    }

    public function tags(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|string|max:255',
        ]);

        $tags = $this->dockerHub->getTags($request->input('image'));

        return response()->json($tags);
    }

    public function imageConfig(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|string|max:255',
        ]);

        try {
            $info = $this->portainer->inspectImage($request->input('image'));

            $config = $info['Config'] ?? [];

            return response()->json([
                'env' => $this->parseEnvVars($config['Env'] ?? []),
                'exposed_ports' => array_keys($config['ExposedPorts'] ?? []),
                'volumes' => array_keys($config['Volumes'] ?? []),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'env' => [],
                'exposed_ports' => [],
                'volumes' => [],
            ]);
        }
    }

    /**
     * Parse Docker ENV array into key-value pairs.
     * Filter out common system env vars.
     *
     * @param  list<string>  $envList
     * @return array<string, string>
     */
    private function parseEnvVars(array $envList): array
    {
        $systemVars = ['PATH', 'GOSU_VERSION', 'LANG', 'LANGUAGE', 'LC_ALL', 'GPG_KEYS', 'PHP_VERSION', 'PHP_INI_DIR'];
        $result = [];

        foreach ($envList as $item) {
            $parts = explode('=', $item, 2);
            $key = $parts[0];
            $value = $parts[1] ?? '';

            if (! in_array($key, $systemVars)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
