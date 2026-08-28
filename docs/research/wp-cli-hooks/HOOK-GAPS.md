# Hook Extensibility Gaps — performance-optimisation

**Date:** 2026-08-28 · **Scope:** `includes/*.php`, `templates/*.php`, `performance-optimisation.php` (272 production hook hits) · **Method:** Read-only audit of `HOOK-AUDIT.md` + direct file reads at cited `file:line`; every gap cites best insertion point with line number, type, args, consumers, perf/compat impact, priority. **No production edits.**

> Companion: `HOOK-AUDIT.md` (full 272-hit inventory), `WP-CLI-CURRENT.md` (CLI parity), `docs/hooks.md` (42 public `wppo_*` docs). This doc identifies *missing* meaningful extension points — not vanity hooks.

---

## 0. Prioritisation Key

| Priority | Meaning |
|----------|---------|
| **P0** | Blocking extensibility — forces constant/fork (e.g. `DONOTCACHEPAGE`-only veto, silent per-type DB gap) or high-demand agency use-case |
| **P1** | High-value — unlocks ecosystem integration (APM, CDN, Woo, multisite ops) with negligible risk |
| **P2** | Useful — operator tuning, observability, or tuning knobs for large sites |
| **P3** | Nice-to-have — ergonomic sugar / alias to reduce `has_filter` boilerplate |

**Perf Impact:** `none` = filter on cold path / behind flag; `low` = per-request but short-circuits; `negligible` in table means measurable <0.1 ms.
**Compat Impact:** `none` = additive filter/action with safe default; `low` = caller must respect default `true`/`false` return.

---

## 1. Summary Gap Table (32 gaps, 17 categories)

| # | Category | Missing Extension Point | Type | Best Location | Priority |
|---|----------|-------------------------|------|---------------|----------|
| G-01 | Cache — veto | `wppo_should_cache_request` | filter | `includes/class-cache.php:1505` (`is_not_cacheable()` entry) | **P0** |
| G-02 | Cache — single-page lifecycle | `wppo_before_cache_write` / `wppo_cache_written` / `wppo_cache_miss` | action | `includes/class-cache.php:1741` (`save_processed_buffer`) + `includes/class-cache.php:1661` (`save_cache_files`) + drop-in | **P1** |
| G-03 | Cache — invalidation granularity | `wppo_cache_invalidated` (page/archive/term/home) + `wppo_should_invalidate_on_save` | action+filter | `includes/class-cache.php:1838` (`invalidate_dynamic_static_html`) | **P1** |
| G-04 | Cache — buffer pipeline stages | `wppo_buffer_processed` stepwise filters | filter | `includes/class-cache.php:1243` (`process_buffer_only`) | **P1** |
| G-05 | Cache — preload tuning | `wppo_preload_batch_size`, `wppo_sitemap_preload_limit`, `wppo_preload_sitemap_urls`, `wppo_should_preload_page` | filter | `includes/class-cron.php:301` + `:364` + `:487` + `283` | **P1** |
| G-06 | Asset — combine per-handle | `wppo_combine_css_should_combine` | filter | `includes/class-cache.php:649` (`get_combined_handles`) | **P2** |
| G-07 | Asset — CDN per-asset | `wppo_cdn_url` + `wppo_cdn_should_rewrite` | filter | `includes/class-cache.php:1357` (`maybe_apply_cdn`) | **P1** |
| G-08 | Asset — minify granular | `wppo_minify_should_minify` + `wppo_minify_exclude` alias | filter | `includes/class-main.php:2747` / `2849` (`minify_css`/`minify_js`) | **P2** |
| G-09 | Asset — defer/delay granularity | `wppo_defer_should_defer` / `wppo_delay_should_delay` per-handle | filter | `includes/class-main.php:515` (`add_defer_attribute`) + `:722` | **P2** |
| G-10 | Image — placeholder | `wppo_placeholder_type`, `wppo_placeholder_color`, `wppo_lqip_data_uri` | filter | `includes/class-image-optimisation.php:2720` + `class-img-converter.php:725` + `776` | **P2** |
| G-11 | Image — conversion decision | `wppo_should_convert_image`, `wppo_conversion_quality` | filter+action | `includes/class-img-converter.php:319` (`convert_image` entry) + `377` | **P1** |
| G-12 | Image — srcset/serve | `wppo_next_gen_should_serve`, `wppo_next_gen_image_url` | filter | `includes/class-image-optimisation.php:887` (`replace_image_with_next_gen`) | **P2** |
| G-13 | Lazy — general | `wppo_lazy_should_lazyload`, `wppo_lazy_exclude_first_images` | filter | `includes/class-image-optimisation.php:1400` (`add_delay_load_img` approx) | **P1** |
| G-14 | HTML — minify stage | `wppo_html_minify_should_minify`, `wppo_html_minify_output` | filter | `includes/class-cache.php:1424` (`minify_buffer`) | **P2** |
| G-15 | DB — per-type lifecycle | `wppo_before_database_cleanup`, `wppo_database_cleanup_type_completed` | action | `includes/class-database-cleanup.php:714` (`clean_all` loop) + `:935` (`invoke_cleanup_method`) | **P0** |
| G-16 | DB — tuning | `wppo_db_revision_defaults`, `wppo_db_batch_size`, `wppo_db_optimize_should_optimize` | filter | `includes/class-database-cleanup.php:753` + `:138` (`delete_in_batches`) + `:1040` (`optimize_table`) | **P2** |
| G-17 | RUM — sampling & privacy | `wppo_rum_should_collect`, `wppo_rum_sample_rate`, `wppo_rum_collect_args` | filter | `includes/class-rum.php:121` (`collect`) + `:189` (`maybe_enqueue_scripts`) | **P1** |
| G-18 | RUM — storage/retention | `wppo_rum_max_days`, `wppo_rum_max_paths`, `wppo_rum_rate_limit` | filter | `includes/class-rum.php:36`/`43`/`49` (constants) | **P2** |
| G-19 | Object Cache — lifecycle | `wppo_object_cache_enabled`, `wppo_object_cache_config`, `wppo_object_cache_before_enable/disable` | filter+action | `includes/class-object-cache.php:252` (`enable`) + `:325` (`disable`) + `:67` | **P1** |
| G-20 | Config — settings validation | `wppo_settings_sanitize`, `wppo_settings_before_update`, `wppo_settings_after_update` | filter+action | `includes/class-rest.php:464` (`update_settings`) + `includes/class-util.php:877` (`sanitize_settings_recursively`) | **P1** |
| G-21 | REST — route extensibility | `wppo_rest_routes`, `wppo_rest_permission`, `wppo_rest_pre_dispatch` | filter | `includes/class-rest.php:58` (`get_routes`) + `:357` (`permission_callback`) | **P1** |
| G-22 | CLI — parity & extensibility | `wppo_cli_redis_config`, `wppo_cli_should_run`, `wppo_cli_after_command` | filter+action | `includes/class-wppo-cli-command.php:864` (`get_redis_config_from_assoc`) + `:75` (`cache`) | **P1** |
| G-23 | Cron — scheduling | `wppo_cron_schedules`, `wppo_cron_should_schedule`, `wppo_cron_before_schedule` | filter+action | `includes/class-cron.php:99` (`add_custom_cron_interval`) + `:114` (`schedule_cron_jobs`) | **P2** |
| G-24 | Admin — SPA data | `wppo_admin_localize_data`, `wppo_admin_should_enqueue` | filter | `includes/class-main.php:494` (`admin_enqueue_scripts` — `Main::admin_enqueue_scripts: approx 2200`) | **P2** |
| G-25 | Frontend — head output | `wppo_preload_links`, `wppo_speculation_rules_output`, `wppo_resource_hints_output` | filter | `includes/class-main.php:758` (`add_preload_prefetch_preconnect`) + `:759` + `:760` | **P2** |
| G-26 | Compatibility — LiteSpeed header | `wppo_litespeed_cache_control`, `wppo_litespeed_should_cache` (public alias) | filter | `includes/class-litespeed-integration.php:958` + `:741` | **P3** |
| G-27 | Compatibility — CDN/Edge purge | `wppo_purge_urls`, `wppo_should_purge_cdn` | filter | `includes/class-cdn-purger.php:193` + `includes/class-edge-purger.php:212` | **P1** |
| G-28 | Cache — optimization gate | `wppo_should_optimise_for_user` | filter | `includes/class-main.php:369` (`should_optimise_for_logged_in`) + `class-cache.php:297` (`is_cache_allowed_for_current_user`) | **P0** |
| G-29 | Asset — block assets | `wppo_block_assets_should_load_separate` | filter | `includes/class-main.php:821` (`register_block_assets_filters`) | **P3** |
| G-30 | Image — HTML processor | `wppo_image_use_html_processor` | filter | `includes/class-image-optimisation.php:427` (`should_use_html_processor`) | **P3** |
| G-31 | Core tweaks — feature gates | `wppo_core_tweak_enabled` (per-tweak) | filter | `includes/class-core-tweaks.php:30` (`__construct` gates) | **P2** |
| G-32 | Observability — generic | `wppo_debug_log_level`, `wppo_log_message` | filter+action | `includes/class-cache.php:282` (`do_action wppo_debug_log`) + `includes/class-log.php` | **P2** |

