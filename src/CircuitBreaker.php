<?php

namespace Funnypot\Mainnet;

use Funnypot\Mainnet\Cache\Cache;
use Funnypot\Mainnet\Cache\Persistent;
use Throwable;

/**
 * The canonical decision-N global fail-open cooldown (owner: F). Trip -> open for a cooldown ->
 * fast-fail while open; single-flight half-open at expiry; ALWAYS fails open (allow) if its own store is
 * unreadable — the breaker must never be the thing that breaks (N5). 7.3-clean (no typed props, no
 * promotion).
 *
 * State is one record { failures, until (epoch, 0 = closed), reason, trip_count } (N1). It lives in the
 * injected Cache when that cache is Persistent (shared across requests); otherwise (ArrayCache/NullCache
 * are per-process) it falls back to a filemtime marker in sys_get_temp_dir() so outage state still
 * crosses requests. An absent/evicted marker reads as CLOSED.
 *
 * A breaker guards one channel (one upstream capability: 'check', 'report', ...). The channel names the
 * record's cache key and marker file, so one capability's outage never opens another's breaker. The
 * 'default' channel keeps the original unsuffixed key, so a host that builds a single breaker by hand
 * is unaffected.
 *
 * Two fault classes, two clocks (N2): transport (timeout/status 0, 5xx, 401/403, malformed 200) trips
 * at a threshold for a jittered cooldown; quota (429 quota_exhausted) trips immediately and parks until
 * the server reset time, capped.
 *
 * Consecutive opens back off exponentially. `trip_count` counts opens since the last success, and a
 * transport-class open lasts cooldown x 2^(trip_count - 1), capped at the max backoff. The first open is
 * exactly one cooldown, so a single blip behaves as it always did; only a sustained outage widens the
 * re-probe interval, so a fleet stops hammering a server that is already struggling. `failures` remains
 * the sub-threshold counter toward the FIRST open. While open, only the half-open probe gets through,
 * and its failure re-trips at once (no re-accumulation): a still-down server is probed less, not more.
 */
final class CircuitBreaker
{
    /** Cache key of the 'default' channel; any other channel appends ':<channel>'. */
    const CACHE_KEY = 'mnc:breaker';
    const DEFAULT_CHANNEL = 'default';

    /** @var Cache */
    private $cache;
    /** @var bool the injected cache is cross-request */
    private $persistent;
    /** @var int */
    private $threshold;
    /** @var int */
    private $cooldownSecs;
    /** @var int */
    private $quotaParkCapSecs;
    /** @var callable():int */
    private $clock;
    /** @var callable(int):int applied to a cooldown/park duration on write */
    private $jitter;
    /** @var string filemtime fallback marker path */
    private $markerFile;
    /** @var string the record's key in a Persistent cache (channel-derived) */
    private $cacheKey;
    /** @var int ceiling on an escalated transport-class open; never below cooldownSecs */
    private $maxBackoffSecs;

    /**
     * @param Cache         $cache
     * @param int           $threshold
     * @param int           $cooldownSecs     the first open's duration and the base of the backoff curve
     * @param int           $quotaParkCapSecs
     * @param callable|null $clock            callable():int epoch seconds; defaults to time()
     * @param callable|null $jitter           callable(int):int; defaults to +/-20%
     * @param string|null   $markerFile       filemtime marker path; defaults under sys_get_temp_dir()
     * @param string        $channel          the capability this breaker guards; keys the record + marker
     * @param int           $maxBackoffSecs   cap on an escalated open; clamped to >= $cooldownSecs, so
     *                                        equal to it means the old flat (non-escalating) cooldown
     */
    public function __construct(Cache $cache, $threshold = 5, $cooldownSecs = 60, $quotaParkCapSecs = 21600, $clock = null, $jitter = null, $markerFile = null, $channel = self::DEFAULT_CHANNEL, $maxBackoffSecs = 1800)
    {
        $this->cache = $cache;
        $this->persistent = $cache instanceof Persistent;
        $this->threshold = (int) $threshold;
        $this->cooldownSecs = (int) $cooldownSecs;
        $this->quotaParkCapSecs = (int) $quotaParkCapSecs;
        $this->clock = $clock !== null ? $clock : 'time';
        $this->jitter = $jitter !== null ? $jitter : array($this, 'defaultJitter');
        $this->maxBackoffSecs = max((int) $maxBackoffSecs, $this->cooldownSecs);
        $channel = (string) $channel;
        $this->cacheKey = $channel === self::DEFAULT_CHANNEL ? self::CACHE_KEY : self::CACHE_KEY . ':' . $channel;
        $this->markerFile = $markerFile !== null ? $markerFile : self::defaultMarkerFile($channel);
    }

