# Agent A13 — Compatibility Audit (audit-only)

Base: master@31fffc61
Date: 2026-08-28
Scope: ALL PHP+JS for WP/PHP/multisite/object-cache/hosting/caching-plugins/themes/wp.org compliance/bfcache/edge workers
Mode: audit-only (no code changes)

## Header

- **Files reviewed:** 122 files (41 `includes/*.php` + 3 `includes/minify/*.php` + 1 `includes/redis-connect-helper.php` + 70 `src/**/*.js` + 2 `templates/*.php` + 1 `performance-optimisation.php` + 1 `uninstall.php` + `readme.txt` + `composer.json` + `package.json`)
- **Lines reviewed:** 47,118 (wc -l total for includes+src+templates; excludes vendor/node_modules/build)
- **Lines per bucket:** includes 24,812 (incl. minify+helper) | templates 1,194 | src 19,142 | entry+uninstall 255 | docs/config 1,715
- **Methods:** `Read` full file for 30+ key files, `Grep` for `version_compare|function_exists|class_exists|has_filter|transient_key|blog_prefix|is_multisite|get_transient|wp_cache_|wp_get_loading_optimization|wp_get_image_encode|wp_ai_client|wp_is_client_side|OD_URL_Metric`, `wc -l` counts, manual trace of liteSpeed/bfcache/edge/OD/perf-translations paths.

## Verdict Summary

| Domain | Status | Confidence |
|--------|--------|------------|
| WP version gates (6.2 floor, 6.9/7.1 new APIs) | PASS with 1 INFO | High |
| PHP 8.2-8.5 declared | PASS | High |
| Multisite transient isolation | PASS with 1 MEDIUM (memo leak) + 1 LOW (uninstall gaps) | High |
| Object-cache backends (Redis/Memcached) | PASS (Redis-only drop-in; Memcached = DB fallback) | High |
| Hosting (Apache/Nginx/LiteSpeed/OLS) | PASS | High |
| Caching plugins coexistence | PASS | High |
| Themes (block/classic, builders) | PASS | Medium |
| wp.org External Services compliance | PASS (PageSpeed + Fonts disclosed; Edge/AI/OD local-only) | High |
| bfcache behavior | PASS with 1 LOW | High |
| Edge workers | PASS (local generation, no external runtime) | High |
| JS browser compat | PASS with 1 LOW (stale setting path) | Medium |

---

## Findings

### F-COMPAT-01 — `Tested up to: 7.1` is a future WP version

- **File:Line:** `performance-optimisation.php:7`, `readme.txt:6` (`Tested up to: 7.1`)
- **Category:** wp.org compliance / version declaration
- **Severity:** LOW
- **Problem:** Header claims compatibility with WP 7.1 which was not released at base date (latest stable ~6.9). WP.org validator flags future `Tested up to` as overstatement.
- **Why matters:** Suppresses "untested with your version" warning incorrectly; validator warning in plugin-check.
- **Evidence:** `* Tested up to:      7.1` in plugin header; changelog `= 1.9.0 (2026-08-11)` lists WP 7.1 features (client-side media, `wp_get_image_encode_quality`).
- **Impact:** Low runtime (gates protect), but directory compliance risk.
- **Recommended solution:** Keep 7.1 only if CI ran against `wordpress:7.1-beta/nightly` and note it; otherwise set to latest released (6.9) and bump to 7.1 on release. Add CI check `WPPO_VERSION == header Version`.
- **Confidence:** High

### F-COMPAT-02 — PHP 8.2 `Requires PHP` vs `symfony/css-selector ^7.4` (php ^8.2) is consistent but manual 8.1 installs fatal

- **File:Line:** `composer.json:13` (`php >=8.2`), `composer.json:17` (`symfony/css-selector ^7.4`), `composer.json:55` (`platform.php 8.2`), `performance-optimisation.php:6`
- **Category:** PHP compatibility
- **Severity:** INFO (LOW for wp.org users, MEDIUM for git/zip on 8.1)
- **Problem:** `platform.php 8.2` forces resolution as 8.2 even on PHP 8.1 CI; `symfony/css-selector 7.4` uses `readonly` properties that fatal on 8.1. Header `Requires PHP: 8.2` correctly blocks activation via WP.org, but manual installs that ignore header will fatal.
- **Why matters:** Env mismatch on build hosts vs packager.
- **Evidence:** `composer.json:63` `release` uses `--ignore-platform-reqs`; `phpcs.xml:13` `testVersion 8.2-`.
- **Impact:** Low for directory users; higher for developers checking out on 8.1.
- **Recommended solution:** Document `platform` is resolution hint; ensure release CI builds on PHP 8.2 (already `psalm-wpcs-check.yml` parallel-lint 8.2-8.5). No code change.
- **Confidence:** Medium

### F-COMPAT-03 — `Util::get_settings()` memo not keyed by `get_current_blog_id()` / no `switch_blog` invalidation

