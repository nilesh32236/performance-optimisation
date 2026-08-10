# AUTONOMOUS_PLAN.md — Competitive Feature Gaps & Modern WordPress API Adoption

Status legend: `[PENDING]` `[IN PROGRESS]` `[COMPLETED]`

Baseline: 214 PHP tests / 464 assertions, 296 JS tests / 30 suites, PHPCS clean, lint clean,
build success (measured 2026-08-10 after prior audit).

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

### GAP-M2 `[PENDING]` — Remove query strings from static resources
- **Competitors:** LiteSpeed, Perfmatters (table-stakes toggle).
- **Gap:** No toggle strips `?ver=` from static asset URLs (improves proxy/CDN cacheability).
- **Fix:** Add `removeQueryStrings` toggle under `file_optimisation`; a `clean_url` /
  `style_loader_tag` / `script_loader_tag` filter strips `?ver=` when enabled. Must preserve
  cache-busting via file mtime when static cache/minify rewrites URLs.
- **Effort:** Low. **Value:** Medium-high.

### GAP-M3 `[PENDING]` — Sitemap-aware cache preloading
- **Competitors:** WP Rocket, W3TC.
- **Gap:** Preload discovers URLs only via `get_posts()`. Pages absent from post queries
  (custom endpoints, third-party archives) are never warmed.
- **Fix:** Extend `Cron::schedule_page_cron_jobs()` to also read a sitemap
  (`wp-sitemap.xml` / plugin sitemaps) via `wp_remote_get` and schedule those URLs.
  Must respect exclusion rules, dedupe with post URLs, and cap batch size.
- **Effort:** Medium. **Value:** Medium-high.

### GAP-M4 `[PENDING]` — RUM-style Web Vitals trend monitoring
- **Competitors:** WP Rocket Insights, FlyingPress CrUX.
- **Gap:** We have one-shot PageSpeed scans but no scheduled re-scan + trend history.
- **Fix:** Add a `performance_audit` setting `autoRescan` (daily/weekly) and a lightweight
  `wppo_web_vitals_trends` option storing last N results per strategy for a trend chart in the
  React Dashboard. Reuse existing `Pagespeed` class + `class-cron.php` daily hook.
- **Effort:** Medium-high. **Value:** High (differentiator).

---

## [MODERN_WP_API] — Core API adoption & graceful fallbacks

### MOD-1 `[COMPLETED - verified]` — WP_HTML_Tag_Processor usage
- **Verified:** `class-telemetry.php`, `class-used-css.php`, `class-cache.php` (CDN rewrite),
  `class-image-optimisation.php` already gate on `class_exists( 'WP_HTML_Tag_Processor' )`
  with regex fallbacks. No action needed beyond spot-checking new code.

### MOD-2 `[PENDING]` — Modern enqueue `wp_script_add_data` / strategy support
- **Audit:** `add_defer_strategy()` uses core `strategy` script data on WP 6.3+ with a
  `script_loader_tag` legacy fallback — already progressive. Verify `minify_js`/`minify_css`
  paths still rewrite correctly when `removeQueryStrings` (GAP-M2) is active.

### MOD-3 `[PENDING]` — Version-gated core functions in new code
- Every new feature must use `function_exists()` / `class_exists()` guards and mirror the
  `version_compare( $wp_version, 'X', '>=' )` convention already used in `class-main.php`
  for WP 6.3/6.9 features. New: speculation rules (6.8+), salted cache (6.9+), native lazy.

---

## Execution order
1. MOD-1 verified (no-op).
2. GAP-M1 (DB cleanup) — PHP + REST + React + tests.
3. GAP-M2 (query strings) — PHP + settings + React + tests.
4. GAP-M3 (sitemap preload) — PHP cron + tests.
5. GAP-M4 (Web Vitals trends) — PHP cron + REST + React + tests.
6. Full verification: `composer lint` → `composer test` → `npm run lint:js` → `npm test` → `npm run build`.
