<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreMailMailboxRequest;
use App\Http\Requests\UpdateMailMailboxRequest;
use App\Http\Requests\UpdateMailPasswordRequest;
use App\Models\Domain;
use App\Models\MailDomain;
use App\Models\MailMailbox;
use App\Services\MailcowApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class MailMailboxController extends Controller
{
    public function __construct(
        private MailcowApiService $mailcowApi,
    ) {}

    public function store(StoreMailMailboxRequest $request, Domain $domain): RedirectResponse
    {
        $mailDomain = MailDomain::query()
            ->where('domain_id', $domain->id)
            ->firstOrFail();

        $validated = $request->validated();

        try {
            $this->mailcowApi->addMailbox(
                localPart: $validated['local_part'],
                domain: $domain->fqdn,
                password: $validated['password'],
                name: $validated['display_name'] ?? '',
                quota: $validated['quota_mb'] ?? 256,
            );

            MailMailbox::create([
                'mail_domain_id' => $mailDomain->id,
                'local_part' => $validated['local_part'],
                'full_address' => $validated['local_part'].'@'.$domain->fqdn,
                'display_name' => $validated['display_name'] ?? null,
                'quota_mb' => $validated['quota_mb'] ?? 256,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            Log::info("Mailbox {$validated['local_part']}@{$domain->fqdn} created");

            return redirect()->back()
                ->with('success', __('Mailbox :address created successfully.', [
                    'address' => $validated['local_part'].'@'.$domain->fqdn,
                ]));
        } catch (\Throwable $e) {
            Log::error("Failed to create mailbox {$validated['local_part']}@{$domain->fqdn}: {$e->getMessage()}");

            return redirect()->back()
                ->with('error', __('Failed to create mailbox: :error', ['error' => $e->getMessage()]));
        }
    }

    public function update(UpdateMailMailboxRequest $request, Domain $domain, MailMailbox $mailbox): RedirectResponse
    {
        $this->ensureMailboxBelongsToDomain($mailbox, $domain);

        $validated = $request->validated();

        try {
            $attributes = [];

            if (array_key_exists('display_name', $validated)) {
                $attributes['name'] = $validated['display_name'] ?? '';
            }

            if (array_key_exists('quota_mb', $validated) && $validated['quota_mb'] !== null) {
                $attributes['quota'] = (string) $validated['quota_mb'];
            }

            if (array_key_exists('is_active', $validated)) {
                $attributes['active'] = ($validated['is_active'] ?? true) ? '1' : '0';
            }

            if ($attributes !== []) {
                $this->mailcowApi->updateMailbox($mailbox->full_address, $attributes);
            }

            $mailbox->update($validated);

            Log::info("Mailbox {$mailbox->full_address} updated");

            return redirect()->back()
                ->with('success', __('Mailbox :address updated successfully.', ['address' => $mailbox->full_address]));
        } catch (\Throwable $e) {
            Log::error("Failed to update mailbox {$mailbox->full_address}: {$e->getMessage()}");

            return redirect()->back()
                ->with('error', __('Failed to update mailbox: :error', ['error' => $e->getMessage()]));
        }
    }

    public function updatePassword(UpdateMailPasswordRequest $request, Domain $domain, MailMailbox $mailbox): RedirectResponse
    {
        $this->ensureMailboxBelongsToDomain($mailbox, $domain);

        $validated = $request->validated();

        try {
            $this->mailcowApi->updateMailboxPassword($mailbox->full_address, $validated['password']);

            Log::info("Password updated for mailbox {$mailbox->full_address}");

            return redirect()->back()
                ->with('success', __('Password updated for :address.', ['address' => $mailbox->full_address]));
        } catch (\Throwable $e) {
            Log::error("Failed to update password for {$mailbox->full_address}: {$e->getMessage()}");

            return redirect()->back()
                ->with('error', __('Failed to update password: :error', ['error' => $e->getMessage()]));
        }
    }

    public function destroy(Domain $domain, MailMailbox $mailbox): RedirectResponse
    {
        $this->ensureMailboxBelongsToDomain($mailbox, $domain);

        try {
            $this->mailcowApi->deleteMailbox($mailbox->full_address);
            $mailbox->delete();

            Log::info("Mailbox {$mailbox->full_address} deleted");

            return redirect()->back()
                ->with('success', __('Mailbox :address deleted successfully.', ['address' => $mailbox->full_address]));
        } catch (\Throwable $e) {
            Log::error("Failed to delete mailbox {$mailbox->full_address}: {$e->getMessage()}");

            return redirect()->back()
                ->with('error', __('Failed to delete mailbox: :error', ['error' => $e->getMessage()]));
        }
    }

    public function quotaUsage(Domain $domain, MailMailbox $mailbox): JsonResponse
    {
        $this->ensureMailboxBelongsToDomain($mailbox, $domain);

        try {
            $usage = $this->mailcowApi->getQuotaUsage($mailbox->full_address);

            return response()->json([
                'status' => 'success',
                'data' => $usage,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function ensureMailboxBelongsToDomain(MailMailbox $mailbox, Domain $domain): void
    {
        $mailbox->loadMissing('mailDomain');
        abort_unless((int) $mailbox->mailDomain?->domain_id === (int) $domain->id, 404);
    }
}
