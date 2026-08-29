# TECHNICAL-COMPLEXITY-AUDIT.md — 40+ Terms Classified

**Source:** Agent A 20 confusing + FileOptimization 40 toggles + competitive `docs/competitive-audit-2026.md`

| Term / Detail | File:Line | Problem | Classification | Action |
|---------------|-----------|---------|----------------|--------|
| Minify CSS/JS/HTML | `FileOptimization.js:333,737,658` | User thinks "make smaller" but not why | SIMPLIFY | Label "Make CSS/JS smaller — remove whitespace" + tooltip |
| Combine CSS | `:358` | Single file vs inline-budget `class-cache.php:748` hidden | MOVE TO ADVANCED | Keep but under Advanced + FOUC warning `399` |
| Defer vs Delay JS | `:737 vs :901` | Both "load later" but defer≠delay | SIMPLIFY | Defer = "Load after page" vs Delay = "Load after interaction" benefit/risk |
| Delay strategies interaction/idle/viewport/priority/timeout | `:936-1123` 5 fields | GTM needs idle 3s `54` `delayJSIdleTimeout 3000` jargon | MOVE TO ADVANCED | Collapse accordion |
| Critical CSS vs Used CSS | `:84-86` + `CriticalCssPanel` | Above-fold vs PurgeCSS 30-80% | MOVE TO ADVANCED | Critical = "Load visible styles first" + LCP badge |
| Remove Query Strings `ver=` | `FileOptimization.js:Assets` | Proxy/CDN refuse versioned `ver=` | HIDE BY DEFAULT | Advanced, rare |
| CDN Hostname rewrite `WP_HTML_Tag_Processor` | `class-cache.php:1318` `FileOptimization:1684` | Origin vs CDN | SHOW CONTEXTUALLY | Only when CDN detected |
| Preconnect vs DNS Prefetch vs Preload Fonts | `PreloadSettings.js:433,560` | TCP+TLS vs DNS vs `rel=preload` | MOVE TO ADVANCED | Preconnect/DNS advanced, Fonts keep |
| Speculation Rules prerender/moderate `*` | `PreloadSettings.js:433` `class-main.php:655` | Next-page inflate analytics | MOVE TO ADVANCED | Advanced |
| AI Adaptive RUM→heuristic | `AiPanel.js:291` `class-ai-adaptive.php` | ML auto-tune predictive | MOVE TO ADVANCED | Off by default |
| Edge Workers/Bunny SWR TTL | `EdgeCachePanel.js:261` `class-edge-cache.php` | Workers, SWR 86400 | MOVE TO ADVANCED | Off |
| Object Cache Redis Standalone/Sentinel/Cluster TLS | `ObjectCache.js:37,488,580` | Topologies, TLS, persistent, compression `templates/object-cache.php` | KEEP VISIBLE collapsed / MOVE TO ADVANCED | Standalone collapsed, Sentinel/Cluster advanced |
| Redis DB 0-15 | `ObjectCache.js:37` `database:0` | 0-15 unknown shared host | SIMPLIFY | Tooltip "Database number (0 default, shared host ask)" |
| Autoloaded Options LENGTH | `AutoloadedOptions.js:133` | `wp_options` LENGTH | MOVE TO DIAGNOSTICS | Dev only `Dashboard` |
| System Info OPCache/Infrastructure | `SystemInfo.js:353` | PHP/DB/WP/Server/Cache/OPCache | MOVE TO DIAGNOSTICS | On demand |
| TTFB/LCP/CLS/INP/FCP DOM_SIZE | `PerformanceAudit.js:121-129 88` | 2.5/4, 200/500, 500/1000 thresholds | SIMPLIFY | Badge first Good/Needs/Poor `StatusBadge.js` number in tooltip |
| PageSpeed PSI CrUX | `PageSpeedPanel.js:479` | Lighthouse scores | KEEP VISIBLE | But merged with telemetry deduped `Dashboard.js:78` |
| WebP/AVIF vs HEIC/JXL wasm-vips | `ImageOptimization.js:506,608` GD/Imagick vs WP7.1 | Formats | SIMPLIFY | "Modern formats (WebP/AVIF) — 25-50% smaller" |
| Lazy Native vs IntersectionObserver | `ImageOptimization.js:42` `src/lazyload.js:11K` | Native `loading=lazy decoding=async` vs legacy | HIDE BY DEFAULT | Native ON auto, hide |
| Placeholder none/svg/dominant_color/lqip 20x20 | `ImageOptimization.js:295` | 4 options no thumbs | MOVE TO ADVANCED | Thumbnails |
| Wrap in Picture | `:Wrap` | `<picture>` fallback required D4 | HIDE BY DEFAULT | Auto with D6 |
| Exclude handles "one per line" | `FileOptimization.js:411-575` 12 textareas | Need Asset Manager `class-metabox.php:453` knowledge | SIMPLIFY | Picker + search, not free-text |
| Woo Keep URL regex `shop/.*` | `FileOptimization.js:1187` | Regex | SHOW CONTEXTUALLY | Only when Woo active `1145` |
| Server Rules .htaccess Gzip+Expires `insert_with_markers` `wppo_htaccess_*` + Nginx `wppo_nginx_rules` | `FileOptimization.js:1521,1631` `class-htaccess-handler.php` | FTP 755 `class-main.php:989` OLS restart `1530` | MOVE TO ADVANCED | Raw block behind toggle |
| Brotli `.br` `enableNextGenRewrite/enableBrotli` | `FileOptimization→Network` | Brotli opt-in `wppo_litespeed_nextgen_rewrite` | HIDE BY DEFAULT | Opt-in |
| Vary:Accept + NextGen | `FileOptimization.js` | Accept header gate | HIDE BY DEFAULT | Auto |
| Heartbeat Control default/60s | `FileOptimization→Core` | Admin heartbeat flood | MOVE TO ADVANCED | Rare |
| Block Assets On Demand `should_load_separate_core_block_assets` WP6.9 | `FileOptimization.js:81` `class-main.php:185-279` | Version-gated | HIDE BY DEFAULT | Auto ON 6.9 |
| Inline CSS/JS minify inside `<style>` | `FileOptimization.js:84-86` | Sub-toggle under HTML | HIDE BY DEFAULT | Under Minify HTML |
| ORPHANED meta `LEFT JOIN posts IS NULL` | `DatabaseCleanup.js:54-127` | False positives | MOVE TO ADVANCED | Review badge `E6` |
| OPTIMIZE TABLE skip >1GB | `DatabaseCleanup.js:197` | <1GB log | HIDE BY DEFAULT | Auto |
| `wppo_*` hooks `wppo_should_cache_request:1524` `wppo_invalidation_urls:1920` etc 30+ | `docs/hooks.md:493` | Developer | KEEP FOR DEVELOPERS | Docs only |
| Cron `wppo_page_cron_hook 5h` 500 cap 15s `Cron::get_sitemap_urls` | `class-cron.php` | Wall clock | HIDE BY DEFAULT | Auto |
| RUM token+IP rate-limit `rum_collect` | `class-rum.php` | Beacon | HIDE BY DEFAULT | Toggle only |
| bfcache/OD/perf_translations `od_integration/bfcache/perf_translations` | `class-main.php:453` `class-bfcache.php` `class-od-bridge.php` | Gated false | HIDE BY DEFAULT | Advanced |
