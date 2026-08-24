<?php

namespace Funnypot\Mainnet\Transport;

/**
 * ext-curl transport (the default). Connect + total timeouts from timeout_ms, TLS verification on,
 * 4xx/5xx return a status (no exception), and response headers are captured lower-cased so a
 * quota-class 429's retry-after / x-ratelimit-reset reach the breaker.
 */
final class CurlTransport implements Transport
{
    /** @var int */
    private $timeoutMs;

    public function __construct($timeoutMs = 1500)
    {
        $this->timeoutMs = (int) $timeoutMs;
    }

    public function get(string $url, array $headers)
    {
        return $this->run($url, $headers, null);
    }

    public function post(string $url, array $headers, string $body)
    {
        return $this->run($url, $headers, $body);
    }

    /**
     * @param string      $url
     * @param string[]    $headers
     * @param string|null $body   null => GET, string => POST
     * @return array
     */
    private function run($url, array $headers, $body)
    {
        $respHeaders = array();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $this->timeoutMs);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->timeoutMs);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        // Keep 4xx/5xx as a returned status instead of raising.
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($handle, $line) use (&$respHeaders) {
            $len = strlen($line);
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $name = strtolower(trim(substr($line, 0, $pos)));
                $value = trim(substr($line, $pos + 1));
                if ($name !== '') {
                    $respHeaders[$name] = $value;
                }
            }

            return $len;
        });
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // curl_close is a no-op since 8.0 (handles are GC-freed objects) and deprecated as of 8.5;
        // only call it on 7.x, where it still frees the underlying resource.
        if (\PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($resp === false || $resp === null) {
            $resp = '';
        }

        return array('status' => $status, 'body' => (string) $resp, 'headers' => $respHeaders);
    }
}
