<?php

namespace App\Observers;

use App\Models\FirewallRule;
use Illuminate\Support\Facades\Cache;

class FirewallRuleObserver
{
    public function created(FirewallRule $rule): void
    {
        $this->markPendingChanges();
    }

    public function updated(FirewallRule $rule): void
    {
        $this->markPendingChanges();
    }

    public function deleted(FirewallRule $rule): void
    {
        $this->markPendingChanges();
    }

    private function markPendingChanges(): void
    {
        Cache::put('firewall:pending_changes', true);
    }
}
