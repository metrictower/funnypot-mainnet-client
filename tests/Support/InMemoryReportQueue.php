<?php

namespace Funnypot\Mainnet\Tests\Support;

use Funnypot\Mainnet\Report\ReportQueue;

/**
 * Array-backed ReportQueue double. Full contract incl. a stable cached sensorId() UUID, the push hard
 * cap (oldest dropped), and round-tripping the optional `signals` payload on the row. An injectable
 * clock keeps dedup/daily windows deterministic.
 */
final class InMemoryReportQueue implements ReportQueue
{
    /** @var array<int,array> */
    private $rows = array();
    /** @var int */
    private $nextId = 1;
    /** @var array<string,int> key => epoch reported */
    private $reports = array();
    /** @var array<string,int> day => count */
    private $daily = array();
    /** @var string|null */
    private $sensorId;
    /** @var int */
    private $cap;
    /** @var callable():int */
    private $clock;

    public function __construct($cap = 10000, $clock = null)
    {
        $this->cap = (int) $cap;
        $this->clock = $clock !== null ? $clock : 'time';
    }

    public function push(array $row)
    {
        $row['id'] = $this->nextId++;
        if (!isset($row['attempts'])) {
            $row['attempts'] = 0;
        }
        $this->rows[] = $row;
        // Hard cap: drop the oldest rows until within the cap.
        while (count($this->rows) > $this->cap) {
            array_shift($this->rows);
        }

        return true;
    }

    public function take(int $limit)
    {
        return array_slice($this->rows, 0, max(0, (int) $limit));
    }

    public function delete($id)
    {
        foreach ($this->rows as $i => $row) {
            if ($row['id'] === $id) {
                unset($this->rows[$i]);
                $this->rows = array_values($this->rows);

                return;
            }
        }
    }

    public function bumpAttempts($id)
    {
        foreach ($this->rows as $i => $row) {
            if ($row['id'] === $id) {
                $this->rows[$i]['attempts'] = (int) $row['attempts'] + 1;

                return;
            }
        }
    }

    public function count()
    {
        return count($this->rows);
    }

    public function recentlyReported(string $key, int $withinHours)
    {
        if (!isset($this->reports[$key])) {
            return false;
        }

        return $this->reports[$key] > $this->now() - $withinHours * 3600;
    }

    public function markReported(string $key)
    {
        $this->reports[$key] = $this->now();
    }

    public function dailyCount()
    {
        $day = $this->day();

        return isset($this->daily[$day]) ? $this->daily[$day] : 0;
    }

    public function bumpDaily()
    {
        $day = $this->day();
        $this->daily[$day] = (isset($this->daily[$day]) ? $this->daily[$day] : 0) + 1;
    }

    public function sensorId()
    {
        if ($this->sensorId === null) {
            $this->sensorId = $this->uuid4();
        }

        return $this->sensorId;
    }

    /** All queued rows (test inspection). */
    public function rows()
    {
        return $this->rows;
    }

    private function day()
    {
        return gmdate('Y-m-d', $this->now());
    }

    private function now()
    {
        return (int) call_user_func($this->clock);
    }

    private function uuid4()
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
