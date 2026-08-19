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
 * State is one record { failures, until (epoch, 0 = closed), reason } (N1). It lives in the injected
 * Cache when that cache is Persistent (shared across requests); otherwise (ArrayCache/NullCache are
 * per-process) it falls back to a filemtime marker in sys_get_temp_dir() so outage state still crosses
 * requests. An absent/evicted marker reads as CLOSED.
 *
 * Two fault classes, two clocks (N2): transport (timeout/status 0, 5xx, 401/403, malformed 200) trips
 * at a threshold for a jittered cooldown; quota (429 quota_exhausted) trips immediately and parks until
 * the server reset time, capped.
 */
final class CircuitBreaker
{
    const CACHE_KEY = 'mnc:breaker';

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

    /**
     * @param Cache         $cache
     * @param int           $threshold
     * @param int           $cooldownSecs
     * @param int           $quotaParkCapSecs
     * @param callable|null $clock       callable():int epoch seconds; defaults to time()
     * @param callable|null $jitter      callable(int):int; defaults to +/-20%
     * @param string|null   $markerFile  filemtime marker path; defaults under sys_get_temp_dir()
     */
    public function __construct(Cache $cache, $threshold = 5, $cooldownSecs = 60, $quotaParkCapSecs = 21600, $clock = null, $jitter = null, $markerFile = null)
    {
        $this->cache = $cache;
        $this->persistent = $cache instanceof Persistent;
        $this->threshold = (int) $threshold;
        $this->cooldownSecs = (int) $cooldownSecs;
        $this->quotaParkCapSecs = (int) $quotaParkCapSecs;
        $this->clock = $clock !== null ? $clock : 'time';
        $this->jitter = $jitter !== null ? $jitter : array($this, 'defaultJitter');
        $this->markerFile = $markerFile !== null ? $markerFile : rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'mnc_breaker.json';
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
            // Half-open: the first caller CAS-extends `until` and probes alone.
            $rec['until'] = $now + $this->jitter($this->cooldownSecs);
            $this->write($rec);

            return true;
        } catch (Throwable $e) {
            return true; // the breaker never breaks
        }
    }

    public function recordSuccess()
    {
        try {
            $this->write(array('failures' => 0, 'until' => 0, 'reason' => ''));
        } catch (Throwable $e) {
            // best-effort
        }
    }

    public function recordTransportFailure()
    {
        try {
            $rec = $this->read();
            $failures = (isset($rec['failures']) ? (int) $rec['failures'] : 0) + 1;
            if ($failures >= $this->threshold) {
                $this->write(array(
                    'failures' => 0,
                    'until' => $this->now() + $this->jitter($this->cooldownSecs),
                    'reason' => 'transport',
                ));
            } else {
                $this->write(array('failures' => $failures, 'until' => 0, 'reason' => ''));
            }
        } catch (Throwable $e) {
            // best-effort
        }
    }

    /**
     * Quota-class trip (429 quota_exhausted): open immediately, park to the server reset time.
     * $retryAfter is a delta in seconds; $rateLimitReset is an absolute epoch. The later of the two wins,
     * capped at quota_park_cap_secs from now (defensive against a bad header).
     *
     * @param int|null $retryAfter
     * @param int|null $rateLimitReset
     */
    public function recordQuota($retryAfter, $rateLimitReset)
    {
        try {
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
                $candidate = $now + $this->cooldownSecs; // no usable header -> default park
            }
            $capped = min($candidate, $cap);
            $duration = max(1, $capped - $now);
            $until = $now + $this->jitter($duration);
            if ($until > $cap) {
                $until = $cap;
            }
            $this->write(array('failures' => 0, 'until' => $until, 'reason' => 'quota'));
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

    /** @return array the state record, or [] when absent (CLOSED) */
    private function read()
    {
        if ($this->persistent) {
            $rec = $this->cache->get(self::CACHE_KEY, null);

            return is_array($rec) ? $rec : array();
        }

        return $this->readMarker();
    }

    private function write(array $rec)
    {
        if ($this->persistent) {
            $this->cache->set(self::CACHE_KEY, $rec, 0);

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
