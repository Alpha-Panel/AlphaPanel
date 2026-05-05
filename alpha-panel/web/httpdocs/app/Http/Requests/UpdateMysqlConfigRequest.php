<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMysqlConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('panel.mysql-config.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string', 'max:512'],
        ];
    }
}
