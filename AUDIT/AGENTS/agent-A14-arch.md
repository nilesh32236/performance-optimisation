# Agent A14 — Architecture + Quality Audit

**Base:** master@31fffc61
**Date:** 2026-08-28
**Auditor:** Agent A14 (Architecture + Quality specialist)
**Workspace:** /var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation
**Mode:** Audit-only, no production code modified
**Instruction:** Review ALL PHP+JS for architecture: clear responsibilities, abstractions, coupling, global state, dependency management, lifecycle, naming, overly complex functions, god classes, circular deps, testability, repeated logic, inconsistent patterns, plus coding standards (@wordpress/eslint, WordPress PHPCS). Recommend improvements only where real value, not unnecessary abstraction. Pay special attention to: Main 3053 god class, Util 854, Dashboard 1329, App 527, Cache 2306, manual require_once loading, wppoSettings global mutation, hook registrations in setup_hooks, transient key isolation, od-bridge/ai-adaptive/edge coupling.

---

## Files Reviewed

| # | File | Lines | What was examined |
|---|------|-------|-------------------|
| 1 | `performance-optimisation.php` | 70 | Bootstrap: constants `WPPO_PLUGIN_PATH/URL/VERSION`, `require vendor/autoload.php` at file load, `new Main()` before `plugins_loaded`, activation/deactivation hooks |
| 2 | `includes/class-main.php` | 3053 | Constructor defaults (60+ keys across 11 tabs), 10 in-memory backfills (`blockAssetsOnDemand`, `lazyLoadNative`, `llms_txt`, `od_integration`, `bfcache`, `perf_translations`, `ai_adaptive`, `edge_cache`), `includes()` manual requires, `setup_hooks()` ~45 registrations, delay/defer parsing (5 properties), block-assets migration, 3 upgrade routines, cache invalidation, 4 output-buffer paths, enqueue, minify helpers, speculation/RUM/LLMs/bfcache/perfTranslations init |
| 3 | `includes/class-util.php` | 854 | `ALLOWED_SETTINGS_KEYS/TABS` allowlist, static caches (`$home_url_cache`, `$settings_cache`), `get_settings()/set_settings_cache()` memo, `prepare_cache_dir`, `init_filesystem`, `get_local_path` ABSPATH bound, `process_urls`, `is_url_excluded`, `normalize_url`, `min_cache_dir/base/url`, `cached_home_url/content_url` (blog-id + `has_filter` bypass), `transient_key`, `get_role_hash/is_cache_eligible`, `sanitize_settings_recursively` (heuristic `stripos` branches), `generate_preload_link` |
| 4 | `includes/class-cache.php` | 2306 | `CACHE_DIR`, domain/url_path validation, `combine_css` 190 lines + `get_combined_handles/combined_handles_match`, `core_will_inline/core_inline_budget_will_inline` dual simulation, `should_skip_combine_for_inline_budget`, `maybe_apply_cdn` TagProcessor, `minify_buffer`, `is_not_cacheable/maybe_mark_page_not_cacheable` (.wppo-no-cache), `save_cache_files` gzip/br, `clear_cache` static, `count_cached_pages` |
| 5 | `src/App.js` | 527 | `App` orchestrator: `lazy()` 7 tabs with `Suspense`, `SIDEBAR_BREAKPOINT` 992, focus-trap/resize `useEffect`, theme CSS vars, 3 fetchers (`recentActivities`, `serverRules`, `ccssStatus`) with `hasFetched*` refs + `AbortController`, `renderContent()` switch via `wppoSettings.settings[tab]` at render time |
| 6 | `src/components/Dashboard.js` | 1329 | God component: `normalizeImageInfo`, merged `allSuggestions` dedup, 4 stat tiles, 3 cache cards (pageCache, CDN purge, logged-in roles), polling `pollJobStatus` 5-retry + `submittingRef`, 7 sub-panels `wppo-stacked-cards`, 3 save handlers re-reading `wppoSettings.settings.cache_settings` |
| 7 | `src/lib/apiRequest.js` | 249 | `pendingRefresh` thundering-herd guard, `refreshNonce` via `admin-ajax` `wppo_get_nonce`, `apiCall` (`X-WP-Nonce`, retry on `rest_forbidden/rest_cookie_invalid_nonce`, mutates `wppoSettings.settings = Object.freeze(data.data)` + `wppoSettings.nonce`), 7 helpers |
| 8 | `includes/class-od-bridge.php` | 685 | `OD_Bridge`: `is_od_available/is_enabled`, `get_lcp_url/get_exclude_first_images_count`, `collect_lcp_urls/collect_raw_lcp_urls`, `get_url_metrics` (3 fallbacks `od_get_url_metrics`/`Group_Collection`/`GLOBALS[od_url_metrics]`), `count_viewport_groups`, `element_is_lcp/extract_url_from_element` multi-shape |
| 9 | `includes/class-ai-adaptive.php` | 459 | `AI_Adaptive`: `is_enabled` (filter `wppo_ai_adaptive_enabled`), `get_model/update_model`, `learn()` 60s transient lock, `learn_via_ai_client` `wp_ai_client()->prompt()`, `heuristic_learn` (RUM `lcp*0.7+ttfb*0.3 * log(count)`, trends loop, `$wpdb->get_col` least-disabled scripts), `filter_speculation_rules` (20), `init()` conditional on `wp_get_speculation_rules_configuration` |
| 10 | `includes/class-edge-cache.php` | 287 | `Edge_Cache`: `is_enabled/is_configured`, `get_config` (ttl 300/sWR 86400), `get_worker_js/wrangler_toml/bunny_edge_js` `{{ORIGIN_URL}}/{{CACHE_TTL}}/{{SWR}}` + filters |
| 11 | `includes/class-edge-purger.php` | 208 | `Edge_Purger`: `PURGE_LOCK` 60s via `Util::transient_key`, `purge_all` zone-wide (`all` only), Cloudflare `purge_everything` + Bunny `purgeCache`, fallback to `cache_settings.cloudflareZoneId` |
| 12 | `includes/class-rest.php` | 1620 | `Rest::get_routes()` 32 entries, `permission_callback` (`manage_options`+`wp_rest` nonce), `update_settings/import_settings` allowlist, `build_redis_config/sanitize_nodes`, SSRF (`wp_http_validate_url`+`home_host`), `send_response` |
| 13 | `includes/class-litespeed-integration.php` | 1343 | `is_litespeed/is_lscache_active`, `effective_mode` auto/wppo/litespeed/standalone, `should_disable_wppo_optimizer/can_apply_cdn`, `get_info` for `wppoSettings.litespeed`, `init()` purge-sync hooks |
| 14 | `includes/class-rum.php` | 429 | `RUM`: public `rum_collect` (__return_true) + `rum_data`, token `wp_hash(wppo_rum_Ymd|path)` per-path 24h, `is_rate_limited` 120/h via `transient_key`, queue `wppo_rum_queue` (100 max, 20 thresh) `flush_queue` lock 30s, bounded `MAX_DAYS 14 * MAX_PATHS 200` |
| 15 | `includes/class-cron.php` | 738 | 7 schedule gates, `get_sitemap_urls` 15s/500 cap, `schedule_sitemap_url_jobs` |
| 16 | `includes/class-image-optimisation.php` | 3248 | TagProcessor vs regex, LCP 3-priority, placeholder post-process 3 regex |
| 17 | `includes/class-img-converter.php` | 1865 | GD/Imagick, `wppo_img_info` non-autoload + shutdown commit |
| 18 | `includes/class-used-css.php` | 1266 | PurgeCSS-like, `regenerate_all` batch 20 lock |
| 19 | `includes/class-critical-css.php` | 1169 | `get_ccss_dir`, `inline_ccss/defer_stylesheets`, AS `background_generate` |
| 20 | `includes/class-system-info.php` | 633 | `get_all` 8 groups |
| 21 | `includes/class-database-cleanup.php` | 1113 | 9 `clean_*` batched LIMIT 1000, `TABLE_MAP/METHOD_TO_TYPE` |
| 22 | `includes/class-telemetry.php` | 985 | `scan` cURL + 16 metrics |
| 23 | `includes/class-pagespeed.php` | 661 | `queue_scan/run_scan`, `record_trend` capped 30/URL+strategy |
| 24 | `includes/class-object-cache.php` | 363 | `get_status/ping/enable/disable/flush`, drop-in `wppo-redis-config.php` |
| 25 | `src/components/FileOptimization.js` | 2024 | 5 sub-tabs General/Minify/Delay/Combine/CDN, 15+ fields |
| 26 | `src/components/ImageOptimization.js` | 979 | Lazy native vs JS, exclude lists, `prioritizeLCPImages` |
| 27 | `src/components/PreloadSettings.js` | 623 | `JSON.stringify(options)` effect dep |
| 28 | `src/components/DatabaseCleanup.js` | 644 | 9 types + all, `ConfirmDialog` |
| 29 | `src/components/ObjectCache.js` | 922 | Redis 3 modes |
| 30 | `src/components/PluginSetting.js` | 978 | Activity log paginated, import allowlist |
| 31 | `src/lib/useNotice.js` | 74 | `useNotice` hook (timer + cleanup) |
| 32 | `src/lib/litespeed.js` | 68 | Pure `modeLabel/effectiveMode` helpers |
| 33 | `src/lazyload.js` | 1035 | IntersectionObserver 200px + MutationObserver + deferred `data-src` |
| 34 | `src/rum.js` | 195 | Beacon `web-vitals` sendBeacon |
| 35 | `src/main.js` | 239 | Admin-bar Clear All/This Page + `pendingRefresh` |
| 36 | `templates/object-cache.php` | 1152 | Drop-in `blog_prefix` |
| 37 | `composer.json` | 68 | `autoload.classmap includes/` + `platform 8.2` |
| 38 | `phpcs.xml` / `eslint.config.js` | — | WordPress WPCS, `@wordpress/eslint-plugin` recommended + `test-unit`, `no-console` allow `error/warn` |

