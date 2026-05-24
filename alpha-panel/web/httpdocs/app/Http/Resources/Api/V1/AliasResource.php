<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Mail\DTO\Alias;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property Alias $resource */
class AliasResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Alias $a */
        $a = $this->resource;

        return [
            'address' => $a->address,
            'destination' => $a->destination,
            'provider_external_id' => $a->providerExternalId,
        ];
    }
}
