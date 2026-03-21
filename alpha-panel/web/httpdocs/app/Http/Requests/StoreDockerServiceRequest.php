<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDockerServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9\-]*$/', 'unique:docker_services'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'image' => ['required', 'string', 'max:255'],
            'tag' => ['required', 'string', 'max:128'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'restart_policy' => ['required', 'string', Rule::in(['no', 'always', 'unless-stopped', 'on-failure'])],
            'environment_variables' => ['nullable', 'array'],
            'environment_variables.*' => ['string'],
            'volumes' => ['nullable', 'array'],
            'volumes.*.host_path' => ['required_with:volumes.*.container_path', 'string'],
            'volumes.*.container_path' => ['required', 'string'],
            'volumes.*.mode' => [Rule::in(['rw', 'ro'])],
            'ports' => ['nullable', 'array'],
            'ports.*.host_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'ports.*.container_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'ports.*.protocol' => [Rule::in(['tcp', 'udp'])],
            'resource_limits' => ['nullable', 'array'],
            'resource_limits.cpu_limit' => ['nullable', 'numeric', 'min:0.1', 'max:16'],
            'resource_limits.memory_limit' => ['nullable', 'string', Rule::in(['128M', '256M', '512M', '1G', '2G', '4G', '8G', '16G'])],
            'networks' => ['nullable', 'array'],
            'networks.*' => ['string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.regex' => __('Service name must start with a lowercase letter or number and contain only lowercase letters, numbers, and hyphens.'),
            'name.unique' => __('A Docker service with this name already exists.'),
        ];
    }
}
