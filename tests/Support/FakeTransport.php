<?php

namespace Funnypot\Mainnet\Tests\Support;

use Funnypot\Mainnet\Transport\Transport;

/**
 * Test double for Transport. Each test scripts the {status, body, headers} returned for the next
 * get()/post(); the fake records every url + request headers + body it was handed. A queue of scripted
 * responses supports multi-call phases (breaker, TTL). Never touches a socket.
 */
final class FakeTransport implements Transport
{
    /** @var array<int,array> queued scripted responses */
    private $scripted = array();
    /** @var array<int,array> recorded calls: {method,url,headers,body} */
    public $calls = array();
    /** @var array default response when the script queue is empty */
    private $default;
    /** @var callable|null invoked on each get/post (e.g. to advance a clock for budget tests) */
    private $onCall;

    public function __construct()
    {
        $this->default = array('status' => 0, 'body' => '', 'headers' => array());
    }

    /** Register a side effect to run on every get/post (used to advance a clock in drain-budget tests). */
    public function setOnCall($cb)
    {
        $this->onCall = $cb;

        return $this;
    }

    /**
     * Script the next response. Later calls dequeue in FIFO order.
     *
     * @param int    $status
     * @param string $body
     * @param array  $headers  lower-cased header name => value (e.g. retry-after)
     * @return $this
     */
    public function pushResponse($status, $body = '', array $headers = array())
    {
        $this->scripted[] = array('status' => (int) $status, 'body' => (string) $body, 'headers' => $headers);

        return $this;
    }

    /** Set the fallback response returned once the scripted queue is drained. */
    public function setDefault($status, $body = '', array $headers = array())
    {
        $this->default = array('status' => (int) $status, 'body' => (string) $body, 'headers' => $headers);

        return $this;
    }

    public function get(string $url, array $headers)
    {
        return $this->record('GET', $url, $headers, null);
    }

    public function post(string $url, array $headers, string $body)
    {
        return $this->record('POST', $url, $headers, $body);
    }

    public function callCount()
    {
        return count($this->calls);
    }

    public function lastCall()
    {
        return empty($this->calls) ? null : $this->calls[count($this->calls) - 1];
    }

    private function record($method, $url, array $headers, $body)
    {
        $this->calls[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body);
        if ($this->onCall !== null) {
            call_user_func($this->onCall, $method, $url, $headers, $body);
        }
        if (!empty($this->scripted)) {
            return array_shift($this->scripted);
        }

        return $this->default;
    }
}
