<?php

namespace App\Models;

use App\Enums\SupervisorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainSupervisor extends Model
{
    protected $fillable = [
        'domain_id',
        'type',
        'enabled',
        'num_procs',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SupervisorType::class,
            'enabled' => 'boolean',
            'num_procs' => 'integer',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function confFileName(): string
    {
        return $this->domain->fqdn.'-'.$this->type->value.'.conf';
    }
}
