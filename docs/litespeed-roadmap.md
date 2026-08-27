# LiteSpeed / OpenLiteSpeed Roadmap & Task Board

**Date:** 2026-08-27  
**Status:** Draft — Prioritized task board, references `docs/litespeed-research.md` & `docs/litespeed-integration-plan.md`  
**How to use:** Each task has an ID (`LS-0xx`), effort, dependencies, acceptance criteria, and verification. Check off in this doc or move to GitHub Issues / PRs. Review `Open Questions` in `litespeed-integration-plan.md:19` before starting LS-10x.

---

## 1. Summary Timeline

```
Phase 0  Fix detection          ████  S  → PR #1 (can ship solo)
Phase 1  Safe coexistence       ██████ M → PR #2 (depends 0)
Phase 2  Purge sync             ██████ M → PR #3 (depends 0-1)
Phase 3  LS-native acceleration ████████ M-L → PR #4 (depends 0-2)
Phase 4  Next-gen + Brotli      ██████ M → PR #5 (depends 0)
Phase 5  Enterprise/QUIC opts   ░░░░░░ L → deferred
```

S = <1 day · M = 1-3 days · L = ~1 week · Effort per task is solo-dev, including tests + lint + build.

---

## 2. Task Board

### Phase 0 — Fix Server Detection (Prerequisite)

| ID | Task | Effort | Depends | Acceptance |
|---|---|---|---|---|
| **LS-001** | Patch `Server_Rules::get_server_type()` → return `litespeed` for `litespeed/openlitespeed/ols` banner, before `apache/nginx` checks | S | — | `curl -I` + unit test with 6 banners passes; `is_litespeed()` helper added |
| **LS-002** | Update `GET server_rules` REST to populate `apache` rules even when `server_type=litespeed` + new `litespeed` key in response | S | LS-001 | `wp eval` + REST `server_rules` shows `server_type=litespeed` + `apache` populated |
| **LS-003** | SPA — `FileOptimization.js` Network tab: `litespeed` branch shows "LiteSpeed (Apache-compatible)" + Apache rules `<pre>`, not the `other` warning | S | LS-001, LS-002 | Visual check in Network tab on OLS host; existing `other` path unchanged |
| **LS-004** | Add `litespeed: { detected, server_type, lscache_active }` to `System_Info::get_all()` / REST `system_info` for SPA banner data | S | LS-001 | `GET system_info` includes new group; `Dashboard.js` can read it |
| **LS-005** | Unit + JS tests for Phase 0 — `ServerRulesTest` litespeed data provider, `FileOptimization.test.js` litespeed branch | S | LS-001-LS-004 | `composer test` + `npm test` green, `npm run lint:js` + `composer lint` pass |

### Phase 1 — Compatibility & Safe Coexistence

