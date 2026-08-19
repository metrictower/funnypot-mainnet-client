<?php

namespace Funnypot\Mainnet\Tests\Support;

/**
 * Tiny in-memory PSR-16-shaped cache so the Psr16Cache adapter test does not require the real
 * psr/simple-cache package (it stays a suggest, decision F). Implements only the three methods the
 * adapter proxies. Deliberately not typed against the PSR interface so it loads without the package.
 */
final class ArrayPsr16
{
    /** @var array<string,mixed> */
    private $store = array();

    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
    }

    public function set($key, $value, $ttl = null)
    {
        $this->store[$key] = $value;

        return true;
    }

    public function has($key)
    {
        return array_key_exists($key, $this->store);
    }

    public function delete($key)
    {
        unset($this->store[$key]);

        return true;
    }
}
