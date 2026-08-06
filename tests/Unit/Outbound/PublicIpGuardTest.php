<?php

declare(strict_types=1);

namespace Tests\Unit\Outbound;

use App\Outbound\OutboundException;
use App\Outbound\PublicIpGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicIpGuardTest extends TestCase
{
    #[DataProvider('nonGlobalAddresses')]
    public function test_it_rejects_non_global_addresses(string $address): void
    {
        $this->assertFalse((new PublicIpGuard)->isGlobal($address), "[{$address}] must not be treated as global.");
    }

    #[DataProvider('globalAddresses')]
    public function test_it_accepts_global_addresses(string $address): void
    {
        $this->assertTrue((new PublicIpGuard)->isGlobal($address), "[{$address}] must be treated as global.");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonGlobalAddresses(): array
    {
        return [
            // IPv4, IANA special-purpose Global: False
            'this network' => ['0.0.0.0'],
            'private 10/8' => ['10.0.0.1'],
            'private 172.16/12 low' => ['172.16.0.1'],
            'private 172.16/12 high' => ['172.31.255.254'],
            'private 192.168/16' => ['192.168.1.1'],
            'shared address space (CGNAT)' => ['100.64.0.1'],
            'loopback' => ['127.0.0.1'],
            'loopback, not .1' => ['127.255.255.254'],
            'link local' => ['169.254.169.254'],
            'IETF protocol assignments' => ['192.0.0.1'],
            'documentation TEST-NET-1' => ['192.0.2.1'],
            'deprecated 6to4 relay anycast' => ['192.88.99.1'],
            'benchmarking' => ['198.19.0.1'],
            'documentation TEST-NET-2' => ['198.51.100.1'],
            'documentation TEST-NET-3' => ['203.0.113.1'],
            'multicast' => ['224.0.0.1'],
            'reserved' => ['240.0.0.1'],
            'limited broadcast' => ['255.255.255.255'],

            // IPv6 outside global unicast
            'unspecified' => ['::'],
            'v6 loopback' => ['::1'],
            'unique local' => ['fc00::1'],
            'unique local, locally assigned' => ['fd00::1'],
            'v6 link local' => ['fe80::1'],
            'v6 multicast' => ['ff02::1'],
            'discard only' => ['100::1'],

            // IPv6 carve-outs inside 2000::/3
            'IETF protocol assignments v6' => ['2001:0:1::1'],
            'teredo' => ['2001::abcd'],
            'v6 benchmarking' => ['2001:2::1'],
            'v6 documentation' => ['2001:db8::1'],
            'v6 documentation (RFC 9637)' => ['3fff::1'],
            'SRv6 SIDs' => ['5f00::1'],

            // An embedded IPv4 is judged on the address the packet reaches, not on its wrapper.
            'IPv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'IPv4-mapped private' => ['::ffff:10.0.0.1'],
            'IPv4-mapped link local, hex form' => ['::ffff:a9fe:a9fe'],
            'IPv4-compatible loopback' => ['::127.0.0.1'],
            '6to4 wrapping private' => ['2002:a00:1::1'],
            '6to4 wrapping link local' => ['2002:a9fe:a9fe::1'],
            'NAT64 wrapping loopback' => ['64:ff9b::127.0.0.1'],
            'NAT64 wrapping private' => ['64:ff9b::a00:1'],
            // RFC 6052 allows several embedded-address offsets under this prefix, so there is no one
            // position to re-check and it is refused outright.
            'NAT64 local-use prefix' => ['64:ff9b:1::1'],

            // Not addresses at all
            'empty' => [''],
            'hostname' => ['example.com'],
            'decimal integer form' => ['2130706433'],
            'octal-ish dotted quad' => ['0177.0.0.1'],
            'trailing junk' => ['1.2.3.4x'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function globalAddresses(): array
    {
        return [
            'public v4' => ['93.184.216.34'],
            'public v4, just past 172.16/12' => ['172.32.0.1'],
            'public v4, just past 100.64/10' => ['100.128.0.1'],
            'public v4, just before multicast' => ['223.255.255.254'],
            'public v6' => ['2606:2800:220:1:248:1893:25c8:1946'],
            'public v6, just past the IETF assignment block' => ['2001:200::1'],
            'public v6 in 2400::/12' => ['2404:6800:4004:80c::200e'],
            'IPv4-mapped public' => ['::ffff:93.184.216.34'],
            '6to4 wrapping a public v4' => ['2002:5db8:d822::1'],
            'NAT64 wrapping a public v4' => ['64:ff9b::93.184.216.34'],
        ];
    }

    public function test_operator_supplied_ranges_reject_otherwise_global_addresses(): void
    {
        $guard = new PublicIpGuard(['93.184.216.0/24', '2606:2800::/32']);

        $this->assertFalse($guard->isGlobal('93.184.216.34'));
        $this->assertFalse($guard->isGlobal('2606:2800:220:1:248:1893:25c8:1946'));
        $this->assertTrue($guard->isGlobal('93.184.217.1'), 'A neighbouring range stays reachable.');
    }

    public function test_operator_supplied_ranges_cannot_re_permit_a_denied_address(): void
    {
        // The extra list only ever subtracts. Naming a private range does not make it reachable.
        $guard = new PublicIpGuard(['0.0.0.0/0']);

        $this->assertFalse($guard->isGlobal('10.0.0.1'));
        $this->assertFalse($guard->isGlobal('93.184.216.34'));
    }

    #[DataProvider('malformedRanges')]
    public function test_a_malformed_operator_range_is_refused_loudly(string $range): void
    {
        // Silently dropping it would read as "no extra ranges configured", which is indistinguishable
        // from a working configuration — a typo in a security setting must not be a quiet hole.
        $this->expectException(OutboundException::class);
        $this->expectExceptionMessage('is not a valid CIDR range');

        new PublicIpGuard([$range]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedRanges(): array
    {
        return [
            'no prefix length' => ['10.0.0.0'],
            'not an address' => ['not-a-cidr'],
            'non-numeric prefix' => ['10.0.0.0/nope'],
            'prefix wider than the family' => ['10.0.0.0/33'],
            'IPv6 prefix wider than the family' => ['fc00::/129'],
            'two slashes' => ['10.0.0.0/8/8'],
        ];
    }

    public function test_blank_entries_are_ignored_so_an_empty_setting_is_not_an_error(): void
    {
        // The env value is comma-split, so an unset or trailing-comma setting yields empty strings.
        $guard = new PublicIpGuard(['', '  ']);

        $this->assertTrue($guard->isGlobal('93.184.216.34'));
    }

    public function test_an_ipv4_address_is_never_inside_an_ipv6_range(): void
    {
        $guard = new PublicIpGuard(['::/0']);

        $this->assertTrue($guard->isGlobal('93.184.216.34'));
    }
}
