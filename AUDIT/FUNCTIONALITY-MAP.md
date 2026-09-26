# FUNCTIONALITY-MAP.md — End-to-end traces (Input→output)

_Consolidated from A01-A09 hot-path traces. Every major functionality traced input→validation→processing→hooks→DB/cache→output→cleanup._

## 1. Static HTML cache (Cache + Advanced_Cache_Handler + Main)
- Input: `template_redirect` buffer `class-cache.php:200 process_buffer_for_cache` → Validation: `is_not_cacheable` 15 predicates + `Utils::is_url_excluded` → Processing: `html-min` + CDN rewrite + `combine_css` `WP_Filesystem` → Cache: `wp-content/cache/wppo/{domain}/{path}/index.html` (+gzip, `.br`) + `advanced-cache.php` drop-in bypass → Output: `stash_cache` `wp_finalized_template_enhancement_output_buffer` → Cleanup: `invalidate` on `save_post` `class-main.php:save_post` (home/archive/tax smart purge) + `CDN_Purger::purge_all` on `wppo_after_cache_clear`.
- Gaps: stampede (A09 P-CACHE-03), double directory walk, `USE_VERIFY_NONCE` cookie hash.

## 2. CSS/JS optimization (Main + Asset_Manager + Google_Fonts + CriticalCss + UsedCss)
- Input: `wppo_settings[optimization]` + per-post `_wppo_disabled_scripts` → Validation: `Util::sanitize_settings_recursively` → Processing: `minify_css/js` (`matthiasmullie/minify`), `add_defer_attribute` `script_loader_tag:10`, `delayJS` `lazyload.js:609`, `hostGoogleFontsLocally` `class-google-fonts:109`, `criticalCss` `class-critical-css:152` bucket hash, `used_css` `class-used-css:461` selector extraction → Output: `wp_maybe_inline_styles` via `wppo_inline_combined_css` filter → Cleanup: `regenerate_ccss` Action Scheduler.
- Gaps: triple `preg_replace_callback` (A12 Q-05), `combine_css` triple classify, 120× budget sim.

## 3. Image pipeline (Image_Optimisation + Img_Converter)
- Input: `wppo_img_info` + upload dir → Validation: `strpos evil.com` SSRF (A02), `realpath` guards → Processing: `maybe_serve_next_gen_images` regex fallback loses <source>/poster (A02 HIGH), `process_img_tag` TagProcessor vs regex duplication (350×2), `lazy_load_videos×3`, LRU×3 → Output: `<picture>` + `fetchpriority=low` via `wp_get_loading_optimization_attributes` → Cleanup: `delete_optimised_image` `wppo/` rmdir + cron `wppo_img_conversion` hourly.
- Gaps: duplication debt, filesize×2 per image (480 stats).

## 4. Object Cache (Object_Cache + templates/object-cache.php + redis-connect-helper)
- Input: `wppo_settings[object_cache]` + `wp-content/wppo-redis-config.php` → Validation: host/port/TLS/compression → Processing: `WP_Object_Cache` drop-in `blog_prefix` + `add_salt` multisite, `delete_salted` (A03 HIGH SCAN+DEL O(N)), `templates/object-cache:69` `:` namespacing → Output: `wp_cache_*` → Cleanup: `flush` `WP_CLI` `cache` subcommand.
- Gaps: SCAN+DEL flush, password hygiene strong.

## 5. DB Cleanup (Database_Cleanup + Cron + CLI)
- Input: `wppo_settings[database_cleanup]` 7 types → Validation: `wpdb->prepare` correct, `optimize_table` interpolation (A03 HIGH) → Processing: batched DELETE copy-paste `86/267/321/373/425` (A10 D), `clean_unattached_media` N×`wp_delete_attachment` timeout (A03 HIGH) → Output: `wp_send_json_success` counts → Cleanup: `wppo_database_cleanup_cron` daily/weekly/monthly + `on_post_change` `save_post/deleted_post`.
- Gaps: batch template duplication, per-request `get_option` churn.

## 6. Preload/Sitemap (Cron)
- Input: `preload_settings.preloadSitemap` + `wp-sitemap.xml` → Validation: `Cron::get_sitemap_urls` 500 cap 15s wall-clock → Processing: `wppo_generate_static_url` single events per URL 0-1800s rand + `wppo_page_cron_hook` 5h 200/batch → Output: static HTML prefill → Cleanup: `wppo_page_cron_batch` chain.
- Gaps: sitemap lock blocking (A03), per-page `wp_next_scheduled`×200.

## 7. RUM + PageSpeed + Telemetry + Suggestions
- Input: `rum_collect` public `token+IP` 120/hr `Util::transient_key` + `queue_pagespeed_scan` Action Scheduler + `Telemetry` cURL local scan → Validation: `wp_hash` daily token `hash_equals` (A08), SSRF hardening `wp_http_validate_url`+same-host+`MAX_REDIRECT_HOPS 2` → Processing: `RUM::store_sample` `get_option+update_option` per view 10k×200KB (A09 HIGH), `Trends` 30/URL+strategy cap 200 paths/14 days, `Suggestion_Engine` telemetry+PageSpeed → Output: `web_vitals_trends` option + `rum_data` REST → Cleanup: `wppo_web_vitals_rescan` daily auto_rescan gate.
- Gaps: RUM per-view UPDATE storm, trends reverse map missing.

## 8. Admin SPA (React, useNotice, apiRequest)
- Input: `wppoSettings` global via `wp_localize_script` → Validation: `apiCall('update_settings')` mutates global, `useNotice notify durationMs` → Processing: `App.js` god 527, `Dashboard` 1327, `FileOptimization` 2024, `ObjectCache` 902, `PluginSetting` allow-list 9 keys → Output: `NoticeBanner role=alert` → Cleanup: `dismiss_welcome` + `RecentActivityCard`.
- Gaps: stale closures fixed in `fix/deep-dashboard-2026` (B-01..H-03), `wppoSettings` mutable global (A12 A-04).

## 9. REST/CLI/Metabox/LLMs/LiteSpeed
- REST 25 routes `class-rest::get_routes` (all `manage_options`+`X-WP-Nonce` except `rum_collect` `__return_true`), CLI 7 subcommands, Metabox 2 (`_wppo_preload_image_url`, `_wppo_disabled_scripts`), LLMs virtual `/llms.txt` ETag/304/Link 20KB cap, LiteSpeed 4 modes (`auto/wppo/litespeed/standalone`) with `advanced-cache.php` arbitration.
- Gaps: `rum_collect` no args schema (R01), `core_tweaks` allow-list drift (fixed), LLMs `client_version` dead, LiteSpeed `is_lscache_active` grace.

> For per-file Input→output with side effects, see each `AUDIT/AGENTS/agent-*.md` § Findings (evidence `file:line`).
