# Hook Proposed Design — Evidence-Based Filter/Action Specs

**Date:** 2026-08-28 · **Plugin:** `performance-optimisation` v1.9.0  
**Source gaps:** `HOOK-GAPS.md` (32 gaps G-01—G-32, 17 categories) → filtered by `ADVERSARIAL-REVIEW.md §2` survivors (`RETAIN`/`MODIFY`/`REJECT`)  
**Method:** Read-only. Every hook specifies exact `file:line` location with `Before/During/After` stage, WordPress lifecycle, priority, accepted args, type, purpose, example consumer, perf/compat impact, `@since NEXT`. No production edits. Every claim cites `file:line`.

> Pre-read: `HOOK-AUDIT.md` (272 hits, 78 `apply_filters` + 22 `do_action` fire sites), `HOOK-GAPS.md` §2 detailed specs, `ADVERSARIAL-REVIEW.md §2` verdict table, `MIGRATION-COMPATIBILITY.md §4.11` `@since NEXT` policy, `docs/hooks.md` (42 public `wppo_*`), `PERF-RESEARCH.md §2.2` hot-path cost model.

---

## 0. Survivor Summary (Adversarial Gate)

| Verdict | Gaps |
|---------|------|
| **RETAIN** | G-01, G-03, G-07, G-11 (veto), G-13 (predicate), G-15, G-17, G-28 |
| **MODIFY (narrowed)** | G-02 (retain `written`+`miss`, reject `hit` drop-in), G-05 (retain `batch_size`+`sitemap_limit`+`sitemap_urls`, reject `deadline`+`should_preload_page`), G-06 docs-only, G-09 (keep `delay` only, reject `defer`), G-12 (retain `should_serve`, reject `image_url` mutate), G-16 (retain `batch_size`+`revision_defaults`, reject `optimize_max`), G-19 (retain `object_cache_config`+`cli_redis_config`, reject lifecycle actions), G-20 (retain `settings_before_update` veto, reject `sanitize`+`after`), G-22 (retain `cli_redis_config`, reject `should_run`+`after_command`), G-27 (retain `purge_urls`, reject duplicate `should_purge`) |
| **REJECT (defer/docs-only)** | G-04 (6× `buffer_stage`), G-06 per-handle combine (docs-only), G-08 minify granular, G-10 placeholder/LQIP, G-14 HTML minify, G-18 RUM retention bounds, G-21 REST routes, G-23 cron schedules (use core `cron_schedules`), G-24 admin localize, G-25 preload/speculation/hints (use core `wp_resource_hints`/`wp_speculation_rules`), G-26 LiteSpeed alias, G-29 block assets (use core `should_load_separate_core_block_assets`), G-30 HTML processor, G-31 core tweaks, G-32 observability |

Wiring note: `HOOK-GAPS.md §0` priorities are inputs; adversarial refines perf cost (`none` cold/cron/admin vs `low` per-request tag). All new hooks default `true`/`array` so `apply_filters(..., $default)` preserves behavior when unhooked.

---

## 1. Retained Design (P0/P1 Survivors — 14 hooks, 10 gaps)

### G-01 — `wppo_should_cache_request` (P0 — blocking veto)

| Field | Spec |
|-------|------|
| **Name** | `wppo_should_cache_request` |
| **Type** | filter |
| **Location** | `includes/class-cache.php:1505` `is_not_cacheable()` **entry** (before `DONOTCACHEPAGE` / query-string / Woo-cart checks at `class-cache.php:1482-1520`) **AND** `includes/class-cache.php:1755` `maybe_store_cache()` **entry** (covers both paths: `start_output_buffer` legacy `class-main.php:550` + `stash_cache` 6.9+ `class-main.php:546`) — single filter inserted at both gates so drop-in + buffer agree |
| **Stage** | Before cache write (veto) |
| **Priority / Accepted args** | priority default 10, accepted_args 4: `bool $should_cache`, `string $request_uri` (`$_SERVER['REQUEST_URI']` sanitized), `string $url_path` (normalized from `Util::cached_home_url`), `string $domain` |
| **Purpose** | Per-URL veto without defining `DONOTCACHEPAGE` too early (consent/geo/membership must decide late) and without forking `is_not_cacheable()`; `wppo_cache_page_html` at `:1661` mutates HTML but does not prevent `save_cache_files` FS write + `wppo_preload_cron_lock` stampede at `class-cron.php:288` |
| **Example consumer** | `add_filter('wppo_should_cache_request','paid_membership_pro_veto',10,4); function paid_membership_pro_veto($c,$uri,$path,$dom){ if (function_exists('pmpro_hasMembershipLevel') && pmpro_hasMembershipLevel()) return false; if (str_contains($uri,'?ab_variant=')) return false; return $c; }` |
| **Perf/Compat** | `none` — one `apply_filters` on cacheable front-end only; skipped 404/CLI/admin; default `true` preserves behavior; `false` short-circuits before `save_processed_buffer:1741` lock |
| **@since** | `NEXT` (per `AGENTS.md:184-185` never invent `2.0.0`) — docs `docs/hooks.md` entry with `@param bool $should_cache` |
| **Citations** | `HOOK-GAPS.md G-01`, `HOOK-AUDIT.md §2.1` cache generation, `ADVERSARIAL G-01 RETAIN`, `class-cache.php:1505,1755`, `class-main.php:545-546` dual buffer |

### G-28 — `wppo_should_optimise_for_user` (P0 — per-user gate, companion to G-01)

