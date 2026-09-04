<?php

namespace Funnypot\Mainnet\Cache;

/**
 * Marker for a cache whose state survives across requests/processes (a WP object cache, Laravel Cache,
 * APCu, an SQLite/file store). The circuit breaker keeps its shared marker in a Persistent cache; against a
 * per-process cache (ArrayCache/NullCache, which do NOT mark themselves Persistent) it falls back to a
 * filemtime marker so outage state still crosses requests (decision N1).
 */
interface Persistent
{
}
