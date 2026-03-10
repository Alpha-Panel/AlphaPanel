<?php

namespace App\Http\Controllers;

use App\Http\Requests\PhpSettingsRequest;
use App\Models\Domain;
use App\Models\PhpVersion;
use App\Services\DomainConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class PhpSettingsController extends Controller
{
    public function index(Domain $domain): Response
    {
        $this->authorize('managePhp', $domain);

        $domain->load(['phpVersion', 'phpSetting']);

        if (! $domain->phpSetting) {
            $domain->phpSetting()->create([]);
            $domain->load('phpSetting');
        }

        $phpVersions = PhpVersion::where('is_enabled', true)
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('PhpSettings/Index', compact('domain', 'phpVersions'));
    }

    public function update(PhpSettingsRequest $request, Domain $domain, DomainConfigService $configService): JsonResponse
    {
        $this->authorize('managePhp', $domain);

        $validated = $request->validated();
        $domain->load(['phpVersion', 'phpSetting']);

        try {
            // Handle PHP version change
            $newVersionId = (int) $validated['php_version_id'];
            if ($domain->php_version_id !== $newVersionId) {
                $newVersion = PhpVersion::findOrFail($newVersionId);
                $configService->changePhpVersion($domain, $newVersion);
                $domain->refresh();
            }

            // Update PHP settings
            $settingFields = collect($validated)->except('php_version_id')->toArray();

            if ($domain->phpSetting) {
                $domain->phpSetting->update($settingFields);
            } else {
                $domain->phpSetting()->create($settingFields);
            }

            // Rewrite FPM config with updated settings
            $domain->load('phpSetting');
            $configService->writePhpFpmConfig($domain);

            return response()->json([
                'status' => 'success',
                'message' => __('PHP settings updated successfully.'),
            ]);
        } catch (\Throwable $e) {
            Log::error("PHP settings update failed for {$domain->fqdn}: {$e->getMessage()}");

            return response()->json([
                'status' => 'error',
                'message' => __('Failed to update PHP settings: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }
}
