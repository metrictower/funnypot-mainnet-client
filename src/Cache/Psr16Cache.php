<?php

namespace Funnypot\Mainnet\Cache;

/**
 * Adapter wrapping a PSR-16 Psr\SimpleCache\CacheInterface so a consumer injects WP object-cache /
 * transients, Laravel Cache, or the app's SQLite/file cache in one line. psr/simple-cache is only a
 * suggest: this class references the interface loosely (no import, untyped wrapped instance) so the
 * package installs and runs on a host without the PSR package.
 *
 * A wrapped PSR-16 backend is assumed cross-request, so this adapter is Persistent — the breaker keeps
 * its shared marker in it rather than the filemtime fallback.
 */
final class Psr16Cache implements Cache, Persistent
{
    /** @var object a Psr\SimpleCache\CacheInterface */
    private $inner;

    /**
     * @param object $psr16  a Psr\SimpleCache\CacheInterface instance
     */
    public function __construct($psr16)
    {
        $this->inner = $psr16;
    }

    public function get(string $key, $default = null)
    {
        return $this->inner->get($key, $default);
    }

    public function set(string $key, $value, int $ttlSeconds = 0)
    {
        // PSR-16: null TTL = the driver default / no explicit expiry; a positive int is seconds.
        $ttl = $ttlSeconds > 0 ? $ttlSeconds : null;

        return (bool) $this->inner->set($key, $value, $ttl);
    }

    public function has(string $key)
    {
        return (bool) $this->inner->has($key);
    }
}