| Field | Spec |
|-------|------|
| **Name** | `wppo_should_optimise_for_user` |
| **Type** | filter |
| **Location** | `includes/class-main.php:369` `should_optimise_for_logged_in()` **after** `Util::is_cache_eligible_for_current_user()` resolve at `class-main.php:369` **AND** `includes/class-cache.php:297` `is_cache_allowed_for_current_user()` after `cache_settings.enableLoggedInCache` + `loggedInCacheRoles` checks (`:297-301` `Util::get_role_hash` :400-426) — same predicate at two call sites sharing one filter name |
| **Stage** | Before `set_role_hash_cookie` at `class-main.php:495` + before buffer (`wp_template_enhancement_output_buffer:10 :545`) |
| **Priority / Args** | 10, 3: `bool $should_optimise`, `int $user_id` (`get_current_user_id()`), `string[] $roles` |
| **Purpose** | Per-role veto (`shop_manager` sees uncached cart fragments, `subscriber` cached) beyond global `enableLoggedInCache` boolean at `class-cache.php:297-301` |
| **Example** | `add_filter('wppo_should_optimise_for_user', fn($s,$uid,$roles)=> !in_array('shop_manager',$roles,true) ? $s : false,10,3);` |
| **Perf/Compat** | `none` — front-end only, default is `Util::is_cache_eligible_for_current_user()` result so no change when unhooked; keeps `set_role_hash_cookie` correct |
| **@since** | `NEXT` |
| **Citations** | `HOOK-GAPS G-28`, `ADVERSARIAL G-28 RETAIN`, `class-main.php:369,495`, `class-cache.php:297`, `HOOK-GAPS table P0 row G-28` |

### G-02 — Cache observability `wppo_cache_written` / `wppo_cache_miss` (P1 — APM/Dashboard)

| Field | Spec |
|-------|------|
| **Names** | `wppo_cache_written` (action), `wppo_cache_miss` (action) — **REJECT** third `wppo_cache_hit` in `templates/advanced-cache.php` drop-in per adversarial |
| **Type** | actions |
| **Location** | `wppo_cache_written` **After** `save_processed_buffer` success at `includes/class-cache.php:1741` (after `save_cache_files` writes `index.html` + `index.html.gz`/`.br` and before `do_action('wppo_after_cache_clear')` at `:2074` chain) — args: `string $url` (`Util::cached_home_url($request_uri)`), `string $file_path` (`WP_CONTENT_DIR . '/cache/wppo/{domain}/{path}/index.html'` at `class-cache.php:40-41`), `float $generation_ms` (`microtime(true)` diff from capture at `class-main.php:560`) <br> `wppo_cache_miss` **During** `save_processed_buffer` early return when `maybe_store_cache()` false at `includes/class-cache.php:1661` — args: `string $url`, `string $reason` enum (`donotcachepage|query_string|woo_cart|is_404|litespeed_bypass|wppo_should_cache_request|is_cache_allowed_for_current_user`) |
| **Stage** | After write / on miss |
| **Priority / Args** | actions, priority 10, `wppo_cache_written` 3 args, `wppo_cache_miss` 2 args |
| **Purpose** | Hit-rate without patching `Cache`; `wppo_before/after_cache_clear` at `:2032,2074` only covers invalidation (HOOK-AUDIT §2.1) |
| **Example** | `add_action('wppo_cache_written','new_relic_wppo_metric',10,3); function new_relic_wppo_metric($url,$path,$ms){ if(function_exists('newrelic_record_custom_event')) newrelic_record_custom_event('WPPO_Cache', ['url'=>$url,'ms'=>$ms]); }` + `add_action('wppo_cache_miss','prom_wppo_miss',10,2);` to increment Prometheus |
| **Perf/Compat** | `none` — fires once per HTML generation (not per asset); additive |
| **Reject rationale (`wppo_cache_hit`)** | Drop-in `templates/advanced-cache.php` serves `index.html` before `plugins_loaded` — `do_action('wppo_cache_hit')` would require booting `wp-load.php` or writing a no-DB log file, breaking zero-boot promise (`PERF-RESEARCH.md §2.2` advanced-cache row). Measure hit via `X-WPPO-Cache: HIT` header + `access.log` instead (docs-only) |
| **@since** | `NEXT` |
| **Citations** | `HOOK-GAPS G-02`, `ADVERSARIAL G-02 MODIFY`, `class-cache.php:1661,1741`, `templates/advanced-cache.php`, `HOOK-AUDIT §2.1` |

### G-03 — Cache invalidation `wppo_invalidation_urls` + `wppo_cache_invalidated` + `wppo_should_invalidate_on_save` (P1)

| Field | Spec |
|-------|------|
| **Names** | `wppo_invalidation_urls` (filter) + `wppo_cache_invalidated` (action per-file) + `wppo_should_invalidate_on_save` (filter) |
| **Type** | filter / action / filter |
| **Location** | `wppo_invalidation_urls` **During** `invalidate_dynamic_static_html()` at `includes/class-cache.php:1838` after `$post_type` resolve, before `delete_cache_files` — param `array $urls` (relative paths) validated downstream via `Cache::clear_cache` `..` + `realpath` gate at `class-rest.php:413-432` pattern — filter must `wp_normalize_path` + not contain `..` <br> `wppo_cache_invalidated` **After** each `unlink` at `class-cache.php:1838` loop — `string $type` (`single|home|archive|term`), `string $url_path`, `int $post_id` <br> `wppo_should_invalidate_on_save` **Before** `on_save_post_invalidate_cache` at `includes/class-main.php:552` — `bool $should`, `int $post_id`, `WP_Post $post` |
| **Priority / Args** | 10, `wppo_invalidation_urls` 3 args `(array $urls, int $post_id, string $post_type)`, `wppo_cache_invalidated` 3 args `(string $type, string $url_path, int $post_id)`, `wppo_should_invalidate_on_save` 3 args |
| **Purpose** | Headless/Woo/ACF need related URLs (`/store-locator/{city}` CPT, related products) without `clear all`; multilingual (WPML) purge translated post; veto per-post without clearing unrelated |
| **Example** | `add_filter('wppo_invalidation_urls','acf_related_purge',10,3); function acf_related_purge($urls,$post_id,$pt){ if($pt==='location') $urls[]='/store-locator/city/'.get_field('city',$post_id).'/'; return array_map('wp_normalize_path', $urls); }` |
| **Perf/Compat** | `low` — `save_post` only (not front-end); URL list <20; filter sanitizes `..` to avoid traversal |
| **@since** | `NEXT` |
| **Citations** | `HOOK-GAPS G-03`, `ADVERSARIAL G-03 RETAIN`, `class-cache.php:1838`, `class-main.php:552`, `MIGRATION-COMPATIBILITY.md C-FS-09` traversal guard |

### G-05 — Preload tuning `wppo_preload_batch_size` / `wppo_sitemap_preload_limit` / `wppo_preload_sitemap_urls` (P1/P2 — large-site tuning)

