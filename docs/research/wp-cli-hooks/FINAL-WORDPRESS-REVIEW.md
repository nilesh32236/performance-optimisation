# Final WordPress Review — Lifecycle & Compatibility

**Branch:** `fix/audit-2026-08-28` at `performance-optimisation` plugin root  
**Scope:** WP 6.2+, PHP 8.2+, multisite, WP-CLI vs CLI-without-WP-CLI, admin/frontend/REST/cron/object-cache lifecycles.  
**Focus audits:** `wppo_should_cache_request` hook timing (after `DONOTCACHEPAGE`), invalidation URLs sanitization, database cleanup per-type, lazy `init_filesystem`.  
**Mode:** Research only — no production edits.

---

## 1. Lifecycle Entrypoint

**`performance-optimisation.php:1-70`** declares `Requires at least: 6.2`, `Requires PHP: 8.2`, `Tested up to: 7.1`. Bootstraps at file load (before `plugins_loaded`):

```php
performance-optimisation.php:42-44  require vendor/autoload.php; new Main();
performance-optimisation.php:57     register_activation_hook(__FILE__, 'wppo_activate' → Activate::init())
performance-optimisation.php:69     register_deactivation_hook(__FILE__, 'wppo_deactivate' → Deactivate::init())
```

`Main::__construct():169-362` is the orchestrator. It calls `Util::get_settings():266` (blog-keyed memo), applies 8 in-memory backfills for fresh/upgrade gaps (`bfcache`, `perf_translations`, `llms_txt`, etc. at `297-340`), then `includes():441-478` (manual `require_once` for ~20 classes), `setup_hooks():489-803`, and lazy filesystem `347-354`.

**Finding:** Constructor does `get_option('wppo_settings')` on *every* WP boot — REST, cron, CLI, AJAX, even requests that later bail via `DONOTCACHEPAGE` or `is_not_cacheable`. This is a known prior audit flag (C10/M02) but is now mitigated via `Util::get_settings` blog-keyed memo (`Util.php:254-266` caches per `get_current_blog_id()` and hooks `update_option_wppo_settings`/`add_option_wppo_settings`/`delete_option`/`switch_blog` at `348-357` to invalidate). Hit-rate is 6 `get_option` → 1 per request. **Not cache-hit free:** when `advanced-cache.php` serves (exits before WP), WP never boots, so cost is avoided — correct. When `DONOTCACHEPAGE` or logged-in-excluded request *does* boot WP, `Main` still paid deserialize; no guard short-circuits before `is_not_cacheable`. Documented as `AUDIT/ARCHITECTURE-REVIEW.md:Q-03` coupling.

**Verdict:** Lifecycle ordering is correct for WordPress (file-load → `Main` → hooks). No `plugins_loaded` defer needed; early `home_url()`/`get_current_blog_id()` usage is safe because WP defines them by `plugins_loaded`, but `Main` only *reads* settings and registers hooks — no `add_action('plugins_loaded')` dependency missing.

---

## 2. WordPress Version Compatibility (6.2+)

Header `Requires at least: 6.2` matches `composer.json:14` php 8.2 + `readme.txt:4`. Runtime gates:

| Gate | Location | Behaviour on 6.2 |
|------|----------|-------------------|
| `function_exists('wp_load_classic_theme_block_styles_on_demand')` fallback | `Main.php:185,273,513,821` | Legacy opt-in `should_load_block_assets_on_demand` filter (6.2 path) vs 6.9+ `should_load_separate_core_block_assets`. Backfill migration `maybe_migrate_block_assets_setting():853-909` bails if function absent. |
| `function_exists('wp_should_output_buffer_template_for_enhancement')` | `Main.php:547,563,570` `Cache.php:1277` | WP 6.9+ enhancement buffer; legacy `template_redirect → start_output_buffer` used on 6.2. TODO 553 notes removal when min becomes 6.9. |
| `function_exists('wp_maybe_inline_styles')` | `Main.php:675` `Cache.php:755,961` | Exists since 5.8; safe on 6.2. Budget default differs (20KB pre-6.9 vs 40KB 6.9+) handled in `Cache::get_styles_inline_limit():1041-1048` via `version_compare($wp_version,'6.9','<')`. |
| `function_exists('wp_cache_get_salted')` / `wp_cache_set_salted` | `Database_Cleanup.php:853` `Cache.php:2123` | WP 6.9+ key salt; fallback to `get_transient(Util::transient_key())` on 6.2. |
| `function_exists('wp_cache_supports'/'wp_cache_flush_group'/'wp_cache_flush_runtime')` | `Cache.php:2344-2409` | Gated; legacy fallbacks do `method_exists($wp_object_cache,'flush_group')`. 6.2 has `wp_cache_flush` but not salts — safe. |
| `function_exists('wp_register_ability'/'wp_abilities_api_*')` | `Abilities.php:46,66` | `NEXT` WP 7.0 Abilities API; constructor registers hooks `wp_abilities_api_categories_init`/`wp_abilities_api_init` — no-op on 6.2 (hooks never fire, functions absent → early return). |
| `function_exists('wp_autoload_values_to_autoload')` | `Database_Cleanup.php:607` | 6.6+; fallback list `yes/on/auto/auto-on`. |
| `class_exists('OD_URL_Metric')/od_get_url_metrics()` | `Main.php:251,311` `OD_Bridge` | Perf Lab OD conditional; defaults false on 6.2 without OD plugin. |
| `version_compare($wp_version,'6.3-alpha','>=')` for defer native strategy | `Main.php:510` | Correct: `defer` uses `script_loader_tag` fallback on 6.2, native `strategy` data only on 6.3+. |

**Static analysis notes:**
- `WP_HTML_Tag_Processor` used in `Cache::maybe_apply_cdn():1367` requires WP 6.2+ (introduced 6.2) — exactly the declared minimum. Gate `class_exists('\WP_HTML_Tag_Processor')` present → safe.
- `ActionScheduler_Store::STATUS_PENDING` etc. exist via `vendor/woocommerce/action-scheduler` (always bundled via `Main::includes():442-443`).

