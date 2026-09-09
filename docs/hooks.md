# Developer Hooks & Filters Reference Guide

The **Performance Optimisation** WordPress plugin provides action hooks and filter hooks allowing developers, agency teams, and custom themes to extend, customize, and override core performance behaviors.

---

## ⚓ Action Hooks

### `wppo_before_cache_clear`
Fires immediately before the static HTML page cache is cleared.

**Parameters:**
- `$type` *(string)* — Type of cache clear (`'all'` or `'single_page'`).
- `$url_path` *(string|null)* — Relative URL path being cleared (if `$type` is `'single_page'`).

**Example:**
```php
add_action( 'wppo_before_cache_clear', function( $type, $url_path ) {
    error_log( "Preparing to clear static cache: {$type} - Path: {$url_path}" );
}, 10, 2 );
```

---

### `wppo_after_cache_clear`
Fires immediately after the static HTML page cache has been successfully cleared.

**Parameters:**
- `$type` *(string)* — Type of cache clear (`'all'` or `'single_page'`).
- `$url_path` *(string|null)* — Relative URL path cleared (if `$type` is `'single_page'`).

**Example:**
```php
add_action( 'wppo_after_cache_clear', function( $type, $url_path ) {
    // Notify external CDN or edge proxy (e.g. Cloudflare)
    if ( 'all' === $type ) {
        purge_external_cdn_cache();
    }
}, 10, 2 );
```

---

