<?php

namespace Funnypot\Mainnet\Report;

use Funnypot\Mainnet\CircuitBreaker;
use Funnypot\Mainnet\Net;
use Funnypot\Mainnet\Transport\Transport;
use Throwable;

/**
 * Outbound abuse reporter, relocated from piece B (was Funnypot\Report\MainnetReporter). Split into
 * enqueue (fast, local, guarded) and drain (the actual POSTs) so a request/listener path never blocks
 * on the network. Enqueue behaviour is B-verbatim except: (a) the IPv6 dedup/daily-cap key is the /64
 * score_key (P2/G2 — the posted body still carries the full /128), and (b) an optional consumer-computed
 * `signals` payload the reporter forwards but never inspects. The drain is hardened per SF-7 (429 split
 * by Error code) + N6 (breaker-aware, budgeted tick). 7.3-clean.
 */
final class Reporter
{
    const MAX_ATTEMPTS = 3;
    const DRAIN_BUDGET_SECS = 10;
    const DRAIN_CONSEC_FAIL_ABORT = 3;
    const MAX_AGE_SECS = 604800; // 7 days: a report older than this is dropped

    /** @var ReportQueue */
    private $queue;
    /** @var Transport */
    private $transport;
    /** @var string */
    private $baseUrl;
    /** @var string */
    private $apiKey;
    /** @var array */
    private $selfIps;
    /** @var int */
    private $dailyCap;
    /** @var int */
    private $dedupHours;
    /** @var CircuitBreaker|null */
    private $breaker;
    /** @var callable():int */
    private $clock;
    /** @var ReportSender */
    private $sender;

    /**
     * @param ReportQueue         $queue
     * @param Transport           $transport
     * @param string              $baseUrl    scheme+host only; the drain appends /v1/report (D1)
     * @param string              $apiKey
     * @param array               $selfIps
     * @param int                 $dailyCap
     * @param int                 $dedupHours
     * @param CircuitBreaker|null $breaker    shares the decision-N mnc:breaker marker (N6)
     * @param callable|null       $clock      callable():int epoch; defaults to time()
     */
    public function __construct(ReportQueue $queue, Transport $transport, string $baseUrl, string $apiKey, array $selfIps = array(), $dailyCap = 1000, $dedupHours = 24, ?CircuitBreaker $breaker = null, $clock = null)
    {
        $this->queue = $queue;
        $this->transport = $transport;
        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
        $this->selfIps = $selfIps;
        $this->dailyCap = (int) $dailyCap;
        $this->dedupHours = (int) $dedupHours;
        $this->breaker = $breaker;
        $this->clock = $clock !== null ? $clock : 'time';
        // drain() delegates the per-row POST + wire classification to the sender so the same handling is
        // reused by hosts with their own delivery queue (FP-0060). Same transport/breaker/clock instances.
        $this->sender = new ReportSender($transport, $baseUrl, $apiKey, $breaker, $this->clock);
    }

    /** AbuseIPDB-style category ids appropriate to a protocol honeypot hit (moved verbatim from B). */
    public static function categoriesForProtocol(string $protocol)
    {
        switch (strtolower($protocol)) {
            case 'ssh':
                return '18,22';   // brute-force, SSH
            case 'telnet':
                return '18,23';   // brute-force, IoT-targeted
            case 'ftp':
            case 'smtp':
            case 'pop3':
            case 'imap':
                return '18';      // brute-force
            default:
                return '14,15';   // port scan, hacking
        }
    }

    /**
     * Queue a report if it passes the guards (fast, local):
     * no api key -> self (when self_ips configured) -> not a public ip -> deduped -> daily cap -> queued.
     *
     * @param string $ip
     * @param string $comment
     * @param string $categories
     * @param array  $signals  OPTIONAL consumer-computed request-shape evidence (forwarded verbatim; empty => omitted)
     * @return array {queued:bool, reason:string}
     */
    public function enqueue(string $ip, string $comment, string $categories = '21', array $signals = array())
    {
        if ($this->apiKey === '') {
            return $this->skip('no api key');
        }
        if (in_array($ip, $this->selfIps, true)) {
            return $this->skip('self');                     // the invariant
        }
        if (!Net::isPublic($ip)) {
            return $this->skip('not a public ip');
        }
        // The dedup/daily-cap key is the /64 score_key for IPv6 (agrees with the server's /64
        // aggregation, G2); IPv4 is the full address. The posted body still carries the full /128.
        $dedupKey = Net::scoreKey($ip);
        try {
            if ($this->queue->recentlyReported($dedupKey, $this->dedupHours)) {
                return $this->skip('deduped');
            }
            if ($this->queue->dailyCount() >= $this->dailyCap) {
                return $this->skip('daily cap');
            }
            $row = array(
                'ip' => $ip,
                'categories' => $categories,
                'comment' => substr($comment, 0, 1000),
                'created_at' => gmdate('c', $this->now()),
                'attempts' => 0,
            );
            if ($signals !== array()) {
                $row['signals'] = json_encode($signals);
            }
            $this->queue->push($row);
            $this->queue->markReported($dedupKey); // dedup mark now so the same entity does not re-queue

            return array('queued' => true, 'reason' => 'queued');
        } catch (Throwable $e) {
            return $this->skip('error: ' . $e->getMessage());
        }
    }