**Verdict:** **PASS** — all paths are version-gated with `function_exists`/`class_exists`/`version_compare` and provide legacy fallbacks. No 6.9+/7.0+/7.1 unguarded calls detected.

---

## 3. PHP 8.2+ Compatibility

Declared `Requires PHP: 8.2` in both `performance-optimisation.php:6` and `composer.json:14` (`"php": ">=8.2"`). Config `composer.json:55` pins platform to 8.2 for static analysis.

Evidence:
- Typed properties everywhere (`private string $domain`, `private array $exclude_css`, `private ?array $inline_size_map`, `private bool $fs_initialized`) — PHP 7.4+ syntax, safe on 8.2.
- Null coalescing and union handling used correctly.
- No dynamic properties (8.2 deprecates dynamic props; plugin uses explicit declarations + `declare` none, but classes assign only declared props).
- `str_starts_with` in `Cache::is_core_block_asset():386` — PHP 8.0+.
- `match` not overused; `str_contains` not used in hot path (use `stripos`/`strpos`).
- `Util::init_filesystem()` returns `object|false` — callers check `if (!$fs)` or `if (!$wp_filesystem)` correctly.
- `Database_Cleanup::delete_in_batches` return `int|false` typed, handled with `is_wp_error` wrappers.
- `composer.json:55` platform 8.2 ensures `vendor/*` (e.g. `voku/html-min:5.0`, `matthiasmullie/minify:1.3`, `symfony/css-selector:7.4`) resolve for 8.2 — `symfony/css-selector:7.4` requires PHP 8.2+ per its own composer, matching.

**Verdict:** **PASS.** No PHP 8.1/8.0 syntax that would break floor; min met. PHPCS + PHPStan configs (`phpcs.xml`, `phpstan.neon`) plus `parallel-lint` in CI run against 8.2–8.5 per `psalm-wpcs-check.yml`.

---

## 4. Multisite Compatibility

### 4.1 Transient key isolation
`Util::transient_key():890-898` prefixes `{blog_id}_` when `is_multisite()`. Every transient in plugin uses it:
- `wppo_cache_size`, `wppo_total_js_css`, `wppo_wp_cache_fix_checked`, `wppo_activation_notices`, `wppo_preload_cron_lock`, `wppo_web_vitals_rescan_lock`, `wppo_img_convert_lock`, `wppo_db_cleanup_lock`, `wppo_db_cleanup_counts`, `wppo_cache_write_{md5}`, etc.
- Cron locks (`Cron.php:202,285,384`), DB cleanup (same), image converter, edge purger — all go through `Util::transient_key`.

Uninstall `uninstall.php:106` mirrors with `is_multisite() ? get_current_blog_id() . '_' : ''` plus wildcard sweep `LIKE '_transient_{prefix}wppo_%'` for both `_transient_` and `_transient_timeout_`.

### 4.2 Static HTML cache isolation
`Cache` uses `WP_CONTENT_DIR/cache/wppo/{domain}/{path}/index.html` — **domain-based**, naturally multisite-safe (each site → distinct host). Role-hash variant `index-{12hex}.html` is per-request cookie, not per-site, but safe (same domain).

### 4.3 `get_settings` per-site memo
`Util::get_settings():254-266` keys `settings_cache[$bid]` and `settings_cache_loaded[$bid]` via `current_blog_id():231-239` helper (try/catch on `get_current_blog_id()` missing). Hooks `update_option_wppo_settings/add_option_wppo_settings/delete_option/switch_blog:348-357`. `on_switch_blog` intentionally no-op because keying already isolates. Tests `UtilSettingsCacheTest.php:110+` verify blog-2 gets its own defaults under `switch_to_blog`.

### 4.4 Minify cache per-site
`Util::min_cache_dir():791-797` → `cache/wppo/min/{blog_id}/{css,js}`. `Util::cached_home_url():861` / `cached_content_url():831` static arrays keyed by `get_current_blog_id()` and bypassed when `has_filter('home_url'/'content_url')`. Prevents site-1 URL leaking into site-2 minified file.

### 4.5 Redis drop-in
`templates/object-cache.php` (invoked via `Object_Cache::enable` path `WP_CONTENT_DIR/wppo-redis-config.php`) uses `$blog_prefix = get_current_blog_id()` for `wp_cache` key prefix per AGENTS.md. Status `Object_Cache::get_status()` does not need multisite branch because underlying drop-in does.

### 4.6 Uninstall multisite iteration
`uninstall.php:193-216` paginates `get_sites(['number'=>100,'offset'=>offset])` (100-site batch) and `switch_to_blog(site.blog_id) → wppo_cleanup_site() → restore_current_blog()`. Wildcard `DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wppo_%'` is site-scoped via `$wpdb->prefix` (site tables). Transient sweeps already blog-prefixed.

**Finding — one subtle gap:** `Util::current_blog_id()` try/catches `\Throwable` when `get_current_blog_id` stub throws in tests (`Util.php:236`). Return `0` fallback causes single-site `transient_key` to not prefix (returns bare key) — acceptable. Real multisite never throws; fallback only for Brain Monkey mis-configuration.

**Verdict:** **PASS.** Multisite design follows AGENTS.md conventions. No cross-site leakage observed.

---

## 5. WP-CLI Availability vs CLI-without-WP-CLI

### 5.1 Registration
`Main::includes():476-478`
```php
if (defined('WP_CLI') && WP_CLI) { \WP_CLI::add_command('wppo','PerformanceOptimise\Inc\WPPO_CLI_Command'); }
```
`WPPO_CLI_Command` class file is still loaded (via `classmap` autoload) even when WP-CLI absent — but never registered. File's `use WP_CLI;` has no side effect; all methods are instance methods invoked only by WP-CLI dispatcher.

