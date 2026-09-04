<?php

namespace Funnypot\Mainnet\Tests\Report;

use Funnypot\Mainnet\Cache\Psr16Cache;
use Funnypot\Mainnet\CircuitBreaker;
use Funnypot\Mainnet\Report\ReportSender;
use Funnypot\Mainnet\Tests\Support\ArrayPsr16;
use Funnypot\Mainnet\Tests\Support\FakeClock;
use Funnypot\Mainnet\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * ReportSender is Reporter::drain()'s per-row POST + wire classification, extracted (FP-0060) so the
 * funnypot-laravel delivery paths share the identical 2xx / 429-split / 4xx / 5xx handling without a
 * ReportQueue. These pin the outcome contract; ReporterTest's own drain() suite proves the extraction
 * left drain() behaviour unchanged.
 */
final class ReportSenderTest extends TestCase
{
    const BASE = 'https://mainnet.example';
    const KEY = 'report-key';

    /** @var FakeClock */
    private $clock;
    /** @var FakeTransport */
    private $transport;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(1000000000);
        $this->transport = new FakeTransport();
    }

    private function breaker()
    {
        return new CircuitBreaker(new Psr16Cache(new ArrayPsr16()), 5, 60, 21600, $this->clock->asCallable(), function ($n) {
            return $n;
        });
    }

    private function sender($breaker = null)
    {
        return new ReportSender($this->transport, self::BASE, self::KEY, $breaker, $this->clock->asCallable());
    }

    private function row()
    {
        return array('ip' => '203.0.113.9', 'categories' => '21', 'comment' => 'x');
    }

    public function test_2xx_is_delivered_and_droppable()
    {
        $this->transport->setDefault(200, '');
        $out = $this->sender()->send($this->row(), 'sensor-1');

        $this->assertTrue($out['delivered']);
        $this->assertSame('delivered', $out['status']);
        $this->assertTrue($out['drop']);
    }

    public function test_it_posts_to_v1_report_with_the_key_header_and_form_body()
    {
        $this->transport->setDefault(200, '');
        $this->sender()->send($this->row(), 'sensor-1');

        $call = $this->transport->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame(self::BASE . '/v1/report', $call['url']);
        $this->assertContains('Key: ' . self::KEY, $call['headers']);
        parse_str($call['body'], $fields);
        $this->assertSame('203.0.113.9', $fields['ip']);
        $this->assertSame('sensor-1', $fields['sensor_id']);
    }

    public function test_duplicate_429_drops_and_does_not_touch_the_breaker()
    {
        $breaker = $this->breaker();
        $this->transport->setDefault(429, '{"error":{"code":"duplicate_report"}}');
        $out = $this->sender($breaker)->send($this->row(), 'sensor-1');

        $this->assertSame('duplicate', $out['status']);
        $this->assertTrue($out['drop']);
        $this->assertTrue($breaker->allow(), 'a duplicate 429 is not a fault');
        $this->assertSame('', $breaker->reason());
    }

    public function test_quota_429_parks_and_records_the_breaker()
    {
        // The regression this ticket exists for: a quota 429 must NOT be a droppable outcome.
        $breaker = $this->breaker();
        $this->transport->setDefault(429, '{"error":{"code":"quota_exhausted"}}', array('retry-after' => '120'));
        $out = $this->sender($breaker)->send($this->row(), 'sensor-1');

        $this->assertSame('quota', $out['status']);
        $this->assertFalse($out['drop'], 'a quota 429 must survive, not drop — this is the bug');
        $this->assertSame(120, $out['retry_after']);
        $this->assertSame('quota', $breaker->reason());
        $this->assertFalse($breaker->allow());
    }

    public function test_an_unlabelled_429_also_parks_rather_than_dropping()
    {
        $breaker = $this->breaker();
        $this->transport->setDefault(429, '');
        $out = $this->sender($breaker)->send($this->row(), 'sensor-1');

        $this->assertSame('quota', $out['status']);
        $this->assertFalse($out['drop']);
        $this->assertSame('quota', $breaker->reason());
    }

    public function test_a_delivery_closes_the_breaker_and_resets_its_backoff()
    {
        $breaker = $this->breaker();
        $breaker->tripTransport();
        $breaker->tripTransport(); // two consecutive opens (60s, 120s): the next would be 240s
        $this->clock->advance(121);
        $this->assertTrue($breaker->allow(), 'the half-open probe lease');
        $this->transport->setDefault(200, '');

        $out = $this->sender($breaker)->send($this->row(), 'sensor-1');

        $this->assertTrue($out['delivered']);
        $this->assertSame(0, $breaker->tripCount());
        $this->assertTrue($breaker->allow(), 'closed at once, not parked on the probe lease');
        $breaker->tripTransport();
        $this->assertSame($this->clock->now() + 60, $breaker->openUntil(), 'the curve restarts at one cooldown');
    }

    public function test_a_4xx_client_error_drops()
    {
        $this->transport->setDefault(422, '');
        $out = $this->sender()->send($this->row(), 'sensor-1');

        $this->assertSame('client_error', $out['status']);
        $this->assertTrue($out['drop']);
    }

    public function test_a_5xx_is_a_transport_error_and_writes_no_breaker()
    {
        $breaker = $this->breaker();
        $this->transport->setDefault(503, '');
        $out = $this->sender($breaker)->send($this->row(), 'sensor-1');

        $this->assertSame('transport_error', $out['status']);
        $this->assertFalse($out['drop']);
        $this->assertTrue($breaker->allow(), 'a single transport failure does not trip the breaker at this layer');
    }

    public function test_a_transport_exception_is_a_transport_error()
    {
        $breaker = $this->breaker();
        $this->transport->setDefault(0, ''); // status 0 stands in for a thrown/failed transport
        $out = $this->sender($breaker)->send($this->row(), 'sensor-1');

        $this->assertSame('transport_error', $out['status']);
        $this->assertFalse($out['drop']);
        $this->assertSame(0, $out['http_status']);
    }
}
