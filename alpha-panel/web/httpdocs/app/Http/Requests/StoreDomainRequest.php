<?php

namespace App\Http\Requests;

use App\Enums\DomainMode;
use App\Enums\DomainType;
use App\Models\Domain;
use App\Rules\NoExistingCatchall;
use App\Rules\NotReservedDomain;
use App\Rules\RequiresAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

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
            'owner_user_id' => [
                Rule::excludeIf(fn () => $this->filled('parent_domain_id')),
                'nullable',
                'exists:users,id',
            ],
            'mode' => ['required', new Enum(DomainMode::class)],
            'fqdn' => array_filter([
                'required',
                'string',
                'max:255',
                'unique:domains,fqdn',
                new NotReservedDomain,
                // FQDN regex for regular domain modes
                in_array($this->input('mode'), [
                    DomainMode::Main->value,
                    DomainMode::Subdomain->value,
                    DomainMode::Addon->value,
                ], true)
                    ? 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i'
                    : null,
                // Wildcard subdomain: must be *.something.tld
                $this->input('mode') === DomainMode::WildcardSubdomain->value
                    ? 'regex:/^\*\.(?=.{1,251}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i'
                    : null,
                // Catch-all: must be literal *, admin only, no existing one
                $this->input('mode') === DomainMode::WildcardCatchall->value
                    ? Rule::in(['*'])
                    : null,
                $this->input('mode') === DomainMode::WildcardCatchall->value
                    ? new NoExistingCatchall
                    : null,
                $this->input('mode') === DomainMode::WildcardCatchall->value
                    ? new RequiresAdmin
                    : null,
            ]),
            'parent_domain_id' => [
                Rule::requiredIf(fn () => in_array($this->input('mode'), [
                    DomainMode::Subdomain->value,
                    DomainMode::WildcardSubdomain->value,
                ], true)),
                'nullable',
                'exists:domains,id',
            ],
            'linked_domain_id' => [
                'nullable',
                'exists:domains,id',
            ],
            'type' => ['required', new Enum(DomainType::class)],
            'root_path' => ['nullable', 'string', 'max:500'],
            'inherit_parent_root_path' => [
                Rule::excludeIf(fn () => ! $this->filled('parent_domain_id')),
                'sometimes',
                'boolean',
            ],
            'dns_provider' => [
                Rule::excludeIf(fn () => $this->filled('parent_domain_id')),
                'nullable',
                'string',
                Rule::in(['local', 'cloudflare']),
            ],
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
                Rule::excludeIf(fn () => ! $this->filled('parent_domain_id')),
                'sometimes',
                'boolean',
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('mode') !== DomainMode::WildcardSubdomain->value) {
                return;
            }

            $fqdn = $this->input('fqdn', '');
            $parentId = $this->input('parent_domain_id');

            if (! $parentId) {
                return;
            }

            $parent = Domain::find($parentId);
            if (! $parent) {
                return;
            }

            // *.example.com must have apex = example.com (the parent's fqdn)
            $dotPos = strpos($fqdn, '.');
            if ($dotPos === false) {
                $validator->errors()->add('fqdn', __('Wildcard subdomain must match the apex of the selected parent.'));

                return;
            }
            $expectedApex = substr($fqdn, $dotPos + 1);
            if ($expectedApex !== $parent->fqdn) {
                $validator->errors()->add('fqdn', __('Wildcard subdomain must match the apex of the selected parent.'));
            }
        });
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
