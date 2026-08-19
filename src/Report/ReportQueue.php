<?php

namespace Funnypot\Mainnet\Report;

/**
 * Storage contract for the report path (relocated from piece B, unchanged but for the namespace and the
 * additive optional `signals` payload). D/E bind their own implementations (wpdb / Eloquent); the bundled
 * PdoSqliteReportQueue is the default. Enqueue writes are fast and local so the request/listener paths
 * never block on a network call; the background drain does the POSTs.
 */
interface ReportQueue
{
    /**
     * Enqueue a report row and enforce the hard queue cap (drop the oldest row when full). The row is
     * {ip, categories, comment, created_at, attempts}, plus an optional non-empty `signals` JSON string.
     *
     * @param array $row
     * @return bool
     */
    public function push(array $row);

    /**
     * Oldest-first rows to attempt, each carrying id + attempts + created_at (+ signals when present).
     *
     * @param int $limit
     * @return array<int,array>
     */
    public function take(int $limit);

    /** Remove a row by id. */
    public function delete($id);

    /** Increment a row's attempts counter. */
    public function bumpAttempts($id);

    /** Number of rows currently queued. */
    public function count();

    /** True when $key was reported within the last $withinHours (per-IP dedup; $key is the score_key). */
    public function recentlyReported(string $key, int $withinHours);

    /** Mark $key as reported now (the dedup stamp; $key is the score_key). */
    public function markReported(string $key);

    /** Number of reports sent today (against the daily cap). */
    public function dailyCount();

    /** Increment today's sent counter. */
    public function bumpDaily();

    /** Stable per-sensor UUID (D3), generated once and persisted; NEVER a hardware/MAC id. */
    public function sensorId();
}