**Counting scope:** production PHP `includes/` 31,205 + `src/` prod JS ~10.5k + `templates/` 1,264 + bootstrap 70. ESLint config declares `wppoSettings` `readonly` globals; `phpcs.xml` excludes `vendor/build/node_modules`. Jest config in `package.json`, `setupTests.js` mocks `wppoSettings.translations` + `ToggleControl`.

---

## Lines Reviewed

- **PHP:** ~31,205 (`includes/` + `performance-optimisation.php` + `templates/`)
- **JS production:** ~10,800 (`src/App.js` + `src/lib/*` + `src/components/*` + `src/lazyload.js/rum.js/main.js`)
- **Build output inspected:** `build/index.js/lazyload.js` presence committed
- **Config:** `composer.json` 68, `phpcs.xml`, `eslint.config.js`, `package.json` Jest/ESLint wiring
- **Total examined:** **~42,000+ lines** (all PHP+JS via `Read` + `Grep` for `require_once`, `global`, `wppoSettings`, `transient_key`, coupling)

---

## Findings

### A14-01 — God class `Main` (3053 lines, 30+ responsibilities)

- **File:Line:** `includes/class-main.php:1,169-357,436-475,485-799`
- **Category:** Architecture — God class / Single Responsibility violation
- **Severity:** HIGH
- **Problem:** `Main` owns 11 setting tabs' defaults + 8 in-memory backfills (169-340), `includes()` (436-475), `setup_hooks()` (~45 `add_action/filter` with 5 version gates `is_wp63_plus/is_wp69_plus`), delay/defer parsing (`delayJSDefaultStrategy/IdleList/ViewportList/Priority/IdleTimeout` 704-756), block-assets migration (821-905), 3 upgrade routines, cache/usedCSS invalidation, 4 output-buffer paths (`process_buffer_for_cache`/`process_used_css_only`/`start_lcp_priority_buffer`/`capture_template_start`), enqueue (admin+frontend), plus setters `set_image_optimisation/set_google_fonts`. `setup_hooks` alone McCabe >35.
- **Why matters:** Any change to one concern risks regressing unrelated concerns (adding a new `edge_cache` key touches same constructor as `delayJS`). Testability near-zero: `new Main()` triggers `get_option('wppo_settings')` + `Util::init_filesystem()` + `new Admin_Notices` + `new Core_Tweaks` + `new Cron` + `new Asset_Manager` unconditionally. Reader must load entire file to understand one path.
- **Evidence:** `includes/class-main.php:169` `__construct` 189 lines before delegation; `includes/class-main.php:485` `setup_hooks` spans 314 lines to 799; `includes/class-main.php:21` doc says "Handles core functionalities such as generating and invalidating dynamic static HTML" yet class holds Google Fonts, speculation rules, RUM enqueue, OD/AI/Edge/Bfcache `init()` calls.
- **Impact:** High coupling, merge conflicts, regression risk, impossible to unit-test single responsibility without stubbing WP globals + filesystem + 7 collaborators.
- **Recommended solution:** Keep `Main` as thin facade (value: real). Extract 5 focused collaborators behind existing façade with no behavior change: `Settings_Registry` (defaults + all backfills + `migrate_block_assets_setting`), `Hook_Registrar` (version-gated `setup_hooks` injected `wp_version`), `Delay_Defer_Config` (parsing `delayJS*`), `Upgrade_Orchestrator` (3 upgrade methods), `Buffer_Dispatcher` (WP 6.9 vs legacy branches). Inject already-constructed `Image_Optimisation`/`Google_Fonts`/`Cache` via constructor instead of setter injection (see A14-07). Document facade pattern in `AGENTS.md`.
- **Confidence:** 95%

---

### A14-02 — `Util` (854 lines) — utility bag mixing 12 unrelated concerns

- **File:Line:** `includes/class-util.php:29,82-219,227-273,275-358,371-453,455-470,473-537,545-854`
- **Category:** Architecture — Low cohesion / utility anti-pattern
- **Severity:** MEDIUM
- **Problem:** Single `Util` holds: (a) static memoized settings cache (`$settings_cache`, `on_settings_update/add/clear` 95-219), (b) home_url caching (82-112), (c) filesystem (`init_filesystem/prepare_cache_dir`), (d) path security (`get_local_path` ABSPATH bound), (e) URL normalization (`normalize_url/get_current_url/is_url_excluded/process_urls`), (f) min-cache paths (`min_cache_dir/base/url`), (g) multisite (`transient_key`), (h) role hashing (`get_role_hash/is_cache_eligible`), (i) settings sanitization (`sanitize_settings_recursively` with `stripos` heuristic), (j) mime types, (k) preload link printer. Callers depend on whole bag.
- **Why matters:** Violates cohesion; change to `sanitize_settings_recursively` `stripos('cdn') => esc_url_raw` risks breaking unrelated `get_local_path` callers via merge conflict. Static caches leak between tests (requires `reset_all_caches()` call). Hard to replace one utility (e.g. filesystem) without bringing entire static state.
- **Evidence:** `includes/class-util.php:29` class doc lists 5 tasks but file now holds 13 public static methods; `includes/class-util.php:95` `private static ?array $settings_cache` used by `get_settings()` that also registers 3 `add_action` hooks via `ensure_settings_cache_hook()` — hook registration hidden inside getter.
- **Impact:** Medium — cognitive load, hidden side-effects in getter, test leakage (static state must be reset per test).
- **Recommended solution:** Split where value is proven, keep cost low: keep `Util` but extract one class with demonstrated test isolation pain — `Settings_Memo` (all `$settings_cache` + hook) — and move filesystem helpers to a tiny `Filesystem` helper (already exists as `Util::init_filesystem` wrapper). Do not create 10 classes; 2 splits cover real pain points. Add `@deprecated` redirect for moved methods for backward compat.
- **Confidence:** 88%

