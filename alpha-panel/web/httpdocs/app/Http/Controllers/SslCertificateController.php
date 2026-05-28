<?php

namespace App\Http\Controllers;

use App\Enums\SslCertificateType;
use App\Enums\SslMethod;
use App\Events\SslOperationProgress;
use App\Exceptions\SslImportException;
use App\Http\Requests\GenerateCsrRequest;
use App\Http\Requests\UploadCertificateRequest;
use App\Jobs\SslActivateJob;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\SslCertificate;
use App\Services\Acme\AcmeService;
use App\Services\DomainConfigService;
use App\Services\ReloadService;
use App\Services\SslCertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class SslCertificateController extends Controller
{
    public function __construct(
        private SslCertificateService $sslCertificateService,
    ) {}

    public function index(Domain $domain): Response
    {
        $this->authorize('manageSsl', $domain);

        $own = $domain->sslCertificates()
            ->orderByDesc('created_at')
            ->get();

        $inherited = collect();

        if ($domain->isSubdomain()) {
            $apex = Domain::where('fqdn', $domain->getApexDomain())->first();

            if ($apex) {
                $apex->loadMissing('sslCertificates');
                $inherited = $apex->sslCertificates
                    ->filter(fn (SslCertificate $c): bool => $c->coversFqdn($domain->fqdn))
                    ->values();

                $inherited->each(function (SslCertificate $c) use ($apex): void {
                    $c->setAttribute('inherited_from_fqdn', $apex->fqdn);
                    $c->setAttribute('inherited_from_domain_id', $apex->id);
                });
            }
        }

        return Inertia::render('Domains/SslCertificates', [
            'domain' => $domain,
            'certificates' => $own->concat($inherited)->values(),
            'activeCertificateId' => $domain->active_ssl_certificate_id,
        ]);
    }

    public function storeLetsEncrypt(Request $request, Domain $domain): RedirectResponse
    {
        $this->authorize('manageSsl', $domain);

        $validated = $request->validate([
            'validation_method' => ['required', 'string', 'in:dns-01-cloudflare,dns-01-local,http-01'],
        ]);

        // Prevent duplicate operations — if a lock already exists, another request is in progress
        $lock = Cache::lock("ssl:operation:{$domain->id}", 300);
        if (! $lock->get()) {
            return redirect()
                ->route('domains.ssl.index', $domain)
                ->with('error', __('An SSL operation is already in progress for this domain. Please wait or cancel it first.'));
        }
        $lock->release(); // Release immediately — the job will acquire its own lock

        $sslMethod = match ($validated['validation_method']) {
            'dns-01-cloudflare' => SslMethod::CloudflareDns,
            'dns-01-local' => SslMethod::LocalDns,
            'http-01' => SslMethod::WebrootHttp,
        };

        $domain->update(['ssl_method' => $sslMethod]);

        SslActivateJob::dispatch(
            $domain,
            $request->user()->id,
            app()->getLocale(),
            $request->ip(),
            is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
        );

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ssl_letsencrypt_queued',
            'domain_id' => $domain->id,
            'summary' => "Let's Encrypt certificate requested for {$domain->fqdn} via {$validated['validation_method']}.",
        ]);

        return redirect()
            ->route('domains.ssl.index', $domain)
            ->with('success', __("Let's Encrypt certificate request started. You will be notified when complete."));
    }

    public function storeSelfSigned(
        Request $request,
        Domain $domain,
        AcmeService $acmeService,
        DomainConfigService $configService,
        ReloadService $reloadService,
    ): RedirectResponse {
        $this->authorize('manageSsl', $domain);

        $result = $acmeService->generateSelfSigned($domain);

        if (! $result->success) {
            return redirect()
                ->route('domains.ssl.index', $domain)
                ->with('error', __('Failed to generate self-signed certificate: :error', ['error' => $result->error ?? 'Unknown error']));
        }

        try {
            $certificate = $this->sslCertificateService->createFromPem(
                domain: $domain,
                type: SslCertificateType::SelfSigned,
                fullchainPem: $result->fullchainPem,
                keyPem: $result->privateKeyPem,
            );

            if ($domain->active_ssl_certificate_id === null) {
                $this->sslCertificateService->activate($domain, $certificate);
            }
        } catch (\Exception $e) {
            Log::warning("Failed to create SslCertificate record for self-signed cert on {$domain->fqdn}: {$e->getMessage()}");

            return redirect()
                ->route('domains.ssl.index', $domain)
                ->with('error', __('Self-signed certificate was generated but failed to register: :error', ['error' => $e->getMessage()]));
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ssl_self_signed_created',
            'domain_id' => $domain->id,
            'summary' => "Self-signed certificate generated for {$domain->fqdn}.",
        ]);

        return redirect()
            ->route('domains.ssl.index', $domain)
            ->with('success', __('Self-signed certificate generated successfully.'));
    }

    public function cancelSslOperation(Request $request, Domain $domain): RedirectResponse
    {
        $this->authorize('manageSsl', $domain);

        Cache::lock("ssl:operation:{$domain->id}")->forceRelease();

        app(AcmeService::class)->clearCaddyAcmeLocks($domain);

        broadcast(new SslOperationProgress(
            domain: $domain,
            percent: 0,
            message: __('SSL operation cancelled by user.'),
            status: 'cancelled',
        ));

        return redirect()
            ->route('domains.ssl.index', $domain)
            ->with('success', __('SSL operation cancelled.'));
    }

    public function generateCsr(GenerateCsrRequest $request, Domain $domain): RedirectResponse
    {
        $this->authorize('manageSsl', $domain);

        $validated = $request->validated();

        try {
            $this->sslCertificateService->generateCsr(
                $domain,
                $validated['common_name'],
                $validated['key_type'],
                [
                    'organization' => $validated['organization'] ?? null,
                    'organizational_unit' => $validated['organizational_unit'] ?? null,
                    'country' => $validated['country'] ?? null,
                    'state' => $validated['state'] ?? null,
                    'city' => $validated['locality'] ?? null,
                ],
                $validated['san_domains'] ?? [],
            );
        } catch (\Exception $e) {
            return redirect()
                ->route('domains.ssl.index', $domain)
                ->with('error', __('CSR generation failed: :error', ['error' => $e->getMessage()]));
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ssl_csr_generated',
            'domain_id' => $domain->id,
            'summary' => "CSR generated for {$validated['common_name']} on {$domain->fqdn}.",
        ]);

        return redirect()
            ->route('domains.ssl.index', $domain)
            ->with('success', __('CSR generated successfully. You can now download it and submit to a Certificate Authority.'));
    }

    public function downloadCsr(Domain $domain, SslCertificate $certificate): \Illuminate\Http\Response|RedirectResponse
    {
        $this->authorize('manageSsl', $domain);

        if ($certificate->domain_id !== $domain->id) {
            abort(404);
        }

        if (! $certificate->csr_pem) {
            return redirect()
                ->route('domains.ssl.index', $domain)
                ->with('error', __('CSR file not found.'));
        }

        return response($certificate->csr_pem, 200, [
            'Content-Type' => 'application/pkcs10',
            'Content-Disposition' => "attachment; filename=\"{$domain->fqdn}-{$certificate->id}.csr\"",
        ]);
    }

    public function uploadCertificate(UploadCertificateRequest $request, Domain $domain): RedirectResponse
    {
        $this->authorize('manageSsl', $domain);

        $validated = $request->validated();

        try {
            Log::info("SSL upload attempt for {$domain->fqdn}: key length=".strlen($validated['private_key']).', cert length='.strlen($validated['certificate']));

            // Check if the uploaded key matches an existing CSR record's key
            $existingCsr = $this->sslCertificateService->findMatchingCsrRecord($domain, $validated['private_key']);

            if ($existingCsr) {
                Log::info("Found matching CSR record ID {$existingCsr->id} for {$domain->fqdn}.");
                $this->sslCertificateService->completeCsrWithCertificate($existingCsr, $domain, $validated);
                $certificate = $existingCsr->fresh();
            } else {
                $certificate = $this->sslCertificateService->storeUploadedCert(
                    $domain,
                    $validated['certificate'],
                    $validated['private_key'],
                    $validated['ca_bundle'] ?? null,
                    $validated['label'] ?? null,
                );
                Log::info("SSL certificate uploaded for {$domain->fqdn}, record ID: {$certificate->id}.");
            }
        } catch (SslImportException $e) {
            return redirect()
                ->route('domains.ssl.index', $domain)
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error("SSL upload failed for {$domain->fqdn}: {$e->getMessage()}", [
                'domain' => $domain->fqdn,
                'exception' => $e,
            ]);

            return redirect()
                ->route('domains.ssl.index', $domain)
                ->with('error', __('Certificate upload failed. Please contact support.'));
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ssl_certificate_uploaded',
            'domain_id' => $domain->id,
            'summary' => "Custom certificate uploaded for {$domain->fqdn}.",
        ]);

        return redirect()
            ->route('domains.ssl.index', $domain)
            ->with('success', __('Certificate uploaded successfully.'));
    }

    public function activate(Request $request, Domain $domain, SslCertificate $certificate): RedirectResponse
    {
        $this->authorize('manageSsl', $domain);

        if ($certificate->domain_id !== $domain->id) {
            // Allow activating an ancestor cert on a subdomain it covers
            // (e.g. a wildcard cert owned by the apex domain).
            if (! $domain->isSubdomain()
                || $certificate->domain?->fqdn !== $domain->getApexDomain()
                || ! $certificate->coversFqdn($domain->fqdn)) {
                abort(404);
            }
        }

        if (! $certificate->certificate_pem) {
            return redirect()
                ->route('domains.ssl.index', $domain)
                ->with('error', __('Cannot activate a certificate without a certificate file. Please upload the signed certificate first.'));
        }

        $this->sslCertificateService->activate($domain, $certificate);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ssl_certificate_activated',
            'domain_id' => $domain->id,
            'summary' => "SSL certificate ID {$certificate->id} activated for {$domain->fqdn}.",
        ]);

        return redirect()
            ->route('domains.ssl.index', $domain)
            ->with('success', __('SSL certificate activated successfully.'));
    }

    /**
     * Validate that a private key matches a certificate (and optionally CA bundle).
     * Called via AJAX from the upload modal for real-time feedback.
     */
    public function validateKey(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageSsl', $domain);

        $validated = $request->validate([
            'private_key' => ['required', 'string'],
            'certificate' => ['required', 'string'],
            'ca_bundle' => ['nullable', 'string'],
        ]);

        $keyMatch = $this->sslCertificateService->validateKeyMatchesCert(
            $validated['certificate'],
            $validated['private_key'],
        );

        if (! $keyMatch) {
            return response()->json([
                'valid' => false,
                'message' => __('Private key does not match the certificate.'),
            ]);
        }

        // Validate CA bundle chains to the certificate if provided
        if (! empty($validated['ca_bundle'])) {
            $caValid = $this->sslCertificateService->validateCaBundle(
                $validated['certificate'],
                $validated['ca_bundle'],
            );

            if (! $caValid) {
                return response()->json([
                    'valid' => false,
                    'message' => __('CA bundle does not match the certificate chain.'),
                ]);
            }
        }

        return response()->json(['valid' => true]);
    }

    public function show(Request $request, Domain $domain, SslCertificate $certificate): JsonResponse
    {
        $this->authorize('manageSsl', $domain);

        if ($certificate->domain_id !== $domain->id) {
            abort(404);
        }

        return response()->json([
            'id' => $certificate->id,
            'label' => $certificate->label,
            'type' => $certificate->type->value,
            'common_name' => $certificate->common_name,
            'issuer' => $certificate->issuer,
            'san_domains' => $certificate->san_domains,
            'not_before' => $certificate->not_before?->toIso8601String(),
            'not_after' => $certificate->not_after?->toIso8601String(),
            'fingerprint_sha256' => $certificate->fingerprint_sha256,
            'is_wildcard' => $certificate->is_wildcard,
            'auto_renew' => $certificate->auto_renew,
            'is_active' => $certificate->is_active,
            'certificate_pem' => $certificate->certificate_pem,
            'private_key_pem' => $certificate->private_key_pem,
            'ca_bundle_pem' => $certificate->ca_bundle_pem,
            'csr_pem' => $certificate->csr_pem,
            'created_at' => $certificate->created_at->toIso8601String(),
        ]);
    }

    /**
     * Complete a CSR record by uploading the signed certificate.
     */
    public function completeCsr(Request $request, Domain $domain, SslCertificate $certificate): RedirectResponse
    {
        $this->authorize('manageSsl', $domain);

        if ($certificate->domain_id !== $domain->id) {
            abort(404);
        }

        if (! $certificate->csr_pem || $certificate->has_certificate) {
            return redirect()
                ->route('domains.ssl.index', $domain)
                ->with('error', __('This certificate does not have a pending CSR.'));
        }

        $validated = $request->validate([
            'certificate' => ['required', 'string'],
            'ca_bundle' => ['nullable', 'string'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->sslCertificateService->completeCsrWithCertificate($certificate, $domain, array_merge($validated, [
                'private_key' => $certificate->private_key_pem,
            ]));
        } catch (SslImportException $e) {
            return redirect()
                ->route('domains.ssl.index', $domain)
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error("CSR completion failed for {$domain->fqdn}: {$e->getMessage()}", [
                'domain' => $domain->fqdn,
                'certificate_id' => $certificate->id,
                'exception' => $e,
            ]);

            return redirect()
                ->route('domains.ssl.index', $domain)
                ->with('error', __('Certificate installation failed. Please contact support.'));
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ssl_csr_completed',
            'domain_id' => $domain->id,
            'summary' => "CSR completed with signed certificate for {$domain->fqdn}.",
        ]);

        return redirect()
            ->route('domains.ssl.index', $domain)
            ->with('success', __('Certificate installed successfully.'));
    }

    public function export(Request $request, Domain $domain, SslCertificate $certificate): \Illuminate\Http\Response
    {
        $this->authorize('manageSsl', $domain);

        if ($certificate->domain_id !== $domain->id) {
            abort(404);
        }

        $pem = '';
        if ($certificate->private_key_pem) {
            $pem .= trim($certificate->private_key_pem)."\n";
        }
        if ($certificate->certificate_pem) {
            $pem .= trim($certificate->certificate_pem)."\n";
        }
        if ($certificate->ca_bundle_pem) {
            $pem .= trim($certificate->ca_bundle_pem)."\n";
        }

        $filename = "{$domain->fqdn}-{$certificate->id}.pem";

        return response($pem)
            ->header('Content-Type', 'application/x-pem-file')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    public function importPem(Request $request, Domain $domain): RedirectResponse
    {
        $this->authorize('manageSsl', $domain);

        $request->validate([
            'pem_file' => ['required', 'file', 'mimes:pem,txt', 'max:102400'],
        ]);

        $content = (string) file_get_contents($request->file('pem_file')->path());

        try {
            $parts = $this->sslCertificateService->parsePemBundle($content);

            $certificate = $this->sslCertificateService->storeUploadedCert(
                $domain,
                $parts['certificate'],
                $parts['private_key'],
                $parts['ca_bundle'],
            );

            Log::info("PEM file imported for {$domain->fqdn}, certificate ID: {$certificate->id}.");
        } catch (SslImportException $e) {
            return redirect()->route('domains.ssl.index', $domain)
                ->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            // storeUploadedCert throws RuntimeException for key/cert mismatch — user input error.
            return redirect()->route('domains.ssl.index', $domain)
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error("PEM import failed for {$domain->fqdn}: {$e->getMessage()}", [
                'domain' => $domain->fqdn,
                'exception' => $e,
            ]);

            return redirect()->route('domains.ssl.index', $domain)
                ->with('error', __('PEM import failed. Please contact support.'));
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ssl_pem_imported',
            'domain_id' => $domain->id,
            'summary' => "PEM file imported for {$domain->fqdn}.",
        ]);

        return redirect()->route('domains.ssl.index', $domain)
            ->with('success', __('PEM file imported successfully.'));
    }

    public function destroy(Request $request, Domain $domain, SslCertificate $certificate): RedirectResponse
    {
        $this->authorize('manageSsl', $domain);

        if ($certificate->domain_id !== $domain->id) {
            abort(404);
        }

        if ($domain->active_ssl_certificate_id === $certificate->id) {
            return redirect()
                ->route('domains.ssl.index', $domain)
                ->with('error', __('Cannot delete the active certificate. Please activate a different certificate first.'));
        }

        // Delete cert files from disk (if written during activation)
        $this->sslCertificateService->deleteCertDiskFiles($domain, $certificate);

        $label = $certificate->label;
        $certificate->delete();

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ssl_certificate_deleted',
            'domain_id' => $domain->id,
            'summary' => "SSL certificate \"{$label}\" deleted for {$domain->fqdn}.",
        ]);

        return redirect()
            ->route('domains.ssl.index', $domain)
            ->with('success', __('SSL certificate deleted successfully.'));
    }
}
