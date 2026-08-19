<?php

namespace Funnypot\Mainnet;

/**
 * Verdict-first reputation result (decision H1). Immutable value object with untyped props + docblocks
 * (typed properties are 7.4+). A fail-open/inert result carries verdict='unknown', score=null, empty
 * evidence/context, expiresAt=null, scoredAs=null — so a caller can never mistake "could not check"
 * (unknown) for "data exists, looks fine" (clean).
 */
final class CheckResult
{
    const SOURCE_FRESH     = 'fresh';      // a live /v1/check answered
    const SOURCE_CACHE     = 'cache';      // served from the verdict cache, no call spent
    const SOURCE_FAIL_OPEN = 'fail-open';  // inert / down / timeout / breaker / 429 — no verdict

    // Verdict enum (H1), least -> most severe. unknown (no meaningful data) is DISTINCT from clean.
    const VERDICT_UNKNOWN    = 'unknown';
    const VERDICT_CLEAN      = 'clean';
    const VERDICT_SUSPICIOUS = 'suspicious';
    const VERDICT_MALICIOUS  = 'malicious';
    const VERDICT_CRITICAL   = 'critical';

    /** @var string one of the VERDICT_* constants */
    private $verdict;
    /** @var int|null 0-100 bounded score; null when unknown/fail-open */
    private $score;
    /** @var string|null A1 score_version, e.g. '2026-08' */
    private $scoreVersion;
    /** @var array aggregate magnitude; [] when unknown */
    private $evidence;
    /** @var array FP-mitigation context; [] when unknown */
    private $context;
    /** @var string|null ISO-8601 validity horizon (cache-TTL hint) */
    private $expiresAt;
    /** @var string|null the CIDR/IP the verdict was computed for (/64 v6, /128 v4) */
    private $scoredAs;
    /** @var string one of the SOURCE_* constants */
    private $source;

    /**
     * @param string      $verdict
     * @param int|null    $score
     * @param string|null $scoreVersion
     * @param array       $evidence
     * @param array       $context
     * @param string|null $expiresAt
     * @param string|null $scoredAs
     * @param string      $source
     */
    public function __construct(string $verdict, $score, $scoreVersion, array $evidence, array $context, $expiresAt, $scoredAs, string $source)
    {
        $this->verdict = $verdict;
        $this->score = $score === null ? null : (int) $score;
        $this->scoreVersion = $scoreVersion === null ? null : (string) $scoreVersion;
        $this->evidence = $evidence;
        $this->context = $context;
        $this->expiresAt = $expiresAt === null ? null : (string) $expiresAt;
        $this->scoredAs = $scoredAs === null ? null : (string) $scoredAs;
        $this->source = $source;
    }

    /** The uniform fail-open/inert result: unknown verdict, null score, no evidence. */
    public static function failOpen()
    {
        return new self(self::VERDICT_UNKNOWN, null, null, array(), array(), null, null, self::SOURCE_FAIL_OPEN);
    }

    public function verdict()
    {
        return $this->verdict;
    }

    public function score()
    {
        return $this->score;
    }

    public function scoreVersion()
    {
        return $this->scoreVersion;
    }

    public function evidence()
    {
        return $this->evidence;
    }

    public function context()
    {
        return $this->context;
    }

    public function expiresAt()
    {
        return $this->expiresAt;
    }

    public function scoredAs()
    {
        return $this->scoredAs;
    }

    public function source()
    {
        return $this->source;
    }

    /** verdict is malicious or critical. */
    public function isMalicious()
    {
        return $this->verdict === self::VERDICT_MALICIOUS || $this->verdict === self::VERDICT_CRITICAL;
    }

    /** verdict is suspicious. */
    public function isSuspicious()
    {
        return $this->verdict === self::VERDICT_SUSPICIOUS;
    }

    /** source is fail-open (could-not-check / inert). */
    public function isFailOpen()
    {
        return $this->source === self::SOURCE_FAIL_OPEN;
    }

    /** Flat snapshot for the verdict cache + local mirror rows. */
    public function toArray()
    {
        return array(
            'verdict' => $this->verdict,
            'score' => $this->score,
            'score_version' => $this->scoreVersion,
            'evidence' => $this->evidence,
            'context' => $this->context,
            'expires_at' => $this->expiresAt,
            'scored_as' => $this->scoredAs,
        );
    }

    /**
     * Rebuild a CheckResult from a stored/mirror row with the given source. Missing keys degrade to the
     * unknown defaults so a thin mirror row ({verdict} only) still yields a usable result.
     *
     * @param array  $row
     * @param string $source
     * @return self
     */
    public static function fromArray(array $row, string $source)
    {
        return new self(
            isset($row['verdict']) ? (string) $row['verdict'] : self::VERDICT_UNKNOWN,
            isset($row['score']) && $row['score'] !== null ? (int) $row['score'] : null,
            isset($row['score_version']) && $row['score_version'] !== null ? (string) $row['score_version'] : null,
            isset($row['evidence']) && is_array($row['evidence']) ? $row['evidence'] : array(),
            isset($row['context']) && is_array($row['context']) ? $row['context'] : array(),
            isset($row['expires_at']) && $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            isset($row['scored_as']) && $row['scored_as'] !== null ? (string) $row['scored_as'] : null,
            $source
        );
    }
}