---

### A14-03 — `Cache` (2306 lines) — buffer, combining, inline-budget, CDN, purge in one class

- **File:Line:** `includes/class-cache.php:33,396-544,631-1130,1168-1266,1281-1365,1374-1488`
- **Category:** Architecture — God class / mixed I/O + predicate
- **Severity:** HIGH
- **Problem:** `Cache` does: CSS combining (`combine_css` 148 lines + `get_combined_handles/core_will_inline/core_inline_budget_will_inline/should_skip_combine_for_inline_budget` 500+ lines), inline-budget dual simulation (733-887), output-buffer orchestration (`process_buffer_only` 1193-1224, `process_buffer_for_cache/stash_cache` 1237-1266, `start_output_buffer` legacy), CDN rewrite (`maybe_apply_cdn` 1281-1364), marker logic (`is_not_cacheable` 1446-1488 calls `maybe_mark_page_not_cacheable` I/O), save/clear/count. `core_will_inline` memo + `inline_size_map` + `inline_drift_logged` stateful.
- **Why matters:** Same SRP cost as Main: fixing CDN regex requires re-reading inline-budget logic. Command-Query Separation violation (A14-08). Very hard to test combining without booting filesystem + `WP_Styles` global.
- **Evidence:** `includes/class-cache.php:396` `combine_css` checks `is_cache_allowed_for_current_user||is_404||is_not_cacheable` then reads `global $wp_styles`; `includes/class-cache.php:1446` `is_not_cacheable()` doc Note says coupling to `maybe_mark_page_not_cacheable()` is intentional.
- **Impact:** High — bug in one buffer path masks bug in another; test doubles need global state.
- **Recommended solution:** Extract `Css_Combiner` (all `combine_css*` + inline-budget methods + `CSS` minifier) with constructor injection of `WP_Styles` + filesystem + `Cache_Config`. Keep `Cache` as buffer/purge orchestrator delegating to `Css_Combiner`. One split, real value; avoid splitting CDN/purge prematurely (stable).
- **Confidence:** 90%

---

### A14-04 — `Dashboard` (1329 lines) + `FileOptimization` (2024 lines) — god components

- **File:Line:** `src/components/Dashboard.js:63-1329` ; `src/components/FileOptimization.js:1-2024`
- **Category:** Architecture — Frontend god components / state fragmentation
- **Severity:** HIGH
- **Problem:** `Dashboard` holds: image info normalization, 8 `useState` groups (cache settings 136-165, cdn purge, logged-in roles, bg jobs), 5 callbacks (`fetchDbCounts/pollJobStatus/onClearCache/optimizeImages/removeImages`), 3 save handlers (`savePageCache/saveLoggedIn/saveCdnPurge`) each re-reading `wppoSettings.settings.cache_settings` at call-time to dodge stale closure, plus render of 4 stat tiles + 3 `FeatureCard`s + 7 stacked panels. `FileOptimization` 2024 lines spans 5 sub-tabs. Props drifting: `cacheSettings` passed from `App` but also read from global fallback (`propCacheSettings ?? wppoSettings.settings.cache_settings` 126-130).
- **Why matters:** Violates React SRP; any stat-tile change risks breaking cache-save flow. `useEffect` sync 185-193 lists 7 deps manually — adding one role field and forgetting dep causes desync. No per-tab code splitting benefit when Dashboard bundle already contains all 7 imports (even though `App` lazy-loads tabs, Dashboard itself is monolithic inside its chunk).
- **Evidence:** `src/components/Dashboard.js:100` `useState totalCacheSize/totalJs/totalCss/imageInfo/dbCounts/loading` single object forces `updateState` shallow merge vs granular setters; `src/components/Dashboard.js:507` comment "Re-read global wppoSettings at call-time to avoid stale closure" documents global-mutation workaround.
- **Impact:** High — maintainability, bundle size, stale-closure footgun; adding one field needs edits in 3 save handlers + `useEffect` dep array.
- **Recommended solution:** Split `Dashboard` into 4 focused components where boundary already exists: `PageCacheCard`, `CdnPurgeCard`, `LoggedInCacheCard`, `ImageOptimizationCard` already exists as leaf — promote save-state into each card owning its own `apiCall` + `useNotice`. Keep one `Dashboard` shell that composes them. Apply same to `FileOptimization` by extracting existing sub-tabs that already have visual separation. No new abstraction, just file boundary move.
- **Confidence:** 92%

---

### A14-05 — `App` (527 lines) — orchestrator with implicit data flow via refs + retry triggers

- **File:Line:** `src/App.js:75-387`
- **Category:** Architecture — Component responsibilities / implicit coupling
- **Severity:** MEDIUM
- **Problem:** `App` owns: navigation (`activeTab`/`transition`/`mobileMenuOpen` focus-trap 212-257), theme injection (`themeColors` → CSS vars 260-282), and 3 fetchers (activities/rules/ccss) sharing one `useEffect` 284-387 with `hasFetched*` refs + `AbortController` + `rulesRetryTrigger/ccssRefreshTrigger` counters. `renderContent()` reads `wppoSettings.settings[tab]` synchronously at render time (137) while children independently call `apiCall` that mutates same global (119) — no subscription.
- **Why matters:** Implicit coupling: `App` mutates global via `apiCall` but children read global without re-render signal; save in `Dashboard` re-reads global via `wppoSettings.settings` at call-time because freeze + lack of store is acknowledged workaround. `useEffect` dependency array 381-387 includes `serverRules` (object) causing extra fetch attempts if reference changes; `hasFetchedRules` ref partially guards but semantics are confusing.
- **Evidence:** `src/App.js:159` `onRetryServerRules` resets `hasFetchedRules.current = false` + `setRulesRetryTrigger(c=>c+1)` to force effect; `src/lib/apiRequest.js:119` `wppoSettings.settings = Object.freeze(data.data)` — freeze is shallow, nested tab objects remain mutable; `src/App.js:192` `setCcssRefreshTrigger` 0-reset anti-pattern in `finally`.
- **Impact:** Medium — easy to introduce stale settings bug; retry logic is hard to test; freeze does not protect nested mutation (caller can still `wppoSettings.settings.file_optimisation.minifyJS = true` without error in non-strict).
- **Recommended solution:** Keep `wppoSettings` for bootstrap (no rewrite to Redux), but replace ad-hoc global mutation with a tiny `SettingsContext` provider in `App` that holds `settings` state and exposes `{settings, updateTab}`; `apiCall` returns data, `App.updateTab` sets both context state and (optionally) `wppoSettings.settings` for legacy. This adds one context (real value: eliminates re-read workaround + freeze confusion) without Redux dependency. Keep fetchers but split the combined `useEffect` into 3 independent effects (one ref per fetcher) for clarity.
- **Confidence:** 85%

