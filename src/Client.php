<?php

namespace Funnypot\Mainnet;

use Funnypot\Mainnet\Cache\Cache;
use Funnypot\Mainnet\Cache\NullCache;
use Funnypot\Mainnet\Report\PdoSqliteReportQueue;
use Funnypot\Mainnet\Report\Reporter;
use Funnypot\Mainnet\Transport\CurlTransport;
use Funnypot\Mainnet\Transport\StreamTransport;
use Funnypot\Mainnet\Transport\Transport;
use Throwable;

/**
 * The one entry point for a consumer: check + report over {base_url}/v1/*.
 *
 * check()/cachedVerdict() split is the load-bearing M5 seam: check() opens a socket and is OUT-OF-BAND
 * only (a warmer job / cron drain), cachedVerdict() is the request-path read (cache/mirror only, no
 * socket, no breaker). check() never throws — every fault degrades to a fail-open CheckResult. 7.3-clean.
 */
final class Client
{
    /** Bulk local-mirror rows (O1): array of {cidr, verdict, score, ..., expires_at, scored_as}. */
    const MIRROR_KEY = 'mnc:mirror';
    /** Default check window in days when a caller passes no maxAgeInDays. */
    const DEFAULT_MAX_AGE = 90;
    /** Breaker channels: one record per upstream capability, so one capability's outage never opens the other. */
    const CHANNEL_CHECK = 'check';
    const CHANNEL_REPORT = 'report';

    /** @var Config */
    private $config;
    /** @var Transport */
    private $transport;
    /** @var Cache */
    private $cache;
    /** @var Reporter|null lazily built */
    private $reporter;
    /** @var CircuitBreaker|null an injected instance, which then serves every channel */
    private $injectedBreaker;
    /** @var array<string,CircuitBreaker> per-channel breakers, built from Config on first use */
    private $breakers = array();
    /** @var callable():int */
    private $clock;
    /** @var callable(int):int TTL jitter */
    private $jitter;

    /**
     * @param Config              $config
     * @param Transport|null      $transport  CurlTransport default (StreamTransport when curl absent)
     * @param Cache|null          $cache      verdict + breaker store (NullCache default)
     * @param Reporter|null       $reporter   the relocated report engine (built lazily if null)
     * @param CircuitBreaker|null $breaker    one instance for every channel; null = per-channel breakers built from Config over $cache
     * @param callable|null       $clock      callable():int epoch; defaults to time()
     * @param callable|null       $jitter     callable(int):int TTL jitter; defaults to +/-10-20%
     */
    public function __construct(Config $config, ?Transport $transport = null, ?Cache $cache = null, ?Reporter $reporter = null, ?CircuitBreaker $breaker = null, $clock = null, $jitter = null)
    {
        $this->config = $config;
        $this->cache = $cache !== null ? $cache : new NullCache();
        if ($transport !== null) {
            $this->transport = $transport;
        } elseif (function_exists('curl_init')) {
            $this->transport = new CurlTransport($config->timeoutMs());
        } else {
            $this->transport = new StreamTransport($config->timeoutMs());
        }
        $this->reporter = $reporter;
        $this->clock = $clock !== null ? $clock : 'time';
        $this->jitter = $jitter !== null ? $jitter : array($this, 'defaultTtlJitter');
        $this->injectedBreaker = $breaker;
    }

