<?php

namespace Funnypot\Mainnet\Tests\Report;

use Funnypot\Mainnet\Report\ReportQueue;
use Funnypot\Mainnet\Tests\Support\FakeTransport;
use Funnypot\Mainnet\Tests\Support\InMemoryReportQueue;
use Funnypot\Mainnet\Transport\Transport;
use PHPUnit\Framework\TestCase;

final class ContractsSmokeTest extends TestCase
{
    public function test_doubles_implement_their_contracts()
    {
        $this->assertInstanceOf(ReportQueue::class, new InMemoryReportQueue());
        $this->assertInstanceOf(Transport::class, new FakeTransport());
    }

    public function test_in_memory_queue_roundtrips()
    {
        $q = new InMemoryReportQueue();
        $q->push(array('ip' => '203.0.113.7', 'categories' => '21', 'comment' => 'x', 'created_at' => gmdate('c'), 'attempts' => 0));
        $rows = $q->take(10);
        $this->assertCount(1, $rows);
        $this->assertSame('203.0.113.7', $rows[0]['ip']);
        $q->delete($rows[0]['id']);
        $this->assertSame(0, $q->count());
    }

    public function test_sensor_id_stable_across_calls()
    {
        $q = new InMemoryReportQueue();
        $a = $q->sensorId();
        $this->assertNotSame('', $a);
        $this->assertSame($a, $q->sensorId());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a);
    }
}
