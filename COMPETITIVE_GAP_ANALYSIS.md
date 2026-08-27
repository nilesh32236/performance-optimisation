# Competitive Gap Analysis & Improvement Roadmap

**Date:** 2026-08-11 · **Research depth:** 4 parallel research passes (WordPress core 6.7–7.1 APIs, 10 big competitors, ~20 small/niche/emerging plugins, full local codebase inventory). 60+ sources browsed.
**Plugin baseline:** full-featured free performance plugin — static HTML cache + `advanced-cache.php` drop-in, Redis object cache, minify (JS/CSS/HTML), WebP/AVIF conversion, lazy loading, used/critical CSS, DB cleanup, PageSpeed + local telemetry, speculation rules, Google Fonts self-hosting, per-page asset manager, activity log, WP-CLI, Abilities API registration, WP 6.9 template-enhancement buffers, WP 7.1 client-side media handling.

**Implementation status (2026-08-11, matrix synced 2026-08-27 LS-902):** Tier-1 items #1–#5 implemented + verified (RUM class-rum.php:22, background lazy lazyLoadBackgroundImages class-cache.php:1188, CDN_Purger class-cdn-purger.php:45) plus Tier-2 bloat toggles + autoloaded-options audit. 276 PHP tests / 315 JS tests green. Deferred to next pass: Brotli, feed/REST caching, bfcache, server-level next-gen delivery, OD integration, per-page cache TTL, `.mo`→`.php` translations, LLMs.txt.

Legend: ✅ native · 🟡 partial · ❌ missing · (WP x.y) = minimum core version for the API, always behind a `function_exists()`/`class_exists()` fallback.

---

## 1. WordPress core APIs worth adopting (2025–2026)

Already adopted by this plugin (verified in code): `wp_get_speculation_rules_configuration` (6.8), salted cache keys `wp_cache_get_salted` (6.9), `WP_HTML_Processor::serialize_token` (6.9), template-enhancement output buffer (6.9), `should_load_separate_core_block_assets` on-demand block assets (6.9), client-side media processing handling (7.1), Abilities API registration (6.9).

| Opportunity | Core version | What to do | Fallback |
|---|---|---|---|
| Salted-cache **family**: `wp_cache_set_salted` / `get_multiple_salted` / `set_multiple_salted` in the Redis drop-in + query-cache eviction | 6.9 | Add to `templates/object-cache.php`; evict old `*-queries` keys on 6.9 upgrade | `function_exists()` |
| **Script-module** defer/delay: `fetchpriority=low` + `in_footer` + `modulepreload` | 6.9 | Extend `deferJS`/`delayJS` to `wp_enqueue_script_module()` handles; set `wp_script_add_data($h,'fetchpriority','low')` | `function_exists('wp_script_modules')` |
| Classic scripts depending on script modules (`module_dependencies`) | 7.0 | Allow combining classic entry scripts with module-loaded deps | `function_exists`/`_doing_it_wrong` guard |
| `wp_get_loading_optimization_attributes()` `fetchpriority=low/auto` (non-lazy semantics) | 7.0 | Use for below-fold/occluded images instead of only `loading=lazy` | `function_exists` (6.3 base) |
| Client-side media processing **coexistence** (two-pass `wp_generate_attachment_metadata`, `wp_image_quality`) | 7.1 | Make converter hooks idempotent for `'create'`+`'update'`; stop depending on `image_make_intermediate_size` | 7.1 auto-falls back server-side |
| Speculation rules default overrides via `WP_SPECULATIVE_LOADING_DEFAULT_MODE` / `_EAGERNESS` | 7.1 | Expose prerender/moderate default as a setting; keep `wp_speculation_rules_configuration` filter | `function_exists('wp_get_speculative_loading_override')` |
| Hidden-block asset omission + `enqueue_empty_block_content_assets` filter | 6.9 | Document/no-op (core handles); add filter pass-through for edge cases | n/a |
| Inline-CSS budget 40KB (`styles_inline_size_limit`) + `oembed_discovery_links` | 6.9 | Raise inline ceiling; suppress oEmbed discovery links cleanly | filter |
| Emoji detection is now a footer module (6.9) | 6.9 | Recheck `disableEmojis` still strips both head + footer module | `remove_action` |
| Abilities API refinements: `wp_get_abilities($args)`, lifecycle filters, public flag | 7.1 | Extend `class-abilities.php` registration surface | 6.9 base |

