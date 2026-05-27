<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Mail\StoreAliasApiRequest;
use App\Http\Resources\Api\V1\AliasResource;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\Mail\Exceptions\MailProviderException;
use App\Services\Mail\MailProviderResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailAliasApiController extends ApiController
{
    public function __construct(private readonly MailProviderResolver $resolver) {}

    public function index(Request $request, Domain $domain): JsonResponse
    {
        $this->authorizeDomain($request, $domain);
        $provider = $this->resolver->for($domain);

        return response()->json([
            'provider' => $provider->key(),
            'data' => AliasResource::collection($provider->listAliases($domain))->resolve(),
        ]);
    }

    public function store(StoreAliasApiRequest $request, Domain $domain): JsonResponse
    {
        $this->authorizeDomain($request, $domain);
        $data = $request->validated();
        try {
            $alias = $this->resolver->for($domain)->createAlias(
                $domain,
                $data['from_local_part'],
                $data['to_address'],
            );
        } catch (MailProviderException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'mail_alias_created',
            'domain_id' => $domain->id,
            'summary' => "{$data['from_local_part']}@{$domain->fqdn} → {$data['to_address']}",
        ]);

        return response()->json(['data' => (new AliasResource($alias))->resolve()], 201);
    }

    public function destroy(Request $request, Domain $domain, string $localPart): JsonResponse
    {
        $this->authorizeDomain($request, $domain);
        try {
            $this->resolver->for($domain)->deleteAlias($domain, $localPart);
        } catch (MailProviderException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'mail_alias_deleted',
            'domain_id' => $domain->id,
            'summary' => "{$localPart}@{$domain->fqdn}",
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
}
