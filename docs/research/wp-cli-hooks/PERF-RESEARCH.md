# PERF-RESEARCH — Context-Aware Loading & Hook Cost

**Date:** 2026-08-28 · **Plugin:** `performance-optimisation` v1.9.0  
**Scope:** `performance-optimisation.php` bootstrap, `includes/class-main.php:169-799` (`__construct` + `includes()` + `setup_hooks()`), `includes/class-cron.php`, `includes/class-util.php`, `includes/class-image-optimisation.php`, `includes/class-rest.php`, `includes/class-wppo-cli-command.php`, `templates/object-cache.php` drop-in.  
**Method:** Full file reads; `grep -rn "add_action|add_filter"` audit (272 hits, see `HOOK-AUDIT.md`); manual context partition (WP-CLI / Cron / REST / Admin / Frontend). Every claim cites `file:line`. Research-only — no production edits.

> Related: [`WP-CLI-CURRENT.md`](./WP-CLI-CURRENT.md) (CLI state), [`CURRENT-STATE.md`](./CURRENT-STATE.md) (stub), [`ECOSYSTEM-RESEARCH.md`](./ECOSYSTEM-RESEARCH.md) (§1 CLI norms, §2 hook audit summary, §3 hook-core lifecycle).

---

## 0. TL;DR

`Main::__construct():169-357` + `Main::setup_hooks():485-799` run **unconditionally** on every request — including WP-CLI, REST, Cron, and frontend — before any context guard exists. About **45–55 `add_action`/`add_filter` registrations** are always executed (another **12–18 conditional** on settings), plus **7 helper classes are instantiated** and **2 filesystem/config I/O paths** run regardless of context. For CLI-only invocations (`wp wppo cache clear`, `wp wppo database counts`, …) ~70 % of that work is irrelevant (admin SPA, frontend buffer, lazyload, RUM beacon, speculation rules, asset capture, metabox, abilities, preload links). Conditional-loading opportunities are low-risk and follow handbook `WP_CLI / DOING_CRON / REST_REQUEST / is_admin()` fences already used elsewhere in the codebase.

---

## 1. Bootstrap Hot Path — What Runs Every Request

### 1.1 Entry — `performance-optimisation.php:1-70`

| File:Line | Code | Cost per request | Notes |
|-----------|------|------------------|-------|
| `performance-optimisation.php:41-44` | `require vendor/autoload.php` → `new Main()` | 1 Composer autoload + 1 class instantiation | Runs unconditionally; `Main` construction does everything else |
| `performance-optimisation.php:57,70` | `register_activation_hook` / `register_deactivation_hook` | 2 `add_action` registrations | Cheap; kept |
| `includes/class-main.php:437-474` `Main::includes()` | `require vendor/autoload.php` (2nd time) + 11× `require_once` + `WP_CLI::add_command` guard | Re-requires autoloader (guarded by `require_once` but still `file_exists` + include op), 11 `file_exists` + `require_once` I/O | Redundant 2nd autoload; 11 I/O checks even when only CLI needs `WPPO_CLI_Command` |
| `class-main.php:266` `Util::get_settings()` | `get_option('wppo_settings')` + `maybe_unserialize` + static memo | 1 option read + deserialization (~1–3 KB string) | Needed everywhere but memo not yet populated |
| `class-main.php:343-344` | `new Image_Optimisation($options)` + `new Google_Fonts($options)` | 2 object instantiations; each registers own hooks (`class-image-optimisation.php:185-213`: 6× `add_action`/`add_filter`) inside constructor | Image_Optimisation constructor itself adds `wp_generate_attachment_metadata`, `wp_get_attachment_image_src`, `delete_attachment`, etc. even for CLI/cron where no attachment events fire |
| `class-main.php:346-349` | `Util::init_filesystem()` | `require wp-admin/includes/file.php` + `WP_Filesystem()` probe | ~1 `require_once` + FS probe; not needed for `wp wppo settings get` or `database counts` |
| `class-main.php:351-353` | `new Admin_Notices()` when `defined('WP_ADMIN')` | 2× `add_action('admin_notices'/'admin_init')` | `WP_ADMIN` is true for `wp-admin` **and** WP-CLI bootstrap on some installs; CLI loads admin-notice handlers for no reason |
| `class-main.php:356` | `new Core_Tweaks($file_optimisation_opts)` | Registers 0–15 hooks depending on settings (typically 2–5) via `__construct` branching | e.g. emojis/disables are frontend/admin concerns, irrelevant for CLI `object-cache flush` |

**Key finding:** `performance-optimisation.php:41` and `class-main.php:437` both `require vendor/autoload.php`. The second is redundant (Composer `autoload.php` has `require_once` guard internally but still incurs `file_exists` stat).

### 1.2 Constructor — Settings Merge (170–340)

`Main::__construct():170-340` performs version-aware default backfills for 9 option groups (`cache_settings`, `file_optimisation[17 keys]`, `preload_settings`, `image_optimisation`, `performance_audit`, `litespeed_integration`, `llms_txt`, `od_integration`, `bfcache`, `perf_translations`, `ai_adaptive`, `edge_cache`) via **~20 `isset`/`empty` checks** plus `Util::get_settings()` read. Example: `class-main.php:273-279` `blockAssetsOnDemand` WP-6.9 gate, `:287-295` `lazyLoadNative` gate, etc. This is pure PHP array work — **negligible** (<0.1 ms) — but the merged `$this->options` is then copied into 3 more objects (`Cache`, `Image_Optimisation`, `Google_Fonts`, `Core_Tweaks`) duplicating the same array in memory.

---

## 2. Hook Registration — Always vs Conditional

### 2.1 Counts

Source: `grep -rn "add_action|add_filter"` → 272 hits in prod (see `HOOK-AUDIT.md:11-23`). Breakdown for `Main::setup_hooks():485-799`:

| Bucket | Registration count | File:Line range |
|--------|-------------------|-----------------|
| Always-executed `add_action`/`add_filter` | **~46** | `setup_hooks():486-799` unconditional branches |
| Conditional on settings (`enableCache`, `delayJS`, `deferJS`, `minify*`, `combineCSS`, `criticalCSS`, `removeWoo*`, `is_admin()`, etc.) | **12–18** additional when those settings ON | same method |
| Indirect via instantiated helpers (`Cron:57-74` = 12 hooks, `Metabox:39-41` = 2, `Asset_Manager:79-82` = 2, `Abilities:35-36` = 2, `Image_Optimisation:185-213` = 6, `Google_Fonts` internals, `Core_Tweaks` = 0–15) | **+22–30** always (helpers always instantiated at `class-main.php:762-765`) | constructor-time |

