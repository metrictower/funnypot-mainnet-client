<?php

namespace Funnypot\Mainnet\Tests\Cache;

use Funnypot\Mainnet\Cache\ArrayCache;
use Funnypot\Mainnet\Cache\NullCache;
use Funnypot\Mainnet\Cache\Psr16Cache;
use Funnypot\Mainnet\Tests\Support\ArrayPsr16;
use Funnypot\Mainnet\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    public function test_array_cache_roundtrip_and_miss()
    {
        $c = new ArrayCache();
        $this->assertFalse($c->has('k'));
        $this->assertSame('fallback', $c->get('k', 'fallback'));
        $c->set('k', array('v' => 1));
        $this->assertTrue($c->has('k'));
        $this->assertSame(array('v' => 1), $c->get('k'));
    }

    public function test_array_cache_ttl_expires()
    {
        $clock = new FakeClock();
        $c = new ArrayCache($clock->asCallable());
        $c->set('k', 'x', 10);
        $this->assertTrue($c->has('k'));
        $clock->advance(11);
        $this->assertFalse($c->has('k'));
        $this->assertSame('miss', $c->get('k', 'miss'));
    }

    public function test_null_cache_always_misses()
    {
        $c = new NullCache();
        $c->set('k', 'x');
        $this->assertSame('def', $c->get('k', 'def'));
        $this->assertFalse($c->has('k'));
    }

    public function test_psr16_adapter_delegates()
    {
        $inner = new ArrayPsr16();
        $c = new Psr16Cache($inner);
        $this->assertFalse($c->has('k'));
        $c->set('k', 'value', 60);
        $this->assertTrue($c->has('k'));
        $this->assertSame('value', $c->get('k'));
        // Proxied through to the wrapped instance.
        $this->assertSame('value', $inner->get('k'));
    }
}
