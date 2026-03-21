<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDockerServiceDomainBindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'docker_service_id' => ['required', 'exists:docker_services,id'],
            'container_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'path_prefix' => ['nullable', 'string', 'max:255', 'regex:/^\/[a-zA-Z0-9\-_\/]*$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'path_prefix.regex' => __('Path prefix must start with / and contain only letters, numbers, hyphens, underscores, and forward slashes.'),
        ];
    }
}
