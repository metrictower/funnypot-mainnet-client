<?php

namespace Funnypot\Mainnet\Transport;

/**
 * Stream-context fallback when ext-curl is absent. Generalises the reporter's original POST transport
 * to add a GET variant and parse $http_response_header into the same lower-cased headers map. Never
 * throws on a network failure — returns status 0.
 */
final class StreamTransport implements Transport
{
    /** @var int */
    private $timeoutMs;

    public function __construct($timeoutMs = 1500)
    {
        $this->timeoutMs = (int) $timeoutMs;
    }

    public function get(string $url, array $headers)
    {
        return $this->run('GET', $url, $headers, null);
    }

    public function post(string $url, array $headers, string $body)
    {
        return $this->run('POST', $url, $headers, $body);
    }

    /**
     * @param string      $method
     * @param string      $url
     * @param string[]    $headers
     * @param string|null $body
     * @return array
     */
    private function run($method, $url, array $headers, $body)
    {
        $hdrLines = $headers;
        $http = array(
            'method' => $method,
            'timeout' => max(1, (int) ceil($this->timeoutMs / 1000)),
            'ignore_errors' => true,
        );
        if ($body !== null) {
            $hdrLines[] = 'Content-Type: application/x-www-form-urlencoded';
            $http['content'] = $body;
        }
        $http['header'] = implode("\r\n", $hdrLines);

        $ctx = stream_context_create(array('http' => $http, 'ssl' => array(
            'verify_peer' => true,
            'verify_peer_name' => true,
        )));

        $rawHeaders = array();
        set_error_handler(function () {
            return true;
        });
        $resp = file_get_contents($url, false, $ctx);
        // The magic $http_response_header local is deprecated as of 8.5 (its mere textual mention
        // is a compile-time deprecation). Use the 8.5 replacement where present, else read the
        // magic local indirectly via get_defined_vars() so its name never appears in source.
        if (function_exists('http_get_last_response_headers')) {
            $lastHeaders = http_get_last_response_headers();
            if (is_array($lastHeaders)) {
                $rawHeaders = $lastHeaders;
            }
        } else {
            $defined = get_defined_vars();
            if (isset($defined['http_response_header']) && is_array($defined['http_response_header'])) {
                $rawHeaders = $defined['http_response_header'];
            }
        }
        restore_error_handler();

        $status = 0;
        $respHeaders = array();
        foreach ($rawHeaders as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $status = (int) $m[1];
                // A new status line resets any headers from an earlier (redirect/continue) response.
                $respHeaders = array();
                continue;
            }
            $pos = strpos($h, ':');
            if ($pos !== false) {
                $name = strtolower(trim(substr($h, 0, $pos)));
                $value = trim(substr($h, $pos + 1));
                if ($name !== '') {
                    $respHeaders[$name] = $value;
                }
            }
        }

        return array('status' => $status, 'body' => $resp === false ? '' : (string) $resp, 'headers' => $respHeaders);
    }
}
