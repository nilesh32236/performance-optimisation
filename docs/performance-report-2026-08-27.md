# Performance Optimisation — LiteSpeed Fix Status + Competitive Deep Dive (2026-08-27)

**Baseline:** Performance Optimisation 1.9.0 · `performance-optimisation.php:1` · branch `master` @ `bbb4783e`  
**Env:** OpenLiteSpeed 1.9.1 / `litespeed-cache` 7.9 · WP 7.1 · PHP 8.2-8.3  
**Sources:** `docs/litespeed-research.md`, `docs/litespeed-integration-plan.md`, `docs/litespeed-roadmap.md`, `docs/competitive-audit-2026.md`, `docs/wordpress-7x-readiness.md`, `COMPETITIVE_GAP_ANALYSIS.md` + 4 websearch passes (WP Rocket / LSCache / FlyingPress / Perfmatters) on 2026-08-27.

---

## 1. Where your LiteSpeed reports live

| Doc | Purpose |
|---|---|
| `docs/litespeed-research.md:1` | Deep research — server detection gap, conflict matrix, header protocol, storage, feature parity |
| `docs/litespeed-integration-plan.md:1` | Architecture + 5-phase plan + coexistence modes (`auto/wppo/litespeed/standalone`) + data model |
| `docs/litespeed-roadmap.md:1` | Task board LS-001..904 with acceptance criteria + PR split |
| `docs/competitive-audit-2026.md:1` | 2026-08-27 competitive matrix (11 plugins) + 10 novel white-space features |
| `docs/wordpress-7x-readiness.md:1` | WP 6.8→7.2 delta + library audit (`action-scheduler` bump, salted cache, OD) |
| `COMPETITIVE_GAP_ANALYSIS.md:1` | Original 2026-08-11 tiered gap roadmap (Tier 1-3) — now superseded by `competitive-audit-2026.md:2` deltas |

All three LiteSpeed docs are **reviewable, versioned, and linked from `AGENTS.md`** per `litespeed-integration-plan.md:6`.

---

## 2. LiteSpeed compatibility — what was already fixed (Phases 0-4, merged to `master`)

| Phase | PR | IDs | What shipped | Key files |
|---|---|---|---|---|
| **0** | #716 | LS-001..005 | `Server_Rules::get_server_type():34` now returns `litespeed` for `litespeed`/`openlitespeed` before `apache`/`nginx`; `is_litespeed():59`; REST `server_rules` populates `apache` rules for LS; SPA `FileOptimization.js` Network tab shows “LiteSpeed (Apache-compatible)”; `System_Info` exposes `litespeed` group. | `includes/class-server-rules.php:34`, `includes/class-server-rules.php:59`, `includes/class-system-info.php` |
| **1** | #717 | LS-101..108 | New `includes/class-litespeed-integration.php:1` (1343 lines) — `is_lscache_active():235` ( `LSCWP_V` + class_exists + `active_plugins`/`active_sitewide_plugins`), `get_mode():300`, `effective_mode():347` (`auto→wppo/litespeed/standalone`), `is_wppo_cache_owner():411`, `should_disable_wppo_optimizer():435` + `litespeed_can_optm` cooperation; optimizer guards in `class-main.php:2650` (`minify_css`), `class-cache.php:367` etc. — early return when LS owns optimizer; `litespeed_integration` defaults `mode=auto, purgeSync=true, nextGen/Brotli false` in `class-main.php`; REST allowlist; `.htaccess` ordering guard `class-htaccess-handler.php:157`; drop-in arbitration UI; SPA `NoticeBanner` + `StatusBadge`. | `includes/class-litespeed-integration.php`, `includes/class-main.php:682`, `includes/class-cache.php:367` |
| **2** | #718 | LS-201..204 | Purge sync both directions — `Cache::clear_cache():1991` → `LiteSpeed_Integration::sync_purge_all_to_litespeed():530` (`do_action litespeed_purge_all`), single-page → `sync_purge_url_to_litespeed:548`, `invalidate_dynamic_static_html:1827` → `sync_purge_post:570`; reverse hooks `init():594` → `handle_litespeed_purged_all:615` / `handle_litespeed_purged_post:635` / `handle_litespeed_purge_finalize:659` with blog-prefixed lock `wppo_litespeed_purge_lock` 60s via `Util::transient_key():497`; `CDN_Purger::purge_all` now also purges LS. | `includes/class-litespeed-integration.php:530`, `includes/class-cache.php:1827` |
| **3** | #719 | LS-301..305 | LS-native acceleration — `handle_send_headers():849` on `send_headers:0` emits `X-LiteSpeed-Cache-Control: public,max-age=N` ( `cacheLife 0→604800` via `get_litespeed_ttl():685`), `X-LiteSpeed-Tag: WPPO` + `Po.{id}` per `is_singular`, fallback raw `header()` when `LSCWP` absent (OLS honors raw), `no-cache` for `DONOTCACHEPAGE` non-cacheable; bypass path `class-cache.php:1671` skips `save_processed_buffer` when `!is_wppo_cache_owner`; vary bridge `should_vary_by_role():808` + `filter_litespeed_vary():1069` and raw `Vary: Cookie` / `X-LiteSpeed-Vary` when `enableLoggedInCache`; LS-304 strips generic `Cache-Control` via `maybe_strip_generic_cache_control():1274`. | `includes/class-litespeed-integration.php:849`, `includes/class-cache.php:1671` |
| **4** | #720 | LS-401..404 | Server-level next-gen + Brotli + CDN awareness — `Htaccess_Handler::get_rules():157` gated by `is_nextgen_rewrite_enabled():1103` (`litespeed && convertImg && enableNextGenRewrite`) emits `<IfModule mod_rewrite>` `RewriteCond %{HTTP:Accept} image/webp|avif` → `.webp/.avif` + `Vary Accept`; `Server_Rules::get_nginx_rules():118` emits `map $http_accept $wppo_avif/webp_suffix` + `try_files` (LS-402, nginx is server-agnostic); `Cache::save_processed_buffer()` generates `.br` via `LiteSpeed_Integration::is_brotli_enabled():1184` when `enableBrotli` + `extension_loaded('brotli')`, `advanced-cache.php` serves `.br` before `.gz`; `can_apply_cdn():1235` respects `wppo_litespeed_can_cdn` + `litespeed_can_cdn` — `Cache::maybe_apply_cdn():1273` skips WPPO rewrite when LS CDN active. | `includes/class-htaccess-handler.php:157`, `includes/class-server-rules.php:118`, `includes/class-cache.php:1597` |

