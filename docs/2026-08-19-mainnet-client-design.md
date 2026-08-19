# metrictower/mainnet-client · F (mainnet client library) — design spec

**Status:** draft for review · **Date:** 2026-08-19 · **Piece:** F of the funnypot-mainnet program
**Canonical:** [`funnypot-mainnet/docs/2026-08-19-program-decisions.md`](../../funnypot-mainnet/docs/2026-08-19-program-decisions.md) §F (wins over this spec on any conflict)
**Server anchor:** [`funnypot-mainnet/docs/2026-08-19-mainnet-api-design.md`](../../funnypot-mainnet/docs/2026-08-19-mainnet-api-design.md) (A1 — `/v1/check`, `/v1/report`)
**Output model:** native **verdict-first** `/v1/check` schema (decision H) — A1's OpenAPI 3.x spec is the source of truth for the wire shapes; AbuseIPDB wire-parity is **dropped** (H4/H5)
**Historical ref:** [`funnypot-mainnet/docs/abuseipdb-v2-parity-reference.md`](../../funnypot-mainnet/docs/abuseipdb-v2-parity-reference.md) (informational only — compat dropped per H4)
**Reporter origin:** [`funnypot-core/docs/2026-08-19-mainnet-reporting-design.md`](../../funnypot-core/docs/2026-08-19-mainnet-reporting-design.md) (piece B — the code relocated here)

---

## 1. What this is + why standalone

`metrictower/mainnet-client` is the **client library** for the funnypot-mainnet reputation service
(A1). It is the one place any consumer — the honeypot, the WordPress/Laravel extensions, or a future
non-honeypot tool — talks to `{MAINNET_BASE_URL}/v1/*`. It carries two capabilities:

- **Report** (`/v1/report`) — the existing outbound abuse reporter, **relocated wholesale from piece
  B** (`Funnypot\Report\MainnetReporter` → `Funnypot\Mainnet\Reporter`). Report path, body shape,
  `sensor_id`, per-IP dedup, daily cap, and the non-blocking enqueue→drain queue are carried over
  unchanged.
- **Check** (`/v1/check`) — a **new** inbound reputation lookup: given a visitor IP, ask mainnet for
  a **verdict-first** reputation result (`verdict` + bounded `score` + `evidence`/`context` +
  `expires_at`, decision H1) and turn the **verdict** into an allow/block/challenge decision. Per
  **decision M5**, the network `check` is **never on the request path**: a WAF-style middleware reads
  an already-resolved verdict via `Client::cachedVerdict()` (cache/mirror only, no socket, §3.2), and
  the network `check()`/`ReputationGate::decide()` run **out-of-band** (a warmer job / cron drain) to
  populate that cache. See the §5 request-path invariant.

**A1 is the server and is NOT this package.** A1 stays a deployed Laravel SaaS. This is the tiny
framework-free library its clients embed.

### Why a standalone package (not folded into funnypot-core)

The check capability has consumers that must **not** drag in the honeypot engine:

```
                         ┌──────────────────────────────┐
                         │ metrictower/mainnet-client    │   PHP >= 7.3, framework-free
                         │  Client(check+report)         │   curl/streams transport
                         │  ReputationGate / Reporter    │   PSR-16 cache seam (injected)
                         └───────────────┬───────────────┘
              requires + re-exports      │              requires directly (no engine)
        ┌────────────────────────────────┼───────────────────────────────┐
        ▼                                 ▼                               ▼
 ┌──────────────────┐            (transitively via core)        ┌────────────────────────┐
 │ funnypot-core    │            ┌──────────────┐               │ non-honeypot consumers  │
 │ (engine)         │            │ honeypot-wp  │ (D)           │  blocklist-agent        │
 │  re-exports      │◄───────────┤ honeypot-lar │ (E)           │  firewall / TI feeds    │
 │  mainnet-client  │            └──────────────┘               │  (future)               │
 └────────┬─────────┘                                           └────────────────────────┘
          │ requires
          ▼
   funnypot app
```

- **funnypot-core `requires` it and re-exports** — the engine keeps its `Funnypot\` surface and pulls
  the reporter/check in transitively. D and E depend on core and therefore get mainnet-client for
  free.
- **Non-honeypot consumers depend on it directly, without the engine.** A firewall feed or a
  standalone blocklist agent wants IP reputation and reporting but has no use for nuclei-inversion,
  the SSH server, or the rules engine. Folding the client into funnypot-core would force every such
  consumer to vendor the whole honeypot. Standalone is the only shape that serves both.
- **It is also the cheap IP-reputation first-gate** for the CRS-WAF idea and the report-suppression
  seam noted in `IDEAS.md` — both want a reputation *modifier* without the engine. Per M5 the gate is
  consumed cache-first (`cachedVerdict()`), never as an inline network call — reputation is a
  cache-first modifier/tiebreaker in the request path, never a synchronous lookup.

### Who consumes it, with and without core

| Consumer | Depends via | Uses |
|---|---|---|
| funnypot app | funnypot-core (re-export) | `Reporter` (report); `cachedVerdict()` in-path, `check()`/`decide()` out-of-band (M5) |
| honeypot-wordpress (D) | core, transitively | `Reporter` + `cachedVerdict()` in-path + a `decide()` warmer/cron; injects WP transients as cache/mirror |
| honeypot-laravel (E) | core, transitively | `Reporter` + `cachedVerdict()` in-path + a `decide()` warmer job; injects Laravel `Cache` |
| blocklist-agent / firewall feeds (future) | **mainnet-client directly** | `Client::check` / `ReputationGate` (batch/out-of-band) + `cachedVerdict()` |

The request-path/out-of-band split is the M5 invariant (§5): every consumer reads a resolved verdict
from cache/mirror in-path and lets the network `check`/`decide` run out of band.

## 2. Package layout

```
mainnet-client/
├── composer.json
├── src/
│   ├── Client.php                 Funnypot\Mainnet\Client         (check + report entry)
│   ├── CheckResult.php            value object (verdict/score/score_version/evidence/context/expires_at/source)
│   ├── ReputationGate.php         decide(ip) -> Decision
│   ├── Decision.php               value object (allow|block|challenge + CheckResult)
│   ├── Config.php                 all fields + defaults (§3.5)
│   ├── CircuitBreaker.php         decision-N breaker: shared marker (REQUIRED) + filemtime fallback,
│   │                              transport vs quota fault classes, single-flight half-open
│   ├── Cache/
│   │   ├── Cache.php              our PSR-16-shaped seam (interface)
│   │   ├── ArrayCache.php         in-process default / test double
│   │   ├── NullCache.php          no-op (checks always fresh)
│   │   └── Psr16Cache.php         adapter wrapping a psr/simple-cache instance
│   ├── Transport/
│   │   ├── Transport.php          get()/post() -> {status,body}
│   │   ├── CurlTransport.php      ext-curl (default)
│   │   └── StreamTransport.php    stream-context fallback
│   └── Report/                    RELOCATED from funnypot-core/src/Report/
│       ├── Reporter.php           was Funnypot\Report\MainnetReporter
│       ├── ReportQueue.php        contract (push/take/dedup/daily/sensorId)
│       └── PdoSqliteReportQueue.php
└── tests/                         PHPUnit, host-run, no network
```

### composer.json (shape)

```json
{
  "name": "metrictower/mainnet-client",
  "description": "Client library for the funnypot-mainnet IP-reputation service (report + check).",
  "type": "library",
  "license": "proprietary",
  "require": {
    "php": ">=7.3"
  },
  "require-dev": {
    "phpunit/phpunit": "^9.5"
  },
  "suggest": {
    "ext-curl": "Preferred HTTP transport; falls back to stream contexts when absent.",
    "ext-pdo_sqlite": "For the bundled PdoSqliteReportQueue (report path only).",
    "psr/simple-cache": "Inject any PSR-16 cache as the verdict/breaker store via Psr16Cache."
  },
  "autoload": {
    "psr-4": { "Funnypot\\Mainnet\\": "src/" }
  },
  "autoload-dev": {
    "psr-4": { "Funnypot\\Mainnet\\Tests\\": "tests/" }
  }
}
```

- **PHP `>=7.3` from birth** (WP hosts on 7.x must run it) — 7.3-clean throughout: no constructor
  promotion, no enums, no `match`, no nullsafe `?->`, no `??=`, no typed properties, no union types.
  Scalar/array/nullable **parameter and return** types (`?int`, `?array`, `bool`, `void`, class names)
  are 7.3-legal and are used.
- **Zero hard runtime deps.** `psr/simple-cache` is a `suggest`, never a `require` — the package
  defines its own tiny `Cache` seam and ships an `Psr16Cache` adapter so a consumer *can* hand us a
  PSR-16 instance, but the library imposes no interface package on a 7.3 host that lacks one.
- `ext-curl` and `ext-pdo_sqlite` are suggests (the transport degrades to streams; the SQLite queue
  is report-path only, and D/E bind their own queue).
- PSR-4 root `Funnypot\Mainnet\ → src/`, tests a separate autoload-dev root (mirrors M14).

## 3. Public API

All signatures are 7.3-legal. Value objects use **untyped properties + docblocks** (typed properties
are 7.4+); constructors and methods keep scalar/array/nullable parameter and return types.

### 3.1 `Client` — check + report entry

```php
namespace Funnypot\Mainnet;

final class Client
{
    /**
     * @param Config    $config
     * @param Transport\Transport $transport   HTTP doer (CurlTransport default, Stream fallback)
     * @param Cache\Cache $cache               verdict + breaker store (NullCache default)
     * @param Reporter|null $reporter          the relocated report engine (built lazily if null)
     */
    public function __construct(
        Config $config,
        Transport $transport = null,
        Cache $cache = null,
        Reporter $reporter = null
    ) { /* assign to untyped props */ }

    /**
     * Look up one IP's reputation. **Out-of-band only** — this opens a socket, so per M5 it MUST NOT
     * be called on the request path (call it from a warmer job / cron drain; the request path reads
     * cachedVerdict()). On a clean 200 it writes the verdict to cache so a later cachedVerdict() hits.
     * GET {base_url}/v1/check?ipAddress=...&maxAgeInDays=...[&sensitivity=...][&verbose][&signals=...].
     * Inert (returns a fail-open CheckResult) unless check_enabled AND a non-empty key are set.
     * Never throws for a network/HTTP/parse fault — those degrade to source='fail-open'.
     *
     * @param string $ip
     * @param array  $opts  {maxAgeInDays?:int, verbose?:bool, sensitivity?:string, signals?:array}
     *                      sensitivity is one of 'strict'|'balanced'|'lenient' (K4): it selects the
     *                      band thresholds over the ONE calibrated score — NOT a model choice. Omitted
     *                      => Config's sensitivity default (§3.5). Passed straight through as the
     *                      `sensitivity` query param; A1 owns the banding.
     *                      signals is an OPTIONAL request-shape telemetry object the CONSUMER computes
     *                      (funnypot-core.classify / the policy adapter — never this client, S1/T5) and
     *                      hands in: missing-header flags, self-consistency flags, UA class, the
     *                      digit-stripped header fingerprint, a local anomaly summary. When present it is
     *                      attached VERBATIM to this outbound escalation check as low-trust OBSERVATIONAL
     *                      telemetry (T1/T2); the client neither computes nor validates it. Opt-in — sent
     *                      only when the consumer supplies it (T4). It rides ONLY a check that actually
     *                      reaches mainnet (a cache miss); a cache hit sends nothing, and cachedVerdict()
     *                      (§3.2) carries NO signals (mirror/cache read, no network, T3). NOT part of the
     *                      verdict cache key — telemetry riding the request, never a verdict discriminant.
     * @return CheckResult
     */
    public function check(string $ip, array $opts = []);

