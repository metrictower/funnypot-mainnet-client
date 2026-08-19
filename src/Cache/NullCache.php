<?php

namespace Funnypot\Mainnet\Cache;

/**
 * No-op cache: get() always misses, so every check is fresh (no caching). The package's injected default
 * when a consumer passes no cache. Per-process/non-persistent, so the breaker uses its filemtime marker.
 */
final class NullCache implements Cache
{
    public function get(string $key, $default = null)
    {
        return $default;
    }

    public function set(string $key, $value, int $ttlSeconds = 0)
    {
        return true;
    }

    public function has(string $key)
    {
        return false;
    }
}
