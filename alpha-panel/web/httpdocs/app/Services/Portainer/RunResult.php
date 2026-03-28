<?php

namespace App\Services\Portainer;

class RunResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
