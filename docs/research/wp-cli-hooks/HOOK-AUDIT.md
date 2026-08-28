# Hook Architecture Audit — performance-optimisation

**Date:** 2026-08-28 · **Scope:** `performance-optimisation.php`, `includes/*.php`, `templates/*.php`, `uninstall.php` · **Method:** `grep -rn "add_action|add_filter|do_action|apply_filters|register_activation_hook|register_deactivation_hook"` excluding `vendor/` and `tests/` — 272 production hits (unique). Every claim cites `file:line`. Read-only research, no production edits.

> Task categories: Optimization decisions, cache gen/inval, asset CSS/JS, image lazy, HTML minify, DB cleanup, RUM, object cache, settings, REST, CLI, cron, admin, frontend, compatibility (LiteSpeed, core tweaks). For each hook: Hook name, Type (add_/do_/apply_), File:Line, Purpose, Fires when (priority, condition, lifecycle), Arguments (count + types), Priority, Current consumers, Should remain private/public, Documentation (docs/hooks.md?).

Related: `docs/hooks.md` (42 public wppo_* hooks), `docs/research/wp-cli-hooks/WP-CLI-CURRENT.md` (CLI), `includes/class-main.php:485-799` (`setup_hooks()`).

---

## 0. Totals & Conventions

| Bucket | Count (prod) | Example |
|--------|-------------|---------|
| `add_action` (plugin registers) | ~128 | `class-main.php:486 add_action('admin_menu', ...)` |
| `add_filter` (plugin registers) | ~38 | `class-main.php:515 add_filter('script_loader_tag', ...)` |
| `apply_filters` (plugin fires, third-party can override) | ~78 | `class-cache.php:1661 apply_filters('wppo_cache_page_html', ...)` |
| `do_action` (plugin fires) | ~22 | `class-cache.php:2032 do_action('wppo_before_cache_clear', ...)` |
| `register_activation_hook` / `register_deactivation_hook` | 2 | `performance-optimisation.php:57,70` |
| Templates | 1 | `templates/object-cache.php:532 apply_filters('object_cache_allow_flush_all', ...)` |
| Uninstall | 0 | `uninstall.php` contains no hook registrations — direct `WP_UNINSTALL_PLUGIN` guard |
| **Total prod hits** | **272** | `grep` unique lines |

**Priority convention:** default 10 unless noted. Conditional hooks are flagged `always-loaded` vs `conditional (gated by setting)`. Early = `init`/`admin_init`/`plugins_loaded`; Late = `wp_enqueue_scripts`/`wp_head`/`template_redirect`/`shutdown`.

**Visibility:** `public` = intended for site operators/themes (`docs/hooks.md`, `wppo_*` prefix). `private` = internal plumbing (`wp_*`, `litespeed_*`, `wppo_*` debug/rate-limit not documented). `external` = WordPress/LiteSpeed core hook consumed, not owned.

---

## 1. Plugin Lifecycle (activation / deactivation / uninstall)

| Hook | Type | File:Line | Purpose | Fires when | Args | Priority | Consumers | Public? | Docs |
|------|------|-----------|---------|------------|------|----------|-----------|---------|------|
| `register_activation_hook(__FILE__, 'wppo_activate')` | `register_activation_hook` | `performance-optimisation.php:57` | Call `Activate::init()` (create `wppo_activity_logs` table, add `WP_CACHE`, salts) | Plugin activate UI / `wp plugin activate` | 0 | `Activate::init()` | n/a | `private` (core) | — |
| `register_deactivation_hook(__FILE__, 'wppo_deactivate')` | `register_deactivation_hook` | `performance-optimisation.php:70` | Call `Deactivate::init()` (clear cron, not delete data) | Plugin deactivate | 0 | `Deactivate::init()` | n/a | `private` | — |
| _(no hooks)_ | — | `uninstall.php:1-217` | Guard `WP_UNINSTALL_PLUGIN`, drop table, delete `wppo_*` options/metas/transients/drop-ins, multisite loop | Uninstall delete | — | — | — | `private` | — |
| `wppo_run_upgrades` (custom action) | `add_action` | `class-main.php:489` → `Activate::maybe_run_upgrades` | One-time version migrations (non-activation updates) | `upgrader_process_complete` via `maybe_schedule_upgrade_routine:490` + `wppo_run_upgrades` dispatch | 0 | 10 | `Activate::maybe_run_upgrades` (sole consumer) | `private` (internal) | — |
| `wppo_version` (option gate, not hook) | — | `class-main.php:997-1023 maybe_run_version_upgrade` | Regenerate `advanced-cache.php` + clear cache once per version bump | `admin_init:491` + `upgrader_process_complete:492` | — | 10 | self | `private` | — |

**Notes:**
- `WP_CLI` gate at `includes/class-main.php:472-474` is **not** a hook — it's `if (defined('WP_CLI') && WP_CLI) \WP_CLI::add_command('wppo', ...)` executed in `Main::includes()` before `setup_hooks()`. Always-loaded when WP_CLI true, else absent. No hook, but CLI registration site.
- `uninstall.php` intentionally has zero hooks; it uses direct `delete_option`/`$wpdb->query` with multisite `switch_to_blog()` loop.

---

## 2. Cache Generation / Invalidation (Static HTML)

### 2.1 Plugin-owned cache hooks (fired by Cache, consumed by Main + CDN/Edge)

| Hook | Type | File:Line | Purpose | Fires when | Args (count + types) | Priority | Consumers (grep same hook) | Public? | Docs |
|------|------|-----------|---------|------------|----------------------|----------|----------------------------|---------|------|
| `wppo_before_cache_clear` | `do_action` | `class-cache.php:2032` | Pre-clear signal | `Cache::clear_cache($type,$path)` entry, before FS delete | 2: `string $type ('all'|'single_page')`, `string\|null $url_path` | n/a | None (no `add_action` for it in prod; placeholder for theme) | `public` | ✅ `docs/hooks.md:9` |
| `wppo_after_cache_clear` | `do_action` | `class-cache.php:2074` | Post-clear signal, also triggers CDN/Edge purges | After FS delete succeeded | 2: `string $type`, `string\|null $url_path` | n/a | `class-main.php:623 add_action('wppo_after_cache_clear', CDN_Purger::purge_all)` (default 10) + `class-main.php:626 add_action('wppo_after_cache_clear', Edge_Purger::purge_all, 20, 2)` | `public` | ✅ `docs/hooks.md:25` |
| `wppo_cache_page_html` | `apply_filters` | `class-cache.php:1661` | Filter pre-rendered HTML before writing `index.html` | Inside `process_buffer_only` → `maybe_apply_*` pipeline, after minify/used-CSS/CDN, before `save_processed_buffer` | 2: `string $html`, `string $url` | n/a | None in prod (operator hook) | `public` | ✅ `docs/hooks.md:117` |
| `wppo_inline_combined_css` | `apply_filters` | `class-cache.php:980` (+ `class-cache.php:980` duplicate) | Disable inlining of combined CSS via core `wp_maybe_inline_styles()` | `register_combine_css_path()` after combined file built | 1: `bool $inline` (default `true`) | n/a | `Main::register_block_assets_filters` respects; no prod consumer besides self | `public` | — (mentioned in AGENTS.md:79 `wppo_inline_combined_css`) |
| `wppo_skip_combine_on_small_block_theme` | `apply_filters` | `class-cache.php:1143` | Skip combined-CSS file on small block-theme bundles where core inline budget wins | `should_skip_combine_for_inline_budget()` | 3: `bool $skip`, `string[] $eligible_handles`, `int $limit` | n/a | None | `public` (debug, @since NEXT) | — |
| `styles_inline_size_limit` | `apply_filters` (WP core) | `class-cache.php:1047` | Core inline budget (20K pre-6.9, 40K 6.9+) | `get_styles_inline_limit()` | 1: `int $default` | n/a | Self consumes core filter; no wppo producer | `external` (core) | — |
| `wppo_debug_log` | `do_action` | `class-cache.php:282` (domain invalid), `class-cdn-purger.php:235`, `class-cloudflare-purger.php:97`, `class-edge-purger.php:212`, `minify/class-html.php:238` | Lightweight debug channel (if someone hooks it) | Cache domain fail, HTML minify exception, CDN/Edge purge fail detail | 1-2: `string $msg`, `array $ctx?` | n/a | No prod `add_action('wppo_debug_log', ...)` — unconnected | `private` | — |

### 2.2 Cache invalidation consumers (added in `Main::setup_hooks()`)

| Hook | Type | File:Line | Purpose | Fires when | Args | Priority | Consumers | Public? | Docs |
|------|------|-----------|---------|------------|------|----------|-----------|---------|------|
| `save_post` → `on_save_post_invalidate_cache` | `add_action` | `class-main.php:552` | Smart purge: home + archive + taxonomies for edited post | `save_post` after post save, only when `Cache` enabled | 3: `int $post_id`, `WP_Post $post`, `bool $update` | 10 | `Cache::invalidate_dynamic_static_html()` | `private` | — |
| `save_post` → `on_save_post_queue_used_css` | `add_action` | `class-main.php:784` | Queue `wppo_used_css_generate` AS job when `removeUnusedCSS` on | `save_post` (always, but early-return if `removeUnusedCSS` off) | 3 | 10 | `Used_CSS::process_background` | `private` | — |
| `save_post` → `Database_Cleanup::on_post_change` | `add_action` | `class-main.php:596` (admin only) | Invalidate DB counts salt on public post change | `save_post` + `deleted_post:597` (both admin-only guard `is_admin():595`) | 2: `int $post_id`, `WP_Post` | 10 | `Database_Cleanup::on_post_change` | `private` | — |
| `deleted_post` → `Database_Cleanup::on_post_change` | `add_action` | `class-main.php:597` | Same salt bump on delete | `deleted_post` | 2 | 10 | same | `private` | — |
| `update_option_permalink_structure` | `add_action` | `class-main.php:787` | Clear all cache on permalink change | Option update | 0 (static `clear_all_cache`) | 10 (default) | `Cache::clear_cache()` | `private` | — |
| `switch_theme` | `add_action` | `class-main.php:788` | Clear all cache on theme switch | Theme switch | 0 | 10 | same | `private` | — |
| `update_option_wppo_settings` → `on_settings_update` | `add_action` | `class-main.php:789` | Clear cache when `cache_settings/file_optimisation/image_optimisation/preload_settings/core_tweaks` tab diff; flush runtime when admin-only tab diff; rebuild drop-in; htaccess; Google Fonts; salts | `update_option('wppo_settings')` (REST + CLI + UI all fire this) | 2: `mixed $old`, `mixed $new` | 10 | `Main::on_settings_update:1032` | `private` (but `wppo_settings` is the settings store) | — |
| `activated_plugin` | `add_action` | `class-main.php:790` | Clear all cache | Any plugin activate | 0 | 10 | same | `private` | — |
| `deactivated_plugin` | `add_action` | `class-main.php:791` | Clear all cache | Any plugin deactivate | 0 | 10 | same | `private` | — |
| `wp_template_enhancement_output_buffer` → `process_buffer_for_cache` | `add_filter` | `class-main.php:545` | 6.9+ path: process HTML without saving | `wp_template_enhancement_output_buffer` filter | 2: `string $filtered`, `string $output` | 10 | `Cache::process_buffer_for_cache` | `private` | — |
| `wp_finalized_template_enhancement_output_buffer` → `stash_cache` | `add_action` | `class-main.php:546` | 6.9+ path: stash processed HTML to `cache/wppo/{domain}/{path}/index.html` | After finalized buffer | 0 (receives `$output` implicitly) | 10 | `Cache::stash_cache` | `private` | — |
| `template_redirect` → `start_output_buffer` | `add_action` | `class-main.php:550` | <6.9 fallback: `ob_start` with `process_buffer_only` callback | `template_redirect` | 0 | default | `Cache::start_output_buffer` | `private` · `TODO #553` | — |
| `wp_template_enhancement_output_buffer` → `process_used_css_only` | `add_filter` | `class-main.php:568` | Standalone used-CSS buffer when cache disabled but `removeUnusedCSS` on | 6.9+ filter | 2 | 20 | `Main::process_used_css_only` | `private` | — |
| `template_redirect` → `start_used_css_buffer` | `add_action` | `class-main.php:572` | <6.9 fallback for used-CSS | `template_redirect` | 0 | default | `Main::start_used_css_buffer` | `private` · `TODO #553` | — |
| `wppo_generate_ccss` → `Critical_CSS::background_generate` | `add_action` | `class-main.php:771` | AS job for critical CSS | `wppo_generate_ccss` single event | 1: `$args` | 10 | `Critical_CSS::background_generate` | `private` | — |

