<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Stringable;

/**
 * An event carries the actor and guard where one is resolved, the attempted identifier only for
 * failed-login, lockout and email-change events, and never a password, token, session id, recovery
 * code or raw credentials array (docs/internals/logging.md).
 */
final class SecurityLog
{
    private const MAX_LENGTH = 256;

    public static function event(string $event, array $context = []): void
    {
        // Caller-set keys win; the request fields fill only what is absent.
        Log::channel('security')->info($event, self::sanitize($context + self::requestContext()));
    }

    /** @param array<string, mixed> $context */
    private static function sanitize(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            $string = self::stringify($value);
            if ($string === null) {
                continue;
            }

            $string = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $string);
            $clean[$key] = mb_substr($string, 0, self::MAX_LENGTH);
        }

        return $clean;
    }

    private static function stringify(mixed $value): ?string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value instanceof Stringable => (string) $value,
            default => null,
        };
    }

    /**
     * The client's ip / user_agent for HTTP requests. A console command binds a CLI-derived stub
     * request (argv in its server bag) with a bogus 127.0.0.1 / "Symfony" client, so it must not
     * stamp network fields — both conditions guard that, and neither the file nor its truncation
     * is applied here (sanitize() handles the merged result).
     *
     * @return array<string, string|null>
     */
    private static function requestContext(): array
    {
        if (! app()->bound('request')) {
            return [];
        }

        $request = app('request');

        if (app()->runningInConsole() && $request->server->has('argv')) {
            return [];
        }

        return ['ip' => $request->ip(), 'user_agent' => $request->userAgent()];
    }
}
