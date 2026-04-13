<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\MailDomain;
use App\Services\MailcowApiService;
use App\Services\MailcowDnsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class MailDomainController extends Controller
{
    public function __construct(
        private MailcowApiService $mailcowApi,
        private MailcowDnsService $mailcowDnsService,
    ) {}

    public function show(Domain $domain): Response
    {
        $mailDomain = MailDomain::query()
            ->where('domain_id', $domain->id)
            ->with(['mailboxes', 'aliases'])
            ->first();

        $dkimRecord = null;

        if ($mailDomain) {
            try {
                $dkimRecord = $this->mailcowApi->getDkimRecord($domain->fqdn);
            } catch (\Throwable) {
            }
        }

        return Inertia::render('Mail/Domain', [
            'domain' => $domain->only('id', 'fqdn', 'status'),
            'mailDomain' => $mailDomain,
            'dkimRecord' => $dkimRecord,
            'webmailUrl' => 'https://'.config('panel.mailcow.webmail_domain'),
        ]);
    }

    public function enable(Domain $domain): RedirectResponse
    {
        try {
            $this->mailcowApi->addDomain($domain->fqdn);
            $this->mailcowApi->generateDkim($domain->fqdn);

            $mailDomain = MailDomain::create([
                'domain_id' => $domain->id,
                'mail_domain' => $domain->fqdn,
                'is_active' => true,
            ]);

            $this->mailcowDnsService->provisionMailDns($domain);

            Log::info("Mail enabled for domain {$domain->fqdn}");

            return redirect()->back()
                ->with('success', __('Mail has been enabled for :domain.', ['domain' => $domain->fqdn]));
        } catch (\Throwable $e) {
            Log::error("Failed to enable mail for {$domain->fqdn}: {$e->getMessage()}");

            return redirect()->back()
                ->with('error', __('Failed to enable mail: :error', ['error' => $e->getMessage()]));
        }
    }

    public function disable(Domain $domain): RedirectResponse
    {
        $mailDomain = MailDomain::query()
            ->where('domain_id', $domain->id)
            ->first();

        if (! $mailDomain) {
            return redirect()->back()
                ->with('error', __('Mail is not enabled for this domain.'));
        }

        try {
            $this->mailcowApi->deleteDomain($domain->fqdn);
            $this->mailcowDnsService->removeMailDns($domain);
            $mailDomain->delete();

            Log::info("Mail disabled for domain {$domain->fqdn}");

            return redirect()->back()
                ->with('success', __('Mail has been disabled for :domain.', ['domain' => $domain->fqdn]));
        } catch (\Throwable $e) {
            Log::error("Failed to disable mail for {$domain->fqdn}: {$e->getMessage()}");

            return redirect()->back()
                ->with('error', __('Failed to disable mail: :error', ['error' => $e->getMessage()]));
        }
    }
}