### `wppo_after_builder_purge`
Fires after WPPO purges builder + page caches for a page-builder update (issue #907). The watcher (`Builder_Purge_Watcher`, hooked to `upgrader_process_complete`) self-gates to Elementor/Divi/Bricks/WPBakery slugs, deletes the builders' regenerable CSS directories, clears the page cache + used-CSS + critical-CSS, writes an audit log entry, and stages a one-time admin notice. @since NEXT.

**Parameters:**
- `$matched` *(string[])* — Matched builder keys (e.g. `array( 'elementor' )`).

**Example:**
```php
add_action( 'wppo_after_builder_purge', function( $matched ) {
    if ( in_array( 'elementor', $matched, true ) ) {
        // e.g. warm the homepage so the regenerated CSS is fresh.
        wp_remote_get( home_url( '/' ) );
    }
} );
```

---

### `wppo_database_cleanup_completed`
Fires after a database cleanup operation completes. Since NEXT, also fires per-type after each individual cleanup (before the `all` aggregate). @since NEXT for per-type.

**Parameters:**
- `$type` *(string)* — Cleanup type (`'all'`, `'revisions'`, `'auto_drafts'`, `'trashed_posts'`, `'spam_comments'`, `'trashed_comments'`, `'expired_transients'`, `'orphan_postmeta'`, `'unattached_media'`, `'oembed_cache'`).
- `$count` *(int)* — Total number of database rows deleted (or per-type count).
- `$results` *(array|null)* — Detailed per-type cleanup counts when `$type` is `'all'`.

**Example:**
```php
add_action( 'wppo_database_cleanup_completed', function( $type, $count ) {
    error_log( "Performance Optimisation DB Cleanup ({$type}): {$count} rows removed." );
}, 10, 2 );
```

---

### `wppo_should_cache_request`
Filters whether the current request should be cached. Placed **after** the `DONOTCACHEPAGE` constant check so the constant always wins even if the filter returns true. Return `false` to skip `ob_start` and cache storage. @since NEXT.

**Parameters:**
- `$should` *(bool)* — Whether the request should be cached. Default `true`.
- `$request_uri` *(string)* — The request URI.
- `$is_mobile` *(bool)* — Whether the request is from a mobile device (`wp_is_mobile()`).
- `$is_logged_in` *(bool)* — Whether the user is logged in.

**Example:**
```php
add_filter( 'wppo_should_cache_request', function( $should, $request_uri, $is_mobile, $is_logged_in ) {
    if ( false !== strpos( $request_uri, '/members/' ) ) {
        return false; // Membership area: never cache
    }
    return $should;
}, 10, 4 );
```

**WooCommerce cookie behavior note (issue #907):** only cart-content cookies (`woocommerce_items_in_cart`, `woocommerce_cart_hash`) bypass the cache; currency-switcher cookies (`WOOCS` / `wmc-current-currency`, Aelia, …) intentionally do **not** vary or bypass — there is no per-currency segmentation today. Currency vary is a future M-sized item. WooCommerce AJAX endpoints (`?wc-ajax=…`, `/wc-ajax/…`) are always excluded from serving (the `advanced-cache.php` drop-in returns early pre-boot), buffering, and storage.

**WooCommerce safe mode (issue #922):** when `cache_settings.wooSafeMode` is `true` (default), `cart`/`checkout`/`my-account` endpoints (plus any configured custom WooCommerce page slugs), `wc-ajax` and `?add-to-cart` requests, and Woo session cookies (`wp_woocommerce_session_*` + cart fragments) are never served as cache HIT. The per-URL override below (`wppo_woo_cacheable`) applies only after WordPress boots (Cache layer); the pre-boot `advanced-cache.php` drop-in cannot run the filter and instead bakes the toggle and the configured Woo paths in at generation time — disabling safe mode (`wooSafeMode => false` via `wppo_settings`, e.g. `wp wppo settings` or import) regenerates the drop-in with only the pre-#922 guards, restoring master behaviour.

---

### `wppo_invalidation_urls`
Filters the list of URL paths to purge when a post is invalidated. Merges filesystem and CDN purge; sanitized via `wp_normalize_path` + deduped before deletion. @since NEXT.

**Parameters:**
- `$urls` *(string[])* — List of URL paths to purge (relative, e.g. `'/about/'`, `'/'`).
- `$post_id` *(int)* — The post ID being invalidated.

**Example:**
```php
add_filter( 'wppo_invalidation_urls', function( $urls, $post_id ) {
    $urls[] = '/feed/';
    return $urls;
}, 10, 2 );
```

---

### `wppo_object_cache_config`
Filters the Redis object cache configuration after merging Dashboard settings with on-disk config (and before connection in `ping`/`enable`). @since NEXT.

**Parameters:**
- `$config` *(array)* — Redis configuration (`mode`, `host`, `port`, `password`, `database`, `timeout`, `prefix`, `nodes`, `master_name`, `use_tls`, `persistent`, `compression`).

**Example:**
```php
add_filter( 'wppo_object_cache_config', function( $config ) {
    $config['timeout'] = 2;
    return $config;
} );
```

---

### `wppo_object_cache_circuit_breaker_threshold`
Filters how many counted Redis failures trip the object-cache circuit breaker (auto-disable the drop-in). Readable at early boot: the `WPPO_CB_THRESHOLD` constant wins when defined, otherwise this filter, otherwise `5`. Only auth/connection-class errors count (`auth_fail`, `conn_fail`, `sentinel_fail`, `cluster_fail`, `select_fail`, boot exceptions, write failures) — environment/config codes never trip the breaker. @since NEXT.

**Parameters:**
- `$threshold` *(int)* — Consecutive counted failures within the window that trip the breaker. Default `5`, minimum `1`.

**Example:**
```php
add_filter( 'wppo_object_cache_circuit_breaker_threshold', function() {
    return 3; // Trip sooner on fragile infrastructure.
} );
```

---

### `wppo_object_cache_circuit_breaker_window`
Filters the counting window in seconds in which threshold failures must occur to trip the object-cache circuit breaker. Readable at early boot: the `WPPO_CB_WINDOW` constant wins when defined, otherwise this filter, otherwise `600` (10 minutes). Failures older than the window restart the counter instead of tripping. @since NEXT.

**Parameters:**
- `$window` *(int)* — Window in seconds. Default `600`, minimum `1`.

**Example:**
```php
add_filter( 'wppo_object_cache_circuit_breaker_window', function() {
    return 300; // Count failures over the last 5 minutes.
} );
```

---

### `wppo_object_cache_probe_interval`
Filters the recovery-probe interval in seconds while the object-cache circuit is open (the `wppo_object_cache_probe` cron recurrence). Default `HOUR_IN_SECONDS`, minimum `300`. The probe is scheduled only while the circuit is open and cleared on recovery. @since NEXT.

**Parameters:**
- `$interval` *(int)* — Seconds between recovery probes. Default `3600`, minimum `300`.

**Example:**
```php
add_filter( 'wppo_object_cache_probe_interval', function() {
    return 900; // Re-check Redis every 15 minutes while down.
} );
```

---

## 🎛️ Filter Hooks

### `wppo_woo_cacheable`
Filters whether a Woo-excluded request should be re-allowed as cacheable (issue #922). Guarded by `has_filter()` — the filter is only applied when a listener is present. Return `true` to re-allow caching for that URL; default `false` (not cacheable). Runs inside `Cache::is_woo_excluded()` after any Woo exclusion is detected. Override applies only after WordPress boots (Cache layer); the pre-boot drop-in remains unconditional — it bakes the `wooSafeMode` toggle and configured Woo paths at generation time and cannot run this filter. @since NEXT.

**Parameters:**
- `$cacheable` *(bool)* — Whether the Woo request should be re-allowed as cacheable. Default `false`.
- `$request_uri` *(string)* — The request URI.

**Example:**
```php
add_filter( 'wppo_woo_cacheable', function( $cacheable, $request_uri ) {
    if ( false !== strpos( $request_uri, '/my-account/custom-public/' ) ) {
        return true; // Re-allow caching for a custom public account sub-page.
    }
    return $cacheable;
}, 10, 2 );
```

---

### `wppo_builder_purge_map`
Filters the builder-update purge map used by the watcher (`Builder_Purge_Watcher::get_builder_map()`, issue #907). Lets hosts and themes add builders or correct slugs and cache directories. @since NEXT.

**Parameters:**
- `$map` *(array)* — Builder map keyed by builder slug. Each entry: `label` (string), `plugins` (plugin-file slugs), `themes` (theme directory slugs), `upload_subdirs` (cache dirs relative to the uploads basedir), `content_subdirs` (cache dirs relative to `WP_CONTENT_DIR`), `clear_hooks` (builder-native actions fired best-effort when a listener exists), `css_only` (bool — when true, only top-level `*.css` files are removed instead of the whole directory; used for Bricks/WPBakery whose roots may hold non-regenerable files).

**Example:**
```php
add_filter( 'wppo_builder_purge_map', function( $map ) {
    $map['oxygen'] = array(
        'label'           => 'Oxygen',
        'plugins'         => array( 'oxygen/functions.php' ),
        'themes'          => array(),
        'upload_subdirs'  => array( 'oxygen/css' ),
        'content_subdirs' => array(),
        'clear_hooks'     => array(),
        // Set css_only => true when the directory may hold non-regenerable
        // files (only top-level *.css files are then removed).
        'css_only'        => true,
    );
    return $map;
} );
```

---

### `wppo_exclude_delay_js`
Filters the list of script handles or URL substrings excluded from JavaScript delay loading.

**Parameters:**
- `$exclusions` *(array)* — Array of excluded script handles/URLs.

**Example:**
```php
add_filter( 'wppo_exclude_delay_js', function( $exclusions ) {
    $exclusions[] = 'my-critical-script-handle';
    $exclusions[] = 'checkout-tracking.js';
    return $exclusions;
} );
```

---

### `wppo_delay_js_allowed_hosts`
Filters the allowlist of additional remote hosts the lazyload bundle may load deferred external scripts from. Delay-JS hydration only executes a deferred script when its `wppo-src` URL uses http(s) and points at the same origin or an allowlisted host (a built-in list of common analytics/marketing/utility CDNs ships in `src/lazyload.js`). Hosts added here are mirrored to the client as `wppoDelayConfig.allowedScriptHosts`. @since NEXT.

**Parameters:**
- `$hosts` *(string[])* — Array of hostnames (base domains include subdomains, e.g. `googletagmanager.com`).

**Example:**
```php
add_filter( 'wppo_delay_js_allowed_hosts', function( array $hosts ): array {
    $hosts[] = 'cdn.my-portal.example';
    return $hosts;
} );
```

---

### `wppo_esi_allowed_html`
Filters the wp_kses allowlist applied to ESI fragment HTML returned by the `wppo_esi_fragment` admin-ajax endpoint (`LiteSpeed_ESI::handle_ajax_fragment()`). The fragment is inserted into the page DOM by `src/esi.js`, so this allowlist is the server-side sanitization contract: every tag/attribute a custom ESI widget needs must be present here. Script-capable tags must never be added. @since NEXT.

**Parameters:**
- `$tags` *(array)* — Allowed tags => attributes map in `wp_kses()` shape.

**Example:**
```php
add_filter( 'wppo_esi_allowed_html', function( array $tags ): array {
    $tags['mark'] = array( 'class' => true, 'id' => true, 'data-*' => true );
    return $tags;
} );
```

---

### `wppo_exclude_defer_js`
Filters the list of script handles or URL substrings excluded from JavaScript deferral.

**Parameters:**
- `$exclusions` *(array)* — Array of excluded script handles/URLs.

**Example:**
```php
add_filter( 'wppo_exclude_defer_js', function( $exclusions ) {
    $exclusions[] = 'jquery-core';
    return $exclusions;
} );
```

---

### `wppo_cve_guard_handles`
Filter-only (S scope) list of handle strings to auto-exclude from optimization when a CVE is known. Default empty (no auto-exclude). Merged with `array_unique` into `minify_js`/`minify_css` (`exclude_js`/`exclude_css`) and `exclude_defer_js`/`exclude_delay_js` inside `PerformanceOptimise\Inc\Main::setup_hooks()`; respects the existing `litespeed_can_optm` gate; no `wp_options` persistence and no cron. @since NEXT.

**Parameters:**
- `$handles` *(string[])* — Array of handle strings to exclude (e.g. `['vulnerable-slider']`).

**Example:**
```php
add_filter( 'wppo_cve_guard_handles', function( array $handles ): array {
    // Auto-exclude vulnerable handle when CVE-2026-XXXX is known.
    $handles[] = 'vulnerable-slider'; // vulnerable handle
    $handles[] = 'compromised-gallery';
    return $handles;
} );
```

---

### `wppo_cve_excluded_handles`
Backward-compatibility alias for `wppo_cve_guard_handles`. Same semantics; chained after the primary filter (`wppo_cve_guard_handles` → `wppo_cve_excluded_handles`). Prefer `wppo_cve_guard_handles`. @since NEXT.

**Parameters:**
- `$handles` *(string[])* — Array of handle strings to exclude.

**Example:**
```php
add_filter( 'wppo_cve_excluded_handles', function( array $handles ): array {
    $handles[] = 'vulnerable-slider';
    return $handles;
} );
```

---

### `wppo_exclude_minification`
Filters whether a specific CSS or JS file should be skipped during minification.

**Parameters:**
- `$exclude` *(bool)* — Default `false`. Return `true` to skip minification.
- `$file_path` *(string)* — Absolute local file path of the asset.
- `$handle` *(string)* — Registered script or style handle.
- `$type` *(string)* — Asset type (`'css'` or `'js'`).

**Example:**
```php
add_filter( 'wppo_exclude_minification', function( $exclude, $file_path, $handle, $type ) {
    if ( 'my-custom-slider' === $handle ) {
        return true; // Skip minification for this handle
    }
    return $exclude;
}, 10, 4 );
```

---

### `wppo_cache_page_html`
Filters the pre-rendered HTML content before it is saved to the static cache directory.

**Parameters:**
- `$html` *(string)* — Pre-rendered HTML string.
- `$url` *(string)* — Full URL of the page being cached.

**Example:**
```php
add_filter( 'wppo_cache_page_html', function( $html, $url ) {
    // Inject custom HTML signature comment
    return $html . "\n<!-- Cached by Performance Optimisation at " . date( 'c' ) . " -->";
}, 10, 2 );
```

---

### `wppo_lazyload_iframe_allowed`
Filters whether a specific `<iframe>` element should be processed for lazy loading.

**Parameters:**
- `$allowed` *(bool)* — Default `true`. Return `false` to disable lazy loading for this iframe.
- `$src` *(string)* — Source URL of the iframe.
- `$iframe_tag` *(string)* — Full raw HTML of the iframe tag.

**Example:**
```php
add_filter( 'wppo_lazyload_iframe_allowed', function( $allowed, $src, $iframe_tag ) {
    if ( false !== strpos( $src, 'google.com/maps' ) ) {
        return false; // Do not lazy load embedded Google Maps
    }
    return $allowed;
}, 10, 3 );
```

---

### `wppo_litespeed_is_litespeed`
Filters whether the current server is detected as LiteSpeed / OpenLiteSpeed. @since NEXT.

**Parameters:**
- `$is_litespeed` *(bool)* — Whether LiteSpeed was detected.

**Example:**
```php
add_filter( 'wppo_litespeed_is_litespeed', function( $is ) {
    return $is || isset( $_SERVER['HTTP_X_LITESPEED'] );
} );
```

---

### `wppo_litespeed_mode`
Filters the configured LiteSpeed integration mode (`auto|wppo|litespeed|standalone`). @since NEXT.

---

### `wppo_cache_ttl`
Tier-1 per-route LiteSpeed TTL override (LS layer only, file-cache stays global). Filter runs inside `LiteSpeed_Integration::get_litespeed_ttl()` / `handle_send_headers()` before `wppo_litespeed_ttl`; use it to vary `X-LiteSpeed-Cache-Control: public,max-age=N` per request without DB or `wppo_settings` schema change (drop-in-safe). Falls back to `url_to_postid( home_url( $uri ) )` and global `$post` when `$post_id` not explicitly passed; `null` when unresolvable. File-cache `cacheLife` constant untouched (as oracle warned: drop-in must not hit DB). @since NEXT.

**Parameters:**
- `$seconds` *(int)* — Global TTL seconds mapped from `cacheLife` (0→604800).
- `$request_uri` *(string)* — Sanitized request URI (e.g. `"/about/"`).
- `$post_id` *(int|null)* — Resolved post ID or `null` when unresolvable.

**Example:**
```php
add_filter( 'wppo_cache_ttl', function( $seconds, $uri, $post_id ) {
    if ( '/shop/' === $uri || 42 === $post_id ) {
        return 600; // 10 min for high-churn route
    }
    return $seconds;
}, 10, 3 );
```

---

### `wppo_litespeed_ttl`
Filters LiteSpeed TTL seconds mapped from `cacheLife` hours. File-cache `0` (never expire) maps to `604800` (1 week) for the LS server layer as an explicit policy change — LS cannot store infinite. Tier-1 adds third-arg `$context` (`array{uri:string,post_type:string|null,post_id:int|null}`) resolved without DB (REQUEST_URI + `url_to_postid` / `$post` fallback) so per-route TTL works in the `advanced-cache.php` drop-in; existing 2-arg callbacks remain compatible (extra arg ignored). `wppo_cache_ttl` runs first for LS-only overrides; this filter remains the final TTL gate. **Since N10-T2 (Tier-2) the same per-type resolution also reads `wppo_settings[cache_settings][ttlOverrides][post|page|product]` (hours `0/1/6/12/24/48/168`, default inherit global `cacheLife`, sanitized via `Util::sanitize_settings_recursively()` allowlist + `absint`, stored under `cache_settings` tab via `update_settings`). The settings override is applied **before** filters, so `wppo_cache_ttl` / `wppo_litespeed_ttl` still win; non-singular requests always fall back to global; file-cache `advanced-cache.php` constant stays untouched (LS-only).** @since NEXT.

**Parameters:**
- `$seconds` *(int)* — TTL in seconds (after `wppo_cache_ttl` when present).
- `$hours` *(int)* — Original `cacheLife` hours.
- `$context` *(array)* — Context `['uri' => string, 'post_type' => string|null, 'post_id' => int|null]` (since Tier-1).

**Example:**
```php
add_filter( 'wppo_litespeed_ttl', function( $seconds, $hours, $context ) {
    if ( 'product' === ( $context['post_type'] ?? null ) ) {
        return 300; // 5 min for products
    }
    return $hours === 0 ? 86400 : $seconds;
}, 10, 3 );
```

**Settings (N10-T2):** `wppo_settings[cache_settings][ttlOverrides]` stores per-type overrides for `post|page|product` as hours `0|1|6|12|24|48|168` (sanitized `absint` + allowlist, missing = inherit global). UI in Dashboard → Page Cache (LiteSpeed-only). Resolution: `get_post_type($post_id)` → `ttlOverrides[post_type]` → global `cacheLife`; `! is_singular()` → global. Filters above still apply after settings resolution. File-cache `advanced-cache.php` baked `cacheLife` untouched.

### `wppo_cache_ttl_overrides`
Filters sanitized `ttlOverrides` array after allowlist (`post|page|product` + `0/1/6/12/24/48/168`). @since NEXT.

---

### `wppo_litespeed_is_cacheable`
Filters whether the current request is considered cacheable for the LiteSpeed layer. @since NEXT.

---

### `wppo_litespeed_tag`
Filters the `X-LiteSpeed-Tag` value for WPPO pages (default `WPPO`). @since NEXT.

---

### `wppo_litespeed_vary`
Filters the `litespeed_vary` value after WPPO appends `wppo_role_hash` when logged-in cache is enabled. @since NEXT.

---

### `wppo_litespeed_vary_enabled`
Filters whether the LiteSpeed vary bridge (`wppo_role_hash` → `litespeed_vary`) is enabled. @since NEXT.

---

### `wppo_litespeed_strip_cache_control`
Filters whether generic `Cache-Control` is stripped when `X-LiteSpeed-Cache-Control: public` is sent (prevents conflict). @since NEXT.

---

### `wppo_litespeed_bypass_file_cache`
Filters whether the WPPO file cache is bypassed when LiteSpeed owns the cache (`is_litespeed && !is_wppo_cache_owner`). @since NEXT.

---

### `wppo_litespeed_nextgen_rewrite`
Filters whether next-gen Vary:Accept rewrite (LS-401/LS-402) is enabled. Gated by `is_litespeed && convertImg && enableNextGenRewrite` (htaccess) or `convertImg && enableNextGenRewrite` (nginx). Opt-in default false. @since NEXT.

---

### `wppo_litespeed_enable_nextgen_rewrite`
Legacy alias for `wppo_litespeed_nextgen_rewrite`. @since NEXT.

---

### `wppo_litespeed_brotli`
Filters whether Brotli `.br` generation (LS-403) is enabled. Requires `extension_loaded('brotli')` or `brotli_compress`. Opt-in via `enableBrotli` default false. @since NEXT.

---

### `wppo_litespeed_enable_brotli`
Legacy alias for `wppo_litespeed_brotli`. @since NEXT.

---

### `wppo_pagespeed_request_timeout`
Filters the PageSpeed Insights API request timeout in seconds (default `60`, clamped 5–300). Long values risk wedging the Action Scheduler worker; short values risk false timeouts (audit #888 finding 19). @since NEXT.

**Parameters:**
- `$timeout` *(int)* — Timeout in seconds.

---

### `wppo_google_fonts_backoff`
Filters the failure-backoff TTL in seconds for the Google Fonts fetch sentinel transients (`wppo_gf_fail_*`). When a Google Fonts CSS or font-file fetch fails, WPPO stores a short-lived sentinel so subsequent frontend requests skip the synchronous remote call until the sentinel expires (default `300`, clamped to a 60-second floor). @since NEXT.

**Parameters:**
- `$ttl` *(int)* — Backoff TTL in seconds.

---

### `wppo_pagespeed_retry_delay`
Filters the backoff delay in seconds before the single PageSpeed API retry on transport errors (default `2`, clamped 1–10). @since NEXT.

**Parameters:**
- `$retry_after` *(int)* — Delay in seconds.

---

### `wppo_litespeed_can_cdn`
Filters whether WPPO CDN rewriting is allowed. When `false`, `maybe_apply_cdn()` is skipped to avoid double CDN mapping when `litespeed_can_cdn` (LSCWP) is active. Respects `litespeed_can_cdn` ecosystem filter. @since NEXT.

---

### `wppo_cdn_mapping`
Filters CDN mapping array (one-to-many parity with LSCWP `cdn.cls.php:48`). Each entry keys `cdn_url|ori|ori_dir|include_dirs|include_filetypes|cdn_attr|cdn_urls`, capped at 5 (filter `wppo_cdn_mapping_max`). @since NEXT.

---

### `wppo_cdn_url`
Legacy alias for single CDN URL migration (`cdnURL` → `cdnMapping`). @since NEXT.

---

### `wppo_cdn_mapping_hosts`
Alias for CDN mapping hosts (round-robin `cdn_urls`/`cdns` per entry). @since NEXT.

---

### `wppo_cdn_mapping_entry`
Filters single CDN mapping entry post-sanitize. @since NEXT.

---

### `wppo_cdn_mapping_max`
Filters max CDN mappings (default 5). @since NEXT.

---

### `wppo_cdn_auto_filetypes`
Filters auto filetypes when `include_filetypes` empty. @since NEXT.

---

### `wppo_cdn_url_for_asset`
Filters CDN URL chosen for an asset (deterministic `crc32` round-robin when `cdn_urls` >1). @since NEXT.

---

### `wppo_cdn_buffer`
Filters CDN buffer after tag + inline `url()` rewrite (cooperates with `litespeed_buffer_finalize`). Constant `LITESPEED_BYPASS_CDN` bypasses all CDN rewrites. @since NEXT.

---

### `wppo_litespeed_swap_purge`
Filters whether OLS swap fallback purge should run (`find /tmp/lshttpd/swap -type f -delete` + `X-LiteSpeed-Purge`). @since NEXT.

---

### `wppo_litespeed_vary_groups`
Filters active Vary groups (`role, guest, mobile, webp`) before bridge. @since NEXT.

---

### `wppo_litespeed_vary_header`
Filters built X-LiteSpeed-Vary header (`cookie=wppo_role_hash,...`). @since NEXT.

---

### `wppo_litespeed_purge_tags`
Filters queued purge tags before transient store (`F,H,Po.{id},PT.{type},T.{id},A.{id},B.{id}` + scope). @since NEXT.

---

### `wppo_litespeed_purge_tag_string`
Filters flushed tag string (`tag=...`) on shutdown. @since NEXT.

---

### `wppo_crawler_concurrency`
Filters crawler concurrency 1-4 (default 2). @since NEXT.

---

### `wppo_crawler_blacklist_threshold`
Filters crawler BLACKLIST_THRESHOLD (default 3 mirroring `crawler.cls.php:26`). @since NEXT.

---

### `wppo_crawler_variants`
Filters variant matrix per URL (Accept webp/avif × mobile/desktop × guest/role). @since NEXT.

---

### `wppo_esi_available`
Filters whether ESI is available (Enterprise only, OLS has no ESI). @since NEXT.

---

### `wppo_esi_enabled`
Filters whether ESI bridge is enabled (settings `esi.enabled` + availability). @since NEXT.

---

### `wppo_esi_nonces`
Filters ESI nonce list for widget/cart hole-punching. @since NEXT.

---

### `wppo_esi_fallback`
Filters whether ESI AJAX fallback should run on OLS (`DONOTCACHEPAGE`). @since NEXT.

---

### `wppo_htaccess_rules`
Filters the full htaccess rules array before return. @since NEXT.

---

### `wppo_htaccess_nextgen_rules`
Filters the htaccess rules after next-gen block is appended. @since NEXT.

---

### `wppo_nginx_rules`
Filters the nginx rules string. @since NEXT.

---

### `wppo_nginx_nextgen_rules`
Filters the nginx rules array after next-gen map is appended. @since NEXT.

---

### `wppo_llms_txt_content`
Filters LLMs.txt markdown content before writing. @since NEXT.

**Parameters:**
- `$content` *(string)* — Markdown content.
- `$which` *(string)* — `llms` or `llms-full`.

**Example:**
```php
add_filter( 'wppo_llms_txt_content', function( $content, $which ) {
    return $content . "\n## Custom\n- https://example.com/custom/\n";
}, 10, 2 );
```

---

### `wppo_llms_txt_enabled`
Filters whether LLMs.txt is enabled. @since NEXT.

**Parameters:**
- `$enabled` *(bool)* — Whether enabled.

**Example:**
```php
add_filter( 'wppo_llms_txt_enabled', '__return_true' );
```

---

### `wppo_od_should_optimize`
Filters whether Optimization Detective (OD) optimization should be applied. @since NEXT.

When OD is available (`class_exists('OD_URL_Metric')` or `function_exists('od_get_url_metrics')`) and `od_integration.enabled` is true (auto true when OD active), the bridge consumes viewport groups (mobile/desktop LCP tag) to set `fetchpriority=high` for the LCP image and derives `excludeFirstImages` from measured data. Return `false` to degrade to the heuristic 1-3 fallback.

**Parameters:**
- `$should` *(bool)* — Whether OD optimization should be applied.
- `$current_url` *(string)* — Current URL (if resolvable).

**Example:**
```php
add_filter( 'wppo_od_should_optimize', function( $should, $url ) {
    if ( false !== strpos( $url, '/no-od/' ) ) {
        return false;
    }
    return $should;
}, 10, 2 );
```

---

### `wppo_bfcache_enabled`
Filters whether bfcache (Instant Back/Forward) is enabled. @since NEXT.

Privacy-safe session-token invalidation per Performance Lab Instant Back/Forward: a random token is mirrored in a `wordpress_bfcache_session_{COOKIEHASH}` cookie and embedded in the HTML; on `pageshow` with `persisted=true` (bfcache restore) and on immediate execution (HTTP cache) the tokens are compared and a stale page is cleared and reloaded. The `Cache-Control: no-store` directive is stripped for opted-in sessions and replaced with `private, no-cache, max-age=0, must-revalidate`. Gated by `bfcache.enabled` (false default).

**Parameters:**
- `$enabled` *(bool)* — Whether bfcache is enabled.

**Example:**
```php
add_filter( 'wppo_bfcache_enabled', '__return_true' );
```

---

### `wppo_perf_translations_enabled`
Filters whether Performant Translations (.mo→php) is enabled. @since NEXT.

When enabled and `wp_cache_get_salted` exists (WP 6.9+), `.mo` files are compiled to `.php` via the `load_textdomain_mofile` / `load_translation_file` filters using `WP_Translation_File::transform()` and stored per-locale under `wp-content/cache/wppo/lang/` (blog-scoped on multisite, e.g. `wp-content/cache/wppo/lang/site-2/my-plugin-de_DE-abc12345.l10n.php`). The cached file is served when newer than the source `.mo`; OPCache is invalidated on write. Toggle `perf_translations.enabled` defaults to `false`.

**Parameters:**
- `$enabled` *(bool)* — Whether .mo→php compilation is enabled.

**Example:**
```php
add_filter( 'wppo_perf_translations_enabled', '__return_true' );
```

---

### `wppo_ai_adaptive_enabled`
Filters whether AI Adaptive is enabled. @since NEXT.

**Parameters:**
- `$enabled` *(bool)* — Whether AI adaptive is enabled (from `ai_adaptive.enabled`, false default).

**Example:**
```php
add_filter( 'wppo_ai_adaptive_enabled', '__return_true' );
```

---

### `wppo_ai_adaptive_eagerness`
Filters AI-learned speculation eagerness. @since NEXT.

**Parameters:**
- `$eagerness` *(string)* — `conservative` | `moderate` | `eager`.
- `$rum` *(array)* — RUM aggregates.

---

### `wppo_ai_adaptive_commerce_context`
Filters whether the current request is a commerce/auth context for AI speculation guardrails. @since NEXT. When true, AI-learned speculation eagerness is capped at `moderate` and commerce paths are suggested as speculation excludes (manual user settings stay authoritative).

**Parameters:**
- `$is_commerce` *(bool)* — Whether a commerce/auth context was detected (WooCommerce active, cart/checkout/account page, logged-in user, or active cart cookies).

---

### `wppo_ai_adaptive_speculation_rules`
Filters AI-injected speculation rules. @since NEXT.

**Parameters:**
- `$rules` *(array)* — Speculation rules array.
- `$urls` *(string[])* — Top-2 predicted prefetch URLs.

---

### `wppo_speculation_list_urls`
Filters the high-value speculation list URLs (home + `performance_audit.high_value_urls`, same-site validated, capped at 10). @since NEXT.

Emitted as a `{"source":"list"}` rule via the `wp_speculation_rules` filter (WP 6.8+) when `preload_settings.enableSpeculationRules` is on. Return an empty array to suppress the list rule.

**Parameters:**
- `$urls` *(string[])* — Validated list URLs.

---

### `wppo_speculation_list_rules`
Filters the speculation rules after the high-value list rule is appended. @since NEXT.

**Parameters:**
- `$rules` *(array)* — Speculation rules array.
- `$urls` *(string[])* — List URLs that were appended.

---

### `wppo_edge_cache_enabled`
Filters whether Edge HTML Cache (N2) is enabled. @since NEXT.

Host-agnostic Cloudflare Workers / Bunny Edge adapter deploying `cache/wppo/{domain}/{path}/index.html` with stale-while-revalidate (<30ms global TTFB). Gated by `edge_cache.enabled` (false default). Purge via `Edge_Purger::purge_all()` on `wppo_after_cache_clear` alongside `CDN_Purger` (lock via `Util::transient_key('wppo_edge_purge_lock')`). Worker template `templates/cloudflare-worker.js` + `wrangler.toml` generator `Edge_Cache::get_wrangler_toml()` + Bunny `templates/bunny-edge.js` / `Edge_Cache::get_bunny_edge_js()`.

**Parameters:**
- `$enabled` *(bool)* — Whether edge cache is enabled.

**Example:**
```php
add_filter( 'wppo_edge_cache_enabled', '__return_true' );
```

---

### `wppo_edge_cache_worker_content`
Filters Cloudflare Worker JS content. @since NEXT.

**Parameters:**
- `$content` *(string)* — Worker JS source after placeholder replacement.
- `$config` *(array)* — Adapter config (origin_url, cache_ttl, swr, provider).

---

### `wppo_edge_cache_wrangler_content`
Filters wrangler.toml content. @since NEXT.

**Parameters:**
- `$toml` *(string)* — wrangler.toml source.
- `$config` *(array)* — Adapter config.

---

### `wppo_edge_cache_bunny_content`
Filters Bunny edge JS content. @since NEXT.

**Parameters:**
- `$content` *(string)* — Bunny JS source.
- `$config` *(array)* — Adapter config.

---

### `wppo_edge_cache_config`
Filters edge cache adapter config before template generation. @since NEXT.

**Parameters:**
- `$config` *(array)* — `origin_url`, `cache_ttl`, `swr`, `provider`.

---

### `wppo_perf_translations_file_written`
Fires after a compiled translation file is written. @since NEXT.

**Parameters:**
- `$cache_file` *(string)* — Path to the compiled `.php` file.
- `$mofile` *(string)* — Source `.mo` file.
- `$domain` *(string)* — Text domain.

**Example:**
```php
add_action( 'wppo_perf_translations_file_written', function( $cache_file, $mofile, $domain ) {
    error_log( "Compiled {$domain} to {$cache_file}" );
}, 10, 3 );
```

---

### `wppo_server_timing_enabled`
Filters whether the Server-Timing header is emitted. @since NEXT.

**Parameters:**
- `$enabled` *(bool)* — Whether Server-Timing is enabled (from `performance_audit.server_timing_enabled`, `false` default).

When `true`, the plugin registers `wp_finalized_template_enhancement_output_buffer` (see WordPress Core section below), which opts into the template-enhancement buffer (priority 1000 by default) and disables response streaming — TTFB increases while TTLB unchanged. Keep disabled by default; header is emitted only on cache-miss generation passes (`advanced-cache.php` serves cached pages without booting WordPress).

**Performance Lab interop (@since NEXT):** When the Performance Lab Server-Timing module is active (`perflab_server_timing_register_metric()` / `perflab_wrap_server_timed_call()` present — `Main::is_pl_server_timing_active()`), Performance Lab owns the `Server-Timing` header and prefixes every registered metric slug with `wp-` (its defaults `before-template` / `template` / `total` surface as `wp-before-template` / `wp-template` / `wp-total`). The plugin then avoids duplicate/conflicting metric names:

- Performance Lab **with output buffering enabled**: the plugin's emission is suppressed entirely — Performance Lab's defaults already measure before-template + template + total from the same underlying timestamps and send a single header.
- Performance Lab **without output buffering**: Performance Lab sends its header at `template_include` (before the template renders) with `wp-before-template` only. The plugin emits only the render duration (`wp-template` — unclaimed by Performance Lab in this mode) as an appended, distinct entry; the duplicate `wp-before-template` is dropped.
- Performance Lab **with unknown buffering mode** (`perflab_server_timing_use_output_buffer()` absent): the plugin defers to Performance Lab and suppresses its emission entirely.
- Performance Lab **inactive**: unchanged — both `wp-before-template` and `wp-template` are emitted.

No metric slugs are registered through the Performance Lab API by this plugin; the header value is coexistence-managed only. i18n note: metric names are protocol literals (never translated).

**Example:**
```php
add_filter( 'wppo_server_timing_enabled', function( $enabled ) {
    // Only emit for administrators.
    return $enabled && current_user_can( 'manage_options' );
} );
```

---

## 🔌 WordPress Core Late-Header Hooks Used by Plugin

### `wp_finalized_template_enhancement_output_buffer` (alias `wp_send_late_headers`)
WordPress 6.9 late-header / final buffer action. Canonical place to emit late headers (Server-Timing, ETag/304) before flush. @since NEXT.

**Origin:** WP 6.9 standardised the former ad-hoc `ob_start()` at `template_redirect` / `template_include` into `wp_should_output_buffer_template_for_enhancement()` / `wp_start_template_enhancement_output_buffer()` / `wp_finalize_template_enhancement_output_buffer()` with filter `wp_template_enhancement_output_buffer` and action `wp_finalized_template_enhancement_output_buffer ($final)` (also `wp_send_late_headers` alias), try/catch wrapped with `WP_DEBUG_DISPLAY` appended on error. Trac #64126 / #63636 / #43258; Performance Lab #2225/#2515.

**Parameters:**
- `$final` *(string)* — Final HTML string before flush (alias `$output` in plugin; not the filtered value).

**Streaming tradeoff:** Registering this action automatically opts into the template-enhancement buffer (priority 1000 by default via `wp_should_output_buffer_template_for_enhancement()`), which disables response streaming / early flush. TTFB increases while TTLB unchanged — intentional when Server-Timing is enabled; keep disabled by default and emit only on cache-miss generation passes (`advanced-cache.php` serves cached pages without booting WordPress; see `includes/class-main.php:559` `setup_hooks`, `capture_template_start`, `emit_server_timing_header` and `includes/class-cache.php:process_buffer_for_cache`).

**Late-header mechanics:** Header must be sent via `header( 'Server-Timing: ...', false )` before flush (`false` appends to preserve coexisting metrics); guard `headers_sent()` and `null === System_Info::get_request_start_microtime()`. Core wraps the action in try/catch with `WP_DEBUG_DISPLAY` on error — no extra try/catch needed in plugin.

**ETag note:** `Advanced_Cache_Handler::create()` drop-in (`includes/class-advanced-cache-handler.php:214` `wppo_serve_cache_file()`) already handles conditional GET (`If-Modified-Since` / `If-None-Match` → `304` with `ETag` / `Last-Modified`); no ETag is computed in `Main::emit_server_timing_header()`.

**Example:**
```php
// Plugin usage (gated, docs-only tradeoff):
// Server-Timing (WP 6.9+). Registering wp_finalized_template_enhancement_output_buffer
// automatically opts into the template-enhancement buffer (priority 1000 by default),
// which disables response streaming. TTFB increases while TTLB unchanged — intentional
// when Server-Timing is enabled; keep disabled by default and emit only on cache-miss
// generation passes (advanced-cache.php serves cached pages without booting WordPress).
// @since NEXT
if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) && $this->server_timing_enabled() ) {
    add_action( 'template_redirect', array( $this, 'capture_template_start' ), 0 );
    add_action( 'wp_finalized_template_enhancement_output_buffer', array( $this, 'emit_server_timing_header' ), 0, 1 );
}
```

### `wppo_combine_preload_fetchpriority`
Filters fetchpriority for the combined-CSS `rel="preload"` hint (external path only, not inline). Default `high` prioritises LCP stylesheet. Return falsy to suppress. @since NEXT.

**Parameters:**
- `$fetchpriority` *(string)* — `'high'|'low'|'auto'` (any other value suppressed).
- `$url` *(string)* — Preload URL.

**Example:**
```php
add_filter( 'wppo_combine_preload_fetchpriority', function( $prio, $url ) {
    return false; // suppress fetchpriority
}, 10, 2 );
```

---

### `wppo_deferred_fetchpriority`
Filters fetchpriority for each deferred script handle. Default `low` deprioritises non-render-blocking scripts. Return `high` for an LCP-critical handle, falsy to suppress. Guards WP 6.9+ native `wp_script_add_data` plus pre-6.9 regex fallback. @since NEXT.

**Parameters:**
- `$fetchpriority` *(string)* — `'high'|'low'|'auto'`.
- `$handle` *(string)* — Script handle.

**Example:**
```php
add_filter( 'wppo_deferred_fetchpriority', function( $prio, $handle ) {
    return 'jquery-core' === $handle ? 'high' : $prio;
}, 10, 2 );
```

---

### `wppo_deferred_in_footer`
Filters whether deferred classic scripts are moved to the footer on WP 6.9+ (native `in_footer` migration, Trac #63486). Default `true`; the plugin sets the `'group'` data key (core reads `'group'` for footer placement — the `'in_footer'` data key itself is never read for classic scripts) unless already footer-bound. Return `false` per handle to keep a script in the head (e.g. `document.write` dependencies). @since NEXT.

**Parameters:**
- `$in_footer` *(bool)* — Whether to set the footer group.
- `$handle` *(string)* — Script handle.

**Example:**
```php
add_filter( 'wppo_deferred_in_footer', function( $in_footer, $handle ) {
    return 'legacy-ad-slot' === $handle ? false : $in_footer;
}, 10, 2 );
```

---

### `wp_template_enhancement_output_buffer`
WordPress 6.9 filter for the template-enhancement buffer. @since NEXT.

**Parameters:**
- `$filtered_output` *(string)* — Filtered output from previous callbacks.
- `$output` *(string)* — Raw output buffer content.

Used by `Cache::process_buffer_for_cache()` at priority 10 to process (image optimisation, minification, CDN rewrite) without saving; persistence is via `Cache::stash_cache()` on the finalized action above. See also `Main::process_used_css_only` (priority 20) and `Image_Optimisation::prioritize_lcp_in_buffer` (priority 30) on the same filter.

---

## Additional filters & actions (shipped, not yet in the main list)

### `wppo_inline_combined_css`
Filters whether the combined/minified CSS is inlined via core `wp_maybe_inline_styles()`. Return falsy to disable inlining (e.g. when serving the combined file from a CDN). @since NEXT.

**Parameters:**
- `$enabled` *(bool)* — Default `true`.

---

### `wppo_exclude_delay_js`
Filters the resolved delay-JS exclusion list after preset merging. @since NEXT.

**Parameters:**
- `$preset` *(string[])* — Exclusion patterns.

---

### `wppo_exclude_defer_js`
Filters the resolved defer-JS exclusion list after preset merging. @since NEXT.

**Parameters:**
- `$preset` *(string[])* — Exclusion patterns.

---

### `wppo_delay_js_exclusions`
Filters the delay-JS exclusion preset list itself. @since NEXT.

**Parameters:**
- `$preset` *(string[])* — Preset exclusion patterns (`Main::get_delay_js_exclusions`).

---

### `wppo_htaccess_cache_vary_rules`
Filters the `.htaccess` cache-vary rules block before writing. @since NEXT.

**Parameters:**
- `$rules` *(string[])* — Rule lines.
- `$cache_vary` *(bool)* — Whether vary rules are enabled.

---

### `wppo_object_cache_dropin_path`
Filters the object-cache drop-in path (`WP_CONTENT_DIR . '/object-cache.php'` by default). @since NEXT.

**Parameters:**
- `$path` *(string)* — Drop-in file path.

---

### `wppo_redis_allow_request_password`
Filters whether a Redis password supplied via the REST request body may be used for `object_cache` operations. Default `false` — passwords must come from `WPPO_REDIS_PASSWORD` or the config file. @since NEXT.

**Parameters:**
- `$allowed` *(bool)* — Default `false`.

---

### `wppo_telemetry_verify_ssl`
Filters whether the local telemetry cURL scan verifies TLS certificates. @since NEXT.

**Parameters:**
- `$verify_ssl` *(bool)* — Default `true`.
- `$url` *(string)* — URL being scanned.

---

### `wppo_telemetry_allow_remote_head`
Filters whether HEAD requests are allowed to non-local (remote) telemetry targets. Default `false` (localhost only). @since NEXT.

**Parameters:**
- `$allowed` *(bool)* — Default `false`.
- `$url` *(string)* — Target URL.

---

### `wppo_debug_log` (action)
Debug logging sink fired with diagnostic messages (cache domain validation, CDN purge failures). No-op unless listeners are attached. @since NEXT.

**Parameters:**
- `$message` *(string)* — Diagnostic message.
- `$context` *(array, optional)* — Structured detail for the event (for example `array( 'exception' => Throwable )` in the HTML minifier). Listeners should accept it as an optional second argument.

---

### `wppo_varnish_purge_max_urls`
Filters the max URLs per Varnish purge batch (min 1). Default `20`. @since NEXT.

**Parameters:**
- `$max_urls` *(int)* — Batch size cap.

---

### `wppo_cron_discovery_limit`
Filters the per-run discovery cap for preload URL discovery. Default `50`. @since NEXT.

**Parameters:**
- `$limit` *(int)* — Discovered items per cron run.

---

### `wppo_filesize_limit_bytes`
Filters the max source image size accepted for conversion. Default `20 * 1024 * 1024`. @since NEXT.

**Parameters:**
- `$max_bytes` *(int)* — Byte limit.

---

### `wppo_convert_gain_map_images`
Filters whether gain-map (HDR) images are converted. Return truthy to allow; default `false` skips them. @since NEXT.

**Parameters:**
- `$allow` *(bool)* — Default `false`.

---

### `wppo_font_metric_fallback_css`
Filters the generated size-adjust fallback CSS for a font family. @since NEXT.

**Parameters:**
- `$css` *(string)* — Fallback `@font-face` CSS.
- `$family` *(string)* — Font family name.

---

### `wppo_skip_combine_on_small_block_theme`
Filters whether combining styles is skipped for small block themes under the handle limit. Return falsy to always combine. @since NEXT.

**Parameters:**
- `$skip` *(bool)* — Default `true`.
- `$eligible_handles` *(string[])* — Handles considered.
- `$limit` *(int)* — Handle-count threshold.

---

### `wppo_safe_css_combine_fallback`

Filters whether the safe CSS combine fallback is enabled. When true (default) the combine path (`Cache::combine_css()`) verifies the combined payload is non-empty and the written file is valid (`is_file`, `is_readable`, `filesize > 0`) before dequeuing original handles, and `Used_CSS::inject_used_css()` verifies non-empty payload, successful head match, and confirms injection before stripping original `<link>` tags — fail-open to originals with throttled `Log::add()` on any guard failure. Return falsy to restore legacy (unsafe) stripping behavior. @since NEXT.

**Parameters:**
- `$enabled` *(bool)* — Default `true`.

**Example:**

```php
add_filter( 'wppo_safe_css_combine_fallback', '__return_false' ); // disable safe guards (not recommended)
```

---

### `wppo_inline_combined_css`
Filters whether the combined/minified CSS is inlined via core `wp_maybe_inline_styles()`. Return falsy to disable inlining (e.g. when serving the combined file from a CDN); the combined file is still generated and enqueued in that case. @since NEXT.

---

### `wppo_ccss_allowed_stylesheet_host`
Filters whether an external stylesheet host is allowed during Critical CSS generation. Default `false` (self-hosted only). @since NEXT.

**Parameters:**
- `$allowed` *(bool)* — Default `false`.
- `$host` *(string)* — Stylesheet host.

---

### `wppo_ccss_sanitize_inline`
Filters sanitized inline Critical CSS before it is written/inlined. @since NEXT.

**Parameters:**
- `$css` *(string)* — Sanitized CSS.

---

### `wppo_crawler_use_nproc`
Filters whether `nproc` may be probed (via `shell_exec`) as a fallback for CPU-count detection. Default `false`. @since NEXT.

**Parameters:**
- `$use_nproc` *(bool)* — Default `false`.

---

### `wppo_crawler_load_limit`
Filters the server-load ceiling above which the crawler idles. @since NEXT.

**Parameters:**
- `$limit` *(float)* — Default derived from CPU count (min `0.1`).

---

### `wppo_crawler_is_overloaded`
Filters the final overloaded verdict for the crawler. @since NEXT.

**Parameters:**
- `$overloaded` *(bool)* — Whether load exceeds the limit.
- `$load` *(float)* — Current 1-minute load average.
- `$limit` *(float)* — Configured load limit.

---

### `wppo_crawler_disable_curl`
Filters whether curl_multi parallel fetching is disabled (falling back to `wp_remote_get`). @since NEXT.

**Parameters:**
- `$disable` *(bool)* — Default `false`.

---

### `wppo_crawler_full_matrix`
Filters whether the crawler walks the full variant matrix per URL (Accept webp/avif × mobile/desktop × guest/role). @since NEXT.

**Parameters:**
- `$full` *(bool)* — Default `false` (core URLs only).

---

### `wppo_crawler_urls`
Filters the resolved list of URLs the crawler will warm. @since NEXT.

**Parameters:**
- `$urls` *(string[])* — URL list.

---

### `wppo_crawler_sitemap_urls`
Filters sitemap-discovered URLs before the crawler cap is enforced. @since NEXT.

**Parameters:**
- `$sitemap_urls` *(string[])* — Discovered URLs.
- `$cap` *(int)* — Discovery cap.

---

### `wppo_litespeed_purge_sync`
Filters whether LiteSpeed purges run synchronously instead of queueing. @since NEXT.

**Parameters:**
- `$purge_sync` *(bool)* — Resolved setting.

---

### `wppo_litespeed_effective_mode`
Filters the effective LiteSpeed coexistence mode after detection. @since NEXT.

**Parameters:**
- `$mode` *(string)* — Effective mode string.
- `$requested` *(string)* — Requested mode from settings.

---

### `wppo_litespeed_should_disable_optimizer`
Filters whether the LiteSpeed built-in optimizer should be disabled while WPPO owns caching. @since NEXT.

**Parameters:**
- `$disable` *(bool)* — Default derived from effective mode.
- `$mode` *(string)* — Effective mode.

---

### `wppo_litespeed_is_lscache_active`
Filters whether the LSCache engine is detected as active for the current request. @since NEXT.

**Parameters:**
- `$active` *(bool)* — Detection result.

---

### `wppo_litespeed_lscache_vary_value`
Filters the `_lscache_vary` cookie value (12-char hash). @since NEXT.

**Parameters:**
- `$value` *(string)* — Hash.
- `$payload` *(array)* — Active vary payload used to build it.

---

### `wppo_litespeed_vary_fallback`
Filters the vary cookie fallback header used when the vary bridge cannot seed a cookie. @since NEXT.

**Parameters:**
- `$fallback` *(string)* — Fallback header value.

---

### `wppo_litespeed_tag_post_id`
Filters the post ID used for `Po.{id}` LiteSpeed tag fan-out. Return `0` to skip the post tag. @since NEXT.

**Parameters:**
- `$post_id` *(int)* — Queried object ID.

---

### `wppo_litespeed_nocache_reason`
Filters the human-readable reason emitted with LiteSpeed no-cache headers. @since NEXT.

**Parameters:**
- `$reason` *(string)* — Reason slug.

---

### `wppo_litespeed_nocache_header`
Filters the final `X-LiteSpeed-Cache-Control: no-cache` header line. @since NEXT.

**Parameters:**
- `$header` *(string)* — Header value.
- `$reason` *(string)* — Reason slug.

---

### `wppo_litespeed_cache_control_header`
Filters the LiteSpeed `Cache-Control` header value for the resolved TTL. @since NEXT.

**Parameters:**
- `$header` *(string)* — Header value.
- `$ttl` *(int)* — Resolved TTL in seconds.

---

### `wppo_litespeed_esi_available`
Filters whether LiteSpeed ESI is considered available (gates the whole ESI bridge). Default `false`. @since NEXT.

**Parameters:**
- `$available` *(bool)* — Default `false`.

---

### `wppo_esi_should_punch_hole`
Filters whether an ESI block should punch a hole. Return `null` to defer to default detection. @since NEXT.

**Parameters:**
- `$punch` *(bool|null)* — Default `null` (auto).
- `$context` *(string)* — Block context.

---

### `wppo_esi_block`
Filters the ESI block name before the `<esi:include>` is assembled. @since NEXT.

**Parameters:**
- `$block` *(string)* — Block name.
- `$attrs` *(array)* — Block attributes.

---

### `wppo_esi_block_label`
Filters the accessible loading label announced on the OLS ESI placeholder (`role="status"` region) while the fragment is being fetched. The `nonce` block is hidden from assistive tech instead (audit #888 finding 7). @since NEXT.

**Parameters:**
- `$label` *(string)* — Loading label (default: localized per block name).
- `$block` *(string)* — Block name.

---

### `wppo_esi_placeholder`
Filters the ESI placeholder HTML rendered when ESI is unavailable. @since NEXT.

**Parameters:**
- `$html` *(string)* — Placeholder markup.
- `$block` *(string)* — Block name.
- `$attrs` *(array)* — Block attributes.

---

### `wppo_esi_fragment_html`
Filters the rendered ESI fragment HTML before output. @since NEXT.

**Parameters:**
- `$fragment` *(string)* — Fragment markup.
- `$block` *(string)* — Block name.

---

### `wppo_esi_nonce_content`
Filters the content rendered inside a nonce ESI fragment. @since NEXT.

**Parameters:**
- `$content` *(string)* — Fragment content.
- `$nonce` *(string)* — Nonce value.

---

### `wppo_esi_private_headers_sent` (action)
Fires when private/no-cache headers were sent in the ESI path (used by the DB queue fallback to know headers are gone). @since NEXT.

**Parameters:**
- `$scope` *(string)* — `'private'` or `'no-cache'`.

---

### `wppo_litespeed_esi_nonces`
Filters the nonce allowlist map used by the LiteSpeed ESI bridge. @since NEXT.

**Parameters:**
- `$nonces` *(array)* — Nonce names → values.

---

### `wppo_video_placeholder_allowed`
Filters whether a video iframe may be replaced by a click-to-play placeholder. @since NEXT.

**Parameters:**
- `$allowed` *(bool)* — Default `true`.
- `$original_src` *(string)* — Iframe source URL.
- `$iframe_tag` *(string)* — Full iframe tag.

---

### `wppo_video_play_button_html`
Filters the play-button markup in the video placeholder. @since NEXT.

**Parameters:**
- `$play_button` *(string)* — Button HTML.
- `$video_id` *(string)* — Video ID.
- `$video_type` *(string)* — `youtube`|`vimeo`.

---

### `wppo_video_placeholder_html`
Filters the final click-to-play placeholder markup. @since NEXT.

**Parameters:**
- `$placeholder_html` *(string)* — Placeholder HTML.
- `$video_id` *(string)* — Video ID.
- `$video_type` *(string)* — `youtube`|`vimeo`.
- `$thumbnail_url` *(string)* — Poster image URL.

---

## 🔧 CLI-Only Settings Keys

These `wppo_settings` keys have no SPA toggle — they are read by background
jobs / WP-CLI and edited via `wp wppo settings` (or `import_settings`).

| Key | Type / Default | Consumed by |
|-----|----------------|-------------|
| `image_optimisation.excludeWebPImages` | string (newline-separated URLs/handles), default `''` | `Img_Converter::__construct()` (`includes/class-img-converter.php`) — images matching these URLs/handles are skipped during WebP/AVIF conversion. |
| `image_optimisation.batch` | int, default `50` | `Cron` image-conversion worker (`includes/class-cron.php`) and `wp wppo image convert` (`includes/class-wppo-cli-command.php`) — number of images processed per batch. |

---

## ⚠️ Deprecated Features

### `file_optimisation.removeQueryStrings` (deprecated NEXT, removal tracked in #904)
Strips `?ver=` from enqueued CSS/JS URLs. Obsolete per the 2026
cache-busting consensus: `?ver=` **is** the cache-busting mechanism
(fingerprinting), and WPPO's htaccess Expires handler already sets long
immutable TTLs — stripping `ver` risks stale assets with no measurable
gain (see `docs/research/competitor-research-2026-09-08.md` §5). The SPA
toggle now lives in a "Legacy Options" section with warning copy;
default stays off. Planned hard removal two minor releases after the
NEXT release (`Main::strip_static_query_strings()` + setting + filters).
