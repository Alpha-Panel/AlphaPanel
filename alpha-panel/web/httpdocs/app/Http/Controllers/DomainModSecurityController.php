<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDomainModSecurityRequest;
use App\Jobs\ProvisionDomainJob;
use App\Models\Domain;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DomainModSecurityController extends Controller
{
    public function index(Domain $domain): Response
    {
        $this->authorize('update', $domain);

        return Inertia::render('Domains/ModSecurity', [
            'domain' => $domain,
        ]);
    }

    public function update(UpdateDomainModSecurityRequest $request, Domain $domain): RedirectResponse
    {
        $this->authorize('update', $domain);

        $validated = $request->validated();
        $enabled = (bool) ($validated['modsecurity_enabled'] ?? false);
        $mode = $enabled && ($validated['modsecurity_mode'] ?? null) === 'detection_only'
            ? 'detection_only'
            : 'active';

        $domain->update([
            'modsecurity_enabled' => $enabled,
            'modsecurity_mode' => $enabled ? $mode : null,
        ]);

        if ($domain->wasChanged(['modsecurity_enabled', 'modsecurity_mode'])) {
            ProvisionDomainJob::dispatch(
                $domain,
                $request->user()->id,
                false,
                app()->getLocale(),
                actorIpAddress: $request->ip(),
                actorPort: is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
            );
        }

        return redirect()
            ->route('domains.modsecurity.index', $domain)
            ->with('success', __('ModSecurity settings updated successfully.'));
    }
}
