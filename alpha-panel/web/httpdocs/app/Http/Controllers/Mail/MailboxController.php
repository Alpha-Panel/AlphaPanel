<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\SetForwardingRequest;
use App\Http\Requests\Mail\SetPasswordRequest;
use App\Http\Requests\Mail\StoreMailboxRequest;
use App\Http\Requests\Mail\UpdateMailboxRequest;
use App\Models\Domain;
use App\Services\Mail\Exceptions\MailProviderException;
use App\Services\Mail\Exceptions\MailProviderUnavailableException;
use App\Services\Mail\MailProviderResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MailboxController extends Controller
{
    public function __construct(private readonly MailProviderResolver $resolver) {}

    public function index(Request $request, Domain $domain): Response|RedirectResponse
    {
        $this->authorizeDomain($request, $domain);
        $provider = $this->resolver->for($domain);

        try {
            $mailboxes = $provider->listMailboxes($domain);
        } catch (MailProviderUnavailableException $e) {
            if ($request->user()->isAdmin()) {
                return redirect()
                    ->route('mail.settings.edit')
                    ->with('error', __('Mail provider not configured: :msg', ['msg' => $e->getMessage()]));
            }

            return redirect()
                ->route('domains.show', $domain)
                ->with('error', __('Mail provider not configured. Ask an administrator to set it up.'));
        } catch (MailProviderException $e) {
            return Inertia::render('Mail/Mailboxes/Index', [
                'domain' => [
                    'id' => $domain->id,
                    'fqdn' => $domain->fqdn,
                    'mail_hosting' => $domain->mail_hosting->value,
                ],
                'provider' => $provider->key(),
                'mailboxes' => [],
                'provider_error' => $e->getMessage(),
            ]);
        }

        return Inertia::render('Mail/Mailboxes/Index', [
            'domain' => [
                'id' => $domain->id,
                'fqdn' => $domain->fqdn,
                'mail_hosting' => $domain->mail_hosting->value,
            ],
            'provider' => $provider->key(),
            'mailboxes' => $mailboxes->map(fn ($m) => $m->toArray()),
            'provider_error' => null,
        ]);
    }

    public function create(Request $request, Domain $domain): Response
    {
        $this->authorizeDomain($request, $domain);

        return Inertia::render('Mail/Mailboxes/Create', [
            'domain' => ['id' => $domain->id, 'fqdn' => $domain->fqdn],
            'provider' => $this->resolver->for($domain)->key(),
        ]);
    }

    public function store(StoreMailboxRequest $request, Domain $domain): RedirectResponse
    {
        $provider = $this->resolver->for($domain);
        $data = $request->validated();
        try {
            $provider->createMailbox(
                $domain,
                $data['local_part'],
                $data['password'],
                array_filter([
                    'display_name' => $data['display_name'] ?? null,
                    'quota_bytes' => $data['quota_bytes'] ?? null,
                ], static fn ($v) => $v !== null),
            );
        } catch (MailProviderException $e) {
            return back()->withErrors(['provider' => $e->getMessage()]);
        }

        return redirect()
            ->route('mail.mailboxes.index', $domain)
            ->with('success', __('Mailbox created.'));
    }

    public function update(UpdateMailboxRequest $request, Domain $domain, string $localPart): RedirectResponse
    {
        $provider = $this->resolver->for($domain);
        try {
            $provider->updateMailbox($domain, $localPart, $request->validated());
        } catch (MailProviderException $e) {
            return back()->withErrors(['provider' => $e->getMessage()]);
        }

        return back()->with('success', __('Mailbox updated.'));
    }

    public function destroy(Request $request, Domain $domain, string $localPart): RedirectResponse
    {
        $this->authorizeDomain($request, $domain);
        try {
            $this->resolver->for($domain)->deleteMailbox($domain, $localPart);
        } catch (MailProviderException $e) {
            return back()->withErrors(['provider' => $e->getMessage()]);
        }

        return redirect()
            ->route('mail.mailboxes.index', $domain)
            ->with('success', __('Mailbox deleted.'));
    }

    public function setPassword(SetPasswordRequest $request, Domain $domain, string $localPart): RedirectResponse
    {
        try {
            $this->resolver->for($domain)->setPassword(
                $domain,
                $localPart,
                $request->validated()['password'],
            );
        } catch (MailProviderException $e) {
            return back()->withErrors(['provider' => $e->getMessage()]);
        }

        return back()->with('success', __('Password updated.'));
    }

    public function setForwarding(SetForwardingRequest $request, Domain $domain, string $localPart): RedirectResponse
    {
        $data = $request->validated();
        try {
            $this->resolver->for($domain)->setForwarding(
                $domain,
                $localPart,
                $data['addresses'] ?? [],
                $data['keep_local'] ?? true,
            );
        } catch (MailProviderException $e) {
            return back()->withErrors(['provider' => $e->getMessage()]);
        }

        return back()->with('success', __('Forwarding updated.'));
    }

    private function authorizeDomain(Request $request, Domain $domain): void
    {
        $user = $request->user();
        if (! $user->isAdmin() && $domain->owner_user_id !== $user->id) {
            abort(403);
        }
    }
}
