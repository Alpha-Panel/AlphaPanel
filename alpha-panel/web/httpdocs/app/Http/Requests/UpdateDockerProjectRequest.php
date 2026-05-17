<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDockerProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('panel.docker-services.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'display_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
