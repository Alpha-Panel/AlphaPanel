<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreManagedDatabaseUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'db_user' => ['required', 'string', 'max:32', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/', 'unique:managed_database_users,db_user'],
            'db_password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'db_user.regex' => 'Username must start with a letter or underscore and contain only letters, numbers, and underscores.',
            'db_user.unique' => 'This database username already exists.',
            'db_password.confirmed' => 'Password confirmation does not match.',
        ];
    }
}