    /**
     * The request-path read: return the already-resolved verdict for $ip, or null if none is cached.
     * **Never opens a socket and never touches the breaker** — cache/mirror read only. This is the
     * load-bearing "never a synchronous network call in the request path" seam (M5); a null return is
     * the consumer's cue to allow now and enqueue an out-of-band warm (Client::check via a job).
     * Reads the same cache entries check() writes (mnc:v:{ip}:{maxAge}:{sensitivity}); the O1
     * local-mirror pull (§3.6) can back this so a mirror-listed IP resolves without any per-IP call.
     * An IPv6 $ip is normalised to its /64 score_key BEFORE the lookup (P2/G2) so the key agrees with the
     * server's /64 aggregation, and the local-mirror lookup matches range/CIDR/ASN entries by
     * CIDR-containment / prefix-match (Q2), not exact IP — a visitor inside a listed /64 (or /24, or ASN)
     * hits; most-specific match wins (Q4).
     * Inert (opt-in off / no key) => null. The cache-key scheme stays PRIVATE behind this method —
     * ReputationInterface adapters (D/E) MUST call cachedVerdict(), never re-derive the key themselves.
     * Carries NO signals telemetry: this is a mirror/cache read with no network, so the S/T request-shape
     * signals ride ONLY the out-of-band escalation check() that reaches mainnet, never this read (T3).
     *
     * @param string $ip
     * @param array  $opts  {maxAgeInDays?:int, sensitivity?:string} — must match the warm's opts so the
     *                      key aligns; omitted keys take Config defaults.
     * @return CheckResult|null  null on miss (no cached/mirrored verdict); never a fail-open placeholder
     */
    public function cachedVerdict(string $ip, array $opts = []);

    /**
     * Relocated report entry (piece B). Delegates to the Reporter — enqueue a report row if it passes
     * the guards; the background drain POSTs it to {base_url}/v1/report. Report path is unchanged
     * from B (sensor_id, per-IP dedup, daily cap, self-IP guard). Active only when a key is set.
     *
     * @param array $signals  OPTIONAL request-shape signals object the CONSUMER computed (S1/T5) — the
     *                         same shape check() carries. When non-empty it is persisted on the queued
     *                         row and attached to the drain's /v1/report POST body as report evidence
     *                         (T1); the client never computes it. Opt-in — omitted entirely when empty (T4).
     * @return array  {queued:bool, reason:string}
     */
    public function report(string $ip, string $comment, string $categories = '21', array $signals = []);
}
```

Reporting keeps **its own D2 posture** (active when `MAINNET_KEY` is set); it does **not** require
`check_enabled`. Check requires the opt-in (§4.1). Splitting the two gates is deliberate — an
operator reports by default but only checks when they choose to spend check credits.

### 3.2 `CheckResult` — value object

```php
namespace Funnypot\Mainnet;

final class CheckResult
{
    const SOURCE_FRESH     = 'fresh';      // a live /v1/check answered
    const SOURCE_CACHE     = 'cache';      // served from the verdict cache, no call spent
    const SOURCE_FAIL_OPEN = 'fail-open';  // inert / down / timeout / breaker / 429 — no verdict

    // Verdict enum (H1). `unknown` (no meaningful data) is DISTINCT from `clean` (data exists,
    // looks fine) — never conflate them. Ordered least→most severe.
    const VERDICT_UNKNOWN    = 'unknown';
    const VERDICT_CLEAN      = 'clean';
    const VERDICT_SUSPICIOUS = 'suspicious';
    const VERDICT_MALICIOUS  = 'malicious';
    const VERDICT_CRITICAL   = 'critical';

    /**
     * The H1 verdict-first schema. A1 owns the verdict threshold (H2 rubric + hysteresis); this
     * value object only carries what /v1/check returned.
     *
     * @param string      $verdict       one of the VERDICT_* constants; 'unknown' on fail-open/inert
     * @param int|null    $score         0-100 bounded score (H3); null when unknown/fail-open
     * @param string|null $scoreVersion  A1 score_version, e.g. '2026-08'; null when unknown
     * @param array       $evidence      magnitude (H1): {total_reports, distinct_reporters,
     *                                    reports_last_30d, first_seen, last_seen,
     *                                    categories:[{category, count, last_seen}]}; [] when unknown
     * @param array       $context       FP mitigation (H1): {usage_type, shared, allowlisted, asn,
     *                                    country}; [] when unknown
     * @param string|null $expiresAt     ISO-8601 validity horizon (H1); a cache-TTL hint; null when unknown
     * @param string|null $scoredAs      the CIDR/IP the verdict was computed for (P3): the /64 for IPv6,
     *                                    the /128 for IPv4; null when unknown/fail-open. Tells a consumer
     *                                    the verdict is range-level (a /64) rather than host-level.
     * @param string      $source        one of the SOURCE_* constants
     */
    public function __construct(
        string $verdict, $score, $scoreVersion, array $evidence, array $context, $expiresAt, $scoredAs, string $source
    ) { }

    public function verdict();        // string, a VERDICT_* value
    public function score();          // ?int  (0-100, H3)
    public function scoreVersion();   // ?string
    public function evidence();       // array (raw magnitude; [] when unknown)
    public function context();        // array (usage_type/shared/allowlisted/asn/country; [] when unknown)
    public function expiresAt();      // ?string ISO-8601 (cache-TTL hint)
    public function scoredAs();       // ?string  the CIDR/IP the verdict was computed for (P3: /64 v6, /128 v4)
    public function source();         // string, a SOURCE_* value
    // Convenience booleans DERIVED from verdict (H1) — dead-simple integration is "block if isMalicious()".
    public function isMalicious();    // bool  (verdict === malicious || verdict === critical)
    public function isSuspicious();   // bool  (verdict === suspicious)
    public function isFailOpen();     // bool  ($source === SOURCE_FAIL_OPEN)
}
```

A `fail-open` result carries `verdict = 'unknown'` (and `score = null`, `scored_as = null`) — the caller cannot mistake
"we could not check" for `clean` ("data exists, looks fine"). H1 makes `unknown` a first-class,
distinct verdict for exactly this reason. `ReputationGate` (§3.3) is what turns a verdict into an
allow/block per `block_verdicts` and the fail policy.

### 3.3 `ReputationGate` — decide(ip) -> Decision

```php
namespace Funnypot\Mainnet;

final class ReputationGate
{
    public function __construct(Client $client, Config $config) { }

    /**
     * **Out-of-band / warmer only — NOT the request path (M5).** Runs Client::check (a socket call),
     * then maps the VERDICT -> action against block_verdicts and the fail policy (H: the verdict IS
     * the recommendation — no server-sent recommended_action). Use it from a warmer job / cron drain
     * to resolve-and-cache a verdict; a request-path consumer calls decideCached() instead. Mapping:
     * fail_mode governs ONLY the uncertain (verdict=unknown / fail-open) path; a real verdict is always
     * mapped directly.
     *  - inert (opt-in off / no key)              -> Decision::allow (fail-open result, verdict=unknown)
     *  - verdict in block_verdicts                -> Decision::block
     *        (if min_block_score is set: block only when score is also >= min_block_score — the
     *         optional score-threshold override for the sophisticated path)
     *  - verdict in challenge_verdicts            -> Decision::challenge  (only if the band is set)
     *  - verdict === 'clean' (or suspicious not in a band) -> Decision::allow
     *  - verdict === 'unknown' AND source === 'fail-open' (could-not-check) -> fail_mode=open ? allow :
     *        block  (the default open policy NEVER blocks on uncertainty)
     *  - verdict === 'unknown' AND source === 'fresh' (422 bad-IP, or a completed 200 with no data) ->
     *        Decision::allow ALWAYS (SF-3 — the check ran; fail_mode does not apply to a completed check)
     * @return Decision
     */
    public function decide(string $ip);

    /**
     * The request-path decision (M5): map Client::cachedVerdict($ip) -> Decision with **no socket and
     * no breaker**. A cache/mirror HIT maps by the same verdict rules as decide(); a MISS returns
     * Decision::allow (fail-open, verdict=unknown) — the caller allows the request now and SHOULD
     * enqueue an out-of-band warm (a decide()/check job) so a subsequent request for the same IP hits.
     * fail_mode's inert/422 carve-outs (§4.3) apply: a miss/inert always allows regardless of fail_mode.
     * @return Decision
     */
    public function decideCached(string $ip);
}
```

### 3.4 `Decision` — value object

```php
namespace Funnypot\Mainnet;

final class Decision
{
    const ALLOW     = 'allow';
    const BLOCK     = 'block';
    const CHALLENGE = 'challenge';

    public static function allow(CheckResult $r);      // self
    public static function block(CheckResult $r);      // self
    public static function challenge(CheckResult $r);  // self

    public function action();     // string, an ALLOW|BLOCK|CHALLENGE constant
    public function result();     // CheckResult (the evidence behind the action)
    public function isAllow();    // bool
    public function isBlock();    // bool
    public function isChallenge();// bool
}
```

Carrying the `CheckResult` on the `Decision` lets the middleware log *why* it blocked (score, source)
and lets a challenge page show the score band without a second call.

### 3.5 `Config` — all fields + defaults (from decision F)

```php
namespace Funnypot\Mainnet;

final class Config
{
    /** @var string */ private $baseUrl;            // MAINNET_BASE_URL — scheme+host ONLY, no path
    /** @var string */ private $key;                // MAINNET_KEY — the sole credential
    /** @var bool   */ private $checkEnabled;       // default false  (check opt-in)
    /** @var string */ private $failMode;           // 'open' | 'closed', default 'open'
    // --- verdict-first block policy (H) ---
    /** @var array  */ private $blockVerdicts;      // verdict in this set -> block; default ['malicious','critical']
    /** @var int|null*/ private $minBlockScore;     // OPTIONAL score floor on a block; default null (verdict alone)
    /** @var array  */ private $challengeVerdicts;  // verdict in this set -> challenge; default [] (band off)
    /** @var string */ private $sensitivity;        // 'strict'|'balanced'|'lenient' band selector (K4); default 'balanced'
    /** @var int    */ private $cacheTtlHours;      // verdict TTL CEILING; default 12 (expires_at may shorten it; TTL jittered ±10-20%, §4.5)
    /** @var int    */ private $timeoutMs;          // connect+total; default 1500
    /** @var int    */ private $breakerThreshold;   // consecutive TRANSPORT faults to trip; default 5 (N2; quota trips on 1)
    /** @var int    */ private $breakerCooldownSecs;// transport-class open duration; default 60 (N canonical; ±20% jitter on write, N2)
    /** @var int    */ private $quotaParkCapSecs;   // ceiling for a quota-class park; default 21600 (6h, N2)
    // --- report path (carried from B) ---
    /** @var array  */ private $selfIps;            // FUNNYPOT_SELF_IPS; report inert when empty
    /** @var int    */ private $dailyCap;           // default 1000
    /** @var int    */ private $dedupHours;         // default 24

    /**
     * 7.3 has no named args (M15). Build from an assoc array so consumers are not pinned to positional
     * order and unmapped keys take defaults. A1's positional-order footgun is avoided here.
     * @param array $opts  keys mirror the fields above (base_url, key, check_enabled, fail_mode,
     *                     block_verdicts, min_block_score, challenge_verdicts, sensitivity,
     *                     cache_ttl_hours, ...)
     */
    public static function fromArray(array $opts);   // self

