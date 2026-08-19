<?php

namespace Funnypot\Mainnet\Cache;

/**
 * The package's own tiny PSR-16-shaped cache seam. Kept internal so a bare 7.3 host is not forced to
 * install psr/simple-cache; a consumer that has one injects it through Psr16Cache. Stores two kinds of
 * key: verdict entries (mnc:v:*) and the circuit-breaker record (mnc:breaker).
 */
interface Cache
{
    /**
     * @param string $key
     * @param mixed  $default
     * @return mixed  the stored value, or $default on miss
     */
    public function get(string $key, $default = null);

    /**
     * @param string $key
     * @param mixed  $value
     * @param int    $ttlSeconds  0 = no expiry
     * @return bool
     */
    public function set(string $key, $value, int $ttlSeconds = 0);

    /**
     * @param string $key
     * @return bool
     */
    public function has(string $key);
}
