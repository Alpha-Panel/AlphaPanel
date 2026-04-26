<?php

namespace App\Http\Requests;

use App\Enums\NotificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $types = array_column(NotificationType::cases(), 'value');

        return [
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.type' => ['required', 'string', Rule::in($types)],
            'preferences.*.database' => ['required', 'boolean'],
            'preferences.*.push' => ['required', 'boolean'],
            'preferences.*.mail' => ['required', 'boolean'],
            'skip_self_push' => ['sometimes', 'boolean'],
        ];
    }
}