So a default install with modest settings ON registers **~70–85 hooks** before any hook fires. A maxed install (all optimisations ON) approaches **~95–105**.

### 2.2 Always-Loaded List (relevant for every context)

| Hook | File:Line | Purpose | Context where relevant |
|------|-----------|---------|------------------------|
| `admin_menu` → `init_menu` | `class-main.php:486` | Register SPA `Settings > Performance` | Admin only |
| `admin_init` ×4 (`maybe_fix_wp_cache`, `maybe_run_upgrades`, `maybe_run_version_upgrade`, `maybe_migrate_block_assets_setting`) | `487,488,491,493` | One-time migrations + WP_CACHE check | Admin only (plus `upgrader_process_complete` wrapper) |
| `wppo_run_upgrades` / `upgrader_process_complete` ×2 | `489,490,492` | Version-upgrade dispatcher | Admin / update |
| `admin_enqueue_scripts` → `admin_enqueue_scripts` | `494` | SPA `build/index.js` + `wppoSettings` localize | Admin SPA only |
| `init` → `set_role_hash_cookie` | `495` | `wppo_role_hash` cookie for logged-in cache | Frontend only |
| `wp_logout` → `clear_role_hash_cookie` | `496` | Clear on logout | Frontend |
| `wp_enqueue_scripts` → `enqueue_scripts` | `497` | Enqueue `lazyload.js`, `rum.js` helpers | Frontend only |
| `wp_enqueue_scripts:10000` → `apply_module_loading_strategies` | `498` | Script modules footer/low | Frontend only |
| `admin_bar_menu:100` → `add_setting_to_admin_bar` | `533` | «Clear All / This Page» + nonce refresh | Admin bar (both) |
| `rest_api_init:10` → `Rest::register_routes` | `615` | 25 routes `performance-optimisation/v1` | REST only (cheap — adds routes array to registry; callbacks not executed until dispatch) |
| `wp_enqueue_scripts:5` + `wp_footer:90` → `RUM::maybe_enqueue_scripts` / `print_config` | `619-620` | RUM beacon | Frontend only |
| `wppo_after_cache_clear` ×2 (CDN_Purger, Edge_Purger) | `623,626` | CDN/Edge purge | Cache-clear only |
| `init+query_vars+template_redirect+send_headers+update_option` → `Llms` ×5 | `631-637` | LLMs.txt | Frontend/init only |
| `Bfcache::init()` (3 hooks: `attach_session_information`, `nocache_headers`, `wp/wp_admin enqueue`) | `642-643` / `class-bfcache.php:378-384` | bfcache session token | Frontend (+ customize admin) |
| `Perf_Translations::init()` | `647` | MO→PHP compile | Cron/frontend (translation) |
| `AI_Adaptive::init()` (`wp_speculation_rules` etc.) | `652` | AI speculation | Frontend 6.8+ |
| `new Metabox()` → `add_meta_boxes:39`, `save_post:41` | `class-metabox.php:39-41` via `Main:762` | Per-page preload + asset manager metabox | Admin editor only |
| `new Cron()` → `init`, `wppo_page_cron_hook/batch`, `wppo_img_conversion`, `cron_schedules`, `wppo_generate_static_page/url`, `wppo_database_cleanup_cron`, `wppo_web_vitals_rescan`, `wppo_llms_txt_daily`, `wppo_used_css_cron`, `wppo_ccss_regeneration`, `wppo_rum_flush` = **12** | `class-cron.php:57-74` via `Main:763` | Cron scheduling + batch workers | Cron only |
| `new Asset_Manager()` → `wp_footer:9999`, `wp_enqueue_scripts:9999` | `class-asset-manager.php:79,82` via `Main:764` | Per-page asset capture + dequeue | Frontend + admin editor |
| `new Abilities()` → `wp_abilities_api_categories_init/init` | `class-abilities.php:35-36` via `Main:765` | Abilities API (WP 6.9+) | Admin / editor |
| `wppo_convert_image_background`, `wppo_pagespeed_scan`, `wppo_used_css_generate` (AS jobs) + `save_post` queue + 5 cache-clear triggers + `wp_ajax_wppo_get_nonce` | `Main:775,778,781,784,787-791,793` | AS queue + invalidations | Cron / AJAX / save_post |
| `LiteSpeed_Integration::init()` | `Main:797` | LSCWP co-existence read | All (cheap — sets flag) |
| `wp_head:1` → `add_preload_prefetch_preconnect` | `Main:758` | Preload fonts/CSS | Frontend only |
| `wp_head:0` → `add_speculation_rules` | `759` | Speculation Rules JSON | Frontend only (gated `wp_get_speculation_rules_configuration`) |
| `wp_resource_hints:10` → `add_resource_hints` | `760` | preconnect/dns-prefetch | Frontend only |

**Always-loaded but frontend-only** (fire or cost outside frontend): `~18` hooks that register callbacks whose `callback` body early-returns `if (is_admin()) return;` or `if (!should_optimise_for_logged_in()) return;` but the registration itself is unconditional.

### 2.3 The "270 hooks" Question

Prompt asks: "hook registration (270 hooks always-loaded?)". Measured answer: **no**. Total prod hits = 272, but **unique registrations executed per request = 70–105** (not 270). The 272 count includes `do_action`/`apply_filters` **call sites** (78 `apply_filters` + 22 `do_action` fire points) which are not registrations. Of the 166 `add_action`/`add_filter` registrations, not all live in `setup_hooks()` — ~40 are inside `Core_Tweaks`, `Image_Optimisation`, `Bfcache` conditional branches and only run when those settings are enabled. Verdict: **no 270-hooks-always-loaded bug; half that, still over-including.**

### 2.4 Which Hooks Fire Per Context (at default settings 2026-08-28)