    // getters: baseUrl(), key(), checkEnabled(), failMode(), blockVerdicts(), minBlockScore(),
    //          challengeVerdicts(), sensitivity(), cacheTtlHours(), timeoutMs(), breakerThreshold(),
    //          breakerCooldownSecs(), quotaParkCapSecs(), selfIps(), dailyCap(), dedupHours()

    /** True when check is allowed to spend a credit: check_enabled AND key !== ''. */
    public function checkActive();   // bool
    /** True when reporting is allowed: key !== ''  (D2 posture, independent of check). */
    public function reportActive();  // bool
}
```

**Verdict-first block policy (H).** The gate keys on the **verdict**, not a raw score threshold:
`block_verdicts` defaults to `['malicious', 'critical']` — the "block if malicious or critical"
integration H1 calls dead-simple. `min_block_score` is an **optional** score floor for a sophisticated
consumer who wants the bounded 0–100 score (H3) as an *additional* gate on top of the verdict (block
only when the verdict blocks **and** `score >= min_block_score`); left `null`, the verdict alone
decides. `challenge_verdicts` (default `[]` = band off) opts a middle tier of verdicts (typically
`['suspicious']`) into `Decision::challenge`. This replaces F's earlier score-only `block_threshold` /
`challenge_threshold` — the verdict is the headline the consumer configures against, per H's supersede
of the "block on a number" posture.

**Sensitivity (K4) — band selector, NOT a model choice.** `sensitivity` (`strict|balanced|lenient`,
default `balanced`) is forwarded to A1 as the `sensitivity` query param on `/v1/check`. It selects
which **band thresholds** A1 applies over the **one** calibrated score (K4: scoring is one published
model behind a clean seam — the client never picks a model, only how aggressively that single score is
banded). `strict` bands more IPs as `malicious`/`critical` (fewer misses, more false positives);
`lenient` the reverse; `balanced` is A1's default rubric. A per-call `opts['sensitivity']` overrides
`Config`'s default for that lookup. The verdict A1 returns already reflects the requested sensitivity,
so `ReputationGate` still keys on the verdict unchanged — sensitivity only shifts where the bands sit,
never how the client interprets a verdict.

Canonical env mapping (D1): `base_url` ← `MAINNET_BASE_URL` (host only), `key` ← `MAINNET_KEY`. The
consumer (app/WP/Laravel) reads env/settings and calls `Config::fromArray([...])`; the framework may
wrap these in its own config keys (Laravel `config('funnypot.mainnet.*')`, a WP option) but the
underlying names are fixed.

### 3.6 `Cache` — the PSR-16-style seam

```php
namespace Funnypot\Mainnet\Cache;

interface Cache
{
    /** @return mixed  the stored value, or $default on miss */
    public function get(string $key, $default = null);
    /** @param int $ttlSeconds  0 = no expiry */
    public function set(string $key, $value, int $ttlSeconds = 0);   // bool
    public function has(string $key);                                // bool
}
```

- **`ArrayCache`** — in-process map; the test double and a valid single-process store. **Per-process
  only** — it does not survive a request, so with it the cross-request verdict cache and the breaker's
  cache path are **inert** (the breaker falls back to its filemtime marker, N1).
- **`NullCache`** — no-op; `get` always misses, so every check is `fresh` (no caching). It is the
  package's **injected default** when a consumer passes no cache; like `ArrayCache` it is non-persistent,
  so the breaker uses the filemtime fallback (N1).
- **`Psr16Cache`** — adapter wrapping a `Psr\SimpleCache\CacheInterface` so consumers inject WP
  object-cache/transients, Laravel `Cache`, or the app's SQLite/file cache with one line. This is the
  **same seam** the report-suppression idea (`IDEAS.md`) would reuse.

The cache stores **two kinds of key**: verdict entries (`mnc:v:{ip}:{maxAge}:{sensitivity}`) and the
breaker state (`mnc:breaker`). Sharing one injected store means a WP host gets cross-request breaker state for free
(the object cache is shared), the same property the app's SQLite-backed breaker has across php-fpm.

**Persistent shared cache is REQUIRED for check-enabled operation (decision N1).** ArrayCache and
NullCache are per-process; with either, breaker state (and cross-request verdict caching) would die with
the request, so the breaker would never accumulate faults or open. Two consequences, both settled by
decision N: (1) check-enabled production MUST inject a persistent cross-request cache (WP
object-cache/transients, Laravel `Cache`, the app's SQLite/file) — this is a stated requirement, not a
nice-to-have; (2) when no shared cache is injected, the breaker falls back to a **filemtime marker in
`sys_get_temp_dir()`** (JSON body = the same `{failures, until, reason}` record) so even a cache-less
consumer shares outage state across requests. This resolves the earlier §2/§3.1 ArrayCache-vs-NullCache
default ambiguity: the injected default is NullCache, and neither in-process cache backs the breaker —
the filemtime marker does.

**Local-mirror-lite is the PRIMARY fresh-read (decision O1).** The v1 fleet-read model is **not**
per-IP `check()`. Each D/E/WAF install pulls the **thin blacklist artifact** (G3, `{ip, verdict,
expires_at}` rows) on cron — CDN-served, `ETag`/`304`, ~24 pulls/day, quota-cheap — into the policy's
local **StateStore mirror**. `cachedVerdict()` (§3.2) reads that mirror **cache-first** on the request
path, so a mirror-listed IP resolves with **no per-IP call at all**. Per-IP `check()` is the
**escalation path for *uncertain* IPs only** — an IP not resolved by the mirror, warmed out-of-band
(§4.1), never inline. This turns fleet growth into CDN egress rather than origin QPS and gives the G3
artifact its consumer. The `Cache`/store seam therefore MUST NOT assume **per-IP-only** lookups:
`cachedVerdict()`/`ReputationGate` read whatever the injected store (verdict cache OR bulk mirror)
resolves. Mirror population (the cron pull + StateStore write) lives in D/E's policy adapter, not in
this package; F only requires the seam read the mirror the same way it reads a warmed verdict entry.

**IPv6 /64 normalisation + CIDR-containment matching (P2/Q2).** Because the server aggregates the scored
entity at the /64 for IPv6 (G2) and lists ranges/ASNs as first-class entries (Q1), the client MUST (a)
normalise an IPv6 visitor to its /64 `score_key` before the mirror lookup so the key agrees with the
server's aggregation, and (b) match by **CIDR-containment / prefix-match** — a visitor IP falling inside a
listed /64, /24, or ASN entry is a hit — never exact-IP-only (Q2). **Most-specific match wins** (an
exact-IP entry overrides a containing range for that IP, Q4). Because the mirror is a range table, one
/24 row covers 256 addresses and the mirror stays small. F only derives the visitor's `score_key` and
tests containment; the range/ASN rows themselves are the thin G3 artifact D/E pull (their cron), and
auto-rollup + range-allowlist tuning (Q3/Q4) are A1/ScoringModel concerns, not F.

**Forward-compat — `/v1/changes` delta upgrade (decision I, reserved; NOT built in v1).** The same
mirror seam later upgrades from full-artifact pulls to an incremental **`/v1/changes?since=<cursor>`**
delta feed (decision I) — poll/webhook/pub-sub, transport-pluggable — keyed by `score_key` (G2). That
is not implemented here; O1's full-artifact pull is the v1 mechanism and `/v1/changes` is its
delta-upgrade of the identical seam. No per-IP-only assumption is baked in, so neither foreclosed.

### 3.7 `Transport` — get + post

```php
namespace Funnypot\Mainnet\Transport;

interface Transport
{
    /**
     * @param string[] $headers  e.g. ['Key: ...','Accept: application/json']
     * @return array  {status:int, body:string, headers:array<string,string>}   status 0 on
     *                transport failure/timeout. `headers` are lower-cased response header names →
     *                value; it carries `retry-after` / `x-ratelimit-reset` so the breaker can park a
     *                quota-class 429 to the server reset time (N2). Empty on a transport failure.
     */
    public function get(string $url, array $headers);
    /** @return array {status:int, body:string, headers:array<string,string>} */
    public function post(string $url, array $headers, string $body);
}
```

- **`CurlTransport`** (default) — ext-curl, `CURLOPT_CONNECTTIMEOUT_MS` + `CURLOPT_TIMEOUT_MS` from
  `timeout_ms`, `SSL_VERIFYPEER/HOST` on, returns the status even on 4xx/5xx (no exception), and
  **captures response headers** (a `CURLOPT_HEADERFUNCTION` collecting lower-cased name→value) so a
  quota-class 429's `retry-after`/`x-ratelimit-reset` reach the breaker.
- **`StreamTransport`** — stream-context fallback lifting B's `StreamReportTransport` verbatim
  (`ignore_errors`, hard timeout, parse `$http_response_header`) plus a GET variant for check, and
  parsing `$http_response_header` into the same lower-cased `headers` map. Both 7.3-safe. This
  **generalizes B's `ReportTransport::post`** to add `get` + header capture; B's post body semantics are
  unchanged.

## 4. Behavior / state machines

### 4.1 The opt-in + key gate (check only)

```
Client::check(ip) / ReputationGate::decide(ip)
        │
        ▼
  config.checkActive()  ==  (check_enabled == true) AND (key != '')
        │
   false│ ── INERT ──► CheckResult(verdict='unknown', score=null, source='fail-open')
        │              ReputationGate -> Decision::allow    (never spends a credit, never calls out)
   true │
        ▼           proceed to cache/breaker/transport (4.2)
```

Checking sends the visitor IP to a third party and spends the operator's check credits, so it is
**off unless the operator explicitly turns it on and supplies a key** (§5). Reporting uses the
separate `reportActive()` gate (key alone, D2). Missing the flag with a key present → check stays
inert; missing the key with the flag on → check stays inert.

**Request-path vs out-of-band (M5).** The flows below (`check`/`decide`) open a socket and are
**out-of-band only** — a warmer job or cron drain that resolves a verdict and writes it to cache. The
**request path** calls `cachedVerdict()` / `decideCached()`, which read the cache/mirror with **no
socket and no breaker** (§3.2): a HIT maps by verdict, a MISS (or inert) allows now and is the cue to
enqueue an out-of-band warm. So an outage — slow mainnet, breaker open, no key — can never add latency
to or block a real request; the worst case is a `cachedVerdict()` miss that fails open.

### 4.2 Check request flow (when active)

```
active check(ip)
  │
  ▼  cache.get(mnc:v:{ip}:{maxAge}:{sensitivity})
  ├─ HIT ─────────────────────────────► CheckResult(source='cache')          [spends no credit, 0 latency]
  │
  ▼  MISS
  breaker.allow()?
  ├─ NO (open) ──────────────────────► CheckResult(source='fail-open')       [fast-fail, no call]
  │
  ▼  YES
  transport.get(base+/v1/check?...&sensitivity=...[&signals=...], timeout=timeout_ms)   [signals attached VERBATIM only when the consumer supplied opts['signals'] — telemetry on the escalation call, T1/T5]
  ├─ status 200 + valid JSON ─────────► read the top-level `data` envelope, then parse
  │                                       verdict/score/evidence/context/expires_at/scored_as from data{} ->
  │                                       CheckResult(source='fresh'); breaker.recordSuccess();
  │                                       cache.set(verdict, ttl=jitter(min(expires_at-now, cache_ttl_hours*3600)))
  │                                                                                    [caches every verdict incl. 'clean'/'unknown-with-data']
  ├─ status 429 code=quota_exhausted ─► breaker.recordQuota(Retry-After/X-RateLimit-Reset); CheckResult(source='fail-open')  [NOT cached]
  │                                       (QUOTA class: trip OPEN immediately, park until the reset time, N2)
  ├─ status 0 (timeout/transport)  ───► breaker.recordTransportFailure(); CheckResult(source='fail-open')  [NOT cached]
  ├─ status 5xx ─────────────────────► breaker.recordTransportFailure(); CheckResult(source='fail-open')  [NOT cached]
  ├─ status 401/403 (bad/again key) ──► breaker.recordTransportFailure(); CheckResult(source='fail-open')  [NOT cached]
  └─ status 4xx (e.g. 422 bad ip) ────► CheckResult(verdict='unknown', source='fresh', score=null)  [client error, no breaker trip, no cache]
