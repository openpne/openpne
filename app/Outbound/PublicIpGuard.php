<?php

declare(strict_types=1);

namespace App\Outbound;

/**
 * Fail-closed: IPv6 is an allowlist (global unicast is 2000::/3) with carve-outs, and IPv4 a
 * denylist drawn from IANA's special-purpose registry (`Global: False` entries plus multicast)
 * rather than a hand-picked set. An address embedding an IPv4 one (IPv4-mapped, IPv4-compatible,
 * 6to4, NAT64 well-known prefix) is judged on the embedded address; `64:ff9b:1::/48` is rejected
 * outright because RFC 6052 allows several offsets there.
 *
 * @see https://www.iana.org/assignments/iana-ipv4-special-registry/
 * @see https://www.iana.org/assignments/iana-ipv6-special-registry/
 */
final class PublicIpGuard
{
    /** IANA IPv4 special-purpose entries with Global: False, plus multicast (RFC 5771). */
    private const DENIED_V4 = [
        '0.0.0.0/8',          // "This network"
        '10.0.0.0/8',         // Private-Use
        '100.64.0.0/10',      // Shared Address Space (CGNAT)
        '127.0.0.0/8',        // Loopback
        '169.254.0.0/16',     // Link Local
        '172.16.0.0/12',      // Private-Use
        '192.0.0.0/24',       // IETF Protocol Assignments
        '192.0.2.0/24',       // Documentation (TEST-NET-1)
        '192.88.99.0/24',     // Deprecated 6to4 Relay Anycast
        '192.168.0.0/16',     // Private-Use
        '198.18.0.0/15',      // Benchmarking
        '198.51.100.0/24',    // Documentation (TEST-NET-2)
        '203.0.113.0/24',     // Documentation (TEST-NET-3)
        '224.0.0.0/4',        // Multicast
        '240.0.0.0/4',        // Reserved (covers 255.255.255.255)
    ];

    /**
     * Carve-outs inside 2000::/3. Everything outside that prefix is already rejected, so this list
     * only needs the special-purpose ranges that fall within global unicast.
     */
    private const DENIED_V6 = [
        '2001::/23',          // IETF Protocol Assignments (covers TEREDO 2001::/32 and benchmarking 2001:2::/48)
        '2001:db8::/32',      // Documentation — a separate registry entry, NOT inside 2001::/23
        '2002::/16',          // 6to4 — handled by embedded-v4 extraction, denied if that fails
        '3fff::/20',          // Documentation (RFC 9637)
        '5f00::/16',          // Segment Routing (SRv6) SIDs
    ];

    /** @var list<string> Operator-supplied extra ranges. These only ever subtract from what is allowed. */
    private readonly array $extraDenied;

    /**
     * @param  list<string>  $extraDenied  CIDRs to reject on top of the built-in list.
     *
     * @throws OutboundException when a range is malformed: a typo in a security setting must not
     *                           read as no extra ranges configured.
     */
    public function __construct(array $extraDenied = [])
    {
        $ranges = array_values(array_filter(array_map('trim', $extraDenied), fn (string $range): bool => $range !== ''));

        foreach ($ranges as $range) {
            if (! $this->isWellFormedCidr($range)) {
                throw OutboundException::blocked("[{$range}] in the outbound denied_cidrs list is not a valid CIDR range.");
            }
        }

        $this->extraDenied = $ranges;
    }

    private function isWellFormedCidr(string $cidr): bool
    {
        $parts = explode('/', $cidr);

        if (count($parts) !== 2 || ! ctype_digit($parts[1])) {
            return false;
        }

        $packed = @inet_pton($parts[0]);

        return $packed !== false && (int) $parts[1] <= strlen($packed) * 8;
    }

    /** Whether $ip (a textual IPv4 or IPv6 address) is a globally-routable unicast destination. */
    public function isGlobal(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        return match (strlen($packed)) {
            4 => $this->isGlobalV4($packed),
            16 => $this->isGlobalV6($packed),
            default => false,
        };
    }

    private function isGlobalV4(string $packed): bool
    {
        foreach (self::DENIED_V4 as $cidr) {
            if ($this->inCidr($packed, $cidr)) {
                return false;
            }
        }

        return ! $this->inExtraDenied($packed);
    }

    private function isGlobalV6(string $packed): bool
    {
        // Re-judge by the embedded IPv4 wherever one exists: the wrapper's own prefix says nothing
        // about where the packet lands.
        $embedded = $this->embeddedV4($packed);

        if ($embedded !== null) {
            return $this->isGlobalV4($embedded) && ! $this->inExtraDenied($packed);
        }

        // No single embedded-address offset to check (RFC 6052 §2.2), so reject.
        if ($this->inCidr($packed, '64:ff9b:1::/48')) {
            return false;
        }

        // Global unicast is 2000::/3, so loopback, unspecified, ULA, link-local and multicast need no
        // separate entries.
        if (! $this->inCidr($packed, '2000::/3')) {
            return false;
        }

        foreach (self::DENIED_V6 as $cidr) {
            if ($this->inCidr($packed, $cidr)) {
                return false;
            }
        }

        return ! $this->inExtraDenied($packed);
    }

    /** The packed IPv4 address embedded in $packed, or null when the address embeds none. */
    private function embeddedV4(string $packed): ?string
    {
        // ::ffff:0:0/96 (IPv4-mapped) and ::/96 (deprecated IPv4-compatible) carry it in the last 4 bytes.
        if ($this->inCidr($packed, '::ffff:0:0/96') || $this->inCidr($packed, '::/96')) {
            return substr($packed, 12, 4);
        }

        // 2002::/16 (6to4) carries it in bytes 2-5.
        if ($this->inCidr($packed, '2002::/16')) {
            return substr($packed, 2, 4);
        }

        // 64:ff9b::/96 (NAT64 well-known prefix) carries it in the last 4 bytes.
        if ($this->inCidr($packed, '64:ff9b::/96')) {
            return substr($packed, 12, 4);
        }

        return null;
    }

    private function inExtraDenied(string $packed): bool
    {
        foreach ($this->extraDenied as $cidr) {
            if ($this->inCidr($packed, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the packed address $packed falls inside $cidr. A malformed $cidr never matches. */
    private function inCidr(string $packed, string $cidr): bool
    {
        [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $packedNetwork = @inet_pton((string) $network);

        if ($packedNetwork === false || $bits === null || ! ctype_digit($bits)) {
            return false;
        }

        $bits = (int) $bits;
        $length = strlen($packed);

        // An IPv4 address is never inside an IPv6 range, or vice versa.
        if ($length !== strlen($packedNetwork) || $bits > $length * 8) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);

        if ($wholeBytes > 0 && strncmp($packed, $packedNetwork, $wholeBytes) !== 0) {
            return false;
        }

        $remainingBits = $bits % 8;

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($packed[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }
}