**Result:** On OLS/LSWS, file cache + LS server cache now cooperate. On non-LS hosts, all LS code is inert (`is_litespeed():201` early return) — zero behaviour change. Modes stored in single option `wppo_settings[litespeed_integration]` — no new tables.

---

## 3. GitHub Issues — current state (2026-08-27 12:40 UTC)

| # | Title | State | What it tracks |
|---|---|---|---|
| `722` | [Audit] Daily verification failed — 2026-08-27 | **OPEN** (audit) | 3 PHP errors in `InlineCssTest` — `get_option` not mocked after `LiteSpeed_Integration::is_lscache_active():253` call chain (`class-main.php:2649`). Fix: mock `get_option(active_plugins)` in those 3 tests. JS/PHPCS/Build green (341 JS). |
| `709` | Design chooser: 3 static design proposals | OPEN | SPA redesign A/B/C under `designs/` — not LS-related |
| `708` | LS-904 WP 7.x readiness + library bump | OPEN | `action-scheduler 3.9.3→4.1.0`, salted-cache family, `wp_get_loading_optimization_attributes`, emoji footer — see `docs/wordpress-7x-readiness.md:3` |
| `707` | LS-903 Competition white-space N-features | OPEN | N1-N10 roadmap (ships `N8→N5→N1` per `competitive-audit-2026.md:4`) |
| `706` | LS-902 Sync matrices + TTFB disclaimer | OPEN | Flip `COMPETITIVE_GAP_ANALYSIS.md:2` RUM/CDN rows ✅, host-dependent TTFB table, FlyingPress footnotes |
| `646, 368, 369` | v2.0.0 remaining / WP.org assets | OPEN | Pre-existing, not LS |
| `LS-001..404, LS-901` | All LiteSpeed phases | **CLOSED** (13 issues, `2026-08-27T12:28Z`) | PRs #716-#720 merged |

No new LiteSpeed issues need creation — phases 0-4 are done. Remaining work is **706/707/708 + audit 722** (see §7).

---

## 4. Competitive deep dive — what your plugin is vs what the market ships (2026-08-27 fresh websearch)

### 4.1 Market snapshot

