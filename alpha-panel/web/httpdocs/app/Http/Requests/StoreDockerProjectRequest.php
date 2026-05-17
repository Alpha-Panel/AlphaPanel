<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDockerProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('panel.docker-services.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9\-]*[a-z0-9]$|^[a-z0-9]$/', 'unique:docker_projects,name'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'compose_yaml' => ['nullable', 'string', 'max:262144'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.regex' => __('Name must be lowercase letters, numbers, and hyphens only (no leading or trailing hyphens).'),
            'name.unique' => __('A project with this name already exists.'),
        ];
    }
}