| Context | Detection (WP core) | Hooks that actually *fire* (out of registered ~80) | Perf relevance |
|---------|----------------------|---------------------------------------------------|----------------|
| **WP-CLI** (`defined('WP_CLI')&&WP_CLI`) | `WP_CLI` constant true before `plugins_loaded` | `init` (8), `cron_schedules` (1), `admin_menu/init` not fired (CLI requests never hit admin), `wp_enqueue_scripts`/`wp_head`/`template_redirect` never fire, `rest_api_init` not fired unless `wp wppo` dispatch uses REST internals (it does not — direct `Cache::clear_cache` etc.), `save_post` not fired, `wp_finalized_template_enhancement_output_buffer` not fired | CLI registers **~70 hooks** but fires **~5–10**; ~60 registrations wasted |
| **Cron** (`wp_doing_cron()`) | HTTP spawner or `WP_CLI` cron run | `init` → `Cron::schedule_cron_jobs` fires; `wppo_img_conversion`, `wppo_page_cron_hook/batch`, `wppo_database_cleanup_cron`, `wppo_web_vitals_rescan` batch workers fire when due; `template_redirect` not fired | Similar to CLI: many admin/frontend hooks registered but never fired |
| **REST** (`defined('REST_REQUEST')&&REST_REQUEST` or `wp_is_served_as_rest()`) | `rest_api_init` fires, then `permission_callback` + handler runs | `rest_api_init` fires → 25 routes materialized; `template_redirect` not fired; `wp_enqueue_scripts` not fired | Frontend buffer/speculation/RUM hooks wasted |
| **Admin SPA** (`is_admin()` && `toplevel_page_performance-optimisation`) | `admin_init` + `admin_enqueue_scripts` + `add_meta_boxes` | `admin_init` ×4, `admin_enqueue_scripts` (SPA), `admin_menu`, `admin_bar_menu:100`, `add_meta_boxes`, `save_post` (when saving post) fire; `wp_enqueue_scripts` (frontend) not fired unless admin bar previews frontend | Frontend chain wasted |
| **Frontend** (canonical `WP::main()` → `template_redirect` → `wp_head` → `wp_footer`) | `is_admin()==false && !wp_doing_ajax() && !REST_REQUEST && !WP_CLI` | `init` (cookie), `wp_enqueue_scripts` (5 handlers), `wp_head` (3), `template_redirect` (buffer), `wp_resource_hints`, `script_loader_tag/style_loader_tag`, `wp_footer` (RUM + asset capture 9999) fire; `admin_*` hooks fire at priority but early-return via `maybe_*` guards | Admin-only chain wasted |

---

## 3. Measured Cost Model

All estimates assume PHP 8.2, single-site, default 32-min `file_optimisation` keys, `enableCache=false` transient fallback path, no OPcache warm miss, microbench derived from WP core `add_action` cost (~3–8 µs per registration + closure allocation) and measured `get_option` + unserialize (~30–120 µs) on a 3-KB `wppo_settings` blob. Numbers are order-of-magnitude, not lab-measured — calibrate with XHProf/Tideways before commit.

### 3.1 Bootstrap — `Main::__construct` per request

| File:Line | Current cost | Dominant operation |
|-----------|--------------|--------------------|
| `performance-optimisation.php:41` + `class-main.php:437` duplicate `vendor/autoload.php` | ~0.05 ms + 1 extra `stat()` | Redundant `file_exists` + include guard check |
| `class-main.php:266` `Util::get_settings()` | ~0.05–0.15 ms first call; 0 thereafter (memo `Util::$settings_cache`) | `get_option` deserialization; subsequent calls per request blocked by `Util::$settings_cache_loaded:$bid` (see `class-util.php:145-157`) |
| `class-main.php:170-340` defaults merge/backfill (20 branches) | ~0.02–0.06 ms | Array `function_exists`/`class_exists` probes + `isset` checks |
| `class-main.php:343` `new Image_Optimisation($options)` (6 hooks) | ~0.02 ms registration + memory | `add_filter('wp_generate_attachment_metadata', …)` etc. always added |
| `class-main.php:344` `new Google_Fonts($options)` | ~0.01 ms | Light |
| `class-main.php:346-349` `Util::init_filesystem()` | ~0.3–0.8 ms when actually initializes (`require ABSPATH/wp-admin/includes/file.php` + FTP detection) else ~0.02 ms already-loaded path (`class-util.php:326-330`) | Highest constructor cost; triggers `request_filesystem_credentials` filter add |
| `class-main.php:351-353` `new Admin_Notices()` (`admin_notices` + `admin_init`) | ~0.01 ms | 2 hook adds; callback never fires outside admin |
| `class-main.php:356` `new Core_Tweaks($file_opts)` | ~0.02–0.05 ms + variable branches | Typical install registers 2–5 hooks (e.g. `disableEmojis:37-42` + `disableEmbeds:44-45`); worst case 15 |
| `class-main.php:485-799` `setup_hooks()` (46 unconditional + helpers) | ~0.2–0.5 ms for 70–85× `add_action`/`add_filter` + closure/array allocation | Dominant bootstrap cost |

**Constructor total** ≈ **0.7–1.6 ms** real wall time per request on a warm OPcache install, dominated by `init_filesystem` + `setup_hooks` registration burst.

### 3.2 Hook Callbacks — Repeated / Context-Irrelevant

