<?php

namespace Funnypot\Mainnet\Report;

use PDO;
use Throwable;

/**
 * The bundled real ReportQueue store (SQLite). The only PDO in this package; D/E bind their own queue
 * (wpdb / Eloquent). Tables are namespaced mnc_* so they never collide with the honeypot engine's own
 * abuse_* tables. 7.3-clean: untyped $db prop with a docblock (not a typed property).
 */
final class PdoSqliteReportQueue implements ReportQueue
{
    /** Matches WpdbReportQueue's default so the two bundled stores behave alike. */
    const DEFAULT_QUEUE_CAP = 10000;

    /** @var PDO|null lazily opened */
    private $db = null;
    /** @var string */
    private $path;
    /** @var int hard queue size cap; oldest rows dropped first */
    private $queueCap;

    public function __construct(string $path, $queueCap = self::DEFAULT_QUEUE_CAP)
    {
        $this->path = $path;
        $this->queueCap = max(1, (int) $queueCap);
    }

    public function push(array $row)
    {
        $signals = isset($row['signals']) && $row['signals'] !== '' && $row['signals'] !== null ? (string) $row['signals'] : null;
        $this->db()->prepare(
            'INSERT INTO mnc_queue (ip, categories, comment, created_at, attempts, signals) VALUES (:ip,:c,:m,:t,0,:s)'
        )->execute(array(
            ':ip' => isset($row['ip']) ? $row['ip'] : '',
            ':c' => isset($row['categories']) ? $row['categories'] : '',
            ':m' => isset($row['comment']) ? substr((string) $row['comment'], 0, 1000) : '',
            ':t' => isset($row['created_at']) ? $row['created_at'] : gmdate('c'),
            ':s' => $signals,
        ));

        $this->enforceCap();

        return true;
    }

    /**
     * Hard queue cap: drop the oldest rows once the queue exceeds it (SF-6).
     *
     * The interface has always required this and this store never implemented it, so the queue grew
     * without bound — worst exactly when it matters, since a scanner sweep is the high-volume case
     * and an un-drained queue is the default until delivery is wired up.
     *
     * SQLite is not built with DELETE ... ORDER BY LIMIT by default, so bound by id via a subselect.
     */
    private function enforceCap()
    {
        $count = $this->count();
        if ($count <= $this->queueCap) {
            return;
        }

        // LIMIT is inlined, not bound: PDO quotes bound params as strings under emulated prepares,
        // which SQLite rejects in LIMIT. The value is COUNT(*) arithmetic, so it is always an int.
        $excess = (int) ($count - $this->queueCap);
        $this->db()->exec(
            'DELETE FROM mnc_queue WHERE id IN (SELECT id FROM mnc_queue ORDER BY id ASC LIMIT ' . $excess . ')'
        );
    }

    public function take(int $limit)
    {
        $limit = max(1, (int) $limit);
        $rows = $this->db()->query('SELECT id, ip, categories, comment, created_at, attempts, signals FROM mnc_queue ORDER BY id ASC LIMIT ' . $limit)
            ->fetchAll(PDO::FETCH_ASSOC);
        $out = array();
        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['attempts'] = (int) $row['attempts'];
            if ($row['signals'] === null || $row['signals'] === '') {
                unset($row['signals']);
            }
            $out[] = $row;
        }

        return $out;
    }

    public function delete($id)
    {
        $this->db()->prepare('DELETE FROM mnc_queue WHERE id = :id')->execute(array(':id' => (int) $id));
    }

    public function bumpAttempts($id)
    {
        $this->db()->prepare('UPDATE mnc_queue SET attempts = attempts + 1 WHERE id = :id')->execute(array(':id' => (int) $id));
    }

    public function count()
    {
        return (int) $this->db()->query('SELECT COUNT(*) FROM mnc_queue')->fetchColumn();
    }

    public function recentlyReported(string $key, int $withinHours)
    {
        $st = $this->db()->prepare('SELECT reported_at FROM mnc_reports WHERE ip = :ip');
        $st->execute(array(':ip' => $key));
        $at = $st->fetchColumn();

        return $at !== false && (strtotime((string) $at) ?: 0) > time() - $withinHours * 3600;
    }

    public function markReported(string $key)
    {
        $this->db()->prepare('INSERT OR REPLACE INTO mnc_reports (ip, reported_at) VALUES (:ip,:at)')
            ->execute(array(':ip' => $key, ':at' => gmdate('c')));
    }

    public function dailyCount()
    {
        $st = $this->db()->prepare('SELECT n FROM mnc_daily WHERE day = :d');
        $st->execute(array(':d' => gmdate('Y-m-d')));
        $n = $st->fetchColumn();

        return $n === false ? 0 : (int) $n;
    }

    public function bumpDaily()
    {
        $this->db()->prepare('INSERT INTO mnc_daily (day, n) VALUES (:d,1) ON CONFLICT(day) DO UPDATE SET n = n + 1')
            ->execute(array(':d' => gmdate('Y-m-d')));
    }

    public function sensorId()
    {
        $st = $this->db()->prepare("SELECT v FROM mnc_meta WHERE k = 'sensor_id'");
        $st->execute();
        $v = $st->fetchColumn();
        if ($v !== false && $v !== '') {
            return (string) $v;
        }
        $uuid = $this->uuid4();
        $this->db()->prepare("INSERT OR IGNORE INTO mnc_meta (k, v) VALUES ('sensor_id', :v)")->execute(array(':v' => $uuid));
        // Re-read in case a concurrent writer won the INSERT.
        $st = $this->db()->prepare("SELECT v FROM mnc_meta WHERE k = 'sensor_id'");
        $st->execute();
        $stored = $st->fetchColumn();

        return $stored !== false && $stored !== '' ? (string) $stored : $uuid;
    }

    private function uuid4()
    {
        $b = random_bytes(16); // NEVER a hardware/MAC id (D3)
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function db()
    {
        if ($this->db !== null) {
            return $this->db;
        }
        $dir = dirname($this->path);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $db = new PDO('sqlite:' . $this->path, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        @chmod($this->path, 0666);
        $db->exec('PRAGMA busy_timeout=3000');
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('CREATE TABLE IF NOT EXISTS mnc_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, categories TEXT NOT NULL, comment TEXT NOT NULL, created_at TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, signals TEXT)');
        $db->exec('CREATE TABLE IF NOT EXISTS mnc_reports (ip TEXT PRIMARY KEY, reported_at TEXT NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS mnc_daily (day TEXT PRIMARY KEY, n INTEGER NOT NULL DEFAULT 0)');
        $db->exec('CREATE TABLE IF NOT EXISTS mnc_meta (k TEXT PRIMARY KEY, v TEXT)');

        return $this->db = $db;
    }
}