| Field | Spec |
|-------|------|
| **Names** | `wppo_preload_batch_size` (filter) + `wppo_sitemap_preload_limit` (filter) + `wppo_preload_sitemap_urls` (filter) — **REJECT** `wppo_sitemap_deadline_seconds` (15s) + `wppo_should_preload_page` per-page veto |
| **Type** | filters |
| **Location** | `wppo_preload_batch_size` **During** `schedule_page_cron_jobs()` at `includes/class-cron.php:301` `posts_per_page` literal `200` → `apply_filters('wppo_preload_batch_size', 200)` <br> `wppo_sitemap_preload_limit` **During** `schedule_sitemap_url_jobs(500)` at `class-cron.php:336-364` `apply_filters('wppo_sitemap_preload_limit', 500)` <br> `wppo_preload_sitemap_urls` **After** `get_sitemap_urls()` return at `class-cron.php:487` (`apply_filters('wppo_preload_sitemap_urls', $urls, $cap)`) where `$urls` is `string[]` absolute URLs already host-filtered |
| **Stage** | Cron only (5-hourly) — no front-end cost |
| **Priority / Args** | 10, `wppo_preload_batch_size` 1 arg `int $size`, `wppo_sitemap_preload_limit` 1 arg `int $cap`, `wppo_preload_sitemap_urls` 2 args `(array $urls, int $cap)` |
| **Purpose** | 10k+ posts need larger batch (200→500), VPS needs smaller; headless custom `sitemap-custom.xml` needs URL injection; exclude `/private/*` beyond `excludePreloadCache` at `class-cron.php:271-280` |
| **Example** | `add_filter('wppo_sitemap_preload_limit', fn()=>1000); add_filter('wppo_preload_sitemap_urls', fn($urls)=> array_filter($urls, fn($u)=> !str_contains($u,'/private/')),10,2);` |
| **Reject rationale** | `wppo_sitemap_deadline_seconds` 15s wall-clock at `:496` + `50` child sitemaps not worth tuning; per-page veto `wppo_should_preload_page` at `:283` duplicates UI `excludePreloadCache` + `excludePreloadCacheUrls` already at `class-cron.php:271` — one `batch+limit+urls` triple covers 90% |
| **Perf/Compat** | `none` — cron only (5-hourly); increasing cap increases `wppo_generate_static_url` single events at `:364` with jitter `0-1800` `:337` — documented |
| **@since** | `NEXT` |
| **Citations** | `HOOK-GAPS G-05`, `ADVERSARIAL G-05 RETAIN (MODIFY)`, `class-cron.php:283,301,364,487,496`, `PERF-RESEARCH.md §2.2` preload batch |

### G-07 — `wppo_cdn_url` + `wppo_cdn_should_rewrite` per-asset (P1 — dual CDN)

| Field | Spec |
|-------|------|
| **Names** | `wppo_cdn_url` (filter, mutate base) + `wppo_cdn_should_rewrite` (filter, veto) |
| **Type** | filters |
| **Location** | `wppo_cdn_url` **Before** `$cdn_url` resolve at `includes/class-cache.php:1357` `maybe_apply_cdn()` entry, after `can_apply_cdn` gate at `:1349` (`apply_filters('wppo_litespeed_can_cdn')` `HOOK-AUDIT §3`), before `TagProcessor` loop <br> `wppo_cdn_should_rewrite` **During** `while(next_tag)` per-attribute loop at `includes/class-cache.php:1382` (inside `TagProcessor` per `src`/`href`/`poster`) |
| **Stage** | During `process_buffer_only` buffer pass (front-end HTML generation only) |
| **Priority / Args** | 10, `wppo_cdn_url` 3 args `(string $cdn_url, string $original_url, string $tag_name)` (`img|script|link|source|video`), `wppo_cdn_should_rewrite` 3 args `(bool $should, string $url, string $tag_name)` |
| **Purpose** | Dual-CDN (images→image CDN e.g. Cloudinary, assets→asset CDN e.g. Bunny) + veto `/wp-content/uploads/private/*`; global `cdnURL` setting alone cannot do split |
| **Example** | `add_filter('wppo_cdn_url', fn($cdn,$orig,$tag)=> str_contains($orig,'/uploads/') ? 'https://img.example.net' : $cdn,10,3); add_filter('wppo_cdn_should_rewrite', fn($s,$url)=> !str_contains($url,'/private/'),10,3);` |
| **Perf/Compat** | `low` — per asset in buffer pass; `has_filter` short-circuit (`PERF-RESEARCH.md §2.2` low <0.1ms); default preserves global CDN |
| **@since** | `NEXT` |
| **Citations** | `HOOK-GAPS G-07`, `ADVERSARIAL G-07 RETAIN`, `class-cache.php:1349,1357,1382`, `class-cache.php:1331-1414` `maybe_apply_cdn` 5-way gate |

### G-09 — `wppo_delay_should_delay` per-handle (P2 — Woo checkout etc.)

| Field | Spec |
|-------|------|
| **Name** | `wppo_delay_should_delay` — **REJECT** companion `wppo_defer_should_defer` |
| **Type** | filter |
| **Location** | `includes/class-main.php:722` `exclude_delay_js` resolution loop (inside per-tag `str_contains` check at `class-main.php:515` `add_defer_attribute` + `:722` array build) — insert runtime filter inside the `script_loader_tag:10 :515` per-tag loop |
| **Stage** | During `script_loader_tag` per script tag (front-end, when `delayJS` ON) |
| **Priority / Args** | 10, 4: `bool $should_delay`, `string $handle`, `string $src`, `string $strategy` (`interaction|idle|viewport`) |
| **Purpose** | Array filters `wppo_exclude_delay_js:722` + `wppo_exclude_defer_js:701` applied once in `setup_hooks()` cannot express strategy-specific veto (`checkout.js` never delayed, `analytics.js` idle 5000ms at `class-main.php:72-104` `delay_js_*`) |
| **Example** | `add_filter('wppo_delay_should_delay', fn($s,$h,$src,$strat)=> $h==='wc-checkout' ? false : $s,10,4);` |
| **Reject `defer`** | `wppo_defer_should_defer` duplicates native `wp_script_add_data(strategy)` at `class-main.php:523` (WP 6.3+ `is_wp63_plus:501` gate `HOOK-CORE-RESEARCH.md §0.13`) — defer already via core strategy |
| **Perf/Compat** | `low` — per script tag when delay enabled; default `true` after exclusion array; `false` keeps native strategy |
| **@since** | `NEXT` |
| **Citations** | `HOOK-GAPS G-09`, `ADVERSARIAL G-09 RETAIN (MODIFY)`, `class-main.php:515,701,722`, `class-main.php:72-104` `delay_js_*` |