> Existing but undocumented hooks that should be documented rather than added: `wppo_filesize_limit_bytes` (`class-img-converter.php:402`), `wppo_max_dimensions` (`:422`), `wppo_convert_gain_map_images` (`:360`), `wppo_cron_discovery_limit` (`class-cron.php:666`), `wppo_server_timing_enabled` (`class-main.php:1252`), `wppo_object_cache_dropin_path` (`class-object-cache.php:67`), `wppo_redis_allow_request_password` (`class-rest.php:1130`), `wppo_telemetry_verify_ssl` (`class-telemetry.php:227`), `wppo_ccss_allowed_stylesheet_host`/`wppo_ccss_sanitize_inline` (`class-critical-css.php:569,605`), `wppo_video_*` family (`class-image-optimisation.php:1942,1967,1993`), `wppo_skip_combine_on_small_block_theme` (`class-cache.php:1143`). See `HOOK-AUDIT.md §15`.

---

## 2. Detailed Gap Analysis

### G-01 — Cache write veto (`wppo_should_cache_request`)

| Field | Value |
|-------|-------|
| **Missing Extension Point** | No filter to veto caching before filesystem write; operators must rely on `DONOTCACHEPAGE` constant or post-hoc `wppo_cache_page_html` mutation |
| **Why Needed** | Membership, A/B, geo, cart-fragment, Woo subscriptions, consent-mode pages need per-URL veto without forking `is_not_cacheable()`. `wppo_cache_page_html` mutates HTML but does not prevent disk write / stampede lock. |
| **Best Location** | `includes/class-cache.php:1505` — first line of `is_not_cacheable()` AND `includes/class-cache.php:1755` — entry of `maybe_store_cache()` (both paths: `start_output_buffer` legacy + `stash_cache` 6.9+). Single filter covers both. |
| **Action/Filter** | `apply_filters( 'wppo_should_cache_request', bool $should_cache, string $request_uri, string $url_path, string $domain )` — return `false` to skip cache |
| **Arguments** | `bool $should_cache` (default `true` after internal checks), `string $request_uri` (`$_SERVER['REQUEST_URI']` sanitized), `string $url_path`, `string $domain` |
| **Expected Consumers** | Woo/membership plugins (Paid Memberships Pro, Woo Subscriptions), A/B testing (Nelio, Google Optimize), consent plugins, multilingual (WPML — exclude `?lang` shards not covered by query-string regex) |
| **Perf Impact** | `none` — single `apply_filters` on cacheable front-end only; skipped for 404/CLI/admin |
| **Compat Impact** | `none` — default `true` preserves behavior; `false` short-circuits before `save_cache_files` lock |
| **Priority** | **P0** |

---

### G-02 — Cache observability (write/miss/hit)

| Field | Value |
|-------|-------|
| **Missing Extension Point** | No `wppo_cache_written` / `wppo_cache_miss` / `wppo_cache_hit` actions; operators cannot measure hit-rate without patching `Cache` or the `advanced-cache.php` drop-in |
| **Why Needed** | APM (New Relic, Query Monitor), Prometheus, and the SPA Dashboard need hit/miss counters. `wppo_before/after_cache_clear` only covers invalidation, not generation. |
| **Best Location** | `includes/class-cache.php:1741` (`save_processed_buffer` after `save_cache_files` success → `wppo_cache_written`), `includes/class-cache.php:1661` (`save_cache_files` early return when `maybe_store_cache()` false → `wppo_cache_miss`), and `templates/advanced-cache.php` drop-in serve path (add `do_action('wppo_cache_hit', $url)` when `index.html` served) |
| **Action/Filter** | `do_action( 'wppo_cache_written', string $url, string $file_path, float $generation_ms )`; `do_action( 'wppo_cache_miss', string $url, string $reason )`; `do_action( 'wppo_cache_hit', string $url )` |
| **Arguments** | `string $url` (`cached_home_url($request_uri)`), `string $file_path`, `float $generation_ms` (from `microtime(true)` diff), `string $reason` (`donotcachepage|query_string|woo_cart|is_404|litepseed_bypass|wppo_should_cache_request`) |
| **Expected Consumers** | APM plugins, `wppo_web_vitals_trends` correlator, hosting dashboards (Kinsta, WP Engine), `WP_CLI` `cache status` hit-rate field |
| **Perf Impact** | `none` — fires once per HTML generation; drop-in hit fires without booting WP (no DB) |
| **Compat Impact** | `none` — additive actions; no return value |
| **Priority** | **P1** |

---

### G-03 — Cache invalidation granularity

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `invalidate_dynamic_static_html()` hard-codes smart purge (page + home + `page_for_posts` + post-type archive + terms) with no per-target veto or observable event; no filter to extend invalidation to custom URLs (e.g. `/store-locator/{city}` CPT) |
| **Why Needed** | Headless/Woo/ACF sites need to purge related URLs (related products, location archives) without clearing `all`. Filter allows adding/removing archive/term purging per post type. |
| **Best Location** | `includes/class-cache.php:1838` (`invalidate_dynamic_static_html` after `$post_type` resolve, before `delete_cache_files`) + `includes/class-main.php:552` (`on_save_post_invalidate_cache` — add `apply_filters('wppo_should_invalidate_on_save', true, $post_id, $post)`) |
| **Action/Filter** | `apply_filters( 'wppo_invalidation_urls', array $urls, int $post_id, string $post_type )` where `$urls` is list of relative paths to purge; `do_action( 'wppo_cache_invalidated', string $type, string $url_path, int $post_id )` per檔案 delete; `apply_filters( 'wppo_should_invalidate_on_save', bool $should, int $post_id, WP_Post $post )` |
| **Arguments** | `array $urls` (relative paths), `int $post_id`, `string $post_type`; `string $type` (`single|home|archive|term`), `string $url_path` |
| **Expected Consumers** | ACF/relationship plugins, Woo cross-sells, multilingual (WPML — purge translated post), custom archive generators |
| **Perf Impact** | `low` — filters on `save_post` only; URL list typically <20 items; no front-end cost |
| **Compat Impact** | `none` — default preserves smart purge; adding URLs is additive; `false` on veto skips only that post's purge |
| **Priority** | **P1** |

---

### G-04 — Buffer pipeline stage hooks

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `process_buffer_only()` (`class-cache.php:1243`) is a monolithic private method (image → Google Fonts → minify HTML → used-CSS → CDN) with no interim filters; third-party transforms must copy the whole method |
| **Why Needed** | Custom CDNs (Bunny, KeyCDN dual-origin), consent-mode script blocking, accessibility injectors (UserWay), and CSP nonces need insertion points without forking buffer logic |
| **Best Location** | `includes/class-cache.php:1243` — after each stage: post-image (`1246-1249`), post-Google Fonts (`1255`), post-minify (`1265`), post-used-CSS (`1268`), pre-CDN (`1271`); add `apply_filters('wppo_buffer_stage', $buffer, $stage, $url)` with stage enum |
| **Action/Filter** | `apply_filters( 'wppo_buffer_stage', string $buffer, string $stage, string $url )` where `$stage` in `before_image|after_image|after_google_fonts|after_minify|after_used_css|before_cdn|after_cdn`; also `apply_filters('wppo_buffer_before_save', $buffer, $url)` alias before `wppo_cache_page_html` for symmetry |
| **Arguments** | `string $buffer` (HTML), `string $stage`, `string $url` (`cached_home_url($request_uri)`) |
| **Expected Consumers** | CDN plugins, consent (CookieYes), CSP nonce injectors, accessibility overlays, custom minifiers |
| **Perf Impact** | `low` — 6 `apply_filters` per HTML generation (front-end only); each is short-circuited when no filter registered (`has_filter` check optional) |
| **Compat Impact** | `none` — default passes buffer unchanged; must return string |
| **Priority** | **P1** |

