<?php

declare(strict_types=1);

namespace App\Outbound;

/**
 * Resolves through the DNS, deliberately not through the system resolver.
 *
 * This answer is not what libcurl would look up on its own — /etc/hosts and NSS modules are not
 * consulted here. That divergence is harmless because the connection is pinned to an address from
 * this answer (SafeHttpFetcher), so the validated address is the one actually dialled. A name that
 * resolves only through a local hosts file therefore fails rather than being reached unvalidated.
 */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        // Queried separately: DNS_A|DNS_AAAA in one call is unreliable across libresolv builds.
        $records = array_merge(
            @dns_get_record($host, DNS_A) ?: [],
            @dns_get_record($host, DNS_AAAA) ?: [],
        );

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
