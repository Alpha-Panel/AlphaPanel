<?php

namespace App\Services\Mail\Exceptions;

class ZimbraSoapFaultException extends MailProviderException
{
    public function __construct(
        string $message,
        public readonly ?string $code = null,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($message);
    }
}