| ID | Task | Effort | Depends | Acceptance |
|---|---|---|---|---|
| **LS-101** | New class `includes/class-litespeed-integration.php` — `is_litespeed()`, `is_lscache_active()` (active_plugins+sitewide+`LSCWP_V`+`class_exists`), `get_mode()`, `effective_mode()`, `is_wppo_cache_owner()`, `should_disable_wppo_optimizer()` | M | LS-001 | `Main::includes()` loads it; cheap per-request caching; `@since NEXT`; 100% unit coverage |
| **LS-102** | Add `litespeed_integration` defaults to `Main::get_default_settings()` — `mode=auto`, `enableNextGenRewrite=false`, `enableBrotli=false`, `purgeSync=true`; sanitize via `Util::sanitize_settings_recursively()` + REST allowlist | S | LS-101 | `wppo_settings` round-trip via REST `update_settings tab=litespeed_integration`; invalid `mode` rejected |
| **LS-103** | Optimizer guards — early return in `Main::minify_css/combine_css/minify_js/add_defer_strategy/add_defer_attribute/delayJS`, `Cache::minify_buffer/maybe_apply_cdn` when `should_disable_wppo_optimizer()` | M | LS-101 | With LSCWP active + mode=litespeed, view-source shows 0 `wppo-src` / `wppo-combine-css` tags; with mode=wppo, WPPO tags present |
| **LS-104** | `litespeed_can_optm` / `litespeed_can_cdn` cooperation — respect `apply_filters('litespeed_can_optm', true)` negative | S | LS-103 | Stub filter `__return_false` → our optimizer skipped |
| **LS-105** | Admin notice + SPA banner — `class-admin-notices.php` dismissible when `is_litespeed && is_lscache_active && effective_mode=auto`, `Dashboard.js` / `FileOptimization` `NoticeBanner` + `StatusBadge` when LS detected | S | LS-101 | Banner shows detected/lscache/effective; toggles disabled with tooltip when optimizer paused (test `hasClass('disabled')`) |
| **LS-106** | Drop-in arbitration UI — `advanced-cache.php` + `object-cache.php` status in REST `system_info` → SPA shows collision warning + CTA | S | LS-101 | Shows `Page cache drop-in: WPPO|LSCache|None` + `Object cache: WPPO|LSCache` |
| **LS-107** | `.htaccess` ordering guard — `Htaccess_Handler::update_rules()` must not reorder `# BEGIN LSCACHE` above `# BEGIN WordPress`; add comment + allow `litespeed` to trigger update (today only `apache`) | S | LS-101 | Existing `.htaccess` with LSCACHE block preserved after `update_rules(true)`; test with fixture |
| **LS-108** | Tests for Phase 1 — `LiteSpeedIntegrationTest`, extend `CacheTest`/`MainTest` for guards, `FileOptimization.test.js` pause tooltip | S | LS-101-LS-107 | `composer test` + `npm test` green |

### Phase 2 — Purge Sync & Cache-Control Bridge

| ID | Task | Effort | Depends | Acceptance |
|---|---|---|---|---|
| **LS-201** | WPPO→LS purge — `Cache::clear_cache('all')` → `do_action('litespeed_purge_all')`, single page → `do_action('litespeed_purge_url', $url)`, `invalidate_dynamic_static_html($id)` → `do_action('litespeed_purge_post', $id)`; gated by `is_litespeed && is_lscache_active && purgeSync` | S | LS-101 | With LS+ LSCWP active, `Cache::clear_cache()` triggers `has_action('litespeed_purge_all')` mock exactly once (Brain Monkey) |
| **LS-202** | LS→WPPO purge — `LiteSpeed_Integration::init()` hooks `litespeed_purged_all/post/purge_finalize` → `Cache::clear_cache()` / `invalidate_dynamic...` with `wppo_litespeed_purge_lock` transient (60s, blog-prefixed) to avoid loop | S | LS-101 | Stub `do_action('litespeed_purged_all')` → WPPO files deleted; nested purge doesn't recurse |
| **LS-203** | Extend `CDN_Purger::purge_all()` with `purge_litespeed()` helper (calls `litespeed_purge_all/url` when LS active) | S | LS-101 | Single `purge_all` clears Cloudflare+Varnish+LS in one call (verify via `Util::transient_key()` lock) |
| **LS-204** | Tests for Phase 2 — mock `do_action` expectations, loop-lock assertion, `CDN_PurgerTest` litespeed branch | S | LS-201-LS-203 | PHPUnit green; no infinite-loop regression |

### Phase 3 — Server-Level Acceleration