    /**
     * Out-of-band reputation lookup. Opens a socket, so per M5 it MUST NOT run on the request path (call
     * it from a warmer job / cron drain; the request path reads cachedVerdict()). Never throws — a
     * network/HTTP/parse fault degrades to a fail-open CheckResult. Inert unless checkActive().
     *
     * @param string $ip
     * @param array  $opts {maxAgeInDays?:int, verbose?:bool, sensitivity?:string, signals?:array}
     * @return CheckResult
     */
    public function check(string $ip, array $opts = array())
    {
        if (!$this->config->checkActive()) {
            return CheckResult::failOpen();
        }
        $cached = $this->cachedVerdict($ip, $opts);
        if ($cached !== null) {
            return $cached;
        }
        $breaker = $this->breaker(self::CHANNEL_CHECK);
        if (!$breaker->allow()) {
            return CheckResult::failOpen();
        }

        $maxAge = $this->maxAge($opts);
        $sensitivity = $this->sensitivity($opts);
        $query = array(
            'ip' => $ip,
            'max_age_days' => $maxAge,
            'sensitivity' => $sensitivity,
        );
        if (!empty($opts['verbose'])) {
            $query['verbose'] = '1';
        }
        // Optional consumer-computed telemetry rides ONLY this outbound escalation call (T1/T3/T5).
        if (isset($opts['signals']) && is_array($opts['signals']) && $opts['signals'] !== array()) {
            $query['signals'] = json_encode($opts['signals']);
        }
        $url = rtrim($this->config->baseUrl(), '/') . '/v1/check?' . http_build_query($query);
        $headers = array('Key: ' . $this->config->key(), 'Accept: application/json');

        try {
            $res = $this->transport->get($url, $headers);
        } catch (Throwable $e) {
            $breaker->recordTransportFailure();

            return CheckResult::failOpen();
        }

        return $this->handleResponse($res, $ip, $maxAge, $sensitivity);
    }

    /**
     * The request-path read (M5): the already-resolved verdict for $ip, or null on miss. Never opens a
     * socket and never touches the breaker. An IPv6 $ip is normalised to its /64 score_key before the
     * lookup (P2/G2); the local mirror matches range/CIDR/ASN entries by CIDR-containment, most-specific
     * wins (Q2/Q4). Carries NO signals telemetry. Inert (opt-in off / no key) => null.
     *
     * @param string $ip
     * @param array  $opts {maxAgeInDays?:int, sensitivity?:string}
     * @return CheckResult|null
     */
    public function cachedVerdict(string $ip, array $opts = array())
    {
        if (!$this->config->checkActive()) {
            return null;
        }
        $maxAge = $this->maxAge($opts);
        $sensitivity = $this->sensitivity($opts);

        // 1. Warmed verdict entry, keyed by the normalised score_key (the /64 for IPv6).
        $row = $this->cache->get($this->verdictKey($ip, $maxAge, $sensitivity), null);
        if (is_array($row)) {
            return CheckResult::fromArray($row, CheckResult::SOURCE_CACHE);
        }

        // 2. Bulk local mirror (O1): most-specific CIDR-containment match wins.
        $mirror = $this->cache->get(self::MIRROR_KEY, null);
        if (is_array($mirror)) {
            $best = null;
            $bestLen = -1;
            foreach ($mirror as $entry) {
                if (!is_array($entry) || !isset($entry['cidr'])) {
                    continue;
                }
                $len = Net::containment((string) $entry['cidr'], $ip);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $entry;
                }
            }
            if ($best !== null) {
                return CheckResult::fromArray($best, CheckResult::SOURCE_CACHE);
            }
        }

