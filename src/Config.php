<?php

namespace Funnypot\Mainnet;

/**
 * All configuration fields + defaults (design §3.5). Built via fromArray() because 7.3 has no named
 * args: an assoc-array builder avoids positional-order misassignment and lets unmapped keys default.
 * Untyped private props (typed properties are 7.4+).
 */
final class Config
{
    /** @var string scheme+host ONLY, no path (MAINNET_BASE_URL) */
    private $baseUrl;
    /** @var string the sole credential (MAINNET_KEY) */
    private $key;
    /** @var bool check opt-in (default false) */
    private $checkEnabled;
    /** @var string 'open' | 'closed' */
    private $failMode;
    /** @var array verdicts that map to block */
    private $blockVerdicts;
    /** @var int|null optional score floor on a block */
    private $minBlockScore;
    /** @var array verdicts that map to challenge (band off when empty) */
    private $challengeVerdicts;
    /** @var string 'strict'|'balanced'|'lenient' band selector */
    private $sensitivity;
    /** @var int verdict TTL ceiling in hours */
    private $cacheTtlHours;
    /** @var int connect+total timeout in ms */
    private $timeoutMs;
    /** @var int consecutive transport faults to trip */
    private $breakerThreshold;
    /** @var int transport-class open duration in secs */
    private $breakerCooldownSecs;
    /** @var int ceiling for a quota-class park in secs */
    private $quotaParkCapSecs;
    /** @var array FUNNYPOT_SELF_IPS; report inert when empty */
    private $selfIps;
    /** @var int report daily cap */
    private $dailyCap;
    /** @var int report per-IP dedup window in hours */
    private $dedupHours;
    /** @var string optional SQLite path for the lazily-built report queue (facade convenience) */
    private $intelDbPath;

    private function __construct()
    {
    }

    /**
     * @param array $opts keys mirror the fields (base_url, key, check_enabled, fail_mode, block_verdicts,
     *                    min_block_score, challenge_verdicts, sensitivity, cache_ttl_hours, timeout_ms,
     *                    breaker_threshold, breaker_cooldown_secs, quota_park_cap_secs, self_ips,
     *                    daily_cap, dedup_hours, intel_db_path)
     * @return self
     */
    public static function fromArray(array $opts)
    {
        $c = new self();
        $c->baseUrl = isset($opts['base_url']) ? (string) $opts['base_url'] : '';
        $c->key = isset($opts['key']) ? (string) $opts['key'] : '';
        $c->checkEnabled = isset($opts['check_enabled']) ? (bool) $opts['check_enabled'] : false;
        $c->failMode = isset($opts['fail_mode']) ? (string) $opts['fail_mode'] : 'open';
        $c->blockVerdicts = isset($opts['block_verdicts']) && is_array($opts['block_verdicts'])
            ? array_values($opts['block_verdicts'])
            : array(CheckResult::VERDICT_MALICIOUS, CheckResult::VERDICT_CRITICAL);
        $c->minBlockScore = array_key_exists('min_block_score', $opts) && $opts['min_block_score'] !== null
            ? (int) $opts['min_block_score']
            : null;
        $c->challengeVerdicts = isset($opts['challenge_verdicts']) && is_array($opts['challenge_verdicts'])
            ? array_values($opts['challenge_verdicts'])
            : array();
        $c->sensitivity = isset($opts['sensitivity']) ? (string) $opts['sensitivity'] : 'balanced';
        $c->cacheTtlHours = isset($opts['cache_ttl_hours']) ? (int) $opts['cache_ttl_hours'] : 12;
        $c->timeoutMs = isset($opts['timeout_ms']) ? (int) $opts['timeout_ms'] : 1500;
        $c->breakerThreshold = isset($opts['breaker_threshold']) ? (int) $opts['breaker_threshold'] : 5;
        $c->breakerCooldownSecs = isset($opts['breaker_cooldown_secs']) ? (int) $opts['breaker_cooldown_secs'] : 60;
        $c->quotaParkCapSecs = isset($opts['quota_park_cap_secs']) ? (int) $opts['quota_park_cap_secs'] : 21600;
        $c->selfIps = isset($opts['self_ips']) && is_array($opts['self_ips']) ? array_values($opts['self_ips']) : array();
        $c->dailyCap = isset($opts['daily_cap']) ? (int) $opts['daily_cap'] : 1000;
        $c->dedupHours = isset($opts['dedup_hours']) ? (int) $opts['dedup_hours'] : 24;
        $c->intelDbPath = isset($opts['intel_db_path']) ? (string) $opts['intel_db_path'] : '';

        return $c;
    }

    public function baseUrl()
    {
        return $this->baseUrl;
    }

    public function key()
    {
        return $this->key;
    }

    public function checkEnabled()
    {
        return $this->checkEnabled;
    }

    public function failMode()
    {
        return $this->failMode;
    }

    public function blockVerdicts()
    {
        return $this->blockVerdicts;
    }

    public function minBlockScore()
    {
        return $this->minBlockScore;
    }

    public function challengeVerdicts()
    {
        return $this->challengeVerdicts;
    }

    public function sensitivity()
    {
        return $this->sensitivity;
    }

    public function cacheTtlHours()
    {
        return $this->cacheTtlHours;
    }

    public function timeoutMs()
    {
        return $this->timeoutMs;
    }

    public function breakerThreshold()
    {
        return $this->breakerThreshold;
    }

    public function breakerCooldownSecs()
    {
        return $this->breakerCooldownSecs;
    }

    public function quotaParkCapSecs()
    {
        return $this->quotaParkCapSecs;
    }

    public function selfIps()
    {
        return $this->selfIps;
    }

    public function dailyCap()
    {
        return $this->dailyCap;
    }

    public function dedupHours()
    {
        return $this->dedupHours;
    }

    public function intelDbPath()
    {
        return $this->intelDbPath;
    }

    /** True when check is allowed to spend a credit: check_enabled AND key !== ''. */
    public function checkActive()
    {
        return $this->checkEnabled === true && $this->key !== '';
    }

    /** True when reporting is allowed: key !== '' (D2 posture, independent of check). */
    public function reportActive()
    {
        return $this->key !== '';
    }
}
