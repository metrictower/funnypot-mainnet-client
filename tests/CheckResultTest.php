<?php

namespace Funnypot\Mainnet\Tests;

use Funnypot\Mainnet\CheckResult;
use PHPUnit\Framework\TestCase;

final class CheckResultTest extends TestCase
{
    public function test_getters_roundtrip()
    {
        $ev = array('total_reports' => 12, 'distinct_reporters' => 4, 'categories' => array(array('category' => 'ssh_bruteforce', 'count' => 9)));
        $ctx = array('usage_type' => 'hosting', 'asn' => 'AS64500', 'country' => 'US');
        $r = new CheckResult('malicious', 85, '2026-08', $ev, $ctx, '2026-08-20T00:00:00Z', '203.0.113.7/32', CheckResult::SOURCE_FRESH);
        $this->assertSame('malicious', $r->verdict());
        $this->assertSame(85, $r->score());
        $this->assertSame('2026-08', $r->scoreVersion());
        $this->assertSame($ev, $r->evidence());
        $this->assertSame($ctx, $r->context());
        $this->assertSame('2026-08-20T00:00:00Z', $r->expiresAt());
        $this->assertSame('203.0.113.7/32', $r->scoredAs());
        $this->assertSame('fresh', $r->source());
    }

    public function test_derived_booleans_from_verdict()
    {
        $mk = function ($v) {
            return new CheckResult($v, 50, null, array(), array(), null, null, CheckResult::SOURCE_FRESH);
        };
        $this->assertTrue($mk('malicious')->isMalicious());
        $this->assertTrue($mk('critical')->isMalicious());
        $this->assertFalse($mk('suspicious')->isMalicious());
        $this->assertTrue($mk('suspicious')->isSuspicious());
        $this->assertFalse($mk('clean')->isMalicious());
        $this->assertFalse($mk('clean')->isSuspicious());
        $this->assertFalse($mk('unknown')->isMalicious());
        $this->assertFalse($mk('unknown')->isSuspicious());
    }

    public function test_fail_open_is_unknown_and_null()
    {
        $r = CheckResult::failOpen();
        $this->assertTrue($r->isFailOpen());
        $this->assertSame('unknown', $r->verdict());
        $this->assertNull($r->score());
        $this->assertSame(array(), $r->evidence());
        $this->assertSame(array(), $r->context());
        $this->assertNull($r->expiresAt());
        $this->assertNull($r->scoredAs());

        $fresh = new CheckResult('clean', 10, '2026-08', array(), array(), null, '1.2.3.4/32', CheckResult::SOURCE_FRESH);
        $this->assertFalse($fresh->isFailOpen());
    }

    public function test_scored_as_roundtrips()
    {
        $v6 = new CheckResult('malicious', 90, null, array(), array(), null, '2001:db8::/64', CheckResult::SOURCE_FRESH);
        $this->assertSame('2001:db8::/64', $v6->scoredAs());
        $v4 = new CheckResult('malicious', 90, null, array(), array(), null, '198.51.100.7/32', CheckResult::SOURCE_FRESH);
        $this->assertSame('198.51.100.7/32', $v4->scoredAs());
        $this->assertNull(CheckResult::failOpen()->scoredAs());
    }

    public function test_unknown_is_distinct_from_clean()
    {
        $this->assertNotSame(CheckResult::VERDICT_UNKNOWN, CheckResult::VERDICT_CLEAN);
        $unknown = new CheckResult('unknown', null, null, array(), array(), null, null, CheckResult::SOURCE_FRESH);
        $this->assertFalse($unknown->isMalicious());
        $this->assertFalse($unknown->isSuspicious());
    }

    public function test_constants_are_the_wire_values()
    {
        $this->assertSame('fresh', CheckResult::SOURCE_FRESH);
        $this->assertSame('cache', CheckResult::SOURCE_CACHE);
        $this->assertSame('fail-open', CheckResult::SOURCE_FAIL_OPEN);
        $this->assertSame('unknown', CheckResult::VERDICT_UNKNOWN);
        $this->assertSame('clean', CheckResult::VERDICT_CLEAN);
        $this->assertSame('suspicious', CheckResult::VERDICT_SUSPICIOUS);
        $this->assertSame('malicious', CheckResult::VERDICT_MALICIOUS);
        $this->assertSame('critical', CheckResult::VERDICT_CRITICAL);
    }
}
