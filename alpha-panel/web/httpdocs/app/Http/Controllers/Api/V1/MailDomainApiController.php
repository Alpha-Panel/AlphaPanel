<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MailHosting;
use App\Http\Resources\Api\V1\AliasResource;
use App\Http\Resources\Api\V1\MailboxResource;
use App\Models\Domain;
use App\Services\Mail\Exceptions\MailHostingDisabledException;
use App\Services\Mail\Exceptions\MailProviderException;
use App\Services\Mail\Exceptions\MailProviderUnavailableException;
use App\Services\Mail\MailProviderResolver;
use App\Services\Mail\MailSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailDomainApiController extends ApiController
{
    public function __construct(
        private readonly MailProviderResolver $resolver,
        private readonly MailSettingsService $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only list domains whose hosting provider is currently enabled.
        // Remote is always listable (no provider call needed); Local/Zimbra
        // depend on their feature flags.
        $listableHostings = array_values(array_filter(
            array_map(
                fn (MailHosting $h) => $h->value,
                $this->settings->availableHostings(),
            ),
            fn (string $v) => $v !== MailHosting::Disabled->value,
        ));

        $domains = Domain::query()
            ->whereIn('mail_hosting', $listableHostings)
            ->when(! $user->isAdmin(), fn ($q) => $q->where('owner_user_id', $user->id))
            ->orderBy('fqdn')
            ->get(['id', 'fqdn', 'mail_hosting', 'mail_remote_mx_host', 'mail_remote_mx_priority']);

        return response()->json([
            'data' => $domains->map(fn (Domain $d): array => [
                'id' => $d->id,
                'fqdn' => $d->fqdn,
                'mail_hosting' => $d->mail_hosting->value,
                'mail_hosting_label' => $d->mail_hosting->shortLabel(),
                'mail_remote_mx_host' => $d->mail_remote_mx_host,
                'mail_remote_mx_priority' => $d->mail_remote_mx_priority,
            ])->all(),
            'features' => [
                'mailu' => $this->settings->mailuEnabled(),
                'zimbra' => $this->settings->zimbraEnabled(),
            ],
        ]);
    }

    public function show(Request $request, Domain $domain): JsonResponse
    {
        $this->authorizeDomain($request, $domain);

        try {
            $provider = $this->resolver->for($domain);
        } catch (MailHostingDisabledException $e) {
            return response()->json([
                'message' => __('Mail hosting is disabled for this domain.'),
                'error' => $e->getMessage(),
            ], 409);
        }

        $mailboxes = [];
        $aliases = [];
        $providerError = null;

        try {
            $mailboxes = MailboxResource::collection($provider->listMailboxes($domain))->resolve();
            $aliases = AliasResource::collection($provider->listAliases($domain))->resolve();
        } catch (MailProviderUnavailableException $e) {
            $providerError = $e->getMessage();
        } catch (MailProviderException $e) {
            $providerError = $e->getMessage();
        }

        $hostname = (string) config('panel.mail.hostname');

        return response()->json([
            'data' => [
                'domain' => [
                    'id' => $domain->id,
                    'fqdn' => $domain->fqdn,
                    'mail_hosting' => $domain->mail_hosting->value,
                ],
                'provider' => $provider->key(),
                'mailboxes' => $mailboxes,
                'aliases' => $aliases,
                'provider_error' => $providerError,
                'webmail_url' => $hostname !== '' ? 'https://'.$hostname.'/' : null,
            ],
        ]);
    }

    private function authorizeDomain(Request $request, Domain $domain): void
    {
        $user = $request->user();
        if (! $user->isAdmin() && $domain->owner_user_id !== $user->id) {
            abort(403, 'You do not have access to this domain.');
        }
    }
}
