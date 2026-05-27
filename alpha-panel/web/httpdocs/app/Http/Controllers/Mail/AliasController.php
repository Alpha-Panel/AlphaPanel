<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\StoreAliasRequest;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\Mail\Exceptions\MailProviderException;
use App\Services\Mail\Exceptions\MailProviderUnavailableException;
use App\Services\Mail\MailProviderResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AliasController extends Controller
{
    public function __construct(private readonly MailProviderResolver $resolver) {}

    public function index(Request $request, Domain $domain): Response|RedirectResponse
    {
        $this->authorizeDomain($request, $domain);
        $provider = $this->resolver->for($domain);

        try {
            $aliases = $provider->listAliases($domain);
        } catch (MailProviderUnavailableException $e) {
            if ($request->user()->isAdmin()) {
                return redirect()
                    ->route('mail.settings.edit')
                    ->with('error', __('Mail provider not configured: :msg', ['msg' => $e->getMessage()]));
            }

            return redirect()
                ->route('domains.show', $domain)
                ->with('error', __('Mail provider not configured. Ask an administrator to set it up.'));
        }

        return Inertia::render('Mail/Aliases/Index', [
            'domain' => ['id' => $domain->id, 'fqdn' => $domain->fqdn],
            'provider' => $provider->key(),
            'aliases' => $aliases->map(fn ($a) => $a->toArray()),
        ]);
    }

    public function store(StoreAliasRequest $request, Domain $domain): RedirectResponse
    {
        $data = $request->validated();
        try {
            $this->resolver->for($domain)->createAlias(
                $domain,
                $data['from_local_part'],
                $data['to_address'],
            );
        } catch (MailProviderException $e) {
            return back()->withErrors(['provider' => $e->getMessage()]);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'mail_alias_created',
            'domain_id' => $domain->id,
            'summary' => "{$data['from_local_part']}@{$domain->fqdn} → {$data['to_address']}",
        ]);

        return back()->with('success', __('Alias created.'));
    }

    public function destroy(Request $request, Domain $domain, string $localPart): RedirectResponse
    {
        $this->authorizeDomain($request, $domain);
        try {
            $this->resolver->for($domain)->deleteAlias($domain, $localPart);
        } catch (MailProviderException $e) {
            return back()->withErrors(['provider' => $e->getMessage()]);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'mail_alias_deleted',
            'domain_id' => $domain->id,
            'summary' => "{$localPart}@{$domain->fqdn}",
        ]);

        return back()->with('success', __('Alias deleted.'));
    }

    private function authorizeDomain(Request $request, Domain $domain): void
    {
        $user = $request->user();
        if (! $user->isAdmin() && $domain->owner_user_id !== $user->id) {
            abort(403);
        }
    }
}
