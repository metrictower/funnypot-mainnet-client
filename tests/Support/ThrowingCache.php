<?php

namespace Funnypot\Mainnet\Tests\Support;

use Funnypot\Mainnet\Cache\Cache;
use Funnypot\Mainnet\Cache\Persistent;
use RuntimeException;

/**
 * A Persistent cache whose reads/writes throw. Persistent so the breaker routes state through it (rather
 * than the filemtime fallback), exercising the "breaker never breaks" fail-open path (N5).
 */
final class ThrowingCache implements Cache, Persistent
{
    public function get(string $key, $default = null)
    {
        throw new RuntimeException('store unreadable');
    }

    public function set(string $key, $value, int $ttlSeconds = 0)
    {
        throw new RuntimeException('store unwritable');
    }

    public function has(string $key)
    {
        throw new RuntimeException('store unreadable');
    }
}