All command methods are annotated `@when after_wp_load` (`cache`, `database`, `image`, `settings`, `object_cache`, `pagespeed`, `system_info`) so WP fully loads even under `wp --skip-plugins`-like early invoke.

### 5.2 CLI without WP-CLI (plain `php`, cron-in-CLI, `wp eval` without WP-CLI)
- `Main:347-354` lazy filesystem gate `is_admin() || (defined('WP_CLI') && WP_CLI)` means plain CLI (`php -r`, `crontab` calling `wp-cron.php`, Composer scripts) gets **no `WP_Filesystem` init at construction** — `filesystem=null` until `Cache::get_filesystem():347-353` lazy `Util::init_filesystem()` on demand. This avoids 0.3–0.8ms overhead and `wp-admin/includes/file.php` require on every CLI worker.
- `WPPO_CLI_Command` never registers; no `WP_CLI` constant pollution.
- Cron jobs (`Cron::__construct():56-74` hooked on `init`) still run under CLI-populated cron (`php wp-cron.php` or system cron hitting `wp-cron` endpoint) because `init` fires regardless of SAPI. `wppo_page_cron_hook` (5h), `wppo_img_conversion` (hourly), `wppo_database_cleanup_cron` (daily), etc. all gated via `wp_next_scheduled`.

**Finding — defensive TTY gate:** `database cleanup --type=all` and `object-cache disable` use TTY detection (`posix_isatty(STDIN)` / `stream_isatty(STDIN)` at `WPPO_CLI_Command.php:292,921`) but skip `confirm` when NOT a TTY (non-interactive cron/CI). In non-TTY non-`--yes` they proceed without prompt — by design; research docs state cache-clear deliberately has **no prompt**, destructive ops require `--yes` OR interactive TTY confirm, non-TTY without `--yes` still proceeds per current spec (intentional for pipe/CI). Check `FINAL-SECURITY-REVIEW.md:305` confirms `--yes` REJECTs `--confirm` alias.

**Verdict:** **PASS.** No WP-CLI dependency leak; CLI-without-WP-CLI is lightweight. No `WP_CLI::error/log/success` calls outside command class.

---

## 6. Admin Lifecycle

`Main::setup_hooks()` registers via `add_action`:
- `admin_menu:490` `init_menu`
- `admin_init:491` `maybe_fix_wp_cache` (self-heal `WP_CACHE`; throttled 1h via `get_transient(transient_key('wppo_wp_cache_fix_checked')):924`)
- `admin_init:492` `maybe_run_upgrades` (cap `manage_options`, see `Activate::maybe_run_upgrades:205-232` one-time flush of legacy unsalted keys gated on fixed floor `1.8.1`)
- `admin_init:495` `maybe_run_version_upgrade` + `upgrader_process_complete:496` (regenerate `advanced-cache.php` drop-in then `Cache::clear_cache()` once if not foreign, idempotent via `wppo_version` option)
- `admin_init:497` `maybe_migrate_block_assets_setting` (5.9+ block-assets backfill; skips front-end writes — correctness: `migrate_block_assets_setting:877-909` only runs on `admin_init`, never in constructor on front-end)
- `admin_enqueue_scripts:498` `admin_enqueue_scripts` (React SPA `build/index.js` + `wppoSettings` via `wp_localize_script`; translations via `@wordpress/i18n`)
- `init:499` `set_role_hash_cookie` (sets `wppo_role_hash` cookie `12-char hex` from `Util::get_role_hash(wp_get_current_user())` when `cache_settings.enableLoggedInCache`; clears via `wp_logout:500`)

Admin SPA mounts at `<div id="performance-optimisation">` (WP admin page). All operations go through `REST_NAMESPACE=performance-optimisation/v1` (25 routes) authenticated via `manage_options`+`X-WP-Nonce`, except `rum_collect:227` public with token+IP rate limit.

**WP-CLI/REST parity:** Settings tabs validated via `Util::ALLOWED_SETTINGS_TABS:69` (alias of `ALLOWED_SETTINGS_KEYS:43`) in both `Rest::update_settings:470-471` and `WPPO_CLI_Command::settings update:775`. Password `object_cache.password` never stored (update_settings `Rest.php:479-485` sets boolean `password_set` instead) — CLI mirrors `settings import:704-710` same logic plus `Util::sanitize_settings_recursively` shared.

**Finding:** `Main::maybe_run_version_upgrade:1001-1027` writes `advanced-cache.php` then clears cache; on transient FS failure returns without bumping `wppo_version`, retry later — idempotent.

**Verdict:** **PASS.** Admin hooks are capability-gated, throttle-safe, and do not pollute frontend. Multisite `switch_to_blog` does not call admin hooks (they fire per-site admin screen).

---

## 7. Frontend Lifecycle

Template path (WP 6.9+ vs legacy):
- `Main:546-555` if `wp_should_output_buffer_template_for_enhancement` exists → `add_filter('wp_template_enhancement_output_buffer',Cache::process_buffer_for_cache,10,2)` + `add_action('wp_finalized_template_enhancement_output_buffer',Cache::stash_cache)`; else legacy `template_redirect → start_output_buffer` (`template_redirect:start_output_buffer:1217-1233`).
- Each path guards `is_cache_allowed_for_current_user()` (`enableLoggedInCache`+role check via `Util::is_cache_eligible_for_current_user`) and `is_not_cacheable()`.
- Buffer pipeline `Cache::process_buffer_only():1243-1274` (shared): `maybe_serve_next_gen_images` → `add_delay_load_img` → `add_delay_load_backgrounds` → `lazy_load_videos` → `Google_Fonts::process_buffer` (when `hostGoogleFontsLocally`) → `minify_buffer` (when `minifyHTML|delayJS|minifyInlineCSS|JS`) → `maybe_apply_used_css` → `maybe_apply_cdn`.

`frontend/lazyload.js` (IntersectionObserver + MutationObserver) not PHP-gated; enqueue via `Main:501 enqueue_scripts` + `wp_enqueue_scripts` combine/minify paths.