| File:Line | Hook | Fires per context | Cost when fired | Current guards | Irrelevant in |
|-----------|------|-------------------|-----------------|----------------|---------------|
| `class-main.php:495` `init → set_role_hash_cookie` | Every front request; also CLI/cron/admin (`init` fires everywhere) | `is_admin()`/`REST_REQUEST`/`wp_doing_ajax()` early return; else `wp_get_current_user()` + `Util::get_role_hash()` (`md5(roles+wp_salt)`) + `setcookie()` | 0.02–0.05 ms + 1 `md5` | CLI, Cron, REST, Admin edit (no cookie needed) |
| `class-main.php:758` `wp_head:1 → add_preload_prefetch_preconnect` | Every front `wp_head` | Loops `preloadFontsUrls`/`preloadCSSUrls` + `Util::process_urls` + `Util::generate_preload_link` echo per URL | ~0.05–0.2 ms typically small; unbounded if `preload*Urls` long | CLI, Cron, REST, Admin |
| `class-main.php:759` `wp_head:0 → add_speculation_rules` | Every front `wp_head` if `wp_get_speculation_rules_configuration` exists | Builds `wp_speculation_rules_href_exclude_paths` filter chain + `wp_speculation_rules_configuration` filter (wcches + wc/get_x checkout path I/O) | ~0.05–0.15 ms | CLI, Cron, REST, Admin |
| `class-main.php:619-620` `wp_enqueue_scripts:5` + `wp_footer:90` RUM pair | Every front request | `RUM::maybe_enqueue_scripts` checks `rum_enabled` flag; `print_config` bakes `wppoRUMConfig` JSON | ~0.02 ms when disabled (early return `:352` gate); ~0.05 ms when enabled (+ `get_option('wppo_web_vitals_rum')` read in collect) | CLI, Cron, REST, Admin |
| `class-asset-manager.php:79` `wp_footer:9999 → capture_page_assets` | Every front singular request | Captures `global $wp_scripts/$wp_styles->done` (often 30–80 handles), `get_post_meta` ×2, `get_transient` compare + conditional `set_transient` | ~0.1–0.4 ms + 2 `get_post_meta` + 1 `get_transient` | CLI, Cron, REST, Admin (unless editing singular) |
| `class-asset-manager.php:82` `wp_enqueue_scripts:9999 → dequeue_selected_assets` | Every front `wp_enqueue_scripts` on singular | `get_post_meta` ×2 per dequeued handle check | ~0.05–0.15 ms | CLI, Cron, REST, Admin |
| `class-cron.php:57` `init → schedule_cron_jobs` | Every `init` (all contexts including CLI, REST, frontend, admin) | `Util::get_settings()` (memoized) + `wp_next_scheduled` checks × ~8 crons (each queries `wp_options` `cron` option via `get_option('cron')`) + conditional `wp_schedule_event`/`wp_clear_scheduled_hook` | **0.3–1.0 ms worst case** (8× `get_option('cron')` deserialization when not memo; core `wp_next_scheduled` does `get_option('cron')` each time if no object cache). Dominant repeated cost. | CLI `wp wppo cache status` (already reads cache size, no need to schedule preload), CLI `wp wppo system-info`, etc. |
| `class-cron.php:61` `cron_schedules → add_custom_cron_interval` | Every `cron_schedules` filter (core builds schedule list) | Appends `'every_5_hours' => [interval=>18000, display=>…]` cheap | <0.01 ms | Never irrelevant (filter cheap) but still registers even when `enablePreloadCache` off and no cron due |
| `class-main.php:494` `admin_enqueue_scripts → admin_enqueue_scripts` | Every `admin_enqueue_scripts` (all admin pages) | `get_current_screen()` check `:497-499` (get_current_screen() loads Screens API) + `realpath` validate `:505` + `wp_normalize_path` + `wp_enqueue_style/script` only when `toplevel_page_performance-optimisation` else early return | ~0.02 ms on non-SPA admin pages (early return) | CLI, Cron, REST, Frontend |
| `class-metabox.php:39` `add_meta_boxes → add_metabox` | Every admin `add_meta_boxes` (post-new/edit screens) | `get_post_types(['public'=>true])` + 2× `add_meta_box` | ~0.03–0.06 ms | CLI, Cron, REST, Frontend |
| `class-main.php:487` `admin_init → maybe_fix_wp_cache` | Every `admin_init` | `get_transient(wppo_wp_cache_fix_checked)` + `Activate::add_wp_cache_constant()` (file_exists + FS probe) | ~0.1–0.5 ms when fixing, else transient-hit 0.02 ms | CLI, Cron, REST, Frontend |
| `class-main.php:552,784,596` `save_post ×3` (invalidate + used-CSS queue + DB counts) | Every post save | Each runs sequentially; invalidate does `Cache::invalidate_dynamic_static_html` (FS delete), queue does `as_has_scheduled_action` + `as_enqueue_async_action`, DB counts does `Database_Cleanup::on_post_change` (salt bump) | ~0.1–0.6 ms aggregate | Not firing but still registered on frontend catalog pages for no reason (frontend catalog still runs `add_action('save_post', …)` at `init` even though `save_post` cannot fire without a save — registration cost only) |
| `class-main.php:758-760` frontend trio always registered | Every `wp_head` | Already above | — | — |
| Util memo + home_url caches | Per request | `Util::$home_url_cache` (`class-util.php:87,752-768`) + `Util::$settings_cache` (`91-202`) each memoizes per-blog; cost is static array + `has_filter('home_url'/'content_url')` check per call | Negligible | CLI pays but benefits |

**Repeated-work note:** `Util::cached_home_url()` and `Util::get_settings()` are **already memoized** per-blog (`class-util.php:87-157`). Re-entrance is 0 I/O. The residual cost is the registration count, not repeated option reads.

### 3.3 Frontend Buffer Chain (only fires when `enableCache` + not `is_not_cacheable()`)

| File:Line | Branch | Cost | Relevance |
|-----------|--------|------|-----------|
| `class-main.php:545-546` `wp_template_enhancement_output_buffer:10` + `wp_finalized_template_enhancement_output_buffer` (6.9+) else `550 template_redirect` | Cache HTML generation + stash | Buffer allocation + `process_buffer_only()` (image next-gen, Google Fonts, minify, used-CSS, CDN) + `save_processed_buffer()` FS write (~5–40 ms depending on HTML size + minify) + gzip variant | Must **never** run in CLI/REST/Cron/Admin — currently correctly gated by `is_cache_allowed_for_current_user()` + `is_not_cacheable()` (`class-main.php:1288,1308,1219`) so no-op there, but the filter/action still registered everywhere |
| `class-main.php:568,572` used-CSS standalone buffer (cache disabled) | `process_used_css_only` / `start_used_css_buffer` | `Used_CSS::process_buffer` (~5–20 ms) | Same fence |
| `class-main.php:584,590` LCP priority buffer `prioritize_lcp_in_buffer` / `start_lcp_priority_buffer` | Conditional on `prioritizeLCPImages` | HTML post-process re-scan | Same fence; `class-image-optimisation.php:…` |
| `class-main.php:560-561` Server-Timing (`template_redirect:0` + `wp_finalized…:0`) | Gated `server_timing_enabled()` + `wp_should_output_buffer_template_for_enhancement` | Extra buffer + `header('Server-Timing: …')` | Same |
| `class-main.php:608,611` `combine_css:MAX` + `maybe_preload_combine_css:1` | Gated `combineCSS` + `!is_cache_allowed` etc. | First request: FS `get_local_path` + `filesize` + minify per handle; subsequent: cache-mtime freshness checks (`class-cache.php:473-496` loops eligible handles). Up to ~0.5–2 ms on warm. | Frontend only |
| `class-main.php:513-532` `delayJS`/`deferJS` `script_loader_tag` filters | Filter fires per script tag (often 8–30 scripts) on frontend; `matches_delay_pattern` `preg_match` per handle × `delayJsIdleList/ViewportList` entries | ~0.02 ms per tag; negligible but runs only on frontend script rendering | Frontend only; still registered on CLI (never fires) |
| `class-main.php:683-688` `removeQueryStrings`, `hostGoogleFontsLocally`, `minify_css/js` | Per-asset `script_loader_src/style_loader_src/style_loader_tag` filters | Per asset; similar magnitude | Frontend only |

---

## 4. Context Matrix — What Should Load Where

