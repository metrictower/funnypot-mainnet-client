<?php

namespace Funnypot\Mainnet\Tests\Support;

/**
 * Advanceable clock for deterministic TTL/breaker/drain-budget tests. Use as `array($clock, 'now')` or
 * a closure `function () use ($clock) { return $clock->now(); }` wherever a callable():int is wanted.
 */
final class FakeClock
{
    /** @var int */
    private $t;

    public function __construct($start = 1000000000)
    {
        $this->t = (int) $start;
    }

    public function now()
    {
        return $this->t;
    }

    public function advance($secs)
    {
        $this->t += (int) $secs;

        return $this;
    }

    public function set($t)
    {
        $this->t = (int) $t;

        return $this;
    }

    /** A callable():int bound to this clock. */
    public function asCallable()
    {
        $self = $this;

        return function () use ($self) {
            return $self->now();
        };
    }
}
