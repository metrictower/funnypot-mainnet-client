<?php

namespace Funnypot\Mainnet\Tests\Cache;

use Funnypot\Mainnet\Cache\ApcuCache;
use Funnypot\Mainnet\Cache\Persistent;
use Funnypot\Mainnet\CircuitBreaker;
use Funnypot\Mainnet\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

/**
 * APCu is rarely enabled for the CLI that runs this suite, so most of these pin the inert-SAPI contract
 * (never fatal, never claim a hit); the round-trip cases run only where APCu is live.
 */
final class ApcuCacheTest extends TestCase
{
    public function test_is_persistent_so_the_breaker_keeps_its_record_in_it()
    {
        $this->assertInstanceOf(Persistent::class, new ApcuCache());
    }

    public function test_is_usable_requires_the_extension_enabled_for_this_sapi()
    {
        $live = extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled();
        $this->assertSame($live, ApcuCache::isUsable());
    }

    public function test_inert_apcu_misses_and_refuses_writes_without_fatals()
    {
        if (ApcuCache::isUsable()) {
            $this->markTestSkipped('APCu is live in this SAPI; the inert contract cannot be exercised here.');
        }
        $c = new ApcuCache();
        $this->assertFalse($c->set('mnc:test', 'v'));
        $this->assertSame('miss', $c->get('mnc:test', 'miss'));
        $this->assertFalse($c->has('mnc:test'));
    }

    public function test_roundtrip_when_live()
    {
        if (!ApcuCache::isUsable()) {
            $this->markTestSkipped('APCu is not enabled for this SAPI.');
        }
        $c = new ApcuCache();
        $key = 'mnc:test:' . getmypid();
        $this->assertFalse($c->has($key));
        $this->assertTrue($c->set($key, array('v' => 1), 60));
        $this->assertTrue($c->has($key));
        $this->assertSame(array('v' => 1), $c->get($key));
        apcu_delete($key);
        $this->assertSame('miss', $c->get($key, 'miss'));
    }

    public function test_two_breakers_share_state_over_apcu_when_live()
    {
        if (!ApcuCache::isUsable()) {
            $this->markTestSkipped('APCu is not enabled for this SAPI.');
        }
        $clock = new FakeClock();
        $channel = 'apcu_test_' . getmypid();
        $identity = function ($n) {
            return $n;
        };
        $a = new CircuitBreaker(new ApcuCache(), 5, 60, 21600, $clock->asCallable(), $identity, null, $channel);
        $b = new CircuitBreaker(new ApcuCache(), 5, 60, 21600, $clock->asCallable(), $identity, null, $channel);
        $a->tripTransport();
        $this->assertFalse($b->allow(), 'a trip recorded by A fast-fails B through shared memory');
        apcu_delete(CircuitBreaker::CACHE_KEY . ':' . $channel);
    }
}
