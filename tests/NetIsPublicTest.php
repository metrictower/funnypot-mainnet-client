<?php

namespace Funnypot\Mainnet\Tests;

use Funnypot\Mainnet\Net;
use PHPUnit\Framework\TestCase;

/**
 * Net::isPublic is the reporter's public-IP guard — a private/reserved subject IP must never be queued
 * or reported (FP-0116: "stops junk reports, saves bandwidth"). filter_var's NO_PRIV/NO_RES flags cover
 * RFC1918, loopback, link-local and IPv6 ULA, but MISS carrier-grade NAT (RFC6598 100.64.0.0/10), which
 * is exactly the internal-but-not-RFC1918 space a mis-configured sensor would leak.
 *
 * Documentation ranges (192.0.2/24, 198.51.100/24, 203.0.113/24, 2001:db8::/32) are deliberately treated
 * as public — the SDK's own fixtures use 203.0.113.x as a stand-in for a real public address.
 */
final class NetIsPublicTest extends TestCase
{
    public function private_and_reserved_ranges()
    {
        return array(
            'RFC1918 10/8'      => array('10.0.0.1'),
            'RFC1918 172.16/12' => array('172.16.5.5'),
            'RFC1918 192.168/16' => array('192.168.1.1'),
            'loopback v4'       => array('127.0.0.1'),
            'loopback v6'       => array('::1'),
            'link-local v4'     => array('169.254.1.1'),
            'link-local v6'     => array('fe80::1'),
            'CGNAT low'         => array('100.64.0.1'),
            'CGNAT high'        => array('100.127.255.255'),
            'ULA fc00'          => array('fc00::1'),
            'ULA fd'            => array('fd12::1'),
        );
    }

    /** @dataProvider private_and_reserved_ranges */
    public function test_private_and_reserved_are_not_public($ip)
    {
        $this->assertFalse(Net::isPublic($ip), $ip . ' must not be reportable');
    }

    public function public_addresses()
    {
        return array(
            'google dns'        => array('8.8.8.8'),
            'cloudflare dns'    => array('1.1.1.1'),
            'public v6'         => array('2606:4700::1111'),
            'doc fixture'       => array('203.0.113.9'),   // the SDK's stand-in public IP
            'just below CGNAT'  => array('100.63.255.255'), // 100.64/10 boundary, low side
            'just above CGNAT'  => array('100.128.0.0'),    // 100.64/10 boundary, high side
        );
    }

    /** @dataProvider public_addresses */
    public function test_public_addresses_stay_public($ip)
    {
        $this->assertTrue(Net::isPublic($ip), $ip . ' is public and must remain reportable');
    }
}
