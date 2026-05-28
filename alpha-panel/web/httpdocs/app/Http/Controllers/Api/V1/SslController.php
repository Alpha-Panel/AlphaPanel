<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\GenerateCsrRequest;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\SslCertificate;
use App\Services\SslCertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SslController extends ApiController
{
    public function index(Domain $domain): JsonResponse
    {
        $certs = $domain->sslCertificates()
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $certs]);
    }

    public function requestLetsEncrypt(Request $request, Domain $domain, SslCertificateService $sslService): JsonResponse
    {
        $this->ensureAdmin($request);

        $cert = $sslService->requestLetsEncrypt($domain);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ssl_letsencrypt_requested',
            'domain_id' => $domain->id,
            'summary' => $domain->fqdn,
        ]);

        return response()->json(['data' => $cert], 201);
    }

    public function generateSelfSigned(Request $request, Domain $domain, SslCertificateService $sslService): JsonResponse
    {
        $this->ensureAdmin($request);

        $cert = $sslService->generateSelfSigned($domain);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ssl_self_signed_generated',
            'domain_id' => $domain->id,
            'summary' => $domain->fqdn,
        ]);

        return response()->json(['data' => $cert], 201);
    }

    public function generateCsr(GenerateCsrRequest $request, Domain $domain, SslCertificateService $sslService): JsonResponse
    {
        $this->ensureAdmin($request);

        $cert = $sslService->generateCsr($domain, $request->validated());

        return response()->json(['data' => $cert], 201);
    }

    public function downloadCsr(Domain $domain, SslCertificate $cert): Response
    {
        abort_unless($cert->domain_id === $domain->id, 404);

        return response($cert->csr_content ?? '', 200, [
            'Content-Type' => 'application/pkcs10',
            'Content-Disposition' => "attachment; filename=\"{$domain->fqdn}.csr\"",
        ]);
    }

    public function validateKey(Request $request, Domain $domain, SslCertificateService $sslService): JsonResponse
    {
        $validated = $request->validate(['private_key' => 'required|string']);

        $valid = $sslService->validatePrivateKey($validated['private_key']);

        return response()->json(['data' => ['valid' => $valid]]);
    }

    public function upload(Request $request, Domain $domain, SslCertificateService $sslService): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'certificate' => 'required|string',
            'private_key' => 'required|string',
            'chain' => 'nullable|string',
        ]);

        $cert = $sslService->uploadCertificate($domain, $validated);

        return response()->json(['data' => $cert], 201);
    }

    public function activate(Request $request, Domain $domain, SslCertificate $cert, SslCertificateService $sslService): JsonResponse
    {
        abort_unless($cert->domain_id === $domain->id, 404);
        $this->ensureAdmin($request);

        $sslService->activate($domain, $cert);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ssl_activated',
            'domain_id' => $domain->id,
            'summary' => "cert #{$cert->id} for {$domain->fqdn}",
        ]);

        return response()->json(['data' => $domain->fresh('activeSslCertificate')]);
    }

    public function completeCsr(Request $request, Domain $domain, SslCertificate $cert, SslCertificateService $sslService): JsonResponse
    {
        abort_unless($cert->domain_id === $domain->id, 404);
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'signed_certificate' => 'required|string',
            'chain' => 'nullable|string',
        ]);

        $sslService->completeCsr($cert, $validated);

        return response()->json(['data' => $cert->fresh()]);
    }

    public function show(Domain $domain, SslCertificate $cert): JsonResponse
    {
        abort_unless($cert->domain_id === $domain->id, 404);

        return response()->json(['data' => $cert]);
    }

    public function export(Domain $domain, SslCertificate $cert, SslCertificateService $sslService): Response
    {
        abort_unless($cert->domain_id === $domain->id, 404);

        $zip = $sslService->exportAsZip($cert);

        return response($zip, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => "attachment; filename=\"ssl-{$domain->fqdn}.zip\"",
        ]);
    }

    public function import(Request $request, Domain $domain, SslCertificateService $sslService): JsonResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'certificate' => 'required|string',
            'private_key' => 'required|string',
            'chain' => 'nullable|string',
        ]);

        $cert = $sslService->importCertificate($domain, $validated);

        return response()->json(['data' => $cert], 201);
    }

    public function destroy(Request $request, Domain $domain, SslCertificate $cert, SslCertificateService $sslService): Response
    {
        abort_unless($cert->domain_id === $domain->id, 404);
        $this->ensureAdmin($request);

        $sslService->delete($cert);

        return response()->noContent();
    }

    public function cancel(Request $request, Domain $domain, SslCertificateService $sslService): JsonResponse
    {
        $this->ensureAdmin($request);

        $sslService->cancelPending($domain);

        return response()->json(['message' => __('Pending certificate request cancelled.')]);
    }

    private function ensureAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403, 'Admin access required.');
        }
    }
}