**Firing: always-loaded vs conditional**

- `save_post` invalidate always added when `enableCache` true (`class-main.php:539-553`), else absent. Early `save_post` (priority 10) runs on every post save.
- `wp_template_enhancement_output_buffer`/`stash_cache` always added when `enableCache` true; `template_redirect` fallback only when `!wp_should_output_buffer_template_for_enhancement || !is_wp69_plus` — version-gated.
- `wppo_after_cache_clear` consumers (`CDN_Purger`, `Edge_Purger`) always added (`class-main.php:622-627`) — unconditional, runs even on CLI.

**Missing extension points**

- No filter to veto cache write per URL before `save_processed_buffer` (only post-hoc `wppo_cache_page_html` to mutate HTML). A `wppo_should_cache_request` filter before `is_cache_allowed_for_current_user` + `is_not_cacheable` would be cleaner than relying on `DONOTCACHEPAGE` constant.
- No action between `process_buffer_only` stages (image → Google Fonts → minify → used-CSS → CDN) — a `wppo_buffer_processed` stepwise hook would allow insertion of custom transforms.
- No `wppo_cache_written` / `wppo_cache_miss` action to observe hit-rate without patching `Cache`.

---

## 3. Asset Optimization (CSS/JS Combining, Minify, Defer/Delay, CDN, Modules)

| Hook | Type | File:Line | Purpose | Fires when | Args / Priority | Consumers | Public? | Docs |
|------|------|-----------|---------|------------|-----------------|-----------|---------|------|
| `wp_enqueue_scripts` → `combine_css` | `add_action` | `class-main.php:608` | Generate `wppo-combine.css` (de-dupe, minify, LRU, variant `separate` on 6.9+) | `wp_enqueue_scripts` | 0 extra args, priority `PHP_INT_MAX` (last) → dequeues individuals, enqueues combined | `Cache::combine_css` | `private` | — |
| `wp_head` → `maybe_preload_combine_css` | `add_action` | `class-main.php:611` | Emit `<link rel="preload" as="style">` for combined file | `wp_head` priority 1 (before core prints `<link>` at 8) | 0 | `Cache::maybe_preload_combine_css` | `private` | — |
| `wp_enqueue_scripts` → `minify_queued_styles` | `add_action` | `class-main.php:676` | Register `path` data for core inline pass + rewrite enqueue | `wp_enqueue_scripts` priority `PHP_INT_MAX-1` (just before combine) | 0 | `Main::minify_queued_styles` | `private` (conditional `minifyCSS && function_exists('wp_maybe_inline_styles')`) | — |
| `style_loader_tag` → `minify_css` | `add_filter` | `class-main.php:679` | Minify individual CSS `href` via local file minifier | `style_loader_tag` filter per style | 3: `string $tag`, `string $handle`, `string $src` | 10 | `Main::minify_css` | `private` | — |
| `wppo_exclude_minification` | `apply_filters` | `class-main.php:2747` (css) + `class-main.php:2849` (js) | Skip minification for a handle/file | Inside `minify_css` / `minify_js` before minify | 4: `bool $exclude`, `string $file_path`, `string $handle`, `string $type ('css'|'js')` | n/a | None in prod | `public` | ✅ `docs/hooks.md:96` |
| `script_loader_tag` → `minify_js` | `add_filter` | `class-main.php:662` | Minify JS | `script_loader_tag` | 3: `$tag`, `$handle`, `$src` | 10 | `Main::minify_js` | `private` (gated `minifyJS`) | — |
| `script_loader_src` / `style_loader_src` → `strip_static_query_strings` | `add_filter` | `class-main.php:683-684` | `?ver=` removal | Asset src filters | 2: `string $src`, `string $handle` | 10 | `Main::strip_static_query_strings` (gated `removeQueryStrings`) | `private` | — |
| `style_loader_tag` → `Google_Fonts::process_style_tag` | `add_filter` | `class-main.php:688` | Host Google Fonts locally (buffer-level font intercept) | `style_loader_tag` | 3 | 9 | `Google_Fonts::process_style_tag` (gated `hostGoogleFontsLocally`) | `private` | — |
| `wppo_exclude_defer_js` | `apply_filters` | `class-main.php:701` | Filter defer exclusions before defer strategy | During `setup_hooks()` after building `exclude_defer_js` | 1: `array $exclusions` | n/a | Self (populates `exclude_defer_js`); LiteSpeed coexistence merges defer into delay exclusions `class-main.php:718-720` | `public` | ✅ `docs/hooks.md:80` |
| `wppo_exclude_delay_js` | `apply_filters` | `class-main.php:722` | Filter delay exclusions | Same, after merging defer exclusions when both active | 1: `array $exclusions` | n/a | Self | `public` | ✅ `docs/hooks.md:63` |
| `script_loader_tag` → `add_defer_attribute` | `add_filter` | `class-main.php:515` | `wppo-src` rewriting for delay (interaction/idle/viewport) | `script_loader_tag` | 2: `string $tag`, `string $handle` | 10 | `Main::add_defer_attribute` (gated `delayJS`) | `private` · always-registered when `delayJS` on | — |
| `wp` → `apply_per_page_delay_config` | `add_action` | `class-main.php:516` | Merge per-page `Asset_Manager` delay config into global delay lists | `wp` action | 0 | 10 | `Main::apply_per_page_delay_config` | `private` | — |
| `wp_enqueue_scripts` → `add_defer_strategy` | `add_action` | `class-main.php:523` | Native `defer` via `wp_script_add_data( $handle, 'strategy', 'defer')` | `wp_enqueue_scripts` priority 1000 | 0 | `Main::add_defer_strategy` (gated `deferJS && is_wp63_plus`) | `private` | — |
| `script_loader_tag` → `add_defer_attribute_legacy` | `add_filter` | `class-main.php:525` | Fallback string-replace `defer` for <6.3 | `script_loader_tag` | 2 | 10 | `Main::add_defer_attribute_legacy` (gated `deferJS && !is_wp63_plus`) | `private` · `TODO #553` | — |
| `script_loader_tag` → `add_fetchpriority_to_deferred` | `add_filter` | `class-main.php:530` | Add `fetchpriority=low` to deferred scripts (+ regex fallback <6.9) | `script_loader_tag` | 2 | 11 | `Main::add_fetchpriority_to_deferred` (gated `deferJS && !is_wp69_plus`) | `private` | — |
| `wp_enqueue_scripts` → `apply_module_loading_strategies` | `add_action` | `class-main.php:498` | Module `async`/`defer` via `wp_script_modules` API | `wp_enqueue_scripts` priority 10000 | 0 | `Main::apply_module_loading_strategies` | `private` | — |
| `wp_enqueue_scripts` → `remove_woocommerce_scripts` | `add_action` | `class-main.php:536` | Dequeue Woo CSS/JS when `removeWooCSSJS` | `wp_enqueue_scripts` priority 999 | 0 | `Main::remove_woocommerce_scripts` (gated `removeWooCSSJS`) | `private` | — |
| `wp_enqueue_scripts` → `enqueue_scripts` (Main) | `add_action` | `class-main.php:497` | Enqueue `lazyload.js`, `rum.js`, admin bar helpers | `wp_enqueue_scripts` | 0 | 10 | `Main::enqueue_scripts` | `private` | — |
| `wp_head` → `inline_ccss` / `style_loader_tag` → `defer_stylesheets` | `add_action`/`add_filter` | `class-main.php:769-770` | Critical CSS inline + defer non-critical | `wp_head` prio 0 / `style_loader_tag` prio 10 | — | `Critical_CSS` (gated `criticalCSS`) | `private` | — |
| `wppo_ccss_allowed_stylesheet_host` | `apply_filters` | `class-critical-css.php:569` | Allow extra host for CCSS fetch | `Critical_CSS::is_allowed_host()` | 2: `bool $allow`, `string $host` | n/a | None | `public` (undoc) | — |
| `wppo_ccss_sanitize_inline` | `apply_filters` | `class-critical-css.php:605` | Sanitize inlined CCSS | `Critical_CSS::sanitize()` | 1: `string $css` | n/a | None | `public` (undoc) | — |
| `wp_head` → `add_preload_prefetch_preconnect` | `add_action` | `class-main.php:758` | Preload fonts / preconnect / DNS-prefetch links | `wp_head` | 0 | prio 1 | `Main::add_preload_prefetch_preconnect` — always-loaded | `private` | — |
| `wp_head` → `add_speculation_rules` | `add_action` | `class-main.php:759` | Speculation Rules API JSON | `wp_head` | 0 | prio 0 | `Main::add_speculation_rules` — always-loaded | `private` | — |
| `wp_resource_hints` → `add_resource_hints` | `add_filter` | `class-main.php:760` | `dns-prefetch`/`preconnect` resource hints | `wp_resource_hints` | 2: `array $urls`, `string $relation_type` | 10 | `Main::add_resource_hints` — always-loaded | `private` | — |
| `wppo_litespeed_can_cdn` | `apply_filters` | `class-cache.php:1349` | Gate CDN rewrite when LiteSpeed owns CDN | `maybe_apply_cdn()` early return | 1: `bool $can` (default `true`) | n/a | `LiteSpeed_Integration::can_apply_cdn()` consumes | `public` | ✅ `docs/hooks.md:240` (`wppo_litespeed_can_cdn`) |
| `litespeed_can_cdn` (LS external) | `has_filter` + `apply_filters` | `class-cache.php:1353` + `class-litespeed-integration.php:1254` | Respect LSCWP CDN flag | Same as above | 1: `bool` | n/a | LSCWP sets; WPPO reads | `external` | — |
| `litespeed_can_optm` (LS external) | `has_filter` + `apply_filters` | `class-cache.php:402` (combine gate), `class-main.php:1883,1937,1996,2778,2835` | Bypass WPPO optimiser when LiteSpeed opts out | Various minify/combine defer paths | 1: `bool $can` | n/a | LS sets; WPPO reads | `external` | — |

**Firing**

- `script_loader_tag`/`style_loader_tag` fire per asset during `wp_head`/`wp_footer` script printing — late, only on front-end, not admin.
- `wp_enqueue_scripts` combiners/minifiers are late (after theme enqueues) — `combine_css` at `PHP_INT_MAX` guarantees last.
- `wp_resource_hints` fires in `wp_head` via `wp_resource_hints()` — early head.

**Missing extension points**

- No `wppo_combine_css_exclude` per-handle filter (only `excludeCombineCSS` setting + block-asset hard-exclude). A filter receiving `handle, src, is_block_asset` would be more ergonomic than mutating `exclude_defer_js`/`exclude_delay_js` arrays.
- No filter to replace CDN URL per asset (only global `cdnURL` setting). Per-asset `wppo_cdn_url` filter would cover dual-CDN cases.
- No `wppo_minify_js_exclude` / `wppo_minify_css_exclude` distinct from single `wppo_exclude_minification` (which multiplexes via `$type` arg).

---

## 4. Image Handling (Next-gen, Lazy, Placeholders, Video)

