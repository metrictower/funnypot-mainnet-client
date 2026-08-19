<?php

namespace Funnypot\Mainnet\Tests\Report;

use Funnypot\Mainnet\Report\PdoSqliteReportQueue;
use PHPUnit\Framework\TestCase;

final class PdoSqliteReportQueueTest extends TestCase
{
    /** @var string */
    private $file;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not present');
        }
        $this->file = tempnam(sys_get_temp_dir(), 'mnc_q_') . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (array($this->file, $this->file . '-wal', $this->file . '-shm') as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    public function test_push_take_delete_roundtrip()
    {
        $q = new PdoSqliteReportQueue($this->file);
        $q->push(array('ip' => '198.51.100.9', 'categories' => '18,22', 'comment' => 'brute', 'created_at' => gmdate('c')));
        $this->assertSame(1, $q->count());
        $rows = $q->take(10);
        $this->assertCount(1, $rows);
        $this->assertSame('198.51.100.9', $rows[0]['ip']);
        $this->assertSame(0, $rows[0]['attempts']);
        $q->delete($rows[0]['id']);
        $this->assertSame(0, $q->count());
    }

    public function test_bump_attempts_persists()
    {
        $q = new PdoSqliteReportQueue($this->file);
        $q->push(array('ip' => '198.51.100.9', 'categories' => '21', 'comment' => 'x', 'created_at' => gmdate('c')));
        $id = $q->take(1)[0]['id'];
        $q->bumpAttempts($id);
        $q->bumpAttempts($id);
        $this->assertSame(2, $q->take(1)[0]['attempts']);
    }

    public function test_dedup_and_daily_bookkeeping()
    {
        $q = new PdoSqliteReportQueue($this->file);
        $this->assertFalse($q->recentlyReported('198.51.100.9', 24));
        $q->markReported('198.51.100.9');
        $this->assertTrue($q->recentlyReported('198.51.100.9', 24));
        $this->assertFalse($q->recentlyReported('198.51.100.9', 0), 'a zero-hour window never counts as recent');

        $this->assertSame(0, $q->dailyCount());
        $q->bumpDaily();
        $q->bumpDaily();
        $this->assertSame(2, $q->dailyCount());
    }

    public function test_sensor_id_stable_and_persisted()
    {
        $q = new PdoSqliteReportQueue($this->file);
        $id = $q->sensorId();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        $this->assertSame($id, $q->sensorId());
        // A fresh queue over the SAME file returns the persisted value, not a new one.
        $q2 = new PdoSqliteReportQueue($this->file);
        $this->assertSame($id, $q2->sensorId());
    }

    public function test_signals_blob_roundtrips()
    {
        $q = new PdoSqliteReportQueue($this->file);
        $signals = json_encode(array('ua_class' => 'script'));
        $q->push(array('ip' => '198.51.100.9', 'categories' => 'bad_bot', 'comment' => 'x', 'created_at' => gmdate('c'), 'signals' => $signals));
        $q->push(array('ip' => '198.51.100.10', 'categories' => '21', 'comment' => 'y', 'created_at' => gmdate('c')));
        $rows = $q->take(10);
        $this->assertSame($signals, $rows[0]['signals'], 'the signals JSON blob round-trips');
        $this->assertArrayNotHasKey('signals', $rows[1], 'a row pushed with no signals returns none');
    }
}
