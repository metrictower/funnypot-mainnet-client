<?php

namespace Funnypot\Mainnet\Tests;

use Funnypot\Mainnet\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function test_from_array_applies_defaults()
    {
        $c = Config::fromArray(array());
        $this->assertSame('', $c->baseUrl());
        $this->assertSame('', $c->key());
        $this->assertFalse($c->checkEnabled());
        $this->assertSame('open', $c->failMode());
        $this->assertSame(array('malicious', 'critical'), $c->blockVerdicts());
        $this->assertNull($c->minBlockScore());
        $this->assertSame(array(), $c->challengeVerdicts());
        $this->assertSame('balanced', $c->sensitivity());
        $this->assertSame(12, $c->cacheTtlHours());
        $this->assertSame(1500, $c->timeoutMs());
        $this->assertSame(5, $c->breakerThreshold());
        $this->assertSame(60, $c->breakerCooldownSecs());
        $this->assertSame(1800, $c->breakerMaxBackoffSecs());
        $this->assertSame(21600, $c->quotaParkCapSecs());
        $this->assertSame(array(), $c->selfIps());
        $this->assertSame(1000, $c->dailyCap());
        $this->assertSame(24, $c->dedupHours());
    }

    public function test_from_array_maps_keys()
    {
        $c = Config::fromArray(array(
            'base_url' => 'https://mainnet.example',
            'key' => 'abc',
            'check_enabled' => true,
            'fail_mode' => 'closed',
            'block_verdicts' => array('critical'),
            'min_block_score' => 63,
            'challenge_verdicts' => array('suspicious'),
            'sensitivity' => 'strict',
            'cache_ttl_hours' => 6,
            'timeout_ms' => 900,
            'breaker_max_backoff_secs' => 600,
            'self_ips' => array('203.0.113.9'),
        ));
        $this->assertSame('https://mainnet.example', $c->baseUrl());
        $this->assertSame('abc', $c->key());
        $this->assertTrue($c->checkEnabled());
        $this->assertSame('closed', $c->failMode());
        $this->assertSame(array('critical'), $c->blockVerdicts());
        $this->assertSame(63, $c->minBlockScore());
        $this->assertSame(array('suspicious'), $c->challengeVerdicts());
        $this->assertSame('strict', $c->sensitivity());
        $this->assertSame(6, $c->cacheTtlHours());
        $this->assertSame(900, $c->timeoutMs());
        $this->assertSame(600, $c->breakerMaxBackoffSecs());
        $this->assertSame(array('203.0.113.9'), $c->selfIps());

        $lenient = Config::fromArray(array('sensitivity' => 'lenient'));
        $this->assertSame('lenient', $lenient->sensitivity());
    }

    public function test_check_inert_without_flag()
    {
        $c = Config::fromArray(array('key' => 'abc', 'check_enabled' => false));
        $this->assertFalse($c->checkActive());
    }

    public function test_check_inert_without_key()
    {
        $c = Config::fromArray(array('key' => '', 'check_enabled' => true));
        $this->assertFalse($c->checkActive());
    }

    public function test_check_active_needs_both()
    {
        $c = Config::fromArray(array('key' => 'abc', 'check_enabled' => true));
        $this->assertTrue($c->checkActive());
    }

    public function test_report_active_on_key_alone()
    {
        $c = Config::fromArray(array('key' => 'abc', 'check_enabled' => false));
        $this->assertTrue($c->reportActive());

        $none = Config::fromArray(array('key' => ''));
        $this->assertFalse($none->reportActive());
    }
}
