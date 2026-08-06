<?php

declare(strict_types=1);

namespace App\Outbound;

/**
 * Resolves a hostname to its addresses.
 *
 * A seam rather than a direct dns_get_record() call so the SSRF tests can drive answers that are
 * impossible to arrange for real: a public first hop redirecting to a loopback second one, an answer
 * mixing public and private addresses, an empty answer.
 */
interface HostResolver
{
    /**
     * Every A and AAAA address for $host, in no particular order.
     *
     * @return list<string> Textual addresses; empty when the name does not resolve.
     */
    public function resolve(string $host): array;
}
