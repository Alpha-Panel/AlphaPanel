<?php

namespace App\Actions\Domain;

use RuntimeException;

/**
 * Thrown by ProvisionDomainAction when input validation fails after the
 * FormRequest stage (e.g. DNS target IP mismatch, Cloudflare zone create
 * failure). The controller catches this and rewraps it into the same
 * validation-error HTTP response the original inline logic produced.
 */
class ProvisionDomainValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $field,
        public readonly string $userMessage,
    ) {
        parent::__construct($userMessage);
    }
}
