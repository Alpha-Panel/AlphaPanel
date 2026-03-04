<?php

namespace App\Http\Requests;

use App\Enums\DomainType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'owner_user_id' => ['sometimes', 'exists:users,id'],
            'fqdn' => ['required', 'string', 'max:255', 'unique:domains,fqdn'],
            'parent_domain_id' => ['nullable', 'exists:domains,id'],
            'type' => ['required', new Enum(DomainType::class)],
            'root_path' => ['nullable', 'string', 'max:500'],
            'enable_www_redirect' => ['boolean'],
            'additional_hostnames' => ['nullable', 'array'],
            'additional_hostnames.*' => ['string', 'max:255'],
            'enable_worker' => ['boolean'],
            'worker_num' => [
                Rule::excludeIf(fn () => ! $this->boolean('enable_worker')),
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
            'worker_watch' => [
                Rule::excludeIf(fn () => ! $this->boolean('enable_worker')),
                'boolean',
            ],
            'php_version_id' => [
                'nullable',
                'exists:php_versions,id',
                Rule::requiredIf(fn () => $this->input('type') === 'apache_reverse_proxy'),
            ],
            'cloudflare_mode' => [
                'nullable',
                'string',
                Rule::in(['add', 'skip', 'existing']),
                Rule::requiredIf(fn () => ! $this->filled('parent_domain_id')),
            ],
            'create_dns_record' => [
                'sometimes',
                'boolean',
                Rule::prohibitedIf(fn () => ! $this->filled('parent_domain_id')),
            ],
            'dns_target_ip' => [
                'nullable',
                'ip',
                Rule::requiredIf(fn () => $this->shouldRequireDnsTargetIp()),
            ],
            'ftp_username' => [
                'nullable',
                'string',
                'max:32',
                'alpha_dash',
                'unique:ftp_users,username',
                Rule::requiredIf(fn () => $this->isApacheParentDomain()),
            ],
            'ftp_password' => [
                'nullable',
                'string',
                'min:8',
                'max:128',
                Rule::requiredIf(fn () => $this->isApacheParentDomain()),
            ],
        ];
    }

    /**
     * FTP is only required for Apache parent domains, not subdomains.
     */
    private function isApacheParentDomain(): bool
    {
        return $this->input('type') === 'apache_reverse_proxy'
            && ! $this->filled('parent_domain_id');
    }

    private function shouldRequireDnsTargetIp(): bool
    {
        $isSubdomain = $this->filled('parent_domain_id');

        if ($isSubdomain) {
            return $this->boolean('create_dns_record');
        }

        return (string) $this->input('cloudflare_mode') === 'add';
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'php_version_id.required_if' => __('PHP version is required for Apache + Reverse Proxy domains.'),
            'fqdn.unique' => __('This domain is already registered.'),
            'cloudflare_mode.required_if' => __('Cloudflare status is required for parent domains.'),
            'cloudflare_mode.in' => __('Invalid Cloudflare status selected.'),
            'dns_target_ip.required' => __('Please select an IP address for DNS record creation.'),
            'dns_target_ip.ip' => __('Invalid DNS target IP address.'),
            'ftp_username.required_if' => __('FTP username is required for Apache + Reverse Proxy domains (used as PHP-FPM pool user).'),
            'ftp_password.required_if' => __('FTP password is required for Apache + Reverse Proxy domains.'),
        ];
    }
}
