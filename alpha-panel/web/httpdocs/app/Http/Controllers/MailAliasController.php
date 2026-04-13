<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreMailAliasRequest;
use App\Http\Requests\UpdateMailAliasRequest;
use App\Models\Domain;
use App\Models\MailAlias;
use App\Models\MailDomain;
use App\Services\MailcowApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class MailAliasController extends Controller
{
    public function __construct(
        private MailcowApiService $mailcowApi,
    ) {}

    public function store(StoreMailAliasRequest $request, Domain $domain): RedirectResponse
    {
        $mailDomain = MailDomain::query()
            ->where('domain_id', $domain->id)
            ->firstOrFail();

        $validated = $request->validated();

        try {
            $result = $this->mailcowApi->addAlias(
                address: $validated['address'],
                goto: $validated['goto'],
                active: $validated['is_active'] ?? true,
            );

            $mailcowId = $result[0]['msg'][1] ?? null;

            MailAlias::create([
                'mail_domain_id' => $mailDomain->id,
                'address' => $validated['address'],
                'goto' => $validated['goto'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            Log::info("Mail alias {$validated['address']} -> {$validated['goto']} created for {$domain->fqdn}");

            return redirect()->back()
                ->with('success', __('Alias :address created successfully.', ['address' => $validated['address']]));
        } catch (\Throwable $e) {
            Log::error("Failed to create alias {$validated['address']} for {$domain->fqdn}: {$e->getMessage()}");

            return redirect()->back()
                ->with('error', __('Failed to create alias: :error', ['error' => $e->getMessage()]));
        }
    }

    public function update(UpdateMailAliasRequest $request, Domain $domain, MailAlias $alias): RedirectResponse
    {
        $this->ensureAliasBelongsToDomain($alias, $domain);

        $validated = $request->validated();

        try {
            $attributes = [];

            if (array_key_exists('address', $validated) && $validated['address'] !== null) {
                $attributes['address'] = $validated['address'];
            }

            if (array_key_exists('goto', $validated) && $validated['goto'] !== null) {
                $attributes['goto'] = $validated['goto'];
            }

            if (array_key_exists('is_active', $validated)) {
                $attributes['active'] = ($validated['is_active'] ?? true) ? '1' : '0';
            }

            if ($attributes !== []) {
                $this->mailcowApi->updateAlias((int) $alias->id, $attributes);
            }

            $alias->update(array_filter($validated, fn ($value) => $value !== null));

            Log::info("Mail alias {$alias->address} updated for {$domain->fqdn}");

            return redirect()->back()
                ->with('success', __('Alias :address updated successfully.', ['address' => $alias->address]));
        } catch (\Throwable $e) {
            Log::error("Failed to update alias {$alias->address} for {$domain->fqdn}: {$e->getMessage()}");

            return redirect()->back()
                ->with('error', __('Failed to update alias: :error', ['error' => $e->getMessage()]));
        }
    }

    public function destroy(Domain $domain, MailAlias $alias): RedirectResponse
    {
        $this->ensureAliasBelongsToDomain($alias, $domain);

        try {
            $this->mailcowApi->deleteAlias((int) $alias->id);
            $alias->delete();

            Log::info("Mail alias {$alias->address} deleted for {$domain->fqdn}");

            return redirect()->back()
                ->with('success', __('Alias :address deleted successfully.', ['address' => $alias->address]));
        } catch (\Throwable $e) {
            Log::error("Failed to delete alias {$alias->address} for {$domain->fqdn}: {$e->getMessage()}");

            return redirect()->back()
                ->with('error', __('Failed to delete alias: :error', ['error' => $e->getMessage()]));
        }
    }

    private function ensureAliasBelongsToDomain(MailAlias $alias, Domain $domain): void
    {
        $alias->loadMissing('mailDomain');
        abort_unless((int) $alias->mailDomain?->domain_id === (int) $domain->id, 404);
    }
}
