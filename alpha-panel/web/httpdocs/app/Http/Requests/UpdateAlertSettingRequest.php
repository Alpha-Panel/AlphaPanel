<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAlertSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'cpu_warning' => ['required', 'integer', 'min:1', 'max:99'],
            'cpu_critical' => ['required', 'integer', 'min:2', 'max:100'],
            'ram_warning' => ['required', 'integer', 'min:1', 'max:99'],
            'ram_critical' => ['required', 'integer', 'min:2', 'max:100'],
            'disk_warning' => ['required', 'integer', 'min:1', 'max:99'],
            'disk_critical' => ['required', 'integer', 'min:2', 'max:100'],
            'check_interval' => ['required', 'integer', 'min:1', 'max:60'],
            'cooldown_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['cpu', 'ram', 'disk'] as $metric) {
                $warning = (int) $this->input("{$metric}_warning");
                $critical = (int) $this->input("{$metric}_critical");

                if ($warning >= $critical) {
                    $validator->errors()->add(
                        "{$metric}_critical",
                        __('Critical threshold must be greater than warning threshold.')
                    );
                }
            }
        });
    }
}