### G-11 — `wppo_should_convert_image` + `wppo_conversion_quality` + `wppo_image_converted` (P1/P2 — photography)

| Field | Spec |
|-------|------|
| **Names** | `wppo_should_convert_image` (filter, veto) + `wppo_conversion_quality` (filter, quality 1-100) + `wppo_image_converted` (action, observability) |
| **Type** | filter + filter + action |
| **Location** | `wppo_should_convert_image` **During** `convert_image()` entry at `includes/class-img-converter.php:319` before `resolve_output_format` at `:360` `apply_filters('wppo_convert_gain_map_images')` <br> `wppo_conversion_quality` **During** quality resolve at `includes/class-img-converter.php:377` (`resolve_encode_quality()` inside `convert_image` falling back `wp_get_image_encode_quality`/`wp_image_quality` →82) <br> `wppo_image_converted` **After** `convert_image` success at `class-img-converter.php:357` equivalent (`$img_converter->convert_image($source_path,$fmt)` → `$converted` at `class-wppo-cli-command.php:357`) |
| **Stage** | Upload/cron/AS `wppo_convert_image_background` — `none` front-end cost |
| **Priority / Args** | 10, `wppo_should_convert_image` 3 args `(bool $should, string $source_path, string $format)` (`webp|avif`), `wppo_conversion_quality` 4 args `(int $quality, string $mime, array $size, string $source_path)`, `wppo_image_converted` 4 args `(string $source_path, string $dest_path, string $format, int $quality)` |
| **Purpose** | Per-image veto (screenshots that PNG-compress better) + per-image quality (hero 90, thumbnail 70) beyond globals `wppo_filesize_limit_bytes:402` (20MiB), `wppo_max_image_dimensions:422` (5000×5000), `wppo_convert_gain_map_images:360` |
| **Example** | `add_filter('wppo_conversion_quality', fn($q,$mime,$size)=> ($size['width']??0)>2000 ? 90 : 70,10,4);` |
| **Perf/Compat** | `none` — conversion only (upload/cron/AS, not front-end serve hot path); quality clamped 1-100 internally; document existing `wppo_filesize_limit_bytes` etc. as public (`HOOK-AUDIT.md §15`) before adding |
| **@since** | `NEXT` |
| **Citations** | `HOOK-GAPS G-11`, `ADVERSARIAL G-11 RETAIN`, `class-img-converter.php:319,360,377,402,422`, `class-wppo-cli-command.php:357` |

### G-12 — `wppo_next_gen_should_serve` (P2 — external hotlink)

| Field | Spec |
|-------|------|
| **Name** | `wppo_next_gen_should_serve` — **REJECT** companion `wppo_next_gen_image_url` mutate |
| **Type** | filter |
| **Location** | `includes/class-image-optimisation.php:887` `replace_image_with_next_gen()` **entry** (before `Accept` header + `file_exists` + `excludeConvertImages` checks) |
| **Stage** | During buffer (TagProcessor path, per image) |
| **Priority / Args** | 10, 4: `bool $should`, `string $img_url` (normalized absolute), `string $format` (`webp|avif`), `array $exclude_imgs` |
| **Purpose** | External hotlink (Unsplash) must not be rewritten to local `wppo/…webp`; veto is enough, deterministic `WP_CONTENT_DIR/wppo/…` at `class-img-converter.php` |
| **Example** | `add_filter('wppo_next_gen_should_serve', fn($s,$url)=> str_contains($url,'unsplash.com') ? false : $s,10,4);` |
| **Reject `image_url`** | Mutating `$new_url` risks double-rewrite with CDN at `class-cache.php:1357` + stale path |
| **Perf/Compat** | `low` — per image tag; default `true` when file exists + Accept matches |
| **@since** | `NEXT` |
| **Citations** | `HOOK-GAPS G-12`, `ADVERSARIAL G-12 MODIFY`, `class-image-optimisation.php:887` |

### G-13 — `wppo_lazy_should_lazyload` (P1 — hero/OD/Woo gallery)

| Field | Spec |
|-------|------|
| **Name** | `wppo_lazy_should_lazyload` — **REJECT** companion `wppo_lazy_exclude_first_images` count filter |
| **Type** | filter |
| **Location** | `includes/class-image-optimisation.php` `add_delay_load_img()` loop approx `1400-1600` before `data-src` swap (near `wppo_lazyload_iframe_allowed:2024,2769` check) |
| **Stage** | During buffer (per `<img>`), front-end only |
| **Priority / Args** | 10, 4: `bool $should`, `string $src`, `string $img_tag`, `int $index` (0-based image order) |
| **Purpose** | Hero detection (OD heuristic `class-od-bridge.php`), logged-in preview must not lazy-load, Woo gallery first 2 eager — setting `excludeFirstImages` count alone cannot express `index===0` eager but `index===1` eager only on `/product/*` |
| **Example** | `add_filter('wppo_lazy_should_lazyload', fn($s,$src,$tag,$i)=> is_product() && $i<2 ? false : $s,10,4);` |
| **Reject count filter** | `wppo_lazy_exclude_first_images(int $count,…)` duplicates setting `image_optimisation.excludeFirstImages` |
| **Perf/Compat** | `low` — per image; skipped when `placeholderType=none` at `class-image-optimisation.php` |
| **@since** | `NEXT` |
| **Citations** | `HOOK-GAPS G-13`, `ADVERSARIAL G-13 RETAIN`, `class-image-optimisation.php:2024,2769`, `PERF-RESEARCH.md §2.2` |

### G-15 — `wppo_before_database_cleanup` + `wppo_database_cleanup_type_completed` per-type (P0 — silent per-type gap)

