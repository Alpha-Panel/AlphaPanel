<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PhpSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'php_version_id' => ['required', 'exists:php_versions,id'],
            'display_errors' => ['required', 'in:On,Off'],
            'error_reporting' => ['required', 'string', 'max:100'],
            'memory_limit' => ['required', 'in:32M,64M,128M,256M,512M,1G,2G,4G'],
            'post_max_size' => ['required', 'in:8M,16M,32M,64M,128M,256M,512M,1G,2G,4G'],
            'upload_max_filesize' => ['required', 'in:8M,16M,32M,64M,128M,256M,512M,1G,2G,4G'],
            'max_execution_time' => ['required', 'integer', 'min:0', 'max:86400'],
            'max_input_time' => ['required', 'integer', 'min:0', 'max:86400'],
            'max_input_vars' => ['required', 'integer', 'min:100', 'max:100000'],
            'session_gc_maxlifetime' => ['required', 'integer', 'min:0', 'max:604800'],
            'session_cookie_lifetime' => ['required', 'integer', 'min:0', 'max:604800'],
            'opcache_enable' => ['required', 'in:On,Off'],
            'date_timezone' => ['required', 'timezone'],
            'allow_url_fopen' => ['required', 'in:On,Off'],
            'disable_functions' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
