<?php

namespace Funnypot\Mainnet;

/**
 * Internal IP helpers: /64 score-key normalisation (so the client's notion of "the same entity" agrees
 * with the server's /64 aggregation for IPv6, G2), CIDR-containment matching for the local mirror (Q2),
 * and the public-IP guard for the reporter. Pure static functions, 7.3-clean.
 */
final class Net
{
    /**
     * The score_key A1 aggregates on: the /64 network for IPv6, the address itself for IPv4. An invalid
     * input is returned unchanged (it simply won't match anything).
     *
     * @param string $ip
     * @return string
     */
    public static function scoreKey(string $ip)
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return $ip;
        }
        if (strlen($bin) === 16) {
            $net = substr($bin, 0, 8) . str_repeat("\0", 8);
            $out = @inet_ntop($net);

            return $out === false ? $ip : $out;
        }

        return $ip; // IPv4 unchanged
    }

    /**
     * If $cidr contains $ip (same address family), return the prefix length (a bare IP is treated as a
     * full-length /32 or /128); otherwise -1. Used to pick the most-specific mirror entry (longest
     * prefix wins, so an exact IP overrides a containing range).
     *
     * @param string $cidr  e.g. '203.0.113.0/24', '2001:db8::/64', or a bare '203.0.113.7'
     * @param string $ip
     * @return int  prefix length on containment, else -1
     */
    public static function containment(string $cidr, string $ip)
    {
        if (strpos($cidr, '/') !== false) {
            $parts = explode('/', $cidr, 2);
            $net = $parts[0];
            $bits = (int) $parts[1];
        } else {
            $net = $cidr;
            $bits = null;
        }
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($net);
        if ($ipBin === false || $netBin === false) {
            return -1;
        }
        if (strlen($ipBin) !== strlen($netBin)) {
            return -1; // different family
        }
        $maxBits = strlen($ipBin) * 8;
        if ($bits === null) {
            $bits = $maxBits;
        }
        if ($bits < 0 || $bits > $maxBits) {
            return -1;
        }
        $fullBytes = intdiv($bits, 8);
        $remBits = $bits % 8;
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return -1;
        }
        if ($remBits > 0) {
            $mask = 0xff << (8 - $remBits) & 0xff;
            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($netBin[$fullBytes]) & $mask)) {
                return -1;
            }
        }

        return $bits;
    }

    /** Public, routable IP only (the reporter's public-IP guard). */
    public static function isPublic(string $ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // filter_var's flags miss carrier-grade NAT (RFC6598, 100.64.0.0/10) — internal space that is
        // not routable on the public internet, so a report against it is junk.
        return self::containment('100.64.0.0/10', $ip) < 0;
    }
}