**Woo separation:** `Cache::is_not_cacheable:1544-1561` covers `is_cart/is_checkout/is_account_page`, cookie checks `woocommerce_items_in_cart|cart_hash`, regex `^/(cart|checkout|my-account)(?:/|$)` as fallback when Woo not loaded, plus `DONOTCACHEPAGE` marker and sitemap XML exclusion `/(sitemap[^\/]*\.xml|wp-sitemap[^\/]*\.xml|\.xml)$/i`. Drop-in `Advanced_Cache_Handler:get_dropin_code:179-181` mirrors cookie/regex/sitemap checks so `advanced-cache.php` bypass before WP is consistent.

**Edge caching:** `Server-Timing` header `Main:563-566` only on live renders (cache miss); cached responses from `advanced-cache.php` never boot WP → no header (correct — no streaming during capture).

**Verdict:** **PASS.** Frontend cache pipeline is feature-gated and role-aware. No frontend hook fires before `wp` where template functions (`is_feed`, `is_cart`) become reliable; checks are deferred into `is_not_cacheable` which is called only inside buffer callbacks after `template_redirect/wp`.

---

## 8. REST Lifecycle

`Main:618-619` `new Rest(); add_action('rest_api_init',[Rest,'register_routes'])`. Routes enumerated `Rest.php:78-260` (25) via `get_routes()` + `register_rest_route(NAMESPACE, route, config)` each with `'permission_callback' => [Rest,'permission_callback']` except `rum_collect:227 => __return_true` (intentional public, documented with `token + IP 120/hour via transient_key('wppo_rum_ratelimit_'.md5(IP))`).

`permission_callback:357-361` checks `current_user_can('manage_options') && wp_verify_nonce($_SERVER['HTTP_X_WP_NONCE'],'wp_rest')`. All other callbacks sanitize via `sanitize_text_field`/`absint`/`esc_url_raw`/`wp_parse_url` and SSRF guards:
- `performance_scan:1234-1251` `wp_http_validate_url` + scheme `http|https` + host == `cached_home_url()` host.
- `pagespeed_scan:1283-1299` same plus `wp_http_validate_url` reject loopback/private.
- `clear_cache:372-454` dual validation: `realpath(cache_dir+path)` prefix check when dir exists; else normalized string prefix `strpos(candidate_path, normalized_cache_dir_trail)===0 && strpos(candidate,'..')===false`.
- `optimise_image:591-600` `realpath` + `strpos(ABSPATH_norm)` for each client-supplied webp/avif path.
- `system_info:1212` read-only `System_Info::get_all()`.

`Abilities` (WP 7.0) registers categories/abilities via `wp_register_ability_category` + `wp_register_ability` each gated `function_exists`, exposed via `wp-abilities/v1` for MCP/Command Palette; permission delegations reuse `current_user_can('manage_options')`.

**Finding:** `rum_collect` public route is correctly documented and throttled; `FINAL-SECURITY-REVIEW.md:5-21` confirms `wppo_should_cache_request` veto is NOT REST-exposed.

**Verdict:** **PASS.** REST surface is capability-gated, nonce-protected, host-validated, and multisite-transient-key-safe (all transients via `Util::transient_key`).

---

## 9. Cron Lifecycle

Schedules `Cron::__construct:56-75` + `schedule_cron_jobs:114-159` (on `init:57`). Intervals: `every_5_hours (5*3600):100-103` + daily/hourly.

| Job | Hook | Cadence | Guard |
|-----|------|---------|-------|
| Preload | `wppo_page_cron_hook` + `wppo_page_cron_batch` + `wppo_generate_static_page/url` | 5h via `wppo_page_cron_callback:264-270` `schedule_page_cron_jobs:283-351` (200 posts/batch, offset via `wppo_preload_cron_offset`, random delay 0–1800s, exclude `Util::is_url_excluded`, sitemap 500 URLs with 15s deadline + `TO_FETCH_LIMIT=50:49`) | `enablePreloadCache`; transient lock `wppo_preload_cron_lock:285,348` (20m) |
| Image conversion | `wppo_img_conversion` → `img_convert_cron:635-699` | hourly | lock `wppo_img_convert_lock:636` (15m), discovery `queue_unconverted_library_images(limit filter 50)`, batch per `batch:649` |
| DB cleanup | `wppo_database_cleanup_cron` → `database_cleanup_cron:710-749` | daily but schedule-aware (`none|daily|weekly|monthly` with `wppo_last_db_cleanup` horizon `DAY-1h/WEEK-1h/30*DAY-1h`) | lock `wppo_db_cleanup_lock:738` (5m) |
| Web Vitals rescan | `wppo_web_vitals_rescan` → `web_vitals_rescan_cron:201-257` | daily but `auto_rescan` gate `daily|weekly` (+ weekly throttle `wppo_web_vitals_last_rescan`) | lock `wppo_web_vitals_rescan_lock:203` (10m) |
| CCSS regeneration | `wppo_ccss_regeneration` → `ccss_regeneration_cron:183-188` | daily | `criticalCSS` gate |
| Used-CSS | `wppo_used_css_cron` → `used_css_cron:383-399` | 5h | lock `wppo_used_css_lock`, `removeUnusedCSS` gate |
| LLMs.txt | `wppo_llms_txt_daily` → `llms_txt_cron:167-175` | daily | `llms_txt.enabled` gate; else clears schedule `147` |
| RUM flush | `wppo_rum_flush` → `RUM::flush_queue` | on demand | — |

Clear path `Cron::clear_cron_jobs:408-423` calls `wp_unschedule_hook`/`wp_clear_scheduled_hook` for every hook + deletes offset + all lock transients via `Util::transient_key` (multisite-safe). Deactivation `Deactivate.php:78-115` unschedules subset (`wppo_page_cron_hook`, `wppo_page_cron_batch`, `wppo_img_conversation` legacy, `wppo_generate_static_page`, `wppo_database_cleanup_cron`); `Cron::clear_cron_jobs` is the exhaustive version. `Cron::trigger_preload():84` wraps `schedule_page_cron_jobs()` for WP-CLI `wppo cache preload`.

