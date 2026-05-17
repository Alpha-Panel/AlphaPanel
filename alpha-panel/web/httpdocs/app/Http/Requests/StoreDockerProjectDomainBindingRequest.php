<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDockerProjectDomainBindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('panel.docker-services.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'docker_project_id' => ['required', 'integer', 'exists:docker_projects,id'],
            'service_name' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9][a-z0-9_\-]*$/'],
            'container_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'path_prefix' => ['nullable', 'string', 'max:255', 'regex:/^\/[^\s]*$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'path_prefix.regex' => __('Path prefix must start with / (e.g. /api).'),
            'service_name.regex' => __('Service name must be lowercase letters, numbers, hyphens, or underscores.'),
        ];
    }
}
