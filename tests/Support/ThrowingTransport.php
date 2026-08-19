<?php

namespace Funnypot\Mainnet\Tests\Support;

use Funnypot\Mainnet\Transport\Transport;
use RuntimeException;

/**
 * A transport whose get()/post() throw, to prove Client::check swallows a transport exception (never
 * rethrows) and never surfaces the credential. The message deliberately carries no key.
 */
final class ThrowingTransport implements Transport
{
    public function get(string $url, array $headers)
    {
        throw new RuntimeException('boom while calling ' . $url);
    }

    public function post(string $url, array $headers, string $body)
    {
        throw new RuntimeException('boom while posting ' . $url);
    }
}
