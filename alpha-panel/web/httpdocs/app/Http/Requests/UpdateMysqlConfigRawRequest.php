<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMysqlConfigRawRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('panel.mysql-config.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:65535'],
        ];
    }
}