    /**
     * False while the breaker is open. At expiry it is single-flight half-open: the first caller extends
     * `until` by one cooldown and probes alone (returns true); concurrent callers keep failing open.
     * Any store fault degrades to true (fail-open, N5).
     */
    public function allow()
    {
        try {
            $rec = $this->read();
            $until = isset($rec['until']) ? (int) $rec['until'] : 0;
            if ($until <= 0) {
                return true; // CLOSED (may be accumulating sub-threshold failures)
            }
            $now = $this->now();
            if ($now < $until) {
                return false; // OPEN
            }
            // Half-open: the first caller CAS-extends `until` and probes alone. The extension is a probe
            // lease of one base cooldown, not a backoff step — escalation waits for the probe's outcome.
            $rec['until'] = $now + $this->jitter($this->cooldownSecs);
            $this->write($rec);

            return true;
        } catch (Throwable $e) {
            return true; // the breaker never breaks
        }
    }

    /**
     * Close and clear all counters. A record that is already clean is left alone: the healthy path
     * (every delivered report) then costs a read, not a locked write, and cannot clobber a trip another
     * process recorded in between.
     */
    public function recordSuccess()
    {
        try {
            $rec = $this->read();
            if ((isset($rec['until']) ? (int) $rec['until'] : 0) === 0
                && (isset($rec['failures']) ? (int) $rec['failures'] : 0) === 0
                && $this->trips($rec) === 0) {
                return;
            }
            $this->write(array('failures' => 0, 'until' => 0, 'reason' => '', 'trip_count' => 0));
        } catch (Throwable $e) {
            // best-effort
        }
    }

    public function recordTransportFailure()
    {
        try {
            $rec = $this->read();
            if ((isset($rec['until']) ? (int) $rec['until'] : 0) > 0) {
                // Open: only the half-open probe gets here, and its failure re-trips straight away.
                $this->openTransport($rec);

                return;
            }
            $failures = (isset($rec['failures']) ? (int) $rec['failures'] : 0) + 1;
            if ($failures >= $this->threshold) {
                $this->openTransport($rec);
            } else {
                $this->write(array('failures' => $failures, 'until' => 0, 'reason' => '', 'trip_count' => $this->trips($rec)));
            }
        } catch (Throwable $e) {
            // best-effort
        }
    }

    /**
     * Quota-class trip (429 quota_exhausted): open immediately, park to the server reset time.
     * $retryAfter is a delta in seconds; $rateLimitReset is an absolute epoch. The later of the two wins,
     * capped at quota_park_cap_secs from now (defensive against a bad header). Without a usable header
     * the park follows the transport backoff curve, so a server that keeps answering 429 with no
     * timing gets probed progressively less often.
     *
     * @param int|null $retryAfter
     * @param int|null $rateLimitReset
     */
    public function recordQuota($retryAfter, $rateLimitReset)
    {
        try {
            $trips = $this->trips($this->read()) + 1;
            $now = $this->now();
            $cap = $now + $this->quotaParkCapSecs;
            $candidate = 0;
            if ($retryAfter !== null && (int) $retryAfter > 0) {
                $candidate = max($candidate, $now + (int) $retryAfter);
            }
            if ($rateLimitReset !== null && (int) $rateLimitReset > $now) {
                $candidate = max($candidate, (int) $rateLimitReset);
            }
            if ($candidate <= $now) {
                $candidate = $now + $this->backoffFor($trips); // no usable header -> default park
            }
            $capped = min($candidate, $cap);
            $duration = max(1, $capped - $now);
            $until = $now + $this->jitter($duration);
            if ($until > $cap) {
                $until = $cap;
            }
            $this->write(array('failures' => 0, 'until' => $until, 'reason' => 'quota', 'trip_count' => $trips));
        } catch (Throwable $e) {
            // best-effort
        }
    }

