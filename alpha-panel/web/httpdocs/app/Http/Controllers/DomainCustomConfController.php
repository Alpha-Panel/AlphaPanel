<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Services\ReloadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class DomainCustomConfController extends Controller
{
    public function show(Domain $domain): Response
    {
        $this->authorize('update', $domain);

        $path = $this->confPath($domain);
        $content = File::exists($path) ? File::get($path) : '';

        return Inertia::render('Domains/CustomConf', [
            'domain' => $domain,
            'content' => $content,
        ]);
    }

    public function update(Request $request, Domain $domain, ReloadService $reloadService): RedirectResponse
    {
        $this->authorize('update', $domain);

        $request->validate([
            'content' => ['nullable', 'string', 'max:65536'],
        ]);

        $content = $request->string('content')->toString();
        $path = $this->confPath($domain);

        $dir = dirname($path);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($path, $content);
        $reloadService->reloadCaddy();

        return redirect()
            ->route('domains.custom-conf.show', $domain)
            ->with('success', __('Custom configuration saved and Caddy reloaded.'));
    }

    private function confPath(Domain $domain): string
    {
        return config('panel.caddy_sites_base').'/'.$domain->fqdn.'/custom.conf';
    }
}
