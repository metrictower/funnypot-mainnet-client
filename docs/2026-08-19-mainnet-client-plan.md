# metrictower/mainnet-client · F — implementation plan

**Status:** ready to build · **Date:** 2026-08-19 · **Piece:** F of the funnypot-mainnet program
**Implements:** [`2026-08-19-mainnet-client-design.md`](./2026-08-19-mainnet-client-design.md) (the design is the source of truth; this plan does not redesign it)
**Canonical (wins over both):** [`funnypot-mainnet/docs/2026-08-19-program-decisions.md`](../../funnypot-mainnet/docs/2026-08-19-program-decisions.md) §F
**Reporter origin (relocated here):** [`funnypot-core/docs/2026-08-19-mainnet-reporting-plan.md`](../../funnypot-core/docs/2026-08-19-mainnet-reporting-plan.md) (piece B — its Phases 1–6 tests port over verbatim under the new namespace)
**Breaker pattern:** [`funnypot-app/src/App/Llm/CircuitBreaker.php`](../../funnypot-app/src/App/Llm/CircuitBreaker.php) (behavior mirrored; rewritten 7.3-clean, cache-backed)

A builder should be able to execute this top to bottom without re-reading the design. Each phase is
TDD: the test is written and shown to fail first, then the code makes it pass, then the **whole suite**
is run green before the next phase starts. Phase 0 stands the package up (the repo does not exist yet).

---

## Orientation

### What exists now (grounding)

- **Nothing in this repo but docs.** `mainnet-client/` currently holds only `docs/`. Phase 0 creates
  `composer.json`, `phpunit.xml.dist`, `src/`, `tests/`, and a first green (empty) suite. There is no
  `vendor/` and no autoloader yet — until Phase 0 runs, `php vendor/bin/phpunit` has nothing to run.
- **The reporter being relocated does not live in core yet.** Piece B (`Funnypot\Report\*` in
  `funnypot-core/src/Report/`) is *planned* but not built. Per decision F it never lands in core — it is
  authored **here** as `Funnypot\Mainnet\*` from birth. B's plan (Phases 1–6) is the exact test/behavior
  script for that code; this plan ports it under the new namespace and merged transport. Its grounding
  sources (`funnypot/src/App/ThreatIntel/AbuseIpdb.php`, `funnypot/tests/App/AbuseIpdbTest.php`) are
  still the lineage for the enqueue-guard ladder and drain status branches.
- **The breaker to mirror** — `funnypot/src/App/Llm/CircuitBreaker.php`. Threshold → open for a cooldown
  → fast-fail while open; **fails open (allow) if its own store is unreadable**. We keep that behavior but
  swap its SQLite store for the injected `Cache` (§4.4 of the design), and rewrite it 7.3-clean (its
  `private ?PDO $db` typed property and constructor promotion are 8.0 constructs — not allowed here).

### How to run the tests (once Phase 0 lands)

- From `mainnet-client/`: **`composer install`** once (dev-only: phpunit), then **`php vendor/bin/phpunit`**.
  Pure PHPUnit, **no network, no container**. Tests inject `FakeTransport` + `ArrayCache`; nothing touches
  a socket or a real cache backend.
- Run one file: `php vendor/bin/phpunit tests/Check/ClientCheckTest.php`.
- Run one case: `php vendor/bin/phpunit --filter=test_clean_200_is_fresh_and_cached`.
- Extension-conditional tests (the SQLite report queue) `skip` cleanly when `pdo_sqlite` is absent on the
  host; the 7.3 CI lane (Phase 14) carries `pdo_sqlite`+`curl`+`sodium` so they **run**, not skip.

### Constants fixed by the design (do not re-derive)

- **PSR-4** `Funnypot\Mainnet\ → src/`; tests a separate autoload-dev root `Funnypot\Mainnet\Tests\ → tests/`
  (mirrors M14). Namespaces from B (`Funnypot\Report\*`) become `Funnypot\Mainnet\*`.
- **PHP `>=7.3` from birth**, 7.3-clean throughout: **no** constructor promotion, enums, `match`, nullsafe
  `?->`, `??=`, typed properties, union types. Scalar/array/nullable **parameter and return** types
  (`?int`, `?array`, `bool`, `void`, class names) **are** 7.3-legal and are used. Value objects use
  **untyped properties + docblocks**. `Config` builds via **`fromArray()`**, never a positional constructor
  (7.3 has no named args — M15).
- **Zero hard runtime deps.** `psr/simple-cache` is a `suggest`, never a `require`. `ext-curl` /
  `ext-pdo_sqlite` are `suggest` (transport degrades to streams; the SQLite queue is report-path only).
- **Env mapping (D1):** `base_url` ← `MAINNET_BASE_URL` (**scheme + host only, no path**); `key` ←
  `MAINNET_KEY` (the sole credential). Each capability appends its own path — check → `/v1/check`,
  report → `/v1/report`. Never a hardcoded endpoint constant.
- **Two independent gates:** `checkActive()` = `check_enabled === true` AND `key !== ''`; `reportActive()`
  = `key !== ''` alone (D2). Check is opt-in because it spends credits and sends a visitor IP to a third
  party (GDPR); report keeps B's key-alone posture.
- **Fail-OPEN is the invariant.** Anything that is not a clean `200` → `CheckResult.source='fail-open'`,
  **`verdict='unknown'`**, `score=null`; `ReputationGate` maps `verdict='unknown'` to `allow` under
  `fail_mode=open` (default) and `block` under `fail_mode=closed` (never block on uncertainty by
  default). The library **never** takes the site down and `Client::check` **never throws**.
- **Request path is cache-first; network is out-of-band (M5, MF-3).** `check()` / `decide()` open a
  socket and are **out-of-band only** (warmer job / cron drain). The request path calls
  **`Client::cachedVerdict(ip, opts): ?CheckResult`** / **`ReputationGate::decideCached(ip)`** — a
  cache/mirror read with **no socket, no breaker**; `null`/miss fails open and cues an out-of-band warm.
  `cachedVerdict()` keeps the cache-key scheme **private** (SF-2); adapters MUST use it, never re-derive
  `mnc:v:*`. A §5 design invariant forbids an inline `check()`/`decide()` on the request path.
- **`fail_mode=closed` carve-outs (SF-3).** Closed maps to block ONLY genuine could-not-check states;
  **inert (no key / feature off / cache miss) and `422` bad-IP ALWAYS allow** under both fail modes.
- **The breaker is the canonical decision-N cooldown (SF-1).** One **shared marker (REQUIRED)** +
  **filemtime fallback**; **transport** class (threshold 5, 60 s ±20% cooldown) vs **quota** class
  (`429 code=quota_exhausted`, trip-on-1, park to `max(Retry-After, X-RateLimit-Reset)`, cap 6h);
  **single-flight half-open**; **reads the 429 retry headers**; never breaks (store fault → allow).
  NullCache/ArrayCache are **per-process → breaker-inert**; a persistent shared cache is REQUIRED for
  check-enabled operation, else the filemtime marker carries state.
- **O1 — local-mirror-lite is the primary fresh-read.** `cachedVerdict()` reads a bulk local mirror
  (the thin G3 blacklist artifact D/E pull on cron into the policy StateStore) the same way it reads a
  warmed verdict; per-IP `check()` is escalation-only. No per-IP-only assumption in the store seam.
- **P2/Q2 — IPv6 /64 normalisation + CIDR-containment on the request-path read.** `cachedVerdict()`
  normalises an IPv6 visitor to its **/64 `score_key`** before the lookup (agrees with the server's /64
  aggregation, G2) and the local-mirror lookup matches range/CIDR/ASN entries by **CIDR-containment /
  prefix-match** (Q2), never exact-IP-only; most-specific match wins (Q4). The relocated reporter keys its
  IPv6 **dedup + daily-cap** on the same /64 `score_key` (the posted body still carries the full /128 the
  server stores, G2). **P3 — `scored_as`** (the CIDR/IP the verdict was computed for: /64 v6, /128 v4) is
  a new `CheckResult` field parsed from `data.scored_as`. **R** (country policy) has no F surface — it is
  funnypot-policy + D/E config over a LOCAL GeoIP DB.
- **Verdict-first output (H) under the `{ data: … }` envelope.** Every `/v1` success body is wrapped
  in a top-level `{ "data": … }` envelope (A1 standard). A clean `200` from `/v1/check` is
  `{ "data": { verdict, score, score_version, evidence, context, expires_at, scored_as } }`; the parser reads
  `json['data']` and pulls the H1 fields from **inside** `data{}` (`verdict`
  `unknown|clean|suspicious|malicious|critical` with `unknown` ≠ `clean`, bounded `score` 0–100 +
  `score_version`, `evidence{…}`, `context{…}`, `expires_at`). A `200` with no `data` object is
  malformed → fail-open/`unknown`. `ReputationGate` keys on **`verdict`** (block when
  `verdict ∈ block_verdicts`), not a raw score threshold.
- **Sensitivity (K4) — band selector, not a model choice.** `check` accepts an optional
  `opts['sensitivity']` (`strict|balanced|lenient`); `Config` carries the default (`balanced`). It is
  forwarded verbatim as the `/v1/check` `sensitivity` query param and selects A1's band thresholds over
  the ONE published model's score — the client never picks a model. `sensitivity` is part of the
  verdict cache key.
- **Only a clean `200` is cached** (every verdict — block, `clean`, fresh `unknown`), keyed by
  `mnc:v:{ip}:{maxAge}:{sensitivity}`, for **`min(expires_at − now, cache_ttl_hours)`** — the response
  `expires_at` (H1) can only shorten the operator's ceiling. Fail-open results are **never** cached.
  `422`/malformed-IP is a *client* error → `fresh`, `verdict='unknown'`, `score=null`, **no** breaker
  trip.
- **Key never leaks:** sent only as the `Key:` request header; never in a URL/query string, a cache key,
  a log, an exception, or a `CheckResult`/`Decision`.
- **Optional consumer-supplied `signals` on check + report (S/T5).** `check()` (`opts['signals']`) and
  `report()` (a trailing `array $signals = []`) accept an OPTIONAL request-shape signals object the
  CONSUMER computes (funnypot-core.classify / the policy adapter — the client NEVER computes or validates
  it, S1). On check it is attached VERBATIM to the outbound `/v1/check` request as low-trust OBSERVATIONAL
  telemetry, and it rides the out-of-band escalation call ONLY — never `cachedVerdict()`, never a cache hit
  (T2/T3) — and is **not** part of the verdict cache key. On report it is persisted on the queued row and
  posted in the drain body as report evidence. Opt-in — the field is omitted entirely when the consumer
  supplies nothing (T4). Reserve/forward the shape; A1's OpenAPI spec owns validation.

