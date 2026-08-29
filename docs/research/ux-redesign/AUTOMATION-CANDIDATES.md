# AUTOMATION-CANDIDATES.md — 55 Features Classified

**Detection sources:** `Server_Rules::get_server_type:191`, `LiteSpeed_Integration:800/1343`, `is_plugin_active` WP Rocket/LSCWP/Cloudflare, `extension_loaded redis` `ObjectCache:1047`, `GD/Imagick` `Img_Converter:84`, `wp_is_block_theme()`, `WP 7.1 wasm-vips` `ImageOptimization:506`, `Util::transient_key:781` multisite, `str_contains $_SERVER:LiteSpeed` vs CLI mismatch `class-litespeed-integration.php:201`, `wp_sitemaps` `Cron::get_sitemap_urls:500 cap 15s`, `Accept header` `class-cache.php:748` inline budget.

| # | Feature | Detectable? | Classification | Logic |
|---|---------|-------------|----------------|-------|
| A7 | Enable Cache | Yes (is_front_page, `WP_CACHE` `class-advanced-cache-handler.php:989`, cache dir `Dashboard:668`) | AUTOMATE (Recommended ON) | If no conflicting cache (LSCWP/WP Rocket) and `WP_CACHE true` → enable, lifespan 24h |
| A8 | Logged-in Cache | Yes (roles `wp_roles()`, `is_user_logged_in`) | RECOMMEND | Suggest roles editor/customer if membership plugin detected, else OFF |
| B1 | Minify CSS | Yes (no. of CSS handles `wppo_total_js_css`) | RECOMMEND | Safe with `exclude_js jquery` `Main:46` |
| B2 | Combine CSS | Yes (FOUC detect via `styles_inline_size_limit` `class-cache.php:748` + theme type) | ADVANCED ONLY | Needs preview, not auto |
| B7 | Minify HTML | Yes (HTML size) | RECOMMEND | Safe |
| B9 | Minify JS | Yes | RECOMMEND | Safe with exclude |
| B10 | Defer JS | Yes | RECOMMEND | Safe |
| B11 | Delay JS | Yes (checkout detection `is_checkout` Woo `class-main.php:1166`) | ADVANCED ONLY | Not auto if Woo active |
| B17 | Block Assets On Demand | Yes `wp_is_block_theme()` WP6.9+ `Main:185` | AUTOMATE | Auto ON on 6.9 block theme |
| D1 | Lazy Native | Yes | AUTOMATE | ON `ImageOptimization.js:42` already |
| D6 | Convert WebP/AVIF | Yes GD/Imagick check `Img_Converter:84` | RECOMMEND | If GD/Imagick available |
| D7 | MIME override HEIC/JXL | Yes WP7.1 `wp_image_editor` | ADVANCED ONLY | Only WP7.1+ |
| C1 | Cache Warm-up | Yes cron `wppo_page_cron_hook 5h` | AUTOMATE | ON `enablePreloadCache true:220` keep |
| C2 | Preload Sitemap | Yes sitemap exists `wp-sitemap.xml` | RECOMMEND | If CPT beyond posts |
| C6 | Speculation | Yes browser `Sec-Speculation` + `wp_speculation_rules_configuration` | RECOMMEND | Moderate prerender |
| F1 | Redis Standalone | Yes `extension_loaded redis` + `redis-cli ping` PONG 1.36M (Agent N) | DIAGNOSTIC ONLY | Manual opt-in, show PONG status `ObjectCache:922` then enable |
| F2/F3 | Sentinel/Cluster | Yes config `wppo-redis-config.php` | ADVANCED ONLY | Enterprise only |
| G2 | PageSpeed API Key | Yes `performance_audit.pagespeed_api_key` empty → 681 failed | DIAGNOSTIC ONLY | Prompt for key, show failed queue error `PageSpeedPanel:479` |
| A14 | RUM | Yes traffic `wp_count_posts` >1k | RECOMMEND | Collect if traffic, else off |
| A15 | Autoload | Yes `LENGTH autoload` query `AutoloadedOptions.js:133` | DIAGNOSTIC ONLY | Always diagnostic |
| E1-5 | DB cleanup Safe | Yes counts `DatabaseCleanup.js:131` | RECOMMEND | Weekly schedule `E8` |
| E6 | Orphaned Meta/Unattached | Yes false positives `class-database-cleanup.php:54` | ADVANCED ONLY | Review mode + sample |
| B14 | Server Rules | Yes `get_server_type()` Apache vs LiteSpeed vs Nginx `Server_Rules:191` | AUTOMATE when Apache/LS, DIAGNOSTIC when Nginx | Auto `insert_with_markers` Apache/LS, manual copy Nginx `1631` |
| B15 | CDN | Yes `cdnURL` empty + `Cdn_Purger` `class-cdn-purger.php:238` + header `CDN` | RECOMMEND | If CDN header detected |
| A19 | Edge Cache | Yes `edge_cache.enabled false` + host-agnostic Workers/Bunny `class-edge-cache.php` | ADVANCED ONLY | High-traffic manual |
| A20 | AI Adaptive | Yes RUM data `wppo_ai_adaptive_*` `AiPanel:291` | ADVANCED ONLY | Needs RUM first |

Summary: **AUTOMATE 5** (Cache, Block Assets, Lazy Native, Warm-up, Server Rules Apache/LS), **RECOMMEND 10** (Minify, Defer, HTML, WebP, Sitemap, Speculation, RUM, Safe DB, CDN, LCP), **ADVANCED ONLY 9** (Combine, Delay, Bg, MIME, Orphaned, Sentinel/Cluster, Edge, AI, UsedCSS), **DIAGNOSTIC ONLY 4** (Autoload, SystemInfo, Redis status, PageSpeed failed), rest **LEAVE MANUAL** (Woo regex, CDN hostname, DB schedule).