---

### G-05 — Preload / sitemap tuning (4 filters)

| Field | Value |
|-------|-------|
| **Missing Extension Point** | Hard caps: `200` posts/batch (`class-cron.php:301`), `500` sitemap URLs (`:364` via `get_sitemap_urls(500)`), `15s` wall-clock + `50` child sitemaps (`class-cron.php:496,49`), no filter to adjust caps or mutate discovered URL list |
| **Why Needed** | Large sites (10k+ posts) need larger batches; small VPS need smaller caps; headless/custom sitemap indexes (`/sitemap-custom.xml`) need URL injection; enterprise needs to exclude `/private/*` beyond `excludePreloadCache` |
| **Best Location** | `includes/class-cron.php:301` (`posts_per_page` literal → `apply_filters('wppo_preload_batch_size', 200)`), `336-364` (`schedule_sitemap_url_jobs(500)` → `apply_filters('wppo_sitemap_preload_limit', 500)`), `496` (`$deadline = microtime(true)+15` → `apply_filters('wppo_sitemap_deadline_seconds', 15)`), `487` return `apply_filters('wppo_preload_sitemap_urls', $urls, $cap)`; add `apply_filters('wppo_should_preload_page', true, $page_id, $url)` at `283` loop |
| **Action/Filter** | `apply_filters('wppo_preload_batch_size', int $size)`; `apply_filters('wppo_sitemap_preload_limit', int $cap)`; `apply_filters('wppo_sitemap_deadline_seconds', int $seconds)`; `apply_filters('wppo_preload_sitemap_urls', array $urls, int $cap)`; `apply_filters('wppo_should_preload_page', bool $should, int $page_id, string $url)` |
| **Arguments** | As above; `$urls` is `string[]` absolute URLs already host-filtered |
| **Expected Consumers** | Large catalog sites (10k+ products), headless WP, enterprise sitemap plugins (Yoast, Rank Math sitemap index), low-memory shared hosts |
| **Perf Impact** | `none` — filters on cron only (5-hourly); no front-end cost |
| **Compat Impact** | `none` — defaults preserve current caps; increasing cap increases cron HTTP calls (documented) |
| **Priority** | **P1** (batch/limit), **P2** (deadline) |

---

### G-06 — CSS combine per-handle gate

| Field | Value |
|-------|-------|
| **Missing Extension Point** | No per-handle filter for CSS combining; only global `excludeCombineCSS` setting (string→array via `Util::process_urls`) and hard-coded block-asset / inline-budget exclusions |
| **Why Needed** | Themes/plugins with `@import` or `order`-sensitive CSS (Tailwind layers, Elementor) need to exclude individual handles where URL-fragment match is insufficient; also need to inspect `src`/`media`/`is_block_asset` |
| **Best Location** | `includes/class-cache.php:649` (`get_combined_handles` loop, before `is_excluded_from_combine` check) |
| **Action/Filter** | `apply_filters( 'wppo_combine_css_should_combine', bool $should_combine, string $handle, string $src, bool $is_block_asset )` |
| **Arguments** | `bool $should_combine` (default `true` after existing checks), `string $handle`, `string $src`, `bool $is_block_asset` |
| **Expected Consumers** | Page builders (Elementor, Divi), Tailwind/utility CSS themes, Woo Commerce CSS |
| **Perf Impact** | `low` — once per enqueued style on `wp_enqueue_scripts` `PHP_INT_MAX` |
| **Compat Impact** | `none` — default `true` after existing vetoes; returning `false` keeps handle enqueued individually |
| **Priority** | **P2** |

---

### G-07 — CDN per-asset control

| Field | Value |
|-------|-------|
| **Missing Extension Point** | Only global `cdnURL` setting; no per-asset CDN host / veto; dual-CDN (images → image CDN, assets → asset CDN) impossible |
| **Why Needed** | Cloudflare R2 + Bunny image CDN split, per-region CDN, and origin-pull exclusions (e.g. `/wp-content/uploads/private/*` must not be CDN-rewritten) |
| **Best Location** | `includes/class-cache.php:1357` (`maybe_apply_cdn` entry, after `can_apply_cdn` gate, before `$cdn_url` resolve) + inside `while(next_tag)` per-attribute loop at `:1382` |
| **Action/Filter** | `apply_filters( 'wppo_cdn_url', string $cdn_url, string $original_url, string $tag_name )` — return custom CDN base per asset; `apply_filters( 'wppo_cdn_should_rewrite', bool $should, string $url, string $tag_name )` — veto per asset |
| **Arguments** | `string $cdn_url` (default `cdnURL` Setting), `string $original_url`, `string $tag_name` (`img|script|link|source|video`) |
| **Expected Consumers** | Multi-CDN setups, image CDNs (Cloudinary, Imgix), origin-pull for private uploads |
| **Perf Impact** | `low` — per-asset in buffer pass; short-circuits when no filter |
| **Compat Impact** | `none` — default preserves global CDN; per-asset override is opt-in |
| **Priority** | **P1** |

---

### G-08 — Minify granularity

| Field | Value |
|-------|-------|
| **Missing Extension Point** | Single `wppo_exclude_minification` multiplexes `css`/`js` via `$type` arg; no distinct `wppo_minify_should_minify` predicate with handle/src/type context at queue time |
| **Why Needed** | Hosts that minify at CDN edge want to exclude entire groups (e.g. `wp-polyfill`) from plugin minify but not from CDN rewrite; also need to distinguish `exclude_css` vs `exclude_js` ergonomically |
| **Best Location** | `includes/class-main.php:2747` (`minify_css` entry) + `:2849` (`minify_js` entry) + `includes/class-cache.php:1424` (`minify_buffer` HTML minify) |
| **Action/Filter** | `apply_filters( 'wppo_minify_should_minify', bool $should, string $handle, string $src, string $type )` at CSS/JS minify entry; `apply_filters( 'wppo_html_minify_should_minify', bool $should, string $buffer )` at HTML minify; keep existing `wppo_exclude_minification` as alias for BC |
| **Arguments** | `bool $should` (true after LiteSpeed gate + `excludeCSS/JS` allowlist), `string $handle`, `string $src`, `string $type` (`css|js|html|inline_css|inline_js`) |
| **Expected Consumers** | Edge-minify hosts (Cloudflare Auto-Minify), plugin authors with already-minified handles (`.min.js` double-minify guard) |
| **Perf Impact** | `low` — per style/script tag on `style_loader_tag`/`script_loader_tag` |
| **Compat Impact** | `none` — additive; existing `wppo_exclude_minification` remains canonical |
| **Priority** | **P2** |

---

### G-09 — Defer/delay per-handle predicate

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `wppo_exclude_defer_js` / `wppo_exclude_delay_js` are array filters applied once in `setup_hooks()`; no per-handle runtime predicate to inspect `handle` + `src` + `strategy` |
| **Why Needed** | Delay strategies (`interaction|idle|viewport`) + priorities need per-handle decisions (e.g. `checkout.js` must never be delayed; `analytics.js` idle 5000ms). Array filters cannot express strategy-specific vetoes. |
| **Best Location** | `includes/class-main.php:515` (`add_defer_attribute` per-tag `str_contains` check) + `includes/class-main.php:722` (`exclude_delay_js` resolution) — add runtime filter inside the per-tag loop |
| **Action/Filter** | `apply_filters( 'wppo_defer_should_defer', bool $should_defer, string $handle, string $src )`; `apply_filters( 'wppo_delay_should_delay', bool $should_delay, string $handle, string $src, string $strategy )` |
| **Arguments** | `bool $should`, `string $handle`, `string $src`, `string $strategy` (`interaction|idle|viewport`) |
| **Expected Consumers** | Woo checkout, consent/CMP scripts, performance agencies tuning `delayJSViewportList` programmatically |
| **Perf Impact** | `low` — per script tag on `script_loader_tag` when defer/delay enabled |
| **Compat Impact** | `none` — default `true` after exclusion array; `false` keeps native strategy |
| **Priority** | **P2** |

