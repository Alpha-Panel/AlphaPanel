<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\PhpSettingsRequest;
use App\Models\Domain;
use App\Models\PhpSetting;
use Illuminate\Http\JsonResponse;

class PhpSettingsController extends ApiController
{
    public function show(Domain $domain): JsonResponse
    {
        $settings = $domain->phpSetting ?? new PhpSetting(['domain_id' => $domain->id]);

        return response()->json(['data' => $settings]);
    }

    public function update(PhpSettingsRequest $request, Domain $domain): JsonResponse
    {
        $settings = $domain->phpSetting ?? PhpSetting::create(['domain_id' => $domain->id]);
        $settings->update($request->validated());

        return response()->json(['data' => $settings->fresh()]);
    }
}