---

## 2. Big-competitor feature matrix (what they have that we don't)

Research: WP Rocket, Perfmatters, FlyingPress, LiteSpeed Cache, W3 Total Cache, NitroPack, Autoptimize, WP Super Cache, WP-Optimize, Hummingbird.

| Feature | We | WP Rocket | Perfmatters | FlyingPress | LiteSpeed | W3TC | NitroPack | Autoptimize | WPSC | WPO | Hummingbird |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Page cache + gzip/304 | ✅ | ✅ | ❌ | ✅ | ✅ | ✅ | ✅ | 🟡 | ✅ | ✅ | ✅ |
| Sitemap preload | ✅ | ✅ | ❌ | ✅ | ✅ | ✅ | ✅ | 🟡 | ✅ | ✅ | ✅ |
| Logged-in / role cache | ✅ | 🟡 | ❌ | ✅ | ✅ | ✅ | ✅ | ❌ | 🟡 | ✅ | 🟡 |
| Object cache (Redis) | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **REST API caching** | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ |
| **Feed caching** | ❌ | 🟡 | ❌ | ❌ | 🟡 | ✅ | 🟡 | ❌ | ✅ | 🟡 | ✅ |
| Minify JS/CSS/HTML | ✅ | ✅ | 🟡 | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
| Defer + delay JS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | 🟡 | ❌ | ✅ | ✅ |
| Critical CSS / used CSS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
| Per-page asset manager | ✅ | 🟡 | ✅ | 🟡 | 🟡 | ✅ | 🟡 | 🟡 | ❌ | ❌ | 🟡 |
| Lazy load images/iframes/videos | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | 🟡 | ❌ | ✅ | ✅ |
| **Lazy-load CSS background images / elements** | ✅ | 🟡 | ✅ | 🟡 | 🟡 | ❌ | ✅ | ❌ | ❌ | ❌ | 🟡 |
| LCP preload / fetchpriority | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
| CLS (width/height) | ✅ | ✅ | ✅ | ✅ | 🟡 | 🟡 | ✅ | 🟡 | ❌ | ❌ | 🟡 |
| WebP/AVIF conversion | ✅ | 🟡 | ❌ | ✅ | ✅ | ✅ | ✅ | 🟡 | ❌ | ✅ | ✅ |
| Google Fonts self-host | ✅ | ✅ | ✅ | ✅ | 🟡 | ❌ | ✅ | 🟡 | ❌ | ✅ | 🟡 |
| Speculation rules | ✅ | ✅ | ✅ | ✅ | 🟡 | ❌ | ✅ | ❌ | ❌ | ❌ | 🟡 |
| DB cleanup + scheduled | ✅ | ✅ | ✅ | ✅ | ✅ | 🟡 | ❌ | ❌ | ❌ | ✅ | ✅ |
| CDN rewrite | ✅ | ✅ | ❌ | ✅ | ✅ | 🟡 | ✅ | 🟡 | 🟡 | ❌ | ✅ |
| **Cloudflare / Varnish purge** | ✅ | ✅ | ❌ | 🟡 | ✅ | ✅ | ✅ | ❌ | ❌ | 🟡 | ✅ |
| **Brotli precompression** | ❌ | 🟡 | ❌ | 🟡 | 🟡 | 🟡 | ✅ | ✅ | ✅ | ✅ | ✅ |
| PageSpeed / audit | ✅ | ✅ | ❌ | ❌ | 🟡 | ❌ | 🟡 | ❌ | ❌ | ❌ | ✅ |
| **Real-user monitoring (RUM/CrUX)** | ✅ | 🟡 | ❌ | ✅ | ❌ | ❌ | 🟡 | ❌ | ❌ | ❌ | ✅ |
| Server rules (.htaccess/Nginx) | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | ❌ | ✅ | ✅ | 🟡 | 🟡 |
| WooCommerce handling | ✅ | ✅ | ✅ | 🟡 | ✅ | 🟡 | ✅ | ✅ | 🟡 | ✅ | ✅ |
| 50+ bloat toggles | 🟡 | 🟡 | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ✅ |
| Heartbeat control | ✅ | 🟡 | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Activity log | ✅ | ❌ | ❌ | ❌ | 🟡 | ❌ | ✅ | ❌ | ❌ | ❌ | 🟡 |
| Uptime monitoring | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ✅ |
| WP-CLI | ✅ | ❌ | ✅ | ❌ | ✅ | ✅ | ❌ | 🟡 | 🟡 | ✅ | ❌ |
| **INP "Optimize Interactive Elements"** | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |

