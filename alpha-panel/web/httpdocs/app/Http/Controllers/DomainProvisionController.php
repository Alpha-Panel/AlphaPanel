<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionDomainJob;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DomainProvisionController extends Controller
{
    public function provision(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('provision', $domain);

        $createDns = (bool) $request->input('create_dns_record', false);

        ProvisionDomainJob::dispatch(
            $domain,
            $request->user()->id,
            $createDns,
            app()->getLocale(),
            actorIpAddress: $request->ip(),
            actorPort: is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
        );

        return response()->json([
            'status' => 'success',
            'message' => "Provisioning job dispatched for {$domain->fqdn}.",
        ]);
    }
}
