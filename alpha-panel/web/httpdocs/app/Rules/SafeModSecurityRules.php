<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Guard tenant-supplied ModSecurity (SecLang) rules before they are written raw
 * into a domain's generated WAF config and loaded by the engine.
 *
 * SecLang is powerful enough to disable the engine, redirect audit logs to
 * arbitrary files, pull in other configuration, or execute Lua/external scripts.
 * Free-form rules are therefore restricted to an allowlist of directives
 * (SecRule, SecAction, SecMarker) and an action blocklist that strips anything
 * able to touch the filesystem, run code, or reconfigure the engine. Comments
 * and blank lines are ignored.
 */
class SafeModSecurityRules implements ValidationRule
{
    /**
     * The only SecLang directives a tenant may use. Anything else (engine,
     * logging, include, request-body handling, etc.) is rejected outright.
     *
     * @var list<string>
     */
    private const ALLOWED_DIRECTIVES = [
        'secrule',
        'secaction',
        'secmarker',
    ];

    /**
     * Substrings that must never appear anywhere in the input, regardless of
     * position. These cover code execution, configuration includes, and
     * engine/audit reconfiguration done via chained actions.
     *
     * @var list<string>
     */
    private const DENIED_SUBSTRINGS = [
        'exec:',
        'lua',
        'setenv',
        'ctl:ruleengine',
        'ctl:auditengine',
        'ctl:requestbodyaccess',
        'ctl:forcerequestbodyvariable',
        'ctl:debugloglevel',
        'append:',
        'prepend:',
        '`',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail(__('The :attribute contains invalid rules.'));

            return;
        }

        if (preg_match('/[\0]/', $value) === 1) {
            $fail(__('The :attribute contains invalid characters.'));

            return;
        }

        $lower = strtolower($value);
        foreach (self::DENIED_SUBSTRINGS as $needle) {
            if (str_contains($lower, $needle)) {
                $fail(__('The :attribute contains a blocked action: :pattern', ['pattern' => $needle]));

                return;
            }
        }

        foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $firstToken = strtolower(preg_split('/\s+/', $line, 2)[0] ?? '');

            if ($firstToken === '') {
                continue;
            }

            if (! in_array($firstToken, self::ALLOWED_DIRECTIVES, true)) {
                $fail(__('The :attribute contains a blocked directive: :pattern', ['pattern' => $firstToken]));

                return;
            }

            if (str_starts_with($firstToken, 'secaudit')
                || str_starts_with($firstToken, 'secruleengine')
                || str_contains($line, 'include')
            ) {
                $fail(__('The :attribute contains a blocked directive.'));

                return;
            }
        }
    }
}