---

### G-10 — Placeholder / LQIP

| Field | Value |
|-------|-------|
| **Missing Extension Point** | No filter for placeholder SVG/LQIP colour or data URI; only `wppo_video_placeholder_*` filters exist (`class-image-optimisation.php:1942,1967,1993`) |
| **Why Needed** | Design systems need custom dominant-color palette clamping, blurhash instead of LQIP, or disabling placeholder per-image (hero images) |
| **Best Location** | `includes/class-img-converter.php:725` (`extract_dominant_color` return) + `:776` (`generate_lqip` return) + `includes/class-image-optimisation.php: post_process_placeholders` (≈ line 290) |
| **Action/Filter** | `apply_filters( 'wppo_placeholder_type', string $type, string $src )` (`svg|lqip|dominant_color|none`); `apply_filters( 'wppo_placeholder_color', string $hex, string $rel_path )`; `apply_filters( 'wppo_lqip_data_uri', string $data_uri, string $rel_path, int $width, int $height )` |
| **Arguments** | `string $hex` (`#aabbcc`), `string $data_uri` (`data:image/jpeg;base64,...`), `string $rel_path`, `int $width/height` |
| **Expected Consumers** | Design systems, blurhash plugins, performance agencies benchmarking LQIP vs SVG |
| **Perf Impact** | `low` — per image in buffer pass; filters skipped when `placeholderType=none` |
| **Compat Impact** | `none` — defaults preserve SVG/LQIP; returning empty disables placeholder for that image |
| **Priority** | **P2** |

---

### G-11 — Image conversion decision & quality

| Field | Value |
|-------|-------|
| **Missing Extension Point** | No filter to veto conversion per image (e.g. exclude screenshots that PNG-compress better) or to override quality per image/size; quality resolved via `resolve_encode_quality()` with core fallback but no operator hook |
| **Why Needed** | Photography sites need 90 quality for hero, 70 for thumbnails; screenshots/line-art need lossless decision; `wppo_filesize_limit_bytes` / `wppo_max_dimensions` are global caps only |
| **Best Location** | `includes/class-img-converter.php:319` (`convert_image` entry, before `resolve_output_format`) + `:377` (quality resolve) |
| **Action/Filter** | `apply_filters( 'wppo_should_convert_image', bool $should, string $source_path, string $format )`; `apply_filters( 'wppo_conversion_quality', int $quality, string $mime, array $size, string $source_path )`; `do_action( 'wppo_image_converted', string $source_path, string $dest_path, string $format, int $quality )` |
| **Arguments** | `bool $should`, `string $source_path` (absolute), `string $format` (`webp|avif`), `int $quality` (82 default), `string $mime` (`image/webp|image/avif`), `array $size` (`width|height`) |
| **Expected Consumers** | Photography / media sites, CDN image services, quality-sensitive agencies |
| **Perf Impact** | `none` — on conversion only (upload/cron/AS), not on front-end serve hot path |
| **Compat Impact** | `none` — default `true`/`82`; quality clamped 1-100 internally |
| **Priority** | **P1** (veto), **P2** (quality) |

---

### G-12 — Next-gen serving per-image

| Field | Value |
|-------|-------|
| **Missing Extension Point** | No per-image filter to override next-gen URL rewrite; `maybe_serve_next_gen_images()` rewrites all `src/srcset/poster` via `replace_image_with_next_gen()` with only global `excludeConvertImages` and Accept-header gate |
| **Why Needed** | Third-party images (Unsplash hotlink) must not be rewritten; per-image AVIF vs WebP choice (line-art → WebP lossless, photo → AVIF) |
| **Best Location** | `includes/class-image-optimisation.php:887` (`replace_image_with_next_gen` entry) |
| **Action/Filter** | `apply_filters( 'wppo_next_gen_should_serve', bool $should, string $img_url, string $format, array $exclude_imgs )`; `apply_filters( 'wppo_next_gen_image_url', string $new_url, string $original_url, string $format )` |
| **Arguments** | `bool $should`, `string $img_url` (normalized absolute), `string $format` (`webp|avif`), `string $new_url` |
| **Expected Consumers** | External image hosts, per-format choosers, WebP-quality auditors |
| **Perf Impact** | `low` — per image tag in buffer (TagProcessor path) |
| **Compat Impact** | `none` — default `true` when file exists + Accept header matches |
| **Priority** | **P2** |

---

### G-13 — Lazy-load general predicate

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `wppo_lazyload_iframe_allowed` exists (`class-image-optimisation.php:2024,2769`) but no equivalent `wppo_lazy_should_lazyload` for `<img>`; `excludeFirstImages` / `excludeLazyImgs` are settings only, not filterable per request |
| **Why Needed** | Above-the-fold hero detection (OD vs heuristic), logged-in preview (must not lazy-load), and Woo product gallery (first 2 images eager) need per-image veto beyond `excludeFirstImages` count |
| **Best Location** | `includes/class-image-optimisation.php` — `add_delay_load_img()` loop (approx line 1400-1600) before `data-src` swap |
| **Action/Filter** | `apply_filters( 'wppo_lazy_should_lazyload', bool $should, string $src, string $img_tag, int $index )`; `apply_filters( 'wppo_lazy_exclude_first_images', int $count, array $image_optimisation )` |
| **Arguments** | `bool $should` (default after `excludeFirstImages` + `excludeLazyImgs` URL check), `string $src`, `string $img_tag`, `int $index` (0-based image order), `int $count` |
| **Expected Consumers** | OD bridge, Woo galleries, page builders with slider eager logic, preview plugins |
| **Perf Impact** | `low` — per image in buffer |
| **Compat Impact** | `none` — default `true` when not in first-N and not excluded by URL |
| **Priority** | **P1** |

---

### G-14 — HTML minify stage

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `minify_buffer()` (`class-cache.php:1424`) delegates to `Minify\HTML` with no pre/post filter; conditional minify (`minifyHTML`, `delayJS`, `minifyInlineCSS/JS`) is internal `needs_minify_pass` flag |
| **Why Needed** | CSP nonce scripts (`<script nonce="...">`) must not be minified in a way that strips `nonce`; third-party HTML post-processors (Critical CSS injectors) need stable hook after plugin minify |
| **Best Location** | `includes/class-cache.php:1424` (`minify_buffer` entry → `apply_filters('wppo_html_minify_should_minify', bool, $buffer)`) + post-minify return `apply_filters('wppo_html_minify_output', $buffer, $original)` |
| **Action/Filter** | `apply_filters( 'wppo_html_minify_should_minify', bool $should, string $buffer )`; `apply_filters( 'wppo_html_minify_output', string $html, string $original_html )` |
| **Arguments** | `bool $should` (default `needs_minify_pass`), `string $buffer/html`, `string $original_html` |
| **Expected Consumers** | CSP nonce plugins, alternative minifiers (HTMLMin), debugging (preserve HTML comments per route) |
| **Perf Impact** | `none` — one filter per HTML generation |
| **Compat Impact** | `none` — default `true` when `minifyHTML|delayJS|minifyInlineCSS|JS`; returning `false` skips minify for that request |
| **Priority** | **P2** |

---

