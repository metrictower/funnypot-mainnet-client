<?php

namespace Funnypot\Mainnet\Tests;

use Funnypot\Mainnet\Cache\ArrayCache;
use Funnypot\Mainnet\Cache\Psr16Cache;
use Funnypot\Mainnet\CheckResult;
use Funnypot\Mainnet\CircuitBreaker;
use Funnypot\Mainnet\Client;
use Funnypot\Mainnet\Config;
use Funnypot\Mainnet\Tests\Support\ArrayPsr16;
use Funnypot\Mainnet\Tests\Support\FakeClock;
use Funnypot\Mainnet\Tests\Support\FakeTransport;
use Funnypot\Mainnet\Tests\Support\ThrowingTransport;
use PHPUnit\Framework\TestCase;

final class KeyLeakTest extends TestCase
{
    const KEY = 'super-secret-bearer-abc123';

    /** @var FakeClock */
    private $clock;
    /** @var FakeTransport */
    private $transport;
    /** @var ArrayCache */
    private $cache;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(1000000000);
        $this->transport = new FakeTransport();
        $this->cache = new ArrayCache($this->clock->asCallable());
    }

    private function breaker()
    {
        return new CircuitBreaker(new Psr16Cache(new ArrayPsr16()), 5, 60, 21600, $this->clock->asCallable(), function ($n) {
            return $n;
        });
    }

    private function client($t = null)
    {
        $config = Config::fromArray(array(
            'base_url' => 'https://mainnet.example',
            'key' => self::KEY,
            'check_enabled' => true,
        ));

        return new Client($config, $t !== null ? $t : $this->transport, $this->cache, null, $this->breaker(), $this->clock->asCallable(), function ($n) {
            return $n;
        });
    }

    private function driveAllPaths()
    {
        $body = json_encode(array('data' => array('verdict' => 'malicious', 'score' => 80, 'expires_at' => null, 'scored_as' => '203.0.113.7/32')));
        $this->transport->pushResponse(200, $body);          // fresh
        $this->transport->pushResponse(429, json_encode(array('error' => array('code' => 'quota_exhausted'))), array('retry-after' => '30')); // fail-open
        $client = $this->client();
        $client->check('203.0.113.7'); // fresh + caches
        $client->check('203.0.113.7'); // cache hit
        $client->check('198.51.100.9'); // fail-open (429)

        return $client;
    }

    public function test_key_absent_from_url()
    {
        $this->driveAllPaths();
        foreach ($this->transport->calls as $call) {
            $this->assertStringNotContainsString(self::KEY, $call['url'], 'the key never appears in any request URL');
        }
    }

    public function test_key_present_only_in_key_header()
    {
        $this->driveAllPaths();
        foreach ($this->transport->calls as $call) {
            $inHeader = false;
            foreach ($call['headers'] as $h) {
                if (strpos($h, self::KEY) !== false) {
                    $this->assertStringStartsWith('Key: ', $h, 'the key rides only the Key: header');
                    $inHeader = true;
                }
            }
            $this->assertTrue($inHeader, 'each request carried the key in its Key: header');
        }
    }

    public function test_key_absent_from_cache_keys()
    {
        $this->driveAllPaths();
        foreach ($this->cache->keys() as $k) {
            $this->assertStringNotContainsString(self::KEY, $k);
            $this->assertMatchesRegularExpression('/^mnc:(v:|mirror|breaker)/', $k, 'only mnc:* keys are written');
        }
    }

    public function test_key_absent_from_result()
    {
        $client = $this->driveAllPaths();
        $r = $client->cachedVerdict('203.0.113.7');
        $this->assertNotNull($r);
        $this->assertStringNotContainsString(self::KEY, serialize($r));
        $this->assertStringNotContainsString(self::KEY, serialize($r->toArray()));
    }

    public function test_key_absent_from_exception()
    {
        // A throwing transport must not cause the key to escape; check swallows and returns fail-open.
        $throwClient = new Client(
            Config::fromArray(array('base_url' => 'https://mainnet.example', 'key' => self::KEY, 'check_enabled' => true)),
            new ThrowingTransport(),
            $this->cache,
            null,
            $this->breaker(),
            $this->clock->asCallable(),
            function ($n) {
                return $n;
            }
        );
        $caught = null;
        try {
            $r = $throwClient->check('203.0.113.7');
            $this->assertSame(CheckResult::SOURCE_FAIL_OPEN, $r->source(), 'a transport exception degrades to fail-open, not a throw');
        } catch (\Throwable $e) {
            $caught = $e;
        }
        if ($caught !== null) {
            $this->assertStringNotContainsString(self::KEY, $caught->getMessage());
        }
    }
}
