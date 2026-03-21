<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DockerServiceDomainBinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain_id',
        'docker_service_id',
        'container_port',
        'path_prefix',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'container_port' => 'integer',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function dockerService(): BelongsTo
    {
        return $this->belongsTo(DockerService::class);
    }
}
