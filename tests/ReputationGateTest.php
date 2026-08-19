<?php

namespace Funnypot\Mainnet\Tests;

use Funnypot\Mainnet\Cache\ArrayCache;
use Funnypot\Mainnet\Cache\Psr16Cache;
use Funnypot\Mainnet\CircuitBreaker;
use Funnypot\Mainnet\Client;
use Funnypot\Mainnet\Config;
use Funnypot\Mainnet\ReputationGate;
use Funnypot\Mainnet\Tests\Support\ArrayPsr16;
use Funnypot\Mainnet\Tests\Support\FakeClock;
use Funnypot\Mainnet\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ReputationGateTest extends TestCase
{
    /** @var FakeClock */
    private $clock;
    /** @var FakeTransport */
    private $transport;
    /** @var ArrayCache */
    private $cache;
    /** @var CircuitBreaker */
    private $breaker;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(1000000000);
        $this->transport = new FakeTransport();
        $this->cache = new ArrayCache($this->clock->asCallable());
        $this->breaker = new CircuitBreaker(new Psr16Cache(new ArrayPsr16()), 5, 60, 21600, $this->clock->asCallable(), function ($n) {
            return $n;
        });
    }

    private function config(array $over = array())
    {
        return Config::fromArray(array_merge(array(
            'base_url' => 'https://mainnet.example',
            'key' => 'secret-key',
            'check_enabled' => true,
        ), $over));
    }

    private function gate(Config $config = null)
    {
        if ($config === null) {
            $config = $this->config();
        }
        $client = new Client($config, $this->transport, $this->cache, null, $this->breaker, $this->clock->asCallable(), function ($n) {
            return $n;
        });

        return new ReputationGate($client, $config);
    }

    private function pushVerdict($verdict, $score = 50, array $over = array())
    {
        $data = array_merge(array(
            'verdict' => $verdict,
            'score' => $score,
            'score_version' => '2026-08',
            'evidence' => array(),
            'context' => array(),
            'expires_at' => null,
            'scored_as' => '203.0.113.7/32',
        ), $over);
        $this->transport->pushResponse(200, json_encode(array('data' => $data)));
    }

    public function test_inert_allows()
    {
        $gate = $this->gate($this->config(array('check_enabled' => false)));
        $d = $gate->decide('203.0.113.7');
        $this->assertTrue($d->isAllow());
        $this->assertSame(0, $this->transport->callCount());
    }

    public function test_blocks_on_malicious()
    {
        $this->pushVerdict('malicious', 80);
        $this->assertTrue($this->gate()->decide('203.0.113.7')->isBlock());
    }

    public function test_blocks_on_critical()
    {
        $this->pushVerdict('critical', 95);
        $this->assertTrue($this->gate()->decide('203.0.113.7')->isBlock());
    }

    public function test_allows_on_clean()
    {
        $this->pushVerdict('clean', 5);
        $this->assertTrue($this->gate()->decide('203.0.113.7')->isAllow());
    }

    public function test_min_block_score_override()
    {
        // Below the floor -> allow.
        $this->pushVerdict('malicious', 50);
        $gate = $this->gate($this->config(array('min_block_score' => 70)));
        $this->assertTrue($gate->decide('203.0.113.1')->isAllow(), 'below the floor falls through to allow');

        // At/above the floor -> block.
        $this->pushVerdict('malicious', 75);
        $this->assertTrue($gate->decide('203.0.113.2')->isBlock());

        // With no floor, the same low score still blocks (verdict alone).
        $this->pushVerdict('malicious', 50);
        $this->assertTrue($this->gate()->decide('203.0.113.3')->isBlock());
    }

    public function test_challenge_band_on_verdict()
    {
        $this->pushVerdict('suspicious', 40);
        $withBand = $this->gate($this->config(array('challenge_verdicts' => array('suspicious'))));
        $this->assertTrue($withBand->decide('203.0.113.7')->isChallenge());

        // Band off (default []) -> the same verdict allows.
        $this->pushVerdict('suspicious', 40);
        $this->assertTrue($this->gate()->decide('198.51.100.7')->isAllow());
    }

    public function test_failopen_unknown_allows_under_open()
    {
        $this->transport->pushResponse(0, ''); // timeout -> could-not-check fail-open
        $this->assertTrue($this->gate()->decide('203.0.113.7')->isAllow());
    }

    public function test_failopen_unknown_blocks_under_closed()
    {
        $this->transport->pushResponse(500, 'down'); // 5xx -> could-not-check fail-open
        $gate = $this->gate($this->config(array('fail_mode' => 'closed')));
        $this->assertTrue($gate->decide('203.0.113.7')->isBlock());
    }

    public function test_inert_allows_under_closed()
    {
        $gate = $this->gate($this->config(array('check_enabled' => false, 'fail_mode' => 'closed')));
        $this->assertTrue($gate->decide('203.0.113.7')->isAllow(), 'feature-off never becomes site-off');
        $this->assertSame(0, $this->transport->callCount());
    }

    public function test_422_fresh_unknown_allows_under_closed()
    {
        $this->transport->pushResponse(422, json_encode(array('error' => array('code' => 'invalid_ip'))));
        $gate = $this->gate($this->config(array('fail_mode' => 'closed')));
        $this->assertTrue($gate->decide('not-an-ip')->isAllow(), '422 is a completed check, not could-not-check');
    }

    public function test_fresh_200_unknown_allows_under_closed()
    {
        $this->pushVerdict('unknown', null); // a completed 200 with no meaningful data
        $gate = $this->gate($this->config(array('fail_mode' => 'closed')));
        $this->assertTrue($gate->decide('203.0.113.7')->isAllow());
    }

    public function test_decide_cached_hit_maps_by_verdict_zero_calls()
    {
        // Prime the mirror so cachedVerdict resolves without any warm.
        $this->cache->set(Client::MIRROR_KEY, array(
            array('cidr' => '203.0.113.7/32', 'verdict' => 'malicious', 'score' => 90),
            array('cidr' => '198.51.100.5/32', 'verdict' => 'clean', 'score' => 5),
        ));
        $gate = $this->gate();
        $this->assertTrue($gate->decideCached('203.0.113.7')->isBlock());
        $this->assertTrue($gate->decideCached('198.51.100.5')->isAllow());
        $this->assertSame(0, $this->transport->callCount());
    }

    public function test_decide_cached_miss_allows_both_fail_modes()
    {
        $open = $this->gate();
        $this->assertTrue($open->decideCached('203.0.113.250')->isAllow());
        $closed = $this->gate($this->config(array('fail_mode' => 'closed')));
        $this->assertTrue($closed->decideCached('203.0.113.251')->isAllow(), 'a miss is inert-equivalent');
        $this->assertSame(0, $this->transport->callCount());
    }

    public function test_decide_is_out_of_band_and_caches()
    {
        $this->pushVerdict('malicious', 90);
        $gate = $this->gate();
        $this->assertTrue($gate->decide('203.0.113.7')->isBlock()); // one transport call
        $calls = $this->transport->callCount();
        // The warm populated the cache -> a later request-path decideCached hits with no new call.
        $d = $gate->decideCached('203.0.113.7');
        $this->assertTrue($d->isBlock());
        $this->assertSame($calls, $this->transport->callCount(), 'decideCached is socket-free after the warm');
    }

    public function test_decision_carries_result()
    {
        $this->pushVerdict('malicious', 88);
        $d = $this->gate()->decide('203.0.113.7');
        $this->assertSame('malicious', $d->result()->verdict());
        $this->assertSame(88, $d->result()->score());
        $this->assertSame('fresh', $d->result()->source());
    }
}
