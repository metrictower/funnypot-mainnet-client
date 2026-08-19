<?php

namespace Funnypot\Mainnet\Cache;

/**
 * In-process map honouring TTL against an injectable clock (so tests advance time instead of sleeping).
 * A valid single-process store and the test double. Per-process only: it does NOT survive a request, so
 * with it the cross-request verdict cache and the breaker's cache path are inert (the breaker falls back
 * to its filemtime marker). It is deliberately NOT Persistent.
 */
final class ArrayCache implements Cache
{
    /** @var array<string,array{value:mixed,expires:int}> */
    private $store = array();
    /** @var callable():int */
    private $clock;

    /**
     * @param callable|null $clock  callable returning the current epoch seconds; defaults to time()
     */
    public function __construct($clock = null)
    {
        $this->clock = $clock !== null ? $clock : 'time';
    }

    public function get(string $key, $default = null)
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->store[$key]['value'];
    }

    public function set(string $key, $value, int $ttlSeconds = 0)
    {
        $expires = $ttlSeconds > 0 ? $this->now() + $ttlSeconds : 0;
        $this->store[$key] = array('value' => $value, 'expires' => $expires);

        return true;
    }

    public function has(string $key)
    {
        if (!array_key_exists($key, $this->store)) {
            return false;
        }
        $entry = $this->store[$key];
        if ($entry['expires'] !== 0 && $entry['expires'] <= $this->now()) {
            unset($this->store[$key]);

            return false;
        }

        return true;
    }

    private function now()
    {
        return (int) call_user_func($this->clock);
    }

    /** @return string[] the currently stored keys (observability / tests). */
    public function keys()
    {
        return array_keys($this->store);
    }
}
