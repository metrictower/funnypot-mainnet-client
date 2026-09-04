<?php

namespace Funnypot\Mainnet\Report;

use Funnypot\Mainnet\CircuitBreaker;
use Funnypot\Mainnet\Transport\Transport;
use Throwable;

/**
 * The single POST + wire-response classification, extracted from Reporter::drain() (FP-0060) so a host
 * with its own delivery queue — funnypot-laravel's queued job, or its sync-driver drain command — shares
 * the exact same protocol handling (2xx / 429-split-by-code / 4xx / 5xx) without needing a ReportQueue.
 * drain() uses this internally too; its behaviour is unchanged.
 *
 * Writes the QUOTA breaker signal itself (matches drain()'s unconditional-on-first-quota-429 behaviour)
 * and the SUCCESS signal on a delivered POST, so every delivery path — not only drain() — closes the
 * report breaker and resets its backoff once the server is answering again. Does NOT write a
 * transport-failure signal — that needs a consecutive-failure count that is loop state, owned by
 * whichever caller has a loop (drain(), or ReportDrainCommand); a caller with no loop makes its own
 * call. 7.3-clean.
 */
final class ReportSender
{
    /** @var Transport */
    private $transport;
    /** @var string */
    private $baseUrl;
    /** @var string */
    private $apiKey;
    /** @var CircuitBreaker|null */
    private $breaker;
    /** @var callable():int */
    private $clock;

    /**
     * @param Transport           $transport
     * @param string              $baseUrl  scheme+host only; appends /v1/report (D1)
     * @param string              $apiKey
     * @param CircuitBreaker|null $breaker  the report-channel breaker (N6)
     * @param callable|null       $clock    callable():int epoch; defaults to time()
     */
    public function __construct(Transport $transport, string $baseUrl, string $apiKey, ?CircuitBreaker $breaker = null, $clock = null)
    {
        $this->transport = $transport;
        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
        $this->breaker = $breaker;
        $this->clock = $clock !== null ? $clock : 'time';
    }

    /**
     * @param array  $row {ip, categories, comment, signals?}
     * @param string $sensorId
     * @return array {delivered:bool, status:string, http_status:int, drop:bool, retry_after:int|null, reset:int|null}
     *   status one of: delivered | duplicate | quota | client_error | transport_error
     */
    public function send(array $row, $sensorId)
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/v1/report';
        $httpStatus = 0;
        $body = '';
        $headers = array();
        try {
            $res = $this->transport->post($endpoint, array('Key: ' . $this->apiKey, 'Accept: application/json'), $this->postBody($row, $sensorId));
            $httpStatus = isset($res['status']) ? (int) $res['status'] : 0;
            $body = isset($res['body']) ? (string) $res['body'] : '';
            $headers = isset($res['headers']) && is_array($res['headers']) ? $res['headers'] : array();
        } catch (Throwable $e) {
            $httpStatus = 0;
        }

        if ($httpStatus >= 200 && $httpStatus < 300) {
            if ($this->breaker !== null) {
                $this->breaker->recordSuccess(); // the server is answering: close + reset the backoff curve
            }

            return array('delivered' => true, 'status' => 'delivered', 'http_status' => $httpStatus, 'drop' => true, 'retry_after' => null, 'reset' => null);
        }

        if ($httpStatus === 429) {
            $code = $this->errorCode($body);
            if ($code === 'duplicate_report') {
                // Not a fault: drop, never loop, breaker untouched (SF-7).
                return array('delivered' => false, 'status' => 'duplicate', 'http_status' => 429, 'drop' => true, 'retry_after' => null, 'reset' => null);
            }
            // quota_exhausted (or an unlabelled 429): park + record quota (SF-7/N2). Row survives.
            $retryAfter = $this->retryAfter($headers);
            $reset = $this->rateLimitReset($headers);
            if ($this->breaker !== null) {
                $this->breaker->recordQuota($retryAfter, $reset);
            }

            return array('delivered' => false, 'status' => 'quota', 'http_status' => 429, 'drop' => false, 'retry_after' => $retryAfter, 'reset' => $reset);
        }

        if ($httpStatus >= 400 && $httpStatus < 500) {
            // Permanent client error (no_report_rights, 422, ...) -> drop.
            return array('delivered' => false, 'status' => 'client_error', 'http_status' => $httpStatus, 'drop' => true, 'retry_after' => null, 'reset' => null);
        }

        // 5xx / transport (status 0): no breaker write here — the caller owns the consecutive-fail count.
        return array('delivered' => false, 'status' => 'transport_error', 'http_status' => $httpStatus, 'drop' => false, 'retry_after' => null, 'reset' => null);
    }

    private function postBody(array $row, $sensorId)
    {
        $fields = array(
            'ip' => isset($row['ip']) ? $row['ip'] : '',
            'categories' => isset($row['categories']) ? $row['categories'] : '',
            'comment' => isset($row['comment']) ? $row['comment'] : '',
            'timestamp' => gmdate('c', $this->now()),
            'sensor_id' => $sensorId,
        );
        if (isset($row['signals']) && $row['signals'] !== '' && $row['signals'] !== null) {
            $fields['signals'] = $row['signals']; // the JSON string, forwarded verbatim (T5)
        }

        return http_build_query($fields);
    }

    private function errorCode($body)
    {
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            return '';
        }
        if (isset($json['error']) && is_array($json['error']) && isset($json['error']['code'])) {
            return (string) $json['error']['code'];
        }
        if (isset($json['code'])) {
            return (string) $json['code'];
        }

        return '';
    }

    private function retryAfter(array $headers)
    {
        if (!isset($headers['retry-after'])) {
            return null;
        }
        $h = trim((string) $headers['retry-after']);
        if ($h === '') {
            return null;
        }
        if (ctype_digit($h)) {
            return (int) $h;
        }
        $ts = strtotime($h);

        return $ts === false ? null : max(0, $ts - $this->now());
    }

    private function rateLimitReset(array $headers)
    {
        if (!isset($headers['x-ratelimit-reset'])) {
            return null;
        }
        $h = trim((string) $headers['x-ratelimit-reset']);

        return ctype_digit($h) ? (int) $h : null;
    }

    private function now()
    {
        return (int) call_user_func($this->clock);
    }
}
