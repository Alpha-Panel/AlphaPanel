<?php

namespace App\Exceptions;

/**
 * Thrown by SslCertificateService when user-supplied PEM input fails parsing
 * or validation (missing certificate, key/cert mismatch, malformed PEM
 * structure). Controllers should catch this and convert to a user-facing
 * validation error rather than letting it bubble as a 500.
 */
class SslImportException extends \RuntimeException {}