| Field | Spec |
|-------|------|
| **Names** | `wppo_before_database_cleanup` (action) + `wppo_database_cleanup_type_completed` (action per-type) + keep existing `wppo_database_cleanup_completed` for `type=all` |
| **Type** | actions — **NOTE** existing `wppo_database_cleanup_completed` already at `class-database-cleanup.php:737` for `type=all` — do not change arity |
| **Location** | `wppo_before_database_cleanup` **Before** `invoke_cleanup_method` at `includes/class-database-cleanup.php:714` `clean_all()` loop + at `:935` `invoke_cleanup_method()` return path (single-type via REST `database_cleanup` `class-rest.php:819-916` or CLI `database cleanup --type=…` `class-wppo-cli-command.php:237-277`) <br> `wppo_database_cleanup_type_completed` **After** `invoke_cleanup_method` return at `:935` (even when called via REST/CLI single-type) + after `do_action('wppo_database_cleanup_completed','all',…)` at `:737` keep companion |
| **Stage** | REST/CLI/cron `cleanup` — not front-end |
| **Priority / Args** | 10, `wppo_before_database_cleanup` 1 arg `string $type` (`revisions|auto_drafts|…|all`), `wppo_database_cleanup_type_completed` 2 args `string $type`, `int\|WP_Error $result` (`int` rows deleted or `WP_Error`), keep existing `wppo_database_cleanup_completed` 3 args `type='all', int $total, array\|null $results` |
| **Purpose** | Metrics exporters/Slack get none for per-type cleaners — `WP-CLI-CURRENT.md §3.2` notes CLI logs after `clean_all` but per-type `clean_revisions` fires nothing; fix silent gap |
| **Example** | `add_action('wppo_database_cleanup_type_completed', fn($type,$res)=> is_wp_error($res) ? slack("{$type} failed: ".$res->get_error_message()) : prom("wppo_db_cleanup_rows", is_int($res)?$res:0, ['type'=>$type]),10,2);` |
| **Perf/Compat** | `none` — fires once per cleanup op; additive; existing `all` listener unchanged |
| **@since** | `NEXT` |
| **Citations** | `HOOK-GAPS G-15 P0`, `ADVERSARIAL G-15 RETAIN P0`, `class-database-cleanup.php:714,737,935`, `class-rest.php:819-916`, `class-wppo-cli-command.php:237-277`, `HOOK-AUDIT.md §6` gap note |

### G-16 — `wppo_db_revision_defaults` + `wppo_db_batch_size` (P2 — enterprise legal-hold)

| Field | Spec |
|-------|------|
| **Names** | `wppo_db_revision_defaults` (filter) + `wppo_db_batch_size` (filter) — **REJECT** `wppo_db_optimize_max_bytes` (1 GiB `:1040`) + `wppo_db_optimize_should_optimize` |
| **Type** | filters |
| **Location** | `wppo_db_revision_defaults` **After** `get_revision_defaults()` reading `dbRevMaxAge`/`dbRevKeepLatest` at `includes/class-database-cleanup.php:753` `apply_filters('wppo_db_revision_defaults', [$max_age,$keep], $settings)` with bounds clamp `max_age 1-365, keep 1-100` internally <br> `wppo_db_batch_size` **During** `delete_in_batches()` `$batch` param default `1000` at `includes/class-database-cleanup.php:138` → `apply_filters('wppo_db_batch_size', $batch, $table)` |
| **Stage** | Cleanup/cron only — not front-end |
| **Priority / Args** | 10, `wppo_db_revision_defaults` 2 args `array{0:int,1:int} $defaults, array $settings`, `wppo_db_batch_size` 2 args `int $batch, string $table` |
| **Purpose** | Enterprise `keep_latest=20` legal-hold on one site, `3` elsewhere; large `wp_postmeta` 50M needs smaller batch to avoid `lock_wait_timeout` — CLI flag `--batch-size` (`W-06`) is UI knob, filter is programmatic knob |
| **Example** | `add_filter('wppo_db_batch_size', fn($b,$table)=> $table==='postmeta' ? 500 : $b,10,2);` |
| **Reject optimize** | `optimize_table` at `:1040-1088` allowlists `$wpdb->{table}` + 1 GB gate + `SHOW TABLE STATUS` fallback; operator disables via not running `optimize` rather than per-table filter |
| **Perf/Compat** | `none` — cleanup/cron only; filter can widen but validation clamps |
| **@since** | `NEXT` |
| **Citations** | `HOOK-GAPS G-16`, `ADVERSARIAL G-16 MODIFY`, `class-database-cleanup.php:138,753,1040` |

### G-17 — `wppo_rum_should_collect` + `wppo_rum_sample_rate` + `wppo_rum_collect_args` + `wppo_rum_rate_limit` (P1 — sampling & privacy)

| Field | Spec |
|-------|------|
| **Names** | `wppo_rum_should_collect` (filter, veto) + `wppo_rum_sample_rate` (filter, 0.0-1.0) + `wppo_rum_collect_args` (filter, mutate) + `wppo_rum_rate_limit` (filter, int) |
| **Type** | filters |
| **Location** | `wppo_rum_should_collect` **During** `collect()` entry at `includes/class-rum.php:121` `apply_filters('wppo_rum_should_collect', true, $params, $ip)` — early return avoids `sanitize_sample` + `store_sample` + transient writes <br> `wppo_rum_sample_rate` **During** `maybe_enqueue_scripts()` at `class-rum.php:189` `apply_filters('wppo_rum_sample_rate', 1.0)` gate beacon enqueue at `class-main.php:619` `wp_enqueue_scripts:5` <br> `wppo_rum_collect_args` **Before** `sanitize_sample` at `class-rum.php:121` same entry — mutate raw beacon JSON <br> `wppo_rum_rate_limit` **During** `is_rate_limited()` at `class-rum.php:275` wrapping `RATE_LIMIT_PER_HOUR` constant `120` at `:49` |
| **Stage** | `collect` (beacon HTTP `POST v1/rum_collect` public token+rate-limit `:227`) + `maybe_enqueue_scripts` (front `wp_head`) |
| **Priority / Args** | 10, `wppo_rum_should_collect` 3 args `(bool $should, array $params, string $ip)`, `wppo_rum_sample_rate` 1 arg `float $rate`, `wppo_rum_collect_args` 1 arg `array $params`, `wppo_rum_rate_limit` 1 arg `int $limit` |
| **Purpose** | 1M PV/day needs `1%` sampling to bound `wppo_web_vitals_rum` `wppo_web_vitals_trends` capped 30/URL+strategy; privacy/DNT suppress logged-in admins / `Do-Not-Track:1` |
| **Example** | `add_filter('wppo_rum_sample_rate', fn()=> is_user_logged_in() ? 0.0 : 0.01); add_filter('wppo_rum_should_collect', fn($s,$p,$ip)=> isset($_SERVER['HTTP_DNT'])&&$_SERVER['HTTP_DNT']==='1' ? false : $s,10,3);` |
| **Perf/Compat** | `none` — early return avoids storage; default `true`/`1.0`/`120`; `false` on `should_collect` returns `200` with no storage (silent drop vs 401) |
| **@since** | `NEXT` |
| **Citations** | `HOOK-GAPS G-17`, `ADVERSARIAL G-17 RETAIN`, `class-rum.php:36,43,49,121,189,275`, `class-main.php:619-620`, `class-rest.php:227` |

