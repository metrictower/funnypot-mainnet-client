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
        $b->tripTransport();
        $this->assertTrue($b->allow());
        $this->assertSame(0, $b->tripCount());
    }

    // --- exponential backoff on consecutive opens ---------------------------------------------------

    public function test_first_trip_lasts_exactly_one_cooldown()
    {
        $clock = new FakeClock(1000000000);
        $b = $this->breaker($clock, 5, 60);
        for ($i = 0; $i < 5; $i++) {
            $b->recordTransportFailure();
        }
        $this->assertSame(1000000000 + 60, $b->openUntil(), 'a single blip behaves exactly as before');
        $this->assertSame(1, $b->tripCount());
    }

    public function test_consecutive_trips_double_up_to_the_cap()
    {
        $clock = new FakeClock(1000000000);
        $b = $this->breaker($clock, 5, 60);
        $expected = array(60, 120, 240, 480, 960, 1800, 1800);
        foreach ($expected as $n => $secs) {
            $b->tripTransport();
            $this->assertSame($clock->now() + $secs, $b->openUntil(), 'trip #' . ($n + 1) . " opens for {$secs}s");
            $this->assertSame($n + 1, $b->tripCount());
            $this->assertSame('transport', $b->reason());
        }
    }

    public function test_failed_half_open_probe_retrips_for_longer()
    {
        $clock = new FakeClock(1000000000);
        $b = $this->breaker($clock, 5, 60);
        for ($i = 0; $i < 5; $i++) {
            $b->recordTransportFailure();
        }
        $clock->advance(61);
        $this->assertTrue($b->allow(), 'the probe');
        $b->recordTransportFailure(); // the probe failed: no re-accumulation toward the threshold
        $this->assertFalse($b->allow());
        $this->assertSame(2, $b->tripCount());
        $this->assertSame($clock->now() + 120, $b->openUntil(), 'the second consecutive open is twice as long');
    }

    public function test_success_resets_the_curve()
    {
        $clock = new FakeClock(1000000000);
        $b = $this->breaker($clock, 5, 60);
        $b->tripTransport();
        $b->tripTransport();
        $b->recordSuccess();
        $this->assertSame(0, $b->tripCount());
        $this->assertTrue($b->allow());
        $b->tripTransport();
        $this->assertSame($clock->now() + 60, $b->openUntil(), 'after a success the next trip is one cooldown again');
    }

    public function test_success_on_a_clean_record_skips_the_write()
    {
        $inner = new class {
            /** @var int */
            public $sets = 0;
            /** @var array<string,mixed> */
            private $store = array();

            public function get($key, $default = null)
            {
                return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
            }

            public function set($key, $value, $ttl = null)
            {
                $this->sets++;
                $this->store[$key] = $value;

                return true;
            }

            public function has($key)
            {
                return array_key_exists($key, $this->store);
            }
        };
        $clock = new FakeClock();
        $b = new CircuitBreaker(new Psr16Cache($inner), 5, 60, 21600, $clock->asCallable(), $this->identityJitter(), $this->marker());
        $b->recordSuccess();
        $this->assertSame(0, $inner->sets, 'nothing to clear: the healthy path is read-only');
        $b->recordTransportFailure();
        $b->recordSuccess();
        $this->assertSame(2, $inner->sets, 'a dirty record is cleared with one write');
        $b->recordSuccess();
        $this->assertSame(2, $inner->sets);
    }

    public function test_default_jitter_stays_within_20_percent()
    {
        $clock = new FakeClock(1000000000);
        // No jitter override: the built-in +/-20% applies to the escalated window.
        $b = new CircuitBreaker(new ArrayCache($clock->asCallable()), 5, 60, 21600, $clock->asCallable(), null, $this->marker());
        for ($i = 0; $i < 25; $i++) {
            $b->recordSuccess();
            $b->tripTransport();
            $open = $b->openUntil() - $clock->now();
            $this->assertGreaterThanOrEqual(48, $open);
            $this->assertLessThanOrEqual(72, $open);
        }
    }

    public function test_max_backoff_is_never_below_the_cooldown()
    {
        $clock = new FakeClock(1000000000);
        // max == cooldown: the old flat cooldown, no escalation.
        $flat = new CircuitBreaker(new ArrayCache($clock->asCallable()), 5, 60, 21600, $clock->asCallable(), $this->identityJitter(), $this->marker(), 'default', 60);
        $flat->tripTransport();
        $flat->tripTransport();
        $this->assertSame($clock->now() + 60, $flat->openUntil());
        // A cap below the cooldown is clamped up, so the first trip still lasts one full cooldown.
        $low = new CircuitBreaker(new ArrayCache($clock->asCallable()), 5, 60, 21600, $clock->asCallable(), $this->identityJitter(), $this->marker(), 'default', 10);
        $low->tripTransport();
        $this->assertSame($clock->now() + 60, $low->openUntil());
    }

    public function test_quota_without_headers_parks_on_the_curve_but_a_header_still_wins()
    {
        $clock = new FakeClock(1000000000);
        $b = $this->breaker($clock, 5, 60, 21600);
        $b->recordQuota(null, null);
        $this->assertSame($clock->now() + 60, $b->openUntil());
        $b->recordQuota(null, null);
        $this->assertSame($clock->now() + 120, $b->openUntil(), 'headerless 429s escalate like transport trips');
        $this->assertSame(2, $b->tripCount());
        $b->recordQuota(300, null);
        $this->assertSame($clock->now() + 300, $b->openUntil(), 'the server reset time is authoritative when given');
        $this->assertSame(3, $b->tripCount(), 'but the open still counts toward the curve');
    }

    public function test_record_without_trip_count_reads_as_zero()
    {
        $clock = new FakeClock(1000000000);
        $marker = $this->marker();
        // A record persisted before the field existed: open, no trip_count.
        file_put_contents($marker, json_encode(array('failures' => 0, 'until' => 1000000000 + 60, 'reason' => 'transport')));
        $b = new CircuitBreaker(new ArrayCache($clock->asCallable()), 5, 60, 21600, $clock->asCallable(), $this->identityJitter(), $marker);
        $this->assertSame(0, $b->tripCount());
        $this->assertFalse($b->allow());
        $clock->advance(61);
        $this->assertTrue($b->allow());
        $b->recordTransportFailure(); // the probe fails: the legacy open counted as none, so this is trip #1
        $this->assertSame(1, $b->tripCount());
        $this->assertSame($clock->now() + 60, $b->openUntil());
    }

    // --- channels -----------------------------------------------------------------------------------

    public function test_channels_keep_separate_state_over_one_store()
    {
        $clock = new FakeClock();
        $store = new Psr16Cache(new ArrayPsr16());
        $report = new CircuitBreaker($store, 5, 60, 21600, $clock->asCallable(), $this->identityJitter(), $this->marker(), 'report');
        $check = new CircuitBreaker($store, 5, 60, 21600, $clock->asCallable(), $this->identityJitter(), $this->marker(), 'check');
        $report->tripTransport();
        $report->tripTransport();
        $this->assertFalse($report->allow());
        $this->assertTrue($check->allow(), 'a report outage never opens the check breaker');
        $this->assertSame('', $check->reason());
        $this->assertSame(0, $check->tripCount());
    }

    public function test_default_channel_keeps_the_original_key_and_marker()
    {
        $inner = new ArrayPsr16();
        $clock = new FakeClock();
        $b = new CircuitBreaker(new Psr16Cache($inner), 5, 60, 21600, $clock->asCallable(), $this->identityJitter(), $this->marker());
        $b->tripTransport();
        $this->assertTrue($inner->has(CircuitBreaker::CACHE_KEY), 'a hand-built single breaker still lives at mnc:breaker');
        $named = new CircuitBreaker(new Psr16Cache($inner), 5, 60, 21600, $clock->asCallable(), $this->identityJitter(), $this->marker(), 'report');
        $named->tripTransport();
        $this->assertTrue($inner->has(CircuitBreaker::CACHE_KEY . ':report'));
        $this->assertStringEndsWith(DIRECTORY_SEPARATOR . 'mnc_breaker.json', CircuitBreaker::defaultMarkerFile('default'));
        $this->assertStringEndsWith(DIRECTORY_SEPARATOR . 'mnc_breaker_report.json', CircuitBreaker::defaultMarkerFile('report'));
        $this->assertStringEndsWith(DIRECTORY_SEPARATOR . 'mnc_breaker_a_b.json', CircuitBreaker::defaultMarkerFile('a/b'), 'the channel is sanitised before it lands in a path');
    }

    public function test_channel_marker_files_cross_requests_independently()
    {
        $clock = new FakeClock();
        $channel = 'test_' . getmypid() . '_' . mt_rand(1000, 9999);
        $marker = CircuitBreaker::defaultMarkerFile($channel);
        $this->markers[] = $marker;
        $a = new CircuitBreaker(new ArrayCache($clock->asCallable()), 5, 60, 21600, $clock->asCallable(), $this->identityJitter(), null, $channel);
        $a->tripTransport();
        $this->assertFileExists($marker);
        $b = new CircuitBreaker(new ArrayCache($clock->asCallable()), 5, 60, 21600, $clock->asCallable(), $this->identityJitter(), null, $channel);
        $this->assertFalse($b->allow(), 'a fresh request on the same channel sees the outage through its own marker');
        $other = new CircuitBreaker(new ArrayCache($clock->asCallable()), 5, 60, 21600, $clock->asCallable(), $this->identityJitter(), $this->marker(), 'other');
        $this->assertTrue($other->allow());
    }
}