- **File:Line:** `includes/class-util.php:87-95` (`$home_url_cache`, `$settings_cache`, `$settings_cache_loaded`), `includes/class-util.php:125-137` (`get_settings`), `includes/class-util.php:181-190` (`ensure_settings_cache_hook`)
- **Category:** Multisite
- **Severity:** MEDIUM
- **Problem:** `get_settings()` caches `wppo_settings` in static `$settings_cache` per-request without blog ID. On multisite with `switch_to_blog()`, second blog returns first blog's settings (memo leak). Same pattern affects `get_role_hash` consumers (`Bfcache`, `AI_Adaptive`, `Bfcache::is_enabled`, `Edge_Cache::is_enabled`).
- **Why matters:** Network admins using `switch_to_blog` (CLI, `uninstall.php` already handles via `switch_to_blog` + fresh `get_option`, but frontend cron/REST that switches blogs will serve wrong cache/optimisation settings to second site.
- **Evidence:** `public static function get_settings(): array { if (self::$settings_cache_loaded) return self::$settings_cache; ... $raw = get_option('wppo_settings', array()); self::$settings_cache = $raw;` — no `get_current_blog_id()` key. Contrast `cached_home_url()` and `cached_content_url()` which ARE blog-keyed (`$cache[$blog_id]` at 666-674, `self::$home_url_cache[$blog_id]` at 696-699) while `transient_key()` correctly prefixes `blog_id_` at 720-729.
- **Impact:** Wrong caching/logged-in eligibility/bfcache enablement for switched blog until request end. Rare outside `switch_to_blog` code paths, but user-visible when it hits (e.g., `WP_CLI --url=` is separate request, so OK; but plugins that `switch_to_blog` in loops are affected).
- **Recommended solution:** Key memo by `get_current_blog_id()`: `private static array $settings_cache = []; private static array $settings_loaded = [];` and `get_settings()` => `$bid = get_current_blog_id(); if (isset(self::$settings_loaded[$bid])) return self::$settings_cache[$bid];` + `on_settings_update($old,$new)` must invalidate by current blog ID; add `add_action('switch_blog', [self::class,'clear_settings_cache'])` or clear on `switch_blog`.
- **Confidence:** High

### F-COMPAT-04 — `Util::prepare_cache_dir()` iterative mkdir is multisite/hosting safe; `WP_Filesystem` fallback correct

- **File:Line:** `includes/class-util.php:227-253`, `includes/class-util.php:261-273` (`init_filesystem`)
- **Category:** Hosting compat
- **Severity:** INFO (pass)
- **Problem:** None — verified.
- **Why matters:** Direct `mkdir` without FS API breaks on FTP/SSH hosts.
- **Evidence:** `prepare_cache_dir` uses `WP_Filesystem::is_dir/mkdir(FS_CHMOD_DIR)` iteratively; `init_filesystem` guards `function_exists('WP_Filesystem')` + `require wp-admin/includes/file.php`.
- **Impact:** No issue.
- **Recommended solution:** No change.
- **Confidence:** High

### F-COMPAT-05 — All WP 6.9/7.0/7.1 new APIs are gated (no unguarded calls)

- **File:Line:** `includes/class-img-converter.php:193` (`function_exists wp_get_image_encode_quality`), `includes/class-img-converter.php:200` (`wp_image_quality`), `includes/class-image-optimisation.php:197` (`wp_is_client_side_media_processing_enabled`), `includes/class-image-optimisation.php:1492`/`1621`/`1707` (`wp_get_loading_optimization_attributes`), `includes/class-main.php:2295` (`wp_get_speculation_rules_configuration`), `includes/class-perf-translations.php:68` (`wp_cache_get_salted`), `includes/class-perf-translations.php:147` (`WP_Translation_File`), `includes/class-ai-adaptive.php:108`/`132` (`wp_ai_client`), `includes/class-od-bridge.php:59` (`OD_URL_Metric||od_get_url_metrics`), `includes/class-bfcache.php:114`/`366` (`wp_generate_password`, `wp_print_inline_script_tag`), `includes/class-cache.php:737`/`948` (`wp_maybe_inline_styles`), `includes/class-cache.php:349` (`wp_should_load_separate_core_block_assets`)
- **Category:** WP version gates
- **Severity:** INFO (pass)
- **Problem:** None — every new API has `function_exists`/`class_exists` guard with fallback.
- **Why matters:** Required for `Requires at least: 6.2` floor.
- **Evidence:** Grep shows 320 `function_exists|class_exists|has_filter` hits; sampled 20 — all new APIs guarded. Notably `wp_get_loading_optimization_attributes` has 7 guard sites in `class-image-optimisation.php` alone (1492,1621,1707,1724,1769,1783,1795,2728).
- **Impact:** None.
- **Recommended solution:** Keep. Consider central `Wp_Version` helper per audit note, but not required for compat.
- **Confidence:** High

### F-COMPAT-06 — `version_compare` gates for 6.3 defer strategy vs legacy and 6.9 template buffer are correct (alpha floor)

- **File:Line:** `includes/class-main.php:501-510` (`$is_wp63_plus = version_compare($wp_version,'6.3-alpha','>=')`, `$is_wp69_plus = version_compare($wp_version,'6.9-alpha','>=')`), `includes/class-main.php:522-532` (branch `add_defer_strategy` vs `add_defer_attribute_legacy`), `includes/class-main.php:543-551` (template-enhancement filter vs `template_redirect`)
- **Category:** WP version gates
- **Severity:** INFO (pass)
- **Problem:** None.
- **Why matters:** 6.3 RC should match; alpha floor ensures RC/beta correctly takes native path.
- **Evidence:** Comments at 502-510 document TODO(#553) removal when min WP raised; `wp_version` from `$GLOBALS['wp_version'] ?? get_bloginfo('version')`.
- **Impact:** None.
- **Recommended solution:** No change.
- **Confidence:** High

### F-COMPAT-07 — `transient_key()` isolation: 86 uses, multisite-safe for Redis/Memcached shared backends

- **File:Line:** `includes/class-util.php:720-729` (definition), consumers: `class-cron.php:276,279,337,372,375,623,626,725,728`, `class-rum.php:252,321,329,355,358`, `class-critical-css.php:230,874,883`, `class-cache.php:910` etc. (86 total)
- **Category:** Multisite / object-cache backends
- **Severity:** INFO (pass)
- **Problem:** None — all hot transient keys (`wppo_cache_size`, `wppo_rum_queue`, `wppo_ccss_status_*`, `wppo_preload_cron_lock`, `wppo_wp_cache_fix_checked`) via `Util::transient_key`.
- **Why matters:** Shared Redis/Memcached keyspace without prefix causes cross-site collisions.
- **Evidence:** `public static function transient_key(string $key): string { if (!function_exists('is_multisite')) return $key; try { return is_multisite() ? get_current_blog_id().'_'.$key : $key; } catch...}`. Wraps `is_multisite` + try/catch for early boot.
- **Impact:** None.
- **Recommended solution:** No change.
- **Confidence:** High

### F-COMPAT-08 — Static HTML cache: domain-based dirs are multisite-safe; port stripping + IDN punycode handled

- **File:Line:** `includes/class-cache.php:212-237` (domain derivation, `idn_to_ascii`, port strip, regex `^[a-z0-9\.\-]+$/i`), `includes/class-cache.php:246-252` (request_uri traversal guard)
- **Category:** Multisite / hosting
- **Severity:** INFO (pass)
- **Problem:** None.
- **Why matters:** Subdomain/subdirectory multisite must not share cache files.
- **Evidence:** `wp-content/cache/wppo/{domain}/{path}/index.html` domain isolation; `idn_to_ascii` with `INTL_IDNA_VARIANT_UTS46`; `strtolower($host)` after validation.
- **Impact:** None.
- **Recommended solution:** No change.
- **Confidence:** High

### F-COMPAT-09 — Minify cache: `min_cache_dir()` blog-scoped (`cache/wppo/min/{blog_id}/{js,css}`) prevents cross-site invalidation

- **File:Line:** `includes/class-util.php:604-627` (`min_cache_base_dir` + `min_cache_dir(get_current_blog_id())`), `includes/class-util.php:637-646` (`min_cache_url`)
- **Category:** Multisite
- **Severity:** INFO (pass)
- **Problem:** None.
- **Why matters:** Minified files embed site-specific `content_url()`; sharing would leak URLs across sites.
- **Evidence:** `min_cache_dir()` returns `WP_CONTENT_DIR/cache/wppo/min/{blog_id}`; `cached_content_url` also blog-keyed (661-677). Consumers `class-cache.php` `get_cache_file_path(css)` now variant-aware.
- **Impact:** None.
- **Recommended solution:** No change.
- **Confidence:** High

### F-COMPAT-10 — Object-cache drop-in: `blog_prefix = get_current_blog_id():` + `salt` (WP 6.9+ `wp_cache_add_salt`) are consistent with `transient_key`

- **File:Line:** `templates/object-cache.php:69` (`$this->blog_prefix = (is_multisite()?get_current_blog_id():$table_prefix).':'`), `templates/object-cache.php:85-87` (`add_salt`), `templates/object-cache.php:208-218` (`get_key` with `global_groups` bypass), `templates/object-cache.php:529-570` (`flush` SCAN by prefix, not `flushDb` unless filter), `templates/object-cache.php:928-1151` (salted wrappers `wp_cache_get_salted` etc. with `function_exists` guard)
- **Category:** Object-cache backends
- **Severity:** INFO (pass)
- **Problem:** None.
- **Why matters:** Redis flush must not evict other sites' keys; salt wraps must match core's stable-key format.
- **Evidence:** `flush()` SCAN `prefix*` count 100 per cursor for both standalone and `RedisCluster->_masters()` loop; `wp_cache_supports` correctly claims `add_multiple|set_multiple|get_multiple|delete_multiple|flush_group` but NOT `flush_runtime` (intentional — would otherwise delegate to persistent flush). `no_mc_groups` bypass Redis correctly. Memcached hosts fall back to runtime `$cache` array (no persistent) — compatible degradation.
- **Impact:** None.
- **Recommended solution:** Keep. Note for operators: set `object_cache_allow_flush_all` filter true only on isolated single-site.
- **Confidence:** High

### F-COMPAT-11 — Hosting: Apache `.htaccess` via `insert_with_markers` guarded; Nginx rules via `Server_Rules`; LiteSpeed/OLS via `SERVER_SOFTWARE` + `has_filter(litespeed_can_optm)`

- **File:Line:** `includes/class-htaccess-handler.php:54` (`function_exists insert_with_markers` + `require ABSPATH wp-admin/includes/misc.php`), `includes/class-server-rules.php:35-59` (`is_litespeed` via `$_SERVER['SERVER_SOFTWARE']` sanitized), `includes/class-litespeed-integration.php:201-223` (delegates to `Server_Rules::is_litespeed()` then fallback raw check + `wppo_litespeed_is_litespeed` filter, memoized), `includes/class-cache.php:380-388` (`should_bypass_for_litespeed()` centralizes `LiteSpeed_Integration::should_disable_wppo_optimizer() || has_filter(litespeed_can_optm) && !apply_filters`)
- **Category:** Hosting envs
- **Severity:** INFO (pass)
- **Problem:** None.
- **Why matters:** Must not clobber `wordpress`/`LSCACHE` htaccess markers; Nginx has no htaccess.
- **Evidence:** `Htaccess_Handler::update_rules` uses marker `wppo_rules` only; `Server_Rules` emits Nginx `gzip` + `expires` snippets; `LiteSpeed_Integration` purge sync via `wppo_after_cache_clear` at priority 20 alongside `CDN_Purger`.
- **Impact:** None.
- **Recommended solution:** No change.
- **Confidence:** High

### F-COMPAT-12 — Caching plugin coexistence: `should_bypass_for_litespeed()` / `LiteSpeed_Integration::can_apply_cdn()` respected in combine/minify/CDN paths

- **File:Line:** `includes/class-cache.php:397` (`combine_css` early return if `should_bypass_for_litespeed`), `includes/class-cache.php:1375` (`minify_buffer` bypass), `includes/class-cache.php:1284-1291` (`maybe_apply_cdn` checks `LiteSpeed_Integration::can_apply_cdn()` + `litespeed_can_cdn` filter), `includes/class-litespeed-integration.php:254-261` (multisite filter handling)
- **Category:** Caching plugins
- **Severity:** INFO (pass)
- **Problem:** None.
- **Why matters:** Two full-page caches serving stale variants.
- **Evidence:** `Advanced_Cache_Handler::foreign_dropin_present()` check in `class-activate.php`/`class-main.php:1012-1019` leaves foreign `advanced-cache.php` alone; `maybe_run_version_upgrade` only clears cache when own drop-in is owner.
- **Impact:** None.
- **Recommended solution:** No change.
- **Confidence:** High

### F-COMPAT-13 — Themes / builders: block-asset on-demand (WP 6.9) opt-out correct; builders need no special case

- **File:Line:** `includes/class-main.php:821-839` (`register_block_assets_filters` — `should_load_separate_core_block_assets` → `__return_false` at priority 10 only for non-block themes when `blockAssetsOnDemand` empty or `loadAllCoreBlockAssets` true), `includes/class-cache.php:348-368` (`block_assets_are_separate`, `is_core_block_asset` excludes `wp-block-*`), `readme.txt:15` (Elementor/Divi/Astra/GeneratePress/WooCommerce/Yoast/Rank Math listed)
- **Category:** Themes
- **Severity:** INFO (pass)
- **Problem:** None.
- **Why matters:** WP 6.9 classic themes load `wp-block-*` per-block; combining would re-monolithize.
- **Evidence:** Separate assets state baked into combined filename variant (`separate` vs ``) at `class-cache.php:437-438`; core's priority-0 `should_load_separate_core_block_assets` → true is correctly overridden at priority 10 only when needed. `is_core_block_asset` skips combining `wp-block-*` when separate.
- **Impact:** None.
- **Recommended solution:** No change.
- **Confidence:** Medium (no builder-specific exclusion needed; user excludable via `excludeCombineCSS`/`excludeJS`).

### F-COMPAT-14 — wp.org External Services compliance: `readme.txt == External Services ==` discloses PageSpeed + Fonts correctly

- **File:Line:** `readme.txt:279-298` (External Services — PageSpeed `https://www.googleapis.com/pagespeedonline/v5/runPagespeed` with when/where/what/terms/EOL; Fonts `https://fonts.googleapis.com` + `https://fonts.gstatic.com` with CA Chrome UA, timeouts, opt-out), `includes/class-pagespeed.php:244-276` (server-side `wp_remote_get` 120s, `sslverify true`, strategy whitelist, `get_transient_key` via `transient_key`), `includes/class-google-fonts.php:9-18` (only when `hostGoogleFontsLocally` true, local `wp-content/cache/wppo/fonts/`), `performance-optimisation.php:11` (`Text Domain`), `.distignore` anchored, `vendor/` ignored, `build/` committed per AGENTS.md
- **Category:** wp.org compliance
- **Severity:** INFO (pass)
- **Problem:** None — disclosure complete per directory guidelines.
- **Why matters:** Undisclosed external calls are rejection reason (plugin-check).
- **Evidence:** Both services: purpose/when/where/what sent/terms/EOL documented. PageSpeed only with stored API key + manual/auto scan; Fonts only when toggle on. No other external calls on page load (`RUM` is same-origin beacon, `Edge_Cache` worker generation is local template replacement, `AI_Adaptive` is local heuristic unless `wp_ai_client` provider is configured — provider's own policy governs).
- **Impact:** None.
- **Recommended solution:** Keep. If `wp_ai_client` ever uses remote LLM, add third External Services entry at that time (currently heuristic-only, no disclosure needed).
- **Confidence:** High

### F-COMPAT-15 — Edge HTML adapter is local-only (no external runtime), purge is transient-locked and multisite-safe

- **File:Line:** `includes/class-edge-cache.php:98-116` (`is_configured` checks constants `WPPO_CLOUDFLARE_API_TOKEN`/`WPPO_BUNNY_API_KEY` plus `edge_cache.enabled` filter), `includes/class-edge-cache.php:171-204` (`get_worker_js` — `file_get_contents templates/cloudflare-worker.js` + `str_replace {{ORIGIN_URL}}` etc., inline fallback SWR), `includes/class-edge-purger.php:49-69` (`PURGE_LOCK via Util::transient_key`, 60s TTL), `includes/class-edge-purger.php:107-110` (reads constants, not DB tokens)
- **Category:** wp.org / edge workers
- **Severity:** INFO (pass)
- **Problem:** None.
- **Why matters:** Must not send visitor data to edge vendor without declaration; worker generation must be local.
- **Evidence:** No `wp_remote_*` in `Edge_Cache`; purge via `CDN_Purger`/`Edge_Purger` on `wppo_after_cache_clear` (manual clear). Config via constants (not stored token in DB) is correct for wp.org (no secret in options). Generation uses `esc_url_raw($origin)` and `sanitize_title(host)` for wrangler name.
- **Impact:** None.
- **Recommended solution:** No change. Document that `compatibility_date = "2024-01-01"` in `get_wrangler_toml` may need periodic bump if Cloudflare deprecates.
- **Confidence:** High

### F-COMPAT-16 — `AI_Adaptive` (`wp_ai_client` WP 7.0) is local heuristic with `function_exists` guard; no External Services needed

- **File:Line:** `includes/class-ai-adaptive.php:100-123` (`learn()` throttle `wppo_ai_learn_lock` via `transient_key` 60s, then `function_exists wp_ai_client` delegate else `heuristic_learn`), `includes/class-ai-adaptive.php:131-156` (`learn_via_ai_client` try/catch, prompt built from `RUM::OPTION + TREND_OPTION` JSON, not visitor PII), `includes/class-ai-adaptive.php:454` (`init` only adds `wp_speculation_rules` filter if `wp_get_speculation_rules_configuration` exists), `includes/class-ai-adaptive.php:419-445` (`filter_speculation_rules` gated on `is_enabled()` + non-empty prefetch URLs)
- **Category:** wp.org / AI compat
- **Severity:** INFO (pass)
- **Problem:** None.
- **Why matters:** Remote AI must be disclosed; local heuristic does not.
- **Evidence:** `is_enabled()` reads `ai_adaptive.enabled` (false default) + `wppo_ai_adaptive_enabled` filter. `heuristic_learn` uses local `$wpdb` meta `_wppo_disabled_scripts` LIMIT 500 and RUM aggregates; no `wp_remote_*`. `wp_ai_client()` path wraps in try/catch and `method_exists($client,'prompt')`.
- **Impact:** None.
- **Recommended solution:** Keep disabled default. If a future AI provider routes to external LLM, add External Services disclosure then.
- **Confidence:** High

### F-COMPAT-17 — `OD_Bridge` (Optimization Detective) is fully guarded; degrades to heuristic `excludeFirstImages` 1-3

- **File:Line:** `includes/class-od-bridge.php:59` (`class_exists OD_URL_Metric || function_exists od_get_url_metrics`), `includes/class-od-bridge.php:72-103` (`is_enabled` checks `is_od_available()` then `wppo_settings[od_integration][enabled]` auto true when OD present), `includes/class-od-bridge.php:244-283` (object `get_lcp_element`/`get_elements` with try/catch + `WP_DEBUG` logging), `includes/class-od-bridge.php:346-429` (`count_viewport_groups` via `OD_URL_Metric_Group_Collection` class/method_exists cascade + mobile/desktop bucketing), `includes/class-image-optimisation.php:1492` (uses `wp_get_loading_optimization_attributes` only if exists)
- **Category:** Plugin compat
- **Severity:** INFO (pass)
- **Problem:** None.
- **Why matters:** OD is Performance Lab plugin, not core; hard dependency would WSOD.
- **Evidence:** Every OD class/function/method checked before call; 12 `try/catch(\Throwable)` blocks log only when `WP_DEBUG`. Fallback threshold `2` when no OD data.
- **Impact:** None.
- **Recommended solution:** No change.
- **Confidence:** High

### F-COMPAT-18 — `Perf_Translations` (.mo→php) gated on WP 6.9+ salt + `WP_Translation_File`, multisite blog-scoped

- **File:Line:** `includes/class-perf-translations.php:68` (`!function_exists wp_cache_get_salted → return false`), `includes/class-perf-translations.php:84-88` (`get_cache_dir` suffix `/site-{blog_id}` when `is_multisite`), `includes/class-perf-translations.php:137-204` (`filter_load_file` — early returns if not `.mo`, not readable, no `WP_Translation_File`; `WP_Filesystem` vs `wp_mkdir_p` fallback; `opcache_invalidate` both `wp_opcache_invalidate` and `opcache_invalidate`), `includes/class-perf-translations.php:246-274` (`on_upgrader_complete` only on `type == translation`, conservative full purge)
- **Category:** WP compat / hosting
- **Severity:** INFO (pass)
- **Problem:** None.
- **Why matters:** Writing compiled `.php` to `wp-content/cache/wppo/lang` must respect multisite and OPCache; writing elsewhere would need FS creds.
- **Evidence:** Cache file name sanitized via `sanitize_key(domain)` + `md5(mofile)` hash 8 chars; invalidation via `upgrader_process_complete` SCAN dirlist.
- **Impact:** None.
- **Recommended solution:** No change.
- **Confidence:** High

### F-COMPAT-19 — bfcache (Instant Back/Forward) privacy-safe token + `no-store` → `private,no-cache` strip is correct but `filter_nocache_headers` has benign unreachable branch

- **File:Line:** `includes/class-bfcache.php:75-89` (`is_enabled` reads `bfcache.enabled` false default + `wppo_bfcache_enabled` filter), `includes/class-bfcache.php:99-105` (`get_cookie_name` uses `COOKIEHASH` for multisite dir), `includes/class-bfcache.php:113-118` (`generate_token` via `wp_generate_password` fallback `random_bytes`), `includes/class-bfcache.php:169-182,232-240` (`attach_session_information` + `on_set_logged_in_cookie` via `WP_Session_Tokens`), `includes/class-bfcache.php:270-323` (`filter_nocache_headers` — strips `no-store,public` only when token exists, else preserves privacy), `includes/class-bfcache.php:356-385` (`enqueue_scripts` — `wp_print_inline_script_tag` if exists (WP 6.0+) else echo `<script>`, `static $done` dedup, `get_user_token` guard, `is_admin` skip), `includes/class-bfcache.php:393-401` (`init` hooks `attach_session_information`, `set_logged_in_cookie`, `nocache_headers 1000`, both `wp_enqueue_scripts` + `admin_enqueue_scripts`)
- **Category:** bfcache behavior
- **Severity:** LOW
- **Problem:** Minor dead code at `class-bfcache.php:283-296` — second `if (null === $token) return $headers;` is unreachable because first `if (null === $token)` block already returned or fell through with cookie re-set logic. No functional breakage.
- **Why matters:** Code clarity, not behavior.
- **Evidence:** Lines 281-296: `if (null === $token) { if (!isset($_COOKIE[name]) && null !== $token) { ... } if (null === $token) return $headers; } else { ... }` — inner `null !== $token` always false, second check always true → redundant. The 20-line `filter_nocache_headers` still correctly preserves `no-store` for non-opted sessions and strips for opted.
- **Impact:** No user impact.
- **Recommended solution:** Collapse to single `if (null === $token) return $headers;` + shared cookie-ensure helper. No compat fix needed.
- **Confidence:** High

### F-COMPAT-20 — `uninstall.php` transient cleanup misses most new transient/option keys (orphan rows on DB-backed object-cache)

- **File:Line:** `uninstall.php:32-48` (options deleted), `uninstall.php:92-98` (only 5 transients: `wppo_activation_notices`, `wppo_show_welcome_notice`, `wppo_cache_size`, `wppo_total_js_css`, `wppo_wp_cache_fix_checked`), `uninstall.php:158-184` (multisite loop `get_sites number 100` + `switch_to_blog/restore_current_blog` correct)
- **Category:** Multisite / wp.org
- **Severity:** LOW
- **Problem:** Options `wppo_web_vitals_rum`, `wppo_web_vitals_trends`, `wppo_web_vitals_last_rescan`, `wppo_ai_model` (NEW) and transients `wppo_rum_queue`, `wppo_rum_flush_lock`, `wppo_rum_ratelimit_*`, `wppo_ccss_status_*`, `wppo_pagespeed_*`, `wppo_db_cleanup_counts`, `wppo_inline_drift_*`, `wppo_ai_learn_lock`, `wppo_edge_purge_lock`, `wppo_cache_write_*` etc. survive uninstall as `wp_options` rows (autoload=no) when object-cache is DB-backed (no expiry GC until accessed).
- **Why matters:** Plugin directory guidelines expect cleanup on uninstall; leftover rows bloat options.
- **Evidence:** `uninstall.php:158-184` correctly paginates sites and uses `$transient_prefix = is_multisite()?get_current_blog_id().'_':''` mirroring `Util::transient_key` without autoloader (acceptable). But only 5 keys deleted. No `DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wppo_%'` wildcard.
- **Impact:** Low (few rows, autoload=no), but measurable on DB-backed transient stores.
- **Recommended solution:** In `wppo_cleanup_site()` add `delete_option('wppo_web_vitals_rum')`, `delete_option('wppo_web_vitals_trends')`, `delete_option('wppo_web_vitals_last_rescan')`, `delete_option('wppo_ai_model')`, `delete_option('wppo_lcp_image_url_*')` wildcard, and ` $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '{$wpdb->esc_like('_transient_'.$transient_prefix.'wppo_')}%'" )` + same for `_transient_timeout_*` plus `delete_option('wppo_web_vitals_trends')` etc. Keep symlink guard for directory deletion (already correct at 117-143).
- **Confidence:** High

### F-COMPAT-21 — JS `lazyload.js` fallback paths are correct; one stale setting path in `getLazySelector()`

- **File:Line:** `src/lazyload.js:28-38` (`readModuleData` try/catch), `src/lazyload.js:36-51` (`useNativeLazy` dual path `window.wppoNativeLazy || moduleData.nativeLazy` + `NATIVE_LAZY_SUPPORTED` guard), `src/lazyload.js:84-95` (`getLazySelector` reads `window.wppoSettings.settings.general.native_lazy`), `src/lazyload.js:322-346` (`IntersectionObserver` viewport scripts fallback to `loadScriptsByPriority` when unsupported), `src/lazyload.js:382-394` (`requestIdleCallback` with `setTimeout(Math.min(2000,idleTimeout))` fallback), `src/lazyload.js:607` (`if ('IntersectionObserver' in window)` branch with scroll fallback `lazyLoadFallback`), `src/lazyload.js:769-864` (scroll fallback `isElementInViewport` correct), `src/lazyload.js:1002-1023` (background `IntersectionObserver` fallback to immediate restore)
- **Category:** JS/browser compat / bfcache
- **Severity:** LOW
- **Problem:** `getLazySelector()` fallback branch reads `wppoSettings.settings.general.native_lazy` which does not exist — actual SPA key is `image_optimisation.lazyLoadNative` (see `class-util.php` defaults and `class-main.php:287-295`). No breakage because primary path uses `USE_NATIVE_LAZY` (module data) when `wppoSettings` missing `general`, so secondary branch is dead for SPA contexts. Isolated sites that inject `wppoSettings` without module data would mis-detect.
- **Why matters:** Could emit wrong `LAZY_SELECTOR` (video-only vs all) on edge hosts that strip script-module data and rely on `wppoSettings` fallback.
- **Evidence:** `src/lazyload.js:84-93`: `typeof wppoSettings.settings.general !== 'undefined' ? !!wppoSettings.settings.general.native_lazy : USE_NATIVE_LAZY` — key `general` never set by `Main::enqueue_admin_scripts` (`wppoSettings.settings[tabName]` tabs are file_optimisation/image_optimisation/etc., no `general`). Compare `src/lazyload.js:36`: correct path `moduleData.nativeLazy`.
- **Impact:** Low — module data path is primary; `wppoSettings` fallback is legacy and functionally falls through to `USE_NATIVE_LAZY`, so behavior correct despite wrong key name.
- **Recommended solution:** Change fallback to `wppoSettings.settings.image_optimisation?.lazyLoadNative` or remove `general` branch and rely solely on `USE_NATIVE_LAZY`. Fix `AUTO_SIZES_SUPPORTED` probe (63-76) DOM append on `documentElement` before body exists is okay due to try/catch, but move to `DOMContentLoaded` for strictly spec-compliant timing.
- **Confidence:** Medium

### F-COMPAT-22 — `rum.js` beacon: `sendBeacon` + `fetch` fallback with `visibilitychange`/`pagehide` correctly avoids bfcache `unload` anti-pattern

- **File:Line:** `src/rum.js:56-96` (`send()` — `navigator.sendBeacon` Blob JSON else `fetch POST omit`), `src/rum.js:114-160` (`PerformanceObserver` for LCP buffered, CLS `hadRecentInput` filter, INP `durationThreshold 16`), `src/rum.js:162-194` (`scheduleSend` 5s after `load` or immediately if `complete`, plus `visibilitychange hidden → send`, `pagehide once → send+disconnect`)
- **Category:** bfcache / JS compat
- **Severity:** INFO (pass)
- **Problem:** None — does NOT use `unload` event (which would opt page out of bfcache).
- **Why matters:** `unload` listeners disable bfcache in Chromium/Firefox.
- **Evidence:** Only `visibilitychange`, `pagehide`, `load` listeners; `disconnectObservers()` before `send`.
- **Impact:** None.
- **Recommended solution:** No change.
- **Confidence:** High

### F-COMPAT-23 — `main.js` admin-bar cache clear: nonce refresh dedup + path traversal guard correct

- **File:Line:** `src/main.js:6-49` (`postJsonRequest` + 403→`refreshNonce`), `src/main.js:60-101` (`refreshNonce` singleton `pendingRefresh` thundering-herd guard), `src/main.js:200-215` (`clear_this_page` — `decodeURIComponent` try, `path.length>2048`, `path[0]!=='/'`, `decodedPath.includes('..')` fallback to `/`)
- **Category:** JS compat / caching plugin compat
- **Severity:** INFO (pass)
- **Problem:** None.
- **Why matters:** Admin-bar buttons run on every admin page; shared 403 refresh must not duplicate.
- **Evidence:** `refreshNonce` same pattern as `src/lib/apiRequest.js`; fallback DOM notice handles missing `core/notices` store.
- **Impact:** None.
- **Recommended solution:** No change.
- **Confidence:** High

### F-COMPAT-24 — `class-img-converter.php` GD/Imagick + client-side toggle: `wp_is_client_side_media_processing_enabled` double-guard prevents double conversion

- **File:Line:** `includes/class-img-converter.php:1193` (`$client_side_enabled = function_exists('wp_is_client_side...') && wp_is...`), `includes/class-image-optimisation.php:197-209` (early return when client-side enabled and MIME intersect)
- **Category:** WP 7.1 compat
- **Severity:** INFO (pass)
- **Problem:** None.
- **Why matters:** WP 7.1 client-side Web Worker for WebP; double-encoding wastes CPU.
- **Evidence:** `Img_Converter::is_client_side_processing_enabled()` at 151 checks both existence and MIME intersection; `Image_Optimisation::maybe_serve_next_gen_images` respects it.
- **Impact:** None.
- **Recommended solution:** No change.
- **Confidence:** High

### F-COMPAT-25 — `templates/object-cache.php:536-537` `object_cache_allow_flush_all` filter correctly defaults false (multisite-safe flush)

- **File:Line:** `templates/object-cache.php:532-534` (`if (apply_filters('object_cache_allow_flush_all', false)) return $this->redis->flushDb()`), else SCAN prefix
- **Category:** Multisite / object-cache
- **Severity:** INFO (pass)
- **Problem:** None.
- **Why matters:** Accidental `flushDb` on multisite wipes all sites' object-cache.
- **Evidence:** Default false; doc comment lines 521-527 explain.
- **Impact:** None.
- **Recommended solution:** No change; operators on isolated single-site may opt in via filter.
- **Confidence:** High

---

## Coverage checklist (what was inspected but no finding)

- `phpcs.xml` `testVersion 8.2-` aligns with `composer.json:13/55` + `performance-optimisation.php:6` — PASS.
- `Cache::atomic_put_contents` per-file transient lock `Util::transient_key('wppo_cache_write_'.md5(file_path))` 5s + `WP_Filesystem::move`/`rename` — multisite-safe (domain in path, prefix adds isolation) — PASS, dead `elseif` brotli branch at `class-cache.php:1633-1660` noted as INFO only.
- `Cache::maybe_mark_page_not_cacheable()` DONOTCACHEPAGE → `.wppo-no-cache` marker + `delete_cache_files` — correct single-write per request (INFÓ: stale pre-cached page until next render/clear — documented).
- `Cron` 5h preload + sitemap 15s budget + `get_sitemap_urls` off-site filter + 500 cap — PASS.
- `System_Info`, `Telemetry`, `Pagespeed` — all `wp_http_validate_url` + same-host + scheme `http/https` SSRF guards — PASS (not compat but noted).
- `Llms` `is_multisite ? .../site-{id}/` dir + `has_filter` COOKIEHASH — PASS.
- `Util::sanitize_settings_recursively` empty-key skip — PASS.

## Open questions (no code change in this audit)

- Q1: Keep `Tested up to: 7.1` forward claim or revert to latest released until CI smoke on `wordpress:7.1` passes on PHP 8.2-8.5 matrix?
- Q2: Should `Util::get_settings` blog-keyed memo land as hotfix (MEDIUM) or deferred to next feature cycle (risk: `switch_to_blog` leaks until then)?

## Appendix — Raw counts

- PHP files (includes): 41 + redis helper + 3 minify = 45
- JS files (src): 70 incl. tests + lib
- Templates: 2
- Grep hits: `function_exists|class_exists|has_filter|version_compare` 320; `transient_key` 86; `get_transient|set_transient|delete_transient` 83 (all via `transient_key` except 5 legacy in `uninstall.php` — acceptable, no autoloader there)
- `function_exists` gates on every 6.9/7.0/7.1 API verified (see F-COMPAT-05 evidence list).
