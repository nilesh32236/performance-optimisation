# FINAL WORDPRESS REVIEW — fix/audit-2026-08-28

**Reviewer:** Agent J  
**Branch:** `fix/audit-2026-08-28` @ `44d7bcbf` | `origin/master@31fffc61`  
**Method:** Traced `add_action/filter`, `manage_options`/`X-WP-Nonce`, `switch_to_blog`, `is_multisite`, `wp_parse_url`, `ABSPATH` guards, server detection, option lifecycle.

---

## 1. Fixes Verified (WordPress-Lifecycle)

| ID | File:Line | Finding | Verdict | Evidence |
|---|---|---|---|---|
| **C-01 wppo_run_upgrades** | `class-main.php:489` | Dead upgrade hook — Redis legacy `cache-key eviction` retry never fired | **FIXED+VERIFIED** | `add_action('wppo_run_upgrades', array('PerformanceOptimise\Inc\Activate','maybe_run_upgrades'))` now matches `class-activate.php:11` namespace. Hook registered on `admin_init:maybe_run_upgrades` + `upgrader_process_complete:maybe_schedule_upgrade_routine` already correct. `Activate::maybe_seed_settings` handles `get_option('wppo_settings',null)` fresh-install false vs array semantics `class-activate.php:111` — preserved. |
| **H-06 metabox screen** | `class-metabox.php:54` | `add_meta_box(...,'',...)` invalid screen → preload UI invisible | **FIXED+VERIFIED** | `add_meta_box('preload_image_metabox',...,$post_types,'side','default')` `class-metabox.php:54` where `$post_types = array_diff(get_post_types(['public'=>true],'names'),['attachment'])` `class-metabox.php:50`. Second box already loops `$post_types` individually `class-metabox.php:65` — now consistent. `WP_Screen` accepts `string|array` — `array` valid. Save `save_preload_image_urls` nonce+cap gated unchanged. |
| **H-05 Asset Manager gate** | `class-asset-manager.php:92` | `is_user_logged_in` bail prevented logged-in dequeue | **FIXED+VERIFIED** | `if(is_admin() \|\| is_user_logged_in())` → `if(is_admin())` `class-asset-manager.php:92`. `dequeue_selected_assets` hooked `wp_enqueue_scripts 9999` — logged-in frontend now dequeues disabled handles; `is_admin()` still prevents editor. WP lifecycle correct (no `init` timing issue). Note: may dequeue handles required for logged-in UX (see security.md caveat). |
| **Multisite settings memo** | `class-util.php:87-278` `uninstall.php:190-216` | `Util::get_settings` leak cross-blog + `Util::transient_key` 86-site isolation needed | **FIXED+VERIFIED** | Blog-keyed `settings_cache[bid]` + `current_blog_id()` try/catch `class-util.php:122-148` + `switch_blog` hook `class-util.php:248` (no-op, per-blog keying already isolates). `uninstall.php:194-216` iterates `get_sites(limit 100, offset)` + `switch_to_blog/restore` + per-site `wppo_cleanup_site()` with `transient_prefix = is_multisite()?get_current_blog_id()+'_':''` `uninstall.php:106`. `transient_key()` `class-util.php:781-790` prefixes `blog_id+'_'+key` when `is_multisite()`, try/catch on `get_current_blog_id` stub — retained 86-site isolation verified. `Used_CSS`, `Cache`, `object-cache.php` `blog_prefix` still domain+blog scoped (not changed). |
| **Util memo consistency** | `class-util.php:239-278` | Hook invalidation | **FIXED+VERIFIED** | `ensure_settings_cache_hook` static `$hooked` `class-util.php:240-249` + `update_option_wppo_settings`→`on_settings_update` `class-util.php:259-262` blog-keyed + `add_option`→`on_settings_add` `class-util.php:273-278` + `delete_option`→`clear_settings_cache` `class-util.php:247`. Direct `get_option('wppo_settings',array())` callers collapsed to `Util::get_settings()` (15 files) — read path now single source. |
| **Cache ABSPATH guard** | `class-cache.php:242,1880` `class-rest.php:413` `uninstall.php:149` | Path traversal via `..` or sibling `abspath2` prefix | **RETAINED VERIFIED** | `Util::get_local_path` enforces `strpos(full_path, normalized_abspath)===0` `class-util.php:370-375` with trailing `/` on `ABSPATH`; `clear_cache` fallback `candidate_path` + literal `..` check `class-rest.php:413`; `Cache::get_file_path` literal `..` check `class-cache.php:1880`. Encoded `%2e%2e` becomes literal filename, not traversal (see security). No regression. |
| **Cron server-bump** | `class-cron.php:202-639` | `web_vitals_rescan` no lock + `img_convert` 5m race | **FIXED+VERIFIED** | `web_vitals_rescan` transient_lock 10m `try/finally` + `clear_cron_jobs` delete `class-cron.php:420`; `img_convert 15m` `class-cron.php:639`; all locks via `Util::transient_key` multisite-safe. `wp_clear_scheduled_hook` on unschedule covers all 6 hooks (`wppo_preload_cron_lock`, `wppo_used_css_lock`, now `wppo_web_vitals_rescan_lock`) `class-cron.php:412-420`. |
| **REST settings preservation** | `class-rest.php:488,665,772` `class-wppo-cli-command.php:309,560,652` | Settings API raw vs memo | **FIXED+VERIFIED** | `update_settings:488` + `import_settings:772` + `pagespeed keep` now `Util::get_settings()`; `preserve pagespeed_api_key` still `sanitize_text_field` `class-rest.php:474` — safe round-trip. CLI `settings get/set` `class-wppo-cli-command.php:560,652` now via memo. |
| **Brain Monkey compat** | `tests/php/bootstrap.php:17` `class-util.php:122-131` | WP function stub mis-config caused `get_current_blog_id` exception in tests | **FIXED+VERIFIED** | `current_blog_id()` `try/catch (Throwable)` returns 0 on stub error `class-util.php:128`; `bootstrap.php` `reset_all_caches` per test ensures isolation. `phpunit` 471/471 OK (2 skipped) confirms. |
| **CLI tables + types** | `class-wppo-cli-command.php:180-270` `class-database-cleanup.php:1040-1090` | Missing `trashed/unattached/oembed` + raw LIMIT | **FIXED+VERIFIED** | Allowlist `TABLE_MAP` + 3 new `case trashed/unattached/oembed` `class-wppo-cli-command.php:250-270` + `LIMIT %d` `class-database-cleanup.php:630`. 9-type parity with `Database_Cleanup` + `Abilities` now. |

