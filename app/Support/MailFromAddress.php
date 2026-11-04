<?php

declare(strict_types=1);

namespace App\Support;

final class MailFromAddress
{
    /** The RFC 2606 documentation domains: the shipped placeholder lives there, and nothing under them can be a real sender. */
    private const PLACEHOLDER_DOMAINS = ['example.com', 'example.net', 'example.org'];

    /** The configured mail.from.address, or the administrator's address while that is not configured. */
    public static function sender(): string
    {
        $configured = (string) config('mail.from.address');

        return self::isConfigured($configured) ? $configured : sns_admin_mail_address();
    }

    public static function isConfigured(string $address): bool
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return false;
        }

        $domain = strtolower(substr($address, $at + 1));
        foreach (self::PLACEHOLDER_DOMAINS as $placeholder) {
            if ($domain === $placeholder || str_ends_with($domain, '.'.$placeholder)) {
                return false;
            }
        }

        return true;
    }
}
