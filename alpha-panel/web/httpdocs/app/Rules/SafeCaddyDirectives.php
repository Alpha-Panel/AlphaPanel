<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Guard tenant-supplied Caddy configuration before it is interpolated into, or
 * imported by, the live server block.
 *
 * Two contexts share this rule with different strictness:
 *
 *  - custom.conf (strict): imported INTO the generated server block. The tenant
 *    must not be able to (re)define routing, the filesystem root, TLS material,
 *    the listener, or pull in other config. Strict mode denies all of those.
 *
 *  - custom_caddy_directives (lenient): only used when bypass_reverse_proxy is
 *    on, where the tenant intentionally writes their own handler (reverse_proxy,
 *    root, respond, file_server, ...). Those are allowed, but RCE, config
 *    inclusion, placeholder exfiltration, listener binding, arbitrary-path
 *    logging and TLS file references are always denied.
 *
 * Caddy config is whitespace/brace sensitive and supports placeholders, so a
 * single missed token is enough to break the jail. We reject any line whose
 * leading directive is denied and any line containing a dangerous
 * placeholder/expression. Comments and blank lines are ignored.
 */
class SafeCaddyDirectives implements ValidationRule
{
    public function __construct(private bool $strict = true) {}

    /**
     * Substrings that must never appear anywhere in either context. These cover
     * Caddy placeholders that expose host state / read files and the import and
     * exec directives regardless of where they sit on a line.
     *
     * @var list<string>
     */
    private const DENIED_SUBSTRINGS = [
        'import',
        'exec',
        '{env.',
        '{system.',
        '{file.',
        '{http.vars.',
        '{http.request.tls.client.certificate',
        '`',
    ];

    /**
     * Leading directives denied in every context: they grant remote execution,
     * config inclusion, listener control, TLS file references, or arbitrary
     * file writes via logging.
     *
     * @var list<string>
     */
    private const ALWAYS_DENIED_DIRECTIVES = [
        'import',
        'exec',
        'bind',
        'log',
        'tls',
        'php',
    ];

    /**
     * Leading directives denied only in strict (custom.conf) context: they let
     * a tenant redefine routing or the filesystem root of a block they are only
     * meant to extend.
     *
     * @var list<string>
     */
    private const STRICT_DENIED_DIRECTIVES = [
        'root',
        'file_server',
        'php_server',
        'php_fastcgi',
        'fastcgi',
        'reverse_proxy',
        'respond',
        'file',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail(__('The :attribute contains invalid configuration.'));

            return;
        }

        if (str_contains($value, "\0")) {
            $fail(__('The :attribute contains invalid characters.'));

            return;
        }

        $lower = strtolower($value);
        foreach (self::DENIED_SUBSTRINGS as $needle) {
            if (str_contains($lower, $needle)) {
                $fail(__('The :attribute contains a blocked directive: :pattern', ['pattern' => $needle]));

                return;
            }
        }

        $denied = $this->strict
            ? array_merge(self::ALWAYS_DENIED_DIRECTIVES, self::STRICT_DENIED_DIRECTIVES)
            : self::ALWAYS_DENIED_DIRECTIVES;

        foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Strip a leading request matcher token (e.g. "@name") and any
            // opening/closing braces so we inspect the real directive.
            $line = ltrim($line, "{}\t ");
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }

            $firstToken = strtolower(preg_split('/\s+/', $line, 2)[0] ?? '');

            if ($firstToken === '') {
                continue;
            }

            if (in_array($firstToken, $denied, true)) {
                $fail(__('The :attribute contains a blocked directive: :pattern', ['pattern' => $firstToken]));

                return;
            }
        }
    }
}
