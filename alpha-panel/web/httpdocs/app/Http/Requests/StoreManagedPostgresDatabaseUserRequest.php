<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreManagedPostgresDatabaseUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pg_user' => ['required', 'string', 'max:63', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/', 'unique:managed_pg_database_users,pg_user'],
            'pg_password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'pg_user.regex' => 'Username must start with a letter or underscore and contain only letters, numbers, and underscores.',
            'pg_user.unique' => 'This database username already exists.',
            'pg_password.confirmed' => 'Password confirmation does not match.',
        ];
    }
}
