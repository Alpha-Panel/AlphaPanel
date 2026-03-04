<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhpSetting extends Model
{
    protected $fillable = [
        'domain_id',
        'display_errors',
        'error_reporting',
        'memory_limit',
        'post_max_size',
        'upload_max_filesize',
        'max_execution_time',
        'max_input_time',
        'max_input_vars',
        'session_gc_maxlifetime',
        'session_cookie_lifetime',
        'opcache_enable',
        'date_timezone',
        'allow_url_fopen',
        'disable_functions',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'max_execution_time' => 'integer',
            'max_input_time' => 'integer',
            'max_input_vars' => 'integer',
            'session_gc_maxlifetime' => 'integer',
            'session_cookie_lifetime' => 'integer',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