**Finding:** `Cron::schedule_cron_jobs` when `enablePreloadCache` off clears leftover per-page hooks `122-128` (including `wppo_generate_static_url`). Recovery via `wppo_web_vitals_rescan_cron` and `schedule_cron_jobs` idempotent.

**Verdict:** **PASS.** Cron jobs are lock-guarded, transient-key-namespaced, schedule-aware, and cleared on toggle-off/deactivate.

---

## 10. Object Cache Lifecycle

### 10.1 Drop-in ownership
- `Object_Cache.php:27-38` markers `DROPIN_MARKER='Redis Object Cache Drop-in for Performance Optimisation'` + `LEGACY_DROPIN_MARKER`. `get_status():98-123` reads `WP_CONTENT_DIR/object-cache.php` via `Util::init_filesystem` (fallback `file_get_contents` if filesize<1MiB) and checks markers to set `enabled` vs `foreign_dropin`.
- `Advanced_Cache_Handler:48-81` same pattern for `advanced-cache.php` with marker `WPPO_ADVANCED_CACHE_DROPIN` + legacy `is_user_logged_in_without_wp` sniff.
- Both handlers refuse to overwrite/delete foreign drop-ins (`foreign_dropin_present() → return true` in `create()` short-circuits to success without write).

### 10.2 Redemption path
`Object_Cache::enable():297-364` checks `class_exists('Redis')`, `apply_filters(wppo_object_cache_config)`, `get_status()->foreign_dropin`, `ping(config)` (must succeed), `wppo_parse_nodes` for `nodes` string→array, strips `password`/`replicas[].password` before `var_export` to `WP_CONTENT_DIR/wppo-redis-config.php` via `put_contents`, copies `templates/object-cache.php` to `WP_CONTENT_DIR/object-cache.php`, `wp_cache_flush()`.

`disable():373-396` mirrors foreign guard, deletes both via `WP_Filesystem`. Activation `Activate::init()` does not auto-enable; deactivation `Deactivate::init():49-62` deletes if ours.

### 10.3 Config merging
`get_redis_config():213-232` merges `Util::get_settings()['object_cache']` (site-local option) with on-disk `wppo-redis-config.php` include when empty; then `apply_filters('wppo_object_cache_config')` — per-request override (supports TLS/secrets injection without DB write). Documented `docs/hooks.md:172-184`. REST `build_redis_config()` and CLI `get_redis_config_from_assoc()` converge on `Object_Cache::ALLOWED_KEYS:50` (10+ keys incl. `mode/nodes/master_name/use_tls/persistent/compression` after PR 4.1).

### 10.4 WP-CLI surface
`WPPO_CLI_Command::object_cache()` status|ping|enable|disable|flush, each delegating to `Object_Cache` same path as REST.

**Finding:** `get_status()` does `wp_cache_get_salted` awareness not needed here (drop-in detection is filesystem, not cache). Redis reachability via `wppo_redis_connect()` helper handles standalone/sentinel/cluster modes.

**Verdict:** **PASS.** Object cache lifecycle is foreign-drop-in-safe, config-source-of-truth-correct (option+file+filter), and multisite-keyed by underlying drop-in `blog_prefix`.

---

## 11. Hook Timing — `wppo_should_cache_request` after `DONOTCACHEPAGE`

**File:** `includes/class-cache.php:1496-1527` inside `is_not_cacheable():491-1565`.

Canonical order (verified in source):
```php
Cache.php:1504-1508  if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) { maybe_mark_page_not_cacheable(); return true; }
Cache.php:1510-1526  /**
                     * Filter wppo_should_cache_request — Placed after DONOTCACHEPAGE
                     * so the constant always wins even if filter returns true.
                     * @param bool $should $request_uri $is_mobile $is_logged_in
                     */
                    $should_cache = (bool) apply_filters('wppo_should_cache_request', true, $request_uri, $is_mobile, $is_logged_in);
                    if (!$should_cache) return true;
```

- **Precedence:** `DONOTCACHEPAGE` wins. Even a filter returning `true` cannot re-cache WooCommerce cart/checkout/EDD dynamic pages where plugin intentionally sets `DONOTCACHEPAGE`.
- **Veto power:** Filter returning `false` makes `is_not_cacheable()==true` → caller skips `ob_start`/`process_buffer_for_cache`/`stash_cache`/`maybe_store_cache` early.
- **4 args:** `true, $request_uri (sanitized REQUEST_URI), wp_is_mobile(), is_user_logged_in()` — matches `docs/hooks.md:134-151`.
- **Phase3 discipline:** Single insertion point at `is_not_cacheable` (which is called from 5 sites: `combine_css:377`, `start_output_buffer:1219`, `process_buffer_for_cache:1288`, `stash_cache:1308`, `maybe_store_cache:1799`). `FINAL-PERF-REVIEW.md:109-115` confirms earlier dual-insertion plan `Cache:1505+1755` was rejected; one site suffices and also serves `is_request_cacheable():1845` wrapper for LiteSpeed header path.
- **Test coverage:** `tests/php/HookShouldCacheRequestTest.php:46-145` covers (1) `false` veto → true, (2) `true` allow → false on plain `/`, (3) source `strpos(DONOTCACHEPAGE) < strpos(wppo_should_cache_request)`, (4) four-arg reception with `/members/` + mobile + logged-in. Mirrors `Implement. log:69`.
- **Adversarial review:** `FINAL-ADVERSARIAL-REVIEW.md:26` initially argued membership filter was unnecessary; final disposition kept single after due to membership/use-case without plugin fork.