### G-19 — `wppo_object_cache_config` + `wppo_cli_redis_config` (P1 — sentinel/cluster)

| Field | Spec |
|-------|------|
| **Names** | `wppo_object_cache_config` (filter, mutation) + `wppo_cli_redis_config` (filter, CLI parity) — **REJECT** lifecycle actions `before_enable/disable/flushed` |
| **Type** | filters |
| **Location** | `wppo_object_cache_config` **During** `enable()` entry at `includes/class-object-cache.php:252` `apply_filters('wppo_object_cache_config', $config)` (before `ping`/`put_contents(config)`/`copy(template->dropin)`/`wp_cache_flush()` at `:252-316` `wppo_parse_nodes`) <br> `wppo_cli_redis_config` **After** `get_redis_config_from_assoc()` return at `includes/class-wppo-cli-command.php:864` `apply_filters('wppo_cli_redis_config', $config, $assoc_args)` (bridges CLI 6 vs REST 10 key divergence `host,port,password,database,timeout,prefix` at `:864-871` vs REST `build_redis_config` at `class-rest.php:1104-1142` `mode,nodes,master_name,use_tls,persistent,compression`) |
| **Stage** | Admin/CLI `object-cache enable` only |
| **Priority / Args** | 10, `wppo_object_cache_config` 1 arg `array $config` (`mode,host,port,password,database,nodes,master_name,use_tls,persistent,compression` plus extras), `wppo_cli_redis_config` 2 args `array $config, array $assoc_args` |
| **Purpose** | Managed hosts (WPE/Kinsta) inject Redis via `WPPO_REDIS_PASSWORD` constant / env and need `mode/nodes` sentinel/cluster without patching `redis-connect-helper.php`; CI can assert via filter without writing `wppo-redis-config.php` |
| **Example** | `add_filter('wppo_cli_redis_config', fn($cfg,$assoc)=> isset($assoc['mode']) ? array_merge($cfg, ['mode'=>'sentinel','nodes'=>explode(',',$assoc['nodes'])]) : $cfg,10,2);` |
| **Perf/Compat** | `none` — admin/CLI only; existing `wppo_redis_allow_request_password` at `class-rest.php:1130` remains narrower hatch |
| **Reject actions** | `before_enable/disable/flushed` actions unnecessary — CI can `ping` without write via `object-cache ping` at `class-wppo-cli-command.php:811-819` (`Object_Cache::ping:205-238`) and enable/disable already log `Log::add` at `:827,837` |
| **@since** | `NEXT` |
| **Citations** | `HOOK-GAPS G-19`, `ADVERSARIAL G-19 RETAIN (MODIFY)`, `class-object-cache.php:252,325,356,67`, `class-wppo-cli-command.php:864`, `WP-CLI-CURRENT.md §3.5` 6-vs-10 gap |

### G-20 — `wppo_settings_before_update` veto (P1 — policy)

| Field | Spec |
|-------|------|
| **Name** | `wppo_settings_before_update` — **REJECT** companions `wppo_settings_sanitize` + `wppo_settings_after_update` |
| **Type** | filter (veto) |
| **Location** | `includes/class-rest.php:464` `update_settings()` after `sanitize_settings_recursively` at `:476`/`531` + before `update_option('wppo_settings')` at `class-wppo-cli-command.php:671,739` mirror at `class-wppo-cli-command.php:573` `settings update` + `import` paths — `apply_filters('wppo_settings_before_update', bool $should_update, string $tab, array $sanitized, array $old)` return `false` → `WP_Error 400` at `class-rest.php:464` (distinct from core `pre_update_option_wppo_settings` returning `$old`) |
| **Stage** | REST/CLI `update`/`import` — not front-end |
| **Priority / Args** | 10, 4: `bool $should_update` (default `true`), `string $tab`, `array $sanitized`, `array $old` (`wppo_settings` previous) |
| **Purpose** | Enterprise policy `cdnURL` off-site deny, require `enableBrotli false` on non-brotli hosts — transactional per-tab deny returns `WP_Error 400` |
| **Example** | `add_filter('wppo_settings_before_update', fn($s,$tab,$san,$old)=> $san['file_optimisation']['cdnURL'] && !str_contains($san['file_optimisation']['cdnURL'], parse_url(home_url(),PHP_URL_HOST)) ? false : $s,10,4);` |
| **Reject rationale** | `wppo_settings_sanitize` duplicates `sanitize_settings_recursively:877-913` (`class-util.php:877`) stripping `[^a-zA-Z0-9_\-]` + `esc_url` — use core `pre_update_option_wppo_settings` filter instead; `after_update` duplicates core `update_option_wppo_settings` already firing `Main::on_settings_update:1032` + `Util::on_settings_update:245` |
| **Perf/Compat** | `none` — admin/CLI/REST only; must not throw when `should_update=false` (return WP_Error 400) |
| **@since** | `NEXT` |
| **Citations** | `HOOK-GAPS G-20`, `ADVERSARIAL G-20 MODIFY`, `class-rest.php:464`, `class-util.php:877`, `class-util.php:245-248` dynamic option hook, `class-wppo-cli-command.php:573,671,739` |

### G-22 — `wppo_cli_redis_config` (P1 — already at G-19, CLI parity)

