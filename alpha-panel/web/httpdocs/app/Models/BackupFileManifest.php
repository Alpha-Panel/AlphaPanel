<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupFileManifest extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'backup_run_id',
        'domain',
        'relative_path',
        'file_size',
        'file_mtime',
        'drive_file_id',
        'action',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'file_mtime' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function backupRun(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class);
    }
}
