<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A push service endpoint the site is willing to POST to later.
 *
 * The URL arrives from the member's browser, but nothing stops a client from sending any URL it
 * likes, and the send happens in a queue worker against a Guzzle client outside App\Outbound — so
 * this is where the destination shape is fixed: https on the default port, a named host, no
 * embedded credentials. An address literal is refused because every real push service is a named
 * host, and a literal is how an internal target is reached without leaving a name to inspect.
 *
 * Shape control only: it cannot stop a name that resolves inward (docs/internals/outbound-http.md).
 * The transport's no-redirect, no-proxy configuration is the other half.
 */
final class PushEndpoint implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isPushService($value)) {
            $fail('validation.url')->translate();
        }
    }

    private function isPushService(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || ($parts['port'] ?? 443) !== 443) {
            return false;
        }

        $host = $parts['host'] ?? '';

        // A bracketed IPv6 literal reaches here with its brackets; strip them before judging.
        return $host !== '' && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false;
    }
}