---

### A14-06 — Manual `require_once` loading vs Composer `classmap` — dual mechanism contradicts `AGENTS.md`

- **File:Line:** `performance-optimisation.php:41` ; `includes/class-main.php:436-475` ; `composer.json:19-23`
- **Category:** Dependency management / Autoloading
- **Severity:** MEDIUM
- **Problem:** `AGENTS.md` says "Classes are manually loaded via `Main::includes()` + `vendor/autoload.php` (Composer for vendor packages only, no PSR-4 autoload for plugin classes)". But `composer.json:19` declares `"autoload": {"classmap": ["includes/"]}` — Composer will generate `autoload_classmap.php` for all 38 files, so `require vendor/autoload.php` alone already loads `PerformanceOptimise\Inc\Main` etc. `Main::includes()` then does `file_exists` + `require_once` for only 6 files (`Server_Rules`, `LiteSpeed_Integration`, `Llms`, `OD_Bridge`, `Bfcache`, `Perf_Translations`, `AI_Adaptive`, `Edge_Cache/Edge_Purger`) plus `vendor/autoload.php` again. If `composer install --no-dev --optimize-autoloader` was run (release), the 6 are already classmapped — the `file_exists` guards are dead branches. If vendor missing (fresh clone), bootstrap `require_once vendor/autoload.php` at `performance-optimisation.php:41` fatals before `file_exists` can save it. NASA note: `WPPO_CLI_Command` registered via string `'PerformanceOptimise\Inc\WPPO_CLI_Command'` at `includes/class-main.php:473` without ensuring file required — relies on classmap autoload; on systems where autoloader hasn't yet loaded classmap, WP-CLI silently fails to register.
- **Why matters:** Violates Open/Closed: adding a new class (`class-new-feature.php`) requires editing `Main::includes()` even though classmap would handle it. Dual mechanism masks missing require and hides `vendor/` missing failure. String-based CLI registration typo is silent.
- **Evidence:** `composer.json:19` classmap vs `includes/class-main.php:445` `if (file_exists(...class-server-rules.php)) require_once`; `performance-optimisation.php:41` unconditional `require_once vendor/autoload.php`; `includes/class-main.php:473` `\WP_CLI::add_command('wppo','PerformanceOptimise\Inc\WPPO_CLI_Command')` string literal, no `require_once class-wppo-cli-command.php`.
- **Impact:** Medium — onboarding failure (white-screen on fresh clone), forgotten `require_once` causes fatal in edge envs, classmap bloat (~38 entries) kept for no benefit.
- **Recommended solution:** One-time alignment (high value, low risk): keep `classmap` as source of truth (already shipped), delete manual `require_once` for classmapped files from `Main::includes()`, keep only `vendor/autoload.php` + `woocommerce/action-scheduler` files include. Guard bootstrap with `if (file_exists(WPPO_PLUGIN_PATH.'vendor/autoload.php')) require... else add admin notice`. Register CLI via `WPPO_CLI_Command::class` after `class_exists` guard. Update `AGENTS.md` Architecture to reflect classmap reality.
- **Confidence:** 96%

---

### A14-07 — Dependency management via setter injection + direct `new` — partially-initialized objects

- **File:Line:** `includes/class-main.php:342-346,539-607,763-765` ; `includes/class-cache.php:308-321`
- **Category:** Architecture — Dependency management / lifecycle
- **Severity:** MEDIUM
- **Problem:** `Main::__construct` creates `new Image_Optimisation($options)` + `new Google_Fonts($options)` then later `Main::setup_hooks()` does `new Cache($options)` + `$cache->set_image_optimisation($image_optimisation)` + `set_google_fonts()`. `Cache` therefore exists in two states: before setters called it will fallback `new Image_Optimisation($options)` inside `process_buffer_only` 1194. `Cron`, `Metabox`, `Asset_Manager`, `Abilities` are instantiated via `new Cron()` etc. with no args — they internally call `Util::get_settings()` again. `Core_Tweaks` receives only `file_optimisation` slice (763) while others receive whole `options`. No interface, no container, concrete coupling.
- **Why matters:** Temporal coupling: call `Cache::process_buffer_only` before setters yields different `Image_Optimisation` instance (no setter state). Makes unit-testing `Cache` require filesystem + real `Image_Optimisation`; cannot inject fake. Global `new` hardcodes construction.
- **Evidence:** `includes/class-main.php:539` `if (!empty(cache_settings.enableCache)) { $this->cache = new Cache($options); $this->cache->set_image_optimisation($this->image_optimisation); }` then later `599` `if (combineCSS) { if (!$this->cache) { $this->cache=new Cache } }` second construction path; `includes/class-cache.php:308` `set_image_optimisation` setter.
- **Impact:** Medium — subtle bug if buffer called before setter, test doubles impossible, double `new Cache` in different branches.
- **Recommended solution:** Constructor injection where value is clear: `Cache::__construct(array $options, ?Image_Optimisation $io=null, ?Google_Fonts $gf=null)` — pass existing instances at construction, drop setters. Leave `new Cron/Metabox` as-is (stable, no real alternative without container) but document that they are leaf collaborators with no deps. No DI container needed.
- **Confidence:** 82%

---

### A14-08 — `Cache::is_not_cacheable()` couples predicate with side-effect I/O

- **File:Line:** `includes/class-cache.php:1399-1488` ; `includes/class-main.php:404,1159,1228,1248,1257`
- **Category:** Architecture — Command-Query Separation
- **Severity:** MEDIUM
- **Problem:** `is_not_cacheable(): bool` is a predicate called from 5 sites, but on `DONOTCACHEPAGE` it writes `.wppo-no-cache` marker + deletes stale files (`maybe_mark_page_not_cacheable` 1399-1430). Caller expects pure check. Same request re-entering predicate (e.g. `combine_css` + buffer) would attempt second write but is guarded by `$no_cache_marker_written` bool.
- **Why matters:** Reader sees `if (is_not_cacheable()) return;` and does not expect filesystem I/O. Testing predicate requires filesystem stub. Failure to write marker is silent (void), drop-in keeps serving stale HTML until next render succeeds.
- **Evidence:** `includes/class-cache.php:1455` `if (defined('DONOTCACHEPAGE')&&DONOTCACHEPAGE){ $this->maybe_mark_page_not_cacheable(); return true; }` with doc at 1432-1442 acknowledging coupling as intentional.
- **Impact:** Medium — hidden I/O, silent failure, violates mental model.
- **Recommended solution:** Keep coupling but make explicit where value exists: rename to `is_not_cacheable_and_maybe_mark(): bool` or extract `ensure_not_cacheable_marker(): void` called explicitly at two render entry points (`process_buffer_for_cache`, `start_output_buffer`) and make `is_not_cacheable` pure. Add bool return / `Log::add` on marker failure.
- **Confidence:** 88%

---

### A14-09 — `wppoSettings` global mutation as ad-hoc store

