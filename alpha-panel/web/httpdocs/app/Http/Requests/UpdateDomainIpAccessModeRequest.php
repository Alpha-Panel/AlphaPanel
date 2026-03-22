<?php

namespace App\Http\Requests;

use App\Enums\IpAccessMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateDomainIpAccessModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ip_access_mode' => ['required', new Enum(IpAccessMode::class)],
        ];
    }
}
