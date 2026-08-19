<?php

namespace Funnypot\Mainnet\Tests;

use Funnypot\Mainnet\Cache\ArrayCache;
use Funnypot\Mainnet\Cache\Psr16Cache;
use Funnypot\Mainnet\CircuitBreaker;
use Funnypot\Mainnet\Tests\Support\ArrayPsr16;
use Funnypot\Mainnet\Tests\Support\FakeClock;
use Funnypot\Mainnet\Tests\Support\ThrowingCache;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerTest extends TestCase
{
    /** @var array<int,string> marker files to clean up */
    private $markers = array();

    protected function tearDown(): void
    {
        foreach ($this->markers as $m) {
            if (is_file($m)) {
                @unlink($m);
            }
        }
        $this->markers = array();
    }

    private function marker()
    {
        $m = tempnam(sys_get_temp_dir(), 'mnc_test_');
        // Start from a clean (absent) marker so a fresh breaker reads CLOSED.
        @unlink($m);
        $this->markers[] = $m;

        return $m;
    }

    private function identityJitter()
    {
        return function ($n) {
            return $n;
        };
    }

    private function breaker(FakeClock $clock, $threshold = 5, $cooldown = 60, $quotaCap = 21600)
    {
        return new CircuitBreaker(new ArrayCache($clock->asCallable()), $threshold, $cooldown, $quotaCap, $clock->asCallable(), $this->identityJitter(), $this->marker());
    }

    public function test_closed_allows()
    {
        $b = $this->breaker(new FakeClock());
        $this->assertTrue($b->allow());
        $this->assertSame('', $b->reason());
    }

    public function test_trips_after_transport_threshold()
    {
        $clock = new FakeClock();
        $b = $this->breaker($clock, 5, 60);
        for ($i = 0; $i < 4; $i++) {
            $b->recordTransportFailure();
            $this->assertTrue($b->allow(), "still closed below threshold ($i)");
        }
        $b->recordTransportFailure(); // 5th -> trips
        $this->assertFalse($b->allow());
        $this->assertSame('transport', $b->reason());
    }

    public function test_success_resets_failures()
    {
        $clock = new FakeClock();
        $b = $this->breaker($clock, 5, 60);
        $b->recordTransportFailure();
        $b->recordTransportFailure();
        $b->recordSuccess();
        $this->assertTrue($b->allow());
        // After a reset, it takes the full threshold again to trip.
        for ($i = 0; $i < 4; $i++) {
            $b->recordTransportFailure();
        }
        $this->assertTrue($b->allow(), 'four failures after a reset must not trip');
    }

    public function test_transport_reopens_after_60s_half_open_single_flight()
    {
        $clock = new FakeClock();
        $b = $this->breaker($clock, 5, 60);
        for ($i = 0; $i < 5; $i++) {
            $b->recordTransportFailure();
        }
        $this->assertFalse($b->allow(), 'open right after tripping');
        $clock->advance(61); // past the 60s cooldown
        // Two concurrent callers: exactly one probes.
        $first = $b->allow();
        $second = $b->allow();
        $this->assertTrue($first xor $second, 'exactly one caller probes at half-open');
        $this->assertTrue($first);
        $this->assertFalse($second);
    }

    public function test_quota_parks_to_reset_not_cooldown()
    {
        $clock = new FakeClock(1000000000);
        $b = $this->breaker($clock, 5, 60, 21600);
        // retry-after delta 300s, x-ratelimit-reset absolute now+1800 -> parks to now+1800 (the later).
        $b->recordQuota(300, 1000000000 + 1800);
        $this->assertSame('quota', $b->reason());
        $this->assertSame(1000000000 + 1800, $b->openUntil(), 'parks to the reset, not the 60s cooldown');

        $clock->advance(1799); // just before reset
        $this->assertFalse($b->allow());
        $clock->advance(2); // just past reset
        $this->assertTrue($b->allow());
    }

    public function test_quota_park_clamped_to_cap()
    {
        $clock = new FakeClock(1000000000);
        $b = $this->breaker($clock, 5, 60, 21600);
        // An absurd reset far beyond the 6h cap must clamp to now + 21600.
        $b->recordQuota(999999999, 1000000000 + 999999999);
        $this->assertSame(1000000000 + 21600, $b->openUntil());
    }

    public function test_two_instances_share_state_over_one_store()
    {
        $clock = new FakeClock();
        $store = new Psr16Cache(new ArrayPsr16()); // Persistent -> the breaker keeps the marker here
        $marker = $this->marker();
        $a = new CircuitBreaker($store, 5, 60, 21600, $clock->asCallable(), $this->identityJitter(), $marker);
        $b = new CircuitBreaker($store, 5, 60, 21600, $clock->asCallable(), $this->identityJitter(), $marker);
        for ($i = 0; $i < 5; $i++) {
            $a->recordTransportFailure();
        }
        $this->assertFalse($b->allow(), 'a trip recorded by A fast-fails B over the shared store');
    }

    public function test_filemtime_fallback_crosses_requests_without_shared_cache()
    {
        $clock = new FakeClock();
        $marker = $this->marker();
        // Per-process cache (ArrayCache) -> state must ride the filemtime marker across instances.
        $a = new CircuitBreaker(new ArrayCache($clock->asCallable()), 5, 60, 21600, $clock->asCallable(), $this->identityJitter(), $marker);
        for ($i = 0; $i < 5; $i++) {
            $a->recordTransportFailure();
        }
        $this->assertFalse($a->allow());
        // A fresh instance (a new "request") with its own empty ArrayCache still sees OPEN via the marker.
        $b = new CircuitBreaker(new ArrayCache($clock->asCallable()), 5, 60, 21600, $clock->asCallable(), $this->identityJitter(), $marker);
        $this->assertFalse($b->allow(), 'the temp-dir marker carries outage state across requests');
    }

    public function test_fail_open_when_store_unreadable()
    {
        $b = new CircuitBreaker(new ThrowingCache(), 5, 60, 21600, (new FakeClock())->asCallable(), $this->identityJitter());
        $this->assertTrue($b->allow(), 'a throwing store degrades to allow, never propagates');
        // Writes are swallowed too.
        $b->recordTransportFailure();
        $b->recordQuota(60, null);
        $b->recordSuccess();
        $this->assertTrue($b->allow());
    }
}
