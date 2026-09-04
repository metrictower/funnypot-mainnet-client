# funnypot-mainnet-client

[![Docs](https://img.shields.io/badge/docs-funnypot.org-f46800.svg)](https://funnypot.org/packages/funnypot-mainnet-client/)

> **Not sure you're in the right place?**
> - Want a ready-to-run **honeypot box** to deploy → [funnypot-app](https://github.com/metrictower/funnypot-app)
> - Protecting a **Laravel** app → [funnypot-laravel](https://github.com/metrictower/funnypot-laravel)
> - Protecting a **WordPress** site → [funnypot-wordpress](https://github.com/metrictower/funnypot-wordpress)
> - Detection **and** IP reporting in any PHP app, batteries included → [funnypot](https://github.com/metrictower/funnypot)
> - Embedding the deception/detection **engine** in your own PHP / PSR-15 app → [funnypot-core](https://github.com/metrictower/funnypot-core)
> - Querying / reporting to the **IP-reputation service** from code (the SDK) → funnypot-mainnet-client **← you are here**
> - Building on the low-level **decision/policy engine** → [funnypot-policy](https://github.com/metrictower/funnypot-policy)

The **PHP 7.3+, framework-free SDK** for the funnypot mainnet IP-reputation service — check an IP's
reputation and report abuse over the mainnet `/v1/*` API, with **no runtime dependencies**.

## Install

```bash
composer require metrictower/funnypot-mainnet-client
```

Runs on **PHP 7.3 – 8.5** with no runtime Composer dependencies. `ext-curl` is used when present (a
stream-context transport is the fallback); `ext-pdo_sqlite` is needed only for the bundled report
queue, and any PSR-16 cache can back the verdict/breaker store via `Psr16Cache` (or APCu via
`ApcuCache` on a bare-PHP host).

## The two design rules

- **Opt-in and key-gated.** A fresh install does nothing. Checking needs `check_enabled` *and* a
  `MAINNET_KEY`; reporting needs a key. No key ⇒ every call is inert.
- **Fail-open, never throws.** A timeout, HTTP error, or parse fault degrades to a `fail-open`
  verdict of `unknown` — the SDK never raises and never blocks a request on the service being down.

## Configure

Build a `Config` with `fromArray()` (PHP 7.3 has no named args):

```php
use Funnypot\Mainnet\Config;

$config = Config::fromArray([
    'base_url'      => 'https://mainnet.example',  // scheme + host ONLY, no path
    'key'           => getenv('MAINNET_KEY'),      // the sole credential
    'check_enabled' => true,                       // opt in to reputation checks (default: off)
    // defaults: block_verdicts=['malicious','critical'], fail_mode='open',
    //           sensitivity='balanced', cache_ttl_hours=12, timeout_ms=1500,
    //           breaker_threshold=5, breaker_cooldown_secs=60, breaker_max_backoff_secs=1800
]);
```

`check` is active only when `check_enabled` is true **and** a key is set; `report` is active as soon
as a key is set (independent of `check`).

## Client

```php
use Funnypot\Mainnet\Client;

$client = new Client($config, null, $cache);  // inject a Cache to enable cachedVerdict()/mirror reads
```

| Method | Where | Network | Notes |
|---|---|---|---|
| `check($ip, $opts)` | out-of-band | opens a socket | Never throws. Run from a warmer/cron, **never** on the request path. Consults the circuit breaker; caches the result. |
| `cachedVerdict($ip, $opts)` | request path | none | Reads the verdict cache, then the bulk local mirror; `null` on a miss. No socket, no breaker. |
| `report($ip, $comment, $categories, $signals)` | enqueue | none | Guards + dedups, then **queues** an abuse report. Returns `['queued'=>bool, 'reason'=>string]`. |
| `drain($limit)` | out-of-band | opens sockets | **The other half of `report()`.** Delivers queued rows. Budgeted and breaker-aware. Returns `['sent'=>int,'failed'=>int,'pending'=>int]`. |
| `queuedReports()` | anywhere | none | Rows waiting for delivery. |
| `breaker($channel)` | anywhere | none | The per-channel `CircuitBreaker` (`Client::CHANNEL_CHECK`, `Client::CHANNEL_REPORT`). A host that delivers reports on its own path (not via `drain()`) records on `breaker(Client::CHANNEL_REPORT)` so its outages land where `drain()` looks. |

The `check()` / `cachedVerdict()` split is the load-bearing seam: the request path only ever reads
already-resolved verdicts, so it never waits on the network.

### CheckResult — verdict-first

Both reads return a `CheckResult`. The **verdict is the signal**, and `unknown` (could-not-check) is
deliberately distinct from `clean` (checked, looks fine) — a caller can never confuse the two.

```php
$r = $client->cachedVerdict($ip);        // ?CheckResult  (null on a cache/mirror miss)
if ($r !== null && $r->isMalicious()) {  // verdict is malicious or critical
    // ...
}
```

- `verdict()` — `unknown | clean | suspicious | malicious | critical`
- `score()` — 0–100, or `null` when unknown / fail-open
- `source()` — `fresh | cache | fail-open`
- `isMalicious()`, `isSuspicious()`, `isFailOpen()`, plus `evidence()`, `context()`, `expiresAt()`, `scoredAs()`

## ReputationGate — verdict → allow / block / challenge

`ReputationGate` turns a verdict into a `Decision`. The verdict *is* the recommendation (there is no
server-sent action), and the gate keys on the verdict, not a raw score.

```php
use Funnypot\Mainnet\ReputationGate;

$gate     = new ReputationGate($client, $config);
$decision = $gate->decideCached($ip);   // request path: cachedVerdict(), no socket; a miss ⇒ allow
if ($decision->isBlock()) {
    $why = $decision->result();         // the CheckResult behind it (verdict/score/source) for logging
}
```

- `decide($ip)` — out-of-band/warmer: runs `check()` then maps. **Not** the request path.
- `decideCached($ip)` — request path: maps `cachedVerdict()`; a miss **allows** (and cues an out-of-band warm).
- `block_verdicts` (+ optional `min_block_score`) → block; `challenge_verdicts` → challenge; anything
  else allows. `fail_mode` (`open` / `closed`) governs **only** the genuine could-not-check path.

## Fail-open + the circuit breaker

- **Fail-open everywhere.** A timeout, a 5xx, a 401/403, a malformed 200, or an open breaker all
  return `CheckResult::failOpen()` (`unknown`, no score) — never an exception.
- **Circuit breaker (out-of-band only).** `check()` trips after N consecutive transport faults
  (default 5) and short-circuits to fail-open for a cooldown (default 60s); a `429` parks against
  `Retry-After` / `X-RateLimit-Reset`. `cachedVerdict()` never touches the breaker, so the request
  path is never affected by service trouble.
- **Exponential backoff on a sustained outage.** Each consecutive open — a failed half-open probe, or
  a drain tick hitting its abort budget again — doubles the window: 60s → 120s → 240s → … up to
  `breaker_max_backoff_secs` (default 1800), always ±20% jittered so a fleet never re-probes in
  lockstep. The first open is exactly one cooldown, so a single blip behaves as it always did, and any
  success resets the curve. Set `breaker_max_backoff_secs` equal to `breaker_cooldown_secs` for the
  old flat cooldown.
- **One breaker per channel.** `check()` and report delivery keep separate records
  (`Client::CHANNEL_CHECK` / `Client::CHANNEL_REPORT`), so a struggling report ingest never blinds
  reputation checks, and vice versa. `$client->breaker($channel)` returns them; a `CircuitBreaker`
  injected into the `Client` constructor serves every channel instead (one shared record).

### Breaker state across processes

PHP is shared-nothing, so the outage record has to live somewhere every worker can see:

| Store | When | How |
|---|---|---|
| Framework cache (Redis, Memcached, DB) | Laravel / WordPress hosts | Inject it via `Psr16Cache` — funnypot-laravel and funnypot-wordpress already do. Every worker and queue process shares the record instantly. |
| APCu | Bare-PHP hosts behind php-fpm | `ApcuCache::isUsable() ? new ApcuCache() : null` as the `Client`'s cache. Shared across the fpm pool; opt-in, never auto-selected. |
| Temp-dir marker | Everything else, and any CLI with APCu off | Automatic: with no `Persistent` cache the breaker writes `mnc_breaker_<channel>.json` under `sys_get_temp_dir()`. |

Guard APCu with `isUsable()` rather than constructing it unconditionally: `apcu.enable_cli=0` is the
packaged default, so a cron drain would otherwise hold an inert cache while php-fpm holds a live one,
and the two would stop coordinating. With the guard the CLI side falls through to the marker file. Every
store fault inside the breaker fails open — a broken cache can never block a request.

## Reporting

`report()` is key-gated and self-guarded: it refuses to report the operator's own `self_ips`, reports
only public-routable addresses, dedups per entity, and honours a daily cap. Enqueue is fast and local;
the actual POSTs happen on a budgeted background drain, so a listener/request path never blocks on the
network.

The queue is size-capped (default 10000 rows, oldest dropped first) so an undrained queue cannot grow
without bound — which matters because a scanner sweep is the high-volume case.
`new PdoSqliteReportQueue($path, $cap)` to change it.

### You must arrange delivery

**`report()` never sends. `drain()` sends.** If nothing calls `drain()`, a fully configured install
queues reports forever and delivers none of them, with no error — so wire this up as part of
installing, not later.

There is no genuinely async HTTP in stock PHP without an event loop (fibers are cooperative
coroutines with no I/O of their own; the fire-and-forget socket tricks either fail under TLS or still
block), which is why delivery is a queue plus an out-of-band drain rather than a background send.
The queue is also where the dedup, daily cap and breaker feedback live — a fire-and-forget POST reads
no response, so it could not maintain any of them.

The package ships a CLI so a cron line needs no PHP:

```bash
*/5 * * * * MAINNET_BASE_URL=https://mainnet.example MAINNET_KEY=... \
  MAINNET_DB=/var/lib/funnypot/intel.sqlite \
  /path/to/vendor/bin/funnypot-mainnet-drain >> /var/log/funnypot-drain.log 2>&1
```

Optional: `MAINNET_SELF_IPS` (comma-separated), `MAINNET_DAILY_CAP`, `--limit=N`.

If your app already has a job queue, call `$client->drain()` from a scheduled job instead — that is
what funnypot-laravel does. **Never call it on a request path**: it opens sockets.
