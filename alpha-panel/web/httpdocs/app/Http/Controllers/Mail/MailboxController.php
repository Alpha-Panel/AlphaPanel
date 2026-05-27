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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

    public function update(UpdateMailboxRequest $request, Domain $domain, string $localPart): RedirectResponse|JsonResponse
    {
        $provider = $this->resolver->for($domain);
        try {
            $provider->updateMailbox($domain, $localPart, $request->validated());
        } catch (MailProviderException $e) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['provider' => $e->getMessage()]);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => __('Mailbox updated.')]);
        }

        return back()->with('success', __('Mailbox updated.'));
    }

    /**
     * Fallback for stale frontend bundles where Ziggy lost the {local} path segment.
     * Tries multiple sources because different stale-Ziggy versions ship the value
     * differently (query string, body field, Referer URL).
     */
    public function updateFallback(UpdateMailboxRequest $request, Domain $domain): JsonResponse
    {
        $localPart = $this->resolveLocalPartFromRequest($request);

        Log::warning('mail.mailbox.updateFallback.invoked', [
            'domain_id' => $domain->id,
            'resolved_local' => $localPart,
            'all_input' => $request->all(),
            'query' => $request->query(),
            'referer' => $request->header('Referer'),
            'full_url' => $request->fullUrl(),
        ]);

        if ($localPart === '') {
            return $this->staleBundleResponse($request);
        }

        // Direct dispatch — returns JSON so axios doesn't follow a back() 302 into
        // an infinite loop. update() uses back() which redirects to the Referer
        // (typically the listing page), and axios.put follows 302 with PUT method
        // by default, hitting this fallback again forever.
        $provider = $this->resolver->for($domain);
        try {
            $provider->updateMailbox($domain, $localPart, $request->validated());
        } catch (MailProviderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => __('Mailbox updated.')]);
    }

    /**
     * Same fallback for DELETE — returns JSON for the same redirect-loop reason.
     */
    public function destroyFallback(Request $request, Domain $domain): JsonResponse
    {
        $localPart = $this->resolveLocalPartFromRequest($request);

        if ($localPart === '') {
            return $this->staleBundleResponse($request);
        }

        try {
            $this->resolver->for($domain)->deleteMailbox($domain, $localPart);
        } catch (MailProviderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => __('Mailbox deleted.')]);
    }

    /**
     * When the request reaches the fallback without enough info to identify the
     * mailbox, the only honest diagnosis is "stale browser bundle". Send back a
     * Clear-Site-Data header so the browser purges caches/storage and the next
     * page load fetches the current bundle.
     */
    private function staleBundleResponse(Request $request): JsonResponse
    {
        // Status 422 — NOT 409. Inertia treats 409 as asset-version mismatch and
        // triggers auto-reload; chained with a stale bundle this causes an infinite
        // 302 redirect loop. 422 surfaces as a normal form-error toast.
        return response()->json([
            'message' => __('Mailbox identifier missing from request. Reload the page (Ctrl+Shift+R) and retry.'),
        ], 422);
    }

    /**
     * Pull mailbox local-part from any plausible request location.
     * Stale frontend bundles can ship it as:
     *   - ?local=info       (Ziggy query-string fallback)
     *   - body local/local_part/address/email
     *   - Referer URL like /mail/domains/500/mailboxes/info
     */
    private function resolveLocalPartFromRequest(Request $request): string
    {
        // Named keys — covers proper Ziggy fallback (?local=info) and any
        // body field that ships the mailbox identifier.
        $namedKeys = ['local', 'localPart', 'local_part', 'mailbox', 'mailbox_local'];
        foreach ($namedKeys as $key) {
            $value = $request->query($key) ?? $request->input($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        // Ziggy v2 positional extras land as numeric query keys (?0=info, ?1=info).
        foreach ([0, 1, 2] as $idx) {
            $value = $request->query((string) $idx);
            if (is_string($value) && $value !== '' && ! is_numeric($value)) {
                return $value;
            }
        }

        // Some bundles ship the full address — derive local part.
        $address = $request->input('address') ?? $request->input('email');
        if (is_string($address) && str_contains($address, '@')) {
            return explode('@', $address)[0];
        }

        // Last resort — Referer URL may carry /mailboxes/{local}.
        $referer = (string) $request->header('Referer', '');
        if (preg_match('~/mailboxes/([^/?#]+)~', $referer, $m)) {
            $candidate = urldecode($m[1]);
            if (! in_array($candidate, ['create', 'index'], true)) {
                return $candidate;
            }
        }

        return '';
    }

    public function destroy(Request $request, Domain $domain, string $localPart): RedirectResponse|JsonResponse
    {
        $this->authorizeDomain($request, $domain);
        try {
            $this->resolver->for($domain)->deleteMailbox($domain, $localPart);
        } catch (MailProviderException $e) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['provider' => $e->getMessage()]);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => __('Mailbox deleted.')]);
        }

        return redirect()
            ->route('mail.mailboxes.index', $domain)
            ->with('success', __('Mailbox deleted.'));
    }

    public function setPassword(SetPasswordRequest $request, Domain $domain, string $localPart): RedirectResponse|JsonResponse
    {
        try {
            $this->resolver->for($domain)->setPassword(
                $domain,
                $localPart,
                $request->validated()['password'],
            );
        } catch (MailProviderException $e) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['provider' => $e->getMessage()]);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => __('Password updated.')]);
        }

        return back()->with('success', __('Password updated.'));
    }

    public function setForwarding(SetForwardingRequest $request, Domain $domain, string $localPart): RedirectResponse|JsonResponse
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
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['provider' => $e->getMessage()]);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => __('Forwarding updated.')]);
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