Covered above as `wppo_cli_redis_config` at `class-wppo-cli-command.php:864`. Reject `wppo_cli_should_run` + `wppo_cli_after_command` at `:75` per `ADVERSARIAL G-22 MODIFY` — generic before/after CLI adds 2× `apply_filters` per subcommand for orchestration better done in shell `&&` (deployment script `wp wppo cache clear && wp cache flush`) — not hook.

### G-27 — `wppo_purge_urls` (P1 — CDN/Edge purge targeting)

| Field | Spec |
|-------|------|
| **Name** | `wppo_purge_urls` (filter) — **REJECT** duplicate `wppo_should_purge_cdn` separate veto |
| **Type** | filter |
| **Location** | `includes/class-cdn-purger.php:193` + `includes/class-edge-purger.php:212` inside `purge_all()` before HTTP purge (consumers of `wppo_after_cache_clear:2074` via `class-main.php:623,626` priority 10 vs 20) |
| **Stage** | After `Cache::clear_cache` → `wppo_after_cache_clear` (:2074) |
| **Priority / Args** | 10, 1: `array $urls` (absolute CDN URLs: images+assets, not just `wppo_invalidation_urls` relative FS paths at `G-03`) |
| **Purpose** | CDN purge needs absolute CDN URLs after FS purge — distinct from `wppo_invalidation_urls` relative disk list; narrow `purge_urls` before `wp_remote_get` purge; single veto via empty return |
| **Example** | `add_filter('wppo_purge_urls', fn($urls)=> array_values(array_filter($urls, fn($u)=> !str_contains($u,'/private/'))));` |
| **Perf/Compat** | `none` — `wppo_after_cache_clear` already signals purge; per-URL filter is additive |
| **@since** | `NEXT` |
| **Citations** | `HOOK-GAPS G-27`, `ADVERSARIAL G-27 MODIFY`, `class-cdn-purger.php:193`, `class-edge-purger.php:212`, `class-cache.php:2074`, `class-main.php:623,626` |

---

## 2. Priorities Consolidated (Design → `OPTIONS-COMPARISON.md` Weighted P)

| Hook | Gap | Priority | Impact | Effort | Risk | Perf benefit | Dev benefit |
|---|---|---|---|---|---|---|---|
| `wppo_should_cache_request` | G-01 | **P0** | 5 | 1 | 1 | none | blocks forks |
| `wppo_before_database_cleanup` + `type_completed` | G-15 | **P0** | 5 | 1 | 1 | none | metrics exporters |
| `wppo_should_optimise_for_user` | G-28 | **P0** | 4 | 1 | 1 | none | per-role caching |
| `wppo_cache_written` + `miss` | G-02 | **P1** | 4 | 1 | 1 | none | APM |
| `wppo_invalidation_urls` + `cache_invalidated` + `should_invalidate_on_save` | G-03 | **P1** | 4 | 1 | 1 | low | headless purge |
| `wppo_preload_batch_size` + `sitemap_limit` + `sitemap_urls` | G-05 | **P1** | 4 | 1 | 1 | none (cron) | large sites |
| `wppo_cdn_url` + `should_rewrite` | G-07 | **P1** | 4 | 1 | 1 | low | dual CDN |
| `wppo_should_convert_image` + `conversion_quality` + `image_converted` | G-11 | **P1** | 3 | 1 | 1 | none | photography |
| `wppo_lazy_should_lazyload` | G-13 | **P1** | 3 | 1 | 1 | low | hero/OD |
| `wppo_should_convert_image` already P1, next `should_serve` | G-12 | **P1** | 3 | 1 | 1 | low | external images |
| `wppo_rum_should_collect` + `sample_rate` + `collect_args` + `rate_limit` | G-17 | **P1** | 3 | 1 | 1 | none | privacy |
| `wppo_object_cache_config` + `cli_redis_config` | G-19/G-22 | **P1** | 4 | 1 | 1 | none | sentinel/cluster |
| `wppo_settings_before_update` veto | G-20 | **P1** | 3 | 1 | 1 | none | policy |
| `wppo_purge_urls` | G-27 | **P1** | 3 | 1 | 1 | none | CDN purge |
| `wppo_delay_should_delay` | G-09 | **P2** | 2 | 1 | 1 | low | checkout |
| `wppo_next_gen_should_serve` | G-12 | **P2** | 2 | 1 | 1 | low | hotlink |
| `wppo_db_revision_defaults` + `batch_size` | G-16 | **P2** | 2 | 1 | 1 | none | large postmeta |

---

## 3. Reject List (Separate — Do Not Implement, Document Existing Instead)

Per `ADVERSARIAL-REVIEW.md §2` reject/modify with reason → migrate to docs of existing hook.

