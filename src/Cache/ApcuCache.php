<?php

namespace Funnypot\Mainnet\Cache;

/**
 * APCu-backed store for a bare-PHP host with no framework cache: shared memory across every php-fpm
 * worker on the machine, so the verdict cache and the circuit-breaker record cross requests without
 * Redis or a database. Zero dependencies beyond ext-apcu.
 *
 * APCu is commonly compiled in but switched off for the CLI SAPI (apcu.enable_cli=0 is the packaged
 * default), so a cron drain and an fpm request on the same box can disagree about whether it works.
 * Wire it through isUsable(): pick this driver only when true and otherwise inject nothing, so the
 * breaker falls back to its temp-dir marker — the one store both SAPIs can see. A directly-constructed
 * instance on an inert APCu never fatals (it misses and refuses writes), but because it is Persistent
 * the breaker would keep its record here and coordinate nothing; the guard is what makes it correct.
 */
final class ApcuCache implements Cache, Persistent
{
    /** True only when ext-apcu is loaded AND enabled for the current SAPI. */
    public static function isUsable()
    {
        return extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled();
    }

    public function get(string $key, $default = null)
    {
        if (!self::isUsable()) {
            return $default;
        }
        $hit = false;
        $value = apcu_fetch($key, $hit);

        return $hit ? $value : $default;
    }

    public function set(string $key, $value, int $ttlSeconds = 0)
    {
        if (!self::isUsable()) {
            return false;
        }

        return (bool) apcu_store($key, $value, $ttlSeconds > 0 ? $ttlSeconds : 0);
    }

    public function has(string $key)
    {
        return self::isUsable() && (bool) apcu_exists($key);
    }
}