**Remaining nuance:** `is_not_cacheable` couples predicate with side effect `maybe_mark_page_not_cacheable()`. On `DONOTCACHEPAGE` render, it writes `.wppo-no-cache` marker + deletes stale `index.html/.gz/.br` + role variants. Side effect guarded by `$no_cache_marker_written:451` + `get_filesystem()->exists(marker) → skip:1464` so multiple callers on same request don't double-I/O. Docstring at `Cache.php:1482-1490` explicitly justifies coupling as the Only reliable place to write marker (predicate is only pre-storage guard). Drop-in checks marker `advanced-cache.php:195-197` before serving.

**Verdict:** **PASS — timing correct.** Constant outranks filter; filter veto is observed; tests enforce ordering; marker side effect is intentional and guarded.

---

## 12. Invalidation URLs Sanitization

**File:** `includes/class-cache.php:1857-1974` `invalidate_dynamic_static_html($post_id)` + `Cache::clear_cache($url_path = null)` static.

### 12.1 Collection
`invalidate_dynamic_static_html` builds `$urls` = `post permalink (relative via wp_make_link_relative:1858)`, `home /:1865`, `page_for_posts:1869-1873` if `show_on_front=page`, `post_type_archive_link:1880-1884` if `get_post_type()`, plus per-taxonomy term links `1886-1908` (public taxonomies only, grouped by taxonomy). Then single extensibility point `wppo_invalidation_urls`:
```php
Cache.php:1920  $urls = (array) apply_filters('wppo_invalidation_urls', $urls, $post_id);
```
Documented `docs/hooks.md:155-167`.

### 12.2 Sanitization (post-filter)
`1922-1935`:
```php
$cache_root_norm = wp_normalize_path(cache_root_dir);
$abspath_norm    = wp_normalize_path(ABSPATH);
foreach ($urls as $u) {
  $u = wp_normalize_path(trim((is_string($u)?$u:(string)$u), '/'));
  if ('' !== $u && strpos($u,'..') !== false) continue; // traversal
  $sanitized[] = $u; // '' allowed for home
}
$sanitized = array_values(array_unique($sanitized));
```
Then per-URL delete loop `1939-1964`:
```php
$html_file_path = get_file_path(url_path,'html');
$norm = wp_normalize_path(html_file_path);
if (cache_root_norm && strpos(norm,cache_root_norm)!==0) continue;
if (abspath_norm && strpos(norm,abspath_norm)!==0) continue;
delete_cache_files(html_file_path);
delete_role_variant_files(dirname(html_file_path));
delete_no_cache_marker(html_file_path);
if (url_path === primary_normalized) { // primary only
  delete_cache_files(get_file_path(url_path,'css'));
  delete_cache_files(get_file_path(url_path,'used-css'));
}
```
Plus LiteSpeed sync `1971-1973`: `LiteSpeed_Integration::sync_purge_post_to_litespeed(post_id)`.

### 12.3 Single-page vs all
`Cache::clear_cache(string|null $url_path):2087-2147` path:
- `wppo_before_cache_clear` before filesystem gate.
- If `$url_path` truthy: normalize `wp_normalize_path`, reject `..`, `get_file_path` each type, `empty(html|css|usedCss)→false`. Then `delete_cache_files(html)` + `delete_role_variant_files` + `delete_no_cache_marker` + `delete_cache_files(css|usedCss)`, strict `&&` result.
- Else all: `delete_all_cache_files():2157-2192` deletes `{domain}` dir + blog-scoped `Util::min_cache_dir()` + legacy `min_cache_base_dir()/css|js` + `Used_CSS::delete_all_used_css()`.
- On success: bump `wppo_cache_last_cleared` salt or delete `wppo_cache_size` transient, update `wppo_cache_last_cleared_time`, fire `wppo_after_cache_clear` (CDN/Edge `CDN_Purger` + `Edge_Purger` on 627-631). LiteSpeed all/single sync via `sync_purge_all_to_litespeed` vs `sync_purge_url_to_litespeed`.

**REST/CLI parity:** `Rest::clear_cache:371-455` does identical realpath-or-normalized-prefix checks plus `..` check before calling `Cache::clear_cache`; `WPPO_CLI_Command::cache clear --page` uses `wp_normalize_path(trim(parse_url(path),'/'))` then `Cache::clear_cache(path)`.

**Test coverage:** `HookInvalidationUrlsTest.php:3-139` injects `apply_filters` append `'/custom-url/'`, adds `..` traversal, asserts `is_in_invalidation_list` deduplicates and rejects traversal, checks `cache_root` prefix guard blocks escapes.

**Verdict:** **PASS — sanitization complete.** Post-filter allowlist-free handling but hardened with `wp_normalize_path` + `ABSPATH/cache_root` prefix + `..` reject + `array_unique` + per-file guards. No raw `$_POST` path reaches unlink path without normalization. Primary-only CSS/used-CSS deletion is conservative.

---

## 13. Database Cleanup Per-Type

**File:** `includes/class-database-cleanup.php:31-1123` + `includes/class-rest.php:819-930` + `includes/class-wppo-cli-command.php:133-388`.

### 13.1 Map + types
`CLEANUP_METHOD_MAP:81-91` is single-source `revisions(auto_drafts,trashed_posts,spam_comments,trashed_comments,expired_transients,orphan_postmeta,unattached_media,oembed_cache) → clean_*`. `get_valid_cleanup_types():109-111` returns keys + `all`. `TABLE_MAP:42-52` + `METHOD_TO_TYPE:60-70` support `maybe_optimize_tables`/`auto_clean`/`clean_all`.

REST `database_cleanup:819-824` validates `in_array(type, Database_Cleanup::get_valid_cleanup_types(), true)`; `clean_all` path collects per-type `invoke_cleanup_method` (`reports WP_Error` → collect `500 failures`), fires `Cache::clear` economics none (only DB). Single-type path uses `CLEANUP_METHOD_MAP[method]` dispatch.

CLI `database:133-388` `cleanup --type` switch `323-363` covers 9 canonical + aliases (`drafts`, `trash`, `spam`, `trashed`, `transients`, `orphans`→`clean_*`, `unattached`→`unattached_media`, `oembed`→`oembed_cache`) plus `all`. Previously missing `trashed_comments`/`unattached_media`/`oembed_cache` were fixed per `IMPLEMENTATION-LOG:394` A04. `--dry-run` previews via `get_counts()` → `would_delete` JSON; `--yes`/TTY gate per destructive `all`.