    /**
     * Directly open the breaker for one transport-class window. The report drain calls this after its own
     * 3-consecutive-transport-failure abort budget (N6) so the shared marker fast-skips the next tick.
     * Distinct from recordTransportFailure()'s threshold accumulation, but on the same backoff curve:
     * every consecutive call opens for longer, up to the cap.
     */
    public function tripTransport()
    {
        try {
            $this->openTransport($this->read());
        } catch (Throwable $e) {
            // best-effort
        }
    }

    /** Current open reason ('' when closed). Observability for logging/tests. */
    public function reason()
    {
        try {
            $rec = $this->read();

            return isset($rec['reason']) ? (string) $rec['reason'] : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /** The epoch this breaker is open until (0 when closed). */
    public function openUntil()
    {
        try {
            $rec = $this->read();

            return isset($rec['until']) ? (int) $rec['until'] : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Consecutive opens since the last success (0 when never tripped or since reset). */
    public function tripCount()
    {
        try {
            return $this->trips($this->read());
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** The temp-dir marker a channel falls back to when its cache is not Persistent. */
    public static function defaultMarkerFile($channel)
    {
        $channel = (string) $channel;
        // The channel is code-chosen, but it lands in a path: keep it to a filename-safe token.
        $suffix = $channel === self::DEFAULT_CHANNEL ? '' : '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $channel);

        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'mnc_breaker' . $suffix . '.json';
    }

    /** Open (or re-open) on the transport curve: one more consecutive trip, escalated then jittered. */
    private function openTransport(array $rec)
    {
        $trips = $this->trips($rec) + 1;
        $this->write(array(
            'failures' => 0,
            'until' => $this->now() + $this->jitter($this->backoffFor($trips)),
            'reason' => 'transport',
            'trip_count' => $trips,
        ));
    }

    /** cooldown x 2^(trips - 1), capped. Doubles in a loop so a long outage can never overflow a shift. */
    private function backoffFor($trips)
    {
        $delay = $this->cooldownSecs;
        if ($delay <= 0) {
            return 0;
        }
        for ($i = 1; $i < $trips && $delay < $this->maxBackoffSecs; $i++) {
            $delay *= 2;
        }

        return min($delay, $this->maxBackoffSecs);
    }

    /** @return int the persisted consecutive-trip count; a record written before the field existed reads as 0 */
    private function trips(array $rec)
    {
        return isset($rec['trip_count']) ? max(0, (int) $rec['trip_count']) : 0;
    }

    /** @return array the state record, or [] when absent (CLOSED) */
    private function read()
    {
        if ($this->persistent) {
            $rec = $this->cache->get($this->cacheKey, null);

            return is_array($rec) ? $rec : array();
        }

        return $this->readMarker();
    }

    private function write(array $rec)
    {
        if ($this->persistent) {
            $this->cache->set($this->cacheKey, $rec, 0);

            return;
        }
        $this->writeMarker($rec);
    }

    private function readMarker()
    {
        if (!is_file($this->markerFile)) {
            return array();
        }
        $raw = @file_get_contents($this->markerFile);
        if ($raw === false || $raw === '') {
            return array();
        }
        $rec = json_decode($raw, true);

        return is_array($rec) ? $rec : array();
    }

    private function writeMarker(array $rec)
    {
        @file_put_contents($this->markerFile, json_encode($rec), LOCK_EX);
    }

    private function now()
    {
        return (int) call_user_func($this->clock);
    }

    private function jitter($base)
    {
        return (int) call_user_func($this->jitter, (int) $base);
    }

    /** +/-20% jitter so a fleet sharing one outage does not re-probe in lockstep. */
    private function defaultJitter($base)
    {
        if ($base <= 0) {
            return 0;
        }
        $delta = (int) round($base * (mt_rand(-20, 20) / 100));

        return max(1, $base + $delta);
    }
}