| Hook | Type | File:Line | Purpose | Fires when | Args | Priority | Consumers | Public? | Docs |
|------|------|-----------|---------|------------|------|----------|-----------|---------|------|
| `wp_generate_attachment_metadata` → `convert_image_to_next_gen_format` | `add_filter` | `class-image-optimisation.php:185` | Auto-convert uploaded image to WebP/AVIF on `wp_generate_attachment_metadata` | Filter after attachment metadata generation, conditional `convertImg` + not `core_handles_next_gen` | 2: `array $meta`, `int $id` | 10 | `Img_Converter::convert_image_to_next_gen_format` | `private` · always when `convertImg` | — |
| `wp_get_attachment_image_src` → `maybe_serve_next_gen_image` | `add_filter` | `class-image-optimisation.php:187` | Serve `.webp/.avif` variant if exists (or via client-side mime override) | Each `wp_get_attachment_image_src` | 1+ (filter value is src array) | default | `Img_Converter::maybe_serve_next_gen_image` | `private` | — |
| `delete_attachment` → `clean_placeholder_on_delete` | `add_action` | `class-image-optimisation.php:191` | Clear `dominant_color`/`lqip` placeholder cache | Attachment delete | 1: `int $id` | 10 | `Img_Converter::clean_placeholder_on_delete` | `private` | — |
| `client_side_supported_mime_types` → `filter_client_side_supported_mime_types` | `add_filter` | `class-image-optimisation.php:199` | Client-side `<picture>` mime negotiation via `Accept` | `client_side_supported_mime_types` (WP 6.9+ image pipeline) | 1 | 10 | `Image_Optimisation::filter_client_side_supported_mime_types` (gated `clientSideMimeTypeOverride`) | `private` | — |
| `wp_client_side_media_processing_enabled` → `__return_false` | `add_filter` | `class-image-optimisation.php:210` | Disable WP core client-side media processing when legacy path used | Filter | 1 | — | — | `external` (core) | — |
| `wppo_filesize_limit_bytes` | `apply_filters` | `class-img-converter.php:402,1261` | Max source bytes to attempt conversion (default 20MiB) | `convert_image()` pre-flight | 1: `int $bytes` | n/a | None | `public` (undoc — not in hooks.md) | — |
| `wppo_max_image_dimensions` (via `$max_dims` apply) | `apply_filters` | `class-img-converter.php:422,1281` | Max dims `5000×5000` gate | `convert_image()` pre-flight | 1: `array $dims` | n/a | None | `public` (undoc) | — |
| `wppo_convert_gain_map_images` | `apply_filters` | `class-img-converter.php:360` | Allow gain-map (HDR) image conversion | `convert_image()` early return | 1: `bool $allow` | n/a | None | `public` (undoc) | — |
| `wppo_convertible_image_mimes` (via `apply_filters` at 1561/1623) | `apply_filters` | `class-img-converter.php:1561,1623` | Convertible mime allowlist | `queue_unconverted_library_images` discovery | 1: `array $mimes` | n/a | None | `public` (undoc) | — |
| `shutdown` → `commit_img_info` | `add_action` | `class-img-converter.php:1750` | Deferred atomic `wppo_img_info` commit (avoid race) | `shutdown` | 0 | — | `Img_Converter::commit_img_info` | `private` | — |
| `wppo_lazyload_iframe_allowed` | `apply_filters` | `class-image-optimisation.php:2024,2769` | Veto lazy-iframe per src | `lazy_load_iframe` + regex fallback path | 3: `bool $allowed`, `string $src`, `string $tag` | n/a | None in prod | `public` | ✅ `docs/hooks.md:134` |
| `wppo_video_placeholder_allowed` | `apply_filters` | `class-image-optimisation.php:1942` | Veto video placeholder | `lazy_load_videos()` | 3: `bool $allowed`, `string $src`, `string $tag` | n/a | None | `public` (undoc) | — |
| `wppo_video_play_button_html` | `apply_filters` | `class-image-optimisation.php:1967` | Filter play button SVG | `lazy_load_videos()` | 3: `string $html`, `string $video_id`, `string $type` | n/a | None | `public` (undoc) | — |
| `wppo_video_placeholder_html` | `apply_filters` | `class-image-optimisation.php:1993` | Filter full placeholder markup | `lazy_load_videos()` | 4: `string $html`, `string $id`, `string $type`, `string $thumb` | n/a | None | `public` (undoc) | — |
| `woocommerce_gallery_image_size` | `apply_filters` (Woo) | `class-image-optimisation.php:1257` | Woo gallery image size | `get_gallery_image_size` | 1 | — | Woo | `external` | — |
| `wp_template_enhancement_output_buffer` → `prioritize_lcp_in_buffer` / `template_redirect` → `start_lcp_priority_buffer` | `add_filter`/`add_action` | `class-main.php:584,590` | LCP `fetchpriority=high` + `excludeFirstImages` from OD | 6.9+ filter prio 30 / legacy `template_redirect` prio 20 | — | `Image_Optimisation::prioritize_lcp_in_buffer` (gated `prioritizeLCPImages`) | `private` | — |

**Firing**

- `wp_generate_attachment_metadata` / `wp_get_attachment_image_src` are hot (every upload / every `the_post_thumbnail()`). Always-loaded when image optimisation conditional `convertImg` true.
- `wppo_lazyload_iframe_allowed` / video filters fire late during buffer processing (inside `process_buffer_only`) — only on front-end HTML, not REST/admin.

**Missing extension points**

- No filter to customize placeholder SVG/LQIP colour generation (only video placeholder HTML is filterable).
- No action after `wppo_perf_translations_file_written` for image pipeline observability besides that one action.
- `wppo_filesize_limit_bytes` exists but is undocumented (`docs/hooks.md` omits it) — should be documented as public.

---

## 5. HTML Minification & Output Buffering

| Hook | Type | File:Line | Purpose | Fires when | Args | Priority | Consumers | Public? | Docs |
|------|------|-----------|---------|------------|------|----------|-----------|---------|------|
| `wppo_cache_page_html` | `apply_filters` | `class-cache.php:1661` (already in §2) | Post-minify HTML mutate | Buffer pipeline | 2 | — | — | `public` | ✅ |
| `wppo_debug_log` (HTML) | `do_action` | `minify/class-html.php:238` | Minifier exception channel | HTML minify `catch` | 2: `string $msg`, `array $ctx` | — | None | `private` | — |
| `attach_session_information` → `Bfcache::attach_session_information` | `add_filter` | `class-bfcache.php:378` via `Bfcache::init:378` | Attach `bfcache_session_token` to `WP_Session_Tokens` | `attach_session_information` (login session create) | 1: `array $session` | 10 | `Bfcache::attach_session_information` (gated `wppo_bfcache_enabled`) | `private` (core filter) | — |
| `nocache_headers` → `filter_nocache_headers` | `add_filter` | `class-bfcache.php:381` | Strip `no-store` → `private, no-cache` when bfcache opted-in | `nocache_headers` | 1: `array $headers` | 1000 | `Bfcache::filter_nocache_headers` | `private` | — |

---

## 6. Database Cleanup

| Hook | Type | File:Line | Purpose | Fires when | Args | Priority | Consumers | Public? | Docs |
|------|------|-----------|---------|------------|------|----------|-----------|---------|------|
| `wppo_database_cleanup_completed` | `do_action` | `class-database-cleanup.php:737` | After `clean_all()` completes | After batched deletes + `invalidate_counts_cache()` | 3: `string $type='all'`, `int $total_deleted`, `array\|null $results` | n/a | No prod `add_action` (operator hook) — WP-CLI logs after it anyway | `public` | ✅ `docs/hooks.md:44` |
| `wppo_database_cleanup_cron` → `database_cleanup_cron` | `add_action` | `class-cron.php:66` via `Cron::__construct:66` | Auto-clean per `dbSchedule` (daily/weekly/monthly) | Daily cron `wppo_database_cleanup_cron` (always scheduled) | 0 | 10 | `Cron::database_cleanup_cron` | `private` (internal cron name) | — |
| `save_post` / `deleted_post` → `on_post_change` | `add_action` | `class-main.php:596-597` (duplicate of §2) | Invalidate `wppo_db_cleanup_counts` salt | Post mutation | 2 | 10 | `Database_Cleanup::on_post_change` | `private` | — |

**Firing**

- `wppo_database_cleanup_cron` is always scheduled (daily) but early-returns when `dbSchedule==='none'` or not due — cheap.
- `wppo_database_cleanup_completed` only fires for `type=all` path in `clean_all()` — per-type cleaners (`clean_revisions`, etc.) do **not** fire the action. This is a gap: consumers expecting per-type events get none.

**Missing extension points**

- No filter to adjust `get_revision_defaults()` (`max_age`/`keep_latest`) without patching DB — settings UI has those keys but no filter.
- No `wppo_before_database_cleanup` action (only after). No `wppo_database_cleanup_type_completed` per-type action.

---

## 7. RUM / Web Vitals / AI Adaptive

| Hook | Type | File:Line | Purpose | Fires when | Args | Priority | Consumers | Public? | Docs |
|------|------|-----------|---------|------------|------|----------|-----------|---------|------|
| `wp_enqueue_scripts` → `RUM::maybe_enqueue_scripts` | `add_action` | `class-main.php:619` | Enqueue `rum.js` beacon | `wp_enqueue_scripts` | 0 | 5 | `RUM::maybe_enqueue_scripts` (gated `rum_enabled` + `!is_admin`) | `private` · always-registered, early-return when disabled | — |
| `wp_footer` → `RUM::print_config` | `add_action` | `class-main.php:620` | Print `wppoRUMConfig` JSON (token, endpoint) | `wp_footer` | 0 | 90 | `RUM::print_config` | `private` | — |
| `wppo_rum_flush` → `RUM::flush_queue` | `add_action` | `class-cron.php:74` | Flush buffered RUM beacons to `wppo_web_vitals_rum` option | Cron `wppo_rum_flush` | 0 | 10 | `RUM::flush_queue` | `private` (internal) | — |
| `shutdown` → `RUM::flush_shutdown_buffer` (conditional) | `add_action` | `class-rum.php:352` (inside `RUM::collect` when `function_exists('add_action')`) | Flush HTTP beacon queue at end of request | `shutdown` | 0 | 10 | `RUM::flush_shutdown_buffer` | `private` | — |
| `wppo_web_vitals_rescan` → `web_vitals_rescan_cron` | `add_action` | `class-cron.php:68` | Daily/weekly PageSpeed rescan queue | Daily cron, gated `auto_rescan daily|weekly` | 0 | 10 | `Cron::web_vitals_rescan_cron` | `private` | — |
| `wppo_ai_adaptive_enabled` | `apply_filters` | `class-ai-adaptive.php:60` | Gate AI adaptive globally | `AI_Adaptive::is_enabled()` | 1: `bool $enabled` | n/a | None | `public` | ✅ `docs/hooks.md:346` |
| `wppo_ai_adaptive_eagerness` | `apply_filters` | `class-ai-adaptive.php:315` | Override speculation eagerness from RUM | `heuristic_learn()` | 2: `string $eagerness`, `array $rum` | n/a | None | `public` | ✅ `docs/hooks.md:358` |
| `wppo_ai_adaptive_speculation_rules` | `apply_filters` | `class-ai-adaptive.php:465` | Filter AI-injected speculation rules | `filter_speculation_rules()` after appending top-2 URLs | 2: `array $rules`, `string[] $urls` | n/a | None | `public` | ✅ `docs/hooks.md:367` |
| `wp_speculation_rules` → `filter_speculation_rules` | `add_filter` | `class-ai-adaptive.php:476` via `AI_Adaptive::init:476` | Inject AI prefetch rule into WP 6.8+ speculation rules | `wp_speculation_rules` filter (gated `function_exists('wp_get_speculation_rules_configuration')`) | 1: `array $rules` | 20 | `AI_Adaptive::filter_speculation_rules` | `private` (core filter, conditional) | — |
| `wppo_edge_cache_enabled` | `apply_filters` | `class-edge-cache.php:85` | Gate edge cache adapter | `Edge_Cache::is_enabled()` | 1: `bool $enabled` | n/a | None | `public` | ✅ `docs/hooks.md:377` |
| `wppo_edge_cache_config` | `apply_filters` | `class-edge-cache.php:143` | Mutate adapter config before template generation | `get_config()` | 1: `array{origin_url,cache_ttl,swr,provider}` | n/a | None | `public` | ✅ `docs/hooks.md:418` |
| `wppo_edge_cache_worker_content` | `apply_filters` | `class-edge-cache.php:203` | Filter Cloudflare Worker JS after placeholder replace | `get_worker_js()` | 2: `string $content`, `array $config` | n/a | None | `public` | ✅ `docs/hooks.md:391` |
| `wppo_edge_cache_wrangler_content` | `apply_filters` | `class-edge-cache.php:240` | Filter `wrangler.toml` | `get_wrangler_toml()` | 2: `string $toml`, `array $config` | n/a | None | `public` | ✅ `docs/hooks.md:400` |
| `wppo_edge_cache_bunny_content` | `apply_filters` | `class-edge-cache.php:284` | Filter Bunny edge JS | `get_bunny_edge_js()` | 2: `string $content`, `array $config` | n/a | None | `public` | ✅ `docs/hooks.md:410` |

