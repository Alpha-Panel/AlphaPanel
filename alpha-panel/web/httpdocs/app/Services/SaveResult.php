<?php

namespace App\Services;

readonly class SaveResult
{
    public function __construct(
        public bool $fileWritten,
        public bool $setGlobalApplied,
        public bool $restartRequired,
        /** @var array<string> */
        public array $setGlobalErrors,
    ) {}
}
