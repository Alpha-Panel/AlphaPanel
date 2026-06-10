<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RunArtisanCommandRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED_SUBCOMMANDS = [
        'optimize',
        'optimize:clear',
        'config:cache',
        'config:clear',
        'route:cache',
        'route:clear',
        'view:cache',
        'view:clear',
        'event:cache',
        'event:clear',
        'cache:clear',
        'queue:restart',
        'migrate',
        'migrate:status',
        'storage:link',
        'storage:unlink',
        'schedule:list',
        'key:generate',
        'package:discover',
    ];

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

                if (str_starts_with($normalized, 'php artisan ')) {
                    $rest = substr($normalized, strlen('php artisan '));
                } elseif (str_starts_with($normalized, 'artisan ')) {
                    $rest = substr($normalized, strlen('artisan '));
                } else {
                    $fail(__('The command must start with "php artisan".'));

                    return;
                }

                $parts = preg_split('/\s+/', trim($rest));
                $subcommand = $parts[0] ?? '';

                if (! in_array($subcommand, self::ALLOWED_SUBCOMMANDS, true)) {
                    $fail(__('The artisan command ":cmd" is not allowed.', ['cmd' => $subcommand]));
                }
            }],
        ];
    }
}