    /**
     * Send queued reports. Breaker-aware + budgeted (N6). Returns {sent, failed, pending}.
     *
     * @param int $limit
     * @return array
     */
    public function drain($limit = 200)
    {
        if ($this->breaker !== null && !$this->breaker->allow()) {
            return array('sent' => 0, 'failed' => 0, 'pending' => $this->safeCount()); // OPEN -> skip the tick (N3)
        }

        try {
            $rows = $this->queue->take((int) $limit);
        } catch (Throwable $e) {
            return array('sent' => 0, 'failed' => 0, 'pending' => $this->safeCount());
        }

        $sensorId = $this->queue->sensorId();
        $start = $this->now();
        $sent = 0;
        $failed = 0;
        $consecutiveTransportFails = 0;

        foreach ($rows as $row) {
            if ($this->queue->dailyCount() >= $this->dailyCap) {
                break; // leave the rest for tomorrow
            }
            if (($this->now() - $start) >= self::DRAIN_BUDGET_SECS) {
                break; // wall-clock budget spent (N6)
            }
            $id = isset($row['id']) ? $row['id'] : null;
            $attempts = isset($row['attempts']) ? (int) $row['attempts'] : 0;

            // Drop rows past the age cap before spending a POST on them.
            if ($this->tooOld($row)) {
                $this->queue->delete($id);
                $failed++;
                continue;
            }

            $outcome = $this->sender->send($row, $sensorId);

            if ($outcome['delivered']) {
                $this->queue->delete($id);
                $this->queue->bumpDaily();
                $sent++;
                $consecutiveTransportFails = 0;
                continue;
            }
            if ($outcome['status'] === 'duplicate') {
                // Not a fault: drop, never loop, breaker untouched (SF-7).
                $this->queue->delete($id);
                $failed++;
                $consecutiveTransportFails = 0;
                continue;
            }
            if ($outcome['status'] === 'quota') {
                // send() already recorded quota on the breaker; park + stop the tick (SF-7/N2). Row stays queued.
                break;
            }
            if ($outcome['status'] === 'client_error') {
                // Permanent client error (no_report_rights, 422, ...) -> drop.
                $this->queue->delete($id);
                $failed++;
                $consecutiveTransportFails = 0;
                continue;
            }

            // 5xx / transport (status 0): transport-class failure.
            if ($attempts + 1 >= self::MAX_ATTEMPTS) {
                $this->queue->delete($id);
                $failed++;
            } else {
                $this->queue->bumpAttempts($id);
            }
            $consecutiveTransportFails++;
            if ($consecutiveTransportFails >= self::DRAIN_CONSEC_FAIL_ABORT) {
                if ($this->breaker !== null) {
                    $this->breaker->tripTransport(); // write the shared marker so the next tick fast-skips (N6)
                }
                break;
            }
        }

        return array('sent' => $sent, 'failed' => $failed, 'pending' => $this->safeCount());
    }

    public function queueCount()
    {
        return $this->safeCount();
    }

    // --- internals -------------------------------------------------------------------------------

    private function tooOld(array $row)
    {
        if (!isset($row['created_at']) || $row['created_at'] === '') {
            return false;
        }
        $ts = strtotime((string) $row['created_at']);
        if ($ts === false) {
            return false;
        }

        return ($this->now() - $ts) > self::MAX_AGE_SECS;
    }

    private function safeCount()
    {
        try {
            return (int) $this->queue->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function now()
    {
        return (int) call_user_func($this->clock);
    }

    private function skip($reason)
    {
        return array('queued' => false, 'reason' => $reason);
    }
}
