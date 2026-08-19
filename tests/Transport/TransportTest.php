<?php

namespace Funnypot\Mainnet\Tests\Transport;

use Funnypot\Mainnet\Tests\Support\FakeTransport;
use Funnypot\Mainnet\Transport\CurlTransport;
use Funnypot\Mainnet\Transport\StreamTransport;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    public function test_fake_transport_scripts_and_records()
    {
        $t = new FakeTransport();
        $t->pushResponse(200, '{"data":{"verdict":"clean"}}', array('content-type' => 'application/json'));
        $t->pushResponse(429, '{"error":{"code":"quota_exhausted"}}', array('retry-after' => '300', 'x-ratelimit-reset' => '1712345678'));

        $r1 = $t->get('https://mainnet.example/v1/check?ipAddress=1.2.3.4', array('Key: secret', 'Accept: application/json'));
        $this->assertSame(200, $r1['status']);
        $this->assertSame('{"data":{"verdict":"clean"}}', $r1['body']);
        $this->assertSame('application/json', $r1['headers']['content-type']);

        $r2 = $t->get('https://mainnet.example/v1/check?ipAddress=9.9.9.9', array('Key: secret'));
        $this->assertSame(429, $r2['status']);
        $this->assertSame('300', $r2['headers']['retry-after']);
        $this->assertSame('1712345678', $r2['headers']['x-ratelimit-reset']);

        $this->assertSame(2, $t->callCount());
        $this->assertSame('https://mainnet.example/v1/check?ipAddress=1.2.3.4', $t->calls[0]['url']);
        $this->assertSame(array('Key: secret', 'Accept: application/json'), $t->calls[0]['headers']);
    }

    public function test_curl_returns_zero_status_on_unreachable()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('ext-curl not present');
        }
        $t = new CurlTransport(200);
        $res = $t->get('https://127.0.0.1:1/x', array('Accept: application/json'));
        $this->assertIsArray($res);
        $this->assertSame(0, $res['status']);
        $this->assertIsString($res['body']);
    }

    public function test_stream_returns_zero_status_on_unreachable()
    {
        $t = new StreamTransport(200);
        $get = $t->get('https://127.0.0.1:1/x', array('Accept: application/json'));
        $this->assertSame(0, $get['status']);
        $this->assertSame('', $get['body']);

        $post = $t->post('https://127.0.0.1:1/x', array('Accept: application/json'), 'a=b');
        $this->assertSame(0, $post['status']);
        $this->assertSame('', $post['body']);
    }
}