| ID | Task | Effort | Depends | Acceptance |
|---|---|---|---|---|
| **LS-301** | LS-native header emission — when `is_litespeed && is_wppo_cache_owner && is_cacheable`, emit `X-LiteSpeed-Cache-Control: public,max-age=N` (mapped from `cacheLife` 0→604800) + `X-LiteSpeed-Tag WPPO` + per-post tag via `send_headers:0`; dual path: `do_action('litespeed_control_set_ttl')` when hook exists, else raw `header()` | M | LS-101, Phase 1 | `curl -I` on cacheable page shows `X-LiteSpeed-Cache-Control: public,max-age=604800` (or `no-cache` on DONOTCACHEPAGE); non-LS host shows no header |
| **LS-302** | Bypass path — when `is_litespeed && !is_wppo_cache_owner`, skip `save_processed_buffer()` (still `process_buffer_only()` for CDN/used-css) + emit `no-cache` for non-cacheable routes LS would otherwise cache | S | LS-301 | With mode=litespeed, `cache/wppo/{domain}/.../index.html` not written (check `!file_exists`), but LS serves via its own layer |
| **LS-303** | Vary bridge — when `is_litespeed && is_wppo_cache_owner && enableLoggedInCache`, append `wppo_role_hash` to `litespeed_vary_cookies` filter (only when LSCWP hook exists), else raw `Vary: Cookie` | S | LS-301 | With logged-in cache on, Vary includes role cookie; guest page Vary unchanged |
| **LS-304** | TTL/stale alignment — document `cacheLife→max-age` mapping, ensure `Cache-Control` not conflicting with `X-LiteSpeed-Cache-Control` (strip generic `Cache-Control` when LS header sent) | S | LS-301 | Headers don't contradict (`Cache-Control: no-cache` not sent alongside `X-LiteSpeed-Cache-Control: public`) |
| **LS-305** | Tests + manual TTFB check — header assertions per route type, `curl -I` TTFB before/after (expect 200→50ms on LS when hot) | S | LS-301-LS-304 | Unit tests per route (is_singular, is_feed, DONOTCACHEPAGE); manual `ab -n 100` TTFB log in PR description |

### Phase 4 — Image & CDN — Server-Level Next-Gen

| ID | Task | Effort | Depends | Acceptance |
|---|---|---|---|---|
| **LS-401** | `Vary: Accept` + rewrite to `.webp/.avif` in `Htaccess_Handler::get_rules()` behind `enableNextGenRewrite` (opt-in, default false) + `AddType image/webp/avif` | M | LS-001 | `.htaccess` contains rewrite block when LS+ setting on, absent otherwise; `RewriteCond %{HTTP:Accept} image/webp` + `Vary: Accept` present |
| **LS-402** | Nginx equivalent — `Server_Rules::get_nginx_rules()` `map $http_accept` → `try_files $uri$avif/$webp` | S | LS-401 | `GET server_rules` nginx field includes next-gen map when setting on |
| **LS-403** | Brotli `.br` alongside `.gz` — `save_processed_buffer()` generates `.br` via `brotli_compress()`/`ext-brotli` when `enableBrotli` true, `advanced-cache.php` serves `.br` before `.gz` on `Accept-Encoding: br` | M | LS-001 | `cache/wppo/.../index.html.br` exists when setting on; `Accept-Encoding: br` request gets `Content-Encoding: br`; fallback to `gzip` |
| **LS-404** | CDN mapping awareness — gate `maybe_apply_cdn()` when `apply_filters('litespeed_can_cdn', true) === false`, document single-rewrite rule | S | LS-101 | With LS CDN active, our rewrite skipped; with LS CDN off, ours runs |
| **LS-405** | Tests for Phase 4 — fixture `.htaccess` with/without rewrite, `.br` serve priority, CDN gate | S | LS-401-LS-404 | PHP + integration tests green |

### Phase 5 — Optional Enterprise / QUIC.cloud (Deferred)

| ID | Task | Effort | Depends | Acceptance |
|---|---|---|---|---|
| **LS-501** | ESI-lite for Woo cart/admin bar — AJAX fallback when OLS (no ESI), noop when Enterprise (document) | L | Phase 3 | Cart not cached inside page; no ESI header emitted on OLS; note in docs |
| **LS-502** | QUIC.cloud CCSS/UCS opt-in — filter `wppo_litespeed_use_quic_ccss` that delegates to QUIC.cloud when key configured | L | Phase 3 | When filter true, our heuristic CCSS not enqueued; QUIC.cloud CCSS present |
| **LS-503** | Guest Mode / prefetch deep dive — only if Enterprise | — | — | Deferred |

### Cross-Cutting / Docs

