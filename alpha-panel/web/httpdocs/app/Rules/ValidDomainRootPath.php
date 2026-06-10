<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validate a user-supplied document-root path before it is written into a
 * Caddyfile / Apache vhost. A tenant must not be able to point their web root
 * outside their own jail (`/var/www/vhosts/{apex}/...`), traverse with "..",
 * or smuggle newlines / config metacharacters into the generated server block.
 *
 * The apex is resolved lazily so the same rule works for the create flow
 * (apex derived from the submitted FQDN) and the update flow (apex taken from
 * the persisted domain).
 */
class ValidDomainRootPath implements ValidationRule
{
    private const JAIL_ROOT = '/var/www/vhosts';

    /**
     * @param  (Closure(): ?string)  $apexResolver  Returns the apex hostname whose jail the path must live in.
     */
    public function __construct(private Closure $apexResolver) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('The :attribute must be a valid absolute path.'));

            return;
        }

        if (preg_match('/[\r\n\0]/', $value) === 1) {
            $fail(__('The :attribute contains invalid characters.'));

            return;
        }

        if (! str_starts_with($value, '/')) {
            $fail(__('The :attribute must be an absolute path.'));

            return;
        }

        if (str_contains($value, '..')) {
            $fail(__('The :attribute must not contain parent directory references.'));

            return;
        }

        $apex = ($this->apexResolver)();
        if (! is_string($apex) || $apex === '') {
            $fail(__('The :attribute could not be validated against a known domain.'));

            return;
        }

        $canonical = $this->canonicalize($value);
        $jail = $this->canonicalize(self::JAIL_ROOT.'/'.$apex);

        if ($canonical !== $jail && ! str_starts_with($canonical, $jail.'/')) {
            $fail(__('The :attribute must be inside the document root of this domain.'));
        }
    }

    /**
     * Collapse duplicate and trailing slashes without touching the filesystem.
     * Traversal ("..") is already rejected above, so a lexical normalisation is
     * sufficient and works regardless of whether the path exists on this host.
     */
    private function canonicalize(string $path): string
    {
        $collapsed = preg_replace('#/+#', '/', $path);
        if (! is_string($collapsed)) {
            $collapsed = $path;
        }

        return $collapsed === '/' ? '/' : rtrim($collapsed, '/');
    }
}
