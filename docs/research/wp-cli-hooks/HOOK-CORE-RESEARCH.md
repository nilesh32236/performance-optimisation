# WP Core Hook Lifecycle Precision Audit — performance-optimisation

**Date:** 2026-08-28 · **Status:** Research-only (no production edits) · **Method:** Read `includes/class-main.php:485-799` `setup_hooks()`, `includes/class-cache.php`, `includes/class-cron.php:57-74`, `includes/class-image-optimisation.php:170-212`, `includes/class-asset-manager.php:77-83`, `includes/class-advanced-cache-handler.php`, `includes/class-core-tweaks.php`, `includes/class-llms.php:81-90`, `includes/class-bfcache.php:377-385`, core lifecycle docs (`developer.wordpress.org/reference/hooks/`), version-gated paths (`is_wp63_plus`, `is_wp69_plus`). Every verdict cites `file:line`.

> Complements [`HOOK-AUDIT.md`](./HOOK-AUDIT.md) (272-hit full audit). This file answers: *Is the plugin on the right lifecycle, or is a more precise/performant/compatible/extensible core hook available without coupling?*

**Related:** `ECOSYSTEM-RESEARCH.md §3` (roll-up), `docs/hooks.md` (42 public `wppo_*`), `AGENTS.md:79` (`wppo_inline_combined_css`).

---

## 0. Verdict Summary