### G-15 — DB cleanup lifecycle (P0 — silent per-type gap)

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `wppo_database_cleanup_completed` only fires for `type=all` at `class-database-cleanup.php:737` (`clean_all`); per-type cleaners (`clean_revisions`, `clean_auto_drafts`, … via REST `database_cleanup` or CLI `database cleanup --type=...`) fire **no** action; no `wppo_before_database_cleanup` |
| **Why Needed** | Metrics exporters, Slack notifiers, and the WP-CLI `database cleanup` path (`class-wppo-cli-command.php:237`) log after `clean_all` but consumers expecting per-type events get none; `type=all` success masks per-type `WP_Error` in `clean_all` aggregation |
| **Best Location** | `includes/class-database-cleanup.php:714` (`clean_all` loop — add `do_action('wppo_before_database_cleanup', $key)` before `invoke_cleanup_method` + `do_action('wppo_database_cleanup_type_completed', $key, $res)`) + `includes/class-database-cleanup.php:935` (`invoke_cleanup_method` return — add per-type action even when called via REST/CLI single-type path) + `includes/class-rest.php:880` (`database_cleanup` single-type success branch after `Log::add`) |
| **Action/Filter** | `do_action( 'wppo_before_database_cleanup', string $type )`; `do_action( 'wppo_database_cleanup_type_completed', string $type, int|WP_Error $result )`; keep existing `do_action('wppo_database_cleanup_completed', 'all', $total, $results)` |
| **Arguments** | `string $type` (`revisions|auto_drafts|...|all`), `int|WP_Error $result` (`int` rows deleted or `WP_Error`), `int $total`, `array $results` (for `all`) |
| **Expected Consumers** | Metrics (Prometheus `wppo_db_cleanup_rows`), Slack/Teams notifiers, audit log (custom `wppo_activity_logs` enrichers) |
| **Perf Impact** | `none` — fires once per cleanup operation (REST/CLI/cron, not front-end) |
| **Compat Impact** | `none` — additive actions; existing `all` listener unchanged; per-type listeners are new |
| **Priority** | **P0** |

---

### G-16 — DB tuning (revision defaults / batch / optimize)

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `get_revision_defaults()` (`class-database-cleanup.php:753`) reads `dbRevMaxAge`/`dbRevKeepLatest` from settings with hard bounds (1-365 / 1-100) but no filter to override per-environment; `delete_in_batches` batch `1000` and `optimize_table` `1GB` skip are literals |
| **Why Needed** | Enterprise multisite needs `keep_latest=20` for legal hold on one site, `3` elsewhere; large `wp_postmeta` (50M rows) needs smaller batch to avoid `lock_wait_timeout` |
| **Best Location** | `includes/class-database-cleanup.php:753` (`get_revision_defaults` return → `apply_filters('wppo_db_revision_defaults', [$max_age,$keep], $settings)`), `138` (`delete_in_batches` `$batch` param default `1000` → `apply_filters('wppo_db_batch_size', $batch, $table)`), `1040` (`optimize_table` `1073741824` literal → `apply_filters('wppo_db_optimize_max_bytes', 1073741824, $table)`) |
| **Action/Filter** | `apply_filters( 'wppo_db_revision_defaults', array{0:int,1:int} $defaults, array $settings )`; `apply_filters( 'wppo_db_batch_size', int $batch, string $table )`; `apply_filters( 'wppo_db_optimize_max_bytes', int $max_bytes, string $table )`; `apply_filters( 'wppo_db_optimize_should_optimize', bool $should, string $table )` |
| **Arguments** | As above |
| **Expected Consumers** | Enterprise MU plugins, legal-hold sites, large Woo stores (50M `postmeta`) |
| **Perf Impact** | `none` — on cleanup/cron only |
| **Compat Impact** | `none` — defaults preserve bounds; filters can widen but validation should clamp |
| **Priority** | **P2** |

---

### G-17 — RUM sampling & privacy controls

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `RUM::collect()` (`class-rum.php:121`) and `maybe_enqueue_scripts()` (`:189`) have no sampling gate; all pageviews beacon when `rum_enabled` true; `is_rate_limited` hard-codes `120/hour` per IP |
| **Why Needed** | High-traffic sites (1M+ PV/day) need `1%` sampling to keep `wppo_web_vitals_rum` bounded; privacy/DNT sites need to suppress beacons for logged-in admins or `Do-Not-Track: 1` |
| **Best Location** | `includes/class-rum.php:121` (`collect` entry — add `apply_filters('wppo_rum_should_collect', true, $params, $ip)`) + `:189` (`maybe_enqueue_scripts` — add `apply_filters('wppo_rum_sample_rate', 1.0)` and `apply_filters('wppo_rum_should_enqueue', true)`), `includes/class-rum.php:275` (`is_rate_limited` `RATE_LIMIT_PER_HOUR` → `apply_filters('wppo_rum_rate_limit', 120)`) |
| **Action/Filter** | `apply_filters( 'wppo_rum_should_collect', bool $should, array $params, string $ip )`; `apply_filters( 'wppo_rum_sample_rate', float $rate )` (0.0-1.0); `apply_filters( 'wppo_rum_collect_args', array $params )` (mutate before `sanitize_sample`); `apply_filters( 'wppo_rum_rate_limit', int $limit )` |
| **Arguments** | `bool $should`, `array $params` (raw beacon JSON), `string $ip`, `float $rate` |
| **Expected Consumers** | High-traffic sites, privacy plugins (WP Consent API), APM samplers |
| **Perf Impact** | `none` — early return avoids `sanitize_sample` + `store_sample` + transient writes |
| **Compat Impact** | `none` — default `true` / `1.0` / `120`; `false` on `should_collect` returns 200 with no storage (silent drop) vs 401 |
| **Priority** | **P1** |

---

### G-18 — RUM storage & retention bounds

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `RUM::MAX_DAYS=14`, `MAX_PATHS_PER_DAY=200`, `RATE_LIMIT_PER_HOUR=120`, `QUEUE_MAX=100`, `FLUSH_THRESHOLD=20` are constants; retention and bounds cannot be tuned without fork |
| **Why Needed** | Retention compliance (30-day) and low-cardinality sites (50 paths) want to tune; enterprise wants `QUEUE_MAX=500` for burst traffic |
| **Best Location** | `includes/class-rum.php:36` (`MAX_DAYS`), `43` (`MAX_PATHS_PER_DAY`), `49` (`RATE_LIMIT_PER_HOUR`), `74` (`QUEUE_MAX`), `82` (`FLUSH_THRESHOLD`) — each literal used in `flush_queue`/`store_sample`/`is_rate_limited` → wrap with `apply_filters` at use-site (not constant override, to keep constants authoritative) |
| **Action/Filter** | `apply_filters( 'wppo_rum_max_days', int $days )`; `apply_filters( 'wppo_rum_max_paths_per_day', int $max )`; `apply_filters( 'wppo_rum_queue_max', int $max )`; `apply_filters( 'wppo_rum_flush_threshold', int $threshold )` |
| **Arguments** | `int` values with current defaults |
| **Expected Consumers** | Compliance (30-day retention), low-cardinality sites, burst-traffic hosts |
| **Perf Impact** | `none` — on `collect`/`flush_queue` only |
| **Compat Impact** | `low` — must clamp (`max(1, min(...))`) to avoid unbounded option growth |
| **Priority** | **P2** |

---

### G-19 — Object cache lifecycle (credentials + events)

| Field | Value |
|-------|-------|
| **Missing Extension Point** | No action before/after `enable`/`disable`/`flush`; `get_redis_config_from_assoc()` (`class-wppo-cli-command.php:864`) allowlists only 6 keys vs REST `build_redis_config()` 10 keys; no `wppo_redis_config` filter to inject `sentinels`/`cluster` config without patching `redis-connect-helper.php` |
| **Why Needed** | Managed hosts (WPE, Kinsta) inject Redis via constant/environment and need to veto `enable` when `WPPO_REDIS_PASSWORD` is managed; CI needs `before_enable` to assert `ping` without writing `wppo-redis-config.php`; cluster/sentinel not configurable via CLI today |
| **Best Location** | `includes/class-object-cache.php:252` (`enable` entry → `do_action('wppo_object_cache_before_enable', $config)` + `apply_filters('wppo_object_cache_config', $config)`), `:325` (`disable` entry → `do_action('wppo_object_cache_before_disable')`), `:356` (`flush` → `do_action('wppo_object_cache_flushed', bool $result)`), `includes/class-wppo-cli-command.php:864` (`get_redis_config_from_assoc` return → `apply_filters('wppo_cli_redis_config', $config, $assoc_args)`) |
| **Action/Filter** | `do_action( 'wppo_object_cache_before_enable', array $config )`; `do_action( 'wppo_object_cache_after_enable', array $config, bool|WP_Error $result )`; `do_action( 'wppo_object_cache_before_disable' )`; `do_action( 'wppo_object_cache_flushed', bool $success )`; `apply_filters( 'wppo_object_cache_config', array $config )`; `apply_filters( 'wppo_cli_redis_config', array $config, array $assoc_args )` |
| **Arguments** | `array $config` (`mode,host,port,password,database,nodes,master_name,use_tls,persistent,compression` plus CLI extras), `bool|WP_Error $result`, `bool $success` |
| **Expected Consumers** | Managed-host MU plugins, CI, cluster operators, security auditors (password handling) |
| **Perf Impact** | `none` — admin/CLI only |
| **Compat Impact** | `none` — additive; `wppo_redis_allow_request_password` (existing) remains narrower escape hatch |
| **Priority** | **P1** |

