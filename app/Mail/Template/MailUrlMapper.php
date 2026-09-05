<?php

declare(strict_types=1);

namespace App\Mail\Template;

/**
 * The OpenPNE 3 application argument is ignored and its obsolete query params (id/type) dropped, the
 * OpenPNE 4 routes being token-only. An unmapped route or a missing token throws, so the import preflight
 * surfaces it rather than emitting a broken link.
 */
final class MailUrlMapper
{
    public static function resolve(string $internalUri): string
    {
        $path = (string) parse_url($internalUri, PHP_URL_PATH);
        parse_str((string) parse_url($internalUri, PHP_URL_QUERY), $params);
        $token = (string) ($params['token'] ?? '');

        return match ($path) {
            'member/register' => self::tokenUrl('/register/', $token),
            'member/configComplete' => self::tokenUrl('/member/config/email/confirm/', $token),
            // OpenPNE 3's named-route form (@route?id=N), resolved to the canonical surface-agnostic URL so
            // the mailed link works from any client.
            '@community_home' => route('group.show', ['group' => self::id($params)]),
            '@member_profile' => route('member.profile.show', ['member' => self::id($params)]),
            default => throw new UnsupportedMailTemplateSyntaxException(
                "app_url_for has no OpenPNE 4 mapping for '{$path}'",
                MailTemplateFault::RouteMapFailure,
            ),
        };
    }

    private static function tokenUrl(string $base, string $token): string
    {
        if ($token === '') {
            throw new UnsupportedMailTemplateSyntaxException(
                'app_url_for requires a non-empty `token`',
                MailTemplateFault::RouteMapFailure,
            );
        }

        return url($base.$token);
    }

    /** @param array<string, mixed> $params */
    private static function id(array $params): int
    {
        $id = (string) ($params['id'] ?? '');
        if (! ctype_digit($id)) {
            throw new UnsupportedMailTemplateSyntaxException(
                'app_url_for requires a numeric `id`',
                MailTemplateFault::RouteMapFailure,
            );
        }

        return (int) $id;
    }
}
