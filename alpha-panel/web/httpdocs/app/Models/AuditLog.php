<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'domain_id',
        'summary',
        'ip_address',
        'port',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $auditLog): void {
            if ($auditLog->ip_address !== null || $auditLog->port !== null) {
                return;
            }

            $request = request();
            if (! $request instanceof \Illuminate\Http\Request) {
                return;
            }

            $ipAddress = $request->ip();
            if (is_string($ipAddress) && $ipAddress !== '') {
                $auditLog->ip_address = $ipAddress;
            }

            $remotePort = $request->server('REMOTE_PORT');
            if (is_numeric($remotePort)) {
                $auditLog->port = (int) $remotePort;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }
}