**WP Rocket 3.20** (premium $59/yr, 4M+ sites): 80% one-click, page cache disk + preload (sitemap), Remove Unused CSS (page-specific, external SaaS API, 60-80% CSS reduction, +10-25 PSI on builder sites), delay JS (2 strategies), lazy, DB cleanup, Cloudflare API, Heartbeat, but **no image WebP/AVIF, no object cache** (Imagify separate). Source: `plugintheme.net/blog/wp-rocket-review-2026` + `blog.canadianwebhosting.com 2026-03-14`.

**LiteSpeed Cache 7.9** (free, 7M installs, 2026-08-05): LS-exclusive server cache (shared-memory, zero PHP, ~50-90ms TTFB, `X-LiteSpeed-Cache-Control/Tag/Purge`), ESI hole-punch (Enterprise only), QUIC.cloud UCSS/CCSS + image AVIF/WebP + CDN (credits), Object cache Redis/Memcached/LSMCD, crawler concurrency + blacklist, browser cache, DB cleaner, Cloudflare+Varnish purge, but **no RUM/CrUX, no local telemetry, heuristic CSS is cloud-only**. Changelog 8.0 OptiMax pending. Source: `wordpress.com/plugins/litespeed-cache`, `github.com/litespeedtech/lscache_wp`, `litespeed-research.md:3`.

**FlyingPress 5.6** (premium): Page cache + preload, **Redis object cache since 5.6 (2026-06-24)**, built-in image optimization (5.3, 2026-01-20) via FlyingCDN (70+ PoPs), RUM since 5.2, critical/used CSS browser-based, 3-strategy delay, but **no Varnish, no QUIC**. Source: `flyingpress.com/blog/redis-object-caching`.

**Perfmatters** (premium $24.95, no cache): 50+ bloat toggles, Script Manager (per-page/per-device disable grouped by plugin/theme), Used CSS, lazy, DOM monitoring, DB cleanup, but **has no page/object/image cache — designed to sit beside WP Rocket/FlyingPress**. Source: `perfmatters.io/features`, `gauravtiwari.org/perfmatters-review 2026-02-24`.

Others checked: **NitroPack** (cloud, INP “Optimize Interactive Elements” 2026-01-19 — unique), **W3TC** (16-tab power, object+DB+fragment cache), **Hummingbird** (CrUX field data), **WP-Optimize/WP Fastest Cache/AutoPtimal/SiteGround Optimizer** — none ships local telemetry + suggestions.

### 4.2 Reconciled matrix (your 1.9.0 + Phases 0-4 patched)

Legend: ✅ native · 🟡 partial · ❌ missing · Δ=Phase 2-4 patch