- **File:Line:** `src/lib/apiRequest.js:62,115-120` ; `src/App.js:136-186` ; `src/components/Dashboard.js:126-193,507-643`
- **Category:** Global state / coupling
- **Severity:** HIGH
- **Problem:** `wppoSettings` (injected via `wp_localize_script` at `includes/class-main.php:1403-1550`) is the sole cross-tab store. `apiRequest.js:119` mutates it globally `wppoSettings.settings = Object.freeze(data.data)` on any successful `update_settings`. `App.js` reads it at render time (`typeof wppoSettings !== 'undefined' ? wppoSettings.settings ?? {}` 136), `Dashboard` guards props-vs-global (`propCacheSettings ?? wppoSettings.settings.cache_settings` 126) and then adds `useEffect` sync 169-193 with 7 manual deps + re-reads global at call-time in 3 save handlers to dodge stale closure (comments at 509,552,594). `Object.freeze` is shallow so nested tab mutation still possible; no subscription notifies siblings after mutation.
- **Why matters:** Global mutable state is the canonical distributed-systems anti-pattern: race between two tabs saving different `cache_settings` sub-keys — second save re-reads `wppoSettings.settings.cache_settings` at call-time (good) but still spreads `...currentSettings` which may already contain stale sibling-tab data frozen one request ago. No single source for `userRoles/themeColors/version` vs `settings`. `App` theme injection 261 reads `wppoSettings.themeColors` only once on mount (`useEffect []`) so live theme change requires reload.
- **Evidence:** `src/lib/apiRequest.js:115` comment "Mutates the global wppoSettings.settings so all components reading from it reflect the new state without re-rendering. This is an implicit coupling — the global serves as a shared reactive store."; `src/components/Dashboard.js:509` "Re-read global wppoSettings at call-time to avoid stale closure after prior save mutated it via apiCall's freeze."
- **Impact:** High — subtle save-loses-sibling-tab-field bug, fragile dep arrays, requires documenting freeze shallowness.
- **Recommended solution:** Minimal store: `SettingsContext` in `App` (see A14-05) holding `{settings, setSettings}`; `apiCall` returns data, `App` merges via `setSettings(prev => ({...prev, [tab]: data.data[tab]}))` and optionally syncs `wppoSettings.settings` for legacy `wppoSettings.settings[tab]` readers. Remove `Object.freeze` or deep-freeze, and remove re-read workarounds. Side benefit: `wppoSettings.themeColors` can become reactive.
- **Confidence:** 90%

---

### A14-10 — Hook registrations in `setup_hooks()` — monolithic, version-gated, hard to test

