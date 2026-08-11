# AUTONOMOUS_PLAN.md — Competitive Feature Gaps & Modern WordPress API Adoption

Status legend: `[PENDING]` `[IN PROGRESS]` `[COMPLETED]`

Baseline: 214 PHP tests / 464 assertions, 296 JS tests / 30 suites, PHPCS clean, lint clean,
build success (measured 2026-08-10 after prior audit). Current: 246 PHP tests / 523 assertions,
303 JS tests / 31 suites.

---

## [FEATURE_GAPS] — Competitive feature benchmarking

Benchmark targets: WP Rocket, FlyingPress, Perfmatters, LiteSpeed Cache, W3 Total Cache.

### Already covered (no action)
- Static HTML cache + gzip variants, Redis object cache, image WebP/AVIF conversion.
- Lazy loading (native + IntersectionObserver) for images/iframes/videos, YouTube thumbnail swap.
- Minification (CSS/JS/HTML), defer/delay JS, preconnect/preload resource hints.
- Speculation Rules API (prefetch/prerender, WP 6.8+ `wp_get_speculation_rules_configuration`)
  with login/admin/REST/WooCommerce exclusions — competitor parity with WP Rocket's
  "prefetch links" / Perfmatters' speculation control.
- Google Fonts self-hosting (`class-google-fonts.php`) with `font-display: swap`, woff2,
  subset limiting — parity with Perfmatters "Host Google Fonts Locally".
- Critical CSS / used-CSS, LCP auto-preload (from PageSpeed), width/height CLS injection.
- DB cleanup: revisions (advanced), auto-drafts, trash, spam, trashed comments, expired
  transients, orphaned postmeta + `OPTIMIZE TABLE`.
- Per-page asset control metabox (disable scripts/styles per post).
- Emoji/embed removal, Heartbeat control (core-tweaks).

### GAP-M1 `[COMPLETED]` — Granular database cleanup: orphaned media + oEmbed cache
- Added `Database_Cleanup::clean_unattached_media()` (batch 500, deletes attachment posts +
  their postmeta) and `Database_Cleanup::clean_oembed_cache()` (batch 1000, `_oembed_*` rows).
- Wired both into `TABLE_MAP`, `METHOD_TO_TYPE`, `clean_all()`, `get_counts()`.
- REST: added `unattached_media` + `oembed_cache` to valid types and method map.
- React: added both entries to `CLEANUP_TYPES` in `DatabaseCleanup.js`.
- Tests: `test_clean_unattached_media_returns_zero_when_none_exist`,
  `test_clean_oembed_cache_returns_zero_when_none_exist`, `test_table_map_contains_new_types`,
  expanded `test_table_map_is_correct` + `test_methods_exist`.
- Verification: `phpunit` OK (217 tests), `phpcs` clean, JS DatabaseCleanup suite 17/17.

### GAP-M2 `[COMPLETED]` — Remove query strings from static resources
- Added `removeQueryStrings` toggle under `file_optimisation` (default false) in `class-main.php`
  defaults and the React `FileOptimization.js` settings + toggle UI.
- Implemented `Main::strip_static_query_strings()` registered on `script_loader_src` /
  `style_loader_src` filters; strips `ver` arg while preserving other query args, drops the
  `?` entirely when nothing remains.
- Tests: `tests/php/MainStripQueryStringsTest.php` (4 cases) + 2 JS tests in
  `FileOptimization.test.js` (toggle render + submit payload).
- Verification: `phpunit` OK (221 tests), `phpcs` clean, JS suite 36/36, eslint clean.

### GAP-M3 `[COMPLETED]` — Sitemap-aware cache preloading
- Added `preloadSitemap` setting default false under `preload_settings` (class-main.php defaults)
  and React `PreloadSettings.js` default + SwitchField toggle ("Preload from Sitemap").
- `Cron::process_url()` (public) loads an arbitrary URL via `wp_remote_get`; scheduled on the new
  `wppo_generate_static_url` cron event.
- Private `Cron::get_sitemap_urls()` fetches `wp-sitemap.xml`, follows index child sitemaps,
  filters off-site URLs via `wp_parse_url`, caps at 500 URLs / 50 to-fetch.
- Private `Cron::schedule_sitemap_url_jobs()` skips excluded URLs (`Util::is_url_excluded()`),
  dedupes via `wp_next_scheduled( 'wppo_generate_static_url', array( $url ) )`, schedules with a
  0-1800s random delay; invoked from `schedule_page_cron_jobs()` at `paged_offset === 0`.
- Tests: `tests/php/CronSitemapTest.php` (6 cases) incl. off-site filtering, empty-on-failure,
  excluded-URL skip, and `process_url` request/empty-input behavior.
- Verification: `phpunit` OK (227 tests), `phpcs` clean, JS PreloadSettings 11/11, `npm run
  lint:js` clean, `npm run build` success.