### Config defaults (design §3.5 — do not invent others)

`check_enabled=false`, `fail_mode='open'`, `block_verdicts=['malicious','critical']`,
`min_block_score=null` (verdict alone decides), `challenge_verdicts=[]` (band off),
`sensitivity='balanced'` (band selector, K4), `cache_ttl_hours=12` (TTL ceiling — `expires_at` may
shorten it; the derived TTL is jittered ±10–20%), `timeout_ms=1500`, `breaker_threshold=5`
(consecutive **transport** faults), `breaker_cooldown_secs=60` (decision-N canonical, ±20% jitter on
write — was 30), `quota_park_cap_secs=21600` (6h ceiling on a quota-class park), `daily_cap=1000`,
`dedup_hours=24`, `self_ips=[]`.

---

## Phase 0 — Package skeleton + green empty suite

**Change.** Create the package so `phpunit` runs:
- `composer.json` with the design §2 shape: `name` `metrictower/mainnet-client`, `type` library,
  `require { "php": ">=7.3" }`, `require-dev { "phpunit/phpunit": "^9.5" }`, the three `suggest`s
  (`ext-curl`, `ext-pdo_sqlite`, `psr/simple-cache`), `autoload` PSR-4 `Funnypot\\Mainnet\\ → src/`,
  `autoload-dev` PSR-4 `Funnypot\\Mainnet\\Tests\\ → tests/`.
- `phpunit.xml.dist` with one testsuite rooted at `tests/`, `bootstrap="vendor/autoload.php"`,
  `colors="true"`. A `.gitignore` (`/vendor`, `composer.lock` — a library commits no lock).
- `composer install` to generate `vendor/` + the autoloader.
- One placeholder `src/` file so the autoloader has a target, and a trivial `tests/SmokeTest.php`.

