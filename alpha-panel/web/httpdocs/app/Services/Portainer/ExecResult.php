<?php

namespace App\Services\Portainer;

class ExecResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