| Feature | You (1.9.0+LS 0-4) | WP Rocket | FlyingPress 5.6 | LSCache 7.9 | Perfmatters | NitroPack | W3TC | Hummingbird |
|---|---|---|---|---|---|---|---|---|
| Page cache + gzip/304 + .br | ✅ file+gz (+`.br` opt-in Δ) | ✅ disk | ✅ disk | ✅ server Δ (`X-LiteSpeed-Cache-Control` now emitted) | ❌ | ✅ cloud | ✅ | ✅ |
| LiteSpeed-native header accel | ✅ Δ (LS-301) | ❌ | ❌ | — (is LSCache) | ❌ | ❌ | ❌ | ❌ |
| Purge sync (WP ↔ LS) | ✅ Δ (LS-201/202) | 🟡 (Cloudflare) | 🟡 | ✅ own | ❌ | ❌ | ❌ | ❌ |
| Sitemap preload / crawler | ✅ 5h/500 URL + 15s budget | ✅ sitemap | ✅ sitemap | ✅ concurrency+blacklist | ❌ | ✅ | ✅ | ✅ |
| Logged-in / role / ESI | ✅ role-hash + Vary bridge Δ (LS-303) | 🟡 | ✅ | ✅ ESI Enterprise | ❌ | ✅ | ✅ | 🟡 |
| Object cache (Redis/Memc) | ✅ sentinel/cluster/TLS/compress | ❌ | ✅ Redis § | ✅ Redis/Memc/LSMCD | ❌ | ❌ | ✅ APCu | ❌ |
| REST / Feed caching | ❌ / ❌ | ❌ / 🟡 | ❌ | ✅ / 🟡 | ❌ | ✅ / 🟡 | ✅ / ✅ | ❌ / ✅ |
| Minify/combine/defer/delay | ✅ + guard Δ (LS-103) pause when LS owns | ✅ + RUCSS | ✅ 3-strat | ✅ | ✅ | ✅ cloud | ✅ | ✅ |
| Critical/Used CSS | ✅ local heuristic, SSRF depth-3, no credits | ✅ cloud | ✅ browser | ✅ QUIC credits | ✅ | ✅ cloud | ✅ | ✅ |
| Per-page Asset Manager | ✅ (`_wppo_disabled_scripts/styles`, `_wppo_preload_image_url`) | 🟡 | 🟡 | 🟡 | ✅ (Script Manager) | 🟡 | ✅ | 🟡 |
| Lazy (img/iframe/video/bg) | ✅ (incl. CSS bg) + `lazyLoadNative` + `excludeFirstImages 1-3` | ✅ | 🟡 | ✅ | ✅ | ✅ | ❌ (bg) | 🟡 |
| LCP preload / fetchpriority | ✅ (`autoPreloadLCP`, `fetchpriority` for scripts/modules) | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | 🟡 |
| CLS width/height | ✅ | ✅ | ✅ | 🟡 | ✅ | ✅ | 🟡 | 🟡 |
| WebP/AVIF (sibling) + Vary:Accept | ✅ local + htaccess rewrite opt-in Δ (LS-401/402) | 🟡 Imagify | ✅ FlyingCDN | ✅ QUIC | ❌ | ✅ cloud | ✅ | ✅ |
| Google Fonts self-host woff2 | ✅ + `font-display:swap` | ✅ | ✅ | 🟡 | ✅ | ✅ | ❌ | 🟡 |
| Speculation Rules 6.8+ | ✅ full (mode/eagerness/exclude, `WP_SPECULATIVE_LOADING_DEFAULT_*`) | ✅ | ✅ | 🟡 | ✅ | ✅ | ❌ | 🟡 |
| DB cleanup (7 types, sched) | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | 🟡 | ✅ |
| CDN rewrite | ✅ origin + gate Δ (LS-404) | ✅ + RocketCDN | ✅ | ✅ mapping | ❌ | ✅ | 🟡 | ✅ |
| Cloudflare/Varnish edge purge | ✅ (`Bearer WPPO_CLOUDFLARE_API_TOKEN` + `PURGE`, LS bridge Δ) | ✅ | 🟡 | ✅ | ❌ | ✅ | ✅ | ✅ |
| Brotli `.br` | 🟡 opt-in Δ (LS-403, needs `ext-brotli`) | 🟡 server | 🟡 server | 🟡 server `brStaticCompressLevel` | ❌ | ✅ | 🟡 | ✅ |
| PageSpeed + audit | ✅ local+PSI + trends (30/URL) | ✅ | ❌ | 🟡 | ❌ | 🟡 | ❌ | ✅ |
| RUM / Web Vitals | ✅ RUM beacon (token+IP), `rum_collect`/`rum_data`, trends | 🟡 synthetic | ✅ RUM § | ❌ | ❌ | 🟡 | ❌ | ✅ CrUX |
| Telemetry + Suggestion Engine | ✅ local cURL + suggestions + autoload audit | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | 🟡 |
| Server rules | ✅ Apache+ Nginx + LS (`litespeed` branch) Δ | ✅ | ❌ | ✅ | ❌ | ❌ | ✅ | 🟡 |
| Woo handling | ✅ (cart/checkout/account + cookies + fragments) | ✅ | 🟡 | ✅ | ✅ | ✅ | 🟡 | ✅ |
| Bloat toggles (emojis/embeds/dashicons/XML-RPC/Heartbeat etc.) | ✅ 15+ + Heartbeat + per-page | 🟡 | ✅ | ✅ | ✅ 50+ | ❌ | ❌ | ✅ |
| Activity log + API | ✅ (`wppo_activity_logs`) | ❌ | ❌ | 🟡 | ❌ | ✅ | ❌ | 🟡 |
| WP-CLI | ✅ | ❌ | ❌ | ✅ 8 cmds | ✅ | ❌ | ✅ | ❌ |
| ESI / QUIC.cloud / OptiMax | ❌ (deferred, Enterprise) | ❌ | ❌ | ✅† | ❌ | ❌ | ❌ | ❌ |
| `.mo→php` / LLMs.txt / bfcache / `sizes=auto` | ❌ (planned N7/N8/N6) | ❌ | ❌ | ❌ | ❌ | ❌ (INP only) | ❌ | ❌ |

Δ = shipped in your Phases 1-4 (no new Composer deps). § FlyingPress Redis/RUM since 2026 Q1 (previously ❌).

### 4.3 What the gap audit got stale — and what actually changed (2026-08-27)

`COMPETITIVE_GAP_ANALYSIS.md:1` (2026-08-11) is **conservative + correct** except 4 rows now flipped per `competitive-audit-2026.md:1`:

