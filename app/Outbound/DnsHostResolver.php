<?php

declare(strict_types=1);

namespace App\Outbound;

/**
 * Queries the DNS directly, so `/etc/hosts` and NSS modules are not consulted. That divergence from
 * libcurl's own lookup is harmless because the connection is pinned to an address from this answer;
 * a name that resolves only through a hosts file fails rather than being dialled unvalidated.
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