Δ Purge All clears edge; single-page skips edge (CDN_Purger.php:45). § FlyingPress Redis since v5.6 (2026-06-24) + RUM since v5.2 (2025-09). NitroPack INP "Optimize Interactive Elements" 2026-01-19 is new watchlist row (no free plugin ships it).
*Reconciled 2026-08-27 per docs/competitive-audit-2026.md:2 — this table now matches; see docs/performance-report-2026-08-27.md:4.3.*

---

## 3. Small / niche / emerging plugins — unique features

Key sources: official WordPress **Performance Team** plugins (Performance Lab, Optimization Detective / OD, Image Prioritizer, Embed Optimizer, Speculative Loading, Modern Image Formats, Enhanced Responsive Images, Image Placeholders, Instant Back/Forward, Performant Translations, SQLite, View Transitions), Cache Enabler, Cachify, Docket Cache, Hyper Cache, Comet Cache, WP Fastest Cache, Swift Performance, OMGF, Asset CleanUp, a3 Lazy Load, Lazy Load for Videos, Flying Pages, SiteGround Optimizer, Hostinger, BerqWP, FastPixel, RabbitLoader, FlyingCDN.

Unique features we lack (gap candidates):
1. **RUM-driven optimization (Optimization Detective)** — optimize from measured per-breakpoint visitor data, not heuristics. This is the clearest core roadmap signal.
2. **Real-user Web Vitals analytics / RUM dashboard** (BerqWP, FastPixel, FlyingPress CWV, Hummingbird CrUX field data).
3. **Server-Timing API + Site Health performance audits** (Performance Lab: autoloaded-options audit with one-click fix, enqueued-assets audit, AVIF header check, `no-store`/bfcache test, far-future Expires test).
4. **Bfcache enablement for logged-in users** (Instant Back/Forward — privacy-safe session-token invalidation).
5. **`fetchpriority=low` on occluded images**, video `preload=metadata`/poster downsize (Image Prioritizer).
6. **Lazy CSS background images** (Image Prioritizer / Perfmatters / NitroPack).
7. **Accurate `sizes` from block-theme layout** (Enhanced Responsive Images).
8. **Embed space-reservation + dns-prefetch for above-fold embeds** (Embed Optimizer).
9. **Brotli + Gzip precompression + 304** (Cache Enabler).
10. **Serve stale cache to bots** (Hyper Cache).
11. **`.mo`→`.php` translation compilation** (Performant Translations).
12. **Autoloaded-options audit + one-click autoload fix** (Performance Lab).
13. **Per-page cache TTL / cache rules per post type** (WP Fastest Cache / BerqWP).
14. **LLMs.txt auto-generation** (Hostinger) — AI discoverability.
15. **Smart font preload / metric-matched system fallbacks / icon-font subsetting** (OMGF Pro, Swift).
16. **OPcache-based file object cache** (Docket Cache) — Redis-free path.
17. **Cookie-based Vary cache** (FastPixel) — we already have role-hash variants.
18. **Elementor/theme-specific cache invalidation** (WP Fastest Cache Elementor detection, Divi compat).

---

## 4. Gap analysis — ranked improvement opportunities

### Tier 1 — high value, self-contained, latest-tech with backward-compatible fallbacks

> ✅ **Implemented 2026-08-11.** Items 1–5 below shipped in the `feature/competitive-modernization` branch (see AUTONOMOUS_PLAN GAP-M5); the entries below document the original gap, not future work.

1. **Real-user Web Vitals (RUM) monitoring** (`web-vitals.js` beacon → REST → `wppo_web_vitals_rum` post/option; per-breakpoint; reuse the existing Web Vitals trend chart). Catches FlyingPress/Hummingbird/BerqWP. Complement (not replace) the PageSpeed-API trends. *WP-version-agnostic; REST beacon already supported.*
2. **CSS background-image + generic element lazy loading** in `src/lazyload.js` + `process_picture`/buffer rewrites (Perfmatters/NitroPack/Image-Prioritizer parity). Version-agnostic.
3. **Missing UI toggles / dead settings cleanup** (inventory rough edges): page-cache master toggle, `enablePreloadCache` actually gating preload cron, Server-Timing toggle, `high_value_urls` editor, remove dead `auto_fix_enabled`/`core_tweaks` keys. Improves correctness + UX.
4. **Extend defer/delay to script modules** with `fetchpriority=low` + `in_footer` (6.9+, fallback for classic scripts). ~9% LCP combined.
5. **Cloudflare / Varnish / Fastly cache-purge integration** on cache clear (WP Rocket/LiteSpeed/FastPixel parity). Uses `rest-api`/HTTP; no version gate.