**Firing**

- RUM hooks are always-registered (`class-main.php:619-620`) but gate on `rum_enabled` inside methods — not conditional at `add_action` time.
- `AI_Adaptive::init()` conditionally adds `wp_speculation_rules` only when `wp_get_speculation_rules_configuration` exists (WP 6.8+) — safe fallback.

---

## 8. Object Cache (Redis)

| Hook | Type | File:Line | Purpose | Fires when | Args | Priority | Consumers | Public? | Docs |
|------|------|-----------|---------|------------|------|----------|-----------|---------|------|
| `wppo_object_cache_dropin_path` | `apply_filters` | `class-object-cache.php:67` | Override `WP_CONTENT_DIR . '/object-cache.php'` path | `Object_Cache::__construct()` | 1: `string $path` | n/a | None | `public` (undoc) | — |
| `wppo_redis_allow_request_password` | `apply_filters` | `class-rest.php:1130` + `class-object-cache.php` (same name) | Allow request-supplied Redis password even when `WPPO_REDIS_PASSWORD` constant set | `Rest::build_redis_config()` / `Object_Cache::enable()` | 1: `bool $allow` (default `false`) | n/a | None | `public` (undoc) | — |
| `object_cache_allow_flush_all` (core/community) | `apply_filters` | `templates/object-cache.php:532` | Gate `wp_cache_flush()` flush-all when drop-in active | Inside drop-in `WP_Object_Cache::flush()` | 1: `bool $allow` (default `false`) | n/a | None | `external` (WordPress pattern) | — |

**Note:** CLI `wp wppo object-cache enable` allowlist is narrower than REST (`host,port,password,database,timeout,prefix` at `class-wppo-cli-command.php:864-871` vs REST `mode,host,port,password,database,nodes,master_name,use_tls,persistent,compression` at `class-rest.php:1105`). No hook involved — just a gap.

---

## 9. Settings (wppo_settings store + UI)

| Hook | Type | File:Line | Purpose | Fires when | Args | Priority | Consumers | Public? | Docs |
|------|------|-----------|---------|------------|------|----------|-----------|---------|------|
| `update_option_wppo_settings` → `on_settings_update` | `add_action` | `class-main.php:789` + `class-main.php:1108` (rollback re-add) | Main invalidation/routing (see §2) | `update_option('wppo_settings')` | 2: `mixed $old`, `mixed $new` | 10 | `Main::on_settings_update` | `private` | — |
| `update_option_wppo_settings` → `Util::on_settings_update` | `add_action` | `class-util.php:245` | Bump `wppo_settings` cache + multisite `Util::transient_key` memo clear | Same | 2 | 10 | `Util::on_settings_update` | `private` | — |
| `add_option_wppo_settings` → `Util::on_settings_add` | `add_action` | `class-util.php:246` | Same cache bump on first add | `add_option` | 2 | 10 | `Util::on_settings_add` | `private` | — |
| `delete_option_wppo_settings` → `Util::clear_settings_cache` | `add_action` | `class-util.php:247` | Clear memo on delete | `delete_option` | 0 | 10 | `Util::clear_settings_cache` | `private` | — |
| `switch_blog` → `Util::on_switch_blog` | `add_action` | `class-util.php:248` | Reset `Util::cached_home_url` + settings memo per blog | `switch_blog` | 2: `int $new_blog`, `int $prev_blog` | 10 | `Util::on_switch_blog` | `private` | — |
| `update_option_wppo_settings` → `Llms::on_settings_update` | `add_action` | `class-main.php:637` | Regenerate `llms.txt` when `llms_txt.enabled/source` changes | Same settings update | 2 | 10 | `Llms::on_settings_update` | `private` | — |
| `update_option_wppo_settings` (rollback) → re-add `on_settings_update` | `add_action` | `class-main.php:1108` | Temporary remove/add to avoid infinite loop when htaccess write fails and setting rolled back | Inside `on_settings_update` error path | 2 | 10 | self | `private` | — |
| `wppo_litespeed_mode` etc. | `apply_filters` | §11 | LiteSpeed settings gated by filters (see §11) | Settings read | — | — | — | `public` | ✅ |

**Firing**

