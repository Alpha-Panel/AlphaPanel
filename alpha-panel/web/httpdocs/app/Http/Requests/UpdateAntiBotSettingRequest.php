<?php

namespace App\Http\Requests;

use App\Models\SecuritySetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAntiBotSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'captcha_provider' => ['required', 'string', Rule::in(['none', 'turnstile', 'recaptcha'])],
            'turnstile_site_key' => ['nullable', 'string', 'max:500', 'required_if:captcha_provider,turnstile'],
            'turnstile_secret_key' => ['nullable', 'string', 'max:500'],
            'recaptcha_version' => ['nullable', 'string', Rule::in(['v2', 'v3']), 'required_if:captcha_provider,recaptcha'],
            'recaptcha_site_key' => ['nullable', 'string', 'max:500', 'required_if:captcha_provider,recaptcha'],
            'recaptcha_secret_key' => ['nullable', 'string', 'max:500'],
            'honeypot_enabled' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $provider = $this->input('captcha_provider');
            $settings = SecuritySetting::instance();

            if ($provider === 'turnstile' && empty($this->input('turnstile_secret_key')) && empty($settings->turnstile_secret_key)) {
                $validator->errors()->add('turnstile_secret_key', __('Secret key is required.'));
            }

            if ($provider === 'recaptcha' && empty($this->input('recaptcha_secret_key')) && empty($settings->recaptcha_secret_key)) {
                $validator->errors()->add('recaptcha_secret_key', __('Secret key is required.'));
            }
        });
    }
}
