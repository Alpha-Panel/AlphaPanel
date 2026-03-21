<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DockerHubService
{
    private const API_BASE = 'https://hub.docker.com/v2';

    /**
     * Search Docker Hub for images.
     *
     * @return array{results: array, count: int}
     */
    public function search(string $query, int $page = 1, int $pageSize = 25): array
    {
        $cacheKey = "dockerhub:search:{$query}:{$page}:{$pageSize}";

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($query, $page, $pageSize) {
            $response = Http::timeout(15)
                ->get(self::API_BASE.'/search/repositories', [
                    'query' => $query,
                    'page' => $page,
                    'page_size' => $pageSize,
                ]);

            if (! $response->successful()) {
                Log::warning("Docker Hub search failed: {$response->status()}");

                return ['results' => [], 'count' => 0];
            }

            $data = $response->json();

            return [
                'results' => collect($data['results'] ?? [])->map(fn (array $item) => [
                    'name' => $item['repo_name'] ?? $item['name'] ?? '',
                    'description' => $item['short_description'] ?? $item['description'] ?? '',
                    'star_count' => $item['star_count'] ?? 0,
                    'pull_count' => $item['pull_count'] ?? 0,
                    'is_official' => $item['is_official'] ?? false,
                ])->all(),
                'count' => $data['count'] ?? 0,
            ];
        });
    }

    /**
     * Get tags for a Docker Hub image.
     *
     * @return array{results: array, count: int}
     */
    public function getTags(string $image, int $page = 1, int $pageSize = 50): array
    {
        // Handle official images (no namespace) vs user images
        $path = str_contains($image, '/') ? $image : "library/{$image}";
        $cacheKey = "dockerhub:tags:{$image}:{$page}:{$pageSize}";

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($path, $page, $pageSize) {
            $response = Http::timeout(15)
                ->get(self::API_BASE."/repositories/{$path}/tags", [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'ordering' => 'last_updated',
                ]);

            if (! $response->successful()) {
                Log::warning("Docker Hub tags fetch failed: {$response->status()}");

                return ['results' => [], 'count' => 0];
            }

            $data = $response->json();

            return [
                'results' => collect($data['results'] ?? [])->map(fn (array $tag) => [
                    'name' => $tag['name'] ?? '',
                    'last_updated' => $tag['last_updated'] ?? null,
                    'full_size' => $tag['full_size'] ?? 0,
                    'digest' => $tag['digest'] ?? null,
                ])->all(),
                'count' => $data['count'] ?? 0,
            ];
        });
    }

    /**
     * Get curated list of popular Docker images.
     *
     * @return array<int, array{name: string, description: string, icon: string, category: string}>
     */
    public function getPopularImages(): array
    {
        return Cache::remember('dockerhub:popular', now()->addHour(), function () {
            return [
                ['name' => 'nginx', 'description' => 'High-performance HTTP server and reverse proxy', 'icon' => 'fa-solid fa-server', 'category' => 'Web Server'],
                ['name' => 'mysql', 'description' => 'MySQL relational database server', 'icon' => 'lni lni-mysql', 'category' => 'Database'],
                ['name' => 'postgres', 'description' => 'PostgreSQL object-relational database', 'icon' => 'fa-solid fa-database', 'category' => 'Database'],
                ['name' => 'redis', 'description' => 'In-memory data structure store and cache', 'icon' => 'fa-solid fa-bolt', 'category' => 'Cache'],
                ['name' => 'mongo', 'description' => 'Document-oriented NoSQL database', 'icon' => 'fa-solid fa-leaf', 'category' => 'Database'],
                ['name' => 'mariadb', 'description' => 'Community-developed MySQL fork', 'icon' => 'fa-solid fa-database', 'category' => 'Database'],
                ['name' => 'node', 'description' => 'JavaScript runtime built on V8', 'icon' => 'fa-brands fa-node-js', 'category' => 'Runtime'],
                ['name' => 'python', 'description' => 'Python programming language runtime', 'icon' => 'fa-brands fa-python', 'category' => 'Runtime'],
                ['name' => 'rabbitmq', 'description' => 'Open-source message broker', 'icon' => 'fa-solid fa-envelope', 'category' => 'Messaging'],
                ['name' => 'elasticsearch', 'description' => 'Distributed search and analytics engine', 'icon' => 'fa-solid fa-magnifying-glass', 'category' => 'Search'],
                ['name' => 'memcached', 'description' => 'High-performance distributed memory cache', 'icon' => 'fa-solid fa-memory', 'category' => 'Cache'],
                ['name' => 'httpd', 'description' => 'Apache HTTP Server', 'icon' => 'fa-solid fa-server', 'category' => 'Web Server'],
                ['name' => 'grafana/grafana', 'description' => 'Open-source monitoring and observability platform', 'icon' => 'fa-solid fa-chart-line', 'category' => 'Monitoring'],
                ['name' => 'prom/prometheus', 'description' => 'Systems monitoring and alerting toolkit', 'icon' => 'fa-solid fa-fire', 'category' => 'Monitoring'],
                ['name' => 'adminer', 'description' => 'Database management in a single PHP file', 'icon' => 'fa-solid fa-table', 'category' => 'Database'],
                ['name' => 'wordpress', 'description' => 'Content management system', 'icon' => 'fa-brands fa-wordpress', 'category' => 'CMS'],
                ['name' => 'ghost', 'description' => 'Professional publishing platform', 'icon' => 'fa-solid fa-ghost', 'category' => 'CMS'],
                ['name' => 'minio/minio', 'description' => 'High-performance object storage', 'icon' => 'fa-solid fa-hard-drive', 'category' => 'Storage'],
            ];
        });
    }
}
