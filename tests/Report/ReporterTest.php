<?php

namespace Funnypot\Mainnet\Tests\Report;

use Funnypot\Mainnet\Cache\Psr16Cache;
use Funnypot\Mainnet\CircuitBreaker;
use Funnypot\Mainnet\Report\Reporter;
use Funnypot\Mainnet\Tests\Support\ArrayPsr16;
use Funnypot\Mainnet\Tests\Support\FakeClock;
use Funnypot\Mainnet\Tests\Support\FakeTransport;
use Funnypot\Mainnet\Tests\Support\InMemoryReportQueue;
use PHPUnit\Framework\TestCase;

final class ReporterTest extends TestCase
{
    const BASE = 'https://mainnet.example';
    const KEY = 'report-key';

    /** @var FakeClock */
    private $clock;
    /** @var FakeTransport */
    private $transport;
    /** @var InMemoryReportQueue */
    private $queue;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(1000000000);
        $this->transport = new FakeTransport();
        $this->queue = new InMemoryReportQueue(10000, $this->clock->asCallable());
    }

    private function breaker()
    {
        return new CircuitBreaker(new Psr16Cache(new ArrayPsr16()), 5, 60, 21600, $this->clock->asCallable(), function ($n) {
            return $n;
        });
    }

    private function reporter($key = self::KEY, array $selfIps = array('203.0.113.99'), $dailyCap = 1000, $dedupHours = 24, $breaker = null, $queue = null)
    {
        return new Reporter(
            $queue !== null ? $queue : $this->queue,
            $this->transport,
            self::BASE,
            $key,
            $selfIps,
            $dailyCap,
            $dedupHours,
            $breaker !== null ? $breaker : $this->breaker(),
            $this->clock->asCallable()
        );
    }

    private function bodyOf($call)
    {
        parse_str($call['body'], $out);

        return $out;
    }

    // --- enqueue guard ladder (B-verbatim) --------------------------------------------------------

    public function test_enqueues_successfully_without_self_ips()
    {
        $r = $this->reporter(self::KEY, array());
        $res = $r->enqueue('198.51.100.9', 'hi');
        $this->assertTrue($res['queued']);
        $this->assertSame(1, $this->queue->count());
    }

    public function test_no_key()
    {
        $r = $this->reporter('');
        $res = $r->enqueue('198.51.100.9', 'hi');
        $this->assertSame('no api key', $res['reason']);
    }

    public function test_never_enqueues_self()
    {
        $r = $this->reporter(self::KEY, array('198.51.100.9'));
        $res = $r->enqueue('198.51.100.9', 'hi');
        $this->assertSame('self', $res['reason']);
    }

    public function test_skips_private_and_invalid()
    {
        $r = $this->reporter();
        foreach (array('192.168.1.5', '10.0.0.1', '127.0.0.1', 'not-an-ip') as $ip) {
            $res = $r->enqueue($ip, 'hi');
            $this->assertFalse($res['queued'], $ip);
            $this->assertSame('not a public ip', $res['reason'], $ip);
        }
    }

    public function test_dedup_one_report_per_window()
    {
        $r = $this->reporter();
        $this->assertTrue($r->enqueue('198.51.100.9', 'a')['queued']);
        $this->assertSame('deduped', $r->enqueue('198.51.100.9', 'b')['reason']);
        $this->assertSame('deduped', $r->enqueue('198.51.100.9', 'c')['reason']);
        $this->assertSame(1, $this->queue->count());
    }

    public function test_daily_cap_blocks_enqueue()
    {
        $this->queue->bumpDaily();
        $this->queue->bumpDaily();
        $r = $this->reporter(self::KEY, array('203.0.113.99'), 2);
        $this->assertSame('daily cap', $r->enqueue('198.51.100.9', 'x')['reason']);
    }

    public function test_enqueue_queues_row()
    {
        $r = $this->reporter();
        $res = $r->enqueue('198.51.100.9', 'brute force', '18,22');
        $this->assertTrue($res['queued']);
        $this->assertSame(1, $this->queue->count());
        $this->assertSame(0, $this->transport->callCount(), 'nothing posted at enqueue');
        $row = $this->queue->rows()[0];
        $this->assertSame('198.51.100.9', $row['ip']);
        $this->assertSame('18,22', $row['categories']);
        $this->assertArrayNotHasKey('signals', $row);
    }

    public function test_enqueue_ipv6_dedups_by_64()
    {
        $r = $this->reporter();
        // Two distinct /128s inside 2001:db8::/64 dedup as one entity.
        $this->assertTrue($r->enqueue('2001:db8::1', 'a')['queued']);
        $this->assertSame('deduped', $r->enqueue('2001:db8::2', 'b')['reason']);
        // A /128 in a DIFFERENT /64 enqueues.
        $this->assertTrue($r->enqueue('2001:db8:0:1::1', 'c')['queued']);
        // The posted body keeps the full /128 (not the /64).
        $rows = $this->queue->rows();
        $this->assertSame('2001:db8::1', $rows[0]['ip']);
        $this->assertSame('2001:db8:0:1::1', $rows[1]['ip']);
        // IPv4 dedup unchanged.
        $this->assertTrue($r->enqueue('192.0.2.5', 'd')['queued']);
        $this->assertSame('deduped', $r->enqueue('192.0.2.5', 'e')['reason']);
    }

    public function test_enqueue_then_drain_posts_parity_body()
    {
        $r = $this->reporter();
        $r->enqueue('198.51.100.9', 'brute force', '18,22');
        $this->transport->setDefault(200, '{"data":{"ok":true}}');
        $res = $r->drain();
        $this->assertSame(1, $res['sent']);
        $this->assertSame(0, $this->queue->count());
        $call = $this->transport->lastCall();
        $this->assertSame(self::BASE . '/v1/report', $call['url']);
        $body = $this->bodyOf($call);
        $this->assertSame('198.51.100.9', $body['ip']);
        $this->assertSame('18,22', $body['categories']);
        $this->assertSame('brute force', $body['comment']);
        $this->assertSame($this->queue->sensorId(), $body['sensor_id']);
        $this->assertArrayHasKey('timestamp', $body);
        $this->assertArrayNotHasKey('signals', $body, 'a plain enqueue posts no signals field');
    }

    public function test_enqueue_persists_signals_and_drain_posts_them()
    {
        $signals = array('ua_class' => 'script', 'missing_accept_language' => true);
        $r = $this->reporter();
        $r->enqueue('198.51.100.9', 'bad bot', 'bad_bot', $signals);
        $row = $this->queue->rows()[0];
        $this->assertSame(json_encode($signals), $row['signals'], 'signals persisted verbatim on the row');

        $this->transport->setDefault(200, '{"data":{"ok":true}}');
        $r->drain();
        $body = $this->bodyOf($this->transport->lastCall());
        $this->assertSame(json_encode($signals), $body['signals'], 'signals posted verbatim in the drain body');
        $this->assertSame('bad_bot', $body['categories']);
    }

    public function test_daily_cap_stops_the_drain()
    {
        $r = $this->reporter(self::KEY, array('203.0.113.99'), 2);
        $r->enqueue('198.51.100.1', 'a');
        $r->enqueue('198.51.100.2', 'b');
        $r->enqueue('198.51.100.3', 'c');
        $this->transport->setDefault(200, '{"data":{"ok":true}}');
        $res = $r->drain();
        $this->assertSame(2, $res['sent']);
        $this->assertSame(1, $res['pending']);
        $this->assertSame(2, $this->transport->callCount());
    }

    public function test_drain_drops_4xx()
    {
        $r = $this->reporter();
        $r->enqueue('198.51.100.9', 'x');
        $this->transport->setDefault(422, '{"error":{"code":"invalid_report"}}');
        $res = $r->drain();
        $this->assertSame(0, $res['sent']);
        $this->assertSame(1, $res['failed']);
        $this->assertSame(0, $this->queue->count(), 'a permanent 4xx drops the row');
    }

    public function test_drain_retries_5xx_then_drops()
    {
        $r = $this->reporter();
        $r->enqueue('198.51.100.9', 'x');
        $this->transport->setDefault(500, 'down');
        $r->drain(); // attempt 1
        $this->assertSame(1, $this->queue->count());
        $r->drain(); // attempt 2
        $this->assertSame(1, $this->queue->count());
        $r->drain(); // attempt 3 -> dropped
        $this->assertSame(0, $this->queue->count());
    }

    public function test_categories_for_protocol()
    {
        $this->assertSame('18,22', Reporter::categoriesForProtocol('ssh'));
        $this->assertSame('18,23', Reporter::categoriesForProtocol('telnet'));
        $this->assertSame('18', Reporter::categoriesForProtocol('ftp'));
        $this->assertSame('18', Reporter::categoriesForProtocol('imap'));
        $this->assertSame('14,15', Reporter::categoriesForProtocol('http'));
    }

    // --- drain SF-7 / N6 deltas -------------------------------------------------------------------

    public function test_drain_dedup_429_drops_no_breaker_no_loop()
    {
        $breaker = $this->breaker();
        $r = $this->reporter(self::KEY, array('203.0.113.99'), 1000, 24, $breaker);
        $r->enqueue('198.51.100.9', 'x');
        $this->transport->setDefault(429, '{"error":{"code":"duplicate_report"}}');
        $res = $r->drain();
        $this->assertSame(0, $this->queue->count(), 'a duplicate 429 drops the row');
        $this->assertSame(1, $res['failed']);
        $this->assertTrue($breaker->allow(), 'duplicate 429 is not a fault: the breaker is untouched');
        $this->assertSame('', $breaker->reason());
    }

    public function test_drain_quota_429_parks_breaker_and_stops()
    {
        $breaker = $this->breaker();
        $r = $this->reporter(self::KEY, array('203.0.113.99'), 1000, 24, $breaker);
        $r->enqueue('198.51.100.1', 'a');
        $r->enqueue('198.51.100.2', 'b');
        $reset = $this->clock->now() + 1800;
        $this->transport->setDefault(429, '{"error":{"code":"quota_exhausted"}}', array('retry-after' => '300', 'x-ratelimit-reset' => (string) $reset));
        $res = $r->drain();
        $this->assertGreaterThanOrEqual(1, $this->queue->count(), 'the quota row stays queued');
        $this->assertSame('quota', $breaker->reason());
        $this->assertSame($reset, $breaker->openUntil());
        $this->assertSame(1, $this->transport->callCount(), 'the tick stops at the first quota 429');
    }

    public function test_drain_skips_tick_while_breaker_open()
    {
        $breaker = $this->breaker();
        $breaker->tripTransport(); // force OPEN
        $r = $this->reporter(self::KEY, array('203.0.113.99'), 1000, 24, $breaker);
        $r->enqueue('198.51.100.9', 'x');
        $res = $r->drain();
        $this->assertSame(0, $this->transport->callCount(), 'breaker open -> zero POSTs');
        $this->assertSame(1, $res['pending']);
    }

    public function test_drain_aborts_after_3_transport_failures_within_budget()
    {
        $breaker = $this->breaker();
        $r = $this->reporter(self::KEY, array('203.0.113.99'), 1000, 24, $breaker);
        foreach (array('198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4', '198.51.100.5') as $ip) {
            $r->enqueue($ip, 'x');
        }
        $this->transport->setDefault(0, ''); // total outage
        $r->drain();
        $this->assertSame(3, $this->transport->callCount(), 'aborts after 3 consecutive transport failures');
        $this->assertSame('transport', $breaker->reason());
        $this->assertFalse($breaker->allow(), 'the shared marker is written OPEN');
    }

    public function test_drain_budget_stops_tick()
    {
        $breaker = $this->breaker();
        $r = $this->reporter(self::KEY, array('203.0.113.99'), 100000, 24, $breaker);
        foreach (array('198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4', '198.51.100.5') as $ip) {
            $r->enqueue($ip, 'x');
        }
        $clock = $this->clock;
        // Each POST advances the clock 4s; the 10s budget is spent after 3 posts.
        $this->transport->setOnCall(function () use ($clock) {
            $clock->advance(4);
        });
        $this->transport->setDefault(200, '{"data":{"ok":true}}');
        $res = $r->drain();
        $this->assertSame(3, $res['sent'], 'the tick stops once the 10s budget is spent');
        $this->assertSame(2, $res['pending']);
    }

    public function test_drain_requeue_is_bounded()
    {
        // Age cap: a stale row is dropped before a POST is spent.
        $this->queue->push(array('ip' => '198.51.100.9', 'categories' => '21', 'comment' => 'old', 'created_at' => gmdate('c', $this->clock->now() - 8 * 86400), 'attempts' => 0));
        $r = $this->reporter();
        $this->transport->setDefault(200, '{"data":{"ok":true}}');
        $res = $r->drain();
        $this->assertSame(0, $res['sent']);
        $this->assertSame(1, $res['failed']);
        $this->assertSame(0, $this->transport->callCount(), 'the stale row is dropped without a POST');

        // Hard queue size cap: pushing beyond the cap drops the oldest.
        $capped = new InMemoryReportQueue(3, $this->clock->asCallable());
        $r2 = $this->reporter(self::KEY, array('203.0.113.99'), 1000, 24, $this->breaker(), $capped);
        foreach (array('198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4') as $ip) {
            $r2->enqueue($ip, 'x');
        }
        $this->assertSame(3, $capped->count(), 'the queue never grows past its cap');
        $this->assertSame('198.51.100.2', $capped->rows()[0]['ip'], 'the oldest row was dropped');
    }
}
