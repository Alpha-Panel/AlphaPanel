<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendPushNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:1000'],
            'url' => ['nullable', 'url', 'max:500'],
            'target' => ['required', 'string', 'in:all,admins,domain'],
            'domain_id' => ['nullable', 'required_if:target,domain', 'exists:domains,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'domain_id.required_if' => __('Please select a domain when targeting domain owners.'),
        ];
    }
}