### GAP-M4 `[COMPLETED]` — RUM-style Web Vitals trend monitoring
- Added `performance_audit.auto_rescan` setting (`''` disabled / `daily` / `weekly`, default `''`).
- `Pagespeed::record_trend()` snapshots performance + core vitals into the `wppo_web_vitals_trends`
  option on every successful scan, capped at `TREND_LIMIT` (30) per URL+strategy; `get_trends()`
  reads it back.
- **Hardening (post-landing):** the read-append-write in `record_trend()` is serialized with a
  shared `wp_cache_add()` lock so concurrent mobile+desktop async workers cannot overwrite each
  other's snapshot; `prune_trends()` caps the whole map at `TREND_MAX_KEYS` (20) by dropping keys
  ranked by most-recent snapshot. The rescan cron only records `wppo_web_vitals_last_rescan` when
  every URL queued (`as_enqueue_async_action()` returning 0 skips), and `clear_cron_jobs()` now
  clears the `wppo_web_vitals_rescan` hook. The REST `web_vitals_trends` endpoint filters by
  strategy even without a `url`, using `str_ends_with()` so a strategy cannot match mid-URL.
- New daily cron event `wppo_web_vitals_rescan` → `Cron::web_vitals_rescan_cron()` queues scans for
  home + high-value URLs on mobile+desktop, gated by `auto_rescan`; weekly mode throttles via
  `wppo_web_vitals_last_rescan` timestamp.
- REST: new GET `web_vitals_trends` endpoint (optional url/strategy filters) + `auto_rescan`
  preservation in `update_settings`.
- React: `autoRescan` select in PluginSetting.js, new `WebVitalsTrends` component (inline SVG
  sparkline, no chart lib) rendered in Dashboard under PageSpeedPanel; `fetchWebVitalsTrends()`
  API wrapper.
- Tests: `tests/php/PagespeedTrendsTest.php` (6, incl. lock-held skip + global retention via a
  swapped object-cache store since Patchwork cannot redefine `wp_cache_*`),
  `tests/php/CronWebVitalsRescanTest.php` (5, incl. enqueue-failure gate), RestTest endpoint
  count 22 + strategy-only filter, JS WebVitalsTrends (3) + PluginSetting auto-rescan (1).
- Verification: `phpunit` OK (246 tests), `phpcs` clean, JS 31 suites / 303 tests, `npm run
  lint:js` clean, `npm run build` success.

---

## [MODERN_WP_API] — Core API adoption & graceful fallbacks

### MOD-1 `[COMPLETED - verified]` — WP_HTML_Tag_Processor usage
- **Verified:** `class-telemetry.php`, `class-used-css.php`, `class-cache.php` (CDN rewrite),
  `class-image-optimisation.php` already gate on `class_exists( 'WP_HTML_Tag_Processor' )`
  with regex fallbacks. No action needed beyond spot-checking new code.

### MOD-2 `[COMPLETED]` — Modern enqueue `wp_script_add_data` / strategy support
- **Audit:** `add_defer_strategy()` uses core `strategy` script data on WP 6.3+ with a
  `script_loader_tag` legacy fallback — already progressive.
- **Verified:** `minify_js`/`minify_css` still rewrite correctly with `removeQueryStrings`
  (GAP-M2) active. The tag-time rewrites (`script_loader_tag`/`style_loader_tag`) run after the
  `*_loader_src` strip, so their own `?ver=` survives. **Fix landed:** the enqueue-time paths
  (queued-styles minify `ver = filemtime`, and `wppo-combine-css` version) print through
  `style_loader_src`, so `strip_static_query_strings()` now returns early for URLs under the
  plugin's own `/cache/wppo/` directories — preserving mtime cache-busting on regenerated
  minified/combined files. Tests: 3 new cases in `MainStripQueryStringsTest.php` (min-cache URL,
  combined URL, theme `.min.css` still stripped).

### MOD-3 `[COMPLETED]` — Version-gated core functions in new code
- **Audited:** all GAP-M1..M4 additions. Every new feature uses `function_exists()` /
  `class_exists()` guards and mirrors the `version_compare( $wp_version, 'X', '>=' )` convention
  already used in `class-main.php` for WP 6.3/6.9 features. New: speculation rules (6.8+), salted
  cache (6.9+), native lazy.
- **Verified guards:** `queue_scan()` and `web_vitals_rescan_cron()` gate
  `as_enqueue_async_action()` via `function_exists` (return 0 / skip when absent); REST endpoints
  guard it the same way. `record_trend()`/`prune_trends()`/`strip_static_query_strings()`/sitemap
  discovery/DB cleanup call only long-available core + PHP 8 functions (no version-gated calls).

---

## Execution order
1. MOD-1 verified (no-op).
2. GAP-M1 (DB cleanup) — PHP + REST + React + tests.
3. GAP-M2 (query strings) — PHP + settings + React + tests.
4. GAP-M3 (sitemap preload) — PHP cron + tests (+ React toggle).
5. GAP-M4 (Web Vitals trends) — PHP cron + REST + React + tests.
6. Full verification: `composer lint` → `composer test` → `npm run lint:js` → `npm test` → `npm run build`.