- All `update_option_wppo_settings` listeners are always-registered (unconditional `add_action` in `Main::setup_hooks` + `Util::__construct` via `Main::__construct`? Actually `Util` registers in constructor at `class-util.php:245-248` but `Util` object not instantiated explicitly — those `add_action` run when `Util` class first autoloaded? Check `class-util.php:245` is inside a static init — always-registered after `Main::__construct` loads settings.
- `switch_blog` always — multisite-safe.

---

## 10. REST API

| Hook | Type | File:Line | Purpose | Fires when | Args | Priority | Consumers | Public? | Docs |
|------|------|-----------|---------|------------|------|----------|-----------|---------|------|
| `rest_api_init` → `Rest::register_routes` | `add_action` | `class-main.php:615` | Register 25 routes under `performance-optimisation/v1` | `rest_api_init` (after `init`) | 0 | 10 | `Rest::register_routes` → `register_rest_route()` 25× | `private` (WP core hook) | — |
| `wp_ajax_wppo_get_nonce` → `ajax_get_nonce` | `add_action` | `class-main.php:793` | Refresh `X-WP-Nonce` on 403 (admin bar + SPA) | `wp_ajax_wppo_get_nonce` | 0 | 10 | `Rest::ajax_get_nonce` | `private` | — |
| Routes (namespace `performance-optimisation/v1`): `clear_cache`, `update_settings`, `optimise_image`, `delete_optimised_image`, `recent_activities`, `import_settings`, `database_cleanup`, `database_cleanup_counts`, `get_page_assets`, `image_job_status`, `object_cache`, `system_info`, `performance_scan`, `pagespeed_scan`, `pagespeed_results`, `web_vitals_trends`, `suggestions`, `server_rules`, `used_css_regenerate`, `regenerate_ccss`, `ccss_status`, `dismiss_welcome`, `rum_collect` (public `__return_true`), `rum_data`, `autoloaded_options`, `ai_model`, `ai_learn`, `ai_suggestions` | `register_rest_route` | `class-rest.php:58-260` (`get_routes()`) | See `WP-CLI-CURRENT.md` §3 for parity gap matrix | REST dispatch | — | — | `Rest` handlers | `private` (except `rum_collect` public) | — |

**Missing extension points**

- No `rest_pre_dispatch` filter to audit REST calls beyond `permission_callback` (`manage_options` + `X-WP-Nonce`, except `rum_collect` token+rate-limit). No rate-limit hook for admin routes.
- No `wppo_rest_update_settings_before` / `after` actions around `update_option` — only the generic `update_option_wppo_settings` fires.

---

## 11. CLI (WP-CLI)

| Hook | Type | File:Line | Purpose | Fires when | Args | Priority | Consumers | Public? | Docs |
|------|------|-----------|---------|------------|------|----------|-----------|---------|------|
| `WP_CLI::add_command('wppo', WPPO_CLI_Command)` | `WP_CLI` API (not WP hook) | `class-main.php:472-474` inside `Main::includes()` | Register `wp wppo {cache,database,image,settings,object-cache,pagespeed,system-info}` (7 subcommands, `after_wp_load`) | File load when `WP_CLI` defined | — | — | `WPPO_CLI_Command` | `private` (CLI) | `WP-CLI-CURRENT.md` |

No WP `add_action`/`add_filter` in CLI file — pure `WP_CLI::success|error|warning|log`. See `WP-CLI-CURRENT.md` for per-subcommand validation table and `ADMIN UI → CLI gap matrix`.

---

## 12. Cron & Background Jobs (Action Scheduler + WP-Cron)

| Hook (cron name) | Type | File:Line | Purpose | Fires when | Args | Priority | Consumers | Public? | Docs |
|------------------|------|-----------|---------|------------|------|----------|-----------|---------|------|
| `init` → `schedule_cron_jobs` | `add_action` | `class-cron.php:57` | Schedule all recurring crons | `init` | 0 | 10 | `Cron::schedule_cron_jobs` | `private` · always | — |
| `cron_schedules` → `add_custom_cron_interval` | `add_filter` | `class-cron.php:61` | Add `every_5_hours` | `cron_schedules` filter | 1: `array $schedules` | 10 | `Cron::add_custom_cron_interval` | `private` | — |
| `wppo_page_cron_hook` → `wppo_page_cron_callback` | `add_action` | `class-cron.php:58` | Batch preload entry point (5-hourly) | `init` schedules it; fires every 5h when `enablePreloadCache` | 0 | 10 | `Cron::wppo_page_cron_callback` → `schedule_page_cron_jobs()` | `private` (internal) | — |
| `wppo_page_cron_batch` → `wppo_page_cron_callback` | `add_action` | `class-cron.php:59` | Follow-up batch (60s after) | Single event scheduled by `schedule_page_cron_jobs:346` | 0 | 10 | same | `private` | — |
| `wppo_generate_static_page` → `process_page` | `add_action` | `class-cron.php:63` | `wp_remote_get($permalink)` to warm `index.html` | Single event per `get_posts` ID (200/batch, jitter 0-1800s) | 1: `int $page_id` | 10 | `Cron::process_page` | `private` (internal) | — |
| `wppo_generate_static_url` → `process_url` | `add_action` | `class-cron.php:64` | Sitemap URL warm (non-post URLs) | Single event per sitemap `<loc>` (500 cap) | 1: `string $url` | 10 | `Cron::process_url` | `private` | — |
| `wppo_img_conversion` → `img_convert_cron` | `add_action` | `class-cron.php:60` | Hourly discovery + batch convert (50/batch, `wppo_cron_discovery_limit` filter) | Hourly | 0 | 10 | `Cron::img_convert_cron` | `private` | — |
| `wppo_database_cleanup_cron` → `database_cleanup_cron` | `add_action` | `class-cron.php:66` | Daily auto-clean | Daily | 0 | 10 | `Cron::database_cleanup_cron` (gates on `dbSchedule`) | `private` | — |
| `wppo_web_vitals_rescan` → `web_vitals_rescan_cron` | `add_action` | `class-cron.php:68` | Daily/weekly PageSpeed re-queue | Daily | 0 | 10 | `Cron::web_vitals_rescan_cron` | `private` | — |
| `wppo_llms_txt_daily` → `llms_txt_cron` | `add_action` | `class-cron.php:70` | Daily LLMs.txt regen | Daily when `llms_txt.enabled` | 0 | 10 | `Cron::llms_txt_cron` | `private` | — |
| `wppo_used_css_cron` → `used_css_cron` | `add_action` | `class-cron.php:72` | 5-hourly used-CSS regenerate | `every_5_hours` when `removeUnusedCSS` | 0 | 10 | `Cron::used_css_cron` | `private` | — |
| `wppo_ccss_regeneration` → `ccss_regeneration_cron` | `add_action` | `class-cron.php:73` | Daily CCSS regeneration | Daily | 0 | 10 | `Cron::ccss_regeneration_cron` | `private` | — |
| `wppo_rum_flush` → `RUM::flush_queue` | `add_action` | `class-cron.php:74` | Flush RUM buffer | Cron `wppo_rum_flush` (scheduled by `RUM::collect`) | 0 | 10 | `RUM::flush_queue` | `private` | — |
| `wppo_convert_image_background` → `process_background_image` | `add_action` | `class-main.php:775` | AS async image convert | `as_enqueue_async_action('wppo_convert_image_background', [{source_path,format}])` | 1: `array $args` | 10 | `Main::process_background_image` → `Img_Converter::convert_image` | `private` (AS group `performance_optimisation`) | — |
| `wppo_pagespeed_scan` → `Pagespeed::run_scan` | `add_action` | `class-main.php:778` | AS async PageSpeed fetch | `as_enqueue_async_action('wppo_pagespeed_scan', [{url,strategy}])` | 1: `array $args` | 10 | `Pagespeed::run_scan` | `private` | — |
| `wppo_used_css_generate` → `Used_CSS::process_background` | `add_action` | `class-main.php:781` | AS async used-CSS per post | `as_enqueue_async_action('wppo_used_css_generate', [{post_id}])` | 1: `array $args` | 10 | `Used_CSS::process_background` | `private` | — |
| `wppo_cron_discovery_limit` | `apply_filters` | `class-cron.php:666` | Images to discover per `img_convert_cron` run (default 50) | Discovery phase | 1: `int $limit` | n/a | None | `public` (undoc) | — |

**Firing**

- `init` scheduling is early, runs on every request (front + admin + CLI) but `wp_next_scheduled` checks make it cheap. Actual workers (`wppo_generate_static_page/url`, `wppo_convert_image_background`, etc.) are late, invoked by WP-Cron HTTP or AS queue runner — not during normal page generation.
- `wppo_generate_static_page`/`url` use `wp_rand(0,1800)` jitter — not immediate.

**Missing extension points**

- No filter to adjust preload sitemap discovery cap (hard 500) or batch size (hard 200) — only `wppo_cron_discovery_limit` for image discovery.
- No action before/after `schedule_page_cron_jobs` to instrument preload coverage.

---

## 13. Admin & Frontend Plumbing

### 13.1 Admin

| Hook | Type | File:Line | Purpose | Fires when | Args | Priority | Consumers | Public? | Docs |
|------|------|-----------|---------|------------|------|----------|-----------|---------|------|
| `admin_menu` → `init_menu` | `add_action` | `class-main.php:486` | Register `Settings > Performance Optimisation` SPA mount | `admin_menu` | 0 | 10 | `Main::init_menu` | `private` · always | — |
| `admin_init` → `maybe_fix_wp_cache` | `add_action` | `class-main.php:487` | Auto-fix `WP_CACHE` constant if missing (hourly throttle via transient) | `admin_init` | 0 | 10 | `Main::maybe_fix_wp_cache` | `private` | — |
| `admin_init` → `maybe_run_upgrades` | `add_action` | `class-main.php:488` | Run `Activate::maybe_run_upgrades` on admin | `admin_init` | 0 | 10 | `Main::maybe_run_upgrades` | `private` | — |
| `admin_init` → `maybe_run_version_upgrade` | `add_action` | `class-main.php:491` | One-time drop-in regen + cache clear per version | `admin_init` | 0 | 10 | `Main::maybe_run_version_upgrade` | `private` | — |
| `upgrader_process_complete` → `maybe_schedule_upgrade_routine` | `add_action` | `class-main.php:490` (2 args) + `class-main.php:492` (0 args wrapper for version upgrade) | Background upgrade when updated via WP-CLI/managed host | `upgrader_process_complete` | 2: `$upgrader`, `$hook_extra` (490) / 0 (492) | 10 | `Main::maybe_schedule_upgrade_routine`, `Main::maybe_run_version_upgrade` | `private` | — |
| `admin_init` → `maybe_migrate_block_assets_setting` | `add_action` | `class-main.php:493` | Backfill `blockAssetsOnDemand=true` on WP 6.9+ | `admin_init` | 0 | 10 | `Main::maybe_migrate_block_assets_setting` | `private` | — |
| `admin_enqueue_scripts` → `admin_enqueue_scripts` | `add_action` | `class-main.php:494` | Enqueue SPA `build/index.js` + `wppoSettings` localize | `admin_enqueue_scripts` | 0 | 10 | `Main::admin_enqueue_scripts` | `private` | — |
| `admin_bar_menu` → `add_setting_to_admin_bar` | `add_action` | `class-main.php:533` | «Clear All Cache» / «Clear This Page» + nonce refresh | `admin_bar_menu` | 1: `WP_Admin_Bar` | 100 | `Main::add_setting_to_admin_bar` | `private` | — |
| `admin_notices` → `render_notices` | `add_action` | `class-admin-notices.php:44` | Show `wppo_activation_notices` transient | `admin_notices` | 0 | 10 | `Admin_Notices::render_notices` | `private` · always | — |
| `admin_init` → `handle_dismiss` | `add_action` | `class-admin-notices.php:45` | Dismiss notice via `?wppo_dismiss=` | `admin_init` | 0 | 10 | `Admin_Notices::handle_dismiss` | `private` | — |
| `add_meta_boxes` → `add_metabox` | `add_action` | `class-metabox.php:39` via `Metabox::__construct:39` | Per-page preload image + asset manager metabox | `add_meta_boxes` | 0 | 10 | `Metabox::add_metabox` — always | `private` | — |
| `save_post` → `save_metabox` | `add_action` | `class-metabox.php:41` | Save `_wppo_preload_image_url`, `_wppo_disabled_scripts/styles` | `save_post` | 1: `int $post_id` | 10 | `Metabox::save_metabox` — always | `private` | — |
| `wp_abilities_api_categories_init` / `wp_abilities_api_init` | `add_action` | `class-abilities.php:35-36` via `Abilities::__construct:35` | Register Abilities API categories/abilities (WP 6.9+) | Abilities API init | 0 | 10 | `Abilities::register_*` — always | `private` | — |
| `wp_enqueue_scripts` / `admin_enqueue_scripts` → `Asset_Manager::capture_page_assets` + `dequeue_selected_assets` | `add_action` | `class-asset-manager.php:79,82` + instantiated at `class-main.php:764` | Per-page asset capture (footer at 9999) + dequeue selected | `wp_footer` 9999 / `wp_enqueue_scripts` 9999 | 0 | 9999 | `Asset_Manager` — always | `private` | — |
| `admin_enqueue_scripts` → `Bfcache::enqueue_scripts` | `add_action` | `class-bfcache.php:384` via `Bfcache::init:384` | bfcache script for admin (customize) | `admin_enqueue_scripts` | 0 | 10 | `Bfcache::enqueue_scripts` | `private` · always but gates on `is_enabled` | — |

### 13.2 Frontend (always or gated)

| Hook | Type | File:Line | Purpose | Fires when | Args | Priority | Consumers | Public? | Docs |
|------|------|-----------|---------|------------|------|----------|-----------|---------|------|
| `init` → `set_role_hash_cookie` | `add_action` | `class-main.php:495` | Set `wppo_role_hash` cookie for logged-in cache variant | `init` (front only, skips `is_admin`/`REST_REQUEST`) | 0 | 10 | `Main::set_role_hash_cookie` — always | `private` | — |
| `wp_logout` → `clear_role_hash_cookie` | `add_action` | `class-main.php:496` | Clear role hash cookie | `wp_logout` | 0 | 10 | `Main::clear_role_hash_cookie` — always | `private` | — |
| `template_redirect` → `capture_template_start` | `add_action` | `class-main.php:560` | Record `microtime(true)` for Server-Timing | `template_redirect` prio 0 | 0 | 0 | `Main::capture_template_start` (gated `server_timing_enabled() && wp_should_output_buffer_template_for_enhancement`) | `private` | — |
| `wp_finalized_template_enhancement_output_buffer` → `emit_server_timing_header` | `add_action` | `class-main.php:561` | Emit `Server-Timing: wppo;dur=…` | Finalized buffer | 1: `string $output` | 0 | `Main::emit_server_timing_header` — same gate | `private` | — |
| `wppo_server_timing_enabled` | `apply_filters` | `class-main.php:1252` | Gate Server-Timing header | `server_timing_enabled()` | 1: `bool $enabled` | n/a | None | `public` (undoc) | — |
| `wp_enqueue_scripts` → `Bfcache::enqueue_scripts` | `add_action` | `class-bfcache.php:382` via `Bfcache::init:382` | bfcache invalidation inline script | `wp_enqueue_scripts` | 0 | 10 | `Bfcache::enqueue_scripts` — always but gates on `is_enabled` + `is_user_logged_in` | `private` | — |
| `wppo_bfcache_enabled` | `apply_filters` | `class-bfcache.php:85` | Gate bfcache | `Bfcache::is_enabled()` | 1: `bool $enabled` | n/a | None | `public` | ✅ `docs/hooks.md:315` |
| `wppo_perf_translations_enabled` | `apply_filters` | `class-perf-translations.php:63` | Gate `.mo→php` compilation | `Perf_Translations::is_enabled()` | 1: `bool $enabled` | n/a | None | `public` | ✅ `docs/hooks.md:329` |
| `load_translation_file` / `load_textdomain_mofile` → `filter_load_file` | `add_filter` | `class-perf-translations.php:229-230` via `Perf_Translations::init:229` | Serve compiled `.php` translation when newer than `.mo` | Translation load | 2: `string $file`, `string $domain` | 10 | `Perf_Translations::filter_load_file` — always via `Perf_Translations::init` (gated inside) | `private` (core filters) | — |
| `wppo_perf_translations_file_written` | `do_action` | `class-perf-translations.php:199` | After compiled file written | `Perf_Translations::compile()` | 3: `string $cache_file`, `string $mofile`, `string $domain` | n/a | `Perf_Translations::opcache_invalidate:231` (self) | `public` | ✅ `docs/hooks.md:426` |
| `wppo_perf_translations_file_written` → `opcache_invalidate` | `add_action` | `class-perf-translations.php:231` | `opcache_invalidate($cache_file, true)` | Same action | 3 | 10 | self | `private` | — |
| `upgrader_process_complete` → `on_upgrader_complete` (perf translations) | `add_action` | `class-perf-translations.php:233` | Clear stale compiled lang files | Upgrader complete | 2 | 10 | `Perf_Translations::on_upgrader_complete` | `private` | — |

---

## 14. Compatibility Layers

### 14.1 LiteSpeed / OpenLiteSpeed (coexistence)

Registered unconditionally in `class-main.php:442-449 includes()`, but behavior gated by `LiteSpeed_Integration::is_litespeed()` (detects `$_SERVER['HTTP_X_LITESPEED']` etc. + `wppo_litespeed_is_litespeed` filter).

| Hook | Type | File:Line | Purpose | Fires when | Args | Priority | Consumers | Public? | Docs |
|------|------|-----------|---------|------------|------|----------|-----------|---------|------|
| `wppo_litespeed_is_litespeed` | `apply_filters` | `class-litespeed-integration.php:221` | Override LS detection | `is_litespeed()` | 1: `bool $is` | n/a | None | `public` | ✅ `docs/hooks.md:154` |
| `wppo_litespeed_is_lscache_active` | `apply_filters` | `class-litespeed-integration.php:286` | Override LSCache active detection | `is_lscache_active()` | 1 | n/a | None | `public` (undoc) | — |
| `wppo_litespeed_mode` | `apply_filters` | `class-litespeed-integration.php:319` | `auto|wppo|litespeed|standalone` | `get_mode()` | 1: `string $mode` | n/a | None | `public` | ✅ `docs/hooks.md:168` |
| `wppo_litespeed_effective_mode` | `apply_filters` | `class-litespeed-integration.php:391` | Effective mode after `auto` resolution | `effective_mode()` | 2: `string $effective`, `string $raw_mode` | n/a | None | `public` (undoc) | — |
| `wppo_litespeed_should_disable_optimizer` | `apply_filters` | `class-litespeed-integration.php:455` | Force disable WPPO optimiser even when LS not detected | `should_disable_wppo_optimizer()` | 2: `bool $disable`, `string $effective_mode` | n/a | None | `public` (undoc) | — |
| `litespeed_can_optm` (LS) / `wppo_litespeed_should_disable_optimizer` | `has_filter`+`apply_filters` | `class-litespeed-integration.php:444` | Respect LS `litespeed_can_optm` ecosystem flag | Same gate | 1 | n/a | LS | `external` | — |
| `wppo_litespeed_purge_sync` | `apply_filters` | `class-litespeed-integration.php:485` | Whether to purge LS when WPPO clears | `purge_all()` | 1: `bool $sync` | n/a | None | `public` (undoc) | — |
| `litespeed_purge_all` / `litespeed_purge_url` / `litespeed_purge_post` | `do_action` | `class-litespeed-integration.php:538,560,581` | Emit LS purge signals (WPPO → LS) | `purge_all/url/post` | 1: `string $reason`/`$url`/`$id` | n/a | LS plugin consumes if present (no WPPO consumer) | `external` (LS) | — |
| `litespeed_purged_all` | `add_action` | `class-litespeed-integration.php:600` | LS → WPPO sync: LS purged all → clear WPPO | `litespeed_purged_all` (LS fires) | 0 | 10 | `LiteSpeed_Integration::handle_litespeed_purged_all` | `private` (LS→WPPO) | — |
| `litespeed_purged_post` | `add_action` | `class-litespeed-integration.php:601` | LS purged post → clear WPPO single path | `litespeed_purged_post` | 1: `int $post_id` | 10 | `handle_litespeed_purged_post` | `private` | — |
| `litespeed_purge_finalize` | `add_action` | `class-litespeed-integration.php:602` | LS finalize → maybe clear | LS finalize | 0 | 10 | `handle_litespeed_purge_finalize` | `private` | — |
| `send_headers` → `handle_send_headers` | `add_action` | `class-litespeed-integration.php:605` | Emit `X-LiteSpeed-Cache-Control`, `X-LiteSpeed-Tag`, `X-LiteSpeed-Vary` | `send_headers` prio 0 | 0 | 0 | `LiteSpeed_Integration::handle_send_headers` | `private` · `add_action` always via `LiteSpeed_Integration::init:605` | — |
| `litespeed_vary` → `filter_litespeed_vary` | `add_filter` | `class-litespeed-integration.php:606` | Append `wppo_role_hash` vary | `litespeed_vary` filter | 1: `string $vary` | 10 | `LiteSpeed_Integration::filter_litespeed_vary` | `private` | — |
| `wppo_litespeed_ttl` | `apply_filters` | `class-litespeed-integration.php:706` (cacheLife→seconds) + `:944` (generic) | Override LS TTL seconds | `get_ttl()` + `emit_cache_control()` | 2 or 1: `(int $seconds, int $hours)` or `(int $ttl)` | n/a | None | `public` | ✅ `docs/hooks.md:174` |
| `wppo_litespeed_is_cacheable` | `apply_filters` | `class-litespeed-integration.php:741,794` | Override LS cacheable decision | `is_cacheable()` | 1: `bool $cacheable` | n/a | None | `public` | ✅ `docs/hooks.md:190` |
| `wppo_litespeed_vary_enabled` | `apply_filters` | `class-litespeed-integration.php:827` | Gate `wppo_role_hash` → `litespeed_vary` bridge | `should_vary()` | 1: `bool $enable` | n/a | None | `public` | ✅ `docs/hooks.md:204` |
| `wppo_litespeed_vary` | `apply_filters` | `class-litespeed-integration.php:1088` | Filter final `X-LiteSpeed-Vary` value | `filter_litespeed_vary()` | 1: `string $vary` | n/a | None | `public` | ✅ `docs/hooks.md:199` |
| `wppo_litespeed_vary_fallback` | `apply_filters` | `class-litespeed-integration.php:921` | Fallback `cookie=wppo_role_hash` | `emit_vary()` | 1: `string $fallback` | n/a | None | `public` (undoc) | — |
| `wppo_litespeed_tag` | `apply_filters` | `class-litespeed-integration.php:1025` | `X-LiteSpeed-Tag` default `WPPO` | `emit_tag()` | 1: `string $tag` | n/a | None | `public` | ✅ `docs/hooks.md:194` |
| `wppo_litespeed_tag_post_id` | `apply_filters` | `class-litespeed-integration.php:1045` | Post ID for `litespeed_tag_post` | `emit_tag()` post path | 1: `int $post_id` | n/a | None | `public` (undoc) | — |
| `litespeed_tag` / `litespeed_tag_post` | `do_action` | `class-litespeed-integration.php:1029,1048` | Emit LS tag signals (WPPO → LS) | `emit_tag()` | 1 | n/a | LS consumes | `external` | — |
| `wppo_litespeed_cache_control_header` / `wppo_litespeed_nocache_reason` / `wppo_litespeed_nocache_header` | `apply_filters` | `class-litespeed-integration.php:958,987,1002` | Customize LS cache-control / nocache header/reason | `handle_send_headers` no-cache path | 1-2 | n/a | None | `public` (undoc) | — |
| `litespeed_control_set_ttl` / `litespeed_control_set_nocache` | `do_action` | `class-litespeed-integration.php:961,990` | LS bitmask control (WPPO → LS) | When `has_action('litespeed_control_set_ttl/nocache')` | 1: `int $ttl` / `string $reason` | n/a | LS | `external` | — |
| `litespeed_control_set_nocache` via `Cache::maybe_mark_page_not_cacheable` | `do_action` | `class-cache.php:1763` | WPPO file-cache claims nocache for LS | When LS owns cache but WPPO file cache bypassed | 1: `string $reason` | n/a | LS | `external` | — |
| `wppo_litespeed_bypass_file_cache` | `apply_filters` | `class-cache.php:1774` | Bypass file cache when LS owns | `Cache::is_cacheable()` LS branch | 1: `bool $bypass` (default true) | n/a | None | `public` | ✅ `docs/hooks.md:214` |
| `wppo_litespeed_nextgen_rewrite` / `wppo_litespeed_enable_nextgen_rewrite` (alias) | `apply_filters` | `class-server-rules.php:133` + `class-htaccess-handler.php:174` + `class-litespeed-integration.php:1128,1136,1167,1168` | Enable LS next-gen `Vary:Accept` rewrite | Htaccess/Nginx rules generation | 1: `bool $use` | n/a | Self (both htaccess+nginx + LiteSpeed_Integration duplicate checks) | `public` | ✅ `docs/hooks.md:219` (+ alias 224) |
| `wppo_litespeed_brotli` / `wppo_litespeed_enable_brotli` (alias) | `apply_filters` | `class-cache.php:1706` + `class-litespeed-integration.php:1210,1218` | Enable `.br` brotli generation | Cache save | 1: `bool $use` | n/a | None | `public` | ✅ `docs/hooks.md:230` (+ alias 235) |
| `wppo_htaccess_rules` / `wppo_htaccess_nextgen_rules` | `apply_filters` | `class-htaccess-handler.php:217,208` | Filter full htaccess rules + next-gen block | `Htaccess_Handler::get_rules()` | 1: `array $rules` | n/a | None | `public` | ✅ `docs/hooks.md:245,250` |
| `wppo_nginx_rules` / `wppo_nginx_nextgen_rules` | `apply_filters` | `class-server-rules.php:173,162` | Filter nginx rules | `Server_Rules::get_rules()` | 1: `string` / `array` | n/a | None | `public` | ✅ `docs/hooks.md:255,260` |
| `wppo_litespeed_strip_cache_control` | `apply_filters` | `class-litespeed-integration.php:1281` | Strip generic `Cache-Control` when LS sends `X-LS-Cache-Control: public` | `handle_send_headers` | 1: `bool $strip` (true) | n/a | None | `public` | ✅ `docs/hooks.md:210` |
| `wppo_litespeed_can_cdn` (already in §3) | `apply_filters` | `class-litespeed-integration.php:1246` | Gate CDN | `can_apply_cdn()` | 1 | n/a | None | `public` | ✅ `docs/hooks.md:240` |
| `LiteSpeed_Integration::init` | `add_action` orchestrator | `class-main.php:797` `LiteSpeed_Integration::init()` | Registers all LS `add_action('litespeed_purged_*')` + `send_headers` + `litespeed_vary` | `Main::setup_hooks` conditional `class_exists` | — | — | self | `private` | — |

### 14.2 Core Tweaks (remove bloat)

All gated by `file_optimisation` toggles; registered in `Core_Tweaks::__construct` at `class-core-tweaks.php:30-100` (always instantiated at `class-main.php:356` but each `add_action`/`add_filter` gated).

| Hook | Type | File:Line | Purpose | Fires when | Priority | Consumers | Public? |
|------|------|-----------|---------|------------|----------|-----------|---------|
| `init` → `disable_emojis` (+ `wp_enqueue_scripts:100` / `admin_enqueue_scripts:100` → `disable_emojis_script_module`) | `add_action` | `class-core-tweaks.php:37,39,40` | Remove emoji scripts/styles | `init` / enqueue | default | `Core_Tweaks` | `private` |
| `tiny_mce_plugins` → `disable_emojis_tinymce` | `add_filter` | `class-core-tweaks.php:116` | Strip `wpemoji` from TinyMCE | TinyMCE init | 10 | `Core_Tweaks` | `private` |
| `wp_resource_hints` → `disable_emojis_remove_dns_prefetch` | `add_filter` | `class-core-tweaks.php:117` | Remove `https://s.w.org` prefetch | `wp_resource_hints` | 10 (2 args) | `Core_Tweaks` | `private` |
| `emoji_svg_url` (core) | `apply_filters` | `class-core-tweaks.php:163` | SVG URL (core calls, not added) | Emoji URL | — | — | `external` |
| `init` → `disable_embeds` | `add_action` | `class-core-tweaks.php:45` | Remove oEmbed | `init` | -1000 | `Core_Tweaks` | `private` |
| `embed_oembed_discover` → `__return_false` | `add_filter` | `class-core-tweaks.php:176` | Disable discovery | `embed_oembed_discover` | — | WP | `external` |
| `tiny_mce_plugins` → `disable_embeds_tinymce` | `add_filter` | `class-core-tweaks.php:180` | Strip `wpembed` | TinyMCE | 10 | `Core_Tweaks` | `private` |
| `rewrite_rules_array` → `disable_embeds_rewrites` | `add_filter` | `class-core-tweaks.php:181` | Remove embed rewrite | `rewrite_rules_array` | 10 | `Core_Tweaks` | `private` |
| `wp_enqueue_scripts` → `disable_dashicons` | `add_action` | `class-core-tweaks.php:49` | Dequeue `dashicons` for visitors | `wp_enqueue_scripts` | 10 | `Core_Tweaks` | `private` |
| `xmlrpc_methods` / `xmlrpc_enabled` / `wp_headers` | `add_filter` | `class-core-tweaks.php:54-56` | Block XML-RPC, strip `X-Pingback` | XML-RPC / headers | 10 | `Core_Tweaks` | `private` |
| `init` → `control_heartbeat` | `add_action` | `class-core-tweaks.php:61` | `wp_enqueue_scripts`? Actually gates `heartbeat_settings` | `init` prio 1 | 1 | `Core_Tweaks` | `private` |
| `heartbeat_settings` → `heartbeat_60s` | `add_filter` | `class-core-tweaks.php:264` | Slow heartbeat 60s | Heartbeat | 10 | `Core_Tweaks` | `private` |
| `wp_head` → `remove_rest_api_links` | `add_action` | `class-core-tweaks.php:65` | Remove `wp-json` link | `wp_head` prio 100 | 100 | `Core_Tweaks` | `private` |
| `rest_post_dispatch` → `suppress_rest_header` | `add_filter` | `class-core-tweaks.php:66` | Suppress `X-WP-Total`? Actually removes `Link` header | `rest_post_dispatch` | 10 (3 args) | `Core_Tweaks` | `private` |
| `do_feed*` (6 hooks) → `redirect_feed_to_home` | `add_action` | `class-core-tweaks.php:70-76` | Redirect RSS feeds to home | Feed hooks | 1 | `Core_Tweaks` | `private` |
| `wp_head` → `remove_feed_links` | `add_action` | `class-core-tweaks.php:77` | Remove feed `<link>` | `wp_head` prio 100 | 100 | `Core_Tweaks` | `private` |
| `after_setup_theme` → `remove_shortlink_tag` | `add_filter` | `class-core-tweaks.php:82` | Remove shortlink | `after_setup_theme` | — | `Core_Tweaks` | `private` |
| `the_generator` → `__return_empty_string` | `add_filter` | `class-core-tweaks.php:87` | Hide generator | `the_generator` | — | WP | `external` |
| `wp_default_scripts` → `remove_jquery_migrate` | `add_action` | `class-core-tweaks.php:91` | Deregister `jquery-migrate` | `wp_default_scripts` | 10 | `Core_Tweaks` | `private` |
| `wp_print_scripts` → `remove_password_strength_scripts` | `add_action` | `class-core-tweaks.php:95` | Dequeue `zxcvbn` | `wp_print_scripts` prio 100 | 100 | `Core_Tweaks` | `private` |
| `pre_ping` → `disable_self_pingbacks` | `add_action` | `class-core-tweaks.php:99` | Filter `pre_ping` | `pre_ping` | 10 | `Core_Tweaks` | `private` |

### 14.3 LLMs.txt / OD Bridge / Edge / AI / Perf Translations / Abilities

| Hook | Type | File:Line | Purpose | Fires when | Args | Priority | Consumers | Public? | Docs |
|------|------|-----------|---------|------------|------|----------|-----------|---------|------|
| `init` → `Llms::register_rewrite` | `add_action` | `class-main.php:631` | Add rewrite for `/llms.txt` | `init` | 0 | 10 | `Llms::register_rewrite` (gated `class_exists(Llms)`) | `private` · always | — |
| `query_vars` → `Llms::add_query_vars` | `add_filter` | `class-main.php:632` | Add `llms_txt` query var | `query_vars` | 1 | 10 | `Llms::add_query_vars` | `private` | — |
| `template_redirect` → `Llms::serve` | `add_action` | `class-main.php:633` | Serve `llms.txt` / `llms-full.txt` | `template_redirect` prio 1 | 0 | 1 | `Llms::serve` | `private` | — |
| `send_headers` → `Llms::emit_link_header` | `add_action` | `class-main.php:634` | `Link: <.../llms.txt>; rel="alternate"` header | `send_headers` | 0 | — | `Llms::emit_link_header` | `private` | — |
| `wp_head` → `Llms::emit_head_link` | `add_action` | `class-main.php:635` | `<link rel="alternate" href="llms.txt">` | `wp_head` prio 1 | 0 | 1 | `Llms::emit_head_link` | `private` | — |
| `wppo_llms_txt_enabled` | `apply_filters` | `class-llms.php:46` | Gate LLMs.txt generation | `Llms::is_enabled()` | 1: `bool $enabled` | n/a | None | `public` | ✅ `docs/hooks.md:282` |
| `wppo_llms_txt_content` | `apply_filters` | `class-llms.php:263 ('llms'), 271 ('llms-full')` | Filter generated markdown before `file_put_contents` | `Llms::generate()` | 2: `string $content`, `string $which` | n/a | None | `public` | ✅ `docs/hooks.md:265` |
| `wppo_od_should_optimize` | `apply_filters` | `class-od-bridge.php:100` | Gate OD viewport-group LCP optimization | `Od_Bridge::should_optimize()` | 2: `bool $should`, `string $url` | n/a | None | `public` | ✅ `docs/hooks.md:294` |
| `wppo_telemetry_verify_ssl` | `apply_filters` | `class-telemetry.php:227,453,717,872` | Override `sslverify` for telemetry `wp_remote_get` | Scan / robots fetch | 2: `bool $verify`, `string $url` | n/a | None | `public` (undoc) | — |
| `set_logged_in_cookie` → `Bfcache::on_set_logged_in_cookie` | `add_action` | `class-bfcache.php:379` via `Bfcache::init:379` | Mirror bfcache token cookie | `set_logged_in_cookie` | 6: `... $token` | 10 | `Bfcache::on_set_logged_in_cookie` | `private` (core) | — |
| `clear_auth_cookie` → `Bfcache::on_clear_auth_cookie` | `add_action` | `class-bfcache.php:380` | Clear token cookie | `clear_auth_cookie` | 0 | 10 | `Bfcache::on_clear_auth_cookie` | `private` | — |
| `should_load_separate_core_block_assets` / `should_load_block_assets_on_demand` | `add_filter` | `class-main.php:833,837` via `register_block_assets_filters` | 6.9+ block assets toggle | Filter | 0 | 10 | Core consumes | `external` (core) | — |
| `attach_session_information` / `secure_auth_cookie` / `secure_logged_in_cookie` | `add_filter` | `class-bfcache.php:378,193,194` | bfcache session plumbing | Core session filters | — | — | Bfcache | `external` (core) | — |

---

## 15. Missing / Undocumented Extension Points & Recommendations (not enacted)

| Gap | Current | Suggested hook (public) | Rationale |
|-----|---------|-------------------------|-----------|
| Per-type DB cleanup event | `wppo_database_cleanup_completed` only fires for `type=all` at `class-database-cleanup.php:737` | `wppo_database_cleanup_type_completed` (`$type, $count`) per cleaner + `wppo_before_database_cleanup` | Consumers can't observe single-type CLI/REST cleanups; e.g. metrics exporter |
| Cache write veto | Only `wppo_cache_page_html` to mutate HTML | `wppo_should_cache_page` (`bool $allow, string $url, int $post_id`) before `save_processed_buffer` | Cleaner than `DONOTCACHEPAGE` constant + drop-in marker file |
| Buffer pipeline stages | Monolithic `process_buffer_only` | `wppo_buffer_before_minify` / `after_minify` / `before_cdn` filters | Insertion point for custom transforms without copying whole buffer method |
| Cache observability | No hit/miss action | `wppo_cache_hit` / `wppo_cache_miss` (`$url`) in `Cache` + `advanced-cache.php` drop-in | Without it, APM can't measure cache efficacy |
| Filesize / dimension gates | `wppo_filesize_limit_bytes` + dims apply at `class-img-converter.php:402,422` exist but undoc | Document in `docs/hooks.md` | Already public but hidden |
| Convert gain-map / mime gates | `wppo_convert_gain_map_images`, convertible mimes at `1561,1623` | Document | Same |
| Preload sitemap cap / batch | Hard `500` cap in `Cron::get_sitemap_urls:487`, `200` posts/batch in `schedule_page_cron_jobs:302` | `wppo_sitemap_preload_limit` / `wppo_preload_batch_size` filters | Large sites (10k posts) need tuning |
| Cron discovery limit vs image limit | `wppo_cron_discovery_limit` (50) exists at `class-cron.php:666` | Document (already public but missing from hooks.md) | — |
| `wppo_server_timing_enabled` | `apply_filters` at `class-main.php:1252` undoc | Add to hooks.md | Already public |
| CLI allowlist gap | `get_redis_config_from_assoc:864` 6-key allowlist vs REST 10-key | Add `wppo_cli_redis_config` filter or broaden allowlist | Cluster/sentinel not configurable via CLI |
| `wppo_inline_combined_css` docs | Only in AGENTS.md comment, not hooks.md filter section | Add to hooks.md (already there? Actually hooks.md lists only `wppo_before/after_cache_clear` etc.; `wppo_inline_combined_css` missing) | Document |
| `wppo_object_cache_dropin_path` / `wppo_redis_allow_request_password` / `wppo_varnish_purge_max_urls` / `wppo_telemetry_verify_ssl` | Undocumented `apply_filters` at `class-object-cache.php:67`, `class-rest.php:1130`, `class-cdn-purger.php:193`, `class-telemetry.php:227` | Add to hooks.md or mark private | Decide visibility |

**Documentation drift:** `docs/hooks.md` documents 42 `wppo_*` hooks (good) but omits `wppo_filesize_limit_bytes`, `wppo_cron_discovery_limit`, `wppo_server_timing_enabled`, `wppo_object_cache_dropin_path`, `wppo_redis_allow_request_password`, `wppo_varnish_purge_max_urls`, `wppo_telemetry_verify_ssl`, `wppo_debug_log`, `wppo_convert_gain_map_images`, `wppo_skip_combine_on_small_block_theme` (NEXT), and the `wppo_ccss_*`, `wppo_video_*` families. Recommend either documenting or explicitly marking private with `/** @internal */`.

---

## 16. Lifecycle & Loading Order

```
plugins_loaded → Main::__construct (load settings) → Main::includes() (CLI gate) → Main::setup_hooks() registers ~90 add_action/add_filter
init (0) → Cron::schedule_cron_jobs schedules wp-cron; Bfcache/Llms/Perf_Translations/AI_Adaptive inits run
admin_init (0) → maybe_fix_wp_cache / maybe_run_upgrades / maybe_run_version_upgrade / maybe_migrate_block_assets_setting
wp_enqueue_scripts (5 → 999 → PHP_INT_MAX) → RUM (5) → enqueue_scripts (10) → apply_module_loading_strategies (10000) → minify_queued_styles (PHP_INT_MAX-1) → combine_css (PHP_INT_MAX)
wp_head (0) → add_speculation_rules (0) → add_preload_prefetch_preconnect (1) → maybe_preload_combine_css (1)
template_redirect (0 → 20) → capture_template_start (0, server-timing) → start_output_buffer / start_used_css_buffer / start_lcp_priority_buffer
wp_template_enhancement_output_buffer (10/20/30) + wp_finalized_template_enhancement_output_buffer (0) → stash_cache / emit_server_timing
shutdown → Img_Converter::commit_img_info (deferred wppo_img_info) + RUM::flush_shutdown_buffer (conditional)
```

- **Always-loaded:** `admin_menu`, `init:set_role_hash_cookie`, `rest_api_init`, `wp_head:preload/speculation`, `wppo_after_cache_clear` consumers, Cron `init` scheduling.
- **Conditional:** `combine_css` / `maybe_preload_combine_css` only when `combineCSS`; `minify_js/css` only when `minifyJS/CSS`; `defer/delay` filters only when `deferJS/delayJS` (with version gates `is_wp63_plus`/`is_wp69_plus`); `Critical_CSS` only when `criticalCSS`; `RUM` enqueue only when `rum_enabled`; `Llms`/`Bfcache`/`Perf_Translations`/`AI_Adaptive`/`Edge_Cache` all gated on class_exists + enabled flag/filter.
- **Early:** `init`, `admin_init`, `cron_schedules`, `attach_session_information` fire before query; **Late:** `wp_enqueue_scripts`/`wp_head`/`wp_footer`/`template_redirect`/buffer filters fire after query and asset queue.

---

## 17. File:Line Index (prod, excl. vendor/tests, sorted)

| Hook string | File:Line |
|-------------|-----------|
| `add_action('admin_menu', ...)` | `class-main.php:486` |
| `add_action('admin_init', maybe_fix_wp_cache)` | `class-main.php:487` |
| `add_action('admin_init', maybe_run_upgrades)` | `class-main.php:488` |
| `add_action('wppo_run_upgrades', ...)` | `class-main.php:489` |
| `add_action('upgrader_process_complete', maybe_schedule_upgrade_routine)` | `class-main.php:490` |
| `add_action('admin_init', maybe_run_version_upgrade)` | `class-main.php:491` |
| `add_action('upgrader_process_complete', maybe_run_version_upgrade)` | `class-main.php:492` |
| `add_action('admin_init', maybe_migrate_block_assets_setting)` | `class-main.php:493` |
| `add_action('admin_enqueue_scripts', admin_enqueue_scripts)` | `class-main.php:494` |
| `add_action('init', set_role_hash_cookie)` | `class-main.php:495` |
| `add_action('wp_logout', clear_role_hash_cookie)` | `class-main.php:496` |
| `add_action('wp_enqueue_scripts', enqueue_scripts)` | `class-main.php:497` |
| `add_action('wp_enqueue_scripts', apply_module_loading_strategies) prio 10000` | `class-main.php:498` |
| `add_filter('script_loader_tag', add_defer_attribute)` | `class-main.php:515` |
| `add_action('wp', apply_per_page_delay_config)` | `class-main.php:516` |
| `add_action('wp_enqueue_scripts', add_defer_strategy) prio 1000` | `class-main.php:523` |
| `add_filter('script_loader_tag', add_defer_attribute_legacy)` | `class-main.php:525` |
| `add_filter('script_loader_tag', add_fetchpriority_to_deferred) prio 11` | `class-main.php:530` |
| `add_action('admin_bar_menu', add_setting_to_admin_bar) prio 100` | `class-main.php:533` |
| `add_action('wp_enqueue_scripts', remove_woocommerce_scripts) prio 999` | `class-main.php:536` |
| `add_filter('wp_template_enhancement_output_buffer', process_buffer_for_cache) prio 10` | `class-main.php:545` |
| `add_action('wp_finalized_template_enhancement_output_buffer', stash_cache)` | `class-main.php:546` |
| `add_action('template_redirect', start_output_buffer)` | `class-main.php:550` |
| `add_action('save_post', on_save_post_invalidate_cache)` | `class-main.php:552` |
| `add_action('template_redirect', capture_template_start) prio 0` | `class-main.php:560` |
| `add_action('wp_finalized_template_enhancement_output_buffer', emit_server_timing_header) prio 0` | `class-main.php:561` |
| `add_filter('wp_template_enhancement_output_buffer', process_used_css_only) prio 20` | `class-main.php:568` |
| `add_action('template_redirect', start_used_css_buffer)` | `class-main.php:572` |
| `add_filter('wp_template_enhancement_output_buffer', prioritize_lcp_in_buffer) prio 30` | `class-main.php:584` |
| `add_action('template_redirect', start_lcp_priority_buffer) prio 20` | `class-main.php:590` |
| `add_action('save_post', Database_Cleanup::on_post_change)` | `class-main.php:596` |
| `add_action('deleted_post', Database_Cleanup::on_post_change)` | `class-main.php:597` |
| `add_action('wp_enqueue_scripts', combine_css) prio PHP_INT_MAX` | `class-main.php:608` |
| `add_action('wp_head', maybe_preload_combine_css) prio 1` | `class-main.php:611` |
| `add_action('rest_api_init', Rest::register_routes)` | `class-main.php:615` |
| `add_action('wp_enqueue_scripts', RUM::maybe_enqueue_scripts) prio 5` | `class-main.php:619` |
| `add_action('wp_footer', RUM::print_config) prio 90` | `class-main.php:620` |
| `add_action('wppo_after_cache_clear', CDN_Purger::purge_all)` | `class-main.php:623` |
| `add_action('wppo_after_cache_clear', Edge_Purger::purge_all) prio 20` | `class-main.php:626` |
| `add_action('init', Llms::register_rewrite)` | `class-main.php:631` |
| `add_filter('query_vars', Llms::add_query_vars)` | `class-main.php:632` |
| `add_action('template_redirect', Llms::serve) prio 1` | `class-main.php:633` |
| `add_action('send_headers', Llms::emit_link_header)` | `class-main.php:634` |
| `add_action('wp_head', Llms::emit_head_link) prio 1` | `class-main.php:635` |
| `add_action('update_option_wppo_settings', Llms::on_settings_update)` | `class-main.php:637` |
| `add_filter('script_loader_tag', minify_js)` | `class-main.php:662` |
| `add_action('wp_enqueue_scripts', minify_queued_styles) prio PHP_INT_MAX-1` | `class-main.php:676` |
| `add_filter('style_loader_tag', minify_css)` | `class-main.php:679` |
| `add_filter('script_loader_src', strip_static_query_strings)` | `class-main.php:683` |
| `add_filter('style_loader_src', strip_static_query_strings)` | `class-main.php:684` |
| `add_filter('style_loader_tag', Google_Fonts::process_style_tag) prio 9` | `class-main.php:688` |
| `apply_filters('wppo_exclude_defer_js', ...)` | `class-main.php:701` |
| `apply_filters('wppo_exclude_delay_js', ...)` | `class-main.php:722` |
| `add_action('wp_head', add_preload_prefetch_preconnect) prio 1` | `class-main.php:758` |
| `add_action('wp_head', add_speculation_rules) prio 0` | `class-main.php:759` |
| `add_filter('wp_resource_hints', add_resource_hints)` | `class-main.php:760` |
| `add_action('wp_head', Critical_CSS::inline_ccss) prio 0` | `class-main.php:769` |
| `add_filter('style_loader_tag', Critical_CSS::defer_stylesheets)` | `class-main.php:770` |
| `add_action('wppo_generate_ccss', Critical_CSS::background_generate)` | `class-main.php:771` |
| `add_action('wppo_convert_image_background', process_background_image)` | `class-main.php:775` |
| `add_action('wppo_pagespeed_scan', Pagespeed::run_scan)` | `class-main.php:778` |
| `add_action('wppo_used_css_generate', Used_CSS::process_background)` | `class-main.php:781` |
| `add_action('save_post', on_save_post_queue_used_css)` | `class-main.php:784` |
| `add_action('update_option_permalink_structure', clear_all_cache)` | `class-main.php:787` |
| `add_action('switch_theme', clear_all_cache)` | `class-main.php:788` |
| `add_action('update_option_wppo_settings', on_settings_update)` | `class-main.php:789` |
| `add_action('activated_plugin', clear_all_cache)` | `class-main.php:790` |
| `add_action('deactivated_plugin', clear_all_cache)` | `class-main.php:791` |
| `add_action('wp_ajax_wppo_get_nonce', Rest::ajax_get_nonce)` | `class-main.php:793` |
| `add_filter('should_load_separate_core_block_assets', __return_false)` | `class-main.php:833` |
| `add_filter('should_load_block_assets_on_demand', __return_true)` | `class-main.php:837` |
| `apply_filters('wppo_server_timing_enabled', ...)` | `class-main.php:1252` |
| `apply_filters('wppo_exclude_minification', ... css)` | `class-main.php:2747` |
| `apply_filters('wppo_exclude_minification', ... js)` | `class-main.php:2849` |
| `register_activation_hook` | `performance-optimisation.php:57` |
| `register_deactivation_hook` | `performance-optimisation.php:70` |
| `apply_filters('wppo_object_cache_dropin_path', ...)` | `class-object-cache.php:67` |
| `apply_filters('wppo_od_should_optimize', ...)` | `class-od-bridge.php:100` |
| `apply_filters('wppo_perf_translations_enabled', ...)` | `class-perf-translations.php:63` |
| `do_action('wppo_perf_translations_file_written', ...)` | `class-perf-translations.php:199` |
| `add_filter('load_translation_file', filter_load_file)` | `class-perf-translations.php:229` |
| `add_filter('load_textdomain_mofile', filter_load_file)` | `class-perf-translations.php:230` |
| `add_action('wppo_perf_translations_file_written', opcache_invalidate)` | `class-perf-translations.php:231` |
| `add_action('upgrader_process_complete', on_upgrader_complete)` | `class-perf-translations.php:233` |
| `apply_filters('wppo_redis_allow_request_password', ...)` | `class-rest.php:1130` |
| `add_action('shutdown', RUM::flush_shutdown_buffer)` | `class-rum.php:352` |
| `apply_filters('wppo_litespeed_nextgen_rewrite', ...)` | `class-server-rules.php:133` |
| `apply_filters('wppo_nginx_nextgen_rules', ...)` | `class-server-rules.php:162` |
| `apply_filters('wppo_nginx_rules', ...)` | `class-server-rules.php:173` |
| `apply_filters('wppo_telemetry_verify_ssl', ...)` | `class-telemetry.php:227,453,717,872` |
| `add_action('update_option_wppo_settings', Util::on_settings_update)` | `class-util.php:245` |
| `add_action('add_option_wppo_settings', Util::on_settings_add)` | `class-util.php:246` |
| `add_action('delete_option_wppo_settings', Util::clear_settings_cache)` | `class-util.php:247` |
| `add_action('switch_blog', Util::on_switch_blog)` | `class-util.php:248` |
| `do_action('wppo_debug_log', ...)` | `minify/class-html.php:238` |
| `apply_filters('object_cache_allow_flush_all', ...)` | `templates/object-cache.php:532` |
| *(Cron, Bfcache, Edge, AI, Admin Notices, Metabox, Abilities, Core Tweaks, LiteSpeed, etc. — see per-section tables for remaining ~180 lines)* | `class-cron.php:57-74`, `class-bfcache.php:85,378-384`, `class-edge-cache.php:85,143,203,240,284`, `class-ai-adaptive.php:60,315,465-476`, `class-admin-notices.php:44-45`, `class-metabox.php:39,41`, `class-abilities.php:35-36`, `class-core-tweaks.php:37-117`, `class-litespeed-integration.php:221-1281`, `class-cache.php:282,402,980,1047,1143,1349,1353,1661,1706,1763,1774,2032,2074`, `class-database-cleanup.php:737`, `class-image-optimisation.php:185-2769`, `class-img-converter.php:360-1750`, `class-critical-css.php:569,605`, `class-htaccess-handler.php:174,208,217` |

*Full 272-line grep dump: `grep -rn "add_action|add_filter|do_action|apply_filters" --include="*.php" --exclude-dir=vendor --exclude-dir=tests` (see `CLASS-CRON` etc. tables for verbatim lines).*

---

## 18. Verification Checklist

- [x] `grep` across `includes/*.php`, `templates/*.php`, `performance-optimisation.php`, `uninstall.php` (272 prod hits)
- [x] `class-main.php:485-799` `setup_hooks()` read fully
- [x] `docs/hooks.md` cross-checked (42 public `wppo_*` vs 78 `apply_filters` firing sites — drift noted)
- [x] Each hook: Hook name, Type, File:Line, Purpose, Fires when, Args, Priority, Consumers (grep same hook), Public/Private, Docs
- [x] Categories covered: optimization decisions, cache gen/inval, asset CSS/JS, image lazy, HTML minify, DB cleanup, RUM, object cache, settings, REST, CLI, cron, admin, frontend, compatibility (LiteSpeed, core tweaks)
- [x] Early/late, always-loaded vs conditional, missing extension points noted
- [x] No production code modified

---

## 19. Core Hook Lifecycle Precision — Cross-reference

> Full 33-verdict lifecycle precision audit comparing current hooks vs WP core docs (`template_redirect`, `wp`, `wp_enqueue_scripts`, `shutdown`, `send_headers`, `admin_init`/`admin_menu`/`current_screen`, `rest_api_init`, `init`/`wp_loaded`/`shutdown`, `pre_get_posts`, `script_loader_tag`/`style_loader_tag`, `advanced-cache` drop-in, `pre_http_request`, `wp_generate_attachment_metadata`/`image_editor`) → [`HOOK-CORE-RESEARCH.md`](./HOOK-CORE-RESEARCH.md) + roll-up [`ECOSYSTEM-RESEARCH.md §3`](./ECOSYSTEM-RESEARCH.md#3-wp-core-hook-lifecycle-precision-audit--is-the-plugin-on-the-right-lifecycle).

**Bottom line:** Plugin is already on the correct lifecycle for all 9 categories. The 6.9+ `wp_template_enhancement_output_buffer` (filter `class-main.php:545` + action `class-main.php:546`) dual-path with legacy `template_redirect` (`class-main.php:550`) gated `is_wp69_plus:510` (`TODO #553`) is precisely the intended migration; `init` for cron/rewrite/role-cookie, `wp` for delay (`class-main.php:516`), `shutdown` for deferred `wppo_img_info` (`class-img-converter.php:1750`), `rest_api_init` for routes (`class-main.php:615`), `advanced-cache.php` zero-hook by design (`class-advanced-cache-handler.php:128`), and version-gated `strategy`/`fetchpriority` (`class-main.php:523-525,530`) are all correct. No hook moves recommended — only doc/`args` hygiene (see `HOOK-CORE-RESEARCH.md §10`).

*Research-only. For implementation plan see `docs/research/wp-cli-hooks/WP-CLI-CURRENT.md` and `docs/litespeed-integration-plan.md`.*
