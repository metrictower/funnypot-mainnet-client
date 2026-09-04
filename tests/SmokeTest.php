<?php

namespace Funnypot\Mainnet\Tests;

use Funnypot\Mainnet\Version;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_autoload_and_phpunit_wired()
    {
        $this->assertTrue(true);
        $this->assertSame('0.3.0', Version::VERSION, 'a namespaced Funnypot\\Mainnet class autoloads');
    }
}
