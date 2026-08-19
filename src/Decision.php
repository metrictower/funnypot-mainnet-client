<?php

namespace Funnypot\Mainnet;

/**
 * The gate's verdict-of-record: an allow/block/challenge action carrying the CheckResult behind it, so a
 * middleware can log why it blocked (verdict/score/source) and a challenge page can show the band
 * without a second call. Immutable value object, 7.3-clean.
 */
final class Decision
{
    const ALLOW     = 'allow';
    const BLOCK     = 'block';
    const CHALLENGE = 'challenge';

    /** @var string */
    private $action;
    /** @var CheckResult */
    private $result;

    private function __construct($action, CheckResult $result)
    {
        $this->action = $action;
        $this->result = $result;
    }

    public static function allow(CheckResult $r)
    {
        return new self(self::ALLOW, $r);
    }

    public static function block(CheckResult $r)
    {
        return new self(self::BLOCK, $r);
    }

    public static function challenge(CheckResult $r)
    {
        return new self(self::CHALLENGE, $r);
    }

    public function action()
    {
        return $this->action;
    }

    public function result()
    {
        return $this->result;
    }

    public function isAllow()
    {
        return $this->action === self::ALLOW;
    }

    public function isBlock()
    {
        return $this->action === self::BLOCK;
    }

    public function isChallenge()
    {
        return $this->action === self::CHALLENGE;
    }
}
