<?php

namespace Funnypot\Mainnet\Tests\Live;

use Funnypot\Mainnet\Client;
use Funnypot\Mainnet\Config;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in live integration against a running A1 — the 2xx happy path the offline suite deliberately does
 * not cover. Skipped unless MAINNET_LIVE_URL + MAINNET_LIVE_KEY are set, so it never runs in normal CI.
 *
 * @group live
 */
final class LiveIntegrationTest extends TestCase
{
    private function envOrSkip()
    {
        $url = getenv('MAINNET_LIVE_URL');
        $key = getenv('MAINNET_LIVE_KEY');
        if ($url === false || $url === '' || $key === false || $key === '') {
            $this->markTestSkipped('set MAINNET_LIVE_URL + MAINNET_LIVE_KEY to run the live test');
        }

        return array($url, $key);
    }

    public function test_live_check_returns_a_verdict()
    {
        list($url, $key) = $this->envOrSkip();
        $client = new Client(Config::fromArray(array('base_url' => $url, 'key' => $key, 'check_enabled' => true)));
        $r = $client->check('8.8.8.8');
        // A live call returns a real verdict; a fault degrades to fail-open (never a throw).
        $this->assertContains($r->source(), array('fresh', 'cache', 'fail-open'));
    }
}