## 2. WordPress Standards / wp.org

- `readme.txt:6` `Tested up to: 7.1` matches changelog 1.9.0 WP 7.1 features — retained intentional (A13). `== External Services ==` disclosed `pagespeedonline.googleapis.com` + `fonts.googleapis.com` `readme.txt:279` — PASS (already fixed prior branch, retained).
- `build/` committed `build/index.asset.php` `build/style-index.css` etc. `npm run build` webpack 5.109 verified 244 KiB.
- `.distignore` anchored, no obfuscated code (base64 LQIP only), `PHPCS` `includes/` 0e3w (WordPress standard) — PASS.
- No `base64` obfuscation, no remote code execution, no `eval`.

## 3. Compatibility — Server / PHP / LiteSpeed

- **PHP 8.2 min** `composer.json:55` (not changed). `php -l` 42/42 clean on 8.3.33. `??` null-coalesce, typed properties, `array` type hints — 8.2+ correct.
- **Server type** `get_server_type` `class-server-rules.php:34` `apache/nginx/litespeed/other` with `is_litespeed` grace retained. No change.
- **LiteSpeed integration** 4-state `auto/wppo/litespeed/standalone` arbitration `class-litespeed-integration.php:74` not touched this branch (direct `get_option` now via `Util::get_settings()` but logic unchanged) — no regression.
- **Object cache drop-in** `templates/object-cache.php:69,211` `blog_prefix` + `add_salt` still per-blog — retained.

## 4. Remaining Gaps (Deferred / Low)

- **God class Main** `class-main.php:21` 3053 lines McCabe>30 — façade extraction `Settings_Registry/Hook_Registrar` deferred P4 (correct, large refactor).
- `P5-07 readme Tested up to` bump after 7.2 smoke — intentionally kept 7.1 per A13 F-COMPAT-01, not a compat break.

## 5. Verdict

**PASS.** Multisite transient isolation, settings-memo blog keying, metabox screen handling, and upgrade-hook lifecycle all traced and verified. No WP.org guideline regression. `is_multisite` + `Util::transient_key` 86-site coverage intact. Hooks registered at correct `init`/`admin_init`/`shutdown` timing. Recommend shipping.