---

### G-20 — Settings sanitization & update lifecycle

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `update_settings` (`class-rest.php:464`) and `sanitize_settings_recursively()` (`class-util.php:877`) have no per-tab or global pre/post hooks beyond generic `update_option_wppo_settings` (which fires for *all* option updates); no way to veto or mutate a single tab update transactionally |
| **Why Needed** | Enterprise policy (disallow `cdnURL` pointing off-site, require `enableBrotli` false on non-brotli hosts), and SPA telemetry (log which admin changed `file_optimisation` without polling `Log::get_recent_activities`) |
| **Best Location** | `includes/class-rest.php:464` (`update_settings` after `sanitize_settings_recursively` → `apply_filters('wppo_settings_sanitize', $sanitized, $tab)`) + before `update_option` → `apply_filters('wppo_settings_before_update', bool $should_update, $tab, $sanitized, $old)` (return `false` → `WP_Error 400`) + after `update_option` → `do_action('wppo_settings_after_update', $tab, $sanitized, $old)`; mirror in `class-wppo-cli-command.php:573` (`settings update` + `import`) |
| **Action/Filter** | `apply_filters( 'wppo_settings_sanitize', array $sanitized, string $tab )`; `apply_filters( 'wppo_settings_before_update', bool $should_update, string $tab, array $sanitized, array $old )`; `do_action( 'wppo_settings_after_update', string $tab, array $sanitized, array $old )` |
| **Arguments** | `bool $should_update`, `string $tab`, `array $sanitized`, `array $old` (previous `wppo_settings`) |
| **Expected Consumers** | Enterprise policy MU plugins, audit log enrichers, feature-flag systems (LaunchDarkly) |
| **Perf Impact** | `none` — admin/CLI/REST only |
| **Compat Impact** | `none` — default `true`/`$sanitized`; must not throw when `should_update=false` (return `WP_Error`) |
| **Priority** | **P1** |

---

### G-21 — REST route extensibility

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `get_routes()` (`class-rest.php:58`) returns hard-coded 25-route array; no filter to add custom routes under same namespace/capability/nonce gate or to override `permission_callback` for SSO |
| **Why Needed** | Agencies need custom endpoints (`/performance-optimisation/v1/export-ccss`, `/purge-external-cdn`) that share the same `manage_options + X-WP-Nonce` gate without registering a second namespace |
| **Best Location** | `includes/class-rest.php:58` (`get_routes` return → `apply_filters('wppo_rest_routes', $routes)`) + `357` (`permission_callback` inside → `apply_filters('wppo_rest_permission', bool $allowed, string $route, WP_REST_Request $request)`) + add `do_action('wppo_rest_pre_dispatch', $request)` at top of each handler via `rest_pre_dispatch` pattern |
| **Action/Filter** | `apply_filters( 'wppo_rest_routes', array $routes )`; `apply_filters( 'wppo_rest_permission', bool $allowed, string $route, WP_REST_Request $request )`; `do_action( 'wppo_rest_pre_dispatch', WP_REST_Request $request )` |
| **Arguments** | `array $routes` (`route => [methods,callback,permission_callback,schema]`), `bool $allowed` (from `current_user_can('manage_options') && wp_verify_nonce`), `string $route`, `WP_REST_Request $request` |
| **Expected Consumers** | Agency custom endpoints, SSO (SAML/OIDC) permission bridges, API audit log |
| **Perf Impact** | `none` — REST dispatch only; not front-end |
| **Compat Impact** | `none` — additive; `wppo_rest_permission` default `true` when `manage_options+nonce` passes; returning `false` blocks route (security-sensitive — document must-not-return-true-for-anon) |
| **Priority** | **P1** |

---

### G-22 — CLI parity & lifecycle

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `get_redis_config_from_assoc()` allowlists 6 keys (`host,port,password,database,timeout,prefix` at `class-wppo-cli-command.php:864`) vs REST 10 keys; no `wppo_cli_*` filter to bridge parity; no `before/after` actions per subcommand for scripting |
| **Why Needed** | CLI is the only path for cluster/sentinel hosts; without parity, `wp wppo object-cache enable --nodes=...` silently drops keys. Actions allow `wp wppo cache clear && wp cache flush && wp rocket purge` orchestration via hook. |
| **Best Location** | `includes/class-wppo-cli-command.php:864` (`get_redis_config_from_assoc` return → `apply_filters('wppo_cli_redis_config', $config, $assoc_args)`), `:75` (`cache` method entry → `apply_filters('wppo_cli_should_run', true, 'cache', $action, $assoc_args)` + exit → `do_action('wppo_cli_after_command', 'cache', $action, $result)`) — repeat for `database`, `image`, `object_cache` |
| **Action/Filter** | `apply_filters( 'wppo_cli_redis_config', array $config, array $assoc_args )`; `apply_filters( 'wppo_cli_should_run', bool $should, string $subcommand, string $action, array $assoc_args )`; `do_action( 'wppo_cli_after_command', string $subcommand, string $action, mixed $result )` |
| **Arguments** | `array $config`, `array $assoc_args`, `bool $should`, `string $subcommand` (`cache|database|image|settings|object-cache|pagespeed|system-info`), `string $action`, `mixed $result` |
| **Expected Consumers** | Cluster operators, deployment scripts (Deployer, Capistrano), CI (`wp wppo system-info --format=json` consumers) |
| **Perf Impact** | `none` — CLI only |
| **Compat Impact** | `none` — additive; `should_run=false` skips command (must `WP_CLI::warning`) |
| **Priority** | **P1** (parity), **P2** (lifecycle) |

---

### G-23 — Cron scheduling

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `add_custom_cron_interval` (`class-cron.php:99`) and `schedule_cron_jobs` (`:114`) hard-code `every_5_hours = 5*3600`, `hourly`, `daily`, etc.; no filter to adjust interval per environment (e.g. `every_1_hour` on staging) |
| **Why Needed** | Staging needs faster preload/used-CSS cycles; production with 50k posts needs `every_5_hours` → `every_12_hours` to avoid stampede |
| **Best Location** | `includes/class-cron.php:99` (`$schedules['every_5_hours'] = [...]` → `apply_filters('wppo_cron_interval', 5*3600, 'every_5_hours')`), `:114` per `wp_schedule_event` interval → `apply_filters('wppo_cron_schedules', array $schedules, array $options)`, add `do_action('wppo_cron_before_schedule', $hook, $schedule)` before each `wp_schedule_event` |
| **Action/Filter** | `apply_filters( 'wppo_cron_interval', int $seconds, string $hook )`; `apply_filters( 'wppo_cron_schedules', array $schedules, array $options )`; `do_action( 'wppo_cron_before_schedule', string $hook, string $schedule )` |
| **Arguments** | `int $seconds`, `string $hook` (`wppo_page_cron_hook|wppo_img_conversion|...`), `array $schedules` (`hook => interval`), `array $options` (`wppo_settings`) |
| **Expected Consumers** | Staging/production parity, large catalog hosts, monitoring (cron lag alert) |
| **Perf Impact** | `none` — `init` only; schedule check is `wp_next_scheduled` (1 option read) |
| **Compat Impact** | `none` — defaults preserve 5h/hourly/daily; changing interval does not retro-clear old schedule (caller must `wp_clear_scheduled_hook`) |
| **Priority** | **P2** |

---

