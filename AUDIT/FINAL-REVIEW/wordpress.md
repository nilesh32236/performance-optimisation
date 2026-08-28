# FINAL WORDPRESS LIFECYCLE REVIEW — Post-Fix Verification

**Reviewer:** Final WordPress Agent (independent)
**Date:** 2026-08-28
**Workspace:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation`
**Branch:** `fix/deep-dashboard-2026` (`9ce35209` — diff `origin/master c127b865..HEAD` + `git diff HEAD` 46 files unstaged)
**Method:** Independent `Read` of every changed file (`includes/class-util.php`, `class-cron.php`, `class-rum.php`, `class-critical-css.php`, `class-cache.php`, `class-main.php`, `class-rest.php`, `class-database-cleanup.php`, `class-image-optimisation.php`, `class-advanced-cache-handler.php`, `class-google-fonts.php`, `class-wppo-cli-command.php`, `uninstall.php`, `performance-optimisation.php`, `templates/object-cache.php`, `src/components/*`), `grep -rn` for `get_option.*wppo_settings`, `transient_key`, `wp_schedule`, `get_current_blog_id`, `switch_to_blog`, `is_multisite`, `permission_callback`, `register_activation`, manual lifecycle trace. No trust in implementor claims.

---

## 1. Scope

| Lifecycle | Files inspected | Change surface |
|-----------|----------------|----------------|
| **Multisite** | `includes/class-util.php:676`, `includes/class-cache.php:1611`, `includes/class-rum.php:252`, `includes/class-critical-css.php:156`, `includes/class-advanced-cache-handler.php:141`, `includes/class-main.php`, `uninstall.php:179`, `templates/object-cache.php:69` | `transient_key`, `min_cache_dir`, `CCSS hash`, `COOKIEHASH`, cache dirs, option table |
| **Object-cache** | `templates/object-cache.php:69`, `includes/class-object-cache.php`, `includes/class-util.php:676`, `includes/class-cache.php:1611`, `includes/class-rum.php:329` | Redis drop-in `blog_prefix` vs `transient_key` per-blog isolation, atomic writes, transient locks |
| **Cron** | `includes/class-cron.php:56-409`, `includes/class-deactivate.php:50` | `cron_schedules` filter, 7 recurring hooks + `wppo_rum_flush` single event, `schedule_cron_jobs` on `init`, `clear_cron_jobs`, deactivation unschedule |
| **REST** | `includes/class-rest.php:80-227`, `includes/class-rum.php:100` | 25 routes, `permission_callback`, `X-WP-Nonce` + `manage_options`, `rum_collect` public token+rate-limit |
| **Admin** | `includes/class-main.php:418-1736`, `includes/class-cache.php` | `admin_menu`, `admin_init`, `admin_enqueue_scripts`, `admin_bar_menu` capability gate |
| **Frontend** | `includes/class-main.php:482-551`, `includes/class-cache.php:1569`, `includes/class-image-optimisation.php:117`, `src/lazyload.js` | `template_redirect` output buffers (cache, used-CSS, LCP), `wp_enqueue_scripts`, CDN/minify bypass, `wppoRum` config |
| **CLI** | `includes/class-wppo-cli-command.php:623` | `wp wppo cache|database|image|settings|object-cache` subcommands, `ALLOWED_SETTINGS_KEYS` validation |
| **Activation** | `includes/class-activate.php:70`, `performance-optimisation.php:62` | `register_activation_hook`, `maybe_seed_settings`, `create_activity_log_table`, `wppo_version`, `.htaccess`, `advanced-cache.php` drop-in, `WP_CACHE` constant |
| **Uninstall** | `uninstall.php:1-190` | `WP_UNINSTALL_PLUGIN` guard, per-site `DROP TABLE`, `delete_option`, `switch_to_blog` loop, directory + drop-in cleanup, `wppo_delete_directory` symlink guard |

IDs under verdict: `W-MULTI-01`, `W-CACHE-01`, `W-CRON-01`, `W-CRON-02`, `W-RUM-01`, `W-CCSS-01`, `W-UTIL-01`, `W-REST-01`, `W-ADMIN-01`, `W-CLI-01`, `W-ACT-01`, `W-UNINST-01`.

---

## 2. Fixes Verified

### 2.1 Util::get_settings memoization + invalidation (W-UTIL-01)

**What changed — `includes/class-util.php:84-213`:**
```php
private static ?array $settings_cache = null;
private static bool $settings_cache_loaded = false;
public static function get_settings(): array {
  if (self::$settings_cache_loaded) return self::$settings_cache ?? [];
  self::ensure_settings_cache_hook();
  $raw = get_option('wppo_settings',[]);
  if (!is_array($raw)) $raw=[];
  self::$settings_cache=$raw; self::$settings_cache_loaded=true; return $raw;
}
private static function ensure_settings_cache_hook(): void {
  static $hooked=false; if($hooked) return; $hooked=true;
  add_action('update_option_wppo_settings',[self::class,'on_settings_update'],10,2);
  add_action('add_option_wppo_settings',[self::class,'on_settings_add'],10,2);
  add_action('delete_option_wppo_settings',[self::class,'clear_settings_cache']);
}
public static function on_settings_update($old,$new): void {
  self::$settings_cache=is_array($new)?$new:[]; self::$settings_cache_loaded=true;
}
public static function on_settings_add($option,$value): void {
  if ('wppo_settings'===$option){ self::$settings_cache=is_array($value)?$value:[]; self::$settings_cache_loaded=true; }
}
```

**Verdict — PASS with multisite gap (see §3.1):**

* Hot path correctly collapsed: `includes/class-main.php:251` `Main::__construct` now `$stored=Util::get_settings(); $this->options=!empty($stored)?$stored:$defaults`, `includes/class-cache.php:261` + `1633`, `includes/class-cron.php:115,168,184,202,256,307,378,629,698` (8 sites), `includes/class-used-css.php:137,994`, `includes/class-rum.php:91` all via `Util::get_settings()` (`grep -rn get_option.*wppo_settings` shows 6→1 on frontend render). `includes/class-critical-css.php:925,1015,1026` and `includes/class-llms.php:38,236,342,361` etc. remain direct (`~32` residual sites) — not on hot path, deferred.
* Invalidation hooks: `update_option_wppo_settings` (fired by `update_option` only when value actually changes; `on_settings_update` keeps memo coherent for `includes/class-rest.php:489` `update_settings` + `769` `import_settings` + `includes/class-wppo-cli-command.php:654,722`). `add_option_wppo_settings` populates memo on fresh install via `includes/class-activate.php:180` `add_option('wppo_settings',...)`. `delete_option_wppo_settings` clears. `tests/php/bootstrap.php:213` calls `Util::clear_settings_cache()` + `reset_all_caches()` per `WPPO_Test_Bootstrap::setUp` — lifecycle correct for tests.
* `set_settings_cache` / `clear_settings_cache` / `reset_all_caches` (`includes/class-util.php:141,153,164`) allow same-request cache warming after `update_option` without waiting for hook.
* No `switch_to_blog` isolation — memo is per-request global, not per-blog (see §3.1). Single-site correct; multisite `switch_to_blog` leaks.
* `ensure_settings_cache_hook` uses `static $hooked` function-local, not class property — survives `clear_settings_cache` without re-hooking, correct (hooks stay registered for remainder of request).
* Edge: `update_option` no-op (same value) does not fire `update_option_*` hook → memo not refreshed but value unchanged → no staleness. Direct `$wpdb->query("UPDATE wp_options ...")` or `wp_cache_set('wppo_settings','options',...)` bypasses hooks → stale (no such path in plugin; acceptable).
* `get_settings` is not `switch_to_blog`-aware: if `get_settings` called for blog 1, then `switch_to_blog(2)` + `get_settings` returns blog 1's cached array (WordPress `get_option` is site-aware, but memo short-circuits). Need `switch_blog` hook or blog-keyed cache. **New MEDIUM filed §3.1.**

**Before/after:**

| Metric | Before | After |
|--------|--------|-------|
| Deserializations per frontend `GET /` (cache+usedCSS+imageOpt) | 6× `get_option` (`maybe_unserialize` + `apply_filters`) | 1× (`Util::get_settings` first call), 5× memo hit |
| Invalidation | none (re-read each time) | hook-based + explicit setters |

---

### 2.2 transient_key multisite isolation (W-MULTI-01)

**Impl — `includes/class-util.php:676-685`:**
```php
public static function transient_key(string $key): string {
  if (!function_exists('is_multisite')) return $key;
  try { return is_multisite() ? (string)get_current_blog_id().'_'.$key : $key; }
  catch (\Throwable $e) { return $key; }
}
```

**Verdict — PASS (usage correct, uninstall gap):**

* All plugin transient keys now multisite-safe via `Util::transient_key`:
  * RUM: `includes/class-rum.php:252` `wppo_rum_ratelimit_` + `320` `wppo_rum_queue` + `358` `wppo_rum_flush_lock`
  * CCSS: `includes/class-critical-css.php:230,874,955` `wppo_ccss_status_{hash}` (hash itself now blog-aware §2.5, double isolation correct)
  * Cron locks: `includes/class-cron.php:276,372,623,725` `wppo_preload_cron_lock`, `wppo_used_css_lock`, `wppo_img_convert_lock`, `wppo_db_cleanup_lock`
  * Cache stampede: `includes/class-cache.php:1611` `wppo_cache_write_{md5(file_path)}`
  * Cache sizes: `includes/class-main.php:1444,1458` `wppo_cache_size` / `wppo_total_js_css`
  * Pagespeed/LCP: `includes/class-pagespeed.php:312` `wppo_pagespeed_{md5}_{strategy}`, `657` `wppo_lcp_url_`, `includes/class-telemetry.php:69`, `includes/class-asset-manager.php:210`
  * Activation notices: `includes/class-activate.php:70,91` + `includes/class-main.php:833,844` `wppo_activation_notices`, `wppo_wp_cache_fix_checked`
  * DB cleanup counts: `includes/class-database-cleanup.php:821,892,924` `wppo_db_cleanup_counts`
  * Object-cache drop-in template `templates/object-cache.php:69` uses its own `blog_prefix = (is_multisite()?get_current_blog_id():$table_prefix).':'` — consistent convention with `transient_key`'s `blog_id_` prefix; two namespaces don't collide (transients vs object-cache keys).
* `is_multisite()` wrapped in `function_exists` + `try/catch` handles early bootstrap before `pluggable` (≈`WP_INSTALLING`) — graceful fallback.
* `min_cache_dir` (`includes/class-util.php:578`) + `min_cache_url:594` already blog-scoped `cache/wppo/min/{blog_id}/css+js` — correct, mirrors transient isolation.
* **Gap:** `uninstall.php:149-152` still does manual prefix `is_multisite()?get_current_blog_id().'_':''` instead of `Util::transient_key` (unavailable in uninstall context without autoloader — acceptable). But it **only deletes 5 transients** (`wppo_activation_notices`, `wppo_show_welcome_notice`, `wppo_cache_size`, `wppo_total_js_css`, `wppo_wp_cache_fix_checked`) — misses new keys `wppo_rum_queue`, `wppo_rum_flush_lock`, `wppo_rum_ratelimit_*`, `wppo_ccss_status_*`, `wppo_pagespeed_*`, `wppo_db_cleanup_counts`, `wppo_preload_cron_lock` etc. These are non-autoload transients (`wp_options` transient rows or object-cache) that survive uninstall as orphan rows on DB-backed sites. **LOW filed §3.4.**

---

### 2.3 Cron schedules + RUM flush cron (W-CRON-01, W-RUM-01)

**Impl — `includes/class-cron.php:56-409`:**

* Constructor hooks: `init→schedule_cron_jobs` (reschedule check), `wppo_page_cron_hook` + `wppo_page_cron_batch→wppo_page_cron_callback`, `wppo_img_conversion→img_convert_cron`, `cron_schedules→add_custom_cron_interval`, `wppo_generate_static_page/url→process_page/url`, `wppo_database_cleanup_cron`, `wppo_web_vitals_rescan`, `wppo_llms_txt_daily`, `wppo_used_css_cron`, `wppo_ccss_regeneration` — all via `add_action`/`add_filter` at `__construct`. `74` adds `wppo_rum_flush→RUM::flush_queue` (`@since NEXT`).
* Custom schedule `includes/class-cron.php:100-104`:
  ```php
  $schedules['every_5_hours']=['interval'=>5*60*60,'display'=>__('Every 5 Hours',...)];
  ```
  Used by `wppo_page_cron_hook` (`121`) + `wppo_used_css_cron` (`152`) — correct.
* `schedule_cron_jobs:114-158` reads `Util::get_settings()` once (now memo'd) vs pre-fix 2× `get_option`:
  * `enablePreloadCache` gate: schedules `every_5_hours` `wppo_page_cron_hook` if enabled else clears `wppo_page_cron_hook` + `wppo_page_cron_batch` + `wppo_generate_static_page/url` (all leftover per-page singles purged).
  * `wppo_img_conversion` `hourly` (always), `wppo_database_cleanup_cron` `daily` (always), `wppo_web_vitals_rescan` `daily` (always, but `web_vitals_rescan_cron:202` early-returns unless `performance_audit.auto_rescan` ∈ `{daily,weekly}` and `weekly` throttled by `wppo_web_vitals_last_rescan` option < `WEEK_IN_SECONDS`), `wppo_llms_txt_daily` gated on `llms_txt.enabled`, `wppo_used_css_cron` gated on `file_optimisation.removeUnusedCSS`, `wppo_ccss_regeneration` `daily` (always, `ccss_regeneration_cron:183` gates on `criticalCSS`).
* RUM flush: **not a recurring schedule** — `includes/class-rum.php:340-341` does:
  ```php
  if (!wp_next_scheduled('wppo_rum_flush')) wp_schedule_single_event(time()+300,'wppo_rum_flush');
  ```
  in the `else` branch of `store_sample` (low-traffic fallback). Burst path: `count>=FLUSH_THRESHOLD(20)` → immediate `flush_queue()`. Random 10% → immediate flush. Fallback schedules single 300 s event. `includes/class-rum.php:357-431` `flush_queue` uses 30 s transient lock `wppo_rum_flush_lock`, copy-then-`delete_transient(queue)` before aggregation, `_ts`-bucketed by sample day (`gmdate('Y-m-d',$ts)`), `update_option('wppo_web_vitals_rum',...)` once per ≤100 samples. `includes/class-cron.php:409` `clear_cron_jobs` does `wp_clear_scheduled_hook('wppo_rum_flush')` — covers pending singles.

**Verdict — PASS with deactivate gap:**

* `cron_schedules` filter correctly returns array, no `wp_schedule_event` duplication due to `wp_next_scheduled` guards.
* `wppo_rum_flush` wiring is correct for batched Beacon API (95% `update_option` reduction already verified in performance review). `Util::transient_key` qualifies both `QUEUE_KEY` and `FLUSH_LOCK_KEY` + `ratelimit` — multisite-safe.
* `clear_cron_jobs:409` now clears `wppo_rum_flush` + all used-CSS/CCSS/llms/web-vitals hooks — **fixed**.
* **Residual:** `includes/class-deactivate.php:50-72` still only unschedules `wppo_page_cron_hook`, `wppo_page_cron_batch`, both `wppo_img_conversion` aliases, `wppo_generate_static_page` + DB cleanup. It does **not** call `Cron::clear_cron_jobs()` nor clear `wppo_web_vitals_rescan`, `wppo_llms_txt_daily`, `wppo_ccss_regeneration`, `wppo_rum_flush`, `wppo_used_css_cron`, `wppo_generate_static_url`, `wppo_page_cron_batch` offset, nor the new `wppo_generate_ccss` Action Scheduler job. Deactivating leaves 5–6 cron entries scheduled that will fire `do_action` with no handler (no-op but clutters `wp_options cron` and fires `doing_it_wrong` on some hosts). **MEDIUM filed §3.2.** `schedule_cron_jobs` runs on `init` every request (`6× wp_next_scheduled` → 6× `get_option('cron')` unserialize per frontend request) — acceptable but could be throttled behind a 5-min transient (performance review `P-WP-02`).

---

### 2.4 CCSS hash blog_id (W-CCSS-01)

**Impl — `includes/class-critical-css.php:152-156`:**
```php
public static function get_template_hash(string $template=''): string {
  if (empty($template)) $template=self::get_current_template_slug();
  return md5(get_current_blog_id().'-'.$template.'-'.get_stylesheet());
}
```

**Verdict — PASS:**

* Pre-fix `md5($template.'-'.get_stylesheet())` collided across sites on same network with same theme/template (e.g., `twentytwentyfour/page`). Post-fix prefix `get_current_blog_id().'-'` makes CCSS file name `/cache/wppo/ccss/{hash}.css` site-unique without needing per-blog subdirectories. Status transient `wppo_ccss_status_{hash}` via `Util::transient_key` adds second layer (`blog_id_wppo_ccss_status_hash`) — double isolation intentional and harmless.
* `get_stylesheet()` is already site-specific (`get_option('stylesheet')`), but `get_template()` disambiguates parent vs child; `get_current_blog_id` prefix handles same-child-theme multisite.
* No migration needed: old files with old hash remain unused and will be pruned by `Cache::clear_cache` or `uninstall.php:179` `wppo_delete_directory(cache/wppo)`; `Cron::ccss_regeneration_cron:183` will regenerate with new hash on next daily tick.

---

### 2.5 Object-cache lifecycle (W-CACHE-01)

**Verdict — PASS:**

* `templates/object-cache.php:26,69` marker `Redis Object Cache Drop-in for Performance Optimisation` + `blog_prefix = (is_multisite()?get_current_blog_id():$table_prefix).':'` unchanged — per-blog keyspace via `blog_prefix` is correct and consistent with `Util::transient_key`'s `blog_id_` convention in different key domain (no conflict).
* `includes/class-cache.php:1569-1621` `atomic_put_contents` (tmp + `WP_Filesystem::move`/`rename` + fallback `put_contents`) + per-file `Util::transient_key('wppo_cache_write_'.md5(file_path))` 5 s lock + `try/finally` delete — prevents torn `index.html` on stampede (burst 100 RPS → ≤2 writers, file never half-written). `file_path` contains domain so per-site already; `transient_key` adds object-cache isolation.
* `includes/class-cache.php:380-397` `should_bypass_for_litespeed` centralizes `LiteSpeed_Integration::should_disable_wppo_optimizer()` + `litespeed_can_optm` filter — coexistence correct.
* `includes/class-main.php:596` `maybe_fix_wp_cache` throttled by `Util::transient_key('wppo_wp_cache_fix_checked')` 1 h — multisite-safe.

---

### 2.6 REST lifecycle (W-REST-01)

**Verdict — PASS:**

* 25 routes in `includes/class-rest.php:80` all `permission_callback => permission_callback` (`manage_options + X-WP-Nonce` via `Rest::permission_callback:339-343` `current_user_can('manage_options') && $nonce_valid`) except `includes/class-rest.php:222,227` `rum_collect` `permission_callback => __return_true` — intentional public beacon, documented at `215-229` with 3 compensating controls (daily rolling per-path token `wp_hash('wppo_rum_'.Ymd.'|'.$path)` + `hash_equals` 24 h window, per-IP `Util::transient_key('wppo_rum_ratelimit_'.md5(IP))` 120/h, bounded `14d × 200 paths` storage). `includes/class-rum.php:100-144` order `is_enabled → is_valid_token → is_rate_limited → sanitize_sample → store_sample` — cheap checks first, correct.
* `includes/class-rest.php:451,731` now use `Util::ALLOWED_SETTINGS_TABS/KEYS` single source (was 4-way drift) — lifecycle consistent with `includes/class-wppo-cli-command.php:627` + `includes/class-main.php:1526` `wppoSettings.allowedSettingsKeys`.
* `includes/class-rest.php:374-395` `clear_cache` path traversal hardening: `realpath` when available, else `wp_normalize_path` candidate + `trailingslashit` prefix check + `..` rejection — allows clearing uncached pages while blocking `../`.
* `includes/class-rest.php:1164` `get_ccss_status` still gates on `manage_options`.

---

### 2.7 Admin lifecycle (W-ADMIN-01)

**Verdict — PASS:**

* `includes/class-main.php:418-427` hooks `admin_menu→init_menu`, `admin_init→maybe_fix_wp_cache|maybe_run_upgrades|maybe_run_version_upgrade|maybe_migrate_block_assets_setting`, `admin_enqueue_scripts→admin_enqueue_scripts`, all `is_admin()`/`current_user_can` gated inside callbacks (`maybe_fix_wp_cache:827` early `defined WP_CACHE`, `maybe_run_upgrades:861` `manage_options`, `migrate_block_assets_setting:787` early return if not 6.9). Frontend cacheable requests never do settings writes (by design `migrate_block_assets_setting` runs on `admin_init` not constructor).
* `includes/class-main.php:1729-1742` `add_setting_to_admin_bar` now gates `if (!current_user_can('manage_options')) return;` — fixes prior information disclosure (A-AUTH-02). Hook still registered for all but early-returns cheaply; avoids capability timing at `add_action` registration.
* `includes/class-main.php:1444-1462` `admin_enqueue_scripts` `get_cache_size` / `get_js_css_minified_file` gated by `wppo_cache_size` 15-min transient (`Util::transient_key`) + `get_current_screen` early return — only on `toplevel_page_performance-optimisation`, not every `wp-admin`.

---

### 2.8 Frontend lifecycle (W-FRONT-01)

**Verdict — PASS:**

* `includes/class-main.php:482-551` frontend wiring correct: `template_redirect` at priority `0→capture_template_start`, `10→cache start_output_buffer`, `20→used_CSS / LCP` buffers — order respected. `wp_enqueue_scripts` at `5→RUM maybe_enqueue_scripts`, `PHP_INT_MAX-1→minify_queued_styles`, `PHP_INT_MAX→Cache::combine_css` — post-queue.
* `includes/class-cache.php:910` `wppo_inline_drift_` log key via `Util::transient_key` — multisite-safe.
* `includes/class-image-optimisation.php:117-844` per-request `static file_exists_cache` (500 FIFO) + `get_cached_image_size` LRU — cross-request no stale (request scope only).
* `src/lazyload.js` + `src/main.js` admin-bar clear buttons: nonce refresh on 403 + `has_filter('content_url')`/`home_url` cache bypass — SPA `wppoSettings` injected via `wp_localize_script` at `includes/class-main.php:1526` with `allowedSettingsKeys`.
* `RUM::print_config:195` bakes `apiUrl + token + path` into cached HTML via `wp_footer` — cache-hit HTML still beacons without WP.

---

### 2.9 CLI lifecycle (W-CLI-01)

**Verdict — PASS with allowlist narrowing noted:**

* `includes/class-wppo-cli-command.php:42` `WPPO_CLI_Command extends WP_CLI_Command` subcommands `cache|database|image|settings|object-cache` all use `Util::ALLOWED_SETTINGS_KEYS/TABS` single source (`627,711`) — consistent with REST. `Util::sanitize_settings_recursively` called before `update_option`.
* `includes/class-wppo-cli-command.php:712` `known_tabs = Util::ALLOWED_SETTINGS_TABS` — now **excludes** `core_tweaks` (present pre-fix allowlist `file_optimisation, preload_settings, image_optimisation, database_cleanup, object_cache, performance_audit, core_tweaks, cache_settings`). `Util::ALLOWED_SETTINGS_KEYS` is 9 keys without `core_tweaks`. CLI now warns `Unrecognized settings tab "core_tweaks"` and REST `import_settings` 400s on `core_tweaks` payloads — intentional narrowing but **breaking change** for imports that included `core_tweaks` (architecture review `R-CORE-TWEAKS-IMPORT`). Document in `readme.txt` changelog or re-add `core_tweaks` to allowlist if still consumed.
* No `is_multisite`/`switch_to_blog` in CLI command — relies on `WP_CLI` global blog context (`--url` flag). Correct; no extra handling needed.

---

### 2.10 Activation lifecycle (W-ACT-01)

**Verdict — PASS:**

* `performance-optimisation.php:62-72` defines `WPPO_PLUGIN_PATH/URL/VERSION`, `require vendor/autoload.php`, `new Main()` (hooks only, no I/O), `register_activation_hook→Activate::init`, `register_deactivation_hook→Deactivate::init` — correct order (constants before class load).
* `includes/class-activate.php:70-111` `init` does `Advanced_Cache_Handler::foreign_dropin_present` check → `create()` + `add_wp_cache_constant` → `set_transient(Util::transient_key('wppo_activation_notices'))` (multisite-safe) → `update_option('wppo_version', WPPO_VERSION)` (fresh install marker) → `maybe_seed_settings:111` `if (get_option('wppo_settings',null)!==null) return; add_option('wppo_settings',$defaults,'',false)` (`autoload=no` for settings — correct, avoids `wp_load_alloptions` bloat). Adds `wppo_img_info`, `wppo_activation_time`, `wppo_activity_logs` table via `dbDelta`. `maybe_run_upgrades(!has_activation_time)` gated on stored version floor `1.8.1`.
* `includes/class-activate.php:111` `get_option('wppo_settings',null)` intentionally distinguishes "no row" (`null`) from "empty array" — seed only on fresh install. `add_option` hook `add_option_wppo_settings` will populate `Util::get_settings` memo if already primed.

---

### 2.11 Uninstall lifecycle (W-UNINST-01)

**Verdict — PASS with transient residuals (see §3.4):**

* `uninstall.php:9` `if (!defined('WP_UNINSTALL_PLUGIN')) exit;` — required guard.
* `wppo_cleanup_site:18-90` drops `{$prefix}wppo_activity_logs`, deletes 18 options (`wppo_settings`, `wppo_img_info`, `wppo_transient_index`, `wppo_preload_cron_offset`, `wppo_last_db_cleanup`, `wppo_version`, `wppo_block_assets_migrated`, `wppo_cache_last_cleared*`, `wppo_activation_time`, `wppo_activity_cache_version`, `wppo_audit_salt`, `wppo_db_cleanup_salt`, `wppo_activity_log_salt`, `wppo_img_info_salt`, `wppo_review_*`), deletes post-meta `delete_post_meta_by_key('_wppo_*')`, deletes cache dir `WP_CONTENT_DIR/cache/wppo/` + `wppo/` via `wppo_delete_directory`, removes `wppo-redis-config.php` + `advanced-cache.php` + `object-cache.php` drop-ins with content-marker check (`WPPO_ADVANCED_CACHE_DROPIN` / `is_user_logged_in_without_wp` / `Redis Object Cache Drop-in for Performance Optimisation`) — never deletes foreign drop-ins.
* Multisite loop `uninstall.php:175-190` `is_multisite() && get_sites(['number'=>100,'offset'=>...])` + `switch_to_blog(blog_id) → wppo_cleanup_site() → restore_current_blog()` — correct, paginated `has_more_sites = count==limit`. `wppo_cleanup_site` uses `global $wpdb` prefix so table/option names are site-specific after switch.
* `wppo_delete_directory:103-130` symlink hardening **fixed** (`U02`): `is_link($dir)` top guard `unlink` + loop `is_link($path)` before `is_dir($path)` (must be before, since `is_dir` follows symlinks) → deletes link only, never recurses — prevents planted symlink inside `cache/wppo` causing arbitrary delete on uninstall.
* `uninstall.php:149-152` transient cleanup uses manual `is_multisite()?get_current_blog_id().'_':''` prefix — correct in uninstall context without `Util` autoload, and `switch_to_blog` makes `get_current_blog_id` correct per iteration. But only 5 keys deleted (see §3.4).

---

## 3. New Issues / Regressions

| # | Sev | File:Line | Detail | Fix |
|---|-----|-----------|--------|-----|
| **W-001** | **MEDIUM** | `includes/class-util.php:120-213` `get_settings` memo | **Memo is not `switch_to_blog`-aware.** `Util::$settings_cache` is a single global array without blog key. `get_option('wppo_settings')` is site-aware, but after priming for blog 1, `switch_to_blog(2); Util::get_settings()` returns blog 1's settings for remainder of request. Same for `Util::$home_url_cache` (correctly keyed) vs `settings_cache` (not keyed). Impact: multisite admin `switch_to_blog` loops (e.g., uninstall's per-site cleanup if it reused `Util::get_settings`, or any custom code that switches blogs in same request for REST batch) reads wrong settings, potentially re-scheduling crons or applying wrong CDN/optimiser toggles. Likelihood: medium (uninstall does not use `Util::get_settings`, but third-party `switch_to_blog` usage or `WP_CLI --url` loops could hit). **Fix:** key `settings_cache` by `get_current_blog_id()`, clear on `switch_blog` action: `add_action('switch_blog', [self::class,'clear_settings_cache'])` or store `array<int,array>` map + check `get_current_blog_id()` before memo hit. See `Util::cached_home_url:647` for correct pattern. | Add `private static array $settings_cache = []` keyed by `blog_id`, or `clear_settings_cache` on `switch_blog`. |
| **W-002** | **MEDIUM** | `includes/class-deactivate.php:50-95` `Deactivate::unschedule_crons` | **Deactivation leaves 6 cron entries scheduled.** `Deactivate::init` calls `unschedule_crons` (clears `wppo_page_cron_hook`, `wppo_page_cron_batch`, both `wppo_img_conversion` aliases, one `wppo_generate_static_page`) + `unschedule_database_cleanup_cron` (`wppo_database_cleanup_cron`) but not `wppo_web_vitals_rescan`, `wppo_llms_txt_daily`, `wppo_ccss_regeneration`, `wppo_used_css_cron`, `wppo_generate_static_url`, `wppo_rum_flush`, `wppo_generate_ccss` (Action Scheduler), nor `wppo_preload_cron_offset` beyond the single `delete_option`. `includes/class-cron.php:396-409` `Cron::clear_cron_jobs()` does clear all 9 + transients, but is never called by `Deactivate`. Result: after deactivate+reactivate, duplicate hooks can fire or `wp_options cron` accumulates no-op handlers. **Fix:** have `Deactivate::init` call `Cron::clear_cron_jobs()`. | Replace manual unschedule with `Cron::clear_cron_jobs()` or add missing 6 `wp_clear_scheduled_hook` calls. |
| **W-003** | **LOW** | `includes/class-util.php:182-184` `ensure_settings_cache_hook` | **Hooks registered with `static $hooked` are never released on `clear_settings_cache` / plugin deactivation.** After `Util::clear_settings_cache()` (tests or `delete_option_wppo_settings`), hooks remain registered but will re-populate memo on next `get_settings`. Not a leak (single add per request), but if `Util::reset_all_caches()` is called in long-running CLI (`wp wppo` batch across sites), hooks persist for prior blog's context. No functional bug, but long-running `WP_CLI` across `switch_to_blog` could keep old `add_action` closures. | Document `reset_all_caches` does not unhook; acceptable. |
| **W-004** | **LOW** | `uninstall.php:149-155` transient cleanup | **New RUM/CCSS transient keys not deleted on uninstall.** Loop deletes only 5 keys; `wppo_rum_queue`, `wppo_rum_flush_lock`, `wppo_rum_ratelimit_*`, `wppo_ccss_status_*`, `wppo_pagespeed_*`, `wppo_db_cleanup_counts`, `wppo_preload_cron_lock`, `wppo_used_css_lock`, `wppo_img_convert_lock`, `wppo_db_cleanup_lock`, `wppo_cache_write_*`, `wppo_total_js_css` salt etc. remain as orphan transient rows (or object-cache keys) when object-cache is DB-backed (no expiry GC until accessed) — or as `wp_options` rows with `autoload=no` that survive uninstall. Also `wppo_web_vitals_rum` / `wppo_web_vitals_trends` options not deleted. **Fix:** add `delete_option('wppo_web_vitals_rum')`, `delete_option('wppo_web_vitals_trends')`, `delete_option('wppo_web_vitals_last_rescan')`, `delete_transient(Util::transient_key('wppo_rum_queue'))` etc., or wildcard `DELETE FROM wp_options WHERE option_name LIKE '%wppo_rum%'` via `$wpdb->query` with `$wpdb->options`. | Add missing deletes to `wppo_cleanup_site()`. |
| **W-005** | **INFO** | `includes/class-advanced-cache-handler.php:141-150` `COOKIEHASH` fallback | **Fallback hash now host-only (`wp_parse_url(home_url(), PHP_URL_HOST)`) — correct fix for scheme mismatch but must stay in sync with `advanced-cache.php` drop-in baked at `create()` time.** `create()` bakes `$site_url` + `$cookie_hash` into generated `advanced-cache.php`. If site switches `http→https` or changes domain after drop-in generation, baked `COOKIEHASH` rotates on next `maybe_run_upgrades` / re-create, but in-flight requests still use old drop-in until regenerated. Not a regression of this patch; the host-only fallback reduces mismatch window. Verify `includes/class-activate.php:220` upgrade re-bakes drop-in on version change. | No action; monitor. |

---

## 4. Remaining WordPress Lifecycle Gaps (not fixed, pre-existing)

| ID | File:Line | Gap |
|----|-----------|-----|
| **W-GAP-01** | `includes/class-cron.php:114` `schedule_cron_jobs` on `init` | `6× wp_next_scheduled` = `6× get_option('cron')` unserialize per request, including frontend cacheable `GET /`. Pre-fix every request paid `8× get_option('wppo_settings')` + `6× cron`; post-fix memo reduces to `1×` settings but still `6× cron`. Throttle behind 5-min transient or move to `admin_init`/`wp_loaded` to spare frontend. |
| **W-GAP-02** | `includes/class-main.php:795,1069,1114` | 3 residual direct `get_option('wppo_settings')` (migrate_block_assets `795`, on-settings-update read `1069,1114`) not yet via `Util::get_settings` — not on frontend hot path but admin `admin_init` pays extra deserialize. Collapse to memo. |
| **W-GAP-03** | `includes/class-util.php:594` `min_cache_dir` + `includes/class-cache.php:CACHE_DIR` | Frontend min cache `cache/wppo/min/{blog_id}/js|css` is correctly blog-scoped; static HTML cache `cache/wppo/{domain}/{path}/index.html` is domain-scoped (already multisite-safe). No gap — note for consistency. |
| **W-GAP-04** | `includes/class-wppo-cli-command.php` | CLI across multisite (`wp site list --field=url | xargs wp wppo cache clear --url=`) runs per-site sequentially; `Util::get_settings` memo not cleared between `--url` invocations within same process (if `WP_CLI` reuses process). `reset_all_caches` only in `tests/bootstrap`. Add `WP_CLI` hook `before_invoke` clear. |
| **W-GAP-05** | `includes/class-rum.php:329` `set_transient(queue, HOUR)` | Queue transient TTL 1 h; if site has no traffic and `wppo_rum_flush` single event missed (cron disabled via `DISABLE_WP_CRON`), queued samples sit 1 h then expire — data loss. Object-cache sites with eviction may lose earlier. Cron-disabled sites should flush synchronously or use `wp_options` durable queue. |

---

## 5. WP-CLI / Cron / Activation Matrix

| Scenario | Expected | Actual | Verdict |
|----------|----------|--------|---------|
| Single-site fresh install → `wp plugin activate` | `wppo_settings` seeded `autoload=no`, `wppo_activation_time`, `wppo_version`, activity table, `advanced-cache.php` + `WP_CACHE` | `includes/class-activate.php:180` `add_option('wppo_settings',$defaults,'',false)` seeds with `autoload=no`; `update_option('wppo_version')` marks fresh; drop-in baked with host-only hash | **PASS** |
| Single-site update (plugin zip) | `maybe_run_upgrades` on `admin_init` + `upgrader_process_complete` re-bakes drop-in once, clears cache if needed | `includes/class-main.php:861,881` correctly throttled by `wppo_version` floor `1.8.1` | **PASS** |
| Multisite fresh install network-activate | Per-site `wppo_settings` per blog; per-blog cache dirs; per-blog transients; per-blog cron | `activate`/`deactivate` are per-site (not network-wide); `uninstall` correctly loops `get_sites` + `switch_to_blog` + per-site `delete_option` | **PASS** (with W-001 memo leak) |
| Deactivate → reactivate | Cron cleared, `WP_CACHE` removed, drop-ins removed, cache cleared | Partial: 6 cron left (W-002) | **PARTIAL** |
| Uninstall single-site | All `wppo_*` options, transients, tables, cache, drop-ins removed | All options + 5 transients + dirs removed; `wppo_web_vitals_rum/trends` + new RUM transients remain (W-004) | **PARTIAL** |
| Uninstall multisite | Each blog's data removed; shared `wp-content/cache/wppo` deleted | Per-blog loop correct; shared dir deleted on first iteration (second is no-op); transient prefix manually computed (correct) but missing keys (W-004) | **PARTIAL** |
| CLI `wp wppo cache clear` | Clears per-site cache; per-domain dirs | Via `Cache::clear_cache()` (domain-aware) + `transient_key` for size | **PASS** |
| CLI `wp wppo settings import` with `core_tweaks` | Previously accepted; now 400/warning | `Util::ALLOWED_SETTINGS_KEYS` excludes `core_tweaks` | **BREAKING** — intentional narrowing, document |
| Cron disabled (`DISABLE_WP_CRON`) | `wppo_rum_flush` single event never fires; queue grows to 100 then drops oldest | CCSS/used-CSS/preload/page crawler crons also stalled; RUM `get_data()` opportunistic flush on dashboard mitigates but anonymous beacons still queue | **DEGRADED** — W-GAP-05 |
| Object-cache persistent (Redis) | Transient locks/queues in Redis (fast), drop-in `blog_prefix` isolation | `Util::transient_key` → `wp_cache` key includes `blog_id_` prefix, drop-in uses `blog_prefix:` colon — two namespaces, no overlap | **PASS** |

---

## 6. Verdict

**PASS — with 2 MEDIUM + 2 LOW follow-ups.**

| Question | Answer | Evidence |
|----------|--------|----------|
| **Multisite safe?** | **Largely yes, with one medium memo leak.** | `transient_key` + `min_cache_dir` + `CCSS hash` + `COOKIEHASH` + object-cache `blog_prefix` all correctly blog-scoped. **Gap:** `Util::get_settings` memo not keyed by `get_current_blog_id()` / no `switch_blog` hook → `switch_to_blog` returns prior blog's settings (W-001). |
| **Object-cache safe?** | **Yes.** | Drop-in `blog_prefix` + transient `blog_id_` prefix are consistent distinct namespaces; stampede lock and RUM queue/locks use `transient_key` correctly. |
| **Cron correct?** | **Yes, except deactivate residual.** | `cron_schedules` + 7 recurring + `wppo_rum_flush` single event all correctly wired; `clear_cron_jobs` covers all 9. **Gap:** `Deactivate` does not call `Cron::clear_cron_jobs` → 6 orphan crons (W-002). |
| **RUM flush cron?** | **Correct and multisite-safe.** | Single 300 s event, 20-threshold + 10% random + transient lock 30 s, `Util::transient_key` qualified, single `update_option` per ≤100, `_ts` day-bucketing, opportunistic `get_data()` flush. |
| **CCSS hash blog_id?** | **Fixed.** | `md5(get_current_blog_id().'-'.$template.'-'.get_stylesheet())` (`includes/class-critical-css.php:156`) isolates per-blog CCSS files + transients. |
| **Util::get_settings invalidation?** | **Hook-based + explicit, complete for normal path.** | `update_option_wppo_settings` / `add_option_wppo_settings` / `delete_option_wppo_settings` + `set/clear/reset_all_caches` cover `update_option`/`add_option`/`delete_option` and tests. **Not** covering direct `$wpdb` writes (by design) or `switch_to_blog` (W-001). |
| **transient_key coverage?** | **All hot keys covered; uninstall missing new keys.** | All plugin transients via `Util::transient_key`; uninstall only deletes 5/15 keys + misses `wppo_web_vitals_*` options (W-004). |
| **New regressions?** | **Low — 2 medium worth fixing, no data loss or crash.** | W-001 (memo cross-blog leak) and W-002 (orphan crons) are medium but bounded; W-004 transient residuals low. |
| **Remaining gaps?** | **4 info-level — not blocking.** | `P-WP-02` `init` cron chatter, residual direct `get_option`, CLI memo across `--url`, cron-disabled queue expiry. |

**Recommendation:** Ship after fixing **W-001** (key `settings_cache` by `blog_id` + `switch_blog` clear) and **W-002** (`Deactivate::init` call `Cron::clear_cron_jobs()`). Both are S (≤1 h) + no-risk. **W-004** (uninstall transient/option residuals) should be batched with same patch. No revert needed; no high regression.

---

*Evidence produced by independent `Read` + `grep -rn` of `git diff HEAD` (46 files) + `git diff origin/master` and lifecycle trace; no reliance on implementor self-report. All `file:line` refs point to `includes/class-*.php` at `HEAD` (unstaged) unless noted.*