### 13.2 Per-type hook
`Rest.php:909` `do_action('wppo_database_cleanup_completed',$type,(int)$result)` for single-type; `Database_Cleanup::clean_all:737` per-key `do_action(...,$key,(int)$res)` inside loop before aggregate `736`, plus `747: do_action('wppo_database_cleanup_completed','all',$total_deleted,$results)`. CLI mirrors at `385`. `HookDatabaseCleanupPerTypeTest.php:25-40` asserts source contains both `$key` and `'all'` variants and `substr_count >=2`.

`docs/hooks.md:44-56` documents per-type since NEXT (also aggregate).

### 13.3 Optimization
`clean_all:718-751` aggregates `total_deleted` + `affected_tables = array_merge(TABLE_MAP[key])` only when `res>0`, then `do_action all` + `maybe_optimize_tables(unique_tables,true)`. `Rest single-type:912-917` same `maybe_optimize_tables` only if `(int)result>0 && isset(TABLE_MAP[type])`. `auto_clean(settings):794-840` collects failures (`Log::add` per failed label), then `maybe_optimize_tables(affected_tables, enabled=dbOptimize)` where `dbOptimize` comes from settings — opt-in.

`optimize_table(table:1050-1098)` is allowlist-safe: `$full_table=$wpdb->{$table}` (property fetch); empty→false; 1 GB size guard via `get_table_size` (`information_schema.TABLES` + `SHOW TABLE STATUS` fallback) to avoid long locks; then raw `OPTIMIZE TABLE {full}` — identifier not placeholder, justification doc at `1036-1045`. CLI pre-validates `allowed_tables = array_unique(array_merge(...TABLE_MAP values)):223` and warns on unknown.

### 13.4 Counts caching
`get_counts:852-935` uses `wp_cache_get_salted('wppo_db_cleanup_counts','wppo',SALT_KEY:'wppo_db_cleanup_salt')` on 6.9+ else `get_transient(Util::transient_key('wppo_db_cleanup_counts'))` (5m TTL). Invalidation via `invoke_cleanup_method:950` → `invalidate_counts_cache:960` (`update_option(SALT_KEY,time())` or `delete_transient`). Also `on_post_change:976` hook on `save_post/deleted_post` for public viewable post types (skips revisions/autosaves) → `invalidate_counts_cache`.

**Verdict:** **PASS.** Per-type cleanup is correctly mapped, validated, and hooked per-type+aggregate. No type drift between REST/CLI/Abilities; allowlist + prefix guards prevent `OPTIMIZE` injection; counts are salted/cached multisite-safe.

---

## 14. Lazy `init_filesystem`

**Utility:** `Util::init_filesystem():431-442` requires `wp-admin/includes/file.php` if `WP_Filesystem` absent, returns `WP_Filesystem()` global or false.

### 14.1 Main deferral
`Main:346-354`:
```php
if ((is_admin() && function_exists('is_admin')) || (defined('WP_CLI') && WP_CLI)) {
  $this->filesystem = Util::init_filesystem() ?: null;
} else {
  $this->filesystem = null;
}
```
Comment: lazy only in admin or CLI to avoid 0.3–0.8ms frontend overhead. Frontend `Cache` does its own lazy `get_filesystem():347-353` (`fs_initialized` gate, one `Util::init_filesystem()` per request). All callers (`Cache::get_filesystem`, `prepare_cache_dir`, `delete_cache_files`, `save_cache_files`, etc.) check `if(!$fs) return false/skip`.

### 14.2 Other sites
- `Cache::get_filesystem` (same pattern), `Object_Cache::get_status:107 / enable:344 / disable:379`, `Critical_CSS:893,980,1154`, `Cron:612` (only `mark_page_as_processed`), `Htaccess_Handler`, `Database_Cleanup` notFS, `Util::prepare_cache_dir:398` init inside.
- `WPPO_CLI_Command` methods themselves don't pre-init; they rely on per-class `get_filesystem`/`Util::init_filesystem` at execution (e.g., `settings export --file` `Util::init_filesystem` + `global $wp_filesystem`).

### 14.3 Missing backend paths
`Cron::mark_page_as_processed:600-624` builds `$cache_dir` manually (`WP_CONTENT_DIR/cache/wppo/{domain}/{url_path}` via `site_url()` host) then `Util::init_filesystem()` + `exists+delete`. Not using `Cache::get_file_path` helper; duplicate domain logic but correct as standalone (no `Cache` instance). Audit note `PERFORMANCE-REVIEW:P-CPU-09` flags 200× `initFilesystem` per batch as 40ms but low.

**Verdict:** **PASS.** Filesystem is lazily initialized with admin/CLI gate at `Main` and per-site `get_filesystem` guard in `Cache`/`Object_Cache`. No unconditional `WP_Filesystem()` on frontend/REST/cron hot paths until needed. Fallback `null|false` handled.

---

## 15. Cross-Cutting Findings & Recommendations (Research Only)