### G-24 — Admin SPA data

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `admin_enqueue_scripts` (`class-main.php:494` → `Main::admin_enqueue_scripts` ≈ line 2200) localizes `wppoSettings` (apiUrl, nonce, settings, translations, themeColors) with no filter to mutate or to short-circuit enqueue on non-WPPO admin pages |
| **Why Needed** | White-label agencies need to inject `wppoSettings.whiteLabel` or override `translations`; multisite network-admin needs to suppress per-site SPA on network pages |
| **Best Location** | `includes/class-main.php:494` (`admin_enqueue_scripts` method — after `wp_localize_script('wppo-settings', 'wppoSettings', $data)` build, before `wp_enqueue_script`) |
| **Action/Filter** | `apply_filters( 'wppo_admin_localize_data', array $data )`; `apply_filters( 'wppo_admin_should_enqueue', bool $should, string $hook_suffix )` |
| **Arguments** | `array $data` (`apiUrl, nonce, settings, translations, themeColors, allowedSettingsKeys, ...`), `bool $should`, `string $hook_suffix` (`settings_page_performance-optimisation`) |
| **Expected Consumers** | White-label plugins, translation overrides, network-admin suppressors |
| **Perf Impact** | `none` — admin only |
| **Compat Impact** | `none` — default `true`/`$data`; must return array for `wp_localize_script` |
| **Priority** | **P2** |

---

### G-25 — Frontend head output (preload / speculation / hints)

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `add_preload_prefetch_preconnect()` (`class-main.php:758` prio 1), `add_speculation_rules()` (`:759` prio 0), `add_resource_hints()` (`:760` via `wp_resource_hints`) emit directly with no output filter |
| **Why Needed** | Agencies need to inject additional preloads (e.g. `fetch` for headless API), or suppress speculation rules on checkout (`/checkout/*` must never prefetch) without disabling `enableSpeculationRules` globally |
| **Best Location** | `includes/class-main.php:758` (after building `$preload_links` array, before `Util::generate_preload_link` loop → `apply_filters('wppo_preload_links', $links, $url)`), `759` (`$rules` array → `apply_filters('wppo_speculation_rules_output', $rules, $url)`), `760` (`$urls, $relation_type` → `apply_filters('wppo_resource_hints_output', $urls, $relation_type)`) |
| **Action/Filter** | `apply_filters( 'wppo_preload_links', array $links, string $url )`; `apply_filters( 'wppo_speculation_rules_output', array $rules, string $url )`; `apply_filters( 'wppo_resource_hints_output', array $urls, string $relation_type )` |
| **Arguments** | `array $links` (`[ ['href','as','type','media','fetchpriority'] ]`), `array $rules` (speculation JSON), `array $urls`, `string $relation_type` (`dns-prefetch|preconnect`), `string $url` |
| **Expected Consumers** | Headless, Woo checkout suppressors, CSP agencies |
| **Perf Impact** | `low` — one filter per `wp_head` (front-end only) |
| **Compat Impact** | `none` — defaults preserve current output; empty return suppresses that hint class |
| **Priority** | **P2** |

---

### G-26 — LiteSpeed header semantics (public alias)

| Field | Value |
|-------|-------|
| **Missing Extension Point** | LiteSpeed TTL/cacheable/vary/tag filters are documented but `wppo_litespeed_cache_control` style header injection has only internal `do_action('litespeed_control_set_ttl/nocache')` with no public `wppo_*` filter alias, forcing `has_action('litespeed_control_set_ttl')` boilerplate |
| **Why Needed** | Non-LiteSpeed hosts that emulate `X-LiteSpeed-*` (Bunny, Cloudflare edge) need public filter to set TTL without LiteSpeed plugin present |
| **Best Location** | `includes/class-litespeed-integration.php:958` (`cache_control_header` literal → `apply_filters('wppo_litespeed_cache_control', $header, $ttl)`) + `706` (`get_ttl` → already has `wppo_litespeed_ttl`; add `wppo_litespeed_cache_control_header` alias documented) |
| **Action/Filter** | `apply_filters( 'wppo_litespeed_cache_control_header', string $header, int $ttl )`; `apply_filters( 'wppo_litespeed_nocache_reason', string $reason )` (already present at `:987` but undocumented — document it) |
| **Arguments** | `string $header` (`X-LiteSpeed-Cache-Control: public, max-age=...`), `int $ttl`, `string $reason` |
| **Expected Consumers** | Bunny/Cloudflare edge adapters, LiteSpeed-adjacent hosts |
| **Perf Impact** | `none` — `send_headers` only when LiteSpeed or edge mode |
| **Compat Impact** | `none` — alias; existing `wppo_litespeed_ttl` remains canonical |
| **Priority** | **P3** |

---

### G-27 — CDN / Edge purge targeting

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `CDN_Purger::purge_all()` and `Edge_Purger::purge_all()` (`class-main.php:623,626` → `wppo_after_cache_clear` prio 10/20) purge all with no URL list or per-purge veto; single-page clear still purges all CDN URLs |
| **Why Needed** | Single-page invalidation should purge single CDN URL (cost: Cloudflare purge-by-URL vs purge-all). Current `wppo_after_cache_clear` passes `$type/$url_path` but purgers ignore it and purge all. |
| **Best Location** | `includes/class-cdn-purger.php:193` (`varnish_purge_max_urls` filter site) + `includes/class-edge-purger.php:212` (`purge_all` entry) + `includes/class-cache.php:2074` (`do_action('wppo_after_cache_clear', $type, $url_path)` consumers) |
| **Action/Filter** | `apply_filters( 'wppo_purge_urls', array $urls, string $type, ?string $url_path )` — purgers should respect; `apply_filters( 'wppo_should_purge_cdn', bool $should, string $type, ?string $url_path )`; `apply_filters( 'wppo_should_purge_edge', bool $should, string $type, ?string $url_path )` |
| **Arguments** | `array $urls` (absolute URLs to purge), `bool $should`, `string $type` (`all|single_page`), `?string $url_path` |
| **Expected Consumers** | Cloudflare APO, Bunny, Varnish, Sucuri |
| **Perf Impact** | `none` — on cache clear only (admin/cron/invalidation) |
| **Compat Impact** | `none` — default `true` + `$urls = all` when `type=all`; single-page purgers can narrow to one URL |
| **Priority** | **P1** |

---

### G-28 — Optimisation gate per user (P0 — logged-in split)

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `should_optimise_for_logged_in()` (`class-main.php:369`) and `is_cache_allowed_for_current_user()` (`class-cache.php:297` → `Util::is_cache_eligible_for_current_user`) gate optimisations (lazy, defer, minify, used-CSS) and caching on `enableLoggedInCache` + `loggedInCacheRoles` with no per-request filter |
| **Why Needed** | Membership sites need `editor` to see uncached/optimised preview, `subscriber` to see cached; no way to exempt `shop_manager` from minify (Woo session cart) without forking `is_cache_eligible_for_current_user` |
| **Best Location** | `includes/class-cache.php:297` (`is_cache_allowed_for_current_user` return → `apply_filters('wppo_should_cache_for_user', $allowed, WP_User $user)`) + `includes/class-main.php:369` (`should_optimise_for_logged_in` return → `apply_filters('wppo_should_optimise_for_user', $should, WP_User $user)`) |
| **Action/Filter** | `apply_filters( 'wppo_should_cache_for_user', bool $allowed, WP_User $user )`; `apply_filters( 'wppo_should_optimise_for_user', bool $should, WP_User $user )` |
| **Arguments** | `bool $allowed/should` (from role allowlist), `WP_User $user` (current user, or `WP_User` with empty roles for anon) |
| **Expected Consumers** | Membership (Paid Memberships Pro), Woo (shop_manager), preview plugins |
| **Perf Impact** | `low` — per front-end request but single `apply_filters` |
| **Compat Impact** | `none` — default preserves role allowlist; returning `false` bypasses cache/optimisation for that user (must not leak role hash) |
| **Priority** | **P0** |

---

