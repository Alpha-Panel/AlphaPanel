<?php

namespace App\Http\Requests;

use App\Models\DockerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDockerServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9][a-z0-9\-]*$/',
                Rule::unique('docker_services', 'name')->ignore($this->route('dockerService')),
            ],
            'display_name' => ['nullable', 'string', 'max:255'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'restart_policy' => ['required', 'string', Rule::in(['no', 'always', 'unless-stopped', 'on-failure'])],
            'environment_variables' => ['nullable', 'array'],
            'environment_variables.*' => ['string'],
            'volumes' => ['nullable', 'array'],
            'volumes.*.host_path' => ['required_with:volumes.*.container_path', 'string'],
            'volumes.*.container_path' => ['required', 'string'],
            'volumes.*.mode' => [Rule::in(['rw', 'ro'])],
            'ports' => ['nullable', 'array'],
            'ports.*.host_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
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

    /**
     * Additional validation after rules pass.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateHostPortConflicts($validator);
        });
    }

    /**
     * Check that requested host ports are not already in use by other Docker services.
     */
    private function validateHostPortConflicts(Validator $validator): void
    {
        $ports = $this->input('ports', []);
        if (empty($ports)) {
            return;
        }

        $currentServiceId = $this->route('dockerService')?->id ?? $this->route('dockerService');

        $requestedHostPorts = collect($ports)
            ->filter(fn (array $p) => ! empty($p['host_port']))
            ->pluck('host_port')
            ->map(fn ($p) => (int) $p)
            ->unique()
            ->values();

        if ($requestedHostPorts->isEmpty()) {
            return;
        }

        // Collect all host ports used by OTHER services (exclude current)
        $usedPorts = DockerService::where('id', '!=', $currentServiceId)
            ->get()
            ->flatMap(fn (DockerService $s) => collect($s->ports ?? [])
                ->filter(fn (array $p) => ! empty($p['host_port']))
                ->map(fn (array $p) => [
                    'port' => (int) $p['host_port'],
                    'service' => $s->display_name ?? $s->name,
                ]))
            ->keyBy('port');

        foreach ($requestedHostPorts as $hostPort) {
            if ($usedPorts->has($hostPort)) {
                $occupiedBy = $usedPorts->get($hostPort)['service'];
                $validator->errors()->add(
                    'ports',
                    __('Host port :port is already in use by ":service".', [
                        'port' => $hostPort,
                        'service' => $occupiedBy,
                    ]),
                );
            }
        }
    }
}