1. **RUM** was ❌ in gap doc → **✅ you** since Tier-1 #1 (`class-rum.php`) — flip row.
2. **Cloudflare/Varnish purge** was ❌ → **✅ you** (`CDN_Purger::purge_all` on `wppo_after_cache_clear`, LS-20x adds LS) — flip row, note “Purge All clears edge, single-page skips edge”.
3. **CSS background lazy** was ❌ → **✅ you** (`lazyLoadBackgroundImages`) — flip row.
4. **TTFB claim** `PERFORMANCE.md` “680→45ms, 52→98” is **lab warm-cache, Astra, host-dependent** — add disclaimer: LS server ~90ms, PHP file Nginx/Apache ~170-350ms (WitsCode 2026). Issue `706` tracks this.

FlyingPress object cache was ✅ vs old reviews still saying ❌ — footnote “since v5.6”. NitroPack INP feature (2026-01-19) is new watchlist row. `action-scheduler 3.9.3→4.1.0` is 1 major behind (packagist 2026-08-27) — tracked in `708`.

### 4.4 Where you lead (moat) vs where you still gap

**Lead — no competitor has this local+free combo:**

- **Performance Intelligence:** local cURL telemetry + suggestion engine + PageSpeed trends + **RUM** + **autoloaded-options** audit (`wppo_web_vitals_trends` capped 30/URL) — `competitive-audit-2026.md:3` umbrella. WP Rocket synthetic only, FlyingPress RUM display-only, others none.
- **Heuristic CSS credit-free:** `cache/wppo/ccss` + `used-css.css` local PurgeCSS with safelist + one-click regenerate, vs QUIC.cloud/Rocket SaaS credits + latency. (`litespeed-research.md:10`)
- **Per-page Asset Manager + 15 bloat toggles + Heartbeat + Speculation Rules** in one free plugin — Perfmatters-level control without second purchase.
- **Portability + multisite safety:** `Util::transient_key():496` blog-prefix, domain-isolated `wppo/{domain}/{path}/index.html.gz`, works on any host (LS/Apache/Nginx/shared) while LSCache needs LS.
- **WP 6.9-7.1 adopter:** template-enhancement buffer dual-path (`Main::is_template_enhancement_buffer_active()`), `styles_inline_size_limit` 40KB, `WP_HTML_Processor`, `apply_module_loading_strategies`, client-side media idempotency (`filter_client_side_supported_mime_types`).

**Remaining gaps — ranked (from `competitive-audit-2026.md:4` + `COMPETITIVE_GAP_ANALYSIS.md:4`):**

| Tier | Gap | Severity | Next step (filed) |
|---|---|---|---|
| P0 | InlineCss 3-test break + TTFB disclaimer | High + doc | `722` + `706` — fix mocks + update `PERFORMANCE.md` table |
| P0 | `action-scheduler` bump | Medium | `708` — `composer.json ^4.1` |
| P1 | Salted-cache family completeness in `templates/object-cache.php` | Medium | `708` / `wordpress-7x-readiness.md:3` — `get_multiple_salted` + `*-queries` eviction |
| P1 | `wp_get_loading_optimization_attributes()` for occluded images | Medium | `708` |
| P1 | Emoji footer module `wp_dequeue_script_module('emoji')` | Low | `708` |
| P2 | REST/Feed cache, per-URL TTL, `module_dependencies` classic↔module, OD integration | Medium | `707` N5 |
| W-S | 10 novel “no-one-has-it” N1-N10 — recommend **N8 LLMs.txt (S, sales), N5 OD (M), N1 AI Adaptive (L flagship)** per `competitive-audit-2026.md:4` | Strategic | `707` |

---

## 5. LiteSpeed — conflict model you now handle

Before Phase 1, both plugins active = **🔴 High** corruption (double-minify, `wppo-src` vs `data-src`, drop-in collision `object-cache.php` white-screen, stale matrix). After Phase 1-2, `docs/litespeed-research.md:6` conflict matrix is guarded:

- File vs server cache: `advanced-cache.php` foreign-drop-in check keeps LSCWP drop-in; `effective_mode` gates `save_processed_buffer` (`class-cache.php:1671`).
- Minify/combine/defer/delay double-run → `should_disable_wppo_optimizer():435` early return on every `style/script_loader_tag` + `minify_buffer` + `maybe_apply_cdn`.
- CDN double rewrite → `can_apply_cdn():1235` + `litespeed_can_cdn` filter.
- Purge stale → atomic both ways with 60s lock (`wppo_litespeed_purge_lock`).
- `.htaccess` ordering: `LSCACHE` stays above `WordPress`/`wppo_rules` (OLS restart note in UI).