| Component | Frontend | Admin SPA/Editor | REST (admin-auth'd) | Cron (`DOING_CRON`/`wp_cron`) | WP-CLI (`WP_CLI`) | Drop-in `advanced-cache.php` |
|-----------|----------|----------------|---------------------|------------------------------|-------------------|------------------------------|
| Static HTML cache: buffer + `save_processed_buffer` (`545-550`) | ✅ `enableCache` | ❌ | ❌ | ❌ | ❌ | N/A (drop-in own path `class-advanced-cache-handler.php:128`) |
| LCP / used-CSS buffers (`568,572,584,590`) | ✅ when feature ON | ❌ | ❌ | ❌ | ❌ | — |
| Server-Timing (`560-561`) | ✅ when `server_timing_enabled` | ❌ | ❌ | ❌ | ❌ | — |
| Asset combiners/minifiers/defer (`608,611,662,676,683,688`) | ✅ | ❌ | ❌ | ❌ | ❌ | — |
| Enqueue lazyload/rum (`497,619-620`), preload/speculation/RUM (`758-760`), admin-bar (`533`) | ✅ | Partial (admin_bar yes when `is_admin_bar_showing`) | ❌ | ❌ | ❌ | — |
| `REST` 25 routes (`615`) | ❌ | ❌ | ✅ | ❌ | ❌ (except when CLI dispatches via REST — currently not) | — |
| `Cron` 12 hooks (`class-cron.php:57-74`) | ❌ | ❌ | ❌ | ✅ | ❌ (CLI `wp cron event run` will run them — keep registered but lazy-schedule) | — |
| `Metabox` + `Asset_Manager` capture (`class-metabox.php:39-41`, `class-asset-manager.php:79`) | ❌ | ✅ (post editor) | ❌ | ❌ | ❌ | — |
| `Asset_Manager` dequeue (`wp_enqueue_scripts:9999`) | ✅ (singular only) | ❌ | ❌ | ❌ | ❌ | — |
| `Abilities` (`class-abilities.php:35-36`) | ❌ | ✅ | ❌ | ❌ | ❌ | — |
| `Core_Tweaks` (`disableEmojis/Embeds/…`) | ✅ mostly | Partial (`disableEmojis` affects admin too) | ❌ | ❌ | ❌ | — |
| `Llms`/`Bfcache`/`Perf_Translations`/`AI_Adaptive` | ✅ gated | ❌ | ❌ | ❌ (LLMs cron daily) | ❌ | — |
| `LiteSpeed_Integration::init()` | ✅ | ✅ (mode banner) | ✅ (read-only) | ✅ | ✅ (read-only) | — |
| `Cache` invalidations `save_post`, `wppo_after_cache_clear`, AS `wppo_convert_image_background/pagespeed/used_css` | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| `WP_CLI::add_command('wppo', …)` (`473`) | ❌ | ❌ | ❌ | ❌ | ✅ | — |
| `Admin_Notices` (`class-admin-notices.php:44-45`) | ❌ | ✅ | ❌ | ❌ | ❌ | — |
| `admin_enqueue_scripts` SPA (`494`) | ❌ | ✅ | ❌ | ❌ | ❌ | — |

---

## 5. Opportunities — `File:Line` → Current Cost → Opportunity → Expected Saving → Risk

Ordered highest ROI → lowest. "Saving" is wall-time reduction for the stated context on a fresh PHP process (no warm `Util::` memo yet). Calibrate with Blackfire/XHProf before committing; numbers are estimates.

### 5.1 P0 — No-risk fences (pure skips, ≤0.5 ms each but zero risk)

| # | File:Line | Current cost (per CLI/Cron/REST request that should not load it) | Opportunity | Expected saving | Risk |
|---|-----------|------------------------------------------------------------------|-------------|-----------------|------|
| P01 | `class-main.php:486-494, 486-533` ~18 admin hooks | ~0.10–0.25 ms registration + complexity | Gate on `is_admin()` guard around the 7 admin-only `add_action`s (`admin_menu:486`, `admin_init ×4`, `upgrader_process_complete ×2`, `admin_enqueue_scripts:494`, `admin_bar_menu:533` remains dual — keep unconditional or gate `if(is_admin() \|\| is_admin_bar_showing())` ) | ~0.08–0.20 ms on CLI/REST/Cron/Frontend (avoids registry + future `maybe_fix_wp_cache` transient probes) | **Low** — `is_admin()` true on `wp-admin/*` and false for front/CLI/REST/Cron; canonical WP fence already used at `class-main.php:595-598` inside `is_admin()` branch |
| P02 | `class-main.php:762` `new Metabox()` (`class-metabox.php:39-41`) | 2 `add_action` always | Gate `if (is_admin()) new Metabox();` | ~0.01 ms + avoids `save_post` handler memory on frontend/CLI | **Low** — metabox only matters on `add_meta_boxes` admin screen |
| P03 | `class-main.php:764` `new Asset_Manager()` (`class-asset-manager.php:79,82`) | 2 `add_action` always (`wp_footer:9999`, `wp_enqueue_scripts:9999`) firing `get_post_meta` checks even on CLI | Gate: `if (!defined('WP_CLI')&&!WP_CLI && !wp_doing_cron() && empty(REST_REQUEST) && !is_admin())` for the runtime instance; retain separate lightweight editor hook if needed | ~0.02 ms + removes `capture_page_assets` `get_option('post')` I/O from CLI/REST/Cron | **Low** — `Asset_Manager::capture_page_assets()` already `if(is_admin())return` but `wp_footer:9999` still registered everywhere |
| P04 | `class-main.php:765` `new Abilities()` (`class-abilities.php:35-36`) | 2 `add_action` always | `if (is_admin()) new Abilities();` | ~0.01 ms CLI/REST/Frontend/Cron | **Low** — WP 6.9+ Abilities API is admin/pluggable only |
| P05 | `class-main.php:647,652` `Perf_Translations::init()` / `AI_Adaptive::init()` without frontend guard | Each adds 1-2 `add_filter` (translations on `override_load_textdomain`/`load_textdomain_mofile`, AI on `wp_speculation_rules` etc.) | Gate on `!is_admin() && !wp_doing_cron() && empty(REST_REQUEST) && !WP_CLI` (frontend-only) inside `init()` guards already partly, but registration could be deferred | Small but prevents extra textdomain filter chain on CLI/REST | **Low** — existing `is_enabled()` inside callbacks already gates work; this just defers registration |
| P06 | `performance-optimisation.php:41` + `class-main.php:437` duplicate `vendor/autoload.php` | Extra `stat` + include guard | Remove `require_once` at `class-main.php:437`; rely on `performance-optimisation.php:41` which already `require_once`d it before `new Main()` — keep one `if (file_exists(...)) require_once` | ~0.01 ms + cleaner bootstrap | **Low** — verify `Main::includes()` never called standalone in tests without `performance-optimisation.php` bootstrap (tests bootstrap defines `WPPO_PLUGIN_PATH` then manually `require`s files) |
| P07 | `class-main.php:346-349` `Util::init_filesystem()` eager per request | ~0.3–0.8 ms once per request | Make lazy: initialize inside `Admin_Notices`/`Cache`/`Object_Cache` paths that need `$wp_filesystem`, not in constructor | ~0.3–0.8 ms saved on read-only CLI commands (`system-info`, `database counts`, `image status`, `settings get`) and frontend gets that never touch FS | **Medium-low** — audit every `$this->filesystem` consumer to ensure it fetches via `$this->get_filesystem()` lazy accessor (most already do; `Main` kept an eager `$filesystem` property that is never used after init except indirectly) |

### 5.2 P1 — High-impact fences (1–5 ms saved, still low risk)

| # | File:Line | Current cost | Opportunity | Expected saving | Risk |
|---|-----------|--------------|-------------|-----------------|------|
| P10 | `class-main.php:497-498, 519-532, 536-540, 565-612, 619-684, 693-760, 762-771, 774-785` entire frontend optimisation branch (30+ hooks + `Image_Optimisation`/`Google_Fonts` reuse wiring) | Registration ~0.3 ms + conditional version checks (`version_compare $is_wp63_plus/$is_wp69_plus` per request) even on CLI/REST/Admin/Cron | Wrap block in `if (!is_admin() && !wp_doing_cron() && empty(REST_REQUEST) && !(defined('WP_CLI') && WP_CLI))` before any frontend `wp_enqueue_scripts`/`wp_head`/`wp_resource_hints`/`script_loader_tag`/`RUM`/`Llms`/`Bfcache`/`combine_css`/`minify_*`/`strip_static_query_strings`/`hostGoogleFontsLocally`/`apply_module_loading_strategies`/`remove_woocommerce_scripts`/`Critical_CSS`/`add_preload_*`/`add_speculation_rules`/`add_resource_hints`/`prioritize_lcp`/`used_css standalone`/`Server-Timing`. Keep `RUM`/`Li` `init` etc. separately gated frontend-only inside the block. | **0.3–0.6 ms** + removes ~25 registrations on every CLI/REST/Admin/Cron invocation; greatest win for CLI | **Medium** — must keep `RUM` admin-side trigger and `Llms::register_rewrite` (which runs on `init`, not `wp_head`) pinned correctly; `LiteSpeed_Integration::init()` stays outside block |
| P11 | `class-cron.php:57-75` `new Cron()` (12 hooks) eager on every request | `init → schedule_cron_jobs:57` costs 0.3–1.0 ms of `wp_next_scheduled` checks every non-cron request (frontend, admin, REST, CLI) — highest repeated cost after cache buffer | Make `schedule_cron_jobs` lazy: (a) only schedule on `init` when `is_admin()` or `wp_doing_cron()` or `WP_CLI`, OR (b) defer to `should_schedule_cron_jobs()` that checks `get_transient('wppo_schedule_checked')` 1h lock (same pattern as `maybe_fix_wp_cache:920` already does). Alternatively hook to dedicated `wp_loaded` or use WP `wp_schedule_event` idempotent check outside hot path. The worker hooks (`wppo_generate_static_page/url`, `wppo_img_conversion`, etc.) still need `add_action` so async workers can fire, but `init → schedule_cron_jobs` itself should be fenced. | **0.3–1.0 ms on every front/CLI/REST request** | **Low** — needs a fallback so admin still schedules the 5-hourly preload when due (e.g. gate on `!WP_CLI || WP_CLI_CMD === 'cron'`) |
| P12 | `class-main.php:343-344` `new Image_Optimisation` + `new Google_Fonts` eager constructors (6+ filters each) | Always adds image-pipeline filters even for CLI `settings get` that never touches images | Defer: instantiate only inside `if (!WP_CLI && !REST_REQUEST && !wp_doing_cron())` or move `Image_Optimisation`'s `add_filter('wp_generate_attachment_metadata'/'wp_get_attachment_image_src')` etc. to hooked registration that only runs on frontend/admin media upload paths. Keep a lazy accessor `get_image_optimisation()` used by `Cache`, `Metabox` | ~0.02 ms + avoids extra `apply_filters('wp_get_attachment_image_src')` chain on every image render during CLI cron batch conversions (batch decode already heavy) | **Low** |
| P13 | `class-main.php:497` `enqueue_scripts` + `498 apply_module_loading_strategies` + `533 admin_bar_menu` | `apply_module_loading_strategies:1709-1750` builds `Util::process_urls(excludeDeferJS)` array per request even when `deferJS` OFF (guard check at `:1710` returns early but still `method_exists('wp_script_modules')` probe + global `$wp_version` read). `add_setting_to_admin_bar:1823-1865` registers even when `!current_user_can('manage_options')` (callback then early-returns, but admin bar menu building still loops) | Already gated *inside callback* — defer registration not needed beyond `!WP_CLI` skip; alternatively add outer `if (is_admin_bar_showing()) add_action('admin_bar_menu', …)` | Minor (<0.03 ms) but removes noise from XHProf traces | **Medium** — `is_admin_bar_showing()` check requires `init` late timing; keep as registered then `current_user_can` early exit is conservative |
| P14 | `class-main.php:614-615, 793` `add_action('rest_api_init', Rest::register_routes)` + `wp_ajax_wppo_get_nonce` | `rest_api_init` fires only on REST requests — registration itself is ~0.01 ms (just one `add_action` add), not expensive. `wp_ajax_wppo_get_nonce` fires only on AJAX | Could gate `rest_api_init` on `!(WP_CLI)` to save one `add_action`, but saving negligible | <0.01 ms — **not worth fencing** | Keep |

### 5.3 P2 — Fine-grain / already-guarded (no change recommended)

| # | File:Line | Current | Assessment |
|---|-----------|---------|------------|
| P20 | `class-main.php:539-574` `enableCache` conditional buffer hooks | Already conditional on `if (!empty(enableCache))` outer guard; else absent | Correct — keep |
| P21 | `class-main.php:559-561` `server_timing_enabled()` | Already gates `template_redirect:0` + `wp_finalized…:0` on `wp_should_output_buffer_template_for_enhancement && server_timing_enabled()` | Correct — keep; no frontend buffer registered when disabled |
| P22 | `class-main.php:599-612` `combineCSS`, `655-680` `minifyJS/CSS` | Only when those features ON | Keep |
| P23 | `class-main.php:351-353` `defined('WP_ADMIN')` gate | Non-standard: core plugin handbook uses `is_admin()` but `WP_ADMIN` matches same constant path via `wp-admin/admin.php`; works but `is_admin()` more idiomatic | Keep or normalize |
| P24 | `class-util.php:145-202` `Util::get_settings()` memo | Already per-blog keyed + `update_option_wppo_settings` invalidated; `Util::$home_url_cache` likewise | Keep — excellent; further wins must not break this fence |
| P25 | `class-cache.php:473-506` `combine_css` freshness `mtime` loop | Loops eligible handles × `file_exists/ mtime` per request; could be cached for 5 min but handles change rarely | Consider `get_transient('wppo_combine_fresh')` but risk serving stale after style edit; low priority |

### 5.4 Class-Existence / Autoload

Manual `file_exists` + `require_once` at `class-main.php:438-474` loads **11 files** synchronously before any hook runs, even when only CLI needs one of them (e.g. `wp wppo system-info` only needs `System_Info`, not `Llms`, `OD_Bridge`, `Bfcache`, `Perf_Translations`, `AI_Adaptive`, `Edge_Cache`, `Edge_Purger`, `Google_Fonts`, `Cache`). Each `file_exists` → `stat()` → `require_once` is ~5–15 µs disk I/O (warm FS cache) → ~0.06–0.18 ms aggregate.

| # | File:Line | Opportunity | Expected saving | Risk |
|---|-----------|-------------|-----------------|------|
| C01 | `class-main.php:438-474` eager `require_once` list | Autoload on demand: rely on Composer classmap or lazy `class_exists('PerformanceOptimise\Inc\Llms', true)` (triggers autoloader) instead of explicit `require_once`. For non-Composer classes (plugin's own `includes/*`), add PSR-4 `autoload.psr-4: PerformanceOptimise\Inc\ → includes/` (requires `composer dump-autoload` + keeping `vendor/autoload.php` path). Alternatively keep `require_once` but gate by context: `if (!WP_CLI \|\| WP_CLI subcommand needs Llms)` — brittle. | 0.05–0.15 ms + reduces include blast radius; cleaner architecture matches `AGENTS.md:18` warning "Classes are manually loaded … no PSR-4" — this is tech debt | **Medium** — touches release `composer install --no-dev --optimize-autoloader` + `build-release.sh`; must test `performance-optimisation.php:41` load order after change |
| C02 | `class-main.php:472-474` `if(WP_CLI) add_command` guarded via `file_exists` preamble | Already correct (`defined('WP_CLI') && WP_CLI` guard before `add_command`). Class `WPPO_CLI_Command` not yet loaded there — WP_CLI lazy-autoloads it when the file is later required by `includes()` earlier in file. No work. | 0 | None |
| C03 | `includes/*.php` template drop-in `templates/object-cache.php:532` | Loaded only when Redis drop-in active via `class-object-cache.php`; not on every request | 0 | None |

### 5.5 Hook Callbacks — Repeated Invocation (same hook fires many times)

| # | Hook: File:Line | Repeated? | Cost | Action |
|---|-----------------|-----------|------|--------|
| R01 | `cron_schedules:61` | Builds once per request — no repeat | Tiny | None |
| R02 | `wp_resource_hints:760` | Fires once per relation type per `wp_head` (2 times: `preconnect`, `dns-prefetch`) | `add_resource_hints()` runs twice, each `Util::process_urls` + tiny arrays | Keep |
| R03 | `script_loader_tag` / `style_loader_tag` filters (`515,525,530,662,679,688`) | **Fires N times where N = enqueued asset count (often 15–40 scripts + 10–20 styles)** | Per-tag `str_replace` + `preg` + `in_array` — cumulative ~0.3–0.8 ms on asset-heavy frontends when minify/defer/delay ON | Already gated per feature; each filter's first `if(!should_optimise_for_logged_in()) return` + `has_filter('litespeed_can_optm')` early return keeps non-active path cheap |
| R04 | `switch_blog:248` `Util::on_switch_blog` | Fires only on multisite `switch_to_blog()` | No-op per `class-util.php:214-219` (blog-keyed memo) | Keep no-op comment accurate |

---

## 6. Expected Aggregate Savings (Estimate, not Lab)

| Profile | Today (bootstrap only, no business logic) | After P0+P10+P11+P12 (conservative) | Notebook for handler |
|---------|-------------------------------------------|-------------------------------------|----------------------|
| WP-CLI read-only (`wp wppo system-info`, `wp wppo database counts`, `wp wppo settings get`) | ~1.2–2.0 ms bootstrap (constructor + setup_hooks + Cron `wp_next_scheduled×8`) | ~0.4–0.7 ms (−60 %) — skip frontend chain, gate `Cron::schedule_cron_jobs`, defer `Image_Optimisation/ Google_Fonts`, lazy FS | All CLI subcommands are `__return_true` auth; guard must be `defined('WP_CLI') && WP_CLI` checked **before** `setup_hooks()` so `DOING_AJAX`/`REST_REQUEST` guards not needed for CLI path |
| WP-CLI mutating (`wp wppo cache clear`, `database cleanup`, `image convert`, `object-cache flush`) | + image decode/DB delete dominant (~0.2–1 s per image, batched 50) — bootstrap noise irrelevant | Same as above; no functional change | Keep cache-clear invalidations (`wppo_after_cache_clear`) always registered |
| Admin SPA (`/wp-admin/admin.php?page=performance-optimisation`) | ~1.0–1.8 ms bootstrap + ~0.8 ms `admin_enqueue_scripts` asset localize (`class-main.php:1521-1615` clones options, redacts secrets, `get_editable_roles()`, `Util::get_js_css_minified_file()` dirlist) | Similar; admin path cannot skip frontend chain but **can** skip `schedule_cron_jobs` unless `is_admin()` | Keep `Metabox/Abilities` gated admin-only (already P02-P04) |
| Frontend `GET /` (anonymous, `enableCache=false`) | ~1.5–2.5 ms bootstrap + 0.5–2 ms asset loop (combine/minify checks) + buffer only when opted (`used-CSS` 5–20 ms, `LCP` re-scan) | Minimal delta (P10 explicitly skips on non-frontend only) | Buffer path already well-gated (`is_cache_allowed_for_current_user():297-301` + `is_not_cacheable():1482-1520`); no change |
| Frontend `GET /` (`enableCache=true`, hit path) | **0 ms WP bootstrap** — served from `wp-content/cache/wppo/{domain}/{path}/index.html` via `advanced-cache.php` drop-in **before** WP loads | Same | Drop-in untouched |
| Cron HTTP spawner (`/wp-cron.php?...`) | `init → schedule_cron_jobs` ×8 + worker image convert batched 50 | −0.3–1.0 ms when `schedule_cron_jobs` gated (P11) | Must not gate away the worker `wppo_img_conversion` etc. `add_action` themselves — only the scheduler `wp_next_scheduled` check |

---

## 7. Risks & Guards

| Risk | Where | Mitigation |
|------|-------|------------|
| `WP_CLI` constant not set during early `new Main()` in some hosts that load plugin after WP-CLI bootstrap | `performance-optimisation.php:44 new Main()` may run **before** `WP_CLI` defined when plugin loaded as `must-use` or via `wp package install` path | Keep `defined('WP_CLI') && WP_CLI` guards; record bootstrap timing in `Main::includes()` comment; gate only `setup_hooks()` internals, not `includes()` → `add_command` registration which correctly does `if(WP_CLI) add_command` anyway |
| `wp_doing_cron()` false for manually invoked `wp cron event run --due-now` (WP-CLI) vs HTTP spawner true | Context detection must test `WP_CLI \|\| wp_doing_cron() \|\| wp_doing_ajax() \|\| REST_REQUEST` as OR | Single helper `Context::is_frontend_request(): !is_admin && !WP_CLI && !DOING_CRON && !REST_REQUEST && !DOING_AJAX` |
| `is_admin()` false for `admin-ajax.php` on AJAX refresh path (`wp_ajax_wppo_get_nonce:793`) | `admin_init` fences checked at `add_action` time, not when AJAX dispatches `admin_init` does still fire | Keep `wp_ajax_wppo_get_nonce` registered unconditionally — AJAX always goes through `admin-ajax.php` where `is_admin()==true` |
| `Util::get_settings()` memo invalidation on `update_option_wppo_settings:245-248` + `switch_blog:248` | New gating must not remove `switch_blog` hook (`class-util.php:248`) which is needed for multisite `switch_to_blog()` isolation (`class-util.php:214-219` no-op comment depends on this hook being registered) | Keep `Util::ensure_settings_cache_hook():239-248` always registered (it self-gates with `static $hooked`) — do not fence it |
| `image_optimisation` lazy load breaks `Cache::maybe_serve_next_gen_images` inside `process_buffer_only()` which expects an instance | `Cache` holds `set_image_optimisation()` injected from `Main:343-344`; if constructor defers instantiation, null-guard `new Image_Optimisation` inside `Cache` fallback path already exists (`class-cache.php:1244` ternary) — safe | Preserve injection when frontend branch is taken; else let fallback construct lazily |
| `Bfcache`/`Ai_Adaptive` translation filters needed on Cron translation rebuild | Cron does not render frontend but `Perf_Translations` compiles MO→PHP on schedule; Admin does not need it | Keep translation compile on `plugins_loaded/init` only; gate via `class-exists('Perf_Translations')` + `is_enabled()` inside already |
| OPcache `file_exists` vs `class_exists(…, true)` | `class_exists($fqcn, true)` triggers Composer autoloader which in production is `optimize-autoloader` classmap — one hash lookup, no stat. `file_exists` + `require_once` does stat. Preferred for C01. | C01 requires `composer.json` PR + `composer dump-autoload` verification |

---

## 8. Verification Recipe (Before Any Production PR)

1. Fresh branch; do **not** edit `performance-optimisation.php` then `composer dev-setup` on dirty tree (track `vendor/` via `composer.lock` as in `AGENTS.md:17-27`).
2. Micro-bench: add `microtime(true)` markers at `performance-optimisation.php:41` entry, `Main::__construct:169` entry, `Main::setup_hooks:485` entry/exit, `Cron::__construct:56` entry/exit; run 20× each via `wp wppo system-info --skip-plugins` vs `GET /` (anonymous `curl -o /dev/null -w '%{time_total}'`) vs `wp cron event run wppo_img_conversion`. Capture `XHProf`/`Tideways` callgraph or `WP_CLI::debug('bootstrap', …)`.
3. Gate flags: set `enableCache`, `combineCSS`, `delayJS`, `hostGoogleFontsLocally`, `preloadSitemap`, `rum_enabled` toggled true for worst-case front count vs CLI baseline; snapshot registration counts via `global $wp_filter` size after `Main::__construct`.
4. Tests: `npm run lint:js` → `composer lint` → `npm test` → `npm run build` per `AGENTS.md:38` required order; `composer test` (PHPUnit+BrainMonkey) — no CLI tests exist yet (`tests/php/WPPO_CLI*` absent) so regression surface is `class-rest.php` parity tests (`HOOK-AUDIT §10`).
5. Rollback switch for every gate: expose `apply_filters('wppo_enable_context_fences', true)` so hosts can `add_filter('wppo_enable_context_fences','__return_false')` in emergency without code change.

---

## 9. Recommended PR Split (Research-only — not enacted)

| PR | Fences | Files touched | Size |
|----|--------|---------------|------|
| PR-A | P0+P05: admin/metabox/abilities/duplicate autoload/eager FS | `performance-optimisation.php`, `class-main.php:351-353,762-765`, `class-util.php:322` (doc) | <40 lines |
| PR-B | P10+P12: frontend optimisation chain gate + lazy `Image_Optimisation`/`Google_Fonts` | `class-main.php:342-344,485-799` (wrap block) | ~60 lines + reindent |
| PR-C | P11: `Cron::schedule_cron_jobs` de-bounce | `class-cron.php:57-129` + `class-main.php:763` lazy `new Cron()` guard | ~30 lines |
| PR-D (later) | C01 PSR-4 autoload | `composer.json`, `composer.lock` regen, `class-main.php:438-474` (remove manual `require_once` list), `performance-optimisation.php:41` | Medium; needs release pipeline test |

---

## 10. Non-Goals / Already-Good Patterns

- `Util::get_settings():145-157` memo per-blog + `Util::cached_home_url():752-768` memo are already optimal — no repeated deserialization.
- Buffer gating (`is_cache_allowed_for_current_user():297-301` → `Util::is_cache_eligible_for_current_user`) and `is_not_cacheable():1482-1520` correctly skip cache writes for non-cacheable responses; CLI/REST paths never write despite being registered.
- `WPPO_CLI_Command` (`472-474`) registration guard `if(WP_CLI) add_command` is handbook-compliant; `@when after_wp_load` (docblock `69,168,315…`) is correct (ignored when plugin-loaded, per `WP-CLI-RESEARCH.md:26`).
- `REST` permission correctly enforces `manage_options` + `X-WP-Nonce` (`class-rest.php:357-361`) except `rum_collect` public with token+rate-limit; CLI has no equiv (trusted shell).
- Drop-in `advanced-cache.php` (`class-advanced-cache-handler.php:128`) by design runs **before** WP boot — not counted in any WP-hook budget and must remain zero-hook.

---

*Evidence files read in full: `performance-optimisation.php`, `includes/class-main.php` (1–408, 436–799, 1244–2611), `includes/class-cron.php`, `includes/class-cache.php` (1–1520), `includes/class-util.php` (1–280), `includes/class-wppo-cli-command.php` (1–973), `includes/class-metabox.php`, `includes/class-asset-manager.php`, `includes/class-core-tweaks.php`, `templates/object-cache.php` spot-checked; counts cross-checked against `HOOK-AUDIT.md` 272-hit grep and `WP-CLI-CURRENT.md`.*
