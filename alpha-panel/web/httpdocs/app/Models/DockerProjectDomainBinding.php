<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DockerProjectDomainBinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain_id',
        'docker_project_id',
        'service_name',
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

    public function dockerProject(): BelongsTo
    {
        return $this->belongsTo(DockerProject::class);
    }
}