| Rejected Proposal | Gap | Reason | Instead document |
|---|---|---|---|
| `wppo_cache_hit` in `templates/advanced-cache.php` drop-in | G-02 | Drop-in boots before `plugins_loaded`, `do_action` would boot `wp-load.php` breaking zero-boot promise `PERF-RESEARCH.md §2.2` | `X-WPPO-Cache: HIT` header + `access.log` |
| `wppo_buffer_stage` 6 stages (`before_image\|after_image\|…\|before_cdn`) at `class-cache.php:1243` | G-04 | 6× `apply_filters` per front-end hit (40-script pages →0.2-0.6ms `PERF-RESEARCH.md §2.2` low but 6×); `wppo_cache_page_html:1661` + `script_loader_tag:10` already cover | Single `wppo_buffer_before_save` alias before `wppo_cache_page_html` if symmetry needed |
| `wppo_combine_css_should_combine` at `class-cache.php:649` | G-06 | Per-style hot path `wp_enqueue_scripts PHP_INT_MAX`; `excludeCombineCSS` via `Util::process_urls` + `wppo_skip_combine_on_small_block_theme:1143` already cover | Docs: URL-fragment handle exclude + `is_block_asset` hard-exclude |
| `wppo_minify_should_minify` + `wppo_minify_exclude` + `wppo_html_minify_*` | G-08/G-14 | `wppo_exclude_minification:2747,2849` with `$type` arg already gates css/js; `wppo_cache_page_html:1661` post-minify restores CSP nonce | Document `wppo_exclude_minification` as canonical |
| `wppo_defer_should_defer` at `class-main.php:515` | G-09 | Duplicates native `wp_script_add_data(strategy)` at `:523` `is_wp63_plus` `HOOK-CORE-RESEARCH.md §0.13` | Keep `wppo_delay_should_delay` only |
| `wppo_placeholder_type/color/lqip_data_uri` at `class-img-converter.php:725,776` | G-10 | `placeholderType` setting `svg\|lqip\|dominant_color\|none` + `wppo_video_placeholder_*` at `1942,1967,1993` cover; per-image filter would fire per img in buffer | Design-system palette clamp via `wppo_cache_page_html` |
| `wppo_next_gen_image_url` mutate | G-12 | Risks double-rewrite with CDN `:1357` + stale path; veto enough | `wppo_next_gen_should_serve` veto |
| `wppo_lazy_exclude_first_images` count | G-13 | Duplicates `image_optimisation.excludeFirstImages` setting | Keep predicate `wppo_lazy_should_lazyload` with `index` |
| `wppo_html_minify_should_minify` + `output` at `class-cache.php:1424` | G-14 | `needs_minify_pass` internal flag + `wppo_cache_page_html` post-mutate | Same |
| `wppo_db_optimize_max_bytes` + `should_optimize` at `class-database-cleanup.php:1040` | G-16 | `optimize_table` already 1 GB gate + allowlist + `SHOW TABLE STATUS` fallback `:1051-1059`; not running `optimize` is the veto | Filter not needed |
| `wppo_rum_max_days/paths/queue/threshold` at `class-rum.php:36,43,49,74,82` | G-18 | Constants bound `wppo_web_vitals_rum` growth; 4 filters add `apply_filters` to store path | Document `rum_enabled` toggle + `wppo_rum_max_days` only if compliance proves need |
| `wppo_object_cache_before_enable/disable/flushed` + `wppo_cli_should_run` + `wppo_cli_after_command` | G-19/G-22 | CI can `object-cache ping:811` without write; `Log::add` `:827,837` already; orchestration via shell `&&` not hook | Keep filters, drop actions |
| `wppo_settings_sanitize` + `after_update` | G-20 | `sanitize_settings_recursively:877-913` already + core `pre_update_option_wppo_settings` / `update_option_wppo_settings` at `class-util.php:245-248` + `class-main.php:789` | Keep veto `before_update` only |
| `wppo_rest_routes` + `rest_permission` + `rest_pre_dispatch` at `class-rest.php:58,357` | G-21 | Custom endpoints belong in agency `custom/v1` namespace; `wppo_rest_routes` could remove `rum_collect` `__return_true` `:227` or `manage_options` `:357` gate — security-sensitive | Use core `rest_pre_dispatch` filter exists |
| `wppo_cron_schedules` + `should_schedule` + `before_schedule` at `class-cron.php:99,114` | G-23 | Core `cron_schedules:61` already filter `every_5_hours 5*3600`; add `add_filter('cron_schedules', fn($s)=>…)` directly | Document core |
| `wppo_admin_localize_data` + `should_enqueue` at `class-main.php:494` `admin_enqueue_scripts ~2200` | G-24 | `wp_add_inline_script('wppo-settings','window.wppoSettings.whiteLabel=true','before')` + `gettext` `load_textdomain_mofile:229-230` already; filter would fire on every `/wp-admin/post.php` | Unhook `admin_enqueue_scripts` |
| `wppo_preload_links` + `speculation_rules_output` + `resource_hints_output` at `class-main.php:758-760` | G-25 | Core `wp_resource_hints:460-465` + `wp_speculation_rules` (WP 6.8 `class-ai-adaptive.php:476` `function_exists wp_get_speculation_rules_configuration`) already | Document core |
| `wppo_litespeed_cache_control_header` at `class-litespeed-integration.php:958,706,741` | G-26 | `wppo_litespeed_ttl:706` + `wppo_litespeed_can_cdn: cache:1349` already `docs/hooks.md:240` | Document `wppo_litespeed_nocache_reason:987` |
| `wppo_block_assets_should_load_separate` at `class-main.php:821` | G-29 | WordPress 6.9 core `should_load_separate_core_block_assets:833` / `should_load_block_assets_on_demand:837` already `HOOK-CORE-RESEARCH.md §0.29` version-gated | Hook core directly |
| `wppo_image_use_html_processor` at `class-image-optimisation.php:427` | G-30 | Implementation detail `class_exists WP_HTML_Tag_Processor`; not operator point | Debugging-only |
| `wppo_core_tweak_enabled` per-tweak at `class-core-tweaks.php:30` | G-31 | Each tweak already gated on setting `disableEmojis` via `Main:351-353`; adding 15 `apply_filters` on `init:37` per request wasteful | Use `wp wppo settings update` |
| `wppo_debug_log_level` + `wppo_log_message` at `class-cache.php:282` + `class-log.php` | G-32 | `wppo_debug_log:282` private with no prod `add_action` `HOOK-AUDIT §2.1`; `WP_DEBUG` + `have_action wppo_debug_log` + `WP_CLI::debug` `--debug` already standard | Expose as internal not public |

*Undocumented-but-existing to document before adding* (`HOOK-AUDIT.md §15` `wppo_filesize_limit_bytes:402`, `wppo_max_dimensions:422`, `wppo_convert_gain_map_images:360`, `wppo_cron_discovery_limit:666`, `wppo_server_timing_enabled:1252`, `wppo_object_cache_dropin_path:67`, `wppo_redis_allow_request_password:1130`, `wppo_telemetry_verify_ssl:227`, `wppo_ccss_allowed_stylesheet_host:569`, `wppo_ccss_sanitize_inline:605`, `wppo_video_* 1942,1967,1993`, `wppo_skip_combine_on_small_block_theme:1143`) → `docs/hooks.md` PR-Z before PR-F (`ARCH-RESEARCH.md §8` order).

---

*Research-only, no production edits. Compatible with `WP-CLI-GAPS.md` (status/diagnostics/config/cache/etc.) + `WP-CLI-PROPOSED-DESIGN.md` (per-subcommand `Options`/`Permissions`/`Output`/`Examples`) + `BRAINSTORM.md` (A minimal / B recommended / C) + `OPTIONS-COMPARISON.md` (scored P0-3).*
