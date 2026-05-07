<?php

namespace App\Http\Controllers;

use App\Enums\DnsProvider;
use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\SslMethod;
use App\Jobs\SslActivateJob;
use App\Models\Domain;
use App\Models\User;
use App\Services\ReloadService;
use App\Services\SslCertificateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class PanelSslController extends Controller
{
    public function __construct(
        private SslCertificateService $sslCertificateService,
        private ReloadService $reloadService,
    ) {}

    public function index(): Response
    {
        $baseDomain = config('panel.base_domain');
        $domain = $baseDomain ? Domain::where('fqdn', $baseDomain)->first() : null;
        $activeCert = $domain?->activeSslCertificate;

        return Inertia::render('Settings/PanelSsl', [
            'base_domain' => $baseDomain,
            'cert' => $activeCert ? [
                'id' => $activeCert->id,
                'type' => $activeCert->type->value,
                'type_label' => $activeCert->type->label(),
                'common_name' => $activeCert->common_name,
                'issuer' => $activeCert->issuer,
                'not_after' => $activeCert->not_after?->toDateString(),
                'is_wildcard' => $activeCert->is_wildcard,
                'is_live_synced' => $this->isLiveSynced($baseDomain),
            ] : null,
            'ssl_methods' => [
                ['value' => 'cloudflare_dns', 'label' => 'Cloudflare DNS-01'],
                ['value' => 'local_dns', 'label' => 'Local DNS-01'],
                ['value' => 'webroot_http', 'label' => 'HTTP-01 (Webroot)'],
            ],
        ]);
    }

    public function issue(Request $request): RedirectResponse
    {
        $baseDomain = config('panel.base_domain');

        if (! $baseDomain) {
            return redirect()->route('settings.panel-ssl.index')
                ->with('error', __('Base domain is not configured.'));
        }

        $validated = $request->validate([
            'ssl_method' => ['required', 'in:cloudflare_dns,local_dns,webroot_http'],
        ]);

        $sslMethod = match ($validated['ssl_method']) {
            'cloudflare_dns' => SslMethod::CloudflareDns,
            'local_dns' => SslMethod::LocalDns,
            'webroot_http' => SslMethod::WebrootHttp,
        };

        $ownerId = User::query()->orderBy('id')->value('id');

        $domain = Domain::firstOrCreate(
            ['fqdn' => $baseDomain],
            [
                'type' => DomainType::CaddyWebServer,
                'status' => DomainStatus::PendingCert,
                'dns_provider' => DnsProvider::Cloudflare,
                'ssl_method' => $sslMethod,
                'owner_user_id' => $ownerId,
            ],
        );

        $domain->ssl_method = $sslMethod;
        $domain->save();

        SslActivateJob::dispatch(
            domain: $domain,
            triggeredBy: $request->user()->id,
            locale: app()->getLocale(),
            actorIpAddress: $request->ip(),
        );

        return redirect()->route('settings.panel-ssl.index')
            ->with('success', __('Certificate issuance started. This may take a few minutes.'));
    }

    public function sync(): RedirectResponse
    {
        $baseDomain = config('panel.base_domain');

        if (! $baseDomain) {
            return redirect()->route('settings.panel-ssl.index')
                ->with('error', __('Base domain is not configured.'));
        }

        $domain = Domain::where('fqdn', $baseDomain)->first();

        if (! $domain) {
            return redirect()->route('settings.panel-ssl.index')
                ->with('error', __('Base domain not found in panel.'));
        }

        $cert = $domain->activeSslCertificate;

        if (! $cert) {
            return redirect()->route('settings.panel-ssl.index')
                ->with('error', __('No active certificate found for the base domain.'));
        }

        $this->sslCertificateService->syncToLivePath($domain, $cert);
        $this->reloadService->restartCaddy();

        return redirect()->route('settings.panel-ssl.index')
            ->with('success', __('Certificate synced to panel successfully.'));
    }

    private function isLiveSynced(?string $baseDomain): bool
    {
        if (! $baseDomain) {
            return false;
        }

        $liveDir = config('panel.letsencrypt_base').'/'.$baseDomain;

        return File::exists("{$liveDir}/fullchain.pem") && File::exists("{$liveDir}/privkey.pem");
    }
}
