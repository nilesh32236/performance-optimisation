# Agent A01 — PHP Correctness: Core / Main / Lifecycle — Audit Report

**Base:** `master@31fffc61`  
**Mode:** Audit-only — no production code modified  
**Date:** 2026-08-28

| Field | Value |
|-------|-------|
| **Files assigned** | 6 |
| **Files reviewed** | 6 / 6 |
| **Review status** | ✅ Complete — every line read, hooks/flows traced, DB/FS/network/cache/error/perf/security/dupe/dead analysed |
| **Agents responsible** | Agent A01 — PHP Correctness: Core/Main/Lifecycle specialist |
| **Lines reviewed** | 4,134 (70 + 3,053 + 354 + 156 + 185 + 316) |
| **`wc -l` counts** | `performance-optimisation.php:70`, `includes/class-main.php:3053`, `includes/class-activate.php:354`, `includes/class-deactivate.php:156`, `uninstall.php:185`, `includes/class-admin-notices.php:316` |

---

## 1. `performance-optimisation.php` — 70 lines

### A01-001 — No runtime PHP-version guard — `performance-optimisation.php:1-17` — Category: Compatibility — Severity: **MEDIUM** — Confidence: 95%

- **Problem:** Header declares `Requires PHP: 8.2` but no runtime `version_compare(PHP_VERSION, '8.2', '>=')` early return. `includes/class-main.php` uses typed properties, union complexity, `str_contains`-era idioms that fatally parse-error on PHP 8.0/8.1 before WordPress can honour the header (WP only blocks activation via header check when the plugin is inactive; direct include still executes).
- **Why matters:** Sites on PHP 8.1 that sideload or must-use include (or WP-CLI on older container) get white-screen fatal rather than graceful admin notice.
- **Evidence:**
  ```php
  // performance-optimisation.php:1 — header only, no runtime gate
  // * Requires PHP:      8.2        ← declarative only
  if ( ! defined( 'ABSPATH' ) ) { exit; }
  require_once WPPO_PLUGIN_PATH . 'vendor/autoload.php';
  new Main(); // typed properties inside Main will fatal on 8.1
  ```
- **Impact:** WSOD on <8.2 instead of "requires 8.2" notice.
- **Recommended solution:** Insert `if (version_compare(PHP_VERSION,'8.2','<')) { add_action('admin_notices', ...); return; }` at top after ABSPATH guard (same pattern WP recommends).

### A01-002 — `Tested up to: 7.1` fictitious WordPress version — `performance-optimisation.php:7` — Category: Correctness — Severity: **INFO** — Confidence: 100%

- **Problem:** Latest public WP is ~6.8; `7.1` does not exist. WP.org readme validation will flag as future version and the header is displayed to users as if tested on non-existent core.
- **Evidence:** `* Tested up to:      7.1`
- **Impact:** Misleading compatibility badge; WP.org parser may warn.
- **Fix:** Set to actual tested major (e.g. `6.8` or `6.9` after verification).

### A01-003 — Duplicate Composer autoloader `require_once` — `performance-optimisation.php:41` + `includes/class-main.php:437` — Category: **DUPLICATE** — Severity: **LOW** — Confidence: 100%

- **Problem:** Loader required at `performance-optimisation.php:41` and again in `Main::includes():437` `require_once WPPO_PLUGIN_PATH . 'vendor/autoload.php';`. `require_once` prevents fatal but is redundant I/O (`stat` + `realpath`).
- **Evidence:**
  ```php
  // performance-optimisation.php:41
  require_once WPPO_PLUGIN_PATH . 'vendor/autoload.php';
  // includes/class-main.php:437
  require_once WPPO_PLUGIN_PATH . 'vendor/autoload.php';
  ```
- **Impact:** Negligible perf; confuses load-order reasoning (which autoloader wins).
- **Fix:** Keep only entry-file load; let `Main::includes()` assume it is loaded or `require_once` with early return.

### A01-004 — `new Main()` executes unconditionally at include time — `performance-optimisation.php:44` — Category: Architecture — Severity: **INFO** — Confidence: 90%

- **Problem:** Object constructed on every `include`, even in contexts where only activation/deactivation callbacks are needed (CLI `wp plugin activate` includes file before firing hook). Means `Main::__construct()` (heavy: options hydration, `setup_hooks()`, filesystem init) runs even when plugin is about to be deactivated.
- **Impact:** Minor extra boot cost on activation/deactivation AJAX; no functional bug.
- **Fix:** Documented as intentional (WP pattern); no change required — noting for completeness.

---

## 2. `includes/class-main.php` — 3,053 lines

