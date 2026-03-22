<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class AddEarlyHints
{
    /**
     * Critical theme assets that every page loads (Cryptograph theme).
     *
     * @var array<int, array{path: string, as: string}>
     */
    private const THEME_ASSETS = [
        // CSS — render-blocking, highest priority
        ['path' => '/themes/Cryptograph/assets/css/pace.min.css', 'as' => 'style'],
        ['path' => '/themes/Cryptograph/assets/css/bootstrap-extended.css', 'as' => 'style'],
        ['path' => '/themes/Cryptograph/assets/css/app.css', 'as' => 'style'],
        ['path' => '/themes/Cryptograph/assets/css/icons.css', 'as' => 'style'],
        ['path' => '/themes/Cryptograph/assets/plugins/simplebar/css/simplebar.css', 'as' => 'style'],
        ['path' => '/themes/Cryptograph/assets/plugins/metismenu/css/metisMenu.min.css', 'as' => 'style'],
        ['path' => '/css/AlphaPanel.css', 'as' => 'style'],
        ['path' => '/themes/default/app-assets/vendors/css/extensions/toastr.min.css', 'as' => 'style'],

        // JS — core scripts loaded on every page
        ['path' => '/themes/Cryptograph/assets/js/pace.min.js', 'as' => 'script'],
        ['path' => '/themes/Cryptograph/assets/js/bootstrap.bundle.min.js', 'as' => 'script'],
        ['path' => '/themes/Cryptograph/assets/js/jquery.min.js', 'as' => 'script'],
        ['path' => '/themes/Cryptograph/assets/plugins/simplebar/js/simplebar.min.js', 'as' => 'script'],
        ['path' => '/themes/Cryptograph/assets/plugins/metismenu/js/metisMenu.min.js', 'as' => 'script'],
        ['path' => '/themes/Cryptograph/assets/js/app.js', 'as' => 'script'],
        ['path' => '/themes/default/app-assets/vendors/js/extensions/toastr.min.js', 'as' => 'script'],
        ['path' => '/js/AlphaPanel.js', 'as' => 'script'],
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldAddHints($request, $response)) {
            return $response;
        }

        $links = $this->buildLinkHeader();

        if ($links !== '') {
            $existing = $response->headers->get('Link', '');
            $response->headers->set('Link', $existing ? $existing.', '.$links : $links);
        }

        return $response;
    }

    private function shouldAddHints(Request $request, Response $response): bool
    {
        if ($request->ajax() || $request->wantsJson()) {
            return false;
        }

        $contentType = $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'text/html');
    }

    private function buildLinkHeader(): string
    {
        /** @var string $header */
        $header = Cache::remember('early_hints_link_header', 3600, function () {
            $parts = [];

            // Vite manifest assets — ES modules need crossorigin, CSS does not
            foreach ($this->getViteAssets() as $asset) {
                $crossorigin = $asset['as'] === 'script' ? '; crossorigin' : '';
                $parts[] = "<{$asset['url']}>; rel=preload; as={$asset['as']}{$crossorigin}";
            }

            // Theme assets
            foreach (self::THEME_ASSETS as $asset) {
                $parts[] = "<{$asset['path']}>; rel=preload; as={$asset['as']}";
            }

            // Google Fonts preconnect
            $parts[] = '<https://fonts.googleapis.com>; rel=preconnect';
            $parts[] = '<https://fonts.gstatic.com>; rel=preconnect; crossorigin';

            return implode(', ', $parts);
        });

        return $header;
    }

    /**
     * Read Vite manifest and return entry-point URLs.
     *
     * @return array<int, array{url: string, as: string}>
     */
    private function getViteAssets(): array
    {
        $manifestPath = public_path('build/manifest.json');

        if (! file_exists($manifestPath)) {
            return [];
        }

        /** @var array<string, array{file: string, src?: string, isEntry?: bool}> $manifest */
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $assets = [];

        foreach ($manifest as $entry) {
            if (empty($entry['isEntry'])) {
                continue;
            }

            $file = '/build/'.$entry['file'];
            $as = str_ends_with($file, '.css') ? 'style' : 'script';
            $assets[] = ['url' => $file, 'as' => $as];
        }

        return $assets;
    }
}