```

- **Every `/v1` success body is wrapped in a top-level `{ "data": … }` envelope (A1 standard).** A
  clean `200` from `/v1/check` is `{ "data": { verdict, score, score_version, evidence, context,
  expires_at, scored_as } }` — the parser reads `json['data']` first and pulls the verdict-first fields from
  **inside** `data{}` (never from the top level). A `200` whose body has no `data` object is treated
  as a malformed body → fail-open/`unknown` (defensive; do not read fields off the bare root).
- **Fail-open is the whole point:** any state that is not a clean `200` returns `source='fail-open'`
  with `verdict = 'unknown'` and `score = null`. The site never goes down because mainnet is slow, out
  of credits, or gone.
- **429 is a QUOTA-class trip on the check path, and F reads its retry headers (SF-7 / N2).** A
  `/v1/check` `429` carries `code=quota_exhausted` (A1's Error-envelope `code`, SF-7); F reads
  `Retry-After` and `X-RateLimit-Reset` and parks the breaker OPEN until that reset time (capped at
  `quota_park_cap_secs`, 6h) — **not** the 60 s transport cooldown. This kills the all-day
  30 s-probe storm the old "retry on a short cooldown" behavior produced against an hours-long quota
  window. (The `code=duplicate_report` 429 only ever occurs on the **report** path, §4.7 — it is not a
  fault: the drain drops/parks the row, never trips the breaker.)
- **Only a clean `200` is cached** — **every** returned verdict is cached (a `malicious`/`critical`
  block verdict, a `clean` one, an `unknown`-with-data one), so a repeat visitor spends no credit
  either way. The TTL is `min(expires_at − now, cache_ttl_hours)` (§4.5) — `expires_at` (H1) is a
  perishability hint that can only *shorten* the ceiling. Fail-open results are **never** cached (we
  must re-try mainnet next time, not pin an `unknown` for 12h).
- **`422`/malformed-IP** is a *client* error, not a mainnet fault — it returns `fresh` with
  `verdict = 'unknown'`, a null score, and does **not** trip the breaker (retrying won't help; the IP
  is simply invalid).
- **Optional `signals` telemetry rides the outbound check only (T1/T3/T5).** When the consumer passes
  `opts['signals']` (a request-shape object it computed — S1: core.classify / the policy adapter, never
  this client), the client attaches it VERBATIM to this `/v1/check` request as low-trust OBSERVATIONAL
  telemetry (T2). It rides ONLY a call that actually reaches mainnet — a **cache hit sends nothing**, and
  `cachedVerdict()` never carries it (no network, T3) — so signals travel with the escalation check while
  the O1 mirror keeps mirror-hits local, bounding telemetry volume. Signals are **not** part of the
  verdict cache key and never change the returned verdict; A1 async-queues them off the read path, never
  the abuse score, never a synchronous write (T2/T3). Opt-in: the field is omitted entirely when absent.

### 4.3 Fail-open vs fail-closed

`fail_mode` decides what an uncertain result (`source='fail-open'`, `verdict='unknown'`) means at the
gate:

| `fail_mode` | uncertain (verdict=unknown) → Decision | intended deployment |
|---|---|---|
| `open` (default) | `allow` | every normal site — availability first, never block on uncertainty, never self-inflict an outage |
| `closed` | `block` | high-security deployments that would rather 503 a real visitor than admit an unknown |

`fail_mode` governs ONLY the **could-not-check** path — a `verdict === 'unknown'` whose
`source === 'fail-open'` (breaker open, timeout/status 0, 5xx, 429, 401/403, malformed body). An
actionable verdict (`clean`/`suspicious`/`malicious`/`critical`) is always mapped by
`block_verdicts`/`challenge_verdicts` regardless of `fail_mode`. This is why H makes `unknown` distinct
from `clean`: `clean` always allows, but a **could-not-check** `unknown` is the operator's fail-policy
knob.

**SF-3 carve-outs — `fail_mode=closed` governs ONLY genuine could-not-check states.** The gate keys the
fail-policy on **`source`, not on `verdict='unknown'` alone**. Two `unknown` states are **not**
could-not-check (the check either did not run or ran to completion) and therefore **always allow, even
under `fail_mode=closed`**:
- **Inert** (opt-in off / no key / `cachedVerdict()` miss) — the feature is *off*, not *failing*. The
  gate returns allow before any check runs. Fail-closing here would turn "reputation disabled / not yet
  warmed" into a total self-inflicted outage the moment an operator toggles the feature off for
  maintenance or rotates/typos a key. Feature-off must never become site-off.
- **A completed check that returned `unknown` (`source === 'fresh'`)** — either a `422` bad-IP (a
  *client* error: the IP could not be normalized; retrying won't help) or a 200 whose answer is "no
  meaningful data." The check *succeeded*; there was no failure to check, so `fail_mode` does not apply.
  Blocking a visitor because the client failed to parse its own IP, or because an IP is simply unknown
  to a *reachable* mainnet, is never what `fail_mode=closed` (a resilience knob for outages) is for.

So `fail_mode=closed` maps to `block` only when `source === 'fail-open'` (breaker open, timeout/status
0, 5xx, 429, 401/403, malformed 200 body). Inert and any `source === 'fresh'` result allow under
**both** fail modes. (This is a required test, §6.)

### 4.4 Circuit breaker (canonical decision-N spec)

The breaker is the **global fail-open cooldown** of decision N (owner: F; consumed by the report drain
and, out-of-band, by D/E/app). It reuses the `Funnypot\App\Llm\CircuitBreaker` *idea* (trip → open for
a cooldown → fast-fail while open; **fail-open if its own store is unreadable — the breaker must never
be the thing that breaks**) but is rewritten 7.3-clean to decision N's full contract below. It is not a
single threshold-and-cooldown any more.

**N1 — one shared marker, REQUIRED.** State is a single record
`mnc:breaker → {failures:int, until:epoch, reason:'transport'|'quota'}` in the injected persistent
`Cache`. **Check-enabled operation REQUIRES a persistent cross-request cache** (§3.6): with a
per-process cache (ArrayCache/NullCache) breaker state dies with the request, failures never
accumulate, and the breaker never opens. When no shared cache is injected, the breaker falls back to a
**filemtime marker in `sys_get_temp_dir()`** (file contents = the same JSON record) so even a
cache-less consumer shares outage state across requests. An absent/evicted marker is treated as
**CLOSED** (never blocks; worst case is re-discovery of the outage).

**N2 — two fault classes, two clocks.**
- **Transport class** (timeout / status 0, 5xx, 401/403, malformed 200 body): `recordTransportFailure()`
  does `failures += 1`; at `breaker_threshold = 5` consecutive faults → OPEN for
  `breaker_cooldown_secs = 60` (**canonical — supersedes the earlier 30**), with **±20% jitter applied
  on write** so a fleet sharing one outage does not re-probe in lockstep.
- **Quota class** (`429` with `code=quota_exhausted`): `recordQuota()` trips OPEN **immediately** (no
  threshold — one authoritative signal suffices), `until = max(Retry-After, X-RateLimit-Reset) + jitter`,
  **capped at `quota_park_cap_secs = 6h`** (defensive against a bad header). **Reading those two 429
  headers is in scope for v1** — the earlier "reads the status, not the headers" is amended to "and the
  retry headers on a 429".
- `429` with `code=duplicate_report` is **not a fault** (report path only): no breaker effect; the
  drain drops the row or re-queues it once past the 15-min bucket, never loops.

```
CLOSED  ── recordTransportFailure × breaker_threshold ─► OPEN(reason=transport, until=now+60s±20%)
CLOSED  ── recordQuota(reset)                         ─► OPEN(reason=quota, until=min(reset, now+6h)+jitter)
CLOSED  ── recordSuccess                              ─► failures = 0
OPEN    ── allow() while now < until                  ─► false   (skip the socket → instant fail-open)
OPEN    ── now >= until  (half-open, single-flight)   ─► the FIRST caller CAS-extends `until` by one
                                                          cooldown and probes ALONE; everyone else keeps
                                                          failing open; probe success → CLOSED,
                                                          failure → OPEN for another cooldown
