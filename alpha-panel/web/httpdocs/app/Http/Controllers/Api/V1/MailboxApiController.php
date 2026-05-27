<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Mail\SetForwardingApiRequest;
use App\Http\Requests\Api\V1\Mail\SetPasswordApiRequest;
use App\Http\Requests\Api\V1\Mail\StoreMailboxApiRequest;
use App\Http\Requests\Api\V1\Mail\UpdateMailboxApiRequest;
use App\Http\Resources\Api\V1\MailboxResource;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\Mail\Exceptions\MailProviderException;
use App\Services\Mail\MailProviderResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailboxApiController extends ApiController
{
    public function __construct(private readonly MailProviderResolver $resolver) {}

    public function index(Request $request, Domain $domain): JsonResponse
    {
        $this->authorizeDomain($request, $domain);
        $provider = $this->resolver->for($domain);

        return response()->json([
            'provider' => $provider->key(),
            'data' => MailboxResource::collection($provider->listMailboxes($domain))->resolve(),
        ]);
    }

    public function show(Request $request, Domain $domain, string $localPart): JsonResponse
    {
        $this->authorizeDomain($request, $domain);
        $mailbox = $this->resolver->for($domain)->findMailbox($domain, $localPart);
        if ($mailbox === null) {
            abort(404, 'Mailbox not found.');
        }

        return response()->json(['data' => (new MailboxResource($mailbox))->resolve()]);
    }

    public function store(StoreMailboxApiRequest $request, Domain $domain): JsonResponse
    {
        $this->authorizeDomain($request, $domain);
        $data = $request->validated();
        try {
            $mailbox = $this->resolver->for($domain)->createMailbox(
                $domain,
                $data['local_part'],
                $data['password'],
                array_filter([
                    'display_name' => $data['display_name'] ?? null,
                    'quota_bytes' => $data['quota_bytes'] ?? null,
                ], static fn ($v) => $v !== null),
            );
        } catch (MailProviderException $e) {
            return $this->providerError($e);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'mailbox_created',
            'domain_id' => $domain->id,
            'summary' => "{$data['local_part']}@{$domain->fqdn}",
        ]);

        return response()->json(['data' => (new MailboxResource($mailbox))->resolve()], 201);
    }

    public function update(UpdateMailboxApiRequest $request, Domain $domain, string $localPart): JsonResponse
    {
        $this->authorizeDomain($request, $domain);
        try {
            $mailbox = $this->resolver->for($domain)->updateMailbox($domain, $localPart, $request->validated());
        } catch (MailProviderException $e) {
            return $this->providerError($e);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'mailbox_updated',
            'domain_id' => $domain->id,
            'summary' => "{$localPart}@{$domain->fqdn}",
        ]);

        return response()->json(['data' => (new MailboxResource($mailbox))->resolve()]);
    }

    public function destroy(Request $request, Domain $domain, string $localPart): JsonResponse
    {
        $this->authorizeDomain($request, $domain);
        try {
            $this->resolver->for($domain)->deleteMailbox($domain, $localPart);
        } catch (MailProviderException $e) {
            return $this->providerError($e);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'mailbox_deleted',
            'domain_id' => $domain->id,
            'summary' => "{$localPart}@{$domain->fqdn}",
        ]);

        return response()->json(['ok' => true]);
    }

    public function setPassword(SetPasswordApiRequest $request, Domain $domain, string $localPart): JsonResponse
    {
        $this->authorizeDomain($request, $domain);
        try {
            $this->resolver->for($domain)->setPassword($domain, $localPart, $request->validated()['password']);
        } catch (MailProviderException $e) {
            return $this->providerError($e);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'mailbox_password_reset',
            'domain_id' => $domain->id,
            'summary' => "{$localPart}@{$domain->fqdn}",
        ]);

        return response()->json(['ok' => true]);
    }

    public function setForwarding(SetForwardingApiRequest $request, Domain $domain, string $localPart): JsonResponse
    {
        $this->authorizeDomain($request, $domain);
        $data = $request->validated();
        try {
            $this->resolver->for($domain)->setForwarding(
                $domain,
                $localPart,
                $data['addresses'] ?? [],
                $data['keep_local'] ?? true,
            );
        } catch (MailProviderException $e) {
            return $this->providerError($e);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'mailbox_forwarding_set',
            'domain_id' => $domain->id,
            'summary' => "{$localPart}@{$domain->fqdn} → ".implode(', ', $data['addresses'] ?? []),
        ]);

        return response()->json(['ok' => true]);
    }

    private function authorizeDomain(Request $request, Domain $domain): void
    {
        $user = $request->user();
        if (! $user->isAdmin() && $domain->owner_user_id !== $user->id) {
            abort(403, 'You do not have access to this domain.');
        }
    }

    private function providerError(MailProviderException $e): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
    }
}
