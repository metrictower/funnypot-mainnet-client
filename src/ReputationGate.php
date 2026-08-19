<?php

namespace Funnypot\Mainnet;

/**
 * Turns a verdict into an allow/block/challenge Decision (decision H: the verdict IS the recommendation
 * — no server-sent recommended_action). Keys on the VERDICT, not a raw score.
 *
 * decide() runs Client::check (a socket call) and is OUT-OF-BAND / warmer only (M5). decideCached() is
 * the request-path partner: it reads Client::cachedVerdict (no socket, no breaker) and a miss allows.
 * fail_mode governs ONLY the genuine could-not-check path (source='fail-open'); inert and any completed
 * source='fresh' unknown always allow, under both fail modes (SF-3).
 */
final class ReputationGate
{
    /** @var Client */
    private $client;
    /** @var Config */
    private $config;

    public function __construct(Client $client, Config $config)
    {
        $this->client = $client;
        $this->config = $config;
    }

    /**
     * Out-of-band / warmer only — resolves and caches a verdict, then maps it. NOT the request path.
     *
     * @param string $ip
     * @return Decision
     */
    public function decide(string $ip)
    {
        if (!$this->config->checkActive()) {
            return Decision::allow(CheckResult::failOpen()); // inert carve-out (SF-3)
        }

        return $this->map($this->client->check($ip));
    }

    /**
     * The request-path decision (M5): map Client::cachedVerdict($ip) with no socket and no breaker. A
     * miss allows (the cue to enqueue an out-of-band warm). Inert/miss allow under both fail modes.
     *
     * @param string $ip
     * @return Decision
     */
    public function decideCached(string $ip)
    {
        if (!$this->config->checkActive()) {
            return Decision::allow(CheckResult::failOpen()); // inert carve-out (SF-3)
        }
        $r = $this->client->cachedVerdict($ip);
        if ($r === null) {
            return Decision::allow(CheckResult::failOpen()); // miss -> allow + cue a warm
        }

        return $this->map($r);
    }

    private function map(CheckResult $r)
    {
        $verdict = $r->verdict();

        if ($this->wouldBlock($verdict, $r->score())) {
            return Decision::block($r);
        }
        if (in_array($verdict, $this->config->challengeVerdicts(), true)) {
            return Decision::challenge($r);
        }
        if ($verdict === CheckResult::VERDICT_UNKNOWN && $r->source() === CheckResult::SOURCE_FAIL_OPEN) {
            // Genuine could-not-check: the only place fail_mode applies.
            return $this->config->failMode() === 'closed' ? Decision::block($r) : Decision::allow($r);
        }

        // clean, suspicious-not-in-a-band, or a completed source='fresh' unknown (422 / 200-no-data).
        return Decision::allow($r);
    }

    private function wouldBlock($verdict, $score)
    {
        if (!in_array($verdict, $this->config->blockVerdicts(), true)) {
            return false;
        }
        $floor = $this->config->minBlockScore();
        if ($floor === null) {
            return true;
        }

        return $score !== null && $score >= $floor;
    }
}
