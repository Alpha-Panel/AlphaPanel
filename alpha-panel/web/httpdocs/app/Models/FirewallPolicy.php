<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FirewallPolicy extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'chain',
        'policy',
    ];

    /**
     * Get the current policy for the given chain.
     */
    public static function getPolicy(string $chain): string
    {
        return static::where('chain', strtoupper($chain))->value('policy') ?? 'ACCEPT';
    }

    /**
     * Set the policy for the given chain.
     */
    public static function setPolicy(string $chain, string $policy): void
    {
        static::updateOrCreate(
            ['chain' => strtoupper($chain)],
            ['policy' => strtoupper($policy)],
        );
    }
}
