# TEST-PLAN.md — Real-Environment Validation (nileshportfolio.duckdns.org)

**Live:** WP 7.1 PHP 8.3.33 LiteSpeed HIT `x-litespeed-cache: hit` boltfolio `WP_DEBUG false` multisite false Redis PONG `ObjectCache:922` cache 2.9M `min/1/css` 16K

## Activation / Upgrade / Migration
- Fresh install `wp plugin deactivate performance-optimisation && wp plugin activate` → no `php -l` fatal `includes/*.php` `performance-optimisation.php:41` vendor autoload `class-main.php:41` guard.
- Upgrade 1.9.0→NEXT: `wppo_settings` 11→14 tabs backfill `Util::get_settings:92` merges defaults `od_integration` etc — `wp option get wppo_settings --format=json | jq keys` 14 after save.
- Old `activeTab` URL `?tab=fileOptimization` → redirects to `?tab=speed` `App.js:76` mapping — manual `curl -s wp-admin/admin.php?page=performance-optimisation | grep tab-`.

## Existing Configuration
- Current `wppo_settings` `file_optimisation minifyJS true excludeJS` `cdnURL ""` etc `wp option get` — after redesign Health header shows 3 rings without DB write; save via `wp wppo settings get file_optimisation --format=json` `class-wppo-cli-command.php` unchanged.
- Export `PluginSetting.js:301` `REDACTED` → import `Rest.php:734-800` `ALLOWED_SETTINGS_KEYS:750` validate + `array_replace_recursive:773` — diff before/after.

## New UI (4+1)
- Overview health header 3 rings Speed/Stability/Efficiency Good/Needs `PerformanceAudit:121-129` thresholds mocked `wppoSettings.translations`.
- Speed pillars CSS/JS/HTML collapsed Advanced "Show advanced (9)" `FileOptimization.js:399` FOUC `1124` Delay only when `?advanced=1`.
- Media Media→Lazy/Video/Next-Gen `ImageOptimization.js:954` `wp wppo image` still works.
- Data & System `ObjectCache.js:922` Standalone collapsed + Sentinel advanced `508` hidden until click.
- Global search filter "defer" finds `B10/B11` across pillars.

## Old Settings Still Work
- REST 28 endpoints `class-rest.php:78-300` `update_settings` `tab:` generic still accepts `fileOptimization` alias — `curl -X POST wp-json/performance-optimisation/v1/update_settings` with `X-WP-Nonce` `wppoSettings.nonce` `class-main.php:1565` 200.
- `wppoSettings.settings[tabName]` global `AGENTS.md:React SPA` `src/lib/apiRequest.js` `apiCall update_settings` mutates global — health header still reads same.

## Cache / Frontend / Admin / REST / WP-CLI / Cron / Multisite / Error / Rollback
- Cache: Enable `Dashboard:974` true → frontend `curl -sI /` `x-litespeed-cache: miss→hit` second hit, `wp-content/cache/wppo/` html + `min/1/css` 16K `class-cache.php:1572` atomic `put_contents` + `wp-admin/admin-bar clear` `src/main.js` 403 nonce refresh.
- Frontend: View source `minified bundle /wp-content/cache/wppo/min/1/css/0aa6b...css` `class-cache.php:748 inline-budget`, lazy `src/lazyload.js 11K` IntersectionObserver, RUM `src/rum.js 1.8K` beacon `rum_collect` `class-rum.php` token.
- Admin: `npm run lint:js` 0e3w `composer lint` 0e1w `npm test 34/34 345` `vendor/bin/phpunit 513/913` 2 skipped (Agent N plus `FIX-AUDIT 2026-08-28` `c8d1cef3`) — `build/index.js 134K`.
- REST: `wp wppo cache clear --yes` `class-wppo-cli-command.php:206` dry-run vs real, `wp wppo system-info --format=json` json-only.
- Cron: `wp cron event list | grep wppo` 15 events `wppo_page_cron_hook 5h` `wppo_img_conversion 1h` `wppo_database_cleanup_cron daily` — `wppo_generate_static_url` single events `Cron::get_sitemap_urls` 500 cap.
- Multisite: `Util::transient_key()` `{blog_id}_` isolated — not multisite today `Agent N` false but test `wp site list` error expected.
- Error: Trigger Combine FOUC `B2` off → warning `FileOptimization:399` vs on → `ConfirmDialog:618` sample, `?wppo_safe=1` cookie 10min `Util::is_safe_mode()` `setup_hooks:489` bypass.
- Rollback: Snapshot `wppo_settings_snapshot` before Apply Recommended → "Undo last wizard" restores `wppo_settings` `Rest.php:464` + Clear cache.

