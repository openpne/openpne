<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Stringable;

/**
 * The security event trail: authentication and credential-mutation events, written to the
 * dedicated `security` log channel (config/logging.php, docs/internals/logging.md).
 *
 * PII / secret contract — what an event MAY and MUST NOT carry:
 *  - ALWAYS the actor where a resolved one exists (member id / admin username) and the guard.
 *  - The attempted identifier (email / username) ONLY for failed-login and lockout events (no
 *    actor has been resolved yet) and for email-change events (the address is the subject).
 *  - NEVER a password, token, session id, recovery code, or a Failed event's raw credentials
 *    array. Listeners and seams pass the single identifier value, never the credential map.
 *
 * Every context value is sanitised: cast to string (a bool becomes "true"/"false"), control
 * characters — including CR/LF, which would otherwise forge log lines — collapsed to a space,
 * and truncated to 256. A null (or any non-scalar, non-Stringable) value is dropped, so an
 * absent actor simply leaves no key rather than logging an empty one.
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
