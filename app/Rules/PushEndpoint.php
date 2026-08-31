<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A push service endpoint the site is willing to POST to later.
 *
 * The URL arrives from the member's browser, but nothing stops a client from sending any URL it
 * likes, and the send happens in a queue worker against a client that validates no address and pins
 * no connection — so this is where the destination shape is fixed: https on the default port, a
 * fully-qualified host, no embedded credentials. An address literal or a single-label host
 * (`intranet`) is refused — every real push service is a dotted name, and both are shapes an
 * internal target hides behind.
 *
 * Shape control only: it cannot stop a name that resolves inward (docs/internals/outbound-http.md).
 * The transport's no-redirect, no-proxy configuration is the other half.
 */
final class PushEndpoint implements ValidationRule
{
    /**
     * The column's width. The store and the unsubscribe both bound the field to it, so an endpoint
     * past it is refused as a 422 rather than failing on insert.
     */
    public const MAX_LENGTH = 1024;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isPushService($value)) {
            $fail('validation.url')->translate();
        }
    }

    private function isPushService(string $url): bool
    {
        // A URL is printable ASCII (RFC 3986); anything else is not one, and the column is ascii.
        if (preg_match('/^[\x21-\x7e]+$/', $url) !== 1) {
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

        // A bracketed IPv6 literal reaches here with its brackets; strip them before judging.
        // A dotless host is a single-label internal name, never a push service.
        return $host !== ''
            && str_contains($host, '.')
            && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false;
    }
}
