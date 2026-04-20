<?php

namespace App\Services;

use App\Models\DomainSupervisor;
use RuntimeException;

class ReverbPortAllocator
{
    /**
     * Allocate a Reverb port for the supervisor record or return the existing one.
     *
     * Scans config('panel.reverb_port_range') for the first port not already held
     * by another DomainSupervisor. Race conditions are caught by the `unique`
     * DB constraint on reverb_port.
     */
    public function allocate(DomainSupervisor $supervisor): int
    {
        if ($supervisor->reverb_port !== null) {
            return $supervisor->reverb_port;
        }

        $min = (int) config('panel.reverb_port_range.min', 8000);
        $max = (int) config('panel.reverb_port_range.max', 8999);

        if ($max < $min) {
            throw new RuntimeException("Invalid Reverb port range: {$min}-{$max}.");
        }

        $used = DomainSupervisor::query()
            ->whereNotNull('reverb_port')
            ->where('id', '!=', $supervisor->id)
            ->pluck('reverb_port')
            ->flip();

        for ($port = $min; $port <= $max; $port++) {
            if (! $used->has($port)) {
                $supervisor->forceFill(['reverb_port' => $port])->save();

                return $port;
            }
        }

        throw new RuntimeException("No free port in Reverb range {$min}-{$max}.");
    }
}
