<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMailSieveRequest;
use App\Models\Domain;
use App\Models\MailMailbox;
use App\Services\MailcowApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class MailSieveController extends Controller
{
    public function __construct(
        private MailcowApiService $mailcowApi,
    ) {}

    public function index(Domain $domain, MailMailbox $mailbox): JsonResponse
    {
        $this->ensureMailboxBelongsToDomain($mailbox, $domain);

        try {
            $mailboxData = $this->mailcowApi->getMailbox($mailbox->full_address);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'script' => $mailboxData['sieve_script'] ?? '',
                    'active' => (bool) ($mailboxData['sieve_active'] ?? false),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateMailSieveRequest $request, Domain $domain, MailMailbox $mailbox): RedirectResponse
    {
        $this->ensureMailboxBelongsToDomain($mailbox, $domain);

        $validated = $request->validated();

        try {
            $this->mailcowApi->updateMailbox($mailbox->full_address, [
                'sieve_script' => $validated['script'],
                'sieve_active' => '1',
            ]);

            Log::info("Sieve script updated for {$mailbox->full_address}");

            return redirect()->back()
                ->with('success', __('Sieve script updated for :address.', ['address' => $mailbox->full_address]));
        } catch (\Throwable $e) {
            Log::error("Failed to update Sieve script for {$mailbox->full_address}: {$e->getMessage()}");

            return redirect()->back()
                ->with('error', __('Failed to update Sieve script: :error', ['error' => $e->getMessage()]));
        }
    }

    private function ensureMailboxBelongsToDomain(MailMailbox $mailbox, Domain $domain): void
    {
        $mailbox->loadMissing('mailDomain');
        abort_unless((int) $mailbox->mailDomain?->domain_id === (int) $domain->id, 404);
    }
}