### Tier 2 — moderate effort, strong parity

> ✅ **Partially implemented 2026-08-11.** Bloat toggles (#8) and the autoloaded-options audit (#9) shipped; Brotli (#6), feed/REST caching (#7), server-level next-gen delivery (#10) and bfcache (#12) remain as next-pass items.

6. **Brotli precompression** of cached HTML + minified assets with gzip fallback (Cache Enabler parity).
7. **Feed + REST response caching** (W3TC/LiteSpeed/WPO parity): cache `feed` (with 304) and optionally authenticated REST responses; smart invalidation.
8. **More bloat toggles** (Perfmatters parity): disable REST API links/tags, RSS feeds/links, shortlinks, RSD, generator tag, jQuery Migrate, password strength meter, self-pingbacks, WooCommerce cart fragments.
9. **Autoloaded-options audit + one-click fix** in DB cleanup / system info (Performance Lab parity).
10. **Server-level next-gen image delivery** (.htaccess `Vary: Accept` + rewrite to `.webp`/`.avif` variants; Nginx equivalent) — closes inventory rough edge #12.
11. **Script-module-based used-CSS / critical-CSS generation pipeline** — keep (already strong); add `WP_Block_Processor` (6.9) for selector extraction.
12. **Bfcache enablement for logged-in users** (Instant Back/Forward parity) behind a setting; privacy-safe cookie/session token.

### Tier 3 — strategic / larger

13. **Optimization Detective (OD) integration** — feed our lazy-load/LCP logic from real URL Metrics when OD is active; degrade to heuristics when not. Strategic (OD → core).
14. **CDN for minified/combined assets** + CDN-host base for `content_url()` emitted files (inventory rough edge #17).
15. **`sizes=auto`/breakpoint-aware sizing** improvements + video poster optimization (Image Prioritizer parity).
16. **Per-page cache TTL** and cache rules per post type.
17. **LLMs.txt** generation (AI discoverability).
18. **`.mo`→`.php` translation compilation** toggle (Performant Translations parity).
19. **Smart font preload / metric-matched fallbacks** (OMGF Pro parity).
20. **REST caching + fragment cache** (W3TC/LiteSpeed parity) — larger infrastructure.

### Known defects to fix regardless (from inventory)

- `enableCache` master switch has no main-UI toggle (only onboarding).
- `enablePreloadCache` toggle does not actually gate the preload cron.
- `auto_fix_enabled` dead setting; `core_tweaks` tab accepted by REST but unused.
- `server_timing_enabled` has no UI.
- Object-cache tab edits don't re-persist drop-in config (split-brain) — needs an explicit "Apply" action.
- Redis password: no UI hint that `WPPO_REDIS_PASSWORD` must be defined.
- Used-CSS comment parser fails on `/*` inside CSS strings.
- Admin menu fixed at position 2.1 (float collision risk).

---

## 5. Backward-compatibility rules (from repo skill + AGENTS.md)

- Every new WP API call must be behind `function_exists()` / `class_exists()` (+ `method_exists`) with the legacy path preserved.
- New settings must be added to the `wppo_settings` defaults with a safe default that preserves current behavior (opt-in).
- New filters/actions use the `wppo_` prefix; document them.
- `npm run lint:js` → `composer lint` → `npm test` → `npm run build` must all pass; `build/` is committed.
- Multisite safety: use `Util::transient_key()` blog-prefixing for any new transients; domain-based cache dirs; `get_current_blog_id()`.

---

## 6. Proposed immediate work (recommended order)

1. Tier-1 #3 (UI/UX correctness fixes — enableCache toggle, preload gating, dead-setting cleanup) — small, high confidence.
2. Tier-1 #1 (RUM Web Vitals monitoring) — flagship competitive gap.
3. Tier-1 #2 (background-image + element lazy loading).
4. Tier-1 #4 (script-module defer/delay with fetchpriority=low + in_footer).
5. Tier-1 #5 (Cloudflare/Varnish purge) — then reassess.
