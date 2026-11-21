<?php

declare(strict_types=1);

namespace App\Setup;

/** A TRUSTED_PROXIES value typed by a person: addresses and ranges only, none trusting the whole internet. */
final class ProxyList
{
    /** A key under console.setup saying what is wrong, or null. */
    public static function problem(string $value): ?string
    {
        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);
            if ($entry === '*' || $entry === '**') {
                return 'proxy_everyone';
            }
            [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                return 'proxy_not_address';
            }
            if ($prefix === null) {
                continue;
            }
            // Symfony's IpUtils reads /0, and /00 with it, as every address.
            if (preg_match('/\A0+\z/', $prefix) === 1) {
                return 'proxy_everyone';
            }
            $v6 = str_contains($address, ':');
            if (preg_match('/\A[1-9][0-9]{0,2}\z/', $prefix) !== 1 || (int) $prefix > ($v6 ? 128 : 32)) {
                return 'proxy_not_address';
            }
            if ((int) $prefix < ($v6 ? 16 : 8)) {
                return 'proxy_too_wide';
            }
        }

        return null;
    }

    public static function normalize(string $value): string
    {
        return implode(',', array_map('trim', explode(',', $value)));
    }
}