### G-29 — Block assets separate toggle (alias)

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `register_block_assets_filters()` (`class-main.php:821`) hard-codes `should_load_separate_core_block_assets → __return_false` when `blockAssetsOnDemand` off; no public filter alias beyond core's `should_load_separate_core_block_assets` |
| **Why Needed** | Agencies that manage block assets via theme.json need `wppo_*` alias for discoverability (`grep wppo_`); also need to force separate on even when plugin disables it |
| **Best Location** | `includes/class-main.php:821` (`register_block_assets_filters` before `add_filter('should_load_separate_core_block_assets', ...)`) |
| **Action/Filter** | `apply_filters( 'wppo_block_assets_should_load_separate', bool $should, bool $is_block_theme )` — when `false`, register `__return_false` at prio 10; document that core filter remains canonical |
| **Arguments** | `bool $should` (default from `blockAssetsOnDemand` + `loadAllCoreBlockAssets` + `wp_is_block_theme()` logic), `bool $is_block_theme` |
| **Expected Consumers** | Block-theme agencies, `theme.json` heavy sites |
| **Perf Impact** | `none` — `setup_hooks` once |
| **Compat Impact** | `none` — alias |
| **Priority** | **P3** |

---

### G-30 — HTML processor gate

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `should_use_html_processor()` (`class-image-optimisation.php:427`) checks `WP_HTML_Processor::serialize_token` with no filter; regex fallback is kept for <6.4 but no way to force regex for debugging or to inject custom processor |
| **Why Needed** | Debugging malformed `<picture>` handling; benchmark TagProcessor vs regex; custom processors (e.g. Masterminds HTML5 parser) |
| **Best Location** | `includes/class-image-optimisation.php:427` (`should_use_html_processor` return) |
| **Action/Filter** | `apply_filters( 'wppo_image_use_html_processor', bool $use, string $buffer )` |
| **Arguments** | `bool $use` (default `class_exists(WP_HTML_Processor) && method_exists(..., 'serialize_token')`), `string $buffer` |
| **Expected Consumers** | Debug, benchmark, custom HTML parsers |
| **Perf Impact** | `none` — per buffer |
| **Compat Impact** | `none` — default `true` when available; `false` forces regex fallback (existing codepath) |
| **Priority** | **P3** |

---

### G-31 — Core tweaks per-feature gate (filter alias)

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `Core_Tweaks::__construct` (`class-core-tweaks.php:30`) gates each `add_action/add_filter` on `file_optimisation.disable*` settings but no public `wppo_*` filter to veto a single tweak without toggling the setting (e.g. force-enable `disableEmojis` even when setting off, for MU) |
| **Why Needed** | MU plugins that enforce `disableEmojis` site-wide without persisting to `wppo_settings` (which is site-specific and writable via REST) |
| **Best Location** | `includes/class-core-tweaks.php:30` — each gate `if (!empty($opts['disableEmojis']))` → `if (apply_filters('wppo_core_tweak_enabled', !empty($opts['disableEmojis']), 'emojis'))` |
| **Action/Filter** | `apply_filters( 'wppo_core_tweak_enabled', bool $enabled, string $tweak )` where `$tweak` in `emojis|embeds|dashicons|xmlrpc|heartbeat|rest_links|feeds|shortlink|generator|jquery_migrate|password_strength|self_pingbacks` |
| **Arguments** | `bool $enabled` (from setting), `string $tweak` |
| **Expected Consumers** | MU plugins, hardening plugins (Sucuri, Wordfence), performance agencies |
| **Perf Impact** | `none` — `__construct` once (but `Core_Tweaks` instantiated on every request via `Main::__construct:356`) |
| **Compat Impact** | `none` — default preserves setting; `true` forces tweak even when setting off |
| **Priority** | **P2** |

---

### G-32 — Observability: log level + structured context

| Field | Value |
|-------|-------|
| **Missing Extension Point** | `wppo_debug_log` (`class-cache.php:282`, `class-cdn-purger.php:235`, etc.) is unconnected (no prod consumer); `Log::add()` writes to `wppo_activity_logs` with no level/context filter |
| **Why Needed** | APM needs structured logs (`level=warning`, `context={url, handle}`) and sampling; `wppo_debug_log` currently fires with 1-2 args but no level, and no way to suppress noisy `domain invalid` log on valid IDN domains |
| **Best Location** | `includes/class-cache.php:282` (`do_action('wppo_debug_log', $msg)` → `apply_filters('wppo_debug_log_level', 'debug', $msg, $ctx)` + `do_action('wppo_log_message', $msg, $level, $ctx)`), `includes/class-log.php` (`Log::add` entry) |
| **Action/Filter** | `apply_filters( 'wppo_debug_log_level', string $level, string $msg, array $ctx )` (`debug|info|warning|error`); `do_action( 'wppo_log_message', string $msg, string $level, array $ctx )` (post-`Log::add`); `apply_filters( 'wppo_should_log', bool $should, string $msg, string $level )` |
| **Arguments** | `string $msg`, `string $level`, `array $ctx` (`url`, `handle`, `type`, `size`) |
| **Expected Consumers** | APM (Sentry, New Relic), Query Monitor, log aggregators (Papertrail) |
| **Perf Impact** | `none` — debug path only (`WP_DEBUG` or `wppo_settings.debug`) |
| **Compat Impact** | `none` — defaults preserve current `Log::add` behavior; `should_log=false` suppresses row |
| **Priority** | **P2** |

---

## 3. Design Guidance (for future implementation)

1. **Defaults must preserve current behavior.** Every new `apply_filters` defaults to the pre-filter value; every new `do_action` is additive. No gap requires a breaking change.
2. **Document or mark private.** Existing undocumented `apply_filters` (`wppo_filesize_limit_bytes`, `wppo_max_dimensions`, `wppo_convert_gain_map_images`, `wppo_cron_discovery_limit`, `wppo_server_timing_enabled`, `wppo_object_cache_dropin_path`, `wppo_redis_allow_request_password`, `wppo_telemetry_verify_ssl`, `wppo_ccss_*`, `wppo_video_*`) should either be added to `docs/hooks.md` or annotated `/** @internal */` — drift already flagged in `HOOK-AUDIT.md §15`.
3. **Clamp tuned values.** `batch_size`, `max_bytes`, `max_days`, `queue_max` filters must `max(1, min(...))` to avoid unbounded growth / `lock_wait_timeout` / option bloat.
4. **No new `add_action` registrations by default.** All gaps are `apply_filters`/`do_action` firing sites; no new `add_action('wp_*')` listeners — keeps hook table lean.
5. **`@since NEXT` for all new hooks.** Version placeholder per `AGENTS.md` PHP conventions.
6. **Naming:** `wppo_should_*` for predicates, `wppo_*_should_*` for vetoes, `wppo_before_*`/`wppo_after_*` for lifecycle, `wppo_*_config` for config mutation — matches existing `wppo_should_cache_request` (proposed) vs `wppo_cache_page_html`.

---

## 4. Verification

- [x] Read `includes/class-cache.php:282,402,980,1047,1143,1243,1424,1487-1838,2032,2074` (cache gen/inval, combine, minify, CDN, buffer)
- [x] Read `includes/class-main.php:369,472,485-799,1032,2747,2849` (setup_hooks, optimization gate, asset handling, settings update)
- [x] Read `includes/class-image-optimisation.php:185-2769` (lazy, picture, LQIP, video)
- [x] Read `includes/class-img-converter.php:319,360,402,422,725,776,1561` (conversion, limits, placeholder)
- [x] Read `includes/class-database-cleanup.php:138,714,737,753,1040` (per-type gap, batch, optimize)
- [x] Read `includes/class-rum.php:36,49,121,189,275` (collect, enqueue, rate limit, retention)
- [x] Read `includes/class-object-cache.php:67,252,325,356` (drop-in path, enable/disable/flush)
- [x] Read `includes/class-cron.php:49,99,114,283,301,364,487,496,666` (batch, sitemap cap, deadline, discovery limit)
- [x] Read `includes/class-rest.php:58,357,464` (routes, permission, update_settings)
- [x] Read `includes/class-wppo-cli-command.php:75,864` (CLI allowlist parity)
- [x] Read `includes/class-util.php:877` (sanitize), `class-cdn-purger.php:193`, `class-edge-purger.php:212`, `class-core-tweaks.php:30`
- [x] Cross-checked `HOOK-AUDIT.md` (272 hits, 42 docs/hooks.md, drift list) — no duplicate proposals for already-public hooks
- [x] No production code modified — research-only

---

*Research-only. For hook inventory see `HOOK-AUDIT.md` §1-17. For CLI parity see `WP-CLI-CURRENT.md`.*