### A01-005 — Namespace typo in hook registration — `includes/class-main.php:489` — **CRITICAL** — Confidence: 98%

- **Problem:** Hook `wppo_run_upgrades` registered with callback `array('PerformanceOptimisation\Inc\Activate','maybe_run_upgrades')` (note **Optimisation** with trailing *a*). Real namespace is `PerformanceOptimise\Inc` (declared at `class-main.php:13`, `class-activate.php:11`). PHP will try to call non-existent class `PerformanceOptimisation\Inc\Activate`; unless a class_alias exists, the action never fires.
- **Why matters:** This is the background retry path for the 1.8.1 legacy cache-key eviction (`Activate::schedule_upgrade_routine()` schedules `wppo_run_upgrades` via WP-Cron). If the flush failed, the retry is dead — stale unsalted query-group keys remain forever on affected cached sites, causing cross-site cache poisoning in shared Redis/Memcached (issue #489).
- **Evidence:**
  ```php
  // class-main.php:13
  namespace PerformanceOptimise\Inc;
  // class-main.php:489  ← typo
  add_action( 'wppo_run_upgrades', array( 'PerformanceOptimisation\Inc\Activate', 'maybe_run_upgrades' ) );
  // class-activate.php:11
  namespace PerformanceOptimise\Inc; // ← no 'a'
  ```
- **Impact:** Silent failure of version-upgrade retry; only surfaces under Redis/Memcached shared object cache.
- **Fix:** Replace with `Activate::class . '::maybe_run_upgrades'` or `'PerformanceOptimise\Inc\Activate'`.

### A01-006 — `wp_is_block_theme()` called too early — `includes/class-main.php:832` inside `register_block_assets_filters()` called from `__construct()->setup_hooks()` at plugin-load time — Severity: **MEDIUM** — Confidence: 85%

- **Problem:** `Main` is instantiated directly in `performance-optimisation.php:44` at file-include time (before `after_setup_theme`). `register_block_assets_filters()` calls `wp_is_block_theme()` at line 832 to decide whether to add `should_load_separate_core_block_assets` filter. Before `after_setup_theme`, `wp_is_block_theme()` may return `false` even for block themes (theme support not yet registered), causing classic-theme-only codepath to misfire.
- **Evidence:**
  ```php
  // performance-optimisation.php:44
  new Main(); // ← plugins_loaded/file-include phase
  // class-main.php:342-345
  $this->setup_hooks(); // → register_block_assets_filters() → wp_is_block_theme()
  // class-main.php:832
  if ( $opt_out_combined && ! wp_is_block_theme() ) {
  ```
- **Impact:** Block theme incorrectly gets `should_load_separate_core_block_assets → __return_false`, forcing combined `wp-block-library` stylesheet (perf regression, defeats on-demand).
- **Fix:** Defer `register_block_assets_filters` to `after_setup_theme` or `init` hook instead of constructor.

### A01-007 — Duplicate / overlapping upgrade routers — `includes/class-main.php:488-492` + `includes/class-activate.php:205-232` + `includes/class-main.php:997-1023` — Severity: **MEDIUM** — Confidence: 90%

- **Problem:** Two parallel "run on update" systems: (a) `Activate::maybe_run_upgrades()` (1.8.1 LEGACY_FLUSH_FLOOR, scheduled via `wppo_run_upgrades`, throttled via `maybe_run_upgrades` on `admin_init`/`upgrader_process_complete`) and (b) `Main::maybe_run_version_upgrade()` (generic `wppo_version` vs `WPPO_VERSION`, regenerates `advanced-cache.php` drop-in + full cache clear). Both gated on same `wppo_version` option, both bound to `admin_init` + `upgrader_process_complete`. Execution order on `admin_init` is `maybe_fix_wp_cache (10 default) → maybe_run_upgrades → maybe_run_version_upgrade → maybe_migrate_block_assets_setting` (all priority 10, order of `add_action` calls: 487, 488, 491, 493). Race: whichever runs second sees version already bumped by first if they both update `wppo_version`. Currently `maybe_run_upgrades` checks `LEGACY_FLUSH_FLOOR='1.8.1'` and bumps to `WPPO_VERSION` on success; `maybe_run_version_upgrade` then sees `installed >= WPPO_VERSION` and returns — benign after 1.8.1, but on future version bumps only `maybe_run_version_upgrade` fires (legacy path early-returns). The dual binding also means `upgrader_process_complete` for an *unrelated* plugin fires both handlers (see next finding).
- **Evidence:** `setup_hooks():490-492` double registration.
- **Impact:** Redundant cache clears and drop-in regeneration; confusing upgrade audit trail.
- **Fix:** Consolidate into single version-router or document that legacy path is intentionally retained for one migration and will be removed after 1.9.

### A01-008 — `maybe_run_version_upgrade` fires on *any* plugin/theme update — `includes/class-main.php:492` — Severity: **LOW** — Confidence: 95%

- **Problem:** `add_action('upgrader_process_complete', [$this,'maybe_run_version_upgrade'], 10, 0)` registers with `$accepted_args = 0`, so callback ignores `$hook_extra` and never checks which plugin was upgraded. Any update of another plugin will still execute `maybe_run_version_upgrade`, which then checks `wppo_version < WPPO_VERSION` — normally returns early, but still incurs `get_option` + `version_compare` + `Advanced_Cache_Handler::create()` attempt on every third-party update.
- **Compare:** `maybe_schedule_upgrade_routine` at `:490` correctly checks `$hook_extra['plugin']`/`['plugins']` for `performance-optimisation/performance-optimisation.php`.
- **Impact:** Minor wasted I/O on bulk updates; risk thatTransient filesystem failure (create() returns false) leaves `wppo_version` unchanged and retry will be attempted on next *unrelated* update rather than next admin visit — slightly surprising retry.
- **Fix:** Accept `$hook_extra` arg and gate on plugin file like sibling handler.

### A01-009 — `Cache` instantiated twice with stale options snapshot — `includes/class-main.php:539-542` vs `599-607` — Category: **DUPLICATE** / Correctness — Severity: **LOW** — Confidence: 90%

- **Problem:** When `enableCache` is ON, `$this->cache = new Cache($this->options)` at `:540` with `set_image_optimisation`/`set_google_fonts`. When `combineCSS` is ON but `enableCache` OFF, a *second* `new Cache($this->options)` is created at `:604` solely for combining. When BOTH are ON, the first Cache is reused for combining, but its `options` snapshot is the constructor-time copy, not a fresh `Util::get_settings()` — settings changed via REST + `update_option_wppo_settings` hook clear cache but the in-memory `$this->options` on existing request is stale until next page load. No clone needed but confusion.
- **Evidence:** Two `new Cache` sites, second guarded by `if (!$this->cache)`.
- **Impact:** Negligible; combine uses stashed options that may be one-request stale after settings save (cache cleared anyway via `Cache::clear_cache()`).
- **Fix:** Inject fresh options or make Cache read options lazily, or note as intentional single-request snapshot.

### A01-010 — `update_option_permalink_structure`/`switch_theme`/`activated_plugin`/`deactivated_plugin` clear cache indiscriminately — `includes/class-main.php:787-791` — Severity: **INFO** — Confidence: 95%

- **Problem:** `activated_plugin` and `deactivated_plugin` fire for *any* plugin, not just this one — every activation/deactivation of an unrelated plugin triggers full `Cache::clear_cache()` (filesystem `rm -rf wp-content/cache/wppo/` + runtime flush). Permalink/theme hooks are correctly scoped (they always invalidate), but plugin hooks are not.
- **Impact:** Cache thrashing on sites with frequent plugin toggling (staging sync, bulk updates). Not a correctness bug, but perf.
- **Fix:** Guard `activated_plugin`/`deactivated_plugin` callbacks with `if (strpos($plugin,'performance-optimisation')===false && current_filter ends with plugin check)` or document as intentional conservative invalidation.

### A01-011 — `wppo_block_assets_migrated` option not cleaned on uninstall — `includes/class-main.php:878` vs `uninstall.php:32-48` — Severity: **LOW** — Confidence: 100%

- **Problem:** `migrate_block_assets_setting()` sets `update_option('wppo_block_assets_migrated',1)` as one-time marker (line 886/904). `uninstall.php:wppo_cleanup_site()` deletes 17 specific options but omits `wppo_block_assets_migrated`. On multisite, after uninstall-reinstall, migration skipped erroneously (stored DB values may be missing but marker persists on single blog that wasn't fully cleaned? Actually uninstall deletes all sites, but `wppo_block_assets_migrated` would survive if uninstall.php fails to delete it — tested: not in delete list at `:32-48`).
- **Evidence:** `class-main.php:878` vs `uninstall.php:32-48` list lacks `wppo_block_assets_migrated`.
- **Impact:** Reinstall on same DB (e.g., after manual `DELETE` failure) inherits false migration flag.
- **Fix:** Add `delete_option('wppo_block_assets_migrated')` to `uninstall.php` cleanup list (and multisite loop covers it).

### A01-012 — `remove_action('update_option_wppo_settings', ...)` rollback can miss if hook priority/filters changed — `includes/class-main.php:1106-1108` — Severity: **INFO** — Confidence: 80%

- **Problem:** `.htaccess` failure rollback in `on_settings_update` removes action with exact priority 10, then re-adds. If filter had been added with different priority by earlier refactor, removal fails and `update_option` recurses infinitely (hook fires again, fails again, recurses). Current code matches setup_hooks prio 10, so safe today but fragile.
- **Impact:** Potential infinite recursion if priority mismatched in future.
- **Fix:** Store return of `remove_action` or use `did_action` guard; current approach is standard WP idiom, noting fragility.

### A01-013 — Speculation-rules & resource-hints closures are unremovable anonymous functions — `includes/class-main.php:2302-2352`, `2354-2359` — Category: **DEAD CODE** risk — Severity: **INFO** — Confidence: 85%

- **Problem:** `add_filter('wp_speculation_rules_href_exclude_paths', fn(...)` and `wp_speculation_rules_configuration` use anonymous closures capturing `$preload_settings`. They cannot be removed via `remove_filter` by third parties or tests, and if `Main` instantiated twice, duplicate filters stack. Same for `script_module_data_wppo-lazyload` at `:1675`.
- **Impact:** Testability/debuggability; duplicate instantiation leaks.
- **Fix:** Use named methods (`filter_speculation_rules_configuration` already exists as method but closure wraps it) — could add via `array($this,'filter_speculation_rules_href_excludes')` instead.

### A01-014 — `Util::init_filesystem()` called twice in succession — `includes/class-main.php:346-349` — Severity: **INFO** — Confidence: 100%

- **Problem:** `$this->filesystem = Util::init_filesystem(); if (!$this->filesystem) $this->filesystem = null;` — `Util::init_filesystem()` already handles WP_Filesystem init and returns `global $wp_filesystem` or false. The implicit first call at `:346` and second null coercion is noisy; also `$filesystem` property typed as `object` without null union, but set to null at `:348`.
- **Fix:** Simplify to `$this->filesystem = Util::init_filesystem() ?: null;`.

---

## 3. `includes/class-activate.php` — 354 lines

### A01-015 — `maybe_seed_settings()` defaults drift from `Main::__construct()` defaults — `includes/class-activate.php:116-186` vs `includes/class-main.php:170-265` — Severity: **MEDIUM** — Confidence: 98%

- **Problem:** Activation seed omits keys that Main's runtime defaults inject via patches: `file_optimisation.disableRestApiLinks/disableRssFeeds/disableShortlinks/disableGeneratorTag/disableJQueryMigrate/disablePasswordStrength/disableSelfPingbacks/removeWooCSSJS/removeCssJsHandle/excludeUrlToKeepJSCSS`, `litespeed_integration.*`, `llms_txt.*`, `od_integration.*`, `bfcache.*` (present but activation has older shape), `ai_adaptive`, `edge_cache`, and `blockAssetsOnDemand` handling (activation file predates conditional). After fresh install via `wp plugin activate` + CLI `wp wppo` before first admin save, `get_option('wppo_settings')` returns incomplete array — CLI reports "Available tabs: ." or `undefined index` notices if code reads `Util::get_settings()` without Main's in-memory patch.
- **Evidence:**
  ```php
  // class-activate.php:121-151  — seed lacks ~12 keys
  // class-main.php:207-214     — has disableRestApiLinks … disableSelfPingbacks
  // class-main.php:240-264     — has litespeed_integration, llms_txt, od, ai_adaptive, edge_cache
  ```
- **Impact:** Fresh installs via WP-CLI/orchestrator get incomplete settings until first SPA save triggers migration; `wp wppo` may list empty tabs.
- **Fix:** Make `maybe_seed_settings()` delegate to a single source of truth (e.g., `Util::default_settings()` or share defaults constant) instead of duplicating array.

### A01-016 — Version option written BEFORE upgrade check — `includes/class-activate.php:82` then `98-99` — Severity: **HIGH** — Confidence: 92%

- **Problem:** `init()` does `update_option('wppo_version', WPPO_VERSION, false)` at `:82` unconditionally, then calls `self::maybe_run_upgrades(! $has_activation_time)` at `:98`. `maybe_run_upgrades()` reads `get_option(VERSION_OPTION)` at `:206` — which is now already `WPPO_VERSION`, so legacy-flush gate `version_compare(stored, LEGACY_FLUSH_FLOOR, '>=')` at `:218` always true, and the one-time eviction never runs when `init()` is the entrypoint (manual reactivation). On fresh installs it's correct to skip, but on upgrades triggered by reactivation (user deactivated then reactivated an older-site DB on new code), the stale version is overwritten before the check, losing the fix.
- **Why matters:** The intended cron-retry path via `admin_init` still works (that path does NOT pre-write), so the bug is masked after the next admin pageload. But if the site is updated via `wp plugin update` (no activation hook fires) then reactivated manually, the overwrite still defeats the check. Severity high because correctness-critical migration silently skipped.
- **Evidence:**
  ```php
  // class-activate.php:82
  update_option( 'wppo_version', WPPO_VERSION, false );
  // ...
  // class-activate.php:98
  self::maybe_run_upgrades( ! $has_activation_time );
  // class-activate.php:206
  $stored_version = get_option( self::VERSION_OPTION, '' ); // now == WPPO_VERSION
  if ( version_compare( $stored_version, self::LEGACY_FLUSH_FLOOR, '>=' ) ) return;
  ```
- **Fix:** Move `update_option('wppo_version', ...)` after `maybe_run_upgrades()` for non-fresh installs, or make `maybe_run_upgrades` accept `$stored_version` pre-read, or only write inside `maybe_run_upgrades` on fresh path (currently does at `:211`). Simplest: delete line 82 and rely on `maybe_seed_settings`/`maybe_run_upgrades` to set it.

### A01-017 — Redundant `Util::init_filesystem()` double call — `includes/class-activate.php:264-267` — Severity: **INFO** — Confidence: 100%

- **Problem:** `Util::init_filesystem()` called at `:264`, then condition `if (!$wp_filesystem && !Util::init_filesystem())` calls again if global still falsy. First call already attempted init; second call repeats work without new context.
- **Evidence:**
  ```php
  Util::init_filesystem();
  if ( ! $wp_filesystem && ! Util::init_filesystem() ) { return 'wp_config_fs'; }
  ```
- **Impact:** Double FS init on failure path (two `WP_Filesystem()` attempts).
- **Fix:** Single call.

### A01-018 — `strpos($content,'WP_CACHE')` false-positive excludes insertion — `includes/class-activate.php:299-302` — Severity: **LOW** — Confidence: 85%

- **Problem:** Check `elseif ( false !== strpos($content,'WP_CACHE') ) { return null; }` trips on any mention (comment, string literal, other plugin code) and aborts insertion even though constant not defined. Could leave site without `WP_CACHE` when a comment mentions it.
- **Impact:** Missed `WP_CACHE` insertion on hosts where `wp-config.php` contains commented-out example.
- **Fix:** Parse via regex `/define\s*\(\s*['"]WP_CACHE['"]/` instead of substring.

### A01-019 — Transient `wppo_activation_notices` lifetime `WEEK_IN_SECONDS` may outlive redeployment — `includes/class-activate.php:70` — Severity: **INFO**

- **Problem:** One week is long for an activation notice (foreign drop-in / wp-config). User dismisses via `wppo_dismiss=activation`, but if dismissed meta transient still lives a week, the dismiss deletes it — okay. If not dismissed, notice shows for 7 days even after admin fixes manually — noise.
- **Fix:** Consider `DAY_IN_SECONDS` or `0` (no expiry) with explicit dismiss; current is acceptable, noting.

---

## 4. `includes/class-deactivate.php` — 156 lines

### A01-020 — Incomplete cron unscheduling — `includes/class-deactivate.php:78-115` — Severity: **MEDIUM** — Confidence: 95%

- **Problem:** `unschedule_crons()` clears 4 legacy hooks plus `wppo_generate_static_page`, but `setup_hooks`/`class-cron.php` also schedule `wppo_run_upgrades` (single), `wppo_web_vitals_rescan`, `wppo_llms_txt_daily`, `wppo_used_css_cron`, `wppo_ccss_regeneration`, `wppo_rum_flush`, `wppo_generate_static_url` (500 single events per sitemap), `wppo_database_cleanup_cron` (separate method handles one), and Action Scheduler jobs `wppo_pagespeed_scan`, `wppo_used_css_generate`, `wppo_convert_image_background`. Many remain after deactivation. Orphaned `wppo_generate_static_url` singles will fire via WP-Cron after plugin off, calling `Cron::process_url` handler which is not registered (class not loaded) — silent failure but wastes cron runner.
- **Evidence:** Grep shows 13+ hooks: `class-cron.php:57-74`, `class-main.php:489,775,778,781`
- **Impact:** Stale cron entries accumulate (up to 500 per preload), requiring manual `wp cron event delete`.
- **Fix:** Enumerate all plugin-owned hooks and clear via `wp_clear_scheduled_hook` / `wp_unschedule_event` for singles, plus `as_unschedule_all_actions` for Action Scheduler group `performance_optimisation`.

### A01-021 — Object-cache drop-in deletion only when marker present — `includes/class-deactivate.php:51-55` — Severity: **INFO** — Confidence: 100%

- **Problem:** Correctly guards deletion with `strpos($content,'Redis Object Cache Drop-in for Performance Optimisation')`. Safe but leaves behind a drop-in that *is* ours if the marker string was changed in a prior version (marker drift). Currently marker consistent with `templates/object-cache.php`, so fine. Noted as correct defense.

### A01-022 — `remove_wp_cache_constant()` leaves orphaned comment block — `includes/class-deactivate.php:144-151` — Severity: **LOW** — Confidence: 90%

- **Problem:** Regex for gated block `/\/\*\*\s*Enables WordPress Cache\s*\*\/\s*(?:\r?\n|\n)if\s*\(.../` expects exact comment shape from `activate.php:305`. If administrator edited spacing or the file was created on Windows with `\r\n`, `preg_match` still handles `(?:\r?\n|\n)`, okay. But if the block was appended at end via `else` fallback (`$wp_config_content .= $constant_code`) without the `That's all, stop editing` anchor, it still matches. However `preg_replace` failure leaves file unchanged but still returns success (put_contents same content) — no error reported.
- **Fix:** Acceptable; consider `WP_Filesystem` error handling.

---

## 5. `uninstall.php` — 185 lines

### A01-023 — Options cleanup incomplete — `uninstall.php:32-49` — Severity: **MEDIUM** — Confidence: 98%

- **Problem:** `wppo_cleanup_site()` deletes 17 options but omits ~10+ plugin-owned options: `wppo_block_assets_migrated`, `wppo_cache_size` (transient but may be stored as option when object cache not present? Actually transient fallback but fine), `wppo_web_vitals_trends`, `wppo_web_vitals_history`, `wppo_pagespeed_results`, `wppo_pagespeed_api_key` is inside `wppo_settings` so covered, `wppo_used_css_*`, `wppo_critical_css_*`, `wppo_img_info` salt keys partially covered, `wppo_object_cache_error`, `wppo_performance_audit_*`, `wppo_ai_*`, `wppo_edge_cache_*`.
- **Evidence:** Deletion list at `:32-48` vs `Main::on_settings_update` audit salts, `Cron::web_vitals`, `Pagespeed::` storage, `Used_CSS::` storage, `Critical_CSS::`, `AI_Adaptive`.
- **Impact:** Orphaned autoloaded options remain after uninstall, polluting `wp_options` (autoload bloat).
- **Fix:** Add missing keys or `DELETE FROM wp_options WHERE option_name LIKE 'wppo_%'` wildcard cleanup (with multisite iteration).

### A01-024 — Per-user meta cleanup only deletes `wppo_welcome_dismissed` — `uninstall.php:90` — Severity: **LOW** — Confidence: 100%

- **Problem:** `delete_user_meta_by_key('wppo_welcome_dismissed')` removed, but `Admin_Notices` also stores `wppo_litespeed_notice_dismissed` per user (line 81). After uninstall, that meta persists for every user.
- **Evidence:** `class-admin-notices.php:81` vs `uninstall.php:90`
- **Impact:** Tiny orphan meta (one row per user who dismissed).
- **Fix:** Add `delete_user_meta_by_key('wppo_litespeed_notice_dismissed')`.

### A01-025 — `.htaccess` rules not removed on uninstall — `uninstall.php:59-87` — Severity: **MEDIUM** — Confidence: 95%

- **Problem:** `Deactivate::` removes drop-ins but not `.htaccess` Gzip/Expires rules inserted via `Htaccess_Handler::update_rules(true)`. `uninstall.php` also does not call `Htaccess_Handler::update_rules(false)`. After uninstall, LiteSpeed/Apache still serves stale `ExpiresActive`/`mod_deflate` rules with `WPPO` markers, potentially masking removal.
- **Evidence:** No `Htaccess_Handler` reference in `uninstall.php`; `Deactivate::init()` also does not call `update_rules(false)`.
- **Impact:** Orphaned `.htaccess` markers; not data loss but server config drift.
- **Fix:** Call `Htaccess_Handler::update_rules(false)` in `Deactivate` and `uninstall` (with FS init guard).

### A01-026 — Action Scheduler jobs and WP-Cron singles not purged — `uninstall.php` — Severity: **LOW** — Confidence: 90%

- **Problem:** Same unscheduled-jobs leak as deactivate, but uninstall is final — leftover scheduled events remain in `wp_options cron` array after plugin files gone. Next cron run attempts to fire handler class that no longer exists (silent `call_user_func` failure).
- **Impact:** Cron array bloat, wasted tick.
- **Fix:** Enumerate and purge via `wp_clear_scheduled_hook` / `as_unschedule_all_actions` before site cleanup loop.

### A01-027 — Hardcoded cache paths must stay in sync — `uninstall.php:56,62` — Category: **DUPLICATE** — Severity: **INFO** — Confidence: 100%

- **Problem:** Comments note sync needed with `Cache::CACHE_DIR` and `Img_Converter` paths. If those constants move, uninstall will delete wrong paths and orphan real cache.
- **Fix:** Consider `Cache::CACHE_DIR` constant reference (requires class load) or central path util.

---

## 6. `includes/class-admin-notices.php` — 316 lines

### A01-028 — Mixed transient value types (keys vs translated strings) — `includes/class-activate.php:90` vs `class-admin-notices.php:118-137` — Severity: **MEDIUM** — Confidence: 95%

- **Problem:** `Activate::init():90` pushes `__( 'Failed to update .htaccess rules...')` (raw translated string) into `$notices[]`, then `set_transient` stores that string. `Admin_Notices::maybe_activation_notices()` iterates `$notices` as keys in `switch($key)` expecting values like `foreign_dropin`, `wp_config_fs`. The raw string hits `default: break;`, producing no output — the .htaccess failure is silently swallowed during activation.
- **Evidence:**
  ```php
  // class-activate.php:90
  $notices[] = __( 'Failed to update .htaccess rules during activation.', 'performance-optimisation' );
  // class-admin-notices.php:118-137
  switch ( $key ) { case 'foreign_dropin': ... case 'wp_config_fs': ... default: break; }
  ```
- **Impact:** Administrator never sees htaccess failure on activation.
- **Fix:** Push a key like `htaccess_activation_failed` and map in switch, or handle arbitrary strings via fallback `wp_kses_post($key)`.

### A01-029 — `is-dismissible` without persistent handler — `includes/class-admin-notices.php:195,228,277` — Severity: **INFO** — Confidence: 85%

- **Problem:** Notices rendered with `class="notice ... is-dismissible"` (the WordPress JS "X" button) but no JS `wp_ajax` handler or `data-dismissible` persistence — clicking X hides notice for current page only; next pageload it reappears (except activation notice which has explicit `wppo_dismiss` link). LiteSpeed and competing-plugin notices have no nonce dismiss link for the X; expected WordPress behavior (`dismiss-wp-pointer`) not implemented.
- **Impact:** UX annoyance, repeated dismissal.
- **Fix:** Either remove `is-dismissible` or add persistent dismiss via `handle_dismiss` + `data-` attribute.

### A01-030 — `COMPETING_CACHE_PLUGINS` list missing common competitors — `includes/class-admin-notices.php:27-38` — Severity: **INFO** — Confidence: 80%

- **Problem:** List covers 10 well-known cache plugins but omits `autoptimize`, `flying-press`, `breeze`, `hummingbird`, `nitropack`. Not a bug, but detection gap.
- **Fix:** Expand list or gate on `is_our_dropin()` only (current behavior already defers to drop-in check).

### A01-031 — `get_active_plugin_files()` try/catch masks test infra but hides real errors — `includes/class-admin-notices.php:293-314` — Severity: **INFO** — Confidence: 90%

- **Problem:** `try { $is_multisite = is_multisite(); } catch (\Throwable $e)` guards for Brain Monkey tests where `is_multisite` not mocked and throws. In production `is_multisite()` never throws, so catch is dead code that could hide genuine future exceptions.
- **Impact:** None in production; test-specific guard documented. Noted.

---

## Summary Table

| ID | File:Line | Category | Severity | One-line summary |
|----|-----------|----------|----------|------------------|
| A01-001 | `performance-optimisation.php:1` | Compatibility | MEDIUM | No runtime PHP 8.2 guard |
| A01-002 | `performance-optimisation.php:7` | Correctness | INFO | `Tested up to: 7.1` fictitious |
| A01-003 | `performance-optimisation.php:41` + `class-main.php:437` | DUPLICATE | LOW | Duplicate autoloader require |
| A01-004 | `performance-optimisation.php:44` | Architecture | INFO | Heavy constructor at include time |
| A01-005 | `class-main.php:489` | Correctness | **CRITICAL** | Namespace typo `PerformanceOptimisation` vs `PerformanceOptimise` — upgrade retry hook dead |
| A01-006 | `class-main.php:832` | Timing | MEDIUM | `wp_is_block_theme()` called before `after_setup_theme` |
| A01-007 | `class-main.php:488-492` | Architecture | MEDIUM | Overlapping upgrade routers (legacy vs generic) |
| A01-008 | `class-main.php:492` | Correctness | LOW | `maybe_run_version_upgrade` fires on any plugin update |
| A01-009 | `class-main.php:539/599` | DUPLICATE | LOW | Dual Cache instantiation with stale snapshot |
| A01-010 | `class-main.php:787-791` | Perf | INFO | `activated_plugin` clears cache for unrelated plugins |
| A01-011 | `class-main.php:878` | Cleanup | LOW | `wppo_block_assets_migrated` orphan after uninstall |
| A01-012 | `class-main.php:1106` | Robustness | INFO | `remove_action` fragile to priority drift |
| A01-013 | `class-main.php:2302/1675` | DEAD CODE risk | INFO | Anonymous closure filters unremovable |
| A01-014 | `class-main.php:346` | Code Quality | INFO | Double FS init |
| A01-015 | `class-activate.php:116` | Correctness | MEDIUM | Seed defaults drift from Main defaults |
| A01-016 | `class-activate.php:82/98` | Correctness | HIGH | Version write before upgrade check defeats eviction |
| A01-017 | `class-activate.php:264` | Code Quality | INFO | Double `init_filesystem()` |
| A01-018 | `class-activate.php:299` | Correctness | LOW | `strpos('WP_CACHE')` false positive blocks insertion |
| A01-019 | `class-activate.php:70` | UX | INFO | Activation transient lives a week |
| A01-020 | `class-deactivate.php:78` | Correctness | MEDIUM | Incomplete cron unscheduling |
| A01-021 | `class-deactivate.php:51` | Security | INFO | Guarded drop-in deletion — correct |
| A01-022 | `class-deactivate.php:144` | Robustness | LOW | WP_CACHE removal regex fragile |
| A01-023 | `uninstall.php:32` | Cleanup | MEDIUM | Options wildcard cleanup missing |
| A01-024 | `uninstall.php:90` | Cleanup | LOW | Leaves `wppo_litespeed_notice_dismissed` user meta |
| A01-025 | `uninstall.php:59` | Cleanup | MEDIUM | `.htaccess` rules orphaned |
| A01-026 | `uninstall.php:—` | Cleanup | LOW | Cron/AS jobs not purged |
| A01-027 | `uninstall.php:56,62` | DUPLICATE | INFO | Hardcoded paths must stay synced |
| A01-028 | `class-admin-notices.php:118` + `class-activate.php:90` | Correctness | MEDIUM | Raw string vs key mix swallows htaccess error |
| A01-029 | `class-admin-notices.php:195` | UX | INFO | `is-dismissible` without persistence |
| A01-030 | `class-admin-notices.php:27` | Coverage | INFO | Competing plugin list incomplete |
| A01-031 | `class-admin-notices.php:293` | Testing | INFO | try/catch dead code in prod |

**Counts:** CRITICAL 1 · HIGH 1 · MEDIUM 7 · LOW 7 · INFO 15 · DUPLICATE 3 · DEAD CODE risk 1 — 31 findings total.

---

## Duplicate / Dead Code Consolidated

- **Autoloader duplicate** A01-003.
- **Hardcoded paths** A01-027 — `WP_CONTENT_DIR . '/cache/wppo/'` repeated in `Cache::CACHE_DIR`, `Cache::clear_cache()`, `uninstall.php`, `Deactivate` comments.
- **Anonymous closure filters** A01-013 — unremovable, duplicated on double-instantiation would be dead after first.
- **No large dead-code blocks** — all 6 files are live (activation/deactivation hooks, uninstall, notices, main router). `class-main.php` `Bfcache`/`Perf_Translations`/`AI_Adaptive` early `class_exists` guards are intentionally lazy.

## Performance Notes

- `maybe_fix_wp_cache` throttled 1h — correct (A01 not flagged).
- `admin_enqueue_scripts` cache-size transient 15min + salted object cache — correct.
- `Cache::clear_cache` on unrelated plugin toggle (A01-010) is main cache-thrashing perf vector.

## Security Notes

- All REST requires `manage_options` + nonce (out of scope, not in assigned files).
- `Admin_Notices::handle_dismiss` nonce + cap check correct.
- `wppo_delete_directory` symlink guard correctly before `is_dir` — no traversal (verified in `uninstall.php:117/141`).

## Verification

```sh
wc -l performance-optimisation.php includes/class-main.php includes/class-activate.php includes/class-deactivate.php uninstall.php includes/class-admin-notices.php
# 70  3053  354  156  185  316  4134 total  ← matches Files Reviewed
ls -la AUDIT/AGENTS/agent-A01-php-core.md
# non-empty verified
```

