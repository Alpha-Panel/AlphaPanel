<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Mail\DTO\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property Mailbox $resource */
class MailboxResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Mailbox $m */
        $m = $this->resource;

        return [
            'address' => $m->address,
            'display_name' => $m->displayName,
            'quota_bytes' => $m->quotaBytes,
            'quota_used_bytes' => $m->quotaUsedBytes,
            'active' => $m->active,
            'forward_to' => $m->forwardTo,
            'keep_local' => $m->keepLocal,
            'aliases' => $m->aliases,
            'provider_external_id' => $m->providerExternalId,
        ];
    }
}
