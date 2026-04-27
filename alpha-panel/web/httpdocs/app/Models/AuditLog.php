<?php

namespace App\Models;

use App\Services\ImpersonationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'impersonator_id',
        'action',
        'domain_id',
        'summary',
        'details',
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
            $request = request();
            $isHttp = $request instanceof Request;

            if ($auditLog->ip_address === null && $isHttp) {
                $ip = $request->ip();
                if (is_string($ip) && $ip !== '') {
                    $auditLog->ip_address = $ip;
                }
            }

            if ($auditLog->port === null && $isHttp) {
                $remotePort = $request->server('REMOTE_PORT');
                if (is_numeric($remotePort)) {
                    $auditLog->port = (int) $remotePort;
                }
            }

            if ($auditLog->impersonator_id === null) {
                $service = app(ImpersonationService::class);
                if ($service->isActive()) {
                    $impersonator = $service->impersonator();
                    if ($impersonator !== null) {
                        $auditLog->impersonator_id = $impersonator->id;
                    }
                }
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }
}
