<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RunArtisanCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'command' => ['required', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                $normalized = trim((string) $value);

                if (! str_starts_with($normalized, 'php artisan ') && ! str_starts_with($normalized, 'artisan ')) {
                    $fail(__('The command must start with "php artisan".'));

                    return;
                }

                $blocked = ['rm -rf /', 'mkfs', 'dd if=', ':(){', 'chmod -R 777 /', 'chown -R'];
                foreach ($blocked as $pattern) {
                    if (str_contains($normalized, $pattern)) {
                        $fail(__('The command contains a blocked pattern.'));

                        return;
                    }
                }
            }],
        ];
    }
}
