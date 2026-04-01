<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsTemplate extends Model
{
    protected $fillable = [
        'name',
        'is_default',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function records(): HasMany
    {
        return $this->hasMany(DnsTemplateRecord::class);
    }

    /**
     * Mark this template as the default and unset all others.
     */
    public function markAsDefault(): void
    {
        self::query()->where('is_default', true)->update(['is_default' => false]);
        $this->update(['is_default' => true]);
    }

    /**
     * Get the current default template.
     */
    public static function getDefault(): ?self
    {
        return self::query()->where('is_default', true)->first();
    }
}