**Test first.** `tests/SmokeTest.php::test_autoload_and_phpunit_wired` — asserts `true` and that a
namespaced class under `Funnypot\Mainnet\` autoloads (e.g. a one-line `Version` const class, or delete
this assertion once Phase 1 adds a real class). Its only job is to prove the harness runs.

**Verify green.** `php vendor/bin/phpunit`

**Done when.** `composer install` succeeds; `php vendor/bin/phpunit` runs and is green (1 test);
`composer validate` passes; the PSR-4 roots resolve. No product classes yet beyond the placeholder.

---

## Phase 1 — Transport: interface + `FakeTransport` + real transports (failure shape)

**Change.** Add `src/Transport/Transport.php` (design §3.7): `get(string $url, array $headers): array`
and `post(string $url, array $headers, string $body): array`, both returning
`{status:int, body:string, headers:array}` (lower-cased response header name→value),
**status `0` on transport failure/timeout** (never an exception). The `headers` map carries
`retry-after`/`x-ratelimit-reset` so the breaker can park a quota-class 429 (N2). Add:
- `src/Transport/CurlTransport.php` (default) — ext-curl; `CURLOPT_CONNECTTIMEOUT_MS`+`CURLOPT_TIMEOUT_MS`
  from `timeout_ms`, `SSL_VERIFYPEER/HOST` on, `CURLOPT_FAILONERROR=false` so 4xx/5xx still return a
  status via `curl_getinfo(...RESPONSE_CODE)`, plus a `CURLOPT_HEADERFUNCTION` collecting the lower-cased
  `headers` map. `get` and `post` variants.
- `src/Transport/StreamTransport.php` — stream-context fallback lifting B's `StreamReportTransport`
  (`ignore_errors`, hard timeout, parse `$http_response_header`) and **generalizing it to add `get`** +
  parsing `$http_response_header` into the same lower-cased `headers` map; post body semantics unchanged.
  7.3-safe.
- `tests/Support/FakeTransport.php` implementing `Transport` — each test scripts the next
  `{status, body, headers}` for `get`/`post`, and **records** every URL + headers + body it was handed
  (the seam every later phase asserts against). Supports a queue of scripted responses so multi-call
  phases (breaker, TTL) work, and lets a 429 case script `retry-after`/`x-ratelimit-reset`.

**Test first.** `tests/Transport/TransportTest.php` — offline/deterministic only:
- `test_fake_transport_scripts_and_records` → a scripted `get` returns the scripted status/body/headers
  and the fake recorded the exact URL + request headers; a scripted 429 surfaces
  `retry-after`/`x-ratelimit-reset` in the returned `headers` map.
- `test_curl_returns_zero_status_on_unreachable` (skip if `!function_exists('curl_init')`) →
  `get('https://127.0.0.1:1/x', [...])` returns `{status:int(0), body:string}` and does **not** throw.
- `test_stream_returns_zero_status_on_unreachable` → `StreamTransport->get`/`post` at an unreachable URL
  return `{status:0, body:''}` without throwing.

**Verify green.** `php vendor/bin/phpunit tests/Transport/TransportTest.php`

**Done when.** All three transports return the `{status, body, headers}` contract and never throw on
network failure; `FakeTransport` scripts + records (incl. 429 retry headers); full suite green. (The 2xx happy path of the real transports
is proven only by the opt-in live test in Phase 14's notes, not a unit test — the suite is offline.)

---

## Phase 2 — Cache seam: `Cache` + `ArrayCache` + `NullCache` + `Psr16Cache`

**Change.** Add `src/Cache/Cache.php` (design §3.6): `get(string $key, $default=null)`,
`set(string $key, $value, int $ttlSeconds=0): bool` (`0` = no expiry), `has(string $key): bool`. Add:
- `ArrayCache` — in-process map honoring `ttlSeconds` against a monotonic clock seam (an injectable
  `time()` so TTL tests do not sleep); the default test double and a valid single-process default.
- `NullCache` — no-op; `get` always misses (`has` false), so every check is `fresh`, no caching.
- `Psr16Cache` — adapter wrapping a `Psr\SimpleCache\CacheInterface`, translating our 3 methods to PSR-16
  (`suggest` only — the adapter references the interface but the package imposes no `require`).

**Test first.** `tests/Cache/CacheTest.php`:
- `test_array_cache_roundtrip_and_miss` → `set`/`get`/`has`; a missing key returns the `$default`.
- `test_array_cache_ttl_expires` → set with `ttlSeconds`, advance the injected clock past it, `get` misses.
- `test_null_cache_always_misses` → `set` then `get` still returns `$default`, `has` false.
- `test_psr16_adapter_delegates` → wrap a tiny in-memory PSR-16 fake; `get/set/has` proxy through.

**Verify green.** `php vendor/bin/phpunit tests/Cache/CacheTest.php`

**Done when.** All three cache impls satisfy the seam; TTL is clock-injectable (no real sleeps); full
suite green.

---

## Phase 3 — `Config` + the opt-in/key gate

**Change.** Add `src/Config.php` (design §3.5): untyped private props for every field, a
`public static function fromArray(array $opts)` builder mapping the snake_case keys
(`base_url, key, check_enabled, fail_mode, block_verdicts, min_block_score, challenge_verdicts,
sensitivity, cache_ttl_hours, timeout_ms, breaker_threshold, breaker_cooldown_secs, quota_park_cap_secs,
self_ips, daily_cap, dedup_hours`) with the fixed defaults above; a getter per field; and the two gate
methods:
- `checkActive(): bool` = `checkEnabled === true && key !== ''`.
- `reportActive(): bool` = `key !== ''`.

**Test first.** `tests/ConfigTest.php`:
- `test_from_array_applies_defaults` → `fromArray([])` yields every documented default; a mismatch fails
  (incl. `sensitivity()==='balanced'`, the K4 band-selector default; `breakerCooldownSecs()===60`, the
  decision-N canonical; `quotaParkCapSecs()===21600`).
- `test_from_array_maps_keys` → each provided key lands on its getter (base_url host-only preserved;
  a provided `sensitivity` of `strict`/`lenient` reads back on `sensitivity()`).
- `test_check_inert_without_flag` → key set, `check_enabled` false → `checkActive()===false`.
- `test_check_inert_without_key` → `check_enabled` true, empty key → `checkActive()===false`.
- `test_check_active_needs_both` → both set → `checkActive()===true`.
- `test_report_active_on_key_alone` → key set, `check_enabled` false → `reportActive()===true`;
  empty key → `reportActive()===false`. (Proves report does not need the check opt-in — decision F.)

**Verify green.** `php vendor/bin/phpunit tests/ConfigTest.php`

**Done when.** Builder + defaults + both independent gates proven; full suite green.

---

## Phase 4 — `CircuitBreaker` (canonical decision-N cooldown, cache-backed, 7.3-clean)

**Change.** Add `src/CircuitBreaker.php` implementing the **decision-N** contract (design §4.4),
7.3-clean (no promotion, no typed props). State is a single record
`mnc:breaker → {failures:int, until:epoch, reason:'transport'|'quota'}` in the injected `Cache`, with a
**filemtime marker in `sys_get_temp_dir()`** fallback (same JSON body) when the injected store is
per-process/non-persistent so state still crosses requests (N1). Constructor
`(Cache $cache, int $threshold=5, int $cooldownSecs=60, int $quotaParkCapSecs=21600)` plus the injectable
clock seam and a jitter seam (injectable so tests are deterministic). Methods:
- `allow(): bool` — false while `now < until`; at expiry it is **single-flight half-open** (N4): the
  first caller atomically CAS/`add`-extends `until` by one cooldown (filemtime fallback `touch`es the
  file) and returns true to probe alone; concurrent callers keep getting false. **True (fail-open) on
  any store read/write fault** — the breaker must never be the thing that breaks (N5).
- `recordTransportFailure(): void` — increment `failures`; at `>= threshold` set
  `until = now + jitter(cooldownSecs, ±20%)`, `reason='transport'`, reset the count (N2).
- `recordQuota(?int $retryAfter, ?int $rateLimitReset): void` — trip OPEN immediately (no threshold),
  `until = min(max($retryAfter, $rateLimitReset), now + quotaParkCapSecs) + jitter`, `reason='quota'` (N2).
- `recordSuccess(): void` — reset `failures` to 0 and clear `until` (probe success → CLOSED).
- All writes are best-effort (swallow store faults). An absent/evicted marker reads as CLOSED (N1).

> Report-side note (Phase 11): the drain reuses this same `mnc:breaker` record for its 10 s / 3-fail
> budget (N6); a `429 code=duplicate_report` is **not** routed through the breaker at all.

**Test first.** `tests/CircuitBreakerTest.php` — the decision-N set (N7), injecting `ArrayCache` + the
clock + jitter seams:
- `test_closed_allows` → fresh breaker `allow()===true`.
- `test_trips_after_transport_threshold` → `threshold` consecutive `recordTransportFailure()` →
  `allow()===false`, `reason==='transport'`.
- `test_success_resets_failures` → failures below threshold then `recordSuccess()` → `allow()` stays
  true and the count is cleared.
- `test_transport_reopens_after_60s_half_open_single_flight` → trip it; advance the clock past the
  jittered ~60 s cooldown → under two concurrent `allow()` calls **exactly one** returns true (the
  probe), the other false (N4).
- `test_quota_parks_to_reset_not_cooldown` → `recordQuota(retryAfter, rateLimitReset)` parks until
  `max(...)` (still open just before it, half-open just after), **not** 60 s; an absurd header is
  clamped to `quotaParkCapSecs` (6h).
- `test_two_instances_share_state_over_one_store` → a trip recorded by instance A fast-fails instance B
  over the same injected store (N1 shared marker).
- `test_filemtime_fallback_crosses_requests_without_shared_cache` → with a per-process cache, a fresh
  instance still sees the OPEN state via the temp-dir marker (documented breaker-inert-without-it).
- `test_fail_open_when_store_unreadable` → inject a `Cache` whose `get`/`set` throw → `allow()===true`
  (degrades to allow, never propagates — N5).

**Verify green.** `php vendor/bin/phpunit tests/CircuitBreakerTest.php`

**Done when.** Transport vs quota classes, the 60 s ±20% cooldown, quota park-to-reset + 6h cap,
single-flight half-open, the shared-marker + filemtime fallback, and store-fault-fails-open are all
proven against the injected cache + seams; full suite green.

---

## Phase 5 — `CheckResult` value object

**Change.** Add `src/CheckResult.php` (design §3.2, H1): the three `SOURCE_*` constants
(`fresh|cache|fail-open`) **and** the five `VERDICT_*` constants
(`unknown|clean|suspicious|malicious|critical`); a constructor
`(string $verdict, $score, $scoreVersion, array $evidence, array $context, $expiresAt, $scoredAs, string $source)`
assigning **untyped** props; and getters `verdict(): string`, `score(): ?int`, `scoreVersion(): ?string`,
`evidence(): array`, `context(): array`, `expiresAt(): ?string`, `scoredAs(): ?string`, `source(): string`,
plus the derived booleans `isMalicious(): bool` (`verdict === malicious || verdict === critical`),
`isSuspicious(): bool` (`verdict === suspicious`), and `isFailOpen(): bool` (`$source ===
SOURCE_FAIL_OPEN`). `scored_as` (P3) is the CIDR/IP the verdict was computed for — the /64 for IPv6, the
/128 for IPv4 — so a consumer knows a verdict is range-level. A fail-open/inert result carries
`verdict='unknown'`, `score=null`, `[]` evidence and context, `expiresAt=null`, `scoredAs=null` — so a
caller can never mistake "could not check" (`unknown`) for `clean`.

**Test first.** `tests/CheckResultTest.php`:
- `test_getters_roundtrip` → constructed verdict/score/score_version/evidence/context/expires_at/source
  read back through each getter.
- `test_derived_booleans_from_verdict` → `malicious` and `critical` → `isMalicious()===true`;
  `suspicious` → `isSuspicious()===true` and `isMalicious()===false`; `clean`/`unknown` → both false.
- `test_fail_open_is_unknown_and_null` → `SOURCE_FAIL_OPEN` → `isFailOpen()===true`,
  `verdict()==='unknown'`, `score()===null`, `evidence()===[]`, `context()===[]`, `expiresAt()===null`,
  `scoredAs()===null`; a `fresh` result with a verdict/score → `isFailOpen()===false`.
- `test_scored_as_roundtrips` → a `scored_as` of `2001:db8::/64` (IPv6) and a `/128` (IPv4) reads back
  through `scoredAs()` (P3); a fail-open result carries `scoredAs()===null`.
- `test_unknown_is_distinct_from_clean` → `unknown` and `clean` are different verdict values and neither
  is malicious/suspicious (guards the H1 "never conflate" invariant).
- `test_constants_are_the_wire_values` → the `SOURCE_*` equal `'fresh'`/`'cache'`/`'fail-open'` and the
  `VERDICT_*` equal `'unknown'`/`'clean'`/`'suspicious'`/`'malicious'`/`'critical'` (guards renames the
  design/OpenAPI pins).

**Verify green.** `php vendor/bin/phpunit tests/CheckResultTest.php`

**Done when.** The value object is immutable-by-convention, carries the full H1 schema, and its
getters/derived-booleans match §3.2; full suite green.

---

## Phase 6 — `Client::check` active happy path (fresh + parse + cache write)

**Change.** Add `src/Client.php` with the constructor from §3.1
(`Config, Transport = null, Cache = null, Reporter = null` → defaults `CurlTransport`/`NullCache`/lazy
`Reporter`; assign untyped props) and `check(string $ip, array $opts = [])`. This phase implements only
the **active + clean-200** path:
1. If `!config->checkActive()` → return the inert fail-open result (fully covered in Phase 7); stub it
   here as a fail-open so the happy path can be written first.
2. Build the URL `rtrim(base_url,'/').'/v1/check'` with query `ipAddress`, `maxAgeInDays` (from `opts`,
   default per A1), `sensitivity` (from `opts['sensitivity']`, else `config->sensitivity()` — K4, a band
   selector forwarded verbatim, never a model choice), and `verbose` when `opts['verbose']`. Key goes in
   the **`Key:` header only** (never `sensitivity`-adjacent in the URL — the key stays header-only). When
   the consumer passes `opts['signals']` (a request-shape object IT computed — S1, never this client),
   attach it VERBATIM to the outbound request as a `signals` field (opt-in — omit the field entirely when
   absent; T5). It rides only this network escalation call; it is **not** added to the cache key and never
   changes the parsed verdict (T2/T3).
3. `transport->get(url, headers)`; on `status===200` + valid JSON → read the **top-level `{ data: … }`
   envelope** (A1 standard: every `/v1` success body is `data`-wrapped) and parse the **H1 verdict-first
   fields from inside `json['data']`** (`verdict`, `score`, `score_version`, `evidence{…}`, `context{…}`,
   `expires_at`, `scored_as`) into `CheckResult(verdict, score, score_version, evidence, context,
   expires_at, scored_as, SOURCE_FRESH)`, `breaker->recordSuccess()`, and
   `cache->set('mnc:v:{ip}:{maxAge}:{sensitivity}', result, ttl)` where
   **`ttl = jitter(min(expires_at − now, cache_ttl_hours*3600), ±10-20%)`** (fall back to the full
   ceiling when `expires_at` is absent or already past — never a zero/negative TTL; the jitter avoids a
   fleet converging on one absolute `expires_at` instant, the minor expires_at-herd fix). Parse
   defensively: a `200` with
   **no `data` object** is malformed → fail-open/`unknown` (never read fields off the bare root); inside
   `data`, missing `verdict` → `'unknown'`, missing `score` → `null`, missing `evidence`/`context` → `[]`,
   missing `scored_as` → `null`.

**Test first.** `tests/Check/ClientCheckTest.php` (inject `FakeTransport` + `ArrayCache` + clock, both gates on):
- `test_clean_200_is_fresh_and_parsed` → scripted 200 with an H1 body **wrapped in `{ data: { … } }`**
  → `source==='fresh'`, the `verdict`, bounded `score`, `score_version`, `evidence` (counts + slug
  `categories`), `context`, `expires_at`, and `scored_as` all parsed from inside `data{}`.
- `test_200_without_data_object_is_fail_open` → a 200 whose JSON has the verdict-first fields at the
  **bare root** (no `data` object) is treated as malformed → `source==='fail-open'`,
  `verdict==='unknown'`, `score===null` (defensive: never read fields off the bare root).
- `test_check_forwards_sensitivity_query_param` → `check($ip, ['sensitivity'=>'strict'])` puts
  `sensitivity=strict` in the recorded URL; with no opt, the recorded URL carries `config->sensitivity()`
  (default `balanced`); the value is forwarded verbatim (the client never selects a model — K4).
- `test_malicious_and_clean_both_cached` → a `malicious` 200 and a `clean` 200 are each written to cache
  (every verdict is cached, not just "positive").
- `test_cache_ttl_honors_expires_at` → a 200 whose `expires_at` is sooner than `cache_ttl_hours` is
  cached under the **shorter** TTL (advance the clock past `expires_at` but within the ceiling → the
  entry has expired and a re-check calls out); a 200 with a far-future `expires_at` is capped at
  `cache_ttl_hours`.
- `test_check_appends_v1_check_path_and_sends_key_header` → the fake recorded the exact URL
  `base_url + /v1/check?...` and a `Key:` header; the key is **not** in the URL.
- `test_check_attaches_signals_when_provided` (T5) → `check($ip, ['signals'=>[...]])` on a cache miss
  records the signals object **VERBATIM** on the outbound `/v1/check` request; with no `opts['signals']`
  the request carries none; the signals are **not** in the verdict cache key (a signalled and an
  un-signalled check for the same IP/maxAge/sensitivity resolve to the same cache entry, so signals never
  change the parsed verdict — T2/T3).

**Verify green.** `php vendor/bin/phpunit tests/Check/ClientCheckTest.php`

**Done when.** A clean 200 yields a parsed verdict-first `fresh` result, records breaker success, and
writes it to cache under `min(expires_at, cache_ttl_hours)`; the optional `signals` object is attached
verbatim to the outbound call when the consumer supplies it (never in the cache key); full suite green.

---

## Phase 7 — `Client::check` fail-open matrix + inert gate + breaker interaction

**Change.** Complete `check()` per the §4.2 state machine and §4.6 table. Every fail-open/inert result
is the uniform `CheckResult('unknown', null, null, [], [], null, null, SOURCE_FAIL_OPEN)` (a small private
factory keeps it DRY):
- **Inert** (`!checkActive()`) → the fail-open/unknown result, **zero** transport calls, breaker untouched.
- **Active, cache miss:** consult `breaker->allow()`; if open → fail-open/unknown, **no** socket call.
- On the transport result: `200`+valid JSON → fresh (Phase 6); `429 code=quota_exhausted` →
  **`breaker->recordQuota(Retry-After, X-RateLimit-Reset)`** (park to the reset time, N2) + fail-open,
  **not cached**; `status 0` (timeout/transport) → `recordTransportFailure()` + fail-open, not cached;
  `5xx` → `recordTransportFailure()` + fail-open, not cached; `401/403` → `recordTransportFailure()` +
  fail-open, not cached; other `4xx` (e.g. `422` bad IP) → `CheckResult('unknown', null, null, [], [],
  null, null, SOURCE_FRESH)`, **no** breaker trip, **not** cached (client error — retrying won't help).
  `check()` **never throws** — a JSON-parse failure on a 200, **or a 200 whose body has no top-level
  `data` object** (the A1 envelope), degrades to fail-open/unknown and records a **transport**-class
  failure (treat as a fault). Reading the two 429 headers requires the transport to surface response
  headers; `FakeTransport` scripts them alongside `{status, body}`.

**Test first.** extend `tests/Check/ClientCheckTest.php`:
- `test_inert_off_flag_zero_calls` / `test_inert_empty_key_zero_calls` → fail-open, `verdict==='unknown'`,
  `FakeTransport` recorded **0** calls.
- `test_429_quota_is_fail_open_not_cached_parks_breaker` → after a `429 code=quota_exhausted` with
  `Retry-After`/`X-RateLimit-Reset` headers, `source==='fail-open'`, `verdict==='unknown'`,
  `score===null`, cache empty, and the breaker is **parked to the reset time** (`reason==='quota'`,
  `until` matches the header, not the 60 s transport cooldown).
- `test_timeout_status0_is_fail_open` / `test_5xx_is_fail_open` / `test_401_403_is_fail_open` /
  `test_malformed_200_no_data_is_fail_open` → same fail-open/`unknown`/null/not-cached shape, each a
  **transport**-class breaker failure.
- `test_422_is_client_error_fresh_unknown_no_breaker` → `source==='fresh'`, `verdict==='unknown'`,
  `score===null`, cache empty, breaker **not** tripped.
- `test_breaker_open_fast_fails_without_call` → force the breaker open (Phase 4 helper), then `check` →
  fail-open with **0** transport calls.
- `test_check_never_throws_on_garbage_body` → 200 with non-JSON body → fail-open, no exception.

**Verify green.** `php vendor/bin/phpunit tests/Check/ClientCheckTest.php`

**Done when.** Every non-200 path returns fail-open (`verdict='unknown'`, `score=null`, not cached) and
records a breaker failure except `422` (fresh/`unknown`/no-trip); the inert gate and breaker-open both
fast-fail with zero calls; `check` never throws; full suite green.

---

## Phase 8 — Cache read + TTL semantics

**Change.** Add the cache-read arm at the top of `check()` (before the breaker):
`cache->get('mnc:v:{ip}:{maxAge}:{sensitivity}')` HIT → `CheckResult(stored verdict/score/…,
SOURCE_CACHE)` immediately (no breaker, no transport, no credit). Factor that read into the public
**`cachedVerdict(string $ip, array $opts = []): ?CheckResult`** (design §3.2, SF-2/M5): it builds the
same private key, returns the stored `CheckResult` on a hit or **`null` on a miss** (never a fail-open
placeholder), and **never opens a socket and never consults the breaker** — it is the request-path read
that makes M5's never-sync-call enforceable. It carries **no `signals`** — a cache/mirror read makes no
network call, so the S/T request-shape telemetry rides only the out-of-band `check()` escalation, never
this read (T3). Inert (`!checkActive()`) → `null`. The O1 mirror (design
§3.6) backs the same read: when the injected store resolves an IP from the bulk local mirror,
`cachedVerdict()` returns it with no per-IP call. **An IPv6 `$ip` is normalised to its /64 `score_key`
before the lookup (P2/G2), and the mirror lookup matches range/CIDR/ASN entries by CIDR-containment /
prefix-match (Q2) — a visitor inside a listed /64, /24, or ASN hits; most-specific wins (Q4) — never
exact-IP-only.** `check()` reuses `cachedVerdict()` for its own
cache-read arm. **Keep the key scheme private** behind the method (a consumer never re-derives `mnc:v:*`).
Confirm the write arm (Phase 6) uses **`jitter(min(expires_at − now, cache_ttl_hours*3600), ±10-20%)`**
(the minor expires_at-herd fix) with the absent/past-`expires_at` fallback to the full (jittered) ceiling.
Key normalization: the IP is normalized into the key; `maxAgeInDays` is part of the key so 30-day and
90-day verdicts are distinct, and `sensitivity` is part of the key so a `strict` and a `lenient` verdict
for the same IP (different bands over the one score — K4) don't collide.

**Test first.** extend `tests/Check/ClientCheckTest.php`:
- `test_cache_hit_source_cache_zero_calls` → prime the cache (or run one `fresh` check), then a second
  `check` returns `source==='cache'` with the stored verdict/score and the fake recorded **zero**
  additional calls.
- `test_distinct_maxage_are_distinct_entries` → a `maxAgeInDays=30` hit does not serve a `=90` request.
- `test_distinct_sensitivity_are_distinct_entries` → a `strict` cache entry does not serve a `lenient`
  request for the same IP/maxAge (sensitivity is part of the key — K4), so each calls out on first miss.
- `test_expired_entry_recalls` → with a short `cache_ttl_hours`, advance the clock past TTL → the next
  `check` calls the transport again (fresh), then re-caches.
- `test_expires_at_bounds_ttl_below_ceiling` → a 200 whose `expires_at` is sooner than the ceiling is
  evicted at `expires_at` (advance the clock past it, still within `cache_ttl_hours` → re-call); an
  absent/past `expires_at` falls back to the full ceiling (no zero/negative TTL).
- `test_fail_open_is_not_cached_so_next_check_recalls` → a 429 then a scripted 200 → the second `check`
  actually calls out and returns `fresh` (fail-open/`unknown` was not pinned for the TTL).
- `test_cached_verdict_hit_socket_free_breaker_free` → prime the cache, then `cachedVerdict($ip)` returns
  the stored `CheckResult` with **zero** transport calls; force the breaker OPEN and assert
  `cachedVerdict()` **still** returns the cached verdict (it does not gate on the breaker — M5/SF-2).
- `test_cached_verdict_miss_returns_null` → an unprimed IP → `cachedVerdict()` returns **null** (never a
  fail-open placeholder), zero transport calls.
- `test_cached_verdict_inert_returns_null` → `check_enabled=false` (or empty key) → `cachedVerdict()`
  returns null, zero transport calls.
- `test_cached_verdict_ipv6_normalised_to_64` (P2) → with a `/64` entry primed/mirrored, `cachedVerdict()`
  for any /128 inside that /64 hits it (the IPv6 is normalised to its /64 `score_key` before lookup), and
  two distinct /128s in the same /64 resolve to the **same** entry; a /128 in a different /64 misses.
- `test_cached_verdict_cidr_containment_hit` (Q2/Q4) → a `/24` (v4) or `/64` (v6) mirror entry matches a
  contained visitor IP by **containment**, not exact match; an exact-IP entry for that same IP **overrides**
  the containing range (most-specific wins); a visitor outside every entry → null.
- `test_cache_ttl_is_jittered` → with the jitter seam pinned, two writes of the same `expires_at`/ceiling
  land within the ±10–20% band (guards the expires_at-herd fix), and never a zero/negative TTL.
- `test_cached_verdict_and_cache_hit_send_no_signals` (T3) → a `cachedVerdict()` and a **cache-hit**
  `check()` make **zero** transport calls, so no `signals` ever leave the process even if the consumer
  computed some; only a cache-**miss** `check()` that reaches mainnet can carry them (the escalation-only
  telemetry invariant).

**Verify green.** `php vendor/bin/phpunit tests/Check/ClientCheckTest.php`

**Done when.** A cache hit spends no call and no latency; `cachedVerdict()` is socket-free/breaker-free
and returns null on miss/inert; a cache hit and `cachedVerdict()` carry **no signals** (telemetry rides
only the out-of-band `check()`, T3); TTL expiry re-calls; `expires_at` shortens the (jittered) TTL below
the ceiling (absent/past → full ceiling); distinct `maxAgeInDays`/`sensitivity` are distinct; fail-open is
never pinned; full suite green.

---

## Phase 9 — `Decision` + `ReputationGate::decide`

**Change.** Add `src/Decision.php` (§3.4): `ALLOW|BLOCK|CHALLENGE` constants, static factories
`allow/block/challenge(CheckResult $r)`, and `action()/result()/isAllow()/isBlock()/isChallenge()`. The
`Decision` **carries its `CheckResult`** so a middleware can log the score/source behind a block. Add
`src/ReputationGate.php` (§3.3): `__construct(Client $client, Config $config)`, `decide(string $ip)`, and
`decideCached(string $ip)`. Both share a private verdict→Decision mapper keying on **`verdict`** (H), not
a raw score, and the mapper keys the **fail-policy on `source`, not on `verdict='unknown'` alone** (SF-3):
- inert (`!checkActive()`) → `Decision::allow` (before any check runs — the SF-3 inert carve-out).
- `decide()` runs `client->check($ip)` (**out-of-band/warmer only, M5**); `decideCached()` runs
  `client->cachedVerdict($ip)` (**request-path, socket-free/breaker-free, M5**) and on a **null miss**
  returns `Decision::allow` (fail-open) — the cue to warm out-of-band.
- `verdict ∈ block_verdicts` (default `['malicious','critical']`) → **block** — but when
  `min_block_score` is set, block only if `score !== null && score >= min_block_score` (the optional
  score-threshold override; below the floor falls through to allow).
- `verdict ∈ challenge_verdicts` (default `[]`) → challenge.
- `verdict === 'unknown'` **AND `source === 'fail-open'`** (could-not-check) → `fail_mode==='open'` ?
  allow : block (the default open policy **never blocks on uncertainty**).
- `verdict === 'unknown'` **AND `source === 'fresh'`** (a `422` bad-IP, or a completed 200 with no data)
  → `Decision::allow` **ALWAYS**, under both fail modes (SF-3 — the check ran; `fail_mode` is an outage
  knob and does not apply to a completed check).
- otherwise (`clean`, or a `suspicious` not in a band) → allow.

**Test first.** `tests/ReputationGateTest.php` (inject a `Client` over `FakeTransport`/`ArrayCache`):
- `test_inert_allows` → check off → `decide` allow, zero calls.
- `test_blocks_on_malicious` / `test_blocks_on_critical` → a `malicious` (and a `critical`) verdict → block.
- `test_allows_on_clean` → a `clean` verdict → allow.
- `test_min_block_score_override` → with `min_block_score` set: a `malicious` verdict whose `score` is
  **below** the floor → allow; at/above the floor → block. With `min_block_score` null, the same
  `malicious` verdict → block regardless of score.
- `test_challenge_band_on_verdict` → with `challenge_verdicts=['suspicious']`, a `suspicious` verdict →
  challenge; with the band `[]`, the same verdict → allow.
- `test_failopen_unknown_allows_under_open` → a **could-not-check** `unknown` (`source='fail-open'`,
  from a 429/timeout/5xx) with `fail_mode='open'` → allow.
- `test_failopen_unknown_blocks_under_closed` → the same `source='fail-open'` `unknown` with
  `fail_mode='closed'` → block.
- `test_inert_allows_under_closed` (SF-3) → `check_enabled=false` (or empty key) + `fail_mode='closed'`
  → allow (feature-off ≠ site-off), zero calls.
- `test_422_fresh_unknown_allows_under_closed` (SF-3) → a `422` bad-IP (`source='fresh'`,
  `verdict='unknown'`) + `fail_mode='closed'` → **allow** (a completed check, not could-not-check).
- `test_fresh_200_unknown_allows_under_closed` (SF-3) → a completed 200 whose verdict is `unknown`
  (`source='fresh'`, no data) + `fail_mode='closed'` → **allow**.
- `test_decide_cached_hit_maps_by_verdict_zero_calls` (M5) → prime the cache with a `malicious` verdict
  → `decideCached()` blocks with **zero** transport calls; a `clean` cached verdict → allow.
- `test_decide_cached_miss_allows_both_fail_modes` (M5/SF-3) → an unprimed IP → `decideCached()` returns
  allow under **both** `fail_mode=open` and `closed` (a miss is inert-equivalent), zero transport calls.
- `test_decide_is_out_of_band_and_caches` → `decide()` runs `check()` (a transport call) and populates
  the cache so a subsequent `decideCached()` for the same IP hits (the warmer→request-path handoff).
- `test_decision_carries_result` → the returned `Decision->result()` is the `CheckResult`
  (verdict/score/source available for logging + a verdict-badge UI).

**Verify green.** `php vendor/bin/phpunit tests/ReputationGateTest.php`

**Done when.** Verdict-keyed block (with the optional `min_block_score` override), the verdict challenge
band, the **source-based** fail policy (`source='fail-open'` unknown → open/closed; inert + any
`source='fresh'` unknown → allow under both, SF-3), the out-of-band `decide()` vs request-path
`decideCached()` split (M5), and the miss→allow handoff are all proven, and the evidence `CheckResult`
rides on the `Decision`; full suite green.

---

## Phase 10 — Key-never-leaks (security invariant, cross-cutting)

**Change.** No new product code expected — this phase is a dedicated assertion that hardens the §5
credential-handling invariant against regressions. If any assertion fails, fix the offending path
(URL builder, cache-key builder, exception/log string) to keep the key header-only.

**Test first.** `tests/KeyLeakTest.php` — drive a `fresh` check, a fail-open check, and a cache-hit
check through a `Client` over `FakeTransport`, then assert the key string appears **only** in the
`Key:` header the transport recorded, and **never** in:
- any recorded request URL/query (`test_key_absent_from_url`),
- any cache key written (`test_key_absent_from_cache_keys` — inspect `ArrayCache` keys: only `mnc:v:*`
  and `mnc:breaker`),
- the `CheckResult`/`Decision` (`test_key_absent_from_result` — serialize/inspect, no key substring),
- an exception message on a forced fault (`test_key_absent_from_exception`).

**Verify green.** `php vendor/bin/phpunit tests/KeyLeakTest.php`

**Done when.** The key is provably header-only across fresh/fail-open/cache paths; full suite green.

---

## Phase 11 — Relocated Reporter (port piece B's Phases 1–4)

> **Cross-piece:** this is B's reporter, moved here as `Funnypot\Mainnet\*` (was `Funnypot\Report\*`).
> The behavior is **unchanged** — port B's tests verbatim under the new namespace and the merged
> `Transport`. The point of the phase is to prove the move changed nothing but names.

**Change.**
1. `src/Report/ReportQueue.php` — the contract (`push, take, delete, bumpAttempts, count,
   recentlyReported, markReported, dailyCount, bumpDaily, sensorId`), copied from B §4.2. `push` gains an
   OPTIONAL `signals` payload persisted on the row and returned by `take` so the drain can post it (T5);
   empty/absent => no field. Otherwise the contract is B §4.2 verbatim.
2. `src/Report/Reporter.php` — was `MainnetReporter`. Constructor
   `(ReportQueue $queue, Transport $transport, string $baseUrl, string $apiKey, array $selfIps = [],
   int $dailyCap = 1000, int $dedupHours = 24, CircuitBreaker $breaker = null)` (assign untyped props;
   the optional `$breaker` shares the decision-N `mnc:breaker` marker for N6 — a null breaker degrades
   to the pre-N unbudgeted drain only in tests). `enqueue(string $ip, string $comment, string
   $categories = '21', array $signals = [])` with B's exact guard ladder and reason strings (`no api key` →
   `self ips not configured` → `self` → `not a public ip` → `deduped` → `daily cap` → push + `markReported`) —
   **behavior-neutral from B except two additive deltas: (a) one deliberate IPv6 delta (P2/G2):** the
   `deduped` + `daily cap` bookkeeping keys an IPv6 target on its **/64 `score_key`** (two /128s in one /64
   dedup as the same entity), matching the server's /64 aggregation; the posted body still carries the full
   observed /128 (the server stores /128, aggregates /64, G2); IPv4 keys unchanged; **(b) an OPTIONAL
   `signals` array (S1/T5)** the CONSUMER computed, persisted on the queued row via `push` when non-empty
   and attached to the drain body; empty => omitted; the guard ladder + reason strings are unchanged (the
   signals never gate the enqueue). `drain(int $limit = 200)` posts to `rtrim($baseUrl,'/').'/v1/report'`
   via **this package's `Transport::post`** with body `ip, categories, comment, timestamp=gmdate('c'),
   sensor_id=$queue->sensorId()`, plus `signals` when the row carries a non-empty one (T5). The drain is **hardened per SF-7 + N6** (the only deltas from B):
   - **Consults `mnc:breaker` before its first POST** and skips the tick while OPEN (N3, shared outage
     discovery with the check path).
   - Status branches, **429 split by Error `code`** (SF-7): `2xx` → delete + `bumpDaily` + `sent`;
     `429 code=duplicate_report` → **delete/park the row, NO breaker, NO re-queue loop**;
     `429 code=quota_exhausted` → **`breaker->recordQuota(Retry-After, X-RateLimit-Reset)` + stop the
     tick**, leaving the row queued; other `4xx` (`no_report_rights`, `422`, …) → delete;
     `5xx`/transport → `bumpAttempts`, drop at `>= 3`.
   - **N6 budget:** a `10s` wall-clock budget and **abort after 3 consecutive transport-class failures**,
     writing the shared `mnc:breaker` marker so the next tick and the check path fast-skip.
   - **Bounded re-queue:** attempts + age caps on re-queued rows and a hard queue size cap (oldest
     dropped first). `categoriesForProtocol($protocol)` moved verbatim (`ssh 18,22`, `telnet 18,23`,
     `ftp/smtp/pop3/imap 18`, default `14,15`).
3. `tests/Support/InMemoryReportQueue.php` (array-backed, stable cached `sensorId()` UUID, round-trips the
   optional `signals` payload on the row) and reuse `FakeTransport` (Phase 1) as the recording POST double.

**Test first.** `tests/Report/ReporterTest.php` — port B's **enqueue** cases 1:1 under the new namespace
(behavior-neutral): `test_inert_without_self_ips`, `test_no_key`, `test_never_enqueues_self`,
`test_skips_private_and_invalid`, `test_dedup_one_report_per_window`, `test_daily_cap_blocks_enqueue`,
`test_enqueue_queues_row`, `test_enqueue_ipv6_dedups_by_64` (P2 — two distinct IPv6 /128s within one /64
dedup as the same entity, the second enqueue is `deduped`; a /128 in a different /64 enqueues; the posted
body keeps the full /128; IPv4 dedup unchanged); `test_enqueue_then_drain_posts_parity_body` (body has
`sensor_id`, posts to `base_url + /v1/report`), `test_enqueue_persists_signals_and_drain_posts_them` (T5 —
a non-empty `signals` handed to `enqueue` is stored on the row and appears **VERBATIM** in the drain's POST
body; an omitted/empty `signals` leaves the body byte-for-byte B's, guard ladder unchanged),
`test_daily_cap_stops_the_drain`, `test_drain_drops_4xx`,
`test_drain_retries_5xx_then_drops`, `test_categories_for_protocol`. **Drain SF-7/N6 deltas** (new, not
B-verbatim): `test_drain_dedup_429_drops_no_breaker_no_loop` (a `429 code=duplicate_report` → row
dropped, breaker untouched, not re-queued); `test_drain_quota_429_parks_breaker_and_stops`
(a `429 code=quota_exhausted` → `recordQuota` to the reset header, tick stops, row stays queued);
`test_drain_skips_tick_while_breaker_open` (breaker OPEN → the drain makes zero POSTs);
`test_drain_aborts_after_3_transport_failures_within_budget` (three consecutive `status:0`/5xx → abort,
shared marker written, tick within the 10 s budget); `test_drain_requeue_is_bounded` (attempts/age cap +
queue size cap drop oldest). Plus `tests/Report/ContractsSmokeTest.php` proving `InMemoryReportQueue`
round-trips and `sensorId()` is stable across two calls.

**Verify green.** `php vendor/bin/phpunit tests/Report/ReporterTest.php`

**Done when.** Enqueue-guard parity + `categoriesForProtocol` prove the enqueue path is behavior-neutral
from B **except the P2 IPv6 /64 dedup key** (proven by `test_enqueue_ipv6_dedups_by_64`) **and the additive
optional `signals` payload** (proven by `test_enqueue_persists_signals_and_drain_posts_them` — persisted on
the row + posted verbatim, guard ladder untouched); the drain's
**SF-7 429-code branching** (dedup drop / quota park) and **N6 budget** (breaker-open
skip, 3-fail abort within 10 s, bounded re-queue) are green against in-memory doubles; full suite green.

---

## Phase 12 — `PdoSqliteReportQueue` (port B's Phase 5, real store)

**Change.** `src/Report/PdoSqliteReportQueue.php` implementing `ReportQueue` against a SQLite file:
lazy `db()` (WAL, `busy_timeout=3000`), `CREATE TABLE IF NOT EXISTS` for `mainnet_queue`,
`mainnet_reports`, `mainnet_daily`, and `mainnet_meta(k TEXT PRIMARY KEY, v TEXT)`. Map each contract
method to SQL (per B §Phase 5). `mainnet_queue` gains a **nullable `signals` TEXT column** (a JSON blob)
so `push` persists the optional `signals` payload and `take` returns it for the drain to repost (T5); a
null/absent value posts no `signals` field. **`sensorId()`** (D3): read `mainnet_meta` where `k='sensor_id'`; if
absent, generate a v4 UUID from `random_bytes(16)` (**never** a hardware/MAC id), `INSERT OR IGNORE`,
return it; stable thereafter. **7.3-clean:** untyped `$db` prop with a docblock (not `private ?PDO $db`).

**Test first.** `tests/Report/PdoSqliteReportQueueTest.php` (`markTestSkipped` if
`!extension_loaded('pdo_sqlite')`; temp-file setUp/tearDown): `test_push_take_delete_roundtrip`,
`test_bump_attempts_persists`, `test_dedup_and_daily_bookkeeping` (respects the hours window),
`test_sensor_id_stable_and_persisted` (a fresh queue over the **same file** returns the same UUID),
`test_signals_blob_roundtrips` (T5 — a `push` with a `signals` payload stores the JSON blob and `take`
returns it intact; a `push` with none leaves the column null and `take` returns no `signals`).
(The `mainnet_*`/`abuse_*` independence test from B lives in the app repo, not here — core's `abuse_*`
tables are not in this package.)

**Verify green.** `php vendor/bin/phpunit tests/Report/PdoSqliteReportQueueTest.php`

**Done when.** Store round-trips + `sensor_id` persistence green; skips cleanly without `pdo_sqlite`;
full suite green.

---

## Phase 13 — `Client::report` facade

**Change.** Implement `Client::report(string $ip, string $comment, string $categories = '21', array $signals = []): array`
(§3.1) delegating to the `Reporter` — forwarding the OPTIONAL `$signals` verbatim to `Reporter::enqueue`
(T5; the facade neither computes nor validates it) — (built lazily in the constructor from `Config` if none was injected:
`new Reporter(queue, transport, base_url, key, self_ips, daily_cap, dedup_hours, breaker)` — the same
`CircuitBreaker` the check path uses, so the drain shares the `mnc:breaker` marker for N6). It returns
the Reporter's `{queued:bool, reason:string}`. Report activates on `reportActive()` (key alone, **not**
`check_enabled`) — the guard already lives in `Reporter::enqueue` (`no api key`), so the facade just
forwards. (A consumer that only reports may still construct `Reporter` directly — the facade is a
convenience, confirmed at review per design §7/Open items.)

**Test first.** `tests/Check/ClientReportTest.php`:
- `test_report_delegates_to_reporter` → `Client::report` over `FakeTransport`+`InMemoryReportQueue`
  enqueues a row and returns `{queued:true}`; the recorded facade path matches a direct `Reporter` call.
- `test_report_active_on_key_without_check_enabled` → `check_enabled=false`, key set → `report` still
  enqueues (report does not need the check opt-in).
- `test_report_inert_without_key` → empty key → `{queued:false, reason:'no api key'}`.
- `test_report_forwards_signals_to_reporter` (T5) → `Client::report($ip, $comment, $categories, $signals)`
  with a non-empty `$signals` reaches `Reporter::enqueue` verbatim (row carries it, drain posts it); with
  the arg omitted the enqueued row carries no `signals` (opt-in).

**Verify green.** `php vendor/bin/phpunit tests/Check/ClientReportTest.php`

**Done when.** The unified `Client` fronts both check and report; report gating is key-alone; full suite green.

---

## Phase 14 — 7.3 CI lane (the package's own gate)

> The reporter no longer lives in core, so **piece C's 7.3 matrix does not cover this package** — it
> carries its own lane (design §6, §8).

**Change.** Add a CI workflow (`.github/workflows/ci.yml`) with a **PHP 7.3** job: a `php:7.3` runner (or
container) with **`pdo_sqlite` + `curl` + `sodium`** present, `composer install`, then
`php vendor/bin/phpunit`. Extension presence makes Phase 12's SQLite tests and the real-transport tests
**run** (not skip) on 7.3. Add a lint step (`php -l` over `src/`, or PHPCompatibility set to 7.3) as a
fast fail. Optionally add an 8.x job so both interpreters stay green. Document the local one-off:
`docker run --rm -v "$PWD":/app -w /app php:7.3-cli php -l` over `src/`.

**Notes (not committed as tests):** an opt-in `@group live` integration test (skipped unless
`MAINNET_LIVE_URL`+`MAINNET_LIVE_KEY` are set) proves the real `CurlTransport` against a running A1's
`/v1/check` and `/v1/report` — the 2xx happy path the offline suite deliberately does not cover. It
never runs in normal CI.

**Test first.** No product test — the artifact is the workflow. Prove it by a green 7.3 run in CI (or the
local `php:7.3-cli` parse + suite run) with the three extensions loaded.

**Verify.** CI 7.3 job green with `pdo_sqlite`+`curl`+`sodium`; local `php:7.3-cli -l src/` exits 0.

**Done when.** The package's own 7.3 lane is green with the three extensions present (conditional tests
run, not skip); a lint step guards against 7.4+ constructs; the live `@group live` test is documented and
skips cleanly offline.

---

## Cross-piece dependencies (coordination)

- **This package is upstream of everything.** Land it (Phases 0–14, tagged) **before** the consumers can
  pull it. `funnypot-core` (piece C) gains `require metrictower/mainnet-client` and re-exports it; D/E get
  it transitively; non-honeypot consumers require it directly, without the engine.
- **Piece B is superseded here.** B's `funnypot-core/src/Report/` tree is **dropped** — its Phases 1–6
  land in this package (Phases 11–12 above) as `Funnypot\Mainnet\*`; B's Phase 8 app wiring retargets the
  new namespace (`Funnypot\Mainnet\Reporter` + `PdoSqliteReportQueue`) and pulls this package via
  `composer update metrictower/funnypot-core`. Update B's plan to point `src/Report/` at this repo.
- **Piece C:** remove `src/Report/` from C's 7.3 conversion scope (it never lands in core); C keeps
  `pdo_sqlite`+`curl`+`sodium` in its container for the rest of its suite, but this package's reporter
  tests move to this package's own 7.3 lane (Phase 14).
- **D / E:** add the reputation-check/block feature on the **policy-port model (M5), never inline** —
  settings for `check_enabled`, `MAINNET_KEY`, `block_verdicts` (default `malicious`/`critical`),
  optional `min_block_score`, optional `challenge_verdicts`, `cache_ttl_hours`; bind a `Psr16Cache` over
  WP transients/object-cache or Laravel `Cache`. **In-path:** the middleware calls
  `ReputationGate::decideCached($request->ip())` (or reads `cachedVerdict()`) — cache/mirror only, no
  socket, no breaker; the `ReputationInterface` adapter D/E expose to `funnypot-policy` MUST call
  `cachedVerdict()` (SF-2). **Out-of-band:** a scheduled warmer/cron drains uncached IPs through
  `decide()`/`check()` and writes verdicts to the shared cache (E ships it v1; D promotes its warmer to
  v1 — never an inline check; on the sync queue driver run it via the scheduler, not inline). **O1:** a
  cron pulls the thin G3 blacklist artifact into the policy StateStore so `cachedVerdict()` resolves most
  IPs from the mirror; per-IP `check()` warms only the uncertain escalation set. The block/challenge UI
  shows a **verdict badge + evidence**, not a bare score (A2's H ripple). Off by default. They keep
  binding their own `ReportQueue` for the report path.
- **A1 (server):** no structural change. F's `429` path depends on A1's machine-readable Error `code`
  (`quota_exhausted` vs `duplicate_report`, SF-7) and **`Retry-After`/`X-RateLimit-Reset` on both 429
  forms** — F reads the status **and** those retry headers now (decision N2, superseding "status only").
  F's consumers hold `service`/check-quota keys (D5). Exact prod `MAINNET_BASE_URL` host is still an A1
  open decision (placeholder until then).

## Risks & open decisions

1. **`block_verdicts = ['malicious','critical']` default, `min_block_score` unset, challenge band off.**
   H's verdict rubric (H2) already requires distinct-reporter diversity for the top bands (aligns with
   D4's ≥2-distinct-`source_ip`), so verdict-only blocking is corroborated by construction — no raw
   number to tune. A sophisticated consumer adds a score floor via `min_block_score` (the bounded 0–100
   score keeps A1's 39/63/92 anchors, H3). Confirm the default set once A1 publishes its final banding.
   **Open (design §Open items).**
2. **Transport 2xx has no offline unit coverage** (the suite is deliberately network-free). Only the
   `@group live` test (Phase 14 notes) and the consumers' integration prove a real 200. Called out so it
   is not mistaken for a gap.
3. **7.3 conformance is this package's own gate now (Phase 14), not C's.** The dev host is PHP 8.x, so
   `php -l` locally is blind to 7.4+ syntax — the 7.3 CI job is the authoritative check. Until it is
   wired, `src/` is unpoliced; mitigate with a one-off `php:7.3-cli` read.
4. **`Client::report` facade vs. `Reporter` directly** (design §7 / Open items). This plan builds the
   facade (Phase 13) but keeps `Reporter` independently constructible. Confirm the extra surface is worth
   it at review; if not, drop Phase 13 and expose `Reporter` only.
5. **Reporter ENQUEUE stays behavior-neutral except the P2 IPv6 dedup key; the DRAIN changes
   deliberately.** Phase 11 ports B's enqueue suite verbatim (guard order, reason strings) — any drift
   there is a regression — with **one deliberate delta:** an IPv6 target's `deduped` + `daily cap` key is
   its /64 `score_key` (P2/G2), so two /128s in one /64 dedup as one entity (the posted body still carries
   the full /128). The **drain** is intentionally not B-verbatim: SF-7 splits the old unconditional
   "429 → re-queue" into
   `duplicate_report` (drop) vs `quota_exhausted` (park), and N6 adds the budget/abort/bounded-re-queue.
   Keep B's tests as the oracle for enqueue; the drain's oracle is the SF-7/N6 spec (design §4.7).
6. **`psr/simple-cache` stays a `suggest`.** `Psr16Cache` references the interface; guard the package (and
   its test) so a bare 7.3 host without the PSR package still installs and runs — the adapter's test must
   ship its own tiny PSR-16 fake, not `require` the real package.
7. **Cross-repo ordering.** Nothing consumes this until it is tagged and `composer`-pullable; sequence the
   B/C/D/E edits after F lands. A `dev-main` VCS require (like core's) is the likely interim wiring.
8. **O1 — local-mirror-lite is the PRIMARY fresh-read (decision O1, v1 wiring in D/E).** Keep the
   `Cache`/store seam (Phase 2, design §3.6) generalizable to a bulk/local backing — do **not** bake a
   per-IP-only lookup assumption into `ReputationGate`/`Client`, so `cachedVerdict()` can read a bulk
   local mirror the same way it reads a warmed verdict. In v1, D/E pull the thin G3 blacklist artifact
   on cron into the policy StateStore (mirror population lives in D/E, not this package); per-IP
   `check()` is escalation-only for uncertain IPs. This package builds no mirror — it only guarantees
   the seam reads one. The `/v1/changes` delta feed (decision I) remains the later reserved upgrade of
   the same seam (still forward-compat, not v1).
9. **Optional `signals` shape is consumer-defined + A1-owned (S/T5).** F carries the `signals` object
   verbatim on check + report and does not model its fields; A1's OpenAPI spec reserves the shape and owns
   validation. The GET-check wire encoding (a compact JSON `signals` query param vs. a dedicated telemetry
   field) tracks A1's OpenAPI spec once published — F forwards whatever the consumer hands it, so this is
   an A1/OpenAPI decision, not an F redesign. Signals are opt-in telemetry that must never enter the
   verdict cache key, a log, or the request-path `cachedVerdict()` read (T2/T3).

## Definition of done

- `Funnypot\Mainnet\{Client, CheckResult, ReputationGate, Decision, Config, CircuitBreaker}`, the
  `Cache\{Cache, ArrayCache, NullCache, Psr16Cache}` seam, the `Transport\{Transport, CurlTransport,
  StreamTransport}` set, and the relocated `Report\{ReportQueue, Reporter, PdoSqliteReportQueue}` all
  exist under `src/`, **all 7.3-syntax-clean** (untyped props; scalar/array param+return types kept).
- `php vendor/bin/phpunit` green from `mainnet-client/`, covering: the two independent gates
  (`checkActive`/`reportActive`); a clean 200 → verdict-first `fresh` parse
  (`verdict`/`score`/`score_version`/`evidence`/`context`/`expires_at`/`scored_as`) + cache write of every verdict
  under `jitter(min(expires_at, cache_ttl_hours))`; the full fail-open matrix (inert / quota-429 /
  timeout / 5xx / 401·403 / malformed-200 / breaker-open) each `verdict='unknown'`/`score=null`, not
  cached, breaker-recorded (transport class; quota-429 parks to the reset header; except `422` =
  client-error `fresh`/`unknown`/no-trip); **`cachedVerdict()` socket-free/breaker-free** (hit returns
  the stored verdict even with the breaker open, miss→null, inert→null); **IPv6 /64 normalisation +
  CIDR-containment** (`cachedVerdict()` on an IPv6 in a listed /64 hits by containment, two /128s in one
  /64 resolve to one entry, a /24 row matches a contained IPv4 visitor, an exact-IP entry overrides its
  containing range — P2/Q2/Q4); cache hit = `source='cache'`
  zero calls; TTL expiry and `expires_at` both re-call; the **decision-N breaker** (transport vs quota
  classes, 60 s ±20% cooldown, quota park-to-reset + 6h cap, single-flight half-open, shared marker +
  filemtime fallback, store-fault-fails-open — the 8 N7 tests); `ReputationGate` verdict-keyed block (+
  optional `min_block_score`) + verdict challenge band + the **source-based** fail policy
  (`source='fail-open'` unknown → open/closed; inert + any `source='fresh'` unknown → allow under both,
  SF-3) + the out-of-band `decide()` / request-path `decideCached()` split (M5); the P3 `scored_as`
  round-trip on `CheckResult`; **key never leaks** to
  URL, cache key, result, or exception; and the **relocated reporter** — enqueue-guard parity (+ the P2
  IPv6 /64 dedup key: two /128s in one /64 dedup as one entity, the posted body keeps the /128) + the
  drain's **SF-7 429-code branching** (dedup drop / quota park) + **N6 budget** (breaker-open skip,
  3-fail abort within 10 s, bounded re-queue), `categoriesForProtocol`, `sensor_id` persistence, SQLite
  round-trips.
- **Optional `signals` telemetry (S/T5):** `check()` attaches a consumer-supplied `signals` object
  verbatim to the outbound `/v1/check` escalation call (never in the verdict cache key, never on a cache
  hit, never on `cachedVerdict()` — T2/T3); `report()`/`enqueue()` persist a non-empty `signals` on the
  queued row and post it in the `/v1/report` drain body; empty/omitted => no field. The client never
  computes or validates signals.
- `Client::check` **never throws**; every fault degrades to a fail-open `CheckResult`; the site is never
  taken down under the default `fail_mode=open`.
- The package's **own 7.3 CI lane** (Phase 14) is green with `pdo_sqlite`+`curl`+`sodium` (conditional
  tests run, not skip); a lint step guards 7.4+ constructs; the `@group live` test skips cleanly offline.
- **Zero hard runtime deps** (`composer install` on a bare 7.3 host with no `psr/simple-cache` succeeds
  and the suite runs); PSR-4 roots resolve; `composer validate` passes.
- Cross-piece ripples recorded: B's `src/Report/` retargets here, C drops it from its 7.3 scope, D/E wire
  `ReputationGate` off-by-default. No security invariant touched — no `require`/RCE surface (client only
  reads status + JSON), key is header-only over HTTPS with cert verification on.

## Key decisions I made (confirm at review)

1. **Fifteen phases (0–14), primitives-up then reporter.** Skeleton → Transport → Cache → Config →
   Breaker → CheckResult → check happy-path → check fail matrix → cache/TTL → Gate/Decision → key-leak →
   Reporter relocation → SQLite queue → report facade → 7.3 lane. Every phase keeps `phpunit` green.
2. **Transport is unified first (Phase 1)** so the relocated reporter (Phase 11) reuses the same
   `CurlTransport`/`StreamTransport` (its POST) and check reuses their GET — one interface, B's post
   semantics unchanged.
3. **Cache and Breaker precede the Client** so `check`'s cache-read/breaker arms are testable against
   real seams (injected `ArrayCache` + clock), not mocks.
4. **The check happy path (Phase 6) is split from the fail matrix (Phase 7)** so the parse/cache-write is
   proven before the (larger) fault taxonomy — each a small green step.
5. **Key-leak is its own phase (10).** It is a security invariant (§5) worth a dedicated regression guard
   across fresh/fail-open/cache paths, not folded into the happy path.
6. **The reporter relocation ports B's tests verbatim (Phases 11–12).** B is the oracle; the phase proves
   the move changed nothing but names + the shared transport — no redesign.
7. **This package owns its 7.3 lane (Phase 14).** Decision F pulled the reporter out of core, so C's
   matrix no longer covers it; the gate moves here with the code.

---

## Review resolutions applied (2026-08-19)

### H — verdict-first output model (cites H1–H6)

Applies decision H to F's plan, in lockstep with the design's H subsection. Builds on decision G (the
bounded score is G1's decayed accumulator mapped to 0–100; G is not undone).

- **Phase 3 (Config):** `fromArray` keys and defaults swap score-only `block_threshold`/
  `challenge_threshold` for `block_verdicts=['malicious','critical']` + optional `min_block_score`
  (null) + optional `challenge_verdicts` ([]); `cache_ttl_hours`/`timeout_ms`/breaker unchanged (H1).
- **Phase 5 (CheckResult):** value object + tests rewritten to the H1 schema — `VERDICT_*` enum
  (`unknown` distinct from `clean`), `score`+`score_version`, derived `isMalicious()`/`isSuspicious()`,
  `evidence{…}`, `context{…}`, `expires_at`, keeping `source`.
- **Phase 6 (happy path):** parse the verdict-first 200 body; cache TTL = `min(expires_at − now,
  cache_ttl_hours)` with the absent/past fallback; every verdict cached (H1).
- **Phase 7 (fail matrix):** the uniform fail-open/inert result is `verdict='unknown'`, `score=null`,
  empty evidence/context; `422` → fresh/`unknown`; tests assert the verdict on each branch.
- **Phase 8 (cache/TTL):** added the `expires_at`-bounds-TTL test alongside the ceiling-expiry test.
- **Phase 9 (Gate):** `decide` keys on **`verdict`** — block when `verdict ∈ block_verdicts` (optional
  `min_block_score` tightens it), challenge when `verdict ∈ challenge_verdicts`, `unknown`→
  allow(open)/block(closed) so the fail-open path never blocks on uncertainty (H1/H2).
- **Cross-piece + Risks + DoD:** D/E settings/UI use verdicts + evidence badge (A2's H ripple); the
  `block_threshold=75` risk is replaced by the verdict-rubric note; DoD reflects verdict-keyed
  coverage. AbuseIPDB parity dropped (H4); A1's OpenAPI spec is the wire source F tracks (H5); H6
  calibration needs no F change (F surfaces A1's `score_version` verbatim).

### I+J — sync-feed forward-compat + no-CORS (cites decisions I, J)

Layers on top of H; undoes nothing. Reservations/requirements, not v1 build work.

- **I — local-mirror-capable store seam (Risks #8).** Added a reserved forward-compat risk item: keep
  the Phase-2 `Cache`/store seam generalizable to a bulk/local backing (no per-IP-only lookup
  assumption in `ReputationGate`/`Client`), so a future local-mirror mode can answer the gate from a
  synced local bad-IP DB (mainnet's reserved `/v1/changes` feed) without a per-IP `check()`. No mirror
  built in any phase — reservation only, mirroring the design's §3.6 + Open-items note.
- **J — no-CORS on the keyed API: no plan change.** J is a server-side (A1) requirement; F holds the
  key server-side (`Key:` header only, Phase 10 key-never-leaks) and makes no browser call, so the
  no-CORS posture adds no client-side work. Noted for traceability.

### K + re-review + L — sensitivity band selector + {data:…} envelope

Layers on top of H/I/J; undoes nothing. Applies decision K4 and the re-review's program-wide envelope
convention to F's plan, in lockstep with the design's matching subsection.

- **K4 — `sensitivity` band selector (Orientation, Phases 3/6/8).** `Config::fromArray` gains the
  `sensitivity` key (default `balanced`) and a `sensitivity()` getter (Phase 3, with default + mapping
  tests). `Client::check` reads `opts['sensitivity']` (else the Config default) and forwards it verbatim
  as the `/v1/check` `sensitivity` query param (Phase 6, `test_check_forwards_sensitivity_query_param`).
  Per K4 this selects A1's **band thresholds over the ONE published model's calibrated score** — the
  client never chooses a model. `sensitivity` joins the verdict cache key
  (`mnc:v:{ip}:{maxAge}:{sensitivity}`, Phases 6/8) so a `strict` and a `lenient` result for the same IP
  don't collide (`test_distinct_sensitivity_are_distinct_entries`). `ReputationGate` (Phase 9) is
  unchanged — it still keys on the returned verdict, which already reflects the requested sensitivity.
- **Re-review major #5 — `{ data: … }` envelope (Orientation, Phases 6/7).** A1 standardized every
  `/v1` success body under a top-level `{ "data": … }` envelope, so `check`'s `200` body is now
  `{ "data": { verdict, score, … } }` (was a bare top-level object). Phase 6 parses the H1 fields from
  inside `json['data']` (`test_clean_200_is_fresh_and_parsed` uses a `data`-wrapped body); a `200` with
  no `data` object degrades to fail-open/`unknown` (`test_200_without_data_object_is_fail_open`, Phase 6;
  reinforced in the Phase 7 malformed-body branch). Field names stay native snake_case (no AbuseIPDB
  parity names).
- **L — no new F build.** The gap-analysis reservations (L1–L6) add no plan work to F: the consumer-side
  decision overlay (L6) lives in D/E config, and `CheckResult`'s `context` is already an extensible
  struct. No phase change beyond noting it stays extensible.

### N + O + future-proofing (cites decisions N, O1; review items MF-3, SF-1, SF-2, SF-3, SF-7, minor)

Applies decisions N (global fail-open cooldown) and O1 (fleet-read), plus the future-proofing review's
F-scoped items, to F's plan in lockstep with the design's matching subsection.

- **MF-3 — request-path vs out-of-band (Orientation, Phases 8/9).** Added the M5 constant; the request
  path is `cachedVerdict()` (Phase 8) / `decideCached()` (Phase 9), the network `check()`/`decide()` are
  out-of-band warmers. Rewrote the D/E cross-piece to the policy-port model; the app CRS first-gate is a
  cache-first modifier, no longer inline.
- **SF-2 — `cachedVerdict()` (Phase 8).** New public request-path read: cache/mirror only, socket-free,
  breaker-free, `null` on miss; the cache-key scheme stays private behind it; adapters MUST use it. Added
  the socket-free/breaker-free/miss-null tests.
- **SF-1 = decision N — CircuitBreaker rewritten (Phase 4, plus Phases 1/6/7/11 ripples).** Phase 4 now
  implements N1–N7: shared marker + filemtime fallback, transport (5 / 60 s ±20%) vs quota (trip-on-1 /
  park-to-reset / cap 6h) fault classes, single-flight half-open, never-breaks — with the 8 N7 tests.
  Transport gained a `headers` map (Phase 1) so the breaker reads `Retry-After`/`X-RateLimit-Reset` on a
  429 (Phase 7). Config default cooldown 30 → **60**, added `quota_park_cap_secs` (Phase 3). Resolved the
  ArrayCache/NullCache default: NullCache is the injected default, both are breaker-inert, the filemtime
  marker carries state, and a persistent shared cache is REQUIRED for check-enabled operation.
- **SF-3 — source-based fail policy (Phases 7/9).** `fail_mode=closed` blocks only `source='fail-open'`
  unknowns; inert and any `source='fresh'` unknown (422 bad-IP or a completed 200 with no data) allow
  under both fail modes. Added `test_inert_allows_under_closed`, `test_422_fresh_unknown_allows_under_closed`,
  `test_fresh_200_unknown_allows_under_closed`.
- **SF-7 — 429 branched on Error `code` (Phases 7/11).** Check-path 429 = quota park; report-drain 429
  splits `duplicate_report` (drop, no breaker, no loop) vs `quota_exhausted` (park + stop). The breaker
  trips only on quota/transport. Replaced `test_drain_requeues_429` with the code-split + N6-budget tests.
- **N6 — drain budget (Phase 11).** The Reporter takes the shared `CircuitBreaker`; the drain consults
  `mnc:breaker`, has a 10 s budget, aborts after 3 consecutive transport failures, and bounds re-queue.
- **O1 — local-mirror-lite primary (Orientation, Phase 8, Risks #8).** `cachedVerdict()` reads a bulk
  local mirror the same way it reads a warmed verdict; per-IP `check()` is escalation-only. D/E populate
  the mirror from the thin G3 artifact on cron (their work, not this package). `/v1/changes` stays the
  reserved delta-upgrade.
- **Minor — TTL jitter (Phases 6/8).** The `expires_at`-derived cache TTL is jittered ±10–20% (via an
  injectable jitter seam so tests stay deterministic).

### P/Q/R — IPv6 hardening + range/ASN reputation + country policy (cites decisions P2, P3, Q1, Q2, Q4)

Layers on top of H/I/J/K/L/N/O; undoes nothing. Applies the entity/geo decisions to F's plan in lockstep
with the design's matching subsection. R (country policy) has no F surface.

- **P3 — `scored_as` on `CheckResult` (Phases 5/6/7).** Phase 5 adds the `scored_as` field (the CIDR/IP
  the verdict was computed for: /64 v6, /128 v4) + a `scoredAs()` getter + `test_scored_as_roundtrips`
  (fail-open carries `scored_as=null`); the constructor gains the arg before `$source`. Phase 6 parses
  `data.scored_as` from the envelope and asserts it in `test_clean_200_is_fresh_and_parsed` (missing →
  null); Phase 7's uniform fail-open + 422 `CheckResult` literals carry the extra `null` arg.
- **P2/Q2 — IPv6 /64 normalisation + CIDR-containment on the request-path read (Orientation, Phase 8).**
  `cachedVerdict()` normalises an IPv6 to its /64 `score_key` before the lookup (agrees with the server's
  /64 aggregation, G2) and the mirror lookup matches range/CIDR/ASN entries by CIDR-containment (Q2),
  most-specific wins (Q4). Added `test_cached_verdict_ipv6_normalised_to_64` and
  `test_cached_verdict_cidr_containment_hit`.
- **P2/G2 — IPv6 /64 dedup key on the reporter (Orientation, Phase 11, Risks #5).** The enqueue path keys
  an IPv6 target's `deduped` + `daily cap` bookkeeping on its /64 `score_key` (the sole deliberate delta
  from B's verbatim enqueue; the posted body still carries the full /128 the server stores + aggregates at
  /64). Added `test_enqueue_ipv6_dedups_by_64`; Risk #5 + the Phase-11 "Done when" updated to name the delta.
- **Q1 — range/ASN mirror rows: no new F build.** F only adds containment matching (Phase 8); the rows
  themselves are the thin G3 artifact D/E pull, and auto-rollup + range-allowlist (Q3/Q4) are
  A1/ScoringModel work.
- **R — country policy: no plan change.** R's country gate is funnypot-policy + D/E config over a LOCAL
  GeoIP DB; it never touches mainnet-client's request-path read or report key. Noted for traceability.

### S/T — signals+telemetry (cites decisions S1, T1–T5)

Layers on top of H/I/J/K/L/N/O/P/Q/R; undoes nothing. Applies decision S (request-shape bot signals) and
decision T (signals ride check + report; check-carried signals are low-trust async telemetry) to F's plan,
in lockstep with the design's matching subsection. F only **carries** signals — the client never computes
them (S1: computation is funnypot-core.classify / the policy adapter). Minimal + consistent with the
existing M5 / `cachedVerdict()` / decision-N / `scored_as` edits.

- **T5 — optional `signals` on `check()` (Orientation, Phase 6).** `Client::check`'s `$opts` gains an
  optional `signals` array; Phase 6 attaches it VERBATIM to the outbound `/v1/check` request when the
  consumer supplies it (opt-in, omitted when absent) and adds `test_check_attaches_signals_when_provided`.
  It is **not** part of the verdict cache key and never changes the parsed verdict (T2).
- **T3 — signals ride the escalation check ONLY (Orientation, Phase 8).** `cachedVerdict()` carries no
  signals (a cache/mirror read makes no network call), and a **cache-hit** `check()` sends none either;
  only a cache-**miss** `check()` that reaches mainnet can carry them. Added
  `test_cached_verdict_and_cache_hit_send_no_signals`. O1 mirror-hits stay local, bounding telemetry
  volume; A1 async-queues the signals off the read path (never the abuse score, never a synchronous write).
- **T5 — optional `signals` on the report path (Phases 11/12/13).** `Reporter::enqueue` and
  `Client::report` gain a trailing `array $signals = []`; `ReportQueue::push` persists it and `take`
  returns it (Phase 11); `PdoSqliteReportQueue` adds a nullable `signals` JSON column (Phase 12); the drain
  posts it in the `/v1/report` body when non-empty. Added `test_enqueue_persists_signals_and_drain_posts_them`
  (Phase 11), `test_signals_blob_roundtrips` (Phase 12), and `test_report_forwards_signals_to_reporter`
  (Phase 13). A single additive, behaviour-neutral delta on B's enqueue path — the guard ladder + reason
  strings are unchanged (signals never gate the enqueue).
- **T4 — opt-in + disclosed.** Sending visitor request-shape signals is telemetry → opt-in (like check
  itself) and disclosed in the consent posture (D2/GDPR); signals never enter the verdict cache key, a log,
  or carry the key (Phase 10's key-leak guard is unaffected — signals hold no credential).
- **DoD + Risks.** Added the `signals` DoD clause and Risk #9 (consumer-defined shape, A1-owned validation,
  GET-check wire encoding tracks A1's OpenAPI spec).
