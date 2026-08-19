<?php

namespace Funnypot\Mainnet\Tests\Check;

use Funnypot\Mainnet\Client;
use Funnypot\Mainnet\Config;
use Funnypot\Mainnet\Report\Reporter;
use Funnypot\Mainnet\Tests\Support\FakeClock;
use Funnypot\Mainnet\Tests\Support\FakeTransport;
use Funnypot\Mainnet\Tests\Support\InMemoryReportQueue;
use PHPUnit\Framework\TestCase;

final class ClientReportTest extends TestCase
{
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

    private function client(array $over = array())
    {
        $config = Config::fromArray(array_merge(array(
            'base_url' => 'https://mainnet.example',
            'key' => 'report-key',
            'self_ips' => array('203.0.113.99'),
        ), $over));
        $reporter = new Reporter(
            $this->queue,
            $this->transport,
            $config->baseUrl(),
            $config->key(),
            $config->selfIps(),
            $config->dailyCap(),
            $config->dedupHours(),
            null,
            $this->clock->asCallable()
        );

        return new Client($config, $this->transport, null, $reporter, null, $this->clock->asCallable());
    }

    public function test_report_delegates_to_reporter()
    {
        $res = $this->client()->report('198.51.100.9', 'brute force', '18,22');
        $this->assertTrue($res['queued']);
        $this->assertSame(1, $this->queue->count());
        $this->assertSame('198.51.100.9', $this->queue->rows()[0]['ip']);
    }

    public function test_report_active_on_key_without_check_enabled()
    {
        // check_enabled defaults to false; reporting still works on the key alone (D2).
        $res = $this->client()->report('198.51.100.9', 'x');
        $this->assertTrue($res['queued']);
    }

    public function test_report_inert_without_key()
    {
        $res = $this->client(array('key' => ''))->report('198.51.100.9', 'x');
        $this->assertFalse($res['queued']);
        $this->assertSame('no api key', $res['reason']);
    }

    public function test_report_forwards_signals_to_reporter()
    {
        $signals = array('ua_class' => 'script');
        $this->client()->report('198.51.100.9', 'bad bot', 'bad_bot', $signals);
        $row = $this->queue->rows()[0];
        $this->assertSame(json_encode($signals), $row['signals']);

        // Omitted signals -> no signals key on the row (opt-in).
        $this->client()->report('198.51.100.10', 'plain');
        $rows = $this->queue->rows();
        $this->assertArrayNotHasKey('signals', $rows[count($rows) - 1]);
    }
}