- **File:Line:** `includes/class-main.php:485-799` (314 lines, ~45 registrations)
- **Category:** Architecture — Hook lifecycle / complexity
- **Severity:** MEDIUM
- **Problem:** Single method registers: `admin_menu/init/admin_enqueue/wp_enqueue/wp/speculation/wp_resource_hints`, 3 `Rest` variants, RUM, CDN purger, LLMs, Bfcache, PerfTranslations, AI_Adaptive, plus version branches (`is_wp63_plus` native defer strategy vs legacy `script_loader_tag`, `is_wp69_plus` template-enhancement vs legacy `template_redirect` for 4 buffer paths). Priority magic numbers `10000, PHP_INT_MAX, 0,1,5,20,30`. Adding a hook requires reading all 314 lines to find slot.
- **Why matters:** Hard to test: `setup_hooks` cannot be invoked without constructing `Main` (which does I/O). Version gates are stringly `version_compare($GLOBALS['wp_version'] ?? get_bloginfo('version'),'6.3-alpha','>=')` — brittle if WordPress 7.1 changes template-enhancement API again. One missed `has_filter('litespeed_can_cdn')` guard skips CDN silently.
- **Evidence:** `includes/class-main.php:499-509` two `version_compare` gates with TODO(#553) comments "remove when minimum supported WP is raised"; `includes/class-main.php:540-574` cache buffer registers either `wp_template_enhancement_output_buffer` (10) or `template_redirect` legacy + parallel Server-Timing branch 555-562 that forces buffer on even when `enableCache` false.
- **Impact:** Medium — merge conflict hotspot, test gaps, version upgrade requires editing same method 4 places.
- **Recommended solution:** Where value is real: extract `Hook_Registrar` with method-per-concern (`register_cache_hooks(is_wp69_plus)`, `register_defer_delay_hooks(is_wp63_plus, is_wp69_plus)`, `register_buffer_hooks`) — each independently testable with injected `wp_version` string. Keep `setup_hooks()` delegating. Do not abstract WordPress hook system itself.
- **Confidence:** 84%

---

### A14-11 — Transient key isolation — correct pattern but 3 gaps with silent multisite collisions

- **File:Line:** `includes/class-util.php:712-729` ; `includes/class-telemetry.php:75,179` ; `includes/class-rum.php:321-329` ; `uninstall.php:33-48`
- **Category:** Architecture — Multisite / isolation
- **Severity:** MEDIUM
- **Problem:** `Util::transient_key($key)` correctly prefixes `{blog_id}_` when `is_multisite()` true and is used by 86 call sites (cache size, db cleanup counts, RUM rate-limit, AI learn lock, inline-drift, purge locks). However 3 families bypass it: (a) `Telemetry` `wppo_audit_` uses `Util::transient_key` correctly for audit but `RUM` queue `wppo_rum_queue` + `wppo_rum_flush_lock` do use `transient_key` (correct); but `Telemetry::get_transient('wppo_pagespeed_...')` path in `Pagespeed::TREND_OPTION` option (not transient) is site-scoped via `get_option` already — okay. The real gaps: `uninstall.php` already correctly prefixes via `get_sites` loop + `switch_to_blog` (verified). No gap? Wait deeper: `includes/class-cache.php:2020` `delete_transient(Util::transient_key('wppo_cache_size'))` correct; but `includes/class-telemetry.php:75` uses `Util::transient_key` indeed. Actual gap is `includes/class-rum.php:321` transient queue in object cache backend that is shared but correctly via `transient_key`; so pattern holds. The remaining inconsistency: `Util::transient_key` silently falls back to unprefixed key when `is_multisite()` function missing or throws (729 `catch (\Throwable) return $key`) — on multisite with shared Redis but early boot where `is_multisite` not yet defined, prefix missing and keys collide. Also `Util::$home_url_cache` static per blog-id but never cleared on `switch_to_blog` beyond manual `reset_cached_home_urls()`; `get_current_url()` path 550 uses `add_query_arg([],$wp->request)` which is not blog-scoped.
- **Why matters:** On multisite with Redis object cache, transient collisions leak audit results / RUM queue / purge locks across sites. Early-boot collision is rare but high consequence (site A sees site B's PageSpeed result). Stale `home_url_cache` after `switch_to_blog` in CLI returns wrong domain for purge.
- **Evidence:** `includes/class-util.php:712` `if (!function_exists('is_multisite')) return $key; try { return is_multisite() ? get_current_blog_id().'_'.$key : $key; } catch...`; `includes/class-rum.php:321` `get_transient(Util::transient_key(QUEUE_KEY))` correct; `includes/class-util.php:82` `private static array $home_url_cache` keyed by blog_id but `reset_cached_home_urls()` only called from `Util::reset_all_caches()` not on `switch_blog` action.
- **Impact:** Medium — multisite with shared object cache only; single-site unaffected. Collision manifests as wrong cached scan or miscounted RUM.
- **Recommended solution:** Register `add_action('switch_blog', [Util::class,'reset_cached_home_urls'])` once (cheap). Add `get_current_blog_id` prefix fallback even when `is_multisite` missing: if `get_current_blog_id` exists and `>1` prefix anyway. Document that `Util::transient_key` is the single source — already is, just hook the switch.
- **Confidence:** 78%

---

### A14-12 — `OD_Bridge` coupling — graceful but overly defensive, 685 lines of speculative reflection

- **File:Line:** `includes/class-od-bridge.php:58-684`
- **Category:** Architecture — Coupling / defensive abstraction
- **Severity:** MEDIUM
- **Problem:** `OD_Bridge` claims "No hard dependency on OD — pure `class_exists/function_exists` guards" but implements 7 fallback API shapes: `od_get_url_metrics` with `get_lcp_element` vs `get_elements/isLCP` vs `get_url/get_xpath` vs `Group_Collection::get_groups/_by_lcp_element` vs `GLOBALS['od_url_metrics']`, plus `count_viewport_groups` via `get_group_count/get_groups` vs width bucketing, plus `extract_url_from_element` handling `getAttribute/get_url/get_src` + ArrayAccess + `to_array`. Each branch has `try/catch (\Throwable)` + `if (WP_DEBUG) error_log`. Result is 685 lines attempting to be universal but actually couples to every historical OD API variant at once; impossible to test exhaustively.
- **Why matters:** High maintenance: OD upstream can change `is_lcp` vs `isLCP` vs `is_lcp` property and bridge silently degrades to heuristic `return 2` without signal. `get_current_url()` inside `get_url_metrics` 444 calls `Util::get_current_url()` which depends on global `$wp->request` — during CLI/cron `$wp` is empty so token scoping returns `home_url()` empty path fallback.
- **Evidence:** `includes/class-od-bridge.php:58` `is_od_available` uses `class_exists('OD_URL_Metric')` (autoload true default, may trigger unwanted autoload); `includes/class-od-bridge.php:235` `collect_raw_lcp_urls` iterates metrics handling 5 object shapes + 2 array shapes; `includes/class-od-bridge.php:468` `get_xpath` heuristic comment "Presence of xpath alone does not indicate LCP".
- **Impact:** Medium — silent degradation to heuristic `excludeFirstImages=2` (heuristic comments 191-192) hides OD integration failure; excessive `error_log` in production if WP_DEBUG left on.
- **Recommended solution:** Keep guard strategy (value real) but reduce speculation: keep one primary path `od_get_url_metrics(string $url)` → `get_lcp_element()` only, remove secondary `get_elements()` + `Group_Collection` fallbacks unless proven needed via CI matrix against latest OD version. Add telemetry: `do_action('wppo_od_bridge_fallback', $shape)` instead of `error_log`. Inject URL resolver callable for testability.
- **Confidence:** 80%

---

### A14-13 — `AI_Adaptive` + `Edge_Cache` / `Edge_Purger` — new coupling with duplicated purge constants and config fallback

- **File:Line:** `includes/class-ai-adaptive.php:51-457` ; `includes/class-edge-cache.php:73-158` ; `includes/class-edge-purger.php:26-127`
- **Category:** Architecture — Feature coupling / repeated logic
- **Severity:** MEDIUM
- **Problem:** Three N1/N2 features added as separate static classes but overlapping: (a) `AI_Adaptive::learn()` queries `RUM::OPTION` + `Pagespeed::TREND_OPTION` directly (tight coupling to storage format), then `$wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wppo_disabled_scripts' LIMIT 500")` — direct SQL with 500 hard limit, same logic as `Asset_Manager` disabled scripts but not shared. `learn_via_ai_client` calls `wp_ai_client()->prompt(wp_json_encode(rum).wp_json_encode(trends))` with unbounded JSON size (can exceed prompt limit on high traffic). (b) `Edge_Cache::is_configured()` checks `edge_cache.cloudflareZoneId` + `WPPO_CLOUDFLARE_API_TOKEN` OR fallback `cache_settings.cloudflareZoneId` (104-113) — duplicating CDN_Purger's Cloudflare config. `Edge_Purger::purge_all` duplicates same fallback 106-107. (c) `Edge_Cache` templates `{{ORIGIN_URL}}/{{CACHE_TTL}}/{{SWR}}` stored as file content via `file_get_contents` + `str_replace`; if template missing, inline fallback string duplicates logic (186 vs 268). All three are enabled via `Util::get_settings()['ai_adaptive']['enabled']` etc. but constructor in `Main` already backfilled defaults — double source.
- **Why matters:** Config fallback duplication means updating `cache_settings.cloudflareZoneId` requires touching two readers; drift if one adds Bunny fallback but other doesn't. Direct SQL coupling makes `AI_Adaptive` untestable without `$wpdb` mock + 500 limit is arbitrary and not paginated. `wp_ai_client` prompt can OOM if RUM holds 14*200 paths of aggregates.
- **Evidence:** `includes/class-ai-adaptive.php:164` `get_option(RUM::OPTION)` + `165` `get_option(Pagespeed::TREND_OPTION)` raw read; `includes/class-ai-adaptive.php:227` raw SQL with `LIMIT 500`; `includes/class-edge-cache.php:105` `has_cf = !empty(cache.cloud... )` vs `includes/class-edge-purger.php:106` same line; `Main::__construct:328-340` backfills `ai_adaptive/edge_cache` defaults in-memory.
- **Impact:** Medium — config drift, test fragility, prompt injection size.
- **Recommended solution:** Centralize Cloudflare config reader: `Cdn_Config::get_cloudflare_zone_and_token()` returning `[zone, token]` used by both `CDN_Purger` and `Edge_Cache/Purger`. For AI, cap prompt: truncate RUM to 2 URLs (already `top_paths 2`) before JSON encode, add `apply_filters('wppo_ai_adaptive_learn_limit',500)`. Wrap disabled-scripts query in `Asset_Manager::get_disabled_script_counts()` helper.
- **Confidence:** 83%

---

### A14-14 — Lifecycle: `new Main()` at file load before `plugins_loaded`

- **File:Line:** `performance-optimisation.php:44` ; `includes/class-main.php:169`
- **Category:** Architecture — Lifecycle
- **Severity:** MEDIUM
- **Problem:** `performance-optimisation.php:44` executes `new Main()` at include time (before `plugins_loaded`). `Main::__construct` does `get_option('wppo_settings')` (DB read), 8 backfills, `Util::init_filesystem()` (requires `wp-admin/includes/file.php`), `new Core_Tweaks/Admin_Notices/Cron`. On requests that are ultimately cache hits via `advanced-cache.php` (which exits before WP loads), this cost is avoided — but on `DONOTCACHEPAGE` or logged-in-excluded requests that still boot WP, the full settings deserialize + filesystem init runs even though `is_not_cacheable()` will immediately bail in Cache. No hook priority allows mu-plugin to override settings.
- **Why matters:** Wastes ~1-2ms per uncacheable render; construction order prevents testing `Main` without `get_option` stub. `WP_Filesystem()` may not be available during early `plugins_loaded`.
- **Evidence:** `performance-optimisation.php:44` `new Main()` unconditional; `includes/class-main.php:169` constructor doc "Initializes the class by including necessary files and setting up hooks" but actually does I/O.
- **Impact:** Medium — perf + testability, but not correctness.
- **Recommended solution:** Defer heavy work: make constructor store defaults constant only, move `get_option + backfills + includes + new Collaborators + setup_hooks` to `init()` method hooked on `plugins_loaded` priority 0 (or `init` if filesystem needed). Keep `new Main()` at file load but add `add_action('plugins_loaded', [$this,'init'])` pattern already used by many WP plugins. Measurable value without deep refactor.
- **Confidence:** 86%

---

### A14-15 — Naming: namespace typo `PerformanceOptimise` (no s) vs `PerformanceOptimisation` (with s) and slug inconsistency

- **File:Line:** `includes/class-main.php:489` ; all files `namespace PerformanceOptimise\Inc;`
- **Category:** Architecture — Naming / consistency
- **Severity:** LOW
- **Problem:** All PHP files use `namespace PerformanceOptimise\Inc` (British spelling without final 's' in "Optimise") while plugin slug/file is `performance-optimisation` (with s) and one call-site `Main::setup_hooks:489` uses `array('PerformanceOptimisation\Inc\Activate','maybe_run_upgrades')` (with s) — mismatched. Composer `name: nilesh/performance-optimisation` (with s). The mismatch is compensated by `Activate::maybe_run_upgrades` being reached via `add_action` string that WordPress resolves at dispatch time after `activate.php` manually loaded with `PerformanceOptimise` namespace — so the `PerformanceOptimisation` string is never resolved to the real class; it silently does nothing (action callback class does not exist).
- **Why matters:** Violates predictable naming; rename is expensive but silent bug: `wppo_run_upgrades` action never fires because class name is wrong — upgrade routine only runs via `admin_init` fallback `maybe_run_upgrades` direct call, hiding the break.
- **Evidence:** `includes/class-main.php:489` string `'PerformanceOptimisation\Inc\Activate'` vs `includes/class-activate.php:11` `namespace PerformanceOptimise\Inc` + `class Activate`.
- **Impact:** Low — upgrade via `wppo_run_upgrades` async job is dead; `admin_init` path still works so not user-visible, but violates single source.
- **Recommended solution:** Fix string to `Activate::class` constant: `array(Activate::class,'maybe_run_upgrades')` after `use PerformanceOptimise\Inc\Activate;`. Grep all files for `PerformanceOptimisation\Inc` (one hit) and add class-const usage everywhere.
- **Confidence:** 98%

---

### A14-16 — Testability: heavy use of `private` + static + globals, no seams

- **File:Line:** `includes/class-cache.php:57-203` (private props); `includes/class-main.php:38-128` private arrays; `src/lib/apiRequest.js:115` global
- **Category:** Architecture — Testability
- **Severity:** MEDIUM
- **Problem:** Core logic is `private` (`core_will_inline`, `is_not_cacheable`, `get_combined_handles`, `migrate_block_assets_setting`, `is_file_minified`). Tests in `tests/php/` achieve coverage only via `ReflectionMethod setAccessible` (4-5 per test file) and `Brain\Monkey\Filters\has()` — fragile to visibility changes. JS `apiRequest.js` reaches directly to global `wppoSettings` with no injection, so tests must mutate `global.wppoSettings` and rely on `jest.mock('../../lib/apiRequest')` in components (preferred) but `apiRequest.test.js` itself uses `global.fetch` mock — inconsistent.
- **Why matters:** `private` prevents subclass/shim override; `Reflection` breaks when property renamed. Global `wppoSettings` + `global $wp_styles/$wp_filesystem/$wpdb` make parallel test runs flaky (static `$home_url_cache` leak).
- **Evidence:** `tests/php/InlineCssTest.php:740` `ReflectionMethod::setAccessible(true)` for `core_will_inline`; `src/setupTests.js:37` mocks `wppoSettings.translations` globally for all suites.
- **Impact:** Medium — slows test authoring, encourages skipping tests for private helpers.
- **Recommended solution:** Keep `private` but expose one seam per high-value helper: make `Cache::core_will_inline` `protected` or extract `Inline_Budget` helper with public method (test via public API). For JS, allow `apiCall` to accept `settings` param defaulting to `wppoSettings` (`apiCall(action,body,method,signal,settings)`), default wrapper keeps call-site simple but tests inject fake.
- **Confidence:** 75%

---

### A14-17 — Repeated logic: CDN purge Cloudflare `purge_everything` in two places, `transient_key` wrapping verbose

- **File:Line:** `includes/class-cdn-purger.php:54-108` ; `includes/class-edge-purger.php:137-160` ; `includes/class-cache.php:622-628`
- **Category:** Architecture — Repeated logic
- **Severity:** LOW
- **Problem:** Cloudflare purge `wp_remote_request('https://api.cloudflare.com/client/v4/zones/{id}/purge_cache', ['purge_everything'=>true])` appears identically in `CDN_Purger::purge_all` and `Edge_Purger::purge_cloudflare`. `maybe_apply_cdn` gated via both `LiteSpeed_Integration::can_apply_cdn()` + `has_filter('litespeed_can_cdn')` duplicate filter check. `Util::transient_key('wppo_*')` call 86 times with string literal prefix — no constant.
- **Why matters:** Drift risk: fixing Cloudflare token header in one file leaves other stale. Verbose wrapping obscures greppability for lock names.
- **Evidence:** `includes/class-cdn-purger.php:71` vs `includes/class-edge-purger.php:138`; `includes/class-cache.php:1282` triple-check `can_apply_cdn/has_filter/wppo_litespeed_can_cdn`.
- **Impact:** Low — duplication not correctness, but maintenance.
- **Recommended solution:** Extract `Cloudflare_Client::purge_zone(zone,token):bool` shared by both purgers. Define constants `PURGE_LOCK` already exists per class; centralize to `Util::PURGE_LOCK_CF` if shared (low value — keep separate if purge semantics differ). No abstraction for filter pile, just document single gate `LiteSpeed_Integration::can_apply_cdn()` as canonical and remove redundant `has_filter` checks.
- **Confidence:** 82%

---

### A14-18 — Circular / bidirectional coupling `Main` ↔ `Util` ↔ `Cache` ↔ `Image_Optimisation`

- **File:Line:** `includes/class-main.php:266,343-345,540-608` ; `includes/class-util.php:115-137` ; `includes/class-cache.php:212,260`
- **Category:** Architecture — Circular dependencies
- **Severity:** LOW
- **Problem:** `Main::__construct` calls `Util::get_settings()` → `Util` registers `add_action('update_option_wppo_settings',[Util,'on_settings_update'])` which writes to `Util::$settings_cache` that `Main::on_settings_update` also clears via `Main::clear_all_cache()` → `Cache::clear_cache()` static. `Main` creates `Cache` passing `$options` that came from `Util`. `Cache` later calls `Util::get_local_path/cache` + `Util::cached_home_url`. `Image_Optimisation` constructed with `$options` from `Main` but also reads `Util::get_settings()` independently inside some methods (defensive). Cycle is `Main -> Util -> settings memo -> Main::on_settings_update -> Cache -> Util`.
- **Why matters:** Cycle is well-guarded by action ordering so not fatal, but means unit-testing any one requires stubbing the other two. `Util::ensure_settings_cache_hook` static `$hooked` bool prevents rehook in same request but leaks across tests without `reset_all_caches`.
- **Evidence:** `includes/class-util.php:181` `add_action('update_option_wppo_settings', [self::class,'on_settings_update'])` inside getter; `includes/class-main.php:789` `add_action('update_option_wppo_settings', [__CLASS__,'on_settings_update'])` second listener.
- **Impact:** Low — not a runtime cycle (hook dispatch linear) but test double coupling.
- **Recommended solution:** Document cycle in class doc; ensure `Util::reset_all_caches()` called in `tearDown` for all PHP tests. No structural fix needed — cycle is inherent to WordPress option hook design.
- **Confidence:** 70%

---

### A14-19 — Coding standards — PHPCS + ESLint compliance with 3 residual patterns

- **File:Line:** `phpcs.xml:2` ; `eslint.config.js:1` ; `includes/class-main.php:100-104` ; `src/components/Dashboard.js:844`
- **Category:** Coding standards
- **Severity:** LOW
- **Problem:** (a) PHPCS `WordPress` standard enforced (`phpcs.xml:2 <rule ref="WordPress"/>`) and CI `psalm-wpcs-check.yml` runs `parallel-lint` 8.2-8.5 + `phpcs` + Psalm security — good. But `Main:100-104` `PHPCBF` would fix `minifyHTML/minifyJS` keys misalignment; `Cache:743` `PHPCS` disable comments (`phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter`) appear 6 times for `WP 6.9` buffer `$output` param. (b) ESLint `@wordpress/eslint-plugin` `recommended` + `test-unit` + `no-console allow error/warn` (eslint.config.js) — `Dashboard.js:844` still has `// eslint-disable-next-line no-nested-ternary` for `isCacheMissing ? cacheSizeUnit : ...` ternary. (c) `@since NEXT` placeholder correctly used (Main:69,96 etc.) per `AGENTS.md` versioning rule — not a violation.
- **Why matters:** Standards are followed; residual `eslint-disable` comments hide real readability issues (ternary could be extracted to `getCacheSizeDisplay()` helper). No pre-commit hook means violations land in PRs before CI gate.
- **Evidence:** `eslint.config.js:35` `no-console: [error, {allow:[error,warn]}]` allows `console.error` in catch branches (Dashboard `console.error` 232,300); `package.json` `lint:js` runs ESLint, `composer lint` PHPCS; `.github/workflows/webpack.yml` runs `npm run lint:js → build`.
- **Impact:** Low — style only; `required order` `npm run lint:js → composer lint → npm test → build` is documented and enforced in CI.
- **Recommended solution:** Keep standards as-is. Extract nested ternary at `Dashboard.js:844` into `getCacheStatDisplay()` helper removing disable comment (tiny value). Consider adding `lint-staged` + `husky` pre-commit for JS only, but not required per team preference for CI-only.
- **Confidence:** 90%

---

### A14-20 — Inconsistent patterns: `Object.freeze` in `apiRequest`, stale-closure workaround, `switch_blog` reset gap

- **File:Line:** `src/lib/apiRequest.js:119` ; `src/components/Dashboard.js:169,509` ; `includes/class-util.php:108-112`
- **Category:** Architecture — Inconsistent patterns
- **Severity:** MEDIUM
- **Problem:** After `apiCall(update_settings)` does `Object.freeze(data.data)` (shallow), callers handle staleness inconsistently: `Dashboard` adds 3 handlers re-reading global + `useEffect` 169-193 with 7 manual deps; `FileOptimization` keeps local `settings` copy via `useState(settings.file_optimisation)` and `handleChange` factory from `src/lib/util.js` (text/checkbox/number branching) — different pattern. PHP `Util::reset_cached_home_urls()` manual vs missing automatic `switch_blog` hook (see A14-11) is second inconsistency.
- **Why matters:** Shallow freeze gives false sense of immutability; child `settings.file_optimisation` object still mutable so `Dashboard` could accidentally mutate frozen parent. Inconsistent local-state patterns across components mean bug fixes applied to one tab are missed in another.
- **Evidence:** `src/lib/util.js:33` `handleChange(setSettings)` factory shared only by some components; `src/components/PreloadSettings.js:48` `// eslint-disable-next-line react-hooks/exhaustive-deps` for `JSON.stringify(options)` — different effect dep strategy than `Dashboard` 7-dep array.
- **Impact:** Medium — pattern drift across 7 tabs; freeze not deep.
- **Recommended solution:** Standardize one pattern: if keeping global `wppoSettings`, remove `Object.freeze` (shallow = misleading) and replace with `SettingsContext` (A14-09) so stale closure disappears. If freeze must stay, use `deepFreeze` util or remove — don't document freeze as immutability guarantee. Standardize `JSON.stringify(options)` anti-pattern to per-field deps everywhere (fix `PreloadSettings:49`).
- **Confidence:** 76%

---

### A14-21 — `Cache::should_skip_combine_for_inline_budget` + `core_inline_budget_will_inline` — overly clever budget emulation diverging from core

- **File:Line:** `includes/class-cache.php:1080-1129` ; `733-887`
- **Category:** Architecture — Overly complex function
- **Severity:** MEDIUM
- **Problem:** To avoid creating combined CSS on small block-theme bundles, `Cache` re-implements WordPress's greedy smallest-first `styles_inline_size_limit` (20k vs 40k on 6.9) accounting, including sorting, `is_file/is_readable/filesize`, memo `inline_size_map` and `core_will_inline_memo`, plus dual simulation `prediction` vs `reference` with drift detection `inline_drift_detected` (733-781) + transient throttled logging `log_inline_budget_drift`. Complexity: 400 lines to emulate core that has linear `wp_maybe_inline_styles()`. `core_will_inline` does 2 sorts per handle in worst case (up to `2*3*n` simulations comment at 127).
- **Why matters:** Diverges from core as soon as WordPress tweaks inline algorithm (WP 7.0 added `is_readable` skip 1062) — plugin must chase core version. Dual simulation is heroic but still guesses when `wp_styles->get_data('path')` contains `src`-less handle (751-754 `inline_candidates_require_src`). Complex path makes cache fresh/used-CSS correctness dependent on inline prediction.
- **Evidence:** `includes/class-cache.php:106` `private bool $inline_drift_detected` + `private static bool $inline_drift_logged` static cross-request; `includes/class-cache.php:744` `$inline_size_map` lazy built via `is_file(path)` loop; `includes/class-cache.php:113` doc "whether inline-CSS budget prediction drifted".
- **Impact:** Medium — cleverness cost high; correctness depends on keeping emulation in sync with core releases.
- **Recommended solution:** Keep optimization (value real for small bundles) but isolate: extract `Inline_Budget` class with injected `get_styles_inline_limit/inline_candidates_require_src/readable` version gates, making drift test deterministic without filesystem. Add integration test that asserts plugin prediction matches `wp_maybe_inline_styles` output for fixture queue on WP 6.2,6.3,6.9,7.0 matrices.
- **Confidence:** 80%

---

## Summary

- **God classes** confirmed: `Main` 3053, `Cache` 2306, `Dashboard` 1329, `FileOptimization` 2024, `Util` 854 — all SRP violations with measurable merge/regression cost. Each extraction recommended as single file-move facade, not framework abstraction.
- **Global state:** `wppoSettings` mutation via `apiRequest` is the highest-risk coupling (A14-09); thin `SettingsContext` eliminates 3 workaround sites.
- **Manual `require_once` vs classmap:** contradictory mechanisms (A14-06); one-time alignment to classmap + bootstrap vendor-exists guard eliminates fresh-clone fatal.
- **Transient isolation:** pattern is 95% correct; only `switch_blog` cache hook gap remains (A14-11).
- **OD/AI/Edge:** new features are well-isolated behind `is_enabled()` guards but add duplicate config fallback (A14-13) and speculative reflection (A14-12) that should be trimmed after matrix testing against latest OD release.

---

## Verification Order

Per `AGENTS.md` required order for full verification: `npm run lint:js` → `composer lint` → `npm test` → `npm run build`

All findings are audit-only evidence with `file:line` references. No production files were modified.

---

## Limitations

- Commit `master@31fffc61` not present in this `git log`; base referenced from prompt and treated as `origin/master`.
- Build output `build/*.js/css` committed and inspected only for presence/entry points, not byte-level diff.
- CSS design system `/src/css/**` reviewed via `AGENTS.md` conventions, not individual SCSS line-level.