        return null;
    }

    /**
     * Relocated report entry (piece B). Delegates to the Reporter; the background drain POSTs to
     * {base_url}/v1/report. Active only when a key is set (reportActive()).
     *
     * @param string $ip
     * @param string $comment
     * @param string $categories
     * @param array  $signals  OPTIONAL consumer-computed request-shape evidence (forwarded verbatim)
     * @return array {queued:bool, reason:string}
     */
    public function report(string $ip, string $comment, string $categories = '21', array $signals = array())
    {
        $reporter = $this->reporter();
        if ($reporter === null) {
            return array('queued' => false, 'reason' => 'no reporter');
        }

        return $reporter->enqueue($ip, $comment, $categories, $signals);
    }

    /**
     * Deliver queued reports. THIS IS THE OTHER HALF OF report() — without it nothing is ever sent.
     *
     * report() only enqueues, so a consumer that never drains has a queue that grows and delivers
     * nothing, silently. Run this out of band (cron, scheduler tick, worker) and NEVER on the
     * request path: it opens sockets. The drain is wall-clock budgeted and breaker-aware, so a
     * mainnet outage costs one tick, not a pile-up.
     *
     * @param int $limit max rows to attempt this tick
     * @return array {sent:int, failed:int, pending:int}
     */
    public function drain($limit = 200)
    {
        $reporter = $this->reporter();
        if ($reporter === null) {
            return array('sent' => 0, 'failed' => 0, 'pending' => 0, 'reason' => 'no reporter');
        }

        return $reporter->drain($limit);
    }

    /** Rows currently queued for delivery. 0 when reporting is not configured. */
    public function queuedReports()
    {
        $reporter = $this->reporter();

        return $reporter === null ? 0 : (int) $reporter->queueCount();
    }

    /**
     * The breaker guarding one upstream capability. check() records on CHANNEL_CHECK and report delivery
     * on CHANNEL_REPORT, each with its own record, so a report-ingest outage never blinds the check path
     * (the two may be different backends one day). A host delivering reports on its own path (e.g. a
     * queued job) records on breaker(CHANNEL_REPORT) so its outages land where drain() looks. Channels
     * are built lazily from Config; a breaker injected at construction serves every channel instead.
     */
    public function breaker(string $channel = CircuitBreaker::DEFAULT_CHANNEL): CircuitBreaker
    {
        if ($this->injectedBreaker !== null) {
            return $this->injectedBreaker;
        }
        if (!isset($this->breakers[$channel])) {
            $this->breakers[$channel] = new CircuitBreaker(
                $this->cache,
                $this->config->breakerThreshold(),
                $this->config->breakerCooldownSecs(),
                $this->config->quotaParkCapSecs(),
                $this->clock,
                null,
                null,
                $channel,
                $this->config->breakerMaxBackoffSecs()
            );
        }

        return $this->breakers[$channel];
    }

    // --- internals -------------------------------------------------------------------------------

    private function handleResponse(array $res, $ip, $maxAge, $sensitivity)
    {
        $breaker = $this->breaker(self::CHANNEL_CHECK);
        $status = isset($res['status']) ? (int) $res['status'] : 0;
        $body = isset($res['body']) ? (string) $res['body'] : '';
        $headers = isset($res['headers']) && is_array($res['headers']) ? $res['headers'] : array();

        if ($status === 200) {
            $json = json_decode($body, true);
            if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
                $result = $this->parseData($json['data']);
                $breaker->recordSuccess();
                $this->cache->set(
                    $this->verdictKey($ip, $maxAge, $sensitivity),
                    $result->toArray(),
                    $this->ttlFor($result->expiresAt())
                );

                return $result;
            }
            // Malformed 200 (no data object / non-JSON) — treated as a transport-class fault.
            $breaker->recordTransportFailure();

            return CheckResult::failOpen();
        }

        if ($status === 429) {
            $breaker->recordQuota($this->retryAfter($headers), $this->rateLimitReset($headers));

            return CheckResult::failOpen();
        }

        if ($status === 401 || $status === 403 || ($status >= 500 && $status < 600) || $status === 0) {
            $breaker->recordTransportFailure();

            return CheckResult::failOpen();
        }

        if ($status >= 400 && $status < 500) {
            // Client error (e.g. 422 bad IP): the check ran, retrying won't help. No breaker, no cache.
            return new CheckResult(CheckResult::VERDICT_UNKNOWN, null, null, array(), array(), null, null, CheckResult::SOURCE_FRESH);
        }

        // Anything else unexpected (3xx, 2xx non-200) — degrade to fail-open, transport-class.
        $breaker->recordTransportFailure();

        return CheckResult::failOpen();
    }

    private function parseData(array $data)
    {
        $verdict = isset($data['verdict']) ? (string) $data['verdict'] : CheckResult::VERDICT_UNKNOWN;
        $score = isset($data['score']) && $data['score'] !== null ? (int) $data['score'] : null;
        $scoreVersion = isset($data['score_version']) && $data['score_version'] !== null ? (string) $data['score_version'] : null;
        $evidence = isset($data['evidence']) && is_array($data['evidence']) ? $data['evidence'] : array();
        $context = isset($data['context']) && is_array($data['context']) ? $data['context'] : array();
        $expiresAt = isset($data['expires_at']) && $data['expires_at'] !== null ? (string) $data['expires_at'] : null;
        $scoredAs = isset($data['scored_as']) && $data['scored_as'] !== null ? (string) $data['scored_as'] : null;

        return new CheckResult($verdict, $score, $scoreVersion, $evidence, $context, $expiresAt, $scoredAs, CheckResult::SOURCE_FRESH);
    }

    private function verdictKey($ip, $maxAge, $sensitivity)
    {
        return 'mnc:v:' . Net::scoreKey($ip) . ':' . $maxAge . ':' . $sensitivity;
    }

    private function maxAge(array $opts)
    {
        return isset($opts['maxAgeInDays']) ? (int) $opts['maxAgeInDays'] : self::DEFAULT_MAX_AGE;
    }

    private function sensitivity(array $opts)
    {
        return isset($opts['sensitivity']) && $opts['sensitivity'] !== '' ? (string) $opts['sensitivity'] : $this->config->sensitivity();
    }

    /** TTL = jitter(min(expires_at - now, cache_ttl_hours * 3600)); absent/past expires_at => full ceiling. */
    private function ttlFor($expiresAt)
    {
        $ceiling = $this->config->cacheTtlHours() * 3600;
        $base = $ceiling;
        if ($expiresAt !== null && $expiresAt !== '') {
            $ts = strtotime((string) $expiresAt);
            $now = (int) call_user_func($this->clock);
            if ($ts !== false && $ts > $now) {
                $remaining = $ts - $now;
                $base = min($remaining, $ceiling);
            }
        }
        $jittered = (int) call_user_func($this->jitter, (int) $base);

        return max(1, $jittered);
    }

    private function retryAfter(array $headers)
    {
        if (!isset($headers['retry-after'])) {
            return null;
        }
        $h = trim((string) $headers['retry-after']);
        if ($h === '') {
            return null;
        }
        if (ctype_digit($h)) {
            return (int) $h; // delta seconds
        }
        $ts = strtotime($h); // HTTP-date -> convert to a delta
        if ($ts === false) {
            return null;
        }

        return max(0, $ts - (int) call_user_func($this->clock));
    }

    private function rateLimitReset(array $headers)
    {
        if (!isset($headers['x-ratelimit-reset'])) {
            return null;
        }
        $h = trim((string) $headers['x-ratelimit-reset']);

        return ctype_digit($h) ? (int) $h : null; // absolute epoch
    }

    /** +/-10-20% jitter so a fleet caching the same IP does not converge on one absolute expiry. */
    private function defaultTtlJitter($base)
    {
        if ($base <= 1) {
            return max(1, (int) $base);
        }
        $pct = mt_rand(10, 20) / 100;
        $sign = mt_rand(0, 1) === 1 ? 1 : -1;

        return max(1, (int) round($base * (1 + $sign * $pct)));
    }

    private function reporter()
    {
        if ($this->reporter !== null) {
            return $this->reporter;
        }
        // Lazy facade build: only possible when an intel db path is configured and pdo_sqlite is present.
        $path = $this->config->intelDbPath();
        if ($path === '' || !extension_loaded('pdo_sqlite')) {
            return null;
        }
        $this->reporter = new Reporter(
            new PdoSqliteReportQueue($path),
            $this->transport,
            $this->config->baseUrl(),
            $this->config->key(),
            $this->config->selfIps(),
            $this->config->dailyCap(),
            $this->config->dedupHours(),
            $this->breaker(self::CHANNEL_REPORT),
            $this->clock
        );

        return $this->reporter;
    }
}
