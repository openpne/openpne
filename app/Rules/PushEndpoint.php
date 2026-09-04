<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The queued send validates no address, so the destination shape is fixed here: an address literal or
 * a single-label host is refused because every real push service is a dotted name and both are shapes
 * an internal target hides behind. Shape only; a name that resolves inward is the transport's problem
 * (docs/internals/outbound-http.md).
 */
final class PushEndpoint implements ValidationRule
{
    /** The column's width, in bytes — one per character, the rule being ASCII. */
    public const MAX_LENGTH = 1024;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isPushService($value)) {
            $fail('validation.url')->translate();
        }
    }

    private function isPushService(string $url): bool
    {
        // Printable ASCII (RFC 3986), matched with \z rather than $, which admits a final newline.
        if (preg_match('/\A[\x21-\x7e]{1,'.self::MAX_LENGTH.'}\z/', $url) !== 1) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || ($parts['port'] ?? 443) !== 443) {
            return false;
        }

        $host = $parts['host'] ?? '';

        // parse_url keeps an IPv6 literal's brackets.
        return $host !== ''
            && str_contains($host, '.')
            && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false;
    }
}