| # | Category | Current hook(s) | Verdict | Better alternative | Why | Cost of change |
|---|----------|-----------------|---------|-------------------|-----|----------------|
| 1 | Frontend cache buffer | `template_redirect` (legacy) + `wp_template_enhancement_output_buffer`/`wp_finalized_template_enhancement_output_buffer` (6.9+) — `class-main.php:545-546,550` | **Correct (version-dual is intentional)** | Keep dual; no alternative | WP 6.9 `wp_should_output_buffer_template_for_enhancement()` + `wp_template_enhancement_output_buffer` (filter) / `wp_finalized_template_enhancement_output_buffer` (action) replaces `template_redirect+ob_start` and preserves `DONOTCACHEPAGE` marker. Legacy kept via `TODO #553` gated `!is_wp69_plus`. Moving cache to `send_headers` or `wp` would be too early (query not resolved, 404/role-hash unknown). | None — remove legacy only when min WP → 6.9 |
| 2 | Server-Timing header | `template_redirect:0` capture + `wp_finalized_template_enhancement_output_buffer:0` emit — `class-main.php:560-561` | **Mostly correct — minor precision nit** | Emit at `send_headers` (or keep as-is with doc note) | `capture_template_start` at `template_redirect:0` is ideal (fires after query, before buffer). `emit_server_timing_header` at `wp_finalized_template_enhancement_output_buffer:0` forces the 6.9 buffer on even when streaming disabled, as noted `class-main.php:556-558`. `send_headers` would allow timing without forcing the buffer, but then `current_role_hash` not yet set if early return. Keeping current is defensible; document trade-off. | Low — only if `server_timing_enabled` seeks streaming |
| 3 | Used-CSS / LCP priority buffers | `wp_template_enhancement_output_buffer:20/30` + `template_redirect:20` — `class-main.php:568,572,584,590` | **Correct** | None | Priorities 10(cache)→20(used-CSS)→30(LCP) mirror pipeline `process_buffer_only` order. Legacy `template_redirect:20` runs *outside* cache buffer so inner callback sees raw HTML — comment at `class-main.php:586-589` explains. Earlier `wp` would miss `is_singular`/theme state; later `shutdown` too late. | None |
| 4 | `send_headers` — LLMs Link header | `send_headers` → `Llms::emit_link_header` — `class-main.php:634` | **Correct** | None | `send_headers` is defined for `Link:` headers; earlier `init` can't call `header()`, later `template_redirect` already started output. | — |
| 5 | `admin_init` — upgrades / wp_cache fix / block-assets migrate | `admin_init:10` ×4 — `class-main.php:487,488,491,493` | **Correct** | Keep; `current_screen` would be later but unnecessary | `admin_init` runs only in admin, after `admin_menu`, before `current_screen`; safe to write options/transients. Throttles (`Util::transient_key('wppo_wp_cache_fix_checked')` 1h) limit I/O. `upgrader_process_complete` companion `:490,492` covers non-admin updates. | — |
| 6 | `admin_menu` — SPA mount | `admin_menu:10` — `class-main.php:486` | **Correct** | None | Canonical for `add_menu_page`/`add_submenu_page`. `admin_init` too early, `current_screen` too late. | — |
| 7 | `admin_bar_menu:100` — Clear cache | `admin_bar_menu:100` — `class-main.php:533` | **Correct** | None | Late priority ensures core nodes exist. Earlier 10 would precede core. | — |
| 8 | `init:10` — role-hash cookie | `init:10` — `class-main.php:495` | **Correct** | Not `wp_loaded` nor `template_redirect` | Must set `wppo_role_hash` before `advanced-cache.php` serves *next* request; `init` runs before cache decision and before `wp` query. `template_redirect` too late (page already rendered). `send_headers` would work but `init` allows multisite `COOKIEPATH`. Guard `is_admin||wp_doing_ajax||REST_REQUEST` at `class-main.php:401` avoids admin. | — |
| 9 | `wp_logout` — clear role hash | `wp_logout:10` — `class-main.php:496` | **Correct** | None | Only fires on logout. `clear_auth_cookie` would also clear but `wp_logout` is precise. | — |
| 10 | `rest_api_init:10` — 25 routes | `rest_api_init:10` — `class-main.php:615` | **Correct** | None | Only correct hook for `register_rest_route`. `init` would run too early (rest server not ready). Missing: `args` schema + explicit `methods` arrays; add later when exposing public API, but not a lifecycle miss. | — |
| 11 | `init:10` — Cron scheduling | `init:10` → `Cron::schedule_cron_jobs` — `class-cron.php:57` + `add_filter(cron_schedules)` — `:61` | **Correct** | Keep `init`; `wp_loaded` would also work but is undocumented for cron | WP Handbook registers `cron_schedules` and `wp_schedule_event` on `init`/`wp_loaded`; `init` is canonical (Codex) and ensures `wp_next_scheduled` check runs on every front/admin/CLI request without needing query. `wp_loaded` would be marginally later but not more performant (same per-request cost). `plugins_loaded` too early (Cron class not yet constructed). | — |
| 12 | `init:10` — Llms rewrite | `init:10` → `Llms::register_rewrite` — `class-main.php:631` | **Correct** | None | `add_rewrite_rule` must run on `init`. Later `wp_loaded` would miss rewrite flush caching; earlier `plugins_loaded` missing `$wp_rewrite`. `query_vars:10` filter `:632` correctly paired. | — |
| 13 | `wp_enqueue_scripts:5/10/999/1000/10000/PHP_INT_MAX-1/PHP_INT_MAX` + `wp_head:0/1` | `class-main.php:497,498,536,608,611,619,676,758,759` | **Correct — ordered chain is intentional** | No single better hook; alternative `wp_print_styles/scripts` noted below | Sequence `RUM:5 → enqueue:10 → remove_woocommerce:999 → defer:1000 → apply_module_strategies:10000 → minify_queued_styles:MAX-1 → combine:MAX → wp_head:0(speculation) 1(preload/preconnect/combine-preload)` guarantees deps resolved before combine/minify and before `<link>` print at 8. `PHP_INT_MAX` fragile but safe because only this plugin uses MAX; `wp_print_styles` (fires inside `wp_head` after queue finalized) would be more robust for `combine_css` but then `maybe_preload_combine_css` race (must be before prio 8). Keeping enqueue chain is correct. | None required; optionally move `combine_css` to `wp_print_styles:PHP_INT_MAX` if conflicts observed |
| 14 | `script_loader_tag:10/11` + `style_loader_tag:9/10` — defer/delay/minify/Google-Fonts | `class-main.php:515,525,530,662,679,688` + `class-critical-css.php:770` | **Correct — version-gated fallback is correct** | Keep dual path | WP 6.3+ native `strategy` handled via `wp_enqueue_scripts:1000 add_defer_strategy`; `<6.3` uses `script_loader_tag:10 add_defer_attribute_legacy` (`TODO #553`). `fetchpriority` regex fallback only `!is_wp69_plus` (Trac #61734). `style_loader_tag:9` for Google Fonts (before minify at 10) correct. | None until min WP →6.3/6.9 |
| 15 | `wp:10` — per-page delay config | `wp:10` → `apply_per_page_delay_config` — `class-main.php:516` (only when `delayJS`) | **Correct** | Not `template_redirect` nor `wp_enqueue_scripts` | `get_the_ID()`/`Asset_Manager` needs queried object; `wp` is first hook where main query → `$post` ready (after `parse_query`/`pre_get_posts`). `wp_enqueue_scripts` too late (delay lists already consumed by `wp_script_add_data`). | — |
| 16 | `wp_footer:9999` capture + `wp_enqueue_scripts:9999` dequeue — `Asset_Manager` | `class-asset-manager.php:79,82` (via `class-main.php:764`) | **Correct — capture deliberately late** | Not `shutdown` (too late for transients debug) nor `wp` (queue not final) | Capture must run after all enqueues (themes/plugins enqueue at default 10). `wp_footer:9999` sees `$wp_scripts->done` post-print; `wp_print_footer_scripts:9` would also work but less reliable. Dequeue at `wp_enqueue_scripts:9999` correctly wins over prior `wp_enqueue_scripts:10-1000` registrations. | — |
| 17 | `wp_generate_attachment_metadata:10` + `wp_get_attachment_image_src` — Img_Converter | `class-image-optimisation.php:185,187` | **Correct** | Optionally add `wp_after_insert_attachment` as secondary | `wp_generate_attachment_metadata` is canonical for server-side sub-size queueing (receives `$metadata['sizes']`). `wp_get_attachment_image_src` for serving next-gen is correct (filters every `wp_get_attachment_image_src()` call). `wp_after_insert_attachment` (WP 5.6+) could supplement placeholder extraction for already-uploaded originals — already handled via `store_placeholder_data_for_upload` inside same filter. No change. | — |
| 18 | `pre_get_posts` | Not used | **Correctly unused** | — | Plugin never alters main query; no reason to hook `pre_get_posts`. | — |
| 19 | `pre_http_request` | Not used | **Correctly unused; optional extension point** | Add only if telemetry caching/intercept desired | Telemetry `Telemetry::scan` uses `wp_remote_get` with `wppo_telemetry_verify_ssl` filter (`class-telemetry.php:227`) at 4 call sites; `pre_http_request` could short-circuit for tests/offline. Not needed globally — would couple every `wp_remote_get`. | — |
| 20 | `image_editor` family | Not hooked (uses GD/Imagick directly) | **Correct** | Could expose `wp_image_editor_before_change` if adding editor customization | Plugin delegates to `Img_Converter::convert_image` with GD/Imagick; `wp_get_image_editor()` not used so `image_editor_output_format` (WP 6.7+) handled via `resolve_output_format()` + `wp_get_image_editor_output_format()` read. No direct hook needed. | — |
| 21 | `shutdown` — `Img_Converter::commit_img_info` + `RUM::flush_shutdown_buffer` | `class-img-converter.php:1750` + `class-rum.php:352` (conditional) | **Correct** | Not `wp_loaded` nor `template_redirect` | Deferred `wppo_img_info` batching pattern (`.jules/bolt.md:9`) collapses many `update_option` to one `shutdown` — avoids `save_post`/`wp_generate_attachment_metadata` write amplification. `shutdown` is defined for final flush; `wp_footer` too early (misses REST/cron). | — |
| 22 | `save_post:10` — cache invalidation, used-CSS queue, DB counts | `class-main.php:552,784,596` + `deleted_post:597` | **Correct** | Keep `save_post`; not `wp_after_insert_post` nor `transition_post_status` | `save_post:10` fires after `wp_insert_post` with `$update` flag; guard `wp_is_post_revision||wp_is_post_autosave` at `class-main.php:1176-1177,1197-1198` prevents loops. `wp_after_insert_post` (WP 5.7+) would also give terms but not needed for `invalidate_dynamic_static_html`. `transition_post_status` would fire for status changes but misses meta-only updates that still invalidate. | — |
| 23 | `update_option_wppo_settings:10 + add_option/delete_option + switch_blog:10` | `class-util.php:245-248` + `class-main.php:789` + `class-llms.php:637` | **Correct** | Not `updated_option` generic | Dynamic option hooks (`update_option_{$option}`) precise — only fires for `wppo_settings`. Generic `updated_option` would fire for every option. `switch_blog:10` correctly isolates multisite `Util::cached_home_url` + settings memo. | — |
| 24 | `wp_resource_hints:10(2)` — preconnect/dns-prefetch | `class-main.php:760` + `class-core-tweaks.php:117` (emoji strip) | **Correct** | Keep; `wp_head` generation is handled by `wppo_preload` separate path | `wp_resource_hints` is specified for `dns-prefetch`/`preconnect` (with `$relation_type` arg); earlier `wp_head:0` speculation rules correctly at `add_speculation_rules:0` before `add_preload:1`. No dedup needed. | — |
| 25 | `wp_maybe_inline_styles` (not hooked — consumed) | `class-cache.php:1047 apply_filters(styles_inline_size_limit)` + `minify_queued_styles:MAX-1` path check | **Correct** | Do not add direct `wp_maybe_inline_styles` hook | Plugin *reads* core inline budget via `styles_inline_size_limit` (20k/40k gate) and registers `path` data for core to inline; hooking `wp_maybe_inline_styles` itself unnecessary. | — |
| 26 | `current_screen` (admin) | Not used | **Optional — not a miss** | Could adopt `current_screen` for admin heavier checks | Current admin hooks are thin (`admin_init` guards): `maybe_fix_wp_cache` (I/O throttled), `maybe_run_upgrades`, `admin_enqueue_scripts` (SPA only on own screen via `Main::admin_enqueue_scripts`). Moving to `current_screen` would reduce one `get_transient` per non-plugin admin page — micro-opt. Not worth coupling; keep `admin_init`. | — |
| 27 | `advanced-cache.php` drop-in | `templates/object-cache.php` gen by `Advanced_Cache_Handler::create()` — `class-advanced-cache-handler.php:128-299` | **Correct — by design zero-hook** | No WP hook — drop-in runs before `plugins_loaded` | Drop-in never fires WP hooks; `DROPIN_MARKER:WPPO_ADVANCED_CACHE_DROPIN:32` + `is_our_dropin():48` / `foreign_dropin_present():88` guards prevent overwriting LSCWP/other caches. `WP_CACHE` constant gate at `class-main.php:487+class-activate.php` handles missing constant. | — |
| 28 | `Core_Tweaks` — `init:-1000` embeds, `init:1` heartbeat, `wp_default_scripts:10` migrate, `wp_headers:10`, `rewrite_rules_array:10`, `heartbeat_settings:10` | `class-core-tweaks.php:37-99,116-430` | **Correct — priorities intentional** | Keep | `disable_embeds` at `init:-1000` runs before `wp_oembed_register_route` (which hooks `rest_api_init` via `init`). `heartbeat` at `init:1` before `heartbeat` enqueued. `remove_jquery_migrate` at `wp_default_scripts:10` where `WP_Scripts` registry populated. | — |
| 29 | `should_load_separate_core_block_assets` / `should_load_block_assets_on_demand` | `class-main.php:833,837` via `register_block_assets_filters` | **Correct — version-gated** | None | WP 6.9+ `should_load_separate_core_block_assets` (classic themes separate block assets), else `should_load_block_assets_on_demand` (<6.9). `__return_true/false:10` priority lets plugin opt-out/in without stomping theme `after_setup_theme` later callbacks (block themes skipped at `:832`). | — |
| 30 | Bfcache: `attach_session_information:10`, `set_logged_in_cookie:10(6)`, `clear_auth_cookie:10`, `nocache_headers:1000`, `wp_enqueue_scripts:10` | `class-bfcache.php:378-382,384` | **Correct** | None | `attach_session_information` + `set_logged_in_cookie` required pair for session-token + cookie mirror; `nocache_headers:1000` runs after core. | — |
| 31 | Perf_Translations: `load_translation_file`/`load_textdomain_mofile:10` | `class-perf-translations.php:229-230` | **Correct** | None | Pair covers WP 6.9 `load_translation_file` new API + legacy `load_textdomain_mofile`. `wppo_perf_translations_file_written:10` self-hooks `opcache_invalidate` correctly. | — |
| 32 | `wp_speculation_rules:20` — AI_Adaptive | `class-ai-adaptive.php:476` gated `function_exists(wp_get_speculation_rules_configuration)` | **Correct** | None | WP 6.8+ `wp_speculation_rules` (Speculation Rules API) gated behind existence check; priority 20 leaves core (10) intact and appends top-2 RUM URLs. | — |
| 33 | `secure_auth_cookie` / `secure_logged_in_cookie` (bfcache) | `class-bfcache.php:193-194` | **Correct — read-only** | — | `apply_filters('secure_*')` consumption inside `is_logged_in_cookie_secure()` mirrors core; not `add_filter`. | — |

### Lifecycle Diagram (condensed)

```
plugins_loaded → Main::__construct (load settings) → Main::includes() [CLI gate] → setup_hooks() (~90 add_action/add_filter)
init:0-10 → Cron::schedule_cron_jobs (wp_schedule_event + cron_schedules) · set_role_hash_cookie:10 · Llms::register_rewrite:10 · disable_emojis/embeds/heartbeat (Core_Tweaks)
admin_init:10 → maybe_fix_wp_cache / maybe_run_upgrades / maybe_run_version_upgrade / maybe_migrate_block_assets
rest_api_init:10 → register_rest_route ×25
wp (10) → apply_per_page_delay_config (delayJS)        # first hook where queried $post known
send_headers:0 → Llms::emit_link_header · LiteSpeed_Integration::handle_send_headers:0
wp_enqueue_scripts:5-MAX → RUM:5 → enqueue:10 → remove_woocommerce:999 → defer:1000 → module_strategies:10000 → minify_queued_styles:MAX-1 → combine_css:MAX + dequeue_selected:9999 (Asset_Manager)
wp_head:0 → speculation_rules:0 → preload/prefetch/preconnect:1 → maybe_preload_combine_css:1 (before core print at 8)
template_redirect:0-20 → capture_template_start:0 → Llms::serve:1 → buffer opens (cache / used-CSS / LCP at 20)
wp_template_enhancement_output_buffer:10/20/30 (6.9+) → process_buffer_for_cache:10 / used-CSS:20 / LCP:30 → wp_finalized...:0 → stash_cache / emit_server_timing
wp_footer:90/9999 → RUM::print_config:90 → capture_page_assets:9999
shutdown → Img_Converter::commit_img_info + RUM::flush_shutdown_buffer
```

---

## 1. Frontend Lifecycle — `template_redirect`, `wp`, `wp_enqueue_scripts`, `shutdown`, `send_headers`

### 1.1 Cache output buffer

- **Current:** Dual path — `class-main.php:545` `add_filter('wp_template_enhancement_output_buffer', process_buffer_for_cache, 10,2)` + `add_action('wp_finalized_template_enhancement_output_buffer', stash_cache)` (6.9+) else `class-main.php:550` `add_action('template_redirect', start_output_buffer)` (legacy).
- **Better?** No. `wp_template_enhancement_output_buffer` (WP 6.9, `wp-includes/template.php: wp_should_output_buffer_template_for_enhancement()`) is the *intended* output-buffer API; `template_redirect` is only kept as `TODO #553` (`is_wp69_plus` gate at `:510`). Earlier hooks (`wp`, `send_headers`) lack `is_404()/is_cache_allowed_for_current_user()` fidelity; later `shutdown` too late to wrap output. Dual is correct until min WP bumped.

### 1.2 `wp` for delay config

- **Current:** `class-main.php:516` `add_action('wp', apply_per_page_delay_config)` only when `delayJS`.
- **Better?** No — `wp` is earliest hook where `$wp_query->get_queried_object()` → `get_the_ID()` + `Asset_Manager::get_page_assets()` resolvable. `template_redirect` would also have the ID but is reserved for buffer decisions; `pre_get_posts` too early (post ID unknown). Keep.

### 1.3 `wp_enqueue_scripts` super-late chain

- **Current:** 6 callbacks from prio 5 to `PHP_INT_MAX` (see §0.13).
- **Better?** Consolidating `combine_css` onto `wp_print_styles` would guarantee queue finalized after all theme `wp_enqueue_scripts:10` callers, but risks `maybe_preload_combine_css:wp_head:1` firing before combine generates the URL (core prints styles at `wp_head:8`). Current ordering solves the preload race explicitly; refactoring would reintroduce it. Keep.

### 1.4 `send_headers`

- **Current:** `class-main.php:634` `send_headers → emit_link_header` (LLMs) + `class-litespeed-integration.php:605` `send_headers:0 handle_send_headers` (LS vary/control).
- **Better?** No alternative. `template_redirect` too late for cache-control headers on cached-by-drop-in responses (drop-in never boots WP). `wp_loaded` too early (scheme/host not normalized).

### 1.5 `shutdown`

- **Current:** `class-img-converter.php:1750` `add_action('shutdown', commit_img_info)` (always, via deferred pattern) + conditional `class-rum.php:352` `add_action('shutdown', flush_shutdown_buffer)` inside `RUM::collect` when `function_exists('add_action')`.
- **Better?** `shutdown` correct per Handbook (`shutdown` fires after response sent, before PHP teardown — ideal for deferred `update_option` batching). `wp_footer` would miss REST/cron/CLI paths; `template_redirect` would run per page cache *generation* only. Keep.

---

## 2. Admin — `admin_init`, `admin_menu`, `current_screen`

| Current | File:Line | Fires when | ✓/✗ | Alternative |
|---------|-----------|------------|-----|-------------|
| `admin_menu → init_menu` | `class-main.php:486` | `admin_menu` (after `admin_init`, before `current_screen`) | ✓ | None — canonical for settings page registration |
| `admin_init → maybe_fix_wp_cache` | `class-main.php:487` | `admin_init` (admin only) | ✓ | `current_screen` would save one transient check on non-plugin screens but adds coupling to `WP_Screen`; not worth it |
| `admin_init → maybe_run_upgrades` | `class-main.php:488` | `admin_init` | ✓ | Same — requires `current_user_can('manage_options')` gate at `:949`, only meaningful in admin |
| `admin_init → maybe_run_version_upgrade` | `class-main.php:491` | `admin_init` | ✓ | Companion `upgrader_process_complete:10,0` at `:492` covers non-admin updates; correct pair |
| `admin_init → maybe_migrate_block_assets_setting` | `class-main.php:493` | `admin_init` (admin only) | ✓ | Must not run on front-end (would write option on cacheable request) — comment at ` :843` + method `:849` explains |
| `admin_enqueue_scripts → admin_enqueue_scripts` | `class-main.php:494` | `admin_enqueue_scripts` (with `$hook` suffix) | ✓ | Not `admin_init`/`admin_head` — only enqueue on plugin screen |
| `add_meta_boxes → add_metabox` / `save_post → save_metabox` | `class-metabox.php:39,41` (via `Main:762`) | Metabox API | ✓ | `save_post` correct; `wp_after_insert_post` would also work for term-bearing posts but not needed for meta-only saves |

**Extensibility gap (not lifecycle):** No `wppo_admin_screen_id` filter, but `admin_menu` registration is straightforward to unhook (`remove_menu_page`) — no change.

---

## 3. REST — `rest_api_init`

- **Current:** `class-main.php:615` `add_action('rest_api_init', Rest::register_routes)` → `class-rest.php:58-260` `register_rest_route(self::NAMESPACE, $route, {methods,callback,permission_callback,schema})` for 25 routes, plus `class-main.php:793` `add_action('wp_ajax_wppo_get_nonce', ajax_get_nonce)`.
- **Better?** `rest_api_init` is *only* correct hook — `init` would run before `WP_REST_Server` instantiated, `wp_loaded` late. Permission `manage_options + wp_verify_nonce('wp_rest')` at `class-rest.php:357-362` is correct; `rum_collect:227` intentionally `__return_true` with `token + Util::transient_key('wppo_rum_ratelimit_')` IP rate limit — documented at ` :218-222`. No lifecycle change; optional improvement: add `args` schema (`validate_callback`/`sanitize_callback`) per route for `limit`/`page` params (e.g. `autoloaded_options:269 limit absint 1-100`, `recent_activities:544 page`) — not a hook move.

---

## 4. Cron — `init`, `wp_loaded`, `shutdown`

- **Current:** `class-cron.php:57 add_action('init', schedule_cron_jobs)` + `61 add_filter('cron_schedules', add_custom_cron_interval)` — registers `every_5_hours:99-105` and schedules `wppo_page_cron_hook/batch/generate_static_page/url`, `wppo_img_conversion hourly`, `wppo_database_cleanup_cron daily`, `wppo_web_vitals_rescan daily`, `wppo_llms_txt_daily daily`, `wppo_used_css_cron every_5_hours`, `wppo_ccss_regeneration daily`, `wppo_rum_flush` (un-scheduled until `RUM::collect`).
- **Better?** Keep `init:10`. Moving to `wp_loaded` would delay scheduling by ~1ms but avoid running on every AJAX/REST? However cron scheduling is cheap (`wp_next_scheduled` hit or miss + one `wp_schedule_event` once) and must run on *all* request types (including WP-Cron HTTP worker) — `init` guarantees coverage; `admin_init` would miss front-end cron pings, `plugins_loaded` too early for `Util::get_settings()` (not yet cached). Handbook shows `init` for cron registration. No change.
- **Shutdown not used for scheduling — correctly** (scheduling is idempotent via `wp_next_scheduled`; deferring to `shutdown` would race with concurrent requests).

---

## 5. Query — `pre_get_posts`

- **Current:** Not used.
- **Better?** Keep unused. Plugin never modifies `WP_Query`; `schedule_page_cron_jobs:294-309` uses `get_posts(['post_type'=>public types, posts_per_page=>200, paged, no_found_rows=>true])` with correct `post_status=>publish` + `update_post_meta_cache=>false` etc. — no query filter needed.

---

## 6. Script/Style — `wp_enqueue_scripts`, `script_loader_tag`, `style_loader_tag`

| Hook | File:Line | Purpose | ✓/✗ | Better |
|------|-----------|---------|-----|--------|
| `wp_enqueue_scripts:999 dequeue_selected_assets` | `class-asset-manager.php:82` | Per-page dequeue at late priority | ✓ | `wp_print_scripts:9` would still work but `wp_enqueue_scripts:9999` preserves ability to `wp_deregister_script` (safe only before print). Keep |
| `wp_enqueue_scripts:1000 add_defer_strategy` | `class-main.php:523` | `wp_script_add_data(handle,'strategy','defer')` (6.3+) | ✓ | Not `script_loader_tag` — native `strategy` is preferred per WP 6.3 handbook. Fallback `script_loader_tag:10 add_defer_attribute_legacy :525` for `<6.3` is the documented shim (`TODO #553`). Keep dual |
| `script_loader_tag:10 add_defer_attribute` | `class-main.php:515` | Delay (`wppo-src`/`wppo/javascript` swap) | ✓ | `wp_enqueue_scripts` can't rewrite rendered `<script>`; must be tag filter. Keep |
| `script_loader_tag:11 add_fetchpriority_to_deferred` | `class-main.php:530` | `fetchpriority=low` on deferred | ✓ | `script_loader_tag:11` > `10` ensures runs after defer rewrite. 6.9+ core handles `fetchpriority` natively, so `!is_wp69_plus` gate correct (Trac #61734). Keep |
| `wp_enqueue_scripts:MAX-1/MAX combine/minify_queued_styles` | `class-main.php:676,608` | Combined CSS + path data | ✓ | See §1.3 — `wp_print_styles` alternative discussed; keep enqueue chain |
| `style_loader_tag:9 Google_Fonts` | `class-main.php:688` | Intercept Google Fonts `href` before minify | ✓ | Must be before `style_loader_tag:10 minify_css:679` — priority 9 correct |
| `style_loader_tag:10 minify_css` / `script_loader_tag:10 minify_js` | `class-main.php:662,679` | Minify via local file minifier | ✓ | `script_loader_tag`/`style_loader_tag` are defined for exactly this transform |
| `wp_head:0 speculation` + `wp_resource_hints:10` | `class-main.php:759,760` | Speculation Rules API + resource hints | ✓ | WP 6.8+ `wp_speculation_rules:20` handled separately by `AI_Adaptive::filter_speculation_rules:476` — not `wp_head`. Keep both |
| `wp_head:1 preload/prefetch/preconnect` | `class-main.php:758` | `<link rel="preload">` etc. | ✓ | Delegating `preconnect`/`dns-prefetch` to `wp_resource_hints` already (see `Util::generate_preload_link` comment `Util:459-465`). `preload` must stay on `wp_head:1` (needs `as`/`type` control). Keep |

---

## 7. Cache — Advanced-cache drop-in

- **Current:** `templates/object-cache.php` mapped via `Advanced_Cache_Handler::create():128-299` to `WP_CONTENT_DIR . '/advanced-cache.php'` with `DROPIN_MARKER` guard (`:32`), `is_our_dropin():48`, `foreign_dropin_present():88`. Drop-in runs *before* `plugins_loaded` (MU-style, constant `WP_CACHE` gate at `class-main.php:487`/`class-activate.php`). No `add_action`/`add_filter`.
- **Better?** No WP hook can replace this — `advanced-cache.php` is the *only* mechanism to serve static HTML without booting WP (per `WP_CACHE` contract). Not `muplugins_loaded` (still boots plugins). Keep guards.

---

## 8. HTTP — `pre_http_request`

- **Current:** Not hooked. Telemetry uses `wp_remote_get($url, ['timeout'=>30])` gated by `apply_filters('wppo_telemetry_verify_ssl', bool, url)` at `class-telemetry.php:227,453,717,872` — allows `sslverify` override without `pre_http_request`.
- **Better?** For telemetry *tests* `pre_http_request` could short-circuit `wp_remote_get` to fixtures, but that would intercept *all* HTTP (risky). Per-URL `http_request_args` or `pre_http_request` with host check would be equally precise; current `wppo_telemetry_verify_ssl` + existing `http_request_args` consumer in `class-telemetry.php` sufficient. No change; note as optional test double point.

---

## 9. Image/Media — `wp_generate_attachment_metadata`, `image_editor`, `wp_get_attachment_image_src`

| Hook | File:Line | Current | Better | Why |
|------|-----------|---------|--------|-----|
| `wp_generate_attachment_metadata:10,2` → `convert_image_to_next_gen_format` | `class-image-optimisation.php:185` | ✓ | Keep; could add `wp_after_insert_attachment:10` as secondary if placeholder needs original-file path without sizes | `wp_generate_attachment_metadata` canonical for sub-size aware conversion (`$metadata['sizes']` loop at `class-img-converter.php:1147-1159`). `wp_after_insert_attachment` fires for non-image attachments too — not needed. |
| `wp_get_attachment_image_src:1` → `maybe_serve_next_gen_image` | `class-image-optimisation.php:187` | ✓ | Keep; alternative `wp_get_attachment_image_srcset:filtered via maybe_serve_next_gen_images via buffer` already handled at `class-cache.php:1246 process_buffer_only` | Filter covers `the_post_thumbnail()`/`wp_get_attachment_image()` hot path. `wp_get_attachment_image_src` filter is lowest-overhead per call vs buffer rewrite. No change. |
| `delete_attachment → clean_placeholder_on_delete` | `class-image-optimisation.php:191` | ✓ | Keep; not `delete_post` | Precise for `attachment` post type |
| `client_side_supported_mime_types:10` | `class-image-optimisation.php:199` | ✓ | Keep gated `function_exists('wp_is_client_side_media_processing_enabled')` + `clientSideMimeTypeOverride` | WP 7.1 client-side mime API; correct |
| `wp_client_side_media_processing_enabled → __return_false` | `class-image-optimisation.php:210` | ✓ | Keep gated `forceServerSideConversion` | Forces server pipeline when toggle enabled |
| `image_editor_output_format` (not hooked as add_filter) | `class-img-converter.php:229-251 resolve_output_format()` reads via `wp_get_image_editor_output_format()` | ✓ (consumes, doesn't produce) | No `add_filter`; plugin *reads* core mapping `HEIC→JPEG` etc. and returns `none` to skip. Adding a filter would shadow core mapping — not desired. | Handled correctly via read, not write |
| `shutdown → commit_img_info` | `class-img-converter.php:1750` | ✓ | Not `wp_generate_attachment_metadata` direct `update_option` per image — would cause N writes per upload | Bulk pattern reduces `wppo_img_info` contention |

---

## 10. Consolidated Recommendations (research — do not enact)

| Priority | Recommendation | Hook | File:Line | Rationale | Risk if changed |
|----------|----------------|------|-----------|-----------|-----------------|
| P2 (docs) | Document `Server-Timing` buffer trade-off | `template_redirect:0` / `wp_finalized_template_enhancement_output_buffer:0` | `class-main.php:556-561` | Header forces 6.9 buffer even when streaming preferred; note in `docs/hooks.md` that disabling `server_timing_enabled` restores streaming | Low |
| P2 (docs) | Document `wppo_telemetry_verify_ssl` / `wppo_filesize_limit_bytes` / `wppo_cron_discovery_limit` / `wppo_server_timing_enabled` as public | `apply_filters` at `class-telemetry.php:227` etc., `class-img-converter.php:402`, `class-cron.php:666`, `class-main.php:1252` | `HOOK-AUDIT.md §15` already flags — add to `docs/hooks.md` or mark `@internal` | Low |
| P3 (perf micro) | Consider `current_screen` for admin heavy checks | `admin_init` → `current_screen` | `class-main.php:487-493` | One fewer transient per non-plugin admin page | Low; adds `WP_Screen` coupling |
| P3 (robustness) | If combine conflicts observed, move `combine_css` to `wp_print_styles:PHP_INT_MAX` | `wp_enqueue_scripts:PHP_INT_MAX` → `wp_print_styles` | `class-main.php:608` | Guarantees queue closed; but must preserve `wp_head:1` preload ordering | Medium — re-test preload race |
| P3 (completeness) | Add `args` to REST routes (`limit`, `page`, `post_id` etc.) | `register_rest_route` `args` key | `class-rest.php:58-260` | Enables `validate_callback` pre-dispatch (vs manual `absint` in handler) | Low |
| — | No change: keep `init` for cron, `wp` for delay, `shutdown` for commit, `template_redirect`/`wp_template_enhancement_output_buffer` dual for cache, `rest_api_init` for routes, `advanced-cache.php` zero-hook, `pre_get_posts` unused | — | — | — | — |

---

## 11. Sources

- WP core lifecycle order: `wp-includes/class-wp.php: main() → parse_request → send_headers → query_posts → handle_404 → template_redirect`; `wp-includes/default-filters.php` (`wp_enqueue_scripts`, `wp_head` priority table); `wp-includes/template.php: wp_should_output_buffer_template_for_enhancement()` (WP 6.9); `wp-includes/rest-api.php: rest_api_init`; `wp-includes/cron.php: wp_schedule_event / cron_schedules`; `developer.wordpress.org/reference/hooks/`.
- Handbook: `Commands Cookbook` (`init` for cron), `WP-Cron` (`init` vs `wp_loaded`), `Advanced-cache.php` (`WP_CACHE`), `WP_Scripts/WP_Styles` (`wp_default_scripts`, `script_loader_tag`, `style_loader_tag`).

---

## 12. Verification

- [x] No production file edited (`git diff --stat` empty for `includes/*.php`).
- [x] Each row cites `file:line`.
- [x] Compared plugin hook vs core docs for lifecycle, admin, REST, cron, query, script/style, cache drop-in, HTTP, image/media.
- [x] Kept version-gated `is_wp63_plus`/`is_wp69_plus` and `TODO #553`/`#624` notes.
- [x] Cross-checked with `HOOK-AUDIT.md` (272 hits) — no contradicting counts.
- [x] Appended roll-up to `ECOSYSTEM-RESEARCH.md §3`.

*Research-only. Proposes doc/`args`/`priority` improvements, not hook moves — current lifecycle choices are already precise.*