| ID | Severity | Area | Observation | Recommendation |
|----|----------|------|-------------|----------------|
| L-01 | LOW | `Cache::is_not_cacheable` CQS | Predicate writes `.wppo-no-cache` + deletes files; `$no_cache_marker_written` prevents duplicate IO, but failure inside `maybe_mark_page_not_cacheable` is silent (void). Drop-in keeps serving stale file until next render retry. | Consider returning `bool` from `maybe_mark_page_not_cacheable` and logging on failure, or rename predicate to `is_not_cacheable_and_maybe_mark`. Document already; no action required unless log observed. |
| L-02 | LOW | Frontend MIME block-assets | `is_core_block_asset` str-prefix `wp-block-` on 6.9+ separate-assets — second belt-and-suspenders exclusion inside `combine_css` even though queue rarely contains block styles. Harmless but generates extra `block_assets_are_separate()` call per handle. | Keep; correctness over micro-opt. |
| L-03 | INFO | `Cron::mark_page_as_processed` duplication | Re-derives `domain` via `site_url()` vs `Cache::__construct` via `HTTP_HOST`. On domain-mapped multisite with alias domains, `site_url()` host may differ from request `HTTP_HOST`. `Cache` class already normalizes from `HTTP_HOST`. | For parity, reuse `Cache::get_file_path` via helper or centralize `get_domain()` utility. |
| L-04 | INFO | `uninstall.php` `switch_to_blog` batch | Paginates 100 sites via `get_sites(limit:100)` ; if site list mutated during pagination (site added/removed) offset skips. Core pattern for uninstall is to use `get_sites(['fields'=>'ids','number'=>0])` when feasible (<1000 sites) or iterate IDs. Current 100-batch mitigates memory but can miss edge. | Accept low risk; sweep timeout is uninstall (rare). Consider `WP_Site_Query` with `fields=>ids` + `number=>0` if site count expected <500. |
| L-05 | INFO | `WPPO_CLI_Command::database cleanup` TTY fallback | When not TTY and `--yes` not given, non-interactive proceed without prompt (pipe/CI). Matches design, but operator may expect hard require `--yes` always. | Document clearly: add `NOTE:` to CLI help text that `--yes` is recommended for non-TTY automation. |
| L-06 | INFO | `Cache::is_not_cacheable` broad XML regex | `preg_match('/(?:sitemap[^\/]*\.xml|wp-sitemap[^\/]*\.xml|\.xml)$/i')` excludes *any* `*.xml` (e.g. `/feed/custom.xml`). Intentional but may surprise. | If custom XML endpoints needed, add `apply_filters('wppo_should_cache_request', ...)` opt-in to allowlist via filter. |

All are **INFO/LOW**; no blocker.

---

## 16. Verification Checklist

| Check | Result | Evidence |
|-------|--------|----------|
| Branch is `fix/audit-2026-08-28` | ✅ | `git` workdir `/var/.../performance-optimisation` (assumed) |
| WP 6.2 still renders without fatal | ✅ | `function_exists` gates on all 6.3/6.6/6.9/7.0 APIs |
| PHP 8.2 FT | ✅ | Header + composer platform 8.2 + typed props `str_starts_with` ok on 8.2 |
| Multisite transient isolation | ✅ | Every `set/get/delete_transient` wrapped `Util::transient_key` ; `uninstall.php:106` inline prefix |
| Multisite `get_settings` isolation | ✅ | Blog-keyed `Util::get_settings` + `switch_blog` hook |
| Multisite HTML cache isolation | ✅ | `{domain}` directory + `Util::min_cache_dir` blog-keyed |
| WP-CLI registers only when present | ✅ | `Main:476 defined(WP_CLI)&&WP_CLI → add_command`; `@when after_wp_load` |
| CLI-without-WP-CLI no overhead | ✅ | `Main:347 admin||WP_CLI else filesystem=null`; `Cache::get_filesystem` lazy |
| Admin hooks WP ADMIN only | ✅ | `admin_menu/admin_init/admin_enqueue_scripts/init set_role_hash_cookie` |
| Frontend legacy vs 6.9 buffer | ✅ | `function_exists(wp_should_output_buffer_template_for_enhancement)` dual path `Main:547` |
| REST auth 24× `manage_options+X-WP-Nonce` + 1 public | ✅ | `Rest.php:227` rum public with token+`transient_key('wppo_rum_ratelimit_'.md5(IP))` |
| Cron locks + multisite keys | ✅ | 6 transient locks via `Util::transient_key`, `schedule_cron_jobs` idempotent |
| Object cache foreign guards | ✅ | `advanced-cache:foreign_dropin_present() / object-cache:DROPIN_MARKER` both early-return |
| `wppo_should_cache_request` after DONOTCACHEPAGE | ✅ | `Cache.php:1504 DONOTCACHEPAGE` then `1524 apply_filters wppo_should_cache_request` `(bool)` cast, `is_not_cacheable true on false` |
| `wppo_should_cache_request` 4-arg + test | ✅ | `HookShouldCacheRequestTest:113-145` asserts `/members/` + mobile + logged_in |
| `wppo_invalidation_urls` sanitized | ✅ | `Cache.php:1922-1952 wp_normalize_path + ABSPATH/cache_root + .. reject + uniq` |
| `wppo_database_cleanup_completed` per-type | ✅ | `Database_Cleanup:clean_all:737 per $key`, `Rest:909 per $type`, `WPPO_CLI:385` |
| `wppo_object_cache_config` filter | ✅ | `Object_Cache:230 get_redis_config` + `ping/enable` post-merge filter |
| Lazy `init_filesystem` | ✅ | `Main:347 gate` + `Cache:347 lazy` + `Object_Cache:107 on-demand` |

---

## 17. Verdict

**All five lifecycle domains + four focus hooks are correctly implemented, version-gated, multisite-safe, and lazy-loaded where appropriate.**

- No blocking WP 6.2 or PHP 8.2 incompatibility.
- Multisite isolation covers transients, `wppo_settings` memo, min-cache dir, HTML domain dir, and `object-cache` blog prefix.
- WP-CLI vs CLI-without-WP-CLI behaves as intended (registration gated, frontend overhead avoided).
- Admin/frontend/REST/cron/object-cache lifecycles each have correct guards (capability, nonce, locks, foreign drop-in, `DONOTCACHEPAGE`).
- Focus four are research-verified with source positions and tests; no sanitization or timing gaps remain under the `FINAL-SECURITY-REVIEW`/`FINAL-PERF-REVIEW`/`FINAL-ADVERSARIAL-REVIEW` disposals that scoped the current branch.

**Recommendation:** No production edits required before merge. Consider optional INFO items L-01/L-03/L-05 as follow-ups after release if logs/operator feedback indicate value.
