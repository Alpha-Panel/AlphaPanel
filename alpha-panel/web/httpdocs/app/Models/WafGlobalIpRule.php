<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WafGlobalIpRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'ip_or_cidr',
        'action',
        'note',
        'enabled',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