| ID | Task | Effort | Depends | Acceptance |
|---|---|---|---|---|
| **LS-901** | Update `docs/hooks.md` — `wppo_litespeed_mode`, `wppo_litespeed_should_disable_optimizer`, `wppo_litespeed_purge_sync`, `wppo_litespeed_nextgen_rewrite` + `litespeed_purge_*` bridge note | S | LS-101 | Hooks table has new entries, `@since NEXT` |
| **LS-902** | Update `COMPETITIVE_GAP_ANALYSIS.md` Tier 4 — link to LS docs, move "server-level next-gen / Brotli / ESI / QUIC" from Tier 2/3 → LS-specific tier | S | Phase 0 | Matrix row "Server-level cache (LS-native)" moved to LS tier with `wppo: via Litespeed_Integration` |
| **LS-903** | Update `AGENTS.md` — add `docs/litespeed-research.md` + `docs/litespeed-integration-plan.md` + `docs/litespeed-roadmap.md` to file list, mention `is_litespeed` detection pattern | S | — | AGENTS.md reflects LS files, no stale file count |
| **LS-904** | Release chore — `npm run build`, commit `build/`, `npm run lint:js` → `composer lint` → `npm test` → `npm run build` all green per AGENTS.md, tag `@since NEXT` via release script | S | All LS | CI green, build output committed |

---

## 3. Prioritization Rationale

1. **LS-001–005 (Phase 0) first** — 1-day, zero risk to non-LS hosts, fixes a real bug (`other` on LS), and unblocks everything else. Ship immediately.
2. **LS-101–108 (Phase 1) second** — the highest-severity risk (double-minify → broken checkout) is prevented here; without it, installing LSCWP alongside WPPO on LS can corrupt pages. Must ship with or right after Phase 0.
3. **LS-201–204 (Phase 2) third** — stale-cache is the next-highest user pain after corruption; atomic purge is expected by every LS operator.
4. **LS-301–305 (Phase 3) fourth** — the performance win (TTFB 200→50ms) is the reason to be "LS-native", but only safe after purge sync exists (otherwise stale wins).
5. **LS-401–405 (Phase 4) fifth** — independent of Phase 2-3, strong LCP win for image-heavy sites, but opt-in and lower risk than cache layer.
6. **Phase 5 deferred** — Enterprise-only, lowest portability, highest complexity. Document as advantage comparison, not code.

---

## 4. Definition of Done (per PR)

- [ ] New symbols `@since NEXT`, new settings default-safe (`auto`/`false`), gated by `is_litespeed()` so non-LS host unchanged.
- [ ] `Util::transient_key()` for any new transient; multisite tested via `is_multisite()` shard.
- [ ] New filters `wppo_*`, documented in `docs/hooks.md`.
- [ ] PHP unit + JS unit tests added; `composer test` + `npm test` + `composer lint` + `npm run lint:js` + `npm run build` green; `build/` committed.
- [ ] Manual `curl -I` header check logged in PR (cache hit/miss vs mode).
- [ ] AGENTS.md + COMPETITIVE_GAP_ANALYSIS.md updated if file list or matrix changed.

---

## 5. Suggested PR Split

- **PR A — LS-0xx** — `fix: detect LiteSpeed/OpenLiteSpeed in Server_Rules` (LS-001–005 + LS-903)
- **PR B — LS-1xx** — `feat: LiteSpeed safe coexistence & optimizer guard` (LS-101–108 + LS-901)
- **PR C — LS-2xx** — `feat: LiteSpeed purge sync (cross-purge)` (LS-201–204)
- **PR D — LS-3xx** — `feat: LiteSpeed-native page-cache acceleration` (LS-301–305)
- **PR E — LS-4xx** — `feat: server-level next-gen + Brotli + CDN awareness` (LS-401–405)

Each PR is independently shippable; together they implement `docs/litespeed-integration-plan.md` Phases 0-4.

---

## 6. Tracking

Update this file's task IDs to `[x]` when complete, or mirror to GitHub Issues with label `litespeed`. Keep `docs/litespeed-research.md` as the stable research source — update it (not this roadmap) when new LS behaviour is discovered.

