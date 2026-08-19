<?php

namespace Funnypot\Mainnet\Transport;

/**
 * HTTP doer for both capabilities: check (GET) and report (POST). One interface serves both so the
 * relocated reporter and the check path share a single transport.
 *
 * Both methods return an array {status:int, body:string, headers:array<string,string>}. status is 0 on
 * a transport failure/timeout (never an exception — the client degrades to fail-open). headers are
 * lower-cased response header names -> value; they carry retry-after / x-ratelimit-reset so the breaker
 * can park a quota-class 429 to the server reset time. The map is empty on a transport failure.
 */
interface Transport
{
    /**
     * @param string   $url
     * @param string[] $headers  e.g. ['Key: ...','Accept: application/json']
     * @return array {status:int, body:string, headers:array<string,string>}
     */
    public function get(string $url, array $headers);

    /**
     * @param string   $url
     * @param string[] $headers
     * @param string   $body
     * @return array {status:int, body:string, headers:array<string,string>}
     */
    public function post(string $url, array $headers, string $body);
}
