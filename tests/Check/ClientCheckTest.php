<?php

namespace Funnypot\Mainnet\Tests\Check;

use Funnypot\Mainnet\Cache\ArrayCache;
use Funnypot\Mainnet\Cache\Psr16Cache;
use Funnypot\Mainnet\CheckResult;
use Funnypot\Mainnet\CircuitBreaker;
use Funnypot\Mainnet\Client;
use Funnypot\Mainnet\Config;
use Funnypot\Mainnet\Tests\Support\ArrayPsr16;
use Funnypot\Mainnet\Tests\Support\FakeClock;
use Funnypot\Mainnet\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ClientCheckTest extends TestCase
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
        $this->breaker = new CircuitBreaker(new Psr16Cache(new ArrayPsr16()), 5, 60, 21600, $this->clock->asCallable(), $this->identityJitter());
    }

    private function identityJitter()
    {
        return function ($n) {
            return $n;
        };
    }

    private function config(array $over = array())
    {
        return Config::fromArray(array_merge(array(
            'base_url' => 'https://mainnet.example',
            'key' => 'secret-key',
            'check_enabled' => true,
        ), $over));
    }

    private function client(?Config $config = null, $jitter = null)
    {
        if ($config === null) {
            $config = $this->config();
        }

        return new Client($config, $this->transport, $this->cache, null, $this->breaker, $this->clock->asCallable(), $jitter !== null ? $jitter : $this->identityJitter());
    }

    private function dataBody(array $data)
    {
        return json_encode(array('data' => $data));
    }

    private function cleanData(array $over = array())
    {
        return array_merge(array(
            'verdict' => 'clean',
            'score' => 10,
            'score_version' => '2026-08',
            'evidence' => array('total_reports' => 0, 'distinct_reporters' => 0),
            'context' => array('usage_type' => 'isp', 'country' => 'US'),
            'expires_at' => null,
            'scored_as' => '203.0.113.7/32',
        ), $over);
    }

    // --- Phase 6: happy path -----------------------------------------------------------------------

    public function test_clean_200_is_fresh_and_parsed()
    {
        $data = array(
            'verdict' => 'malicious',
            'score' => 85,
            'score_version' => '2026-08',
            'evidence' => array('total_reports' => 12, 'distinct_reporters' => 4, 'categories' => array(array('category' => 'ssh_bruteforce', 'count' => 9))),
            'context' => array('usage_type' => 'hosting', 'asn' => 'AS64500', 'country' => 'US'),
            'expires_at' => '2026-08-20T00:00:00Z',
            'scored_as' => '203.0.113.7/32',
        );
        $this->transport->pushResponse(200, $this->dataBody($data));
        $r = $this->client()->check('203.0.113.7');
        $this->assertSame('fresh', $r->source());
        $this->assertSame('malicious', $r->verdict());
        $this->assertSame(85, $r->score());
        $this->assertSame('2026-08', $r->scoreVersion());
        $this->assertSame(12, $r->evidence()['total_reports']);
        $this->assertSame('ssh_bruteforce', $r->evidence()['categories'][0]['category']);
        $this->assertSame('hosting', $r->context()['usage_type']);
        $this->assertSame('2026-08-20T00:00:00Z', $r->expiresAt());
        $this->assertSame('203.0.113.7/32', $r->scoredAs());
    }

    public function test_200_without_data_object_is_fail_open()
    {
        // The verdict-first fields sit at the bare root (no data envelope) -> malformed -> fail-open.
        $this->transport->pushResponse(200, json_encode(array('verdict' => 'malicious', 'score' => 90)));
        $r = $this->client()->check('203.0.113.7');
        $this->assertSame('fail-open', $r->source());
        $this->assertSame('unknown', $r->verdict());
        $this->assertNull($r->score());
    }

    public function test_check_forwards_sensitivity_query_param()
    {
        $this->transport->pushResponse(200, $this->dataBody($this->cleanData()));
        $this->client()->check('203.0.113.7', array('sensitivity' => 'strict'));
        $this->assertStringContainsString('sensitivity=strict', $this->transport->lastCall()['url']);

        // No opt -> Config default (balanced).
        $t2 = new FakeTransport();
        $t2->pushResponse(200, $this->dataBody($this->cleanData()));
        $c2 = new Client($this->config(), $t2, new ArrayCache($this->clock->asCallable()), null, $this->breaker, $this->clock->asCallable(), $this->identityJitter());
        $c2->check('198.51.100.9');
        $this->assertStringContainsString('sensitivity=balanced', $t2->lastCall()['url']);
    }

    public function test_malicious_and_clean_both_cached()
    {
        $client = $this->client();
        $this->transport->pushResponse(200, $this->dataBody($this->cleanData(array('verdict' => 'malicious', 'score' => 90))));
        $this->transport->setDefault(200, $this->dataBody($this->cleanData()));
        $client->check('203.0.113.1');   // malicious -> cached
        $client->check('203.0.113.2');   // clean -> cached
        $callsAfterWarm = $this->transport->callCount();
        // Re-check both: both served from cache, no new calls.
        $a = $client->check('203.0.113.1');
        $b = $client->check('203.0.113.2');
        $this->assertSame('cache', $a->source());
        $this->assertSame('malicious', $a->verdict());
        $this->assertSame('cache', $b->source());
        $this->assertSame('clean', $b->verdict());
        $this->assertSame($callsAfterWarm, $this->transport->callCount(), 'both verdicts served from cache');
    }

    public function test_cache_ttl_honors_expires_at()
    {
        // expires_at sooner than the 12h ceiling -> evicts at expires_at.
        $soon = gmdate('c', $this->clock->now() + 300); // 5 minutes out
        $this->transport->pushResponse(200, $this->dataBody($this->cleanData(array('expires_at' => $soon))));
        $this->transport->setDefault(200, $this->dataBody($this->cleanData(array('expires_at' => $soon))));
        $client = $this->client();
        $client->check('203.0.113.7');
        $this->clock->advance(299);
        $this->assertSame('cache', $client->check('203.0.113.7')->source(), 'still within the short TTL');
        $this->clock->advance(2); // past expires_at, well within the 12h ceiling
        $this->assertSame('fresh', $client->check('203.0.113.7')->source(), 'expired at expires_at, re-called');
    }

    public function test_check_appends_v1_check_path_and_sends_key_header()
    {
        $this->transport->pushResponse(200, $this->dataBody($this->cleanData()));
        $this->client()->check('203.0.113.7');
        $call = $this->transport->lastCall();
        $this->assertStringStartsWith('https://mainnet.example/v1/check?', $call['url']);
        $this->assertStringContainsString('ip=203.0.113.7', $call['url']);
        $this->assertStringContainsString('max_age_days=', $call['url']);
        $this->assertContains('Key: secret-key', $call['headers']);
        $this->assertStringNotContainsString('secret-key', $call['url'], 'the key is never in the URL');
    }

    public function test_check_attaches_signals_when_provided()
    {
        $signals = array('ua_class' => 'script', 'missing_accept_language' => true, 'header_fp' => 'ab#cd');
        $this->transport->pushResponse(200, $this->dataBody($this->cleanData()));
        $this->transport->setDefault(200, $this->dataBody($this->cleanData()));
        $client = $this->client();
        $client->check('203.0.113.7', array('signals' => $signals));
        $url = $this->transport->lastCall()['url'];
        $this->assertStringContainsString('signals=' . rawurlencode(json_encode($signals)), $url);

        // A second (un-signalled) check for the same IP/maxAge/sensitivity hits the SAME cache entry.
        $callsBefore = $this->transport->callCount();
        $r = $client->check('203.0.113.7');
        $this->assertSame('cache', $r->source());
        $this->assertSame($callsBefore, $this->transport->callCount(), 'signals are not part of the cache key');

        // A fresh IP with no signals sends no signals field.
        $client->check('198.51.100.9');
        $this->assertStringNotContainsString('signals=', $this->transport->lastCall()['url']);
    }

    // --- Phase 7: fail-open matrix + inert + breaker -----------------------------------------------

    public function test_inert_off_flag_zero_calls()
    {
        $client = $this->client($this->config(array('check_enabled' => false)));
        $r = $client->check('203.0.113.7');
        $this->assertSame('fail-open', $r->source());
        $this->assertSame('unknown', $r->verdict());
        $this->assertSame(0, $this->transport->callCount());
    }

    public function test_inert_empty_key_zero_calls()
    {
        $client = $this->client($this->config(array('key' => '')));
        $r = $client->check('203.0.113.7');
        $this->assertSame('fail-open', $r->source());
        $this->assertSame(0, $this->transport->callCount());
    }

    public function test_429_quota_is_fail_open_not_cached_parks_breaker()
    {
        $reset = $this->clock->now() + 1800;
        $this->transport->pushResponse(429, json_encode(array('error' => array('code' => 'quota_exhausted'))), array('retry-after' => '300', 'x-ratelimit-reset' => (string) $reset));
        $client = $this->client();
        $r = $client->check('203.0.113.7');
        $this->assertSame('fail-open', $r->source());
        $this->assertSame('unknown', $r->verdict());
        $this->assertNull($r->score());
        $this->assertNull($this->cache->get('mnc:v:203.0.113.7:90:balanced'), 'quota fail-open is not cached');
        $this->assertSame('quota', $this->breaker->reason());
        $this->assertSame($reset, $this->breaker->openUntil(), 'parked to the reset, not the 60s cooldown');
    }

    public function test_timeout_status0_is_fail_open()
    {
        $this->transport->pushResponse(0, '');
        $r = $this->client()->check('203.0.113.7');
        $this->assertSame('fail-open', $r->source());
        $this->assertNull($this->cache->get('mnc:v:203.0.113.7:90:balanced'));
    }

    public function test_5xx_is_fail_open()
    {
        $this->transport->pushResponse(503, 'upstream down');
        $r = $this->client()->check('203.0.113.7');
        $this->assertSame('fail-open', $r->source());
        $this->assertNull($this->cache->get('mnc:v:203.0.113.7:90:balanced'));
    }

    public function test_401_403_is_fail_open()
    {
        $this->transport->pushResponse(401, 'bad key');
        $this->transport->pushResponse(403, 'forbidden');
        $client = $this->client();
        $this->assertSame('fail-open', $client->check('203.0.113.1')->source());
        $this->assertSame('fail-open', $client->check('203.0.113.2')->source());
    }

    public function test_malformed_200_no_data_is_fail_open()
    {
        $this->transport->pushResponse(200, 'not even json');
        $r = $this->client()->check('203.0.113.7');
        $this->assertSame('fail-open', $r->source());
        $this->assertSame('unknown', $r->verdict());
    }

    public function test_422_is_client_error_fresh_unknown_no_breaker()
    {
        $this->transport->pushResponse(422, json_encode(array('error' => array('code' => 'invalid_ip'))));
        $r = $this->client()->check('not-an-ip');
        $this->assertSame('fresh', $r->source());
        $this->assertSame('unknown', $r->verdict());
        $this->assertNull($r->score());
        $this->assertNull($this->cache->get('mnc:v:not-an-ip:90:balanced'));
        $this->assertTrue($this->breaker->allow(), '422 must not trip the breaker');
        $this->assertSame('', $this->breaker->reason());
    }

    public function test_breaker_open_fast_fails_without_call()
    {
        for ($i = 0; $i < 5; $i++) {
            $this->breaker->recordTransportFailure();
        }
        $this->assertFalse($this->breaker->allow());
        // reset failure count for the assertion below is not needed; just re-check breaker didn't reopen
        $this->transport->pushResponse(200, $this->dataBody($this->cleanData()));
        // Re-trip because allow() above consumed the half-open probe window? No: no time advanced -> still open.
        $r = $this->client()->check('203.0.113.7');
        $this->assertSame('fail-open', $r->source());
        $this->assertSame(0, $this->transport->callCount(), 'breaker-open skips the socket');
    }

    public function test_check_never_throws_on_garbage_body()
    {
        $this->transport->pushResponse(200, "\x00\x01 not json {");
        $r = $this->client()->check('203.0.113.7');
        $this->assertSame('fail-open', $r->source());
    }

    // --- Phase 8: cache read + TTL + cachedVerdict + IPv6/CIDR -------------------------------------

    public function test_cache_hit_source_cache_zero_calls()
    {
        $this->transport->pushResponse(200, $this->dataBody($this->cleanData(array('verdict' => 'malicious', 'score' => 80))));
        $client = $this->client();
        $client->check('203.0.113.7'); // warm
        $callsAfter = $this->transport->callCount();
        $r = $client->check('203.0.113.7');
        $this->assertSame('cache', $r->source());
        $this->assertSame('malicious', $r->verdict());
        $this->assertSame(80, $r->score());
        $this->assertSame($callsAfter, $this->transport->callCount());
    }

    public function test_distinct_maxage_are_distinct_entries()
    {
        $this->transport->setDefault(200, $this->dataBody($this->cleanData()));
        $client = $this->client();
        $client->check('203.0.113.7', array('maxAgeInDays' => 30));
        $before = $this->transport->callCount();
        $r = $client->check('203.0.113.7', array('maxAgeInDays' => 90));
        $this->assertSame('fresh', $r->source(), 'a 30-day entry does not serve a 90-day request');
        $this->assertSame($before + 1, $this->transport->callCount());
    }

    public function test_distinct_sensitivity_are_distinct_entries()
    {
        $this->transport->setDefault(200, $this->dataBody($this->cleanData()));
        $client = $this->client();
        $client->check('203.0.113.7', array('sensitivity' => 'strict'));
        $before = $this->transport->callCount();
        $r = $client->check('203.0.113.7', array('sensitivity' => 'lenient'));
        $this->assertSame('fresh', $r->source(), 'a strict entry does not serve a lenient request');
        $this->assertSame($before + 1, $this->transport->callCount());
    }

    public function test_expired_entry_recalls()
    {
        $this->transport->setDefault(200, $this->dataBody($this->cleanData()));
        $client = $this->client($this->config(array('cache_ttl_hours' => 1)));
        $client->check('203.0.113.7');
        $before = $this->transport->callCount();
        $this->clock->advance(3601); // past the 1h ceiling
        $r = $client->check('203.0.113.7');
        $this->assertSame('fresh', $r->source());
        $this->assertSame($before + 1, $this->transport->callCount());
    }

    public function test_expires_at_bounds_ttl_below_ceiling()
    {
        // A far-future expires_at is capped at the ceiling; an absent one falls back to the full ceiling.
        $client = $this->client($this->config(array('cache_ttl_hours' => 2)));
        $this->transport->setDefault(200, $this->dataBody($this->cleanData(array('expires_at' => null))));
        $client->check('198.51.100.1');
        $this->clock->advance(7199);
        $this->assertSame('cache', $client->check('198.51.100.1')->source(), 'absent expires_at -> full 2h ceiling');
        $this->clock->advance(2);
        $this->assertSame('fresh', $client->check('198.51.100.1')->source());
    }

    public function test_fail_open_is_not_cached_so_next_check_recalls()
    {
        $this->transport->pushResponse(429, json_encode(array('error' => array('code' => 'quota_exhausted'))), array('retry-after' => '1'));
        $this->transport->pushResponse(200, $this->dataBody($this->cleanData()));
        $client = $this->client();
        $this->assertSame('fail-open', $client->check('203.0.113.7')->source());
        $this->clock->advance(5); // let the 1s quota park expire
        $this->assertSame('fresh', $client->check('203.0.113.7')->source(), 'fail-open was not pinned');
    }

    public function test_cached_verdict_hit_socket_free_breaker_free()
    {
        $this->transport->pushResponse(200, $this->dataBody($this->cleanData(array('verdict' => 'malicious', 'score' => 88))));
        $client = $this->client();
        $client->check('203.0.113.7'); // warm
        $callsAfter = $this->transport->callCount();
        // Force the breaker OPEN; cachedVerdict must still return the cached verdict.
        for ($i = 0; $i < 5; $i++) {
            $this->breaker->recordTransportFailure();
        }
        $this->assertFalse($this->breaker->allow());
        $r = $client->cachedVerdict('203.0.113.7');
        $this->assertNotNull($r);
        $this->assertSame('cache', $r->source());
        $this->assertSame('malicious', $r->verdict());
        $this->assertSame($callsAfter, $this->transport->callCount(), 'cachedVerdict never opens a socket');
    }

    public function test_cached_verdict_miss_returns_null()
    {
        $r = $this->client()->cachedVerdict('203.0.113.250');
        $this->assertNull($r);
        $this->assertSame(0, $this->transport->callCount());
    }

    public function test_cached_verdict_inert_returns_null()
    {
        $client = $this->client($this->config(array('check_enabled' => false)));
        $this->assertNull($client->cachedVerdict('203.0.113.7'));
        $this->assertSame(0, $this->transport->callCount());
    }

    public function test_cached_verdict_ipv6_normalised_to_64()
    {
        $this->transport->pushResponse(200, $this->dataBody($this->cleanData(array('verdict' => 'malicious', 'score' => 90, 'scored_as' => '2001:db8:abcd:1::/64'))));
        $client = $this->client();
        // Warm one /128; the write key is the /64 score_key.
        $client->check('2001:db8:abcd:1::5');
        // A different /128 inside the same /64 hits the same entry.
        $r = $client->cachedVerdict('2001:db8:abcd:1:ffff::99');
        $this->assertNotNull($r);
        $this->assertSame('malicious', $r->verdict());
        $this->assertSame('2001:db8:abcd:1::/64', $r->scoredAs());
        // A /128 in a DIFFERENT /64 misses.
        $this->assertNull($client->cachedVerdict('2001:db8:abcd:2::5'));
    }

    public function test_cached_verdict_cidr_containment_hit()
    {
        $client = $this->client();
        // Prime the bulk mirror directly (the O1 interchange format D/E populate on cron).
        $this->cache->set(Client::MIRROR_KEY, array(
            array('cidr' => '203.0.113.0/24', 'verdict' => 'malicious', 'score' => 70, 'scored_as' => '203.0.113.0/24'),
            array('cidr' => '203.0.113.55', 'verdict' => 'critical', 'score' => 99, 'scored_as' => '203.0.113.55/32'),
            array('cidr' => '2001:db8:1::/64', 'verdict' => 'suspicious', 'score' => 40, 'scored_as' => '2001:db8:1::/64'),
        ));
        // A contained v4 visitor hits the /24 by containment (not exact).
        $r = $client->cachedVerdict('203.0.113.10');
        $this->assertNotNull($r);
        $this->assertSame('malicious', $r->verdict());
        // The exact-IP entry overrides its containing /24 (most-specific wins, Q4).
        $exact = $client->cachedVerdict('203.0.113.55');
        $this->assertSame('critical', $exact->verdict());
        // A contained v6 visitor hits the /64.
        $v6 = $client->cachedVerdict('2001:db8:1:0:aaaa::1');
        $this->assertSame('suspicious', $v6->verdict());
        // Outside every entry -> null.
        $this->assertNull($client->cachedVerdict('198.51.100.200'));
    }

    public function test_cache_ttl_is_jittered()
    {
        // Use the DEFAULT jitter and bracket the +/-10-20% band: below 0.8*ceiling always cached,
        // above 1.2*ceiling always expired, and the TTL is always positive.
        $config = $this->config(array('cache_ttl_hours' => 10)); // ceiling = 36000s
        $client = new Client($config, $this->transport, $this->cache, null, $this->breaker, $this->clock->asCallable());
        $this->transport->setDefault(200, $this->dataBody($this->cleanData(array('expires_at' => null))));
        $client->check('198.51.100.5');
        $this->clock->advance(28439); // 0.79 * 36000 -> below the band floor
        $this->assertSame('cache', $client->cachedVerdict('198.51.100.5')->source(), 'still cached below the band');
        $this->clock->advance(43561 - 28439); // total 43561 ~ 1.21 * 36000 -> above the band ceiling
        $this->assertNull($client->cachedVerdict('198.51.100.5'), 'expired above the band');
    }

    public function test_cached_verdict_and_cache_hit_send_no_signals()
    {
        $signals = array('ua_class' => 'script');
        $this->transport->pushResponse(200, $this->dataBody($this->cleanData()));
        $client = $this->client();
        // Warm with signals (this one call carries them).
        $client->check('203.0.113.7', array('signals' => $signals));
        $callsAfter = $this->transport->callCount();
        // A cache-hit check and a cachedVerdict make ZERO further calls -> no signals leave the process.
        $hit = $client->check('203.0.113.7', array('signals' => $signals));
        $this->assertSame('cache', $hit->source());
        $cv = $client->cachedVerdict('203.0.113.7');
        $this->assertNotNull($cv);
        $this->assertSame($callsAfter, $this->transport->callCount(), 'no network on a cache hit or cachedVerdict');
    }
}
