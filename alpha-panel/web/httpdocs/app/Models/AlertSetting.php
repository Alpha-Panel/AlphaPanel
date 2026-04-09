<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertSetting extends Model
{
    protected $fillable = [
        'enabled',
        'cpu_warning',
        'cpu_critical',
        'ram_warning',
        'ram_critical',
        'disk_warning',
        'disk_critical',
        'check_interval',
        'cooldown_minutes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'cpu_warning' => 'integer',
            'cpu_critical' => 'integer',
            'ram_warning' => 'integer',
            'ram_critical' => 'integer',
            'disk_warning' => 'integer',
            'disk_critical' => 'integer',
            'check_interval' => 'integer',
            'cooldown_minutes' => 'integer',
        ];
    }

    public static function instance(): self
    {
        return self::firstOrCreate([]);
    }

    public function getThreshold(string $metric, string $level): int
    {
        $attribute = "{$metric}_{$level}";

        return (int) $this->getAttribute($attribute);
    }
}
