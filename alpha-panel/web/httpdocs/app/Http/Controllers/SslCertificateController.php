<?php

namespace App\Http\Controllers;

use App\Enums\SslCertificateType;
use App\Enums\SslMethod;
use App\Http\Requests\GenerateCsrRequest;
use App\Http\Requests\UploadCertificateRequest;
use App\Jobs\SslActivateJob;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\SslCertificate;
use App\Services\CertbotService;
use App\Services\DomainConfigService;
use App\Services\ReloadService;
use App\Services\SslCertificateService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
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

        $domain->load(['sslCertificates' => fn ($q) => $q->orderByDesc('created_at')]);

        return Inertia::render('Domains/SslCertificates', [
            'domain' => $domain,
            'certificates' => $domain->sslCertificates,
            'activeCertificateId' => $domain->active_ssl_certificate_id,
        ]);
    }

    public function storeLetsEncrypt(Request $request, Domain $domain): RedirectResponse
    {
        $this->authorize('manageSsl', $domain);

        $validated = $request->validate([
            'validation_method' => ['required', 'string', 'in:dns-01,http-01'],
        ]);

        $sslMethod = $validated['validation_method'] === 'dns-01'
            ? SslMethod::CloudflareDns
            : SslMethod::WebrootHttp;

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
            'ip_address' => $request->ip(),
            'port' => is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
        ]);

        return redirect()
            ->route('domains.ssl.index', $domain)
            ->with('success', __("Let's Encrypt certificate request started. You will be notified when complete."));
    }

    public function storeSelfSigned(
        Request $request,
        Domain $domain,
        CertbotService $certbotService,
        DomainConfigService $configService,
        ReloadService $reloadService,
    ): RedirectResponse {
        $this->authorize('manageSsl', $domain);

        $success = $certbotService->generateSelfSigned($domain);

        if (! $success) {
            return redirect()
                ->route('domains.ssl.index', $domain)
                ->with('error', __('Failed to generate self-signed certificate.'));
        }

        $certDir = config('panel.letsencrypt_selfsigned_base')."/{$domain->fqdn}";
        $certPath = "{$certDir}/fullchain.pem";
        $keyPath = "{$certDir}/privkey.pem";

        try {
            $certificate = $this->sslCertificateService->createFromDiskCert(
                $domain,
                SslCertificateType::SelfSigned,
                $certPath,
                $keyPath,
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
            'ip_address' => $request->ip(),
            'port' => is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
        ]);

        return redirect()
            ->route('domains.ssl.index', $domain)
            ->with('success', __('Self-signed certificate generated successfully.'));
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
            'ip_address' => $request->ip(),
            'port' => is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
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
            $existingCsr = $this->findMatchingCsrRecord($domain, $validated['private_key']);

            if ($existingCsr) {
                Log::info("Found matching CSR record ID {$existingCsr->id} for {$domain->fqdn}.");
                $this->completeCsrWithCert($existingCsr, $domain, $validated);
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
        } catch (\Exception $e) {
            Log::error("SSL upload failed for {$domain->fqdn}: {$e->getMessage()}", [
                'exception' => $e::class,
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()
                ->route('domains.ssl.index', $domain)
                ->with('error', __('Certificate upload failed: :error', ['error' => $e->getMessage()]));
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ssl_certificate_uploaded',
            'domain_id' => $domain->id,
            'summary' => "Custom certificate uploaded for {$domain->fqdn}.",
            'ip_address' => $request->ip(),
            'port' => is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
        ]);

        return redirect()
            ->route('domains.ssl.index', $domain)
            ->with('success', __('Certificate uploaded successfully.'));
    }

    public function activate(Request $request, Domain $domain, SslCertificate $certificate): RedirectResponse
    {
        $this->authorize('manageSsl', $domain);

        if ($certificate->domain_id !== $domain->id) {
            abort(404);
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
            'ip_address' => $request->ip(),
            'port' => is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
        ]);

        return redirect()
            ->route('domains.ssl.index', $domain)
            ->with('success', __('SSL certificate activated successfully.'));
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
        $this->deleteCertDiskFiles($domain, $certificate);

        $label = $certificate->label;
        $certificate->delete();

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ssl_certificate_deleted',
            'domain_id' => $domain->id,
            'summary' => "SSL certificate \"{$label}\" deleted for {$domain->fqdn}.",
            'ip_address' => $request->ip(),
            'port' => is_numeric($request->server('REMOTE_PORT')) ? (int) $request->server('REMOTE_PORT') : null,
        ]);

        return redirect()
            ->route('domains.ssl.index', $domain)
            ->with('success', __('SSL certificate deleted successfully.'));
    }

    /**
     * Find an existing CSR record whose private key matches the uploaded key.
     */
    private function findMatchingCsrRecord(Domain $domain, string $uploadedKeyPem): ?SslCertificate
    {
        $csrRecords = $domain->sslCertificates()
            ->whereNotNull('csr_pem')
            ->whereNotNull('private_key_pem')
            ->whereNull('certificate_pem')
            ->get();

        foreach ($csrRecords as $csrRecord) {
            if (trim($csrRecord->private_key_pem) === trim($uploadedKeyPem)) {
                return $csrRecord;
            }
        }

        return null;
    }

    /**
     * Complete a CSR record by adding the signed certificate content.
     */
    private function completeCsrWithCert(SslCertificate $csrRecord, Domain $domain, array $validated): void
    {
        if (! $this->sslCertificateService->validateKeyMatchesCert($validated['certificate'], $validated['private_key'])) {
            throw new \RuntimeException(__('The private key does not match the certificate.'));
        }

        $caBundlePem = $validated['ca_bundle'] ?? null;
        $fullchainPem = $caBundlePem !== null
            ? trim($validated['certificate'])."\n".trim($caBundlePem)."\n"
            : $validated['certificate'];

        $meta = $this->sslCertificateService->parseCertificatePem($fullchainPem);

        $csrRecord->update([
            'certificate_pem' => $fullchainPem,
            'ca_bundle_pem' => $caBundlePem,
            'issuer' => $meta['issuer'],
            'not_before' => $meta['not_before'] ? Carbon::parse($meta['not_before']) : null,
            'not_after' => $meta['not_after'] ? Carbon::parse($meta['not_after']) : null,
            'fingerprint_sha256' => $meta['fingerprint_sha256'],
            'label' => $validated['label'] ?? "Custom Certificate - {$domain->fqdn}",
        ]);

        Log::info("Completed CSR record ID {$csrRecord->id} with uploaded certificate for {$domain->fqdn}.");
    }

    /**
     * Delete certificate-related disk files (written during activation).
     */
    private function deleteCertDiskFiles(Domain $domain, SslCertificate $certificate): void
    {
        $certDir = config('panel.letsencrypt_custom_base')."/{$domain->fqdn}/{$certificate->id}";

        if (File::isDirectory($certDir)) {
            File::deleteDirectory($certDir);
        }
    }
}