Manual header proof on this OLS host (`litespeed-research.md:1`):

```sh
curl -I https://nileshportfolio.duckdns.org/ | grep -i X-LiteSpeed
# mode=wppo, cacheable → X-LiteSpeed-Cache-Control: public,max-age=604800 + X-LiteSpeed-Tag: WPPO
# mode=litespeed, DONOTCACHEPAGE → X-LiteSpeed-Cache-Control: no-cache
# non-LS host → no header (is_litespeed false)
```

---

## 6. WordPress & library readiness (2026-08-27 packagist)

| Package | Constraint → Locked | Latest | Action |
|---|---|---|---|
| `voku/html-min` | `^5.0` → 5.0.0 | 5.0.0 | ✅ |
| `matthiasmullie/minify` | `^1.3` → 1.3.75 | 1.3.75 | ✅ |
| `symfony/css-selector` | `^7.4` → v7.4.17 | 7.4.17 (v8.1.5) | ✅ stay 7.4 or widen `^7.4 \|\| ^8.0` post-7.2 |
| `woocommerce/action-scheduler` | `^3.8` → 3.9.3 | 4.1.0 (2026-08-05) | ⚠️ bump to `^4.1` — `708` |
| `swc/core` etc. | `@wordpress/scripts 33.x` | 33.x | ✅ |
| WP | 7.1 Mary Lou GA | 7.2 alpha (2026-12-09 Secrets API) | Plan: `WPPO_CLOUDFLARE_API_TOKEN` → `wp_set_secret('wppo/cloudflare-token')` behind `function_exists` at GA — `wordpress-7x-readiness.md:1` |
| Node | 22.14.0 `.nvmrc` | 22 LTS | ✅ |

---

## 7. What to do next — actionable checklist

1. **Unblock CI now (30 min):** Fix `722` — mock `get_option` in `tests/php/InlineCssTest.php:397,419,473` (add `Brain\Monkey\Functions\when('get_option')->justReturn([])` or stub `active_plugins`). Re-run `npm run lint:js → composer lint → npm test → composer test → npm run build` per `AGENTS.md:Commands`.
2. **Close `706` (1 day):** Sync `COMPETITIVE_GAP_ANALYSIS.md:2` matrix rows RUM/CDN/bg-lazy → ✅, add TTFB host-dependent table (LS ~90ms vs PHP ~170-350ms), add FlyingPress/NitroPack footnotes. Link to this report.
3. **Close `708` P0 (1 day):** Bump `composer.json` `action-scheduler ^4.1` → `composer update`, smoke `composer test` (Action Scheduler `wppo_convert_image_background` + `wppo_pagespeed_scan`).
4. **Close `708` P1 (2-3 days):** Salted family in `templates/object-cache.php`, `wp_get_loading_optimization_attributes()` in `class-image-optimisation.php`, emoji footer module in `class-core-tweaks.php`.
5. **Plan `707` white-space sprint:** Ship `N8 LLMs.txt` (S) first — `public/llms.txt` + `Link: <llms.txt>` + prewarm; then `N5 OD` (consume `OD_URL_Metric` viewport groups for `fetchpriority`); then `N1 AI Adaptive` (RUM→ML→auto-tune, WP 7.0 AI Client gated).

---

## 8. Verification

- Code: `composer lint` ✅ · `npm run lint:js` ✅ (3 warnings pre-existing `Dashboard.js:122` `react-hooks/exhaustive-deps`) · `composer test` ❌ 3 errors (722) / 380 total · `npm test` ✅ 341/33 · `npm run build` ✅ (webpack 5.109.2). See `gh issue view 722` log.
- Manual: `Server_Rules::get_server_type()` returns `litespeed` for `LiteSpeed`/`OpenLiteSpeed` (`class-server-rules.php:38`), REST `server_rules` populates `apache` on LS, SPA Network tab branch verified in `src/components/__tests__/FileOptimization.test.js`.
- Fresh websearch: 4 queries 2026-08-27 (WP Rocket, LSCache 7.9, FlyingPress 5.6, Perfmatters) — excerpts cited §4.1.

*Update `docs/litespeed-research.md` + `competitive-audit-2026.md` (not this report) when new LS behaviour is discovered — per `litespeed-roadmap.md:6`.*