```

**N3 — while OPEN.** Every check fast-fails `CheckResult(source='fail-open', verdict='unknown')` with
**zero socket work**; the report drain consults the same marker before its first POST and **skips the
tick** while OPEN (shared outage discovery across the check and report paths). `ReputationGate` maps the
fail-open per `fail_mode` — and per §4.3/SF-3, `fail_mode=closed` applies **only** to genuine
could-not-check states, never to inert/422 (those always allow).

**N4 — half-open is single-flight.** At expiry the **first** `allow()` atomically extends `until` by one
cooldown (CAS/`add` on the store; the filemtime fallback `touch`es the file) and that caller alone
probes; everyone else keeps fast-failing open. Probe success → CLOSED (`failures = 0`); probe failure →
OPEN for another cooldown. This kills the herd of concurrent 1.5 s probes at every cooldown boundary.

**N5 — the breaker never breaks.** Every store read/write fault inside the breaker is swallowed and
treated as **CLOSED / allow** (fail-open). Under `fail_mode=open` the breaker's output is always allow;
under `closed` it is the operator's explicit uncertainty policy, subject to N3's inert/422 carve-outs.

**N6 — drain-side budget** (spec'd in the Reporter, §4.7): a report drain tick has a wall-clock budget
(`10s`) and aborts after `3` consecutive transport-class failures, writing the shared `mnc:breaker`
marker so the next tick and the check path fast-skip; re-queued rows carry attempts/age caps and the
queue has a hard size cap (oldest dropped first).

**Canonical numbers (stated once):** transport threshold `5`, transport cooldown `60s ±20%`, quota park
= server reset time (cap `6h`), drain budget `10s` / `3` consecutive failures, drain limit `200`/tick.
The `Config` fields carry these defaults (§3.5); any per-consumer deviation must be an explicit note.

### 4.5 Cache read/write + TTL

- **Key:** `mnc:v:{normalized-ip}:{maxAgeInDays}:{sensitivity}` — `maxAgeInDays` is in the key because
  A1's score is window-dependent (a 30-day and a 90-day verdict for the same IP are distinct entries),
  and `sensitivity` is in the key because it changes the *verdict* A1 bands over that score (K4), so a
  `strict` and a `lenient` result for the same IP must not collide.
- **Write:** only on a clean `200`. TTL = **`jitter(min(expires_at − now, cache_ttl_hours * 3600))`** —
  the response's `expires_at` (H1: "reputation is perishable, tells consumers how long to cache") can
  only *shorten* the operator's `cache_ttl_hours` ceiling, never extend past it. When `expires_at` is
  absent or already in the past, fall back to the full `cache_ttl_hours` ceiling (a past/absent horizon
  must not produce a zero/negative TTL). **`expires_at` is an absolute server timestamp**, so a fleet
  caching the same IP converges on the same expiry instant → a synchronized origin stampede when they
  all re-check; F therefore applies **±10–20% jitter** to the derived TTL (minor: expires_at-herd fix,
  a client-side complement to A1 jittering `expires_at` server-side). **Every** verdict is written
  (block, `clean`, and fresh `unknown`).
- **Read:** a hit returns `source='cache'` with the stored verdict/score/evidence/context — no call, no
  latency, no credit.
- **TTL trade-off:** longer TTL = fewer credits + faster, but staler reputation. `cache_ttl_hours` is
  the operator's config ceiling; `expires_at` is A1's per-verdict freshness hint under it. Default
  ceiling 12h. Honoring `expires_at` means a short-lived `critical` (e.g. a fast-delisted IP, H2) is
  not pinned stale for the full 12h.

### 4.6 Failure-mode summary (what each returns)

| Condition | `.source` | `.verdict` | `.score` | cached? | breaker | Gate (`fail_mode=open`) | Gate (`fail_mode=closed`) |
|---|---|---|---|---|---|---|---|
| check inert (opt-in off / no key) | fail-open | unknown | null | no | untouched | allow | **allow** (SF-3 carve-out — feature-off ≠ site-off) |
| cache hit | cache | stored | stored | (already) | untouched | by verdict | by verdict |
| breaker open | fail-open | unknown | null | no | — | allow | block |
| clean/actionable 200 | fresh | verdict from A1 | 0–100 | yes (jittered min of expires_at, ttl) | success | by verdict | by verdict |
| 200 unknown (no data) | fresh | unknown | null/0 | yes | success | allow | **allow** (SF-3 — the check completed; `source='fresh'`, not could-not-check) |
| 429 `code=quota_exhausted` | fail-open | unknown | null | no | **quota trip (park to reset, N2)** | allow | block |
| timeout / transport (status 0) | fail-open | unknown | null | no | transport failure | allow | block |
| 5xx | fail-open | unknown | null | no | transport failure | allow | block |
| 401/403 auth | fail-open | unknown | null | no | transport failure | allow | block |
| malformed 200 (no `data` object) | fail-open | unknown | null | no | transport failure | allow | block |
| 422 bad IP | fresh | unknown | null | no | untouched | allow | **allow** (SF-3 carve-out — client error, not a fault) |

"By verdict" = block when `verdict ∈ block_verdicts` (default `malicious`/`critical`; `min_block_score`
tightens it), challenge when `verdict ∈ challenge_verdicts`, else allow. **`fail_mode=closed` blocks only
the `source='fail-open'` (could-not-check) `unknown` rows — inert and every `source='fresh'` row
(200-no-data, 422) always allow under both fail modes (SF-3).** On the request path these are the
mappings `decideCached()` applies to a `cachedVerdict()` hit; a `cachedVerdict()` **miss** is an
inert-equivalent allow under both fail modes.

### 4.7 Report flow (relocated from B; drain hardened per SF-7 + decision N6)

Reporting keeps B's `enqueue()` behavior verbatim — a fast non-blocking local write guarded by
self-IP / public-IP / dedup / daily-cap — and the namespace move + shared `Transport`/`Config`. The
**drain** is hardened by SF-7 (429 is code-branched) and decision N6 (a bounded, breaker-aware tick):

**IPv6 /64 normalisation for dedup + cap (P2/G2).** For an IPv6 target the reporter derives the **/64
`score_key`** and keys its per-IP **dedup + daily-cap** bookkeeping on it, so the client's notion of "the
same entity" agrees with the server's /64 aggregation (G2) — an attacker rotating /128s inside one /64
cannot defeat the client-side dedup. The **report body still carries the full observed /128** (the server
stores the /128 and computes the /64 itself, G2); only the client-side dedup/cap key is the /64. IPv4 is
unchanged (the full address). This is the same /64 normalisation `cachedVerdict()` applies before a
mirror lookup (§3.2), so report and lookup speak the same `score_key`.

- **Status branches, 429 split by Error `code` (SF-7):**
  - `2xx` → delete the row + bump the daily counter (`sent`).
  - `429 code=duplicate_report` → **drop** the row (or re-queue once past the 15-min bucket) — a
    duplicate is not a fault; **the breaker is untouched** and the row is never looped.
  - `429 code=quota_exhausted` → **park**: stop the tick and set the shared `mnc:breaker` quota marker
    (until `max(Retry-After, X-RateLimit-Reset)`, cap 6h, N2); the row stays queued for a later tick.
    This replaces B's unconditional "429 → re-queue", which looped dedup-429s and probed a quota window
    every tick.
  - other `4xx` (e.g. `no_report_rights`, `422`) → drop the row.
  - `5xx` / transport (status 0) → transport-class failure: bump attempts, drop at `>= 3`.
- **N6 drain-side budget:** a tick has a wall-clock budget (`10s`) and **aborts after 3 consecutive
  transport-class failures**, writing the shared `mnc:breaker` marker so the next tick **and the check
  path** fast-skip (shared outage discovery, N3). The drain **consults `mnc:breaker` before its first
  POST** and skips the tick while OPEN. This bounds an outage's cost to one budgeted tick instead of
  `200 × timeout` seconds — critical where the drain runs inside a loopback WP-Cron request.
- **Bounded re-queue:** re-queued rows carry **attempts + age caps**, and the queue has a **hard size
  cap** (oldest dropped first) so an outage bounds work and storage rather than accumulating both.

**Optional `signals` on the report (T1/T5).** `Client::report()` / `Reporter::enqueue()` accept an
OPTIONAL request-shape `signals` object the CONSUMER computed (S1 — core.classify / the policy adapter,
never this client). When non-empty it is persisted on the queued row and attached to the drain's
`/v1/report` POST body (alongside `ip, categories, comment, timestamp, sensor_id`) as report evidence;
empty => omitted entirely (opt-in, T4). The client carries but never computes or validates it. This is a
single additive, behaviour-neutral delta on top of B's enqueue path — the guard ladder is unchanged.

See piece B §4 for the enqueue surface; that spec's `Funnypot\Report\*` names become
`Funnypot\Mainnet\*`. The drain's 429-branching + N6 budget are the only behavior deltas from B.

## 5. Security & privacy (GDPR)

- **Request-path invariant (M5) — no synchronous network call on the request path, EVER.** A consumer
  serving a live request MUST use `cachedVerdict()` / `ReputationGate::decideCached()` (cache/mirror
  read only — no socket, no breaker). The network `check()` / `decide()` are **out-of-band only** (a
  warmer job / cron drain). No direct library consumer may claim spec cover for calling `check()` or
  `decide()` inline on a request: this spec does not authorize it, and doing so re-introduces the
  outage-slows-the-app failure M5 exists to prevent (a mainnet outage would add up to `timeout_ms` to
  every uncached visitor and, half-open, again at each cooldown boundary). Reputation is a **cache-first
  modifier/tiebreaker** in the request path, never a primary synchronous lookup, and never the sole
  basis for a block. `cachedVerdict()` is the load-bearing seam that makes this enforceable (SF-2).
- **Check transmits the visitor's IP to a third party.** An IP is personal data under GDPR. That is
  the whole reason check is **opt-in + key-gated + off by default** (`check_enabled=false`): the data
  transfer happens only when the operator has read the implications, flipped the flag, and supplied a
  key. No default deployment ever sends a visitor IP anywhere. The consumer (D/E middleware) decides
  *which* IPs to check — the default is "none, until explicitly enabled."
- **Reporting** already transmits attacker IPs (that is its purpose) and keeps B's D6 posture: the
  report **comment carries no self-identifying honeypot token** (marker / Host / probed path), so a
  stored report cannot deanonymize the reporter.
- **Request-shape `signals` are opt-in telemetry (S/T).** Both `check()` and `report()` accept an
  OPTIONAL `signals` object — request-shape observations (missing-header / self-consistency flags, UA
  class, the digit-stripped header fingerprint, a local anomaly summary) the CONSUMER computes
  (funnypot-core.classify / the policy adapter, never this client — S1). It is **off unless the consumer
  supplies it** (opt-in, disclosed in the consent posture, T4), attached VERBATIM, and — on check — rides
  ONLY the out-of-band escalation call that reaches mainnet (never `cachedVerdict()`, never a cache hit,
  T3), so a request-path read transmits nothing extra. On check the signals are low-trust OBSERVATIONAL
  telemetry (T2) A1 async-queues off the read path — never the abuse score, never a read-path write (T3);
  on report they are report evidence. Signals never enter the verdict cache key or a log line, and never
  carry the key.
- **Key handling.** `MAINNET_KEY` is a bearer credential. It is: held only in `Config`, sent only as
  the `Key:` request header to the configured `base_url`, **never** placed in a URL/query string (no
  `?key=`), **never** written to a log, exception message, or cache key, and **never** included in a
  `CheckResult`/`Decision`. Transport is HTTPS with cert verification on. The breaker/verdict cache
  keys are derived from the IP, never the key.
- **Cache privacy.** Verdict entries are keyed by IP and hold only the verdict-first fields A1 already
  returned to a non-owning caller — `verdict`, bounded `score`/`score_version`, aggregate `evidence`
  (counts + category slugs, H4) and `context` (`usage_type`/`asn`/`country`/…). **No comment text and
  no reporter identity** — `evidence.distinct_reporters` is a count, never identities; A1 withholds raw
  `reports[].comment` and reporter ids from non-owning callers anyway (D6/BL3). A consumer wanting to
  avoid persisting visitor IPs at all can inject `NullCache`.
- **Fail-open is a safety property, not just availability:** a bug or outage in the reputation path
  can never escalate into blocking legitimate traffic under the default `fail_mode=open` — the same
  "never take the site down" invariant the honeypot's LLM layer has (a fault degrades, never escapes).
- **No RCE surface.** Unlike the rules-updater, this client only sends form/query fields and reads a
  status + JSON body; it never `require`s or executes a response. The `base_url` comes from operator
  config, never from attacker-controlled data.

## 6. Testing strategy

Pure PHPUnit, host-run, **no network** — inject a `FakeTransport` and an `ArrayCache`.

- **`FakeTransport`** implements `Transport`; each test scripts the `{status, body}` it returns for
  the next `get`/`post`, and records the URL + headers it was handed.
- **Fail-open on every fault:** for status ∈ {0 (timeout), 429, 500, 401, 403} assert
  `CheckResult.source === 'fail-open'`, `verdict === 'unknown'`, `score === null`, and
  `ReputationGate::decide` returns **allow** under `fail_mode=open` and **block** under `fail_mode=closed`.
- **Clean 200 → fresh + verdict mapping (H):** a verdict-first body yields `source='fresh'` with the
  parsed `verdict`/`score`/`evidence`/`context`/`expires_at`; assert `verdict ∈ block_verdicts` → block
  (and, with `min_block_score` set, only when `score` clears the floor), `verdict ∈ challenge_verdicts`
  → challenge, `clean` → allow.
- **Cache hit spends no call:** prime `ArrayCache` with a verdict (or run one `fresh` check first),
  then assert a second `check` returns `source='cache'` and the `FakeTransport` recorded **zero**
  additional calls. Assert every verdict (block/`clean`/fresh `unknown`) is cached; assert a fail-open
  result is **not** cached (the next check calls out again).
- **TTL:** with a short `cache_ttl_hours`, assert an expired entry re-calls; with a live entry, no
  call. Assert `expires_at` shortens the TTL below the ceiling (an entry evicts at `expires_at`), and an
  absent/past `expires_at` falls back to the full ceiling (no zero/negative TTL).
- **Circuit breaker (decision N — the 8 spec-level tests, N7):**
  1. **shared state:** two `Client` instances over **one** injected store share breaker state (a trip
     recorded by one fast-fails the other) — proves N1's single shared marker.
  2. **absent store never blocks:** a fresh/absent marker → `allow()` true (CLOSED); documented and
     asserted **breaker-inert** with a per-process cache (ArrayCache/NullCache) — proves N1's
     persistent-cache requirement + the filemtime fallback is what carries state without a shared cache.
  3. **quota park:** a `429 code=quota_exhausted` parks until `max(Retry-After, X-RateLimit-Reset)`
     (advance the clock to just before the reset → still open; past it → half-open), **not** the 60 s
     transport cooldown; assert the 6h cap clamps an absurd header (N2).
  4. **duplicate-429 is not a fault:** a `429 code=duplicate_report` (report path) touches **neither**
     the breaker **nor** a re-queue loop — the row drops/parks once, never loops (N2/SF-7).
  5. **single-flight half-open:** at cooldown expiry, under concurrent callers, **exactly one** probes
     (the CAS/`add` winner extends `until`); the rest keep failing open (N4).
  6. **inert + `fail_mode=closed` → allow:** the SF-3 carve-out — feature-off/no-key never blocks even
     under closed; also `422` + closed → allow.
  7. **(consumer test, E):** E's sync-queue-driver guard asserts **zero** transport calls occur inside
     a request (the warmer/drain never runs inline) — noted here, owned by E's plan.
  8. **drain within budget:** a drain tick under a total outage completes within the `10s` wall-clock
     budget and aborts after 3 consecutive transport failures, writing the shared marker (N6).
  Plus the classic shape: `breaker_threshold` consecutive **transport** faults → next `check` fast-fails
  with **no** transport call; after the cooldown a half-open probe re-tries (success closes it); a
  cache-read exception inside the breaker degrades to allow (N5 — the breaker never breaks).
- **`cachedVerdict()` is socket-free + breaker-free (SF-2 / M5):** prime the cache with a verdict, then
  assert `cachedVerdict($ip)` returns it with **zero** transport calls and **without** consulting the
  breaker (force the breaker open → `cachedVerdict()` still returns the cached verdict, proving it does
  not gate on breaker state); a **miss** returns `null` (never a fail-open placeholder); inert (opt-in
  off / no key) returns `null`. Assert the key scheme stays private — the adapter never re-derives
  `mnc:v:*`.
- **IPv6 /64 normalisation + CIDR-containment (P2/Q2/P3):** `cachedVerdict()` on an IPv6 visitor inside a
  listed **/64** mirror entry hits by containment (not exact match), and two distinct /128s in the same
  /64 resolve to the same entry (normalised to the /64 `score_key` before lookup); a **/24** mirror row
  matches a contained IPv4 visitor; an exact-IP entry overrides its containing range for that IP (Q4,
  most-specific wins); a visitor outside every entry → null. The relocated reporter dedups two distinct
  /128s within one /64 as the **same** entity (the /64 `score_key`), while its posted body keeps the full
  /128. `scored_as` (the /64 or /128 the verdict was computed for, P3) round-trips through `CheckResult`.
- **`decideCached()` request-path mapping (M5):** a `cachedVerdict()` hit maps by verdict exactly like
  `decide()` (block on `malicious`/`critical`, allow on `clean`); a **miss** → `Decision::allow`
  (fail-open, verdict=unknown) under **both** fail modes with **zero** transport calls; assert the
  inert/422 carve-outs allow under `fail_mode=closed`.
- **Inert without key/enable:** `check_enabled=false` (key set) → inert fail-open, zero calls;
  `check_enabled=true` + empty key → inert, zero calls; only both-set spends a call. Separately assert
  `reportActive()` is true on key-alone (report does not need `check_enabled`).
- **Key never leaks:** assert the key appears **only** in the `Key:` header the transport received —
  never in the URL, never in a cache key, never in a `CheckResult`/`Decision`/exception string.
- **Relocated reporter parity + drain hardening:** re-run piece B's **enqueue-guard** suite against
  `Funnypot\Mainnet\Reporter` under the new namespace (guards, `sensor_id` persistence, comment
  de-identification) — proving the move changed nothing but names for the enqueue path. The **drain**
  is re-tested for its SF-7/N6 deltas (not B-verbatim): `429 code=duplicate_report` → drop, no breaker,
  no loop; `429 code=quota_exhausted` → park + shared marker; `5xx`/transport → attempts bump/drop; the
  tick consults `mnc:breaker` and aborts within the `10s` budget after 3 consecutive transport failures.
- **Optional `signals` telemetry (S/T):** with `opts['signals']` set, a cache-miss `check` attaches the
  object **VERBATIM** to the outbound `/v1/check` request (`FakeTransport` records it); with no
  `opts['signals']` the outbound request carries none; a **cache hit** and `cachedVerdict()` send **zero**
  signals (no network at all, T3); `signals` is **not** in the verdict cache key (a check with signals and
  one without, same IP/maxAge/sensitivity, hit the same cache entry, so signals never change the parsed
  verdict). On the report path, a non-empty `signals` handed to `Client::report()` / `Reporter::enqueue()`
  is persisted on the queued row and appears in the drain's POST body; an empty/omitted `signals` leaves
  the body byte-for-byte B's (guard ladder unchanged).
- **7.3 lane:** the package runs its **own** 7.3 CI (it no longer lives in core, so C's matrix does not
  cover it) — lint/run the suite on a 7.3 interpreter with `pdo_sqlite` + `curl` present so the
  conditional queue/transport tests run rather than skip.

## 7. Key decisions I made (confirm at review)

1. **`check` and `report` are gated separately.** `report` activates on key alone (D2); `check`
   additionally requires `check_enabled=true` (credits + GDPR). `Config` exposes both
   `checkActive()` and `reportActive()`. Recommended — the alternative (one gate) would either force
   checking on every reporter or block reporting behind the check opt-in.
2. **The circuit breaker is the canonical decision-N cooldown, backed by the injected `Cache`.** It
   keeps the app breaker's *fail-open-if-store-broken* property but is otherwise the full N contract:
   one **shared marker (REQUIRED)** with a **filemtime fallback** so state survives the request (N1);
   **two fault classes** — transport (threshold 5, 60 s ±20% cooldown) and quota (429
   `quota_exhausted`, trip-on-1, park to the reset header, cap 6h, N2); **single-flight half-open** (N4);
   and it **never breaks** (N5). One injected store serves both verdicts and breaker state. This
   supersedes the earlier "threshold/cooldown/30 s" shape.
3. **Own tiny `Cache` seam + `Psr16Cache` adapter, `psr/simple-cache` only a `suggest`.** Keeps the
   hard dep count at zero on a bare 7.3 host while still letting any PSR-16 backend drop in. (Decision
   F called PSR-16 "suggested/optional" — this realizes that.)
4. **`Config::fromArray` builder, not a positional constructor.** 7.3 has no named args (M15); an
   assoc-array builder avoids the silent-misassignment footfall A1/M15 flagged and lets unmapped keys
   default.
5. **Fail-open results are never cached; only clean 200s are, at `min(expires_at, cache_ttl_hours)`.**
   A fail-open must be re-tried next request, not pinned for `cache_ttl_hours`. On a clean 200 the
   response's `expires_at` (H1) can only *shorten* the operator's TTL ceiling, never extend it. `422`
   is treated as a client error (fresh/`unknown`/null, no breaker), distinct from a mainnet fault.
6. **`Decision` carries its `CheckResult`.** The middleware can log the score/source behind a block
   and a challenge page can show the band without a second call.
7. **`Client::check` never throws.** Every fault degrades to a fail-open `CheckResult` (the LLM-layer
   "degrade, never escape" invariant applied to the request path). `Transport::get` returns `status:0`
   on transport failure rather than raising.
8. **A challenge band is optional** (`challenge_verdicts` default `[]`). With no band set the gate is a
   clean allow/block; consumers with a challenge page opt a middle verdict tier (typically
   `['suspicious']`) in.
9. **`Transport` generalizes B's `ReportTransport` to add `get`.** One transport interface serves both
   check (GET) and report (POST); B's POST semantics are unchanged.
10. **The gate keys on `verdict`, not a raw score (H).** `block_verdicts` (default
    `['malicious','critical']`) is the headline knob — "block if malicious or critical" (H1). The
    bounded 0–100 `score` (H3) is exposed on `CheckResult` for logging/analytics and is an *optional*
    block tightener via `min_block_score`, never the primary decision. This supersedes F's original
    score-only `block_threshold`. AbuseIPDB wire-parity is dropped (H4); `CheckResult` speaks A1's
    native slug categories, and the package can be generated-from / validated-against A1's OpenAPI spec
    (H5).
11. **`sensitivity` is a band selector, not a model switch (K4).** The client forwards a single
    `sensitivity` (`strict|balanced|lenient`, Config default `balanced`, per-call override via
    `opts['sensitivity']`) as a query param; A1 keeps ONE published scoring model and only shifts the
    verdict bands. The client never selects a model. It is part of the verdict cache key so distinct
    sensitivities don't collide.
12. **`CheckResult` parses from the `{ data: … }` envelope (A1 standard).** Every `/v1` success body is
    `{ "data": … }`-wrapped; the check parser reads `json['data']` and pulls the verdict-first fields
    from inside it (a body with no `data` object is treated as malformed → fail-open). This aligns F
    with A1's standardized envelope and fixes the earlier "read fields off the bare root" assumption.
13. **`cachedVerdict()` / `decideCached()` are the request-path seam; `check()` / `decide()` are
    out-of-band (M5, MF-3).** The network calls are reframed as warmer/cron-drain operations and are
    **never** valid on the request path. The in-path read is a socket-free, breaker-free cache/mirror
    lookup that fails open on a miss and cues an out-of-band warm. The cache-key scheme stays private
    behind `cachedVerdict()` so `ReputationInterface` adapters (D/E) cannot drift from it (SF-2). A §5
    invariant forbids any consumer from claiming spec cover for an inline `check()`/`decide()`.
14. **`fail_mode=closed` governs only genuine could-not-check states (SF-3).** Inert (opt-in off / no
    key / cache miss) and `422` bad-IP **always allow**, under both fail modes — feature-off must never
    become site-off, and a client-side IP-parse error must never block a visitor. Closed maps only
    breaker-open/timeout/5xx/429/401·403/malformed to block.
15. **Local-mirror-lite is the primary fresh-read (O1); per-IP `check()` is escalation-only.** D/E/WAF
    pull the thin blacklist artifact (G3) on cron into the policy StateStore; `cachedVerdict()` reads it
    cache-first, so most IPs resolve with no per-IP call and fleet growth is CDN egress, not origin QPS.
    The `/v1/changes` delta feed (decision I) is the later upgrade of the same seam. The store seam bakes
    in **no per-IP-only assumption**.
16. **Optional consumer-supplied `signals` on check + report; the client carries, never computes (S/T).**
    `check()` (via `opts['signals']`) and `report()` (a trailing `$signals` arg) accept an OPTIONAL
    request-shape signals object the consumer computes in funnypot-core.classify / the policy adapter (S1).
    On check it is attached VERBATIM as low-trust OBSERVATIONAL telemetry to the out-of-band escalation
    call ONLY (never `cachedVerdict()`, never a cache hit, T2/T3), is **not** part of the verdict cache
    key, and A1 async-queues it off the read path (never the abuse score, never a synchronous write). On
    report it is persisted on the queued row and posted as report evidence. Opt-in — omitted when the
    consumer supplies nothing (T4/T5). This keeps the M5 cache-first request path unchanged (a mirror/cache
    read sends nothing) while every escalation check doubles as passive telemetry. The client reserves the
    shape and forwards it verbatim; A1's OpenAPI spec owns the `signals` schema + validation.

## 8. Dependencies + ripples to B / C / D / E

- **A1 (server):** no structural change to F's transport. Confirms `/v1/check` is F's endpoint, returns
  the **verdict-first H1 schema** (`verdict`/`score`/`score_version`/`evidence`/`context`/`expires_at`)
  **inside the standardized `{ data: … }` envelope** (every `/v1` success body is `data`-wrapped;
  F parses `data{}`), accepts the **`sensitivity`** query param (`strict|balanced|lenient`, K4 — one
  model, band selection only), and that F's consumers hold **`service`/check-quota** keys (D5). A1's
  **OpenAPI 3.x spec is the wire source of truth** (H5) — this package's `CheckResult` parser tracks
  that spec, and F may be generated-from / validated-against it; there is no `/compat` endpoint to
  target (H4). The `429` path now depends on A1's **machine-readable Error `code`** (`quota_exhausted`
  vs `duplicate_report`, SF-7) and its **`Retry-After` / `X-RateLimit-Reset` headers**: F **reads both
  the status AND those retry headers on a 429** in v1 (the earlier "reads the status, not the headers"
  is superseded by decision N2) — `quota_exhausted` parks the breaker to the reset time (cap 6h),
  `duplicate_report` is a no-fault drop on the report path. A1 sending a stable Error `code` +
  `Retry-After` on both 429 forms is the contract F relies on here. A1 also **accepts + async-queues the
  optional `signals` object** on `/v1/check` and `/v1/report` (T1–T3): F attaches it verbatim only when
  the consumer supplies it; A1 processes it as enrichment / calibration telemetry off the read path (never
  the abuse score, never a synchronous write), and the OpenAPI spec **reserves the `signals` shape** (T5).
  F neither computes nor validates it.
- **B (reporter):** **relocates here.** `Funnypot\Report\MainnetReporter` → `Funnypot\Mainnet\Reporter`;
  `ReportQueue`/`PdoSqliteReportQueue` move with it (`Funnypot\Report\*` → `Funnypot\Mainnet\*`). B's
  transport folds into this package's `Transport` (its POST is `Transport::post`). The in-core
  `src/Report/` tree is **dropped**; B's phases that built it retarget this package + the core/app
  wiring. Report body/shape/`sensor_id`/dedup/cap are carried over verbatim.
- **C (funnypot-core → 7.3):** core gains a `require` on `metrictower/mainnet-client` and re-exports
  it. **`src/Report/` is removed from C's conversion scope** — it never lands in core now — so C's 7.3
  matrix no longer needs the reporter row; **this package runs its own 7.3 lane** (§6). C still needs
  `pdo_sqlite`+`curl`+`sodium` in its container for the rest of its suite; the reporter's conditional
  tests move here.
- **D (WordPress) / E (Laravel):** depend on mainnet-client **via core**. Each adds a
  **reputation-check + reputation-block feature** wired on the **policy-port model (M5), never inline**:
  settings for `check_enabled`, `MAINNET_KEY`, `block_verdicts` (default `malicious`/`critical`), an
  optional `min_block_score`, optional `challenge_verdicts`, an optional `sensitivity`
  (`strict|balanced|lenient`, default `balanced`, K4), and `cache_ttl_hours`; a cache/StateStore binding
  (`Psr16Cache` over WP transients/object-cache, or Laravel `Cache`). The split:
  - **In-path (request):** the middleware/hook calls **`ReputationGate::decideCached($request->ip())`**
    (or reads `cachedVerdict()` and maps it) — a **cache/mirror read only, no socket, no breaker**. A
    hit blocks/challenges by verdict; a miss allows and enqueues an out-of-band warm. The
    `ReputationInterface` adapter D/E expose to `funnypot-policy` MUST call `cachedVerdict()` (SF-2), so
    the reputation precedence step (M5) is always a cache-first modifier, never a synchronous lookup.
  - **Out-of-band (warmer/cron):** a scheduled job / cron drains queued uncached actor IPs through
    **`ReputationGate::decide()` / `Client::check()`** (breaker-guarded, bounded per tick) and writes
    the resolved verdicts to the shared cache. E ships this as a v1 deliverable; D promotes its warmer
    to a v1 deliverable too (never an inline check). On the sync queue driver the warmer/drain must run
    via the scheduler, never inline in a request.
  - **O1 mirror pull:** a cron pulls the thin blacklist artifact (ETag/304) into the StateStore so
    `cachedVerdict()` resolves most IPs from the mirror; per-IP `check()` warms only the *uncertain*
    escalation set.
  The block UI is a **verdict badge + evidence**, not a bare score (mirrors A2's H ripple). **Off by
  default** (opt-in). D/E continue to bind their own `ReportQueue` for the report path. D7's
  REMOTE_ADDR-by-default source-IP policy applies equally to *which* IP they check/warm.
- **funnypot app:** consumes via core. For the CRS-WAF idea (`IDEAS.md`) it uses reputation as a
  **cache-first modifier** — a `cachedVerdict()` read on the request path (the `funnypot-policy`
  reputation precedence step, M5), **not** an inline `ReputationGate::decide()` first-gate. The earlier
  "wire `ReputationGate` inline as the CRS first-gate" framing is dropped (MF-3): the network resolve is
  a warmer, the request path is cache-first. The app already builds a `Reporter` (was `MainnetReporter`);
  after this move it updates the class import and pulls `composer update metrictower/funnypot-core`.

---

## Open items for review

- **Default `block_verdicts = ['malicious','critical']`**, `min_block_score` unset, challenge band off
  — confirm against A1's verdict rubric (H2) rather than a raw number. Because H2 requires
  distinct-reporter diversity for the top bands (aligns with D4's ≥2-distinct-`source_ip` gate), a
  `malicious`/`critical` verdict already implies corroboration, so verdict-only blocking is safe by
  default. A sophisticated consumer wanting an extra score floor sets `min_block_score` (the bounded
  0–100 score keeps A1's 39/63/92 anchors mapped onto the range, H3). Confirm the default set and the
  `unknown`→`clean` distinction hold once A1 publishes its final banding.
- **`Client::report` as a facade vs. using `Reporter` directly.** Spec presents `Client` as the unified
  entry with `report()` delegating to `Reporter`; consumers that only report can still construct
  `Reporter` alone. Confirm the facade is worth the extra surface.
- **Exact prod `MAINNET_BASE_URL` host** is an A1 open decision (A1 §12); this spec uses the same
  placeholder as B.
- **Local-mirror-lite is the PRIMARY fresh-read (decision O1), not reserved.** The `Cache`/store seam
  (§3.6) must let `cachedVerdict()` read a bulk local mirror the same way it reads a warmed verdict
  entry — no per-IP-only assumption. In v1, D/E pull the thin blacklist artifact (G3) on cron into the
  policy StateStore (the mirror population lives in D/E, not this package); per-IP `check()` is the
  escalation path for uncertain IPs only. Confirm the seam's read contract with `funnypot-policy`'s
  `StateStoreInterface`. The `/v1/changes` delta feed (decision I) remains the later reserved upgrade of
  the same seam — that part is still forward-compat, not v1.
- **`signals` shape is consumer-defined + A1-owned (S/T5).** F carries the optional `signals` object
  verbatim on check + report and does not model its fields; A1's OpenAPI spec reserves the shape and owns
  validation. Confirm the wire encoding on the GET check (a compact JSON `signals` query param vs. a
  dedicated telemetry field) with A1's OpenAPI spec once published — F forwards whatever the consumer
  hands it, so this is an A1/OpenAPI decision, not an F redesign.

---

## Review resolutions applied (2026-08-19)

### H — verdict-first output model (cites H1–H6)

Applies decision H ("native verdict-first output model; drop AbuseIPDB compat") to piece F. Layers on
top of decision G's accumulator scoring — the bounded score is G1's decayed accumulator mapped to
0–100 (H3), G is not undone.

- **H1 — `CheckResult` carries the verdict-first schema (§3.2).** Replaced the old
  `score/isWhitelisted/categories` value object with: `verdict` (enum `unknown|clean|suspicious|
  malicious|critical`, `unknown` DISTINCT from `clean`), bounded `score` 0–100 + `score_version`,
  derived booleans `isMalicious()`/`isSuspicious()`, `evidence{total_reports, distinct_reporters,
  reports_last_30d, first_seen, last_seen, categories[]}`, `context{usage_type, shared, allowlisted,
  asn, country}`, and `expires_at` — plus the existing `source` (`fresh|cache|fail-open`). A fail-open
  result is `verdict='unknown'`, `score=null`.
- **H1/H2 — `ReputationGate::decide` keys on verdict (§3.3).** Blocks when `verdict ∈ block_verdicts`
  (default `['malicious','critical']`), with an OPTIONAL `min_block_score` floor for the sophisticated
  path. The fail-open / uncertain path is `verdict='unknown'` → allow under the default `fail_mode=open`
  (never block on uncertainty); `closed` flips unknown to block. The verdict is the recommendation — no
  `recommended_action` consumed. Diversity for the top bands is A1's concern (H2), aligned with D4.
- **`Config` (§3.5).** Replaced score-only `block_threshold`/`challenge_threshold` with `block_verdicts`
  + optional `min_block_score` + optional `challenge_verdicts`; kept `cache_ttl_hours`, `timeout_ms`,
  and the breaker fields unchanged.
- **`expires_at` honored as a cache-TTL hint (§4.2, §4.5).** Verdict cache TTL =
  `min(expires_at − now, cache_ttl_hours)`; the perishability horizon can only shorten the operator's
  ceiling. Every actionable verdict is cached; fail-open is still never cached.
- **H4 — AbuseIPDB compat dropped.** Header wire-ref demoted to historical/informational; `CheckResult`
  speaks A1's native slug categories (`evidence.categories[].category`), no `/compat` shape.
- **H5 — OpenAPI adoption.** A1's OpenAPI 3.x spec is the wire source of truth; F's parser tracks it and
  F may be generated-from / validated-against it (§8, §7 decision 10).
- **H6 — calibration.** No F change: F carries whatever bounded `score` + `score_version` A1 returns;
  when A1's fast-follow recalibrates the score semantics it bumps `score_version`, which F surfaces
  verbatim so consumers see the version change.

### I+J — sync-feed forward-compat + no-CORS (cites decisions I, J)

Layers on top of H; undoes nothing. Both are reservations/requirements, not v1 build work.

- **I — local-mirror-capable store seam (§3.6, Open items).** Documented the `Cache`/store seam as
  generalizable to a bulk/local backing: `ReputationGate`/`Client` must not assume per-IP-only
  lookups, so a future local-mirror mode can back the gate onto a synced local bad-IP DB (mainnet's
  reserved `/v1/changes` feed) and answer without a per-IP `check()`. Reserved only — no mirror built.
  Added the matching one-line "reserved: local-mirror mode" item to Open items.
- **J — no-CORS on the keyed API: no F change.** J is a server-side (A1) requirement — keyed `/v1/*`
  emits no permissive CORS because the key is a server-side bearer credential. F already holds the key
  server-side (`Key:` header, never in a URL/query/cache-key/log, §5) and makes no browser call, so
  the no-CORS posture imposes no client-side change; noted here for traceability.

### K + re-review + L — sensitivity band selector + {data:…} envelope

Layers on top of H/I/J; undoes nothing. Applies decision K4 and the re-review's program-wide envelope
convention to piece F.

- **K4 — `sensitivity` band selector (§3.1, §3.5, §4.2, §4.5).** Added an optional `sensitivity`
  (`strict|balanced|lenient`) to `Client::check`'s `$opts` and a `Config` `sensitivity` default
  (`balanced`, per-call override). It is forwarded verbatim as the `/v1/check` `sensitivity` query
  param. Per K4 this selects the **band thresholds over the ONE published model's calibrated score** —
  the client does **not** choose a model. `sensitivity` joins the verdict cache key
  (`mnc:v:{ip}:{maxAge}:{sensitivity}`) so a `strict` and a `lenient` result for the same IP don't
  collide. `ReputationGate` is unchanged — it still keys on the returned verdict, which already
  reflects the requested sensitivity.
- **Re-review major #5 — `{ data: … }` envelope (§4.2, §7 decision 12).** A1 standardized every `/v1`
  success body under a top-level `{ "data": … }` envelope, so `check`'s `200` body is now
  `{ "data": { verdict, score, … } }` (was a bare top-level object). `CheckResult` is parsed from
  `json['data']`; a `200` with no `data` object degrades to fail-open/`unknown` (defensive). Field
  names stay native snake_case (no AbuseIPDB parity names).
- **L — no new F build.** The gap-analysis reservations (L1–L6) add nothing to F's v1 surface: the
  consumer-side decision overlay (L6) lives in D/E config, and `CheckResult`'s `context` is already an
  extensible struct. No F change beyond noting it stays extensible.

### N + O + future-proofing (cites decisions N, O1; review items MF-3, SF-1, SF-2, SF-3, SF-7, minor)

Layers on top of H/I/J/K/L; undoes nothing structural but reframes the request-path contract and the
breaker. Applies decisions N (global fail-open cooldown) and O1 (fleet-read), plus the future-proofing
review's F-scoped items.

- **MF-3 — `check`/`decide` are out-of-band; the request path is cache-first (§1, §3.1, §3.2, §3.3,
  §4.1, §5, §8).** Reconciled F with M5: reframed `check()`/`ReputationGate::decide()` as
  warmer/cron-drain operations that are **never** valid on the request path; rewrote the D/E and app
  ripples to the policy-port model (in-path `cachedVerdict()`/`decideCached()`, out-of-band warmer);
  added a **§5 request-path invariant** so no consumer can claim spec cover for an inline call; and
  **dropped** the "wire `ReputationGate` inline as the app CRS first-gate" framing.
- **SF-2 — `Client::cachedVerdict(ip, opts): ?CheckResult` added (§3.2).** The load-bearing
  never-sync seam: cache/mirror read only, **never opens a socket, never touches the breaker**, returns
  `null` on miss. Stated that `ReputationInterface` adapters MUST use it and the cache-key scheme stays
  **private** behind it. Added `ReputationGate::decideCached()` as its Decision-mapping partner (§3.3).
- **SF-1 = decision N — the circuit breaker rewritten (§2, §3.5, §4.2, §4.4, §4.6, §7 decision 2).**
  Adopted N1–N7 verbatim: one **shared marker REQUIRED** + **filemtime fallback**; **transport vs quota
  fault classes** with separate clocks (threshold 5 / 60 s ±20% vs trip-on-1 / park-to-reset / cap 6h);
  **single-flight half-open**; reads **`Retry-After` + `X-RateLimit-Reset` on 429**; the breaker never
  breaks. Resolved the ArrayCache-vs-NullCache default contradiction — NullCache is the injected default,
  both in-process caches are **breaker-inert**, and the filemtime marker carries state without a shared
  cache (persistent cache REQUIRED for check-enabled operation). Config default cooldown 30 → **60**;
  added `quota_park_cap_secs` (6h).
- **SF-3 — fail-mode carve-outs (§4.3, §4.6, §7 decision 14).** `fail_mode=closed` governs only genuine
  could-not-check states; **inert (no key / feature off / cache miss) and 422 bad-IP ALWAYS allow**
  under both fail modes. Amended the failure-mode table (added a `fail_mode=closed` column) and added
  the required test (inert + closed → allow).
- **SF-7 — 429 branched on Error `code` (§4.2, §4.7, §8).** `quota_exhausted` parks the breaker to the
  reset time; `duplicate_report` (report path) is a no-fault drop that never loops; the breaker trips
  only on quota/transport. The A1 ripple now depends on A1's machine-readable Error `code` +
  `Retry-After` on both 429 forms.
- **O1 — local-mirror-lite is the PRIMARY fresh-read (§1, §3.6, §7 decision 15, Open items).** Upgraded
  the decision-I "reserved local-mirror" note to O1: D/E pull the thin G3 blacklist artifact on cron
  into the policy StateStore; `cachedVerdict()` reads it cache-first; per-IP `check()` is escalation-only
  for uncertain IPs. `/v1/changes` (decision I) stays the later delta-upgrade of the same seam.
- **Minor — TTL jitter (§4.5, §3.5).** The `expires_at`-derived cache TTL is jittered **±10–20%** so a
  fleet caching the same IP does not converge on one absolute expiry instant and stampede the origin.
- **Tests (§6).** Added the 8 decision-N breaker tests (N7), the `cachedVerdict()`/`decideCached()`
  socket-free/breaker-free tests, the SF-3 inert+closed carve-out test, and the SF-7 drain 429-code
  branching + N6 budget tests.

### P/Q/R — IPv6 hardening + range/ASN reputation + country policy (cites decisions P2, P3, Q1, Q2, Q4)

Layers on top of H/I/J/K/L/N/O; undoes nothing. Applies the future-proofing entity/geo decisions (P —
IPv6 hardening, Q — range-level reputation) to piece F's request-path read + report key. R (country
policy) is a funnypot-policy / D-E concern with no F surface — noted for traceability only.

- **P3 — `scored_as` on `CheckResult` (§3.2, §4.2).** Added a `scored_as` field = the CIDR/IP the verdict
  was computed for (the /64 for IPv6, the /128 for IPv4), parsed from `data.scored_as` in the `{ data: … }`
  envelope, so a consumer knows a verdict is range-level (a /64) not host-level. `null` on
  fail-open/unknown. New getter `scoredAs()`; the constructor gains the arg before `$source`.
- **P2/Q2 — IPv6 /64 normalisation + CIDR-containment on the request-path read (§3.2, §3.6).**
  `cachedVerdict()` normalises an IPv6 visitor to its /64 `score_key` BEFORE the lookup (so the key agrees
  with the server's /64 aggregation, G2) and the local-mirror lookup matches range/CIDR/ASN entries by
  **CIDR-containment / prefix-match** (Q2), never exact-IP-only — a visitor inside a listed /64, /24, or
  ASN hits; most-specific match wins (Q4). The mirror seam is a range table; F derives the visitor's
  `score_key` and tests containment, population stays D/E's cron pull.
- **P2/G2 — IPv6 /64 normalisation before reporting (§4.7).** The relocated reporter keys its per-IP
  dedup + daily-cap on the IPv6 /64 `score_key` (the report body still carries the full observed /128; the
  server stores /128 + aggregates at /64, G2), so report and lookup speak the same key and a
  /128-rotating attacker cannot defeat client-side dedup. IPv4 unchanged.
- **Q1 — range/ASN entries are first-class in the mirror (§3.6).** No new F build beyond containment
  matching: the thin-artifact rows F reads may already be CIDR/ASN keys; F matches them by containment.
  Auto-rollup + range-allowlist tuning (Q3/Q4) are A1/ScoringModel concerns, not F.
- **R — country policy: no F change.** R's country gate lives in funnypot-policy's cheap-static ladder +
  D/E config over a LOCAL GeoIP DB (never a network call); it never touches the mainnet-client
  request-path read or report key. Noted for traceability.
- **Tests (§6).** Added IPv6-/64-normalisation + CIDR-containment-mirror-hit coverage (`cachedVerdict()`
  on an IPv6 in a listed /64 hits by containment; two /128s in one /64 resolve to one entry; a /24 row
  matches a contained IPv4 visitor; an exact-IP entry overrides its containing range, Q4; the reporter
  dedups two /128s in one /64 as one entity) and a `scored_as` round-trip on `CheckResult`.

### S/T — signals+telemetry (cites decisions S1, T1–T5)

Layers on top of H/I/J/K/L/N/O/P/Q/R; undoes nothing. Applies decision S (request-shape bot signals) and
decision T (signals ride check + report; check-carried signals are low-trust async telemetry) to piece F.
F only **carries** signals — the client never computes them (S1 puts computation in
funnypot-core.classify / the policy adapter); the whole change is minimal + consistent with the M5 /
`cachedVerdict()` / decision-N / `scored_as` edits already in place.

- **T5 — optional `signals` on `check()` (§3.1, §4.2).** `Client::check`'s `$opts` gains an OPTIONAL
  `signals` array the consumer hands in (missing-header / self-consistency flags, UA class, the
  digit-stripped fingerprint, a local anomaly summary). When present it is attached VERBATIM to the
  outbound `/v1/check` request as a `signals` field; the client neither computes nor validates it. Opt-in
  — omitted entirely when absent (T4).
- **T3 — signals ride the escalation check ONLY, never the request-path read (§3.2, §4.2, §5).** Because
  `check()` is out-of-band (M5) and only reaches mainnet on a cache miss, signals travel with the
  escalation call alone: a **cache hit sends nothing**, and `cachedVerdict()` (the request-path
  cache/mirror read) carries **no** signals at all — no network, no telemetry. O1 mirror-hits stay local,
  bounding telemetry volume. Signals are low-trust OBSERVATIONAL telemetry (T2) A1 async-queues off the
  read path — never the abuse score, never a synchronous read-path write.
- **T2 — not a verdict discriminant (§3.1, §4.2, §6).** `signals` is **not** part of the verdict cache key
  (`mnc:v:{ip}:{maxAge}:{sensitivity}` unchanged) and never changes the returned verdict; a signalled and
  an un-signalled check for the same IP/maxAge/sensitivity resolve to the same cache entry.
- **T5 — optional `signals` on `report()` (§3.1, §4.7).** `Client::report()` and `Reporter::enqueue()`
  gain a trailing OPTIONAL `array $signals = []`; a non-empty object is persisted on the queued row and
  attached to the drain's `/v1/report` POST body (alongside `ip, categories, comment, timestamp,
  sensor_id`) as report evidence — a single additive, behaviour-neutral delta on B's enqueue path (guard
  ladder unchanged); empty => omitted.
- **T4 — opt-in + disclosed (§5).** Sending visitor request-shape signals is telemetry, so it is opt-in
  (like check itself) and disclosed in the consent posture (D2/GDPR). Signals never enter the verdict cache
  key or a log line and never carry the key.
- **A1 ripple (§8).** A1 accepts + async-queues the optional `signals` on `/v1/check` and `/v1/report`
  (enrichment / calibration consumer, never a read-path write); the OpenAPI spec **reserves the `signals`
  shape**. Added the matching Open-items note (consumer-defined shape, A1-owned validation, GET-check wire
  encoding TBD in the OpenAPI spec).
- **Tests (§6).** Added: a cache-miss `check` with `opts['signals']` records the object VERBATIM on the
  outbound request; no `opts['signals']` => none sent; a cache hit and `cachedVerdict()` send zero signals;
  `signals` is not in the verdict cache key; a non-empty report `signals` is persisted on the row and posted
  in the drain body, while an empty one leaves the body byte-for-byte B's.
