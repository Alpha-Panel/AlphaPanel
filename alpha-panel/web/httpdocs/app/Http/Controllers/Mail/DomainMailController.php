<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\Mail\Exceptions\MailProviderException;
use App\Services\Mail\Exceptions\MailProviderUnavailableException;
use App\Services\Mail\MailProviderResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DomainMailController extends Controller
{
    public function __construct(private readonly MailProviderResolver $resolver) {}

    public function __invoke(Request $request, Domain $domain): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $domain->owner_user_id !== $user->id) {
            abort(403);
        }

        $provider = $this->resolver->for($domain);
        $mailboxes = [];
        $aliases = [];
        $providerError = null;

        try {
            $mailboxes = $provider->listMailboxes($domain)->map(fn ($m) => $m->toArray())->all();
            $aliases = $provider->listAliases($domain)->map(fn ($a) => $a->toArray())->all();
        } catch (MailProviderUnavailableException $e) {
            if ($user->isAdmin()) {
                return redirect()
                    ->route('mail.settings.edit')
                    ->with('error', __('Mail provider not configured: :msg', ['msg' => $e->getMessage()]));
            }
            $providerError = __('Mail provider not configured. Ask an administrator to set it up.');
        } catch (MailProviderException $e) {
            $providerError = $e->getMessage();
        }

        $hostname = (string) config('panel.mail.hostname');

        return Inertia::render('Domains/Mail', [
            'domain' => [
                'id' => $domain->id,
                'fqdn' => $domain->fqdn,
                'mail_hosting' => $domain->mail_hosting->value,
            ],
            'provider' => $provider->key(),
            'mailboxes' => $mailboxes,
            'aliases' => $aliases,
            'provider_error' => $providerError,
            'webmail_url' => $hostname ? 'https://'.$hostname.'/' : null,
        ]);
    }
}
