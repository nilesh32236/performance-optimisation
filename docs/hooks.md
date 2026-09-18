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
Fires after WPPO purges builder + page caches for a page-builder update (issue #907). The watcher (`Builder_Purge_Watcher`, hooked to `upgrader_process_complete`) self-gates to Elementor/Divi/Bricks/WPBakery slugs, deletes the builders' regenerable CSS directories, clears the page cache + used-CSS + critical-CSS, writes an audit log entry, and stages a one-time admin notice. @since 2.0.0.

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
Fires after a database cleanup operation completes. @since 2.0.0, also fires per-type after each individual cleanup (before the `all` aggregate). @since 2.0.0 for per-type.

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

### `wppo_purge_failed_actions`
Filters whether failed Action Scheduler actions older than the retention bound are purged (issue #1310). Default off (failed-action debug history is retained unless the site opts in); the `database_cleanup.purgeFailedActions` setting value is passed as the default so either path enables the purge. The purge lifespan is `min( filtered failed-action retention, 3-month cap )` floored at one day, so a rogue retention filter returning 0 cannot destroy just-failed history. @since NEXT.

**Parameters:**
- `$enabled` *(bool)* — Whether the failed-action purge is enabled. Default from `database_cleanup.purgeFailedActions` (`false`).

**Example:**
```php
add_filter( 'wppo_purge_failed_actions', '__return_true' );
```

---

### `wppo_action_scheduler_cleanup_enabled`
Filters whether the plugin may delegate to Action Scheduler's queue cleaner (`ActionScheduler_QueueCleaner::delete_old_actions()`) for terminal (complete/canceled, plus failed when upstream enables it) actions past retention (issue #1310). Cautious operators can return `false` to narrow the scope to a no-op (visibility only); site-specific narrowing beyond that should use the upstream `action_scheduler_*` filters. @since NEXT.

**Parameters:**
- `$enabled` *(bool)* — Whether AS cleanup delegation is enabled. Default `true`.

**Example:**
```php
add_filter( 'wppo_action_scheduler_cleanup_enabled', '__return_false' );
```

---

### `wppo_should_cache_request`
Filters whether the current request should be cached. Placed **after** the `DONOTCACHEPAGE` constant check so the constant always wins even if the filter returns true. Return `false` to skip `ob_start` and cache storage. @since 2.0.0.

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

**WooCommerce safe mode (issue #922, extended by #962, proof by #1256):** when `cache_settings.wooSafeMode` is `true` (default), `cart`/`checkout`/`my-account` endpoints (plus any configured custom WooCommerce page slugs resolved via `Util::get_woo_excluded_paths()`, including nested paths like `shop/basket`), Woo endpoint URLs (`is_wc_endpoint_url()`: order-pay, view-order, downloads, …), `wc-ajax` and `?add-to-cart` requests, faceted layered-nav queries (`filter_*`, `query_type_*`, `min_price`/`max_price`, `rating_filter`, `orderby`, `product_cat` query form, `pa_*`, `attribute_*`, `gpf_*` via `Util::is_woo_faceted_query()`), and Woo session cookies (`wp_woocommerce_session_*` + cart fragments) are never served as cache HIT. Store API routes (`wc/store`, `wcstore`, `wp-json/wc/store*`, `wp-json/wcstore*`, including the plain-permalink `?rest_route=/wc/store/...` form) are never cached unconditionally (safe-mode independent), at serve time, write time, and in the pre-boot drop-in. The same dynamic set is auto-excluded from script delay, remove-unused-CSS, and preload scheduling (faceted/functional queries are skipped by preload unconditionally, even with safe mode off). The one-click `woo_cache_self_test` REST endpoint proves exclusions on the live store: path probes, fragment probes, preload-skip probes (faceted URLs), guest-cart survival probes (cart/session cookies, wc-ajax, add-to-cart, Store API with page + object cache on), and a fail-closed `force_exclude` recommendation (true when the verdict fails: force-exclude dynamic routes plus cookie bypass and serve dynamic — never a stale cart). Product/order/coupon updates purge only affected URLs via `Cache::invalidate_woo_object()` (product → own permalink + category/tag archives + shop page; order/coupon → own permalink only) — never a full-cache wipe. The per-URL override below (`wppo_woo_cacheable`) applies only after WordPress boots (Cache layer); the pre-boot `advanced-cache.php` drop-in cannot run the filter and instead bakes the toggle and the configured Woo paths in at generation time — disabling safe mode (`wooSafeMode => false` via `wppo_settings`, e.g. `wp wppo settings` or import) regenerates the drop-in with only the pre-#922 guards plus the unconditional Store API guard, restoring master behaviour.

**Query-param poisoning guard (issue #1141):** the query gate (`Util::has_uncacheable_query()`) runs **before** this filter as a security property, so this filter can no longer re-allow a functional/unknown query param (e.g. `?ref=`, `?currency=`) as cacheable — such requests always go dynamic. To treat a custom marketing param as cache-neutral, extend `wppo_cache_query_allowlist` instead (e.g. `$allowlist[] = 'ref';`). The legacy `s`/`ver`/`v` params always force dynamic even if allowlisted.

---

### `wppo_invalidation_urls`
Filters the list of URL paths to purge when a post is invalidated. Merges filesystem and CDN purge; sanitized via `wp_normalize_path` + deduped before deletion. @since 2.0.0.

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
Filters the Redis object cache configuration after merging Dashboard settings with on-disk config (and before connection in `ping`/`enable`). @since 2.0.0.

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
Filters how many counted Redis failures trip the object-cache circuit breaker (auto-disable the drop-in). Readable at early boot: the `WPPO_CB_THRESHOLD` constant wins when defined, otherwise this filter, otherwise `5`. Only auth/connection-class errors count (`auth_fail`, `conn_fail`, `sentinel_fail`, `cluster_fail`, `select_fail`, boot exceptions, write failures) — environment/config codes never trip the breaker. @since 2.0.0.

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
Filters the counting window in seconds in which threshold failures must occur to trip the object-cache circuit breaker. Readable at early boot: the `WPPO_CB_WINDOW` constant wins when defined, otherwise this filter, otherwise `600` (10 minutes). Failures older than the window restart the counter instead of tripping. @since 2.0.0.

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
Filters the recovery-probe interval in seconds while the object-cache circuit is open (the `wppo_object_cache_probe` cron recurrence). Default `HOUR_IN_SECONDS`, minimum `300`. The probe is scheduled only while the circuit is open and cleared on recovery. @since 2.0.0.

**Parameters:**
- `$interval` *(int)* — Seconds between recovery probes. Default `3600`, minimum `300`.

**Example:**
```php
add_filter( 'wppo_object_cache_probe_interval', function() {
    return 900; // Re-check Redis every 15 minutes while down.
} );
```

---

### `wppo_nginx_probe_clear_sites`
Bounds how many sites the nginx config-exposure probe-clear fans out to on multisite (audit #1338 review). Sibling verdicts self-expire in 1–2h by design, so a smaller sweep only delays freshness, never correctness. @since NEXT.

**Parameters:**
- `$limit` *(int)* — Maximum site IDs to sweep. Default `500`, minimum `1`.

**Example:**
```php
add_filter( 'wppo_nginx_probe_clear_sites', function() {
    return 50; // Sweep at most 50 sites per settings save.
} );
```

---

## 🎛️ Filter Hooks

### `wppo_woo_cacheable`
Filters whether a Woo-excluded request should be re-allowed as cacheable (issue #922). Guarded by `has_filter()` — the filter is only applied when a listener is present. Return `true` to re-allow caching for that URL; default `false` (not cacheable). Runs inside `Cache::is_woo_excluded()` after any Woo exclusion is detected. Override applies only after WordPress boots (Cache layer); the pre-boot drop-in remains unconditional — it bakes the `wooSafeMode` toggle and configured Woo paths at generation time and cannot run this filter. @since 2.0.0.

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

### `wppo_woo_faceted_query_params`
Filters additional faceted layered-nav query param names treated as WooCommerce dynamic by `Util::is_woo_faceted_query()` (issue #1256). The built-in set (`filter_*`, `query_type_*`, `min_price`, `max_price`, `rating_filter`, `orderby`, `product_cat`, `pa_*`, `attribute_*`, `gpf_*`) always applies; names added here are matched case-insensitively as exact param names. Guarded by `has_filter()` — the filter only runs when a listener is present. @since NEXT.

**Parameters:**
- `$params` *(string[])* — Additional lowercase param names (e.g. `array( 'filter_brand' )` is redundant — `filter_*` already covers it; use for custom params like `'my_layer'`).

**Example:**
```php
add_filter( 'wppo_woo_faceted_query_params', function( $params ) {
    $params[] = 'my_layer';
    return $params;
} );
```

---

### `wppo_woo_invalidation_urls`
Filters the surgical Woo invalidation URL list purged by `Cache::invalidate_woo_object()` (issue #962) when a product, order, or coupon changes. Entries are reduced to their URL path (full URLs/query strings accepted — only the path is purged) via `wp_normalize_path` + deduped before deletion; empty paths (e.g. a `'/'` entry, which would resolve to the homepage) are skipped, so home is never purged. Never fans out to home/archives and never triggers a full-cache wipe. Stores that render product blocks/grids on the homepage or other archives should append those paths explicitly (e.g. `$urls[] = '/'`) — product saves purge the product permalink + its category/tag archives + the shop page only. @since 2.0.0.

**Parameters:**
- `$urls` *(string[])* — List of URL paths to purge (relative, e.g. `'/product/hoodie/'`).
- `$object_id` *(int)* — The Woo object ID being invalidated.
- `$kind` *(string)* — Object kind: `'product'`, `'order'`, or `'coupon'`.

**Example:**
```php
add_filter( 'wppo_woo_invalidation_urls', function( $urls, $object_id, $kind ) {
    if ( 'product' === $kind ) {
        $urls[] = '/sale/';
    }
    return $urls;
}, 10, 3 );
```

---

### `wppo_builder_purge_map`
Filters the builder-update purge map used by the watcher (`Builder_Purge_Watcher::get_builder_map()`, issue #907). Lets hosts and themes add builders or correct slugs and cache directories. @since 2.0.0.

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

### `wppo_used_css_safelist`
Filters the used-CSS safelist (issue #1023). The merged built-in (Elementor + popup presets) + user safelist is passed through this filter when a listener is registered (`has_filter`-guarded). Return the list to keep. @since 2.0.0.

**Parameters:**
- `$safelist` *(string[])* — Merged safelist selectors.

**Example:**
```php
add_filter( 'wppo_used_css_safelist', function( $safelist ) {
    $safelist[] = '.my-popup-';
    return $safelist;
} );
```

---

### `wppo_used_css_regen_cooldown`
Filters the used-CSS full-regeneration cooldown in seconds (issue #1107). Bounds how often `Used_CSS::regenerate_all()` may queue site-wide work when not forced (default 5 hours, matching the `wppo_used_css_cron` schedule). Explicit operator paths (builder purge after a wipe, manual REST/ability triggers) pass `$force` and bypass the cooldown; per-post freshness still applies. @since NEXT.

**Parameters:**
- `$cooldown` *(int)* — Cooldown in seconds. Default 5 hours.

**Example:**
```php
add_filter( 'wppo_used_css_regen_cooldown', function() {
    return HOUR_IN_SECONDS;
} );
```

---

### `wppo_used_css_strict_csp`
Return true when a Content-Security-Policy without `unsafe-inline` is enforced outside PHP (`.htaccess`/Nginx/hosting or edge headers), which the automatic detection (`headers_list()` plus a `<meta http-equiv>` scan) cannot see. Forces the async/delay used-CSS delivery modes to downgrade to the blocking file mode, since the async `onload` swap and the delay-mode inline loader would otherwise be blocked and leave stylesheets never applied. @since NEXT.

**Parameters:**
- `$strict_csp` *(bool)* — Whether a server/edge-level strict CSP is active. Default false.

**Example:**
```php
add_filter( 'wppo_used_css_strict_csp', function() {
    return true; // Server sends `Content-Security-Policy` without 'unsafe-inline'.
} );
```

---

### `wppo_critical_css_strict_csp`
Return true when a Content-Security-Policy without `unsafe-inline` is enforced outside PHP (`.htaccess`/Nginx/hosting or edge headers), which the automatic `headers_list()` detection cannot see. Forces the short-CCSS async loadCSS fallback to downgrade to the blocking per-template file variant (or nothing, deferring to the full stylesheet), since the raw inline loader would otherwise be blocked. A nonce is never baked because this output enters the static page cache. @since NEXT.

**Parameters:**
- `$strict_csp` *(bool)* — Whether a server/edge-level strict CSP is active. Default false.

**Example:**
```php
add_filter( 'wppo_critical_css_strict_csp', function() {
    return true; // Server sends `Content-Security-Policy` without 'unsafe-inline'.
} );
```

---
### `wppo_builder_drift_requeue`
Fires after builder-drift detection requeues used-CSS regeneration (issue #1023). Emitted by `Builder_Purge_Watcher::on_builder_drift()` (no args, Elementor CSS regen; full-site purge, at most once per request) and `Builder_Purge_Watcher::on_builder_drift_save( $post_id )` (explicit editor save; the save bumps the modification time so the post-modification freshness check in `Used_CSS::requeue_for_post()` still requeues genuine changes — issue #1107; fires when a job was queued or already pending, not fired when the variant was skipped as fresh). @since 2.0.0.

**Parameters:**
- `$post_id` *(int, optional)* — Post ID saved in the builder (only for the editor-save variant).

**Example:**
```php
add_action( 'wppo_builder_drift_requeue', function( $post_id = 0 ) {
    if ( $post_id ) {
        error_log( "Used CSS requeued for post {$post_id} after builder drift." );
    }
}, 10, 1 );
```

---

### `wppo_elementor_safe_mode_enabled`
Filters whether Elementor-safe mode is active (issue #1259). When on (default), Combine CSS and combined-CSS inlining step aside on Elementor-built pages. @since NEXT.

**Parameters:**
- `$enabled` *(bool)* — Whether safe mode is on. Default follows the `file_optimisation.elementorSafeMode` setting (absent key = enabled).

**Example:**
```php
add_filter( 'wppo_elementor_safe_mode_enabled', function( $enabled ) {
    // Keep protection on everywhere except a staging host.
    $host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
    if ( 'staging.example.com' === $host ) {
        return false;
    }
    return $enabled;
} );
```

---

### `wppo_is_elementor_page`
Filters the Elementor-built verdict for the current request (issue #1259). Return a non-null bool to force the verdict — the escape hatch for Elementor Theme Builder (header/footer/archive/popup), translated copies, and loop contexts that single-post meta detection does not cover. @since NEXT.

**Parameters:**
- `$verdict` *(bool|null)* — Forced verdict. Default `null` (run built-in detection).
- `$post_id` *(int|null)* — Resolved post ID (queried object with singular loop fallback applied), or `null` when unknown.
- `$raw_post_id` *(int|null)* — The caller-supplied post ID before resolution (`null` on the common no-ID path). Added for BC; two-arg callbacks keep working unchanged.

**Example:**
```php
add_filter( 'wppo_is_elementor_page', function( $verdict, $post_id ) {
    // Treat every page using a Theme Builder header as builder-built.
    if ( null === $verdict && function_exists( 'elementor_theme_do_location' ) ) {
        return true;
    }
    return $verdict;
}, 10, 2 );
```

---

### `wppo_builder_used_css_full_regen`
Restores the legacy forced full used-CSS requeue after a builder purge (issue #1259). By default the watcher runs a cooldown-gated targeted regen (`Used_CSS::request_targeted_regen()`) so a burst of builder updates cannot flood the scheduler; return `true` to wipe all variants and requeue site-wide instead. @since NEXT.

**Parameters:**
- `$full` *(bool)* — Whether to force a full requeue. Default `false` (targeted).

**Example:**
```php
add_filter( 'wppo_builder_used_css_full_regen', '__return_true' );
```

---

### `wppo_exclude_delay_js`
Filters the list of script handles or URL substrings excluded from JavaScript delay loading. Applied to the resolved exclusion list after preset merging, so entries added here win over preset contents and per-page preset opt-outs are subtracted afterwards (filter-then-subtract).

Exclusions apply to both halves of delay loading: the handle-level strategy assigned in `Main`, and the HTML rewrite that swaps a `<script>` to `type="wppo/javascript"` with the real source in `wppo-src`. A script is only genuinely eager when neither path rewrites it, so entries added here suppress both. @since 2.0.0; @since NEXT the HTML rewrite honours this filter.

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

Use this for any script that must run before the first user interaction — a mobile navigation toggle, for example. A delayed script is inert until the visitor interacts, so the tap that was meant to open a menu gets consumed by the delay loader instead.

---

### `wppo_delay_js_allowed_hosts`
Filters the allowlist of additional remote hosts the lazyload bundle may load deferred external scripts from. Delay-JS hydration only executes a deferred script when its `wppo-src` URL uses http(s) and points at the same origin or an allowlisted host (a built-in list of common analytics/marketing/utility CDNs ships in `src/lazyload.js`). Hosts added here are mirrored to the client as `wppoDelayConfig.allowedScriptHosts`. Entries may be bare hostnames (Unicode IDN accepted), IP literals (IPv4/IPv6, optionally in brackets with a port), bare `host:port` pairs, full URLs, or scheme-relative URLs (all reduced to their host part); ports are stripped and entries are validated fail-closed — invalid entries are dropped before exposure to the client. A single `'*'` entry intentionally disables the deferred-script host allowlist (admin-only debug path, loud console warning in the bundle); never use it in production. If the `wppo-lazyload` script element is ever removed dynamically, call `window.wppoLazyloadTeardown()` FIRST so the IntersectionObserver/MutationObserver and config globals are released (removal without teardown leaks the observers). @since 2.0.0.

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
Filters the wp_kses allowlist applied to ESI fragment HTML returned by the `wppo_esi_fragment` admin-ajax endpoint (`LiteSpeed_ESI::handle_ajax_fragment()`). The fragment is inserted into the page DOM by `src/esi.js`, so this allowlist is the server-side sanitization contract: every tag/attribute a custom ESI widget needs must be present here. Script-capable tags must never be added. @since 2.0.0.

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

### `wppo_safe_mode_enabled`
Filters the unified safe-mode kill switch (issue #1098). When truthy, Delay-JS + Defer-JS + Remove-Unused-CSS (and Critical-CSS stylesheet deferral) are all disabled in one click while the underlying `delayJS` / `deferJS` / `removeUnusedCSS` settings are preserved untouched — turn safe mode back off to restore the previous configuration (one-click recovery). Guarded by `has_filter()` — the filter is only applied when a listener is present. @since NEXT.

**Parameters:**
- `$enabled` *(bool)* — Whether safe mode is on. Default from `file_optimisation.safeMode` (`false`).

**Example:**
```php
add_filter( 'wppo_safe_mode_enabled', function( $enabled ) {
    if ( is_page( 'checkout' ) ) {
        return true; // Force safe mode on checkout.
    }
    return $enabled;
} );
```

**Manual revert path:** if aggressive delay/defer/used-CSS breaks a page, recover without losing settings via any of (in order): 1) enable **Safe mode** in File Optimisation → JavaScript Loading (one click, settings preserved); 2) append `?nocache` (or `?wppo_nocache`) to preview the unoptimised page; 3) tick **Disable Delay JS / Disable Defer JS / Disable Used CSS on this page** in the post editor Asset Manager (per-page post meta `_wppo_delay_disabled` / `_wppo_defer_disabled` / `_wppo_used_css_disabled`, survives cache clears via single-URL purge); 4) as a last resort via WP-CLI: `wp option patch update wppo_settings file_optimisation '{"safeMode":true}'` then `wp wppo cache clear`.

---

### `wppo_defer_js_preset_exclusions`
Filters the defer-JS preset exclusions (jQuery, Elementor/Divi, WooCommerce handles). The built-in preset stays un-deferred by default so carts, checkouts, and builders never break. Guarded by `has_filter()` — returns the built-in preset verbatim when no listener is present. Merged via `array_unique` with user `excludeDeferJS`. @since NEXT.

**Parameters:**
- `$preset` *(string[])* — Preset exclusion patterns.

**Example:**
```php
add_filter( 'wppo_defer_js_preset_exclusions', function( $preset ) {
    $preset[] = 'my-critical-slider';
    return $preset;
} );
```

---

### `wppo_cve_guard_handles`
Filter-only (S scope) list of handle strings to auto-exclude from optimization when a CVE is known. Default empty (no auto-exclude). Merged with `array_unique` into `minify_js`/`minify_css` (`exclude_js`/`exclude_css`) and `exclude_defer_js`/`exclude_delay_js` inside `PerformanceOptimise\Inc\Main::setup_hooks()`; respects the existing `litespeed_can_optm` gate; no `wp_options` persistence and no cron. @since 2.0.0.

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
Backward-compatibility alias for `wppo_cve_guard_handles`. Same semantics; chained after the primary filter (`wppo_cve_guard_handles` → `wppo_cve_excluded_handles`). Prefer `wppo_cve_guard_handles`. @since 2.0.0.

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

### `wppo_minify_allowed_roots`
Filters the allow-listed filesystem roots for minify/combine file serving (issue #1179). Every combine source path is canonicalized with `realpath()` and must resolve inside one of these roots (trailing-slash boundary) before any file bytes are read; out-of-root, symlink-escaped, wrapper-based, NUL-bearing, `..`-bearing, and `.php` targets are rejected and the asset degrades to its uncombined form. Guarded by `has_filter()` — the filter only runs when a listener is present; invalid or empty filtered values fall back to the defaults. @since NEXT.

**Parameters:**
- `$roots` *(string[])* — Allowed root paths. Defaults: `ABSPATH`, `WP_CONTENT_DIR`, and the current site's uploads basedir (multisite-safe, resolves per blog).

**Example:**
```php
add_filter( 'wppo_minify_allowed_roots', function( $roots ) {
    $roots[] = '/srv/shared-assets';
    return $roots;
} );
```

---

### `wppo_allow_hidden_block_asset`
Filters whether a hidden core block asset should be kept instead of omitted (issue #1147). On singular frontend views, `Main::omit_hidden_block_assets()` dequeues per-block `wp-block-*` stylesheets whose block type is absent from the current post content (hidden by default); return a truthy value to re-enable the asset for that block. On WP 6.9+ core's canonical `enqueue_empty_block_content_assets` filter takes precedence — a handle it keeps (returns `true` for the block name) is never omitted. The filter is only applied when a listener is registered (`has_filter()` guard). The omission pass only runs when on-demand block assets are enabled (`blockAssetsOnDemand` on, `loadAllCoreBlockAssets` off), only on singular views (archives and other composite views are never touched), only for handles verifiably registered as core block styles, and never when WordPress 6.9+ core block-asset hoisting owns the output via the template-enhancement buffer — in that case this filter does not run. The pass additionally bails out entirely when the post content references out-of-content block sources (reusable blocks, patterns, template parts, shortcodes), and keeps every asset when singular cannot be verified. A throwing listener fails open (the asset is kept). @since NEXT.

**Parameters:**
- `$allowed` *(bool)* — Whether to keep the asset. Default `false` (omit).
- `$block_name` *(string)* — Block name (e.g. `'core/cover'`).
- `$handle` *(string)* — Queued style handle (e.g. `'wp-block-cover'`).

**Example:**
```php
add_filter( 'wppo_allow_hidden_block_asset', function( $allowed, $block_name, $handle ) {
    if ( 'core/cover' === $block_name ) {
        return true; // Always keep the cover stylesheet (e.g. injected via shortcode).
    }
    return $allowed;
}, 10, 3 );
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

### `wppo_cache_query_allowlist`
Filters the list of cache-neutral (tracking/marketing) query params used by the query-poisoning guard (issue #1141). Guarded by `has_filter()` — the filter only runs when a listener is present. A request whose query params are all in this list (or carry the `utm_` prefix) may still be served from the clean-URL cache entry, but its response is never stored over the clean file; any other param (including the legacy `s`, `ver`, `v`, which always force dynamic even if added here) forces a dynamic uncached response. @since NEXT.

**Parameters:**
- `$allowlist` *(string[])* — Lowercase cache-neutral param names (defaults: `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `utm_id`, `gclid`, `gbraid`, `wbraid`, `fbclid`, `msclkid`, `ttclid`, `li_fat_id`, `mc_cid`, `mc_eid`, `igshid`, `dclid`, `yclid`, `gclsrc`, `_ga`, `_gl`, `pk_campaign`, `pk_kwd`, `piwik_kwd`, `matomo`, plus Facebook `fb_action_ids`/`fb_action_types`/`fb_source`).

**Example:**
```php
add_filter( 'wppo_cache_query_allowlist', function( $allowlist ) {
    $allowlist[] = 'ref'; // Treat a custom marketing param as cache-neutral.
    return $allowlist;
} );
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
Filters whether the current server is detected as LiteSpeed / OpenLiteSpeed. @since 2.0.0.

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
Filters the configured LiteSpeed integration mode (`auto|wppo|litespeed|standalone`). @since 2.0.0.

---

### `wppo_cache_ttl`
Tier-1 per-route LiteSpeed TTL override (LS layer only, file-cache stays global). Filter runs inside `LiteSpeed_Integration::get_litespeed_ttl()` / `handle_send_headers()` before `wppo_litespeed_ttl`; use it to vary `X-LiteSpeed-Cache-Control: public,max-age=N` per request without DB or `wppo_settings` schema change (drop-in-safe). Falls back to `url_to_postid( home_url( $uri ) )` and global `$post` when `$post_id` not explicitly passed; `null` when unresolvable. File-cache `cacheLife` constant untouched (as oracle warned: drop-in must not hit DB). @since 2.0.0.

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
Filters LiteSpeed TTL seconds mapped from `cacheLife` hours. File-cache `0` (never expire) maps to `604800` (1 week) for the LS server layer as an explicit policy change — LS cannot store infinite. Tier-1 adds third-arg `$context` (`array{uri:string,post_type:string|null,post_id:int|null}`) resolved without DB (REQUEST_URI + `url_to_postid` / `$post` fallback) so per-route TTL works in the `advanced-cache.php` drop-in; existing 2-arg callbacks remain compatible (extra arg ignored). `wppo_cache_ttl` runs first for LS-only overrides; this filter remains the final TTL gate. **Since N10-T2 (Tier-2) the same per-type resolution also reads `wppo_settings[cache_settings][ttlOverrides][post|page|product]` (hours `0/1/6/12/24/48/168`, default inherit global `cacheLife`, sanitized via `Util::sanitize_settings_recursively()` allowlist + `absint`, stored under `cache_settings` tab via `update_settings`). The settings override is applied **before** filters, so `wppo_cache_ttl` / `wppo_litespeed_ttl` still win; non-singular requests always fall back to global; file-cache `advanced-cache.php` constant stays untouched (LS-only).** @since 2.0.0.

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
Filters sanitized `ttlOverrides` array after allowlist (`post|page|product` + `0/1/6/12/24/48/168`). @since 2.0.0.

---

### `wppo_litespeed_is_cacheable`
Filters whether the current request is considered cacheable for the LiteSpeed layer. @since 2.0.0.

**Query-param poisoning guard (issue #1141):** like the file-cache layer, this filter cannot re-allow a functional/unknown query param — the guard (`Util::has_uncacheable_query()`) is re-enforced after the filter, so such requests always go dynamic. Extend `wppo_cache_query_allowlist` for custom marketing params.

---

### `wppo_litespeed_tag`
Filters the `X-LiteSpeed-Tag` value for WPPO pages (default `WPPO`). @since 2.0.0.

---

### `wppo_litespeed_vary`
Filters the `litespeed_vary` value after WPPO appends `wppo_role_hash` when logged-in cache is enabled. @since 2.0.0.

---

### `wppo_litespeed_vary_enabled`
Filters whether the LiteSpeed vary bridge (`wppo_role_hash` → `litespeed_vary`) is enabled. @since 2.0.0.

---

### `wppo_litespeed_strip_cache_control`
Filters whether generic `Cache-Control` is stripped when `X-LiteSpeed-Cache-Control: public` is sent (prevents conflict). @since 2.0.0.

---

### `wppo_litespeed_bypass_file_cache`
Filters whether the WPPO file cache is bypassed when LiteSpeed owns the cache (`is_litespeed && !is_wppo_cache_owner`). @since 2.0.0.

---

### `wppo_litespeed_nextgen_rewrite`
Filters whether next-gen Vary:Accept rewrite (LS-401/LS-402) is enabled. Gated by `is_litespeed && convertImg && enableNextGenRewrite` (htaccess) or `convertImg && enableNextGenRewrite` (nginx). Opt-in default false. @since 2.0.0.

---

### `wppo_litespeed_enable_nextgen_rewrite`
Legacy alias for `wppo_litespeed_nextgen_rewrite`. @since 2.0.0.

---

### `wppo_litespeed_brotli`
Filters whether Brotli `.br` generation (LS-403) is enabled. Requires `extension_loaded('brotli')` or `brotli_compress`. Opt-in via `enableBrotli` default false. @since 2.0.0.

---

### `wppo_litespeed_enable_brotli`
Legacy alias for `wppo_litespeed_brotli`. @since 2.0.0.

---

### `wppo_pagespeed_request_timeout`
Filters the PageSpeed Insights API request timeout in seconds (default `60`, clamped 5–300). Long values risk wedging the Action Scheduler worker; short values risk false timeouts (audit #888 finding 19). @since 2.0.0.

**Parameters:**
- `$timeout` *(int)* — Timeout in seconds.

---

### `wppo_google_fonts_backoff`
Filters the failure-backoff TTL in seconds for the Google Fonts fetch sentinel transients (`wppo_gf_fail_*`). When a Google Fonts CSS or font-file fetch fails, WPPO stores a short-lived sentinel so subsequent frontend requests skip the synchronous remote call until the sentinel expires (default `300`, clamped to a 60-second floor). @since 2.0.0.

**Parameters:**
- `$ttl` *(int)* — Backoff TTL in seconds.

---

### `wppo_pagespeed_retry_delay`
Filters the backoff delay in seconds before the single PageSpeed API retry on transport errors (default `2`, clamped 1–10). @since 2.0.0.

**Parameters:**
- `$retry_after` *(int)* — Delay in seconds.

---

### `wppo_litespeed_can_cdn`
Filters whether WPPO CDN rewriting is allowed. When `false`, `maybe_apply_cdn()` is skipped to avoid double CDN mapping when `litespeed_can_cdn` (LSCWP) is active. Respects `litespeed_can_cdn` ecosystem filter. @since 2.0.0.

---

### `wppo_cdn_mapping`
Filters CDN mapping array (one-to-many parity with LSCWP `cdn.cls.php:48`). Each entry keys `cdn_url|ori|ori_dir|include_dirs|include_filetypes|cdn_attr|cdn_urls`, capped at 5 (filter `wppo_cdn_mapping_max`). @since 2.0.0.

---

### `wppo_cdn_url`
Legacy alias for single CDN URL migration (`cdnURL` → `cdnMapping`). @since 2.0.0.

---

### `wppo_cdn_mapping_hosts`
Alias for CDN mapping hosts (round-robin `cdn_urls`/`cdns` per entry). @since 2.0.0.

---

### `wppo_cdn_mapping_entry`
Filters single CDN mapping entry post-sanitize. @since 2.0.0.

---

### `wppo_cdn_mapping_max`
Filters max CDN mappings (default 5). @since 2.0.0.

---

### `wppo_cdn_auto_filetypes`
Filters auto filetypes when `include_filetypes` empty. @since 2.0.0.

---

### `wppo_cdn_url_for_asset`
Filters CDN URL chosen for an asset (deterministic `crc32` round-robin when `cdn_urls` >1). @since 2.0.0.

---

### `wppo_cdn_buffer`
Filters CDN buffer after tag + inline `url()` rewrite (cooperates with `litespeed_buffer_finalize`). Constant `LITESPEED_BYPASS_CDN` bypasses all CDN rewrites. @since 2.0.0.

---

### `wppo_litespeed_swap_purge`
Filters whether OLS swap fallback purge should run (`find /tmp/lshttpd/swap -type f -delete` + `X-LiteSpeed-Purge`). @since 2.0.0.

---

### `wppo_litespeed_vary_groups`
Filters active Vary groups (`role, guest, mobile, webp`) before bridge. @since 2.0.0.

---

### `wppo_litespeed_vary_header`
Filters built X-LiteSpeed-Vary header (`cookie=wppo_role_hash,...`). @since 2.0.0.

---

### `wppo_litespeed_purge_tags`
Filters queued purge tags before transient store (`F,H,Po.{id},PT.{type},T.{id},A.{id},B.{id}` + scope). @since 2.0.0.

---

### `wppo_litespeed_purge_tag_string`
Filters flushed tag string (`tag=...`) on shutdown. @since 2.0.0.

---

### `wppo_crawler_concurrency`
Filters crawler concurrency 1-4 (default 2). @since 2.0.0.

---

### `wppo_crawler_blacklist_threshold`
Filters crawler BLACKLIST_THRESHOLD (default 3 mirroring `crawler.cls.php:26`). @since 2.0.0.

---

### `wppo_crawler_variants`
Filters variant matrix per URL (Accept webp/avif × mobile/desktop × guest/role). @since 2.0.0.

---

### `wppo_esi_available`
Filters whether ESI is available (Enterprise only, OLS has no ESI). @since 2.0.0.

---

### `wppo_esi_enabled`
Filters whether ESI bridge is enabled (settings `esi.enabled` + availability). @since 2.0.0.

---

### `wppo_esi_nonces`
Filters ESI nonce list for widget/cart hole-punching. @since 2.0.0.

---

### `wppo_esi_fallback`
Filters whether ESI AJAX fallback should run on OLS (`DONOTCACHEPAGE`). @since 2.0.0.

---

### `wppo_htaccess_rules`
Filters the full htaccess rules array before return. @since 2.0.0.

---

### `wppo_htaccess_nextgen_rules`
Filters the htaccess rules after next-gen block is appended. @since 2.0.0.

---

### `wppo_nginx_rules`
Filters the nginx rules string. @since 2.0.0.

---

### `wppo_nginx_nextgen_rules`
Filters the nginx rules array after next-gen map is appended. @since 2.0.0.

---

### `wppo_llms_txt_content`
Filters LLMs.txt markdown content before writing. @since 2.0.0.

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
Filters whether LLMs.txt is enabled. @since 2.0.0.

**Parameters:**
- `$enabled` *(bool)* — Whether enabled.

**Example:**
```php
add_filter( 'wppo_llms_txt_enabled', '__return_true' );
```

---

### `wppo_od_should_optimize`
Filters whether Optimization Detective (OD) optimization should be applied. @since 2.0.0.

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

### `wppo_occlusion_fetchpriority_low_enabled`
Filters whether OD-measured occluded (CSS-hidden but in-viewport) images are demoted to `fetchpriority=low`. Additive `image_optimisation.occlusionFetchpriorityLow` flag, default off. The true-LCP node is never demoted and `loading` is never touched, so the single-high and never-lazy+high invariants hold. @since NEXT.

**Parameters:**
- `$enabled` *(bool)* — Whether occlusion demotion is enabled.

**Example:**
```php
add_filter( 'wppo_occlusion_fetchpriority_low_enabled', '__return_true' );
```

---

### `wppo_occlusion_fetchpriority_low_urls`
Filters the occluded image URL list before `fetchpriority=low` demotion. @since NEXT.

**Parameters:**
- `$occluded_urls` *(string[])* — Occluded image URLs.
- `$buffer` *(string)* — The HTML buffer being processed.

**Example:**
```php
add_filter( 'wppo_occlusion_fetchpriority_low_urls', function( $urls, $buffer ) {
    return array_values( array_filter( $urls ) );
}, 10, 2 );
```

---

### `wppo_computed_css_hero_url`
Passes a server-side computed CSS-hero background URL (e.g. derived from enqueued stylesheets where no inline `style=""` exists). Validated as an image on an allowed origin (same-origin or configured CDN); anything else is ignored. @since NEXT.

**Parameters:**
- `$url` *(string)* — Computed hero URL (default `''`).
- `$buffer` *(string|null)* — Current HTML buffer for context.

**Example:**
```php
add_filter( 'wppo_computed_css_hero_url', function( $url, $buffer ) {
    return 'https://example.com/wp-content/uploads/hero-bg.jpg';
}, 10, 2 );
```

---

### `wppo_lcp_first_n`
Filters how many leading images are treated as above-the-fold and never lazy-loaded. @since 2.0.0.

The LCP guardrails resolve the count as OD-measured data (1–3) when Optimization Detective is enabled, else the `lcp_first_n` setting (default 3) falling back to the legacy `excludeFirstImages` key. Return `0` to disable the first-N never-lazy pass; values are clamped to 0–10. Any filter failure fails open to the unfiltered count. An explicit `lcp_first_n` setting of `0` is treated as an intentional disable and wins over OD measurements; disabling while OD is active otherwise requires `lcp_guardrails` set to `false` or a `wppo_lcp_first_n` filter returning `0`.

**Parameters:**
- `$count` *(int)* — Effective first-N count.
- `$image_optimisation` *(array)* — Image optimisation settings.

**Example:**
```php
add_filter( 'wppo_lcp_first_n', function( $count ) {
    return 2;
} );
```

---

### `wppo_bfcache_enabled`
Filters whether bfcache (Instant Back/Forward) is enabled. @since 2.0.0.

Privacy-safe session-token invalidation per Performance Lab Instant Back/Forward: a random token is mirrored in a `wordpress_bfcache_session_{COOKIEHASH}` cookie and embedded in the HTML; on `pageshow` with `persisted=true` (bfcache restore) and on immediate execution (HTTP cache) the tokens are compared and a stale page is cleared and reloaded. The `Cache-Control: no-store` directive is stripped for opted-in sessions and replaced with `private, no-cache, max-age=0, must-revalidate`. Gated by `bfcache.enabled` (false default).

**Parameters:**
- `$enabled` *(bool)* — Whether bfcache is enabled.

**Example:**
```php
add_filter( 'wppo_bfcache_enabled', '__return_true' );
```

---

### `wppo_perf_translations_enabled`
Filters whether Performant Translations (.mo→php) is enabled. @since 2.0.0.

When enabled and `wp_cache_get_salted` exists (WP 6.9+), `.mo` files are compiled to `.php` via the `load_textdomain_mofile` / `load_translation_file` filters using `WP_Translation_File::transform()` and stored per-locale under `wp-content/cache/wppo/lang/` (blog-scoped on multisite, e.g. `wp-content/cache/wppo/lang/site-2/my-plugin-de_DE-abc12345.l10n.php`). The cached file is served when newer than the source `.mo`; OPCache is invalidated on write. Toggle `perf_translations.enabled` defaults to `false`.

**Parameters:**
- `$enabled` *(bool)* — Whether .mo→php compilation is enabled.

**Example:**
```php
add_filter( 'wppo_perf_translations_enabled', '__return_true' );
```

---

### `wppo_ai_adaptive_enabled`
Filters whether AI Adaptive is enabled. @since 2.0.0.

**Parameters:**
- `$enabled` *(bool)* — Whether AI adaptive is enabled (from `ai_adaptive.enabled`, false default).

**Example:**
```php
add_filter( 'wppo_ai_adaptive_enabled', '__return_true' );
```

---

### `wppo_ai_adaptive_eagerness`
Filters AI-learned speculation eagerness. @since 2.0.0.

**Parameters:**
- `$eagerness` *(string)* — `conservative` | `moderate` | `eager`.
- `$rum` *(array)* — RUM aggregates.

---

### `wppo_ai_adaptive_commerce_context`
Filters whether the current request is a commerce/auth context for AI speculation guardrails. @since 2.0.0. When true, AI-learned speculation eagerness is capped at `moderate` and commerce paths are suggested as speculation excludes (manual user settings stay authoritative).

**Parameters:**
- `$is_commerce` *(bool)* — Whether a commerce/auth context was detected (WooCommerce active, cart/checkout/account page, logged-in user, or active cart cookies).

---

### `wppo_ai_adaptive_speculation_rules`
Filters AI-injected speculation rules. @since 2.0.0.

**Parameters:**
- `$rules` *(array)* — Speculation rules array.
- `$urls` *(string[])* — Top predicted prefetch URLs (model + RUM-ranked top URLs, capped at 5).

---

### `wppo_ai_speculation_rum_gating`
Filters whether RUM-gated speculation eagerness applies (issue #1061). @since 2.0.0. When on (default true via `preload_settings.speculationRumGating`), good RUM p75 emits a moderate/eager list rule with top URLs; poor or absent RUM forces conservative.

**Parameters:**
- `$enabled` *(bool)* — Whether RUM gating is enabled.

---

### `wppo_ai_speculation_lcp_threshold`
Filters the LCP p75 (ms) threshold for RUM-gated speculation eagerness (issue #1061). @since 2.0.0. Non-finite or negative values fail open to the default.

**Parameters:**
- `$threshold` *(float)* — LCP p75 threshold in milliseconds (default 2500.0).

---

### `wppo_ai_speculation_inp_threshold`
Filters the INP p75 (ms) threshold for RUM-gated speculation eagerness (issue #1061). @since 2.0.0. Non-finite or negative values fail open to the default.

**Parameters:**
- `$threshold` *(float)* — INP p75 threshold in milliseconds (default 200.0).

---

### `wppo_ai_speculation_eagerness`
Filters the eagerness for a RUM-qualified (good p75) speculation list rule (issue #1061). @since 2.0.0.

**Parameters:**
- `$eagerness` *(string)* — Eagerness value (default `moderate`).
- `$state` *(array)* — Gated state (`lcp_p75`, `inp_p75`, `samples`).

---

### `wppo_ai_speculation_top_urls`
Filters the RUM-ranked top URLs for the gated speculation list rule (issue #1061). @since 2.0.0. Filter output is untrusted: it is re-sanitized (`esc_url_raw`), re-checked against the commerce-prefix and same-site guards, deduped, and re-capped at 5 URLs.

**Parameters:**
- `$urls` *(string[])* — Ranked absolute URLs.

---

### `wppo_ai_anomaly_detected`
Filters the detected performance anomalies (LCP +30% relative or CLS +0.05 absolute delta, RUM-corroborated, 7-day cooldown; plus the local RUM anomaly digest covering LCP/INP +30% relative and CLS +0.05 absolute delta as recent-window medians vs baseline with min-sample gate + tolerance band, @since NEXT). @since 2.0.0.

At most one anomaly is passed; return an empty array to suppress the banner. The legacy `wppo_ai_lcp_regression` filter still runs for LCP anomalies. Digest entries (source `rum-digest`, @since NEXT) carry the trend shape plus `path`, `recent`, `window` (e.g. `recent 2026-09-10 vs baseline 2026-09-01 to 2026-09-09`), `samples`, and `source` so the alert can link the affected path and window; the `metric` may be `lcp`, `inp` (digest only — lab trends carry no INP snapshots), or `cls`.

**Parameters:**
- `$anomalies` *(array[])* — At most one anomaly array (`key`, `metric` (`lcp`|`cls`, plus `inp` for digest entries), `baseline`, `current`, plus `change_pct` for LCP/INP or `change_abs` for CLS; digest entries add `path`, `recent`, `window`, `samples`, `source`).

---

### `wppo_ai_lcp_regression`
Filters the detected LCP regression anomalies (backward compatibility; runs after `wppo_ai_anomaly_detected` for LCP anomalies). @since 2.0.0.

**Parameters:**
- `$anomalies` *(array[])* — At most one anomaly array.

---

### `wppo_ai_anomaly_cooldown_days`
Filters the anomaly cooldown window in days (single banner max). @since 2.0.0.

**Parameters:**
- `$days` *(int)* — Cooldown days (default 7, from `ai_adaptive.anomaly_cooldown_days`).

---

### `wppo_ai_anomaly_min_samples`
Filters the minimum numeric samples before an anomaly arm may fire (trend arm and RUM corroboration gate, plus both digest windows). @since 2.0.0.

**Parameters:**
- `$min` *(int)* — Minimum samples (default 10, from `ai_adaptive.anomaly_min_samples`).

---

### `wppo_ai_css_refresh_enabled`
Filters whether RUM-triggered CSS refresh may queue jobs (issue #1407). @since NEXT. Default off/suggest-only via `ai_adaptive.css_refresh_on_lcp_regression`; fail-open to false.

**Parameters:**
- `$enabled` *(bool)* — Whether the CSS-refresh opt-in is on.

**Example:**
```php
add_filter( 'wppo_ai_css_refresh_enabled', '__return_true' );
```

---

### `wppo_ai_css_refresh_cooldown_days`
Filters the per-URL CSS-refresh cooldown window in days (issue #1407). @since NEXT. Non-numeric or negative values fail open to the current setting.

**Parameters:**
- `$days` *(int)* — Cooldown days (default 7, from `ai_adaptive.css_refresh_cooldown_days`; values below 1 are normalized up to 1).

---

### `wppo_ai_css_refresh_queued`
Fires after an LCP regression queues a used-CSS refresh (issue #1407). @since NEXT. In-repo consumer `Main::on_ai_css_refresh_queued()` regenerates the matching critical-CSS template (`home`/`page`/`single`); third parties may hook additional template refreshes without coupling the bridge to template mapping.

**Parameters:**
- `$url` *(string)* — Regressed URL.
- `$post_id` *(int)* — Queued post ID.
- `$anomaly` *(array)* — The firing LCP anomaly.

---

### `wppo_ai_anomaly_tolerance_pct`
Filters the relative tolerance band (percent) above a relative digest arm threshold (issue #1445). The LCP/INP digest arm fires only when the recent-window median clears `baseline * 1.3 * (1 + tolerance/100)`, so borderline wobble inside the band stays silent. @since NEXT.

**Parameters:**
- `$tolerance` *(float)* — Tolerance percent (default 5.0, from `ai_adaptive.anomaly_tolerance_pct`, clamped 0–50).

---

### `wppo_ai_anomaly_tolerance_abs`
Filters the absolute tolerance band added to the CLS digest threshold (issue #1445). The CLS digest arm fires only when the recent-window median clears `baseline + 0.05 + tolerance`, so borderline wobble inside the band stays silent. @since NEXT.

**Parameters:**
- `$tolerance` *(float)* — Absolute tolerance (default 0.01, from `ai_adaptive.anomaly_tolerance_abs`, clamped 0–1).

---

### `wppo_ai_anomaly_persistence_windows`
Filters the number of trailing windows that must each breach the ratio/delta gate before an anomaly may page (single noisy windows never page). @since NEXT. Values are clamped to 1–29 (trend history holds 30 snapshots and detection needs persistence+1 samples for a non-empty baseline, so 29 keeps every admittable value reachable).

**Parameters:**
- `$windows` *(int)* — Trailing windows (default 3, from `ai_adaptive.anomaly_persistence_windows`).

---

### `wppo_ai_anomaly_p75_min_samples`
Filters the minimum RUM samples before field data may corroborate a trend anomaly (enforced per metric arm). @since NEXT. Values are clamped to 1–30.

**Parameters:**
- `$min` *(int)* — Minimum RUM samples (default 10, from `ai_adaptive.anomaly_p75_min_samples`).

---

### `wppo_speculation_list_urls`
Filters the high-value speculation list URLs (home + `performance_audit.high_value_urls` + RUM top URLs, same-site validated, cart/checkout/account/query-string/fragment excluded, capped at 10). @since 2.0.0.

Emitted as a `{"source":"list"}` rule via the `wp_speculation_rules` filter (WP 6.8+) when `preload_settings.enableSpeculationRules` is on. Return an empty array to suppress the list rule.

**Parameters:**
- `$urls` *(string[])* — Validated list URLs.

---

### `wppo_speculation_document_rule`
Filters the archive first-post document rule before it is appended. @since 2.0.0.

Emitted as a `{"source":"document"}` rule (first-post `href_matches` + first-post `selector_matches`) via the `wp_speculation_rules` filter (WP 6.8+) on archive views when `preload_settings.enableSpeculationRules` is on and `preload_settings.speculationDocumentRules` is not `false`. Return a non-array to suppress the document rule.

**Parameters:**
- `$archive_rule` *(array)* — The archive document rule.

---

### `wppo_speculation_list_rules`
Filters the speculation rules after the high-value list rule is appended. @since 2.0.0.

Trusted-code-only: a non-array return falls back to the pre-filter rules and non-array entries are dropped.

**Parameters:**
- `$rules` *(array)* — Speculation rules array.
- `$urls` *(string[])* — List URLs that were appended.

---

### `wppo_speculation_prerender_list_urls`
Filters the high-value prerender list URLs before the dedicated prerender rule is registered/appended. @since NEXT.

Emitted as a `{"source":"list"}` prerender rule with `moderate` eagerness via `Main::wppo_register_speculation_rules()` (WP 6.8+ object path and legacy array path) when `preload_settings.enableSpeculationRules` and the opt-in `preload_settings.speculationPrerenderList` are on, the static-cache + RUM-qualified gate passes, and the visitor is not logged-in/commerce. Post-filter output is re-validated (same-origin, no commerce/query), deduped, and re-sliced to `speculationTopUrlsLimit`.

**Parameters:**
- `$urls` *(string[])* — Validated prerender URLs (home + capped RUM top URLs).

---

### `wppo_speculation_prerender_list_rule`
Filters the high-value prerender list rule before it is registered/appended. @since NEXT.

Post-filter validation enforces `source: list`, an allowlisted eagerness (invalid values fall back to `moderate`), and re-validated/re-sliced `urls`; a rule with the wrong source or no valid URLs is dropped (input returned unchanged).

**Parameters:**
- `$rule` *(array)* — The prerender list rule (`source`, `urls`, `eagerness`).

---

### `wppo_speculation_prerender_list_rules`
Filters the speculation rules after the high-value prerender list rule is appended (legacy array path only; the WP 6.8+ object path registers via `add_rule()` instead). @since NEXT.

Trusted-code-only: a non-array return falls back to the pre-filter rules and non-array entries are dropped.

**Parameters:**
- `$rules` *(array)* — Updated rules.
- `$urls` *(string[])* — Prerender list URLs that were appended.

---

### `wppo_speculation_exclusions`
Filters the speculation-rules href exclusion patterns (auth, admin, REST, generic commerce cart/checkout/account, WooCommerce dynamic paths, plus user `speculationExcludeUrls`). Merged fill-gaps-only via `wp_speculation_rules_href_exclude_paths` (WP 6.8+) so the core ruleset is never duplicated. @since 2.0.0.

Intentionally narrow: no `*logout*` / `*nonce*` / `*add-to-cart*` substring wildcards are emitted — those are query-param actions (`?_wpnonce=`, `?action=logout`, `?add-to-cart=`) already excluded by core's `?`-URL handling, and substring wildcards would also block legitimate slugs containing those words (e.g. a post about "add to cart").

**Parameters:**
- `$excludes` *(string[])* — Canonical exclusion patterns.
- `$preload_settings` *(array)* — The plugin's preload_settings option value.

---

### `wppo_edge_cache_enabled`
Filters whether Edge HTML Cache (N2) is enabled. @since 2.0.0.

Host-agnostic Cloudflare Workers / Bunny Edge adapter deploying `cache/wppo/{domain}/{path}/index.html` with stale-while-revalidate (<30ms global TTFB). Gated by `edge_cache.enabled` (false default). Purge via `Edge_Purger::purge_all()` on `wppo_after_cache_clear` alongside `CDN_Purger` (lock via `Util::transient_key('wppo_edge_purge_lock')`). Worker template `templates/cloudflare-worker.js` + `wrangler.toml` generator `Edge_Cache::get_wrangler_toml()` + Bunny `templates/bunny-edge.js` / `Edge_Cache::get_bunny_edge_js()`.

**Parameters:**
- `$enabled` *(bool)* — Whether edge cache is enabled.

**Example:**
```php
add_filter( 'wppo_edge_cache_enabled', '__return_true' );
```

---

### `wppo_edge_cache_worker_content`
Filters Cloudflare Worker JS content. @since 2.0.0.

**Parameters:**
- `$content` *(string)* — Worker JS source after placeholder replacement.
- `$config` *(array)* — Adapter config (origin_url, cache_ttl, swr, provider).

---

### `wppo_edge_cache_wrangler_content`
Filters wrangler.toml content. @since 2.0.0.

**Parameters:**
- `$toml` *(string)* — wrangler.toml source.
- `$config` *(array)* — Adapter config.

---

### `wppo_edge_cache_bunny_content`
Filters Bunny edge JS content. @since 2.0.0.

**Parameters:**
- `$content` *(string)* — Bunny JS source.
- `$config` *(array)* — Adapter config.

---

### `wppo_edge_cache_config`
Filters edge cache adapter config before template generation. @since 2.0.0.

**Parameters:**
- `$config` *(array)* — `origin_url`, `cache_ttl`, `swr`, `provider`.

---

### `wppo_perf_translations_file_written`
Fires after a compiled translation file is written. @since 2.0.0.

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
Filters whether the Server-Timing header is emitted. @since 2.0.0.

**Parameters:**
- `$enabled` *(bool)* — Whether Server-Timing is enabled (from `performance_audit.server_timing_enabled`, `false` default).

When `true`, the plugin registers `wp_finalized_template_enhancement_output_buffer` (see WordPress Core section below), which opts into the template-enhancement buffer (priority 1000 by default) and disables response streaming — TTFB increases while TTLB unchanged. Keep disabled by default; header is emitted only on cache-miss generation passes (`advanced-cache.php` serves cached pages without booting WordPress).

**Performance Lab interop (@since 2.0.0):** When the Performance Lab Server-Timing module is active (`perflab_server_timing_register_metric()` / `perflab_wrap_server_timed_call()` present — `Main::is_pl_server_timing_active()`), Performance Lab owns the `Server-Timing` header and prefixes every registered metric slug with `wp-` (its defaults `before-template` / `template` / `total` surface as `wp-before-template` / `wp-template` / `wp-total`). The plugin then avoids duplicate/conflicting metric names:

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

### `wppo_rum_throttle_threshold`
Filters the per-minute collection volume that engages the RUM high-traffic auto-throttle (issue #1214). When the site-wide beacon count for the current minute (the windowed `wppo_rum_global` bucket, so no new transient writes) reaches this threshold, the effective sample rate halves (floored at 1) for the rest of the minute. A non-positive value disables the throttle. Non-numeric filter returns fall back to the default (fail-open). @since NEXT.

**Parameters:**
- `$threshold` *(int)* — Beacons per minute that engage the throttle. Default `60` (`RUM::RUM_THROTTLE_THRESHOLD_DEFAULT`).

**Example:**
```php
add_filter( 'wppo_rum_throttle_threshold', function() {
    return 120; // Throttle later on high-capacity infrastructure.
} );
```

---

### `wppo_rum_effective_sample_rate`
Filters the final RUM effective sample rate after the high-traffic auto-throttle (issue #1214). Out-of-range values (outside 1–100) fall back to the unfiltered effective rate. Sampling is a lossy hint only: the client (`src/rum.js`) and the server (`RUM::store_sample()`) each roll independently at this rate, so stored volume is approximately rate²/100. @since NEXT.

**Parameters:**
- `$effective` *(int)* — Effective rate in 1–100 (configured rate, halved under throttle).
- `$base` *(int)* — Configured `performance_audit.rum_sample_rate` before throttling.

**Example:**
```php
add_filter( 'wppo_rum_effective_sample_rate', function( $effective, $base ) {
    return min( $effective, 25 ); // Never sample more than 25% on this site.
}, 10, 2 );
```

---

## 🔌 WordPress Core Late-Header Hooks Used by Plugin

### `wp_finalized_template_enhancement_output_buffer` (alias `wp_send_late_headers`)
WordPress 6.9 late-header / final buffer action. Canonical place to emit late headers (Server-Timing, ETag/304) before flush. @since 2.0.0.

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
// @since 2.0.0
if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) && $this->server_timing_enabled() ) {
    add_action( 'template_redirect', array( $this, 'capture_template_start' ), 0 );
    add_action( 'wp_finalized_template_enhancement_output_buffer', array( $this, 'emit_server_timing_header' ), 0, 1 );
}
```

### `wppo_combine_preload_fetchpriority`
Filters fetchpriority for the combined-CSS `rel="preload"` hint (external path only, not inline). Default `high` prioritises LCP stylesheet. Return falsy to suppress. @since 2.0.0.

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
Filters fetchpriority for each deferred script handle. Default `low` deprioritises non-render-blocking scripts. Return `high` for an LCP-critical handle, falsy to suppress. Guards WP 6.9+ native `wp_script_add_data` plus pre-6.9 regex fallback. @since 2.0.0.

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
Filters whether deferred classic scripts are moved to the footer on WP 6.9+ (native `in_footer` migration, Trac #63486). Default `true`; the plugin sets the `'group'` data key (core reads `'group'` for footer placement — the `'in_footer'` data key itself is never read for classic scripts) unless already footer-bound. Return `false` per handle to keep a script in the head (e.g. `document.write` dependencies). @since 2.0.0.

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
WordPress 6.9 filter for the template-enhancement buffer. @since 2.0.0.

**Parameters:**
- `$filtered_output` *(string)* — Filtered output from previous callbacks.
- `$output` *(string)* — Raw output buffer content.

Used by `Cache::process_buffer_for_cache()` at priority 10 to process (image optimisation, minification, CDN rewrite) without saving; persistence is via `Cache::stash_cache()` on the finalized action above. See also `Main::process_used_css_only` (priority 20) and `Image_Optimisation::prioritize_lcp_in_buffer` (priority 30) on the same filter.

---

## Additional filters & actions (shipped, not yet in the main list)

### `wppo_inline_combined_css`
Filters whether the combined/minified CSS is inlined via core `wp_maybe_inline_styles()`. Return falsy to disable inlining (e.g. when serving the combined file from a CDN). @since 2.0.0.

**Parameters:**
- `$enabled` *(bool)* — Default `true`.

---

### `wppo_exclude_defer_js`
Filters the resolved defer-JS exclusion list after preset merging. @since 2.0.0.

**Parameters:**
- `$preset` *(string[])* — Exclusion patterns.

---

### `wppo_delay_js_exclusions`
Filters the delay-JS exclusion preset list itself. @since 2.0.0.

**Parameters:**
- `$preset` *(string[])* — Preset exclusion patterns (`Main::get_delay_js_exclusions`).

---

### `wppo_delay_js_commerce_exclusions`
Filters the delay-JS commerce preset exclusions (jQuery, cart-fragments, checkout). @since 2.0.0.

**Parameters:**
- `$preset` *(string[])* — Commerce preset exclusion patterns.

---

### `wppo_delay_js_builder_exclusions`
Filters the delay-JS builder preset exclusions (Elementor, Divi, Bricks, WPBakery, Oxygen, block interactivity runtimes). Merged via `array_unique` with the commerce/slider presets and user excludes. @since 2.0.0.

**Parameters:**
- `$preset` *(string[])* — Builder preset exclusion patterns.

---

### `wppo_delay_js_slider_exclusions`
Filters the delay-JS slider preset exclusions (revslider, swiper, slick, etc.). @since 2.0.0.

**Parameters:**
- `$preset` *(string[])* — Slider preset exclusion patterns.

---

### `wppo_delay_js_interaction_exclusions`
Filters the delay-JS first-click interaction preset exclusions (popup/dialog, mobile-menu, add-to-cart handles). Merged into the global preset when `delayJSInteractionPreset` is on (default). @since 2.0.0.

**Parameters:**
- `$preset` *(string[])* — Interaction preset exclusion patterns.

---

### `wppo_delay_js_consent_exclusions`
Filters the delay-JS consent compatibility preset exclusions (CookieYes, Cookiebot, Complianz, Borlabs, OneTrust, etc.). Opt-in via the `delayJSConsentPreset` setting; merged additively with manual exclusions. @since NEXT.

**Parameters:**
- `$preset` *(string[])* — Consent preset exclusion patterns.

---

### `wppo_delay_js_analytics_exclusions`
Filters the delay-JS analytics compatibility preset exclusions (GA4 gtag, Matomo, Plausible, etc.). Opt-in via the `delayJSAnalyticsPreset` setting; merged additively with manual exclusions. @since NEXT.

**Parameters:**
- `$preset` *(string[])* — Analytics preset exclusion patterns.

---

### `wppo_delay_js_gallery_exclusions`
Filters the delay-JS gallery compatibility preset exclusions (PhotoSwipe, Fancybox, Envira, FooGallery, etc.). Opt-in via the `delayJSGalleryPreset` setting; merged additively with manual exclusions. @since NEXT.

**Parameters:**
- `$preset` *(string[])* — Gallery preset exclusion patterns.

---

### `wppo_delay_js_jquery_exclusions`
Filters the delay-JS jQuery legacy preset exclusions (jQuery UI and legacy jQuery plugins; shops stay covered by the commerce preset). Opt-in via the `delayJSJqueryPreset` setting; merged additively with manual exclusions. @since NEXT.

**Parameters:**
- `$preset` *(string[])* — jQuery preset exclusion patterns.

---

### `wppo_delay_js_third_party_denylist`
Filters the curated one-click third-party delay denylist (analytics, ads, social, chat, embeds). Payment gateways (Stripe, PayPal) and consent-management banners (Cookiebot, OneTrust, TrustArc, Quantcast) are intentionally excluded from the preset so one-click mode keeps them eager; add them via the extra-denylist textarea if desired. @since NEXT.

**Parameters:**
- `$preset` *(string[])* — Third-party denylist patterns.

---

### `wppo_delay_js_third_party_allowlist`
Filters the user third-party allowlist that always wins over the denylist (scripts that must stay eager). @since NEXT.

**Parameters:**
- `$list` *(string[])* — Allowlist patterns, pre-populated from the `delayJSThirdPartyAllowlist` textarea setting. Callbacks should merge/append (e.g. `array_merge( $list, [...] )`) rather than replace, so user entries are preserved.

---

### `wppo_delay_js_third_party_auto_patterns`
Filters the curated known-vendor URL patterns used by the opt-in auto third-party delay mode (`delayJSThirdPartyAuto`). Auto-matched scripts delay until the browser is idle (load-when-idle parity with the manual idle list). Fail-open: non-array or throwing callbacks fall back to the built-in preset; a valid empty array is honored and disables auto mode. Guarded with `has_filter()` so requests without a registered callback never pay for the filter. Matching is src-substring on both output paths; handle matching (pre-slash segment, word-boundary) applies on the `script_loader_tag` path only — the buffered path has no handle. Callbacks should merge/append (e.g. `$patterns[] = 'cdn.example.com/tracker';`) rather than replace, so built-in vendors are preserved. @since NEXT.

**Parameters:**
- `$preset` *(string[])* — Auto third-party URL patterns.

**Example:**
```php
add_filter( 'wppo_delay_js_third_party_auto_patterns', function( $patterns ) {
	$patterns[] = 'cdn.example.com/tracker';
	return $patterns;
} );
```

---

### `wppo_htaccess_cache_vary_rules`
Filters the `.htaccess` cache-vary rules block before writing. @since 2.0.0.

**Parameters:**
- `$rules` *(string[])* — Rule lines.
- `$cache_vary` *(bool)* — Whether vary rules are enabled.

---

### `wppo_object_cache_dropin_path`
Filters the object-cache drop-in path (`WP_CONTENT_DIR . '/object-cache.php'` by default). @since 2.0.0.

**Parameters:**
- `$path` *(string)* — Drop-in file path.

---

### `wppo_redis_allow_request_password`
Filters whether a Redis password supplied via the REST request body may be used for `object_cache` operations. Default `false` — passwords must come from `WPPO_REDIS_PASSWORD` or the config file. @since 2.0.0.

**Parameters:**
- `$allowed` *(bool)* — Default `false`.

---

### `wppo_telemetry_verify_ssl`
Filters whether the local telemetry cURL scan verifies TLS certificates. @since 2.0.0.

**Parameters:**
- `$verify_ssl` *(bool)* — Default `true`.
- `$url` *(string)* — URL being scanned.

---

### `wppo_telemetry_allow_remote_head`
Filters whether HEAD requests are allowed to non-local (remote) telemetry targets. Default `false` (localhost only). @since 2.0.0.

**Parameters:**
- `$allowed` *(bool)* — Default `false`.
- `$url` *(string)* — Target URL.

---

### `wppo_debug_log` (action)
Debug logging sink fired with diagnostic messages (cache domain validation, CDN purge failures). No-op unless listeners are attached. @since 2.0.0.

**Parameters:**
- `$message` *(string)* — Diagnostic message.
- `$context` *(array, optional)* — Structured detail for the event (for example `array( 'exception' => Throwable )` in the HTML minifier). Listeners should accept it as an optional second argument.

---

### `wppo_varnish_purge_max_urls`
Filters the max URLs per Varnish purge batch (min 1). Default `20`. @since 2.0.0.

**Parameters:**
- `$max_urls` *(int)* — Batch size cap.

---

### `wppo_cron_discovery_limit`
Filters the per-run discovery cap for preload URL discovery. Default `50`. @since 2.0.0.

**Parameters:**
- `$limit` *(int)* — Discovered items per cron run.

---

### `wppo_filesize_limit_bytes`
Filters the max source image size accepted for conversion. Default `20 * 1024 * 1024`. @since 2.0.0.

**Parameters:**
- `$max_bytes` *(int)* — Byte limit.

---

### `wppo_convert_gain_map_images`
Filters whether gain-map (HDR) images are converted. Return truthy to allow; default `false` skips them. @since 2.0.0.

**Parameters:**
- `$allow` *(bool)* — Default `false`.

---

### `wppo_skip_small_threshold_bytes`
Filters the byte threshold at or under which source images skip conversion (tiny files cost more CPU than they save). Default `5120`. @since 2.0.0.

**Parameters:**
- `$threshold` *(int)* — Threshold in bytes (>= 0).

---

### `wppo_smart_quality`
Filters whether smart quality mapping is applied to image conversion. Return falsy to use the flat quality rule without AVIF/WebP mapping or size/role offsets. @since 2.0.0.

**Parameters:**
- `$smart` *(bool)* — Default from the `image_optimisation.smartQuality` setting (`true`).

---

### `wppo_smart_quality_value`
Filters the resolved smart quality value before size/role offsets are applied. Return an int (or numeric string) in 1-100 to override the heuristic outright; booleans and out-of-range values fail open to the base quality. @since NEXT.

**Parameters:**
- `$quality` *(int)* — Base quality before size/role offsets.
- `$mime` *(string)* — Output MIME type (e.g. `image/webp`).
- `$effective_size` *(array)* — Effective source dimensions (`width`/`height`), derived from the `-WxH` filename suffix when `$size` is empty.
- `$source_image` *(string)* — Source filesystem path (may be empty).

**Example:**

```php
add_filter( 'wppo_smart_quality_value', function ( $quality, $mime, $size, $source ) {
    return 70;
}, 10, 4 );
```

---

### `wppo_smart_pipeline_enabled`
Kill-switch filter for the size-compare smart-compress + local LQIP placeholder pipeline. Return falsy to disable both features: oversized converted siblings are kept (legacy behaviour) and native-lazy images receive no placeholder attributes. Server-side only — zero external HTTP either way. @since NEXT.

**Parameters:**
- `$enabled` *(bool)* — Default from the `image_optimisation.discardOversizedSibling` setting (`true`).

---

### `wppo_discard_oversized_sibling`
Filters whether a converted sibling at or above its source byte size is discarded (source kept, status recorded as `skipped`). Return falsy to keep the sibling. Fail-open: missing/unreadable files are never discarded. @since NEXT.

**Parameters:**
- `$discard` *(bool)* — Whether to discard the sibling.
- `$source_path` *(string)* — Filesystem path to the source image.
- `$sibling_path` *(string)* — Filesystem path to the converted sibling.

---

### `wppo_auto_alt_enabled`
Filters whether missing-alt autofill is enabled. When truthy, `<img>` tags with no `alt` attribute get a deterministic derived alt (sanitized filename, falling back to the parent post title); existing `alt` attributes — including decorative `alt=""` — are never touched. Runs as a standalone buffer pass when lazy-loading is disabled, so the toggle works independently of `lazyLoadImages`. Data-URI images are included in both the Tag Processor and regex paths (derived from the parent title/filter when no filename exists). @since 2.0.0.

**Parameters:**
- `$enabled` *(bool)* — Default from the `image_optimisation.autoAltText` setting (`false`).

---

### `wppo_auto_alt_text`
Filters the derived alt text for an image missing an `alt` attribute. Return a non-empty string to override, or an empty string to leave the tag untouched. No external HTTP is performed. @since 2.0.0.

**Parameters:**
- `$alt` *(string)* — The derived alt text (may be empty).
- `$src` *(string)* — The image `src` URL.

**Example:**

```php
add_filter( 'wppo_auto_alt_text', function ( $alt, $src ) {
    return '' !== $alt ? $alt : 'Site photo';
}, 10, 2 );
```

---

### `wppo_max_longest_edge_px`
Filters the longest-edge downscale cap in pixels applied when converting uploads to WebP/AVIF. Oversized sources are downscaled in-memory so generated outputs never exceed this edge; the original upload file is never modified, and a downscale that would not shrink output keeps the original. Applies to the JPEG/PNG GD path, the WebP-source AVIF path, and the GIF-via-Imagick path (coalesced-frame thumbnail); the 5000px dimension guard is evaluated against post-cap dimensions so cappable images convert instead of failing. Raw source pixel dimensions are still bounded before decode by a separate memory-budget guard, filterable via `wppo_max_source_pixels` (defaults to a PHP-memory-derived budget, clamped to 4,000,000–80,000,000 pixels), so decompression bombs cannot reach full GD decode. `0` disables the cap. @since 2.0.0.

**Parameters:**
- `$cap` *(int)* — Cap in pixels. Default from the `image_optimisation.maxLongestEdgePx` setting (`2560`).

**Example:**

```php
add_filter( 'wppo_max_longest_edge_px', function () {
    return 1920;
} );
```

---

### `wppo_font_metric_fallback_css`
Filters the generated size-adjust fallback CSS for a font family. @since 2.0.0.

**Parameters:**
- `$css` *(string)* — Fallback `@font-face` CSS.
- `$family` *(string)* — Font family name.

---

### `wppo_font_display`
Filters the `font-display` value injected into self-hosted Google Fonts CSS and combined CSS (default `swap`). Return a falsy value to skip injection (opt-out). Unknown values fall back to `swap`. @since NEXT.

**Parameters:**
- `$display` *(string)* — Desired value (`swap|block|fallback|optional|auto`).

---

### `wppo_skip_combine_on_small_block_theme`
Filters whether combining styles is skipped for small block themes under the handle limit. Return falsy to always combine. @since 2.0.0.

**Parameters:**
- `$skip` *(bool)* — Default `true`.
- `$eligible_handles` *(string[])* — Handles considered.
- `$limit` *(int)* — Handle-count threshold.

---

### `wppo_safe_css_combine_fallback`

Filters whether the safe CSS combine fallback is enabled. When true (default) the combine path (`Cache::combine_css()`) verifies the combined payload is non-empty and the written file is valid (`is_file`, `is_readable`, `filesize > 0`) before dequeuing original handles, and `Used_CSS::inject_used_css()` verifies non-empty payload, successful head match, and confirms injection before stripping original `<link>` tags — fail-open to originals with throttled `Log::add()` on any guard failure. Return falsy to restore legacy (unsafe) stripping behavior. @since 2.0.0.

**Parameters:**
- `$enabled` *(bool)* — Default `true`.

**Example:**

```php
add_filter( 'wppo_safe_css_combine_fallback', '__return_false' ); // disable safe guards (not recommended)
```

---

### `wppo_inline_combined_css`
Filters whether the combined/minified CSS is inlined via core `wp_maybe_inline_styles()`. Return falsy to disable inlining (e.g. when serving the combined file from a CDN); the combined file is still generated and enqueued in that case. @since 2.0.0.

---

### `wppo_ccss_allowed_stylesheet_host`
Filters whether an external stylesheet host is allowed during Critical CSS generation. Default `false` (self-hosted only). @since 2.0.0.

**Parameters:**
- `$allowed` *(bool)* — Default `false`.
- `$host` *(string)* — Stylesheet host.

---

### `wppo_ccss_sanitize_inline`
Filters sanitized inline Critical CSS before it is written/inlined. @since 2.0.0.

**Parameters:**
- `$css` *(string)* — Sanitized CSS.

---

### `wppo_ccss_safelist`
Filters the Critical CSS user safelist (selectors always kept in Critical CSS, e.g. hidden or JS-injected selectors). Backed by the additive `file_optimisation.ccssSafelistExtra` setting (one selector per line, default empty = current behaviour). @since 2.0.0.

**Parameters:**
- `$list` *(string[])* — Safelisted selectors.

> **Note — checksum auto-regen and inline cap:** source-CSS checksums stored at generation time trigger regeneration when stylesheet content changes even if mtime is preserved (see `wppo_ccss_checksum_ttl`); oversize Critical CSS is served from a per-template file instead of inline (20 KB `ccssMaxSize` cap). Unlisted dynamic content stays deferred by design. Checksum-triggered regen (frontend probe plus `save_post` requeue) is gated by the additive `file_optimisation.ccssChecksumRegen` setting (default true, issue #1388) — set it to `false` to opt out and keep the pre-feature safelist-only behaviour.

---

### `wppo_ccss_checksum_ttl`
Filters how long a Critical CSS source checksum is kept. @since 2.0.0.

**Parameters:**
- `$ttl` *(int)* — Time to live in seconds. Default `WEEK_IN_SECONDS`.

---

### `wppo_ccss_generation_timeout`
Filters the wall-clock budget in seconds for one Critical CSS generation run (fetch plus parse). On expiry the run aborts fail-open: the previously stored CSS is left untouched, no partial output is stored or inlined, and a retry is scheduled with exponential backoff (5min, 10min, 20min, 40min steps, escalating to `failed` once generic + timeout failures combined reach the `ccssMaxRetries` cap; only the escalation is logged). Below the cap the status is `queued` (1h TTL) while terminal outcomes are `failed` (1-day TTL). Stored values heal to the default `25` when missing, non-numeric, zero, or negative; in-range stored values and valid filter output clamp to 1–120. Non-numeric filter output is ignored and the stored budget is kept. Default `25` (stored `file_optimisation.ccssGenTimeout`). @since NEXT.

**Parameters:**
- `$timeout` *(int)* — Budget in seconds. Default `25`.

**Example:**

```php
add_filter( 'wppo_ccss_generation_timeout', static function() { return 45; } );
```

---

### `wppo_ccss_queue_cap`
Filters how many RUM-worst-first Critical CSS templates are queued per regeneration run. Templates are ordered slowest-p75-first, so the budget lands on worst pages first; the next cron run picks up the remainder. Stored values heal to the default `5` when missing; non-numeric or non-positive stored values mean uncapped (current behaviour). Valid filter output clamps to 1–100; non-numeric filter output is ignored and the stored cap is kept. Default `5` (stored `file_optimisation.ccssQueueCap`). @since NEXT.

**Parameters:**
- `$cap` *(int)* — Per-run cap. Default `5`.

**Example:**

```php
add_filter( 'wppo_ccss_queue_cap', static function() { return 10; } );
```

---

### `wppo_ccss_inline_budget`
Filters the gzipped inline budget in bytes for Critical CSS output (issue #1388). Over-budget output is never inlined: the prior good file is kept, no inline CSS is emitted, and stylesheet deferral is skipped for the request (deferred full stylesheet plus used CSS only). The over-budget warning is throttled to once per template per 12h. Stored values heal to the default `14` KB when missing or out of range; valid filter output clamps to 1–100 KB (`MIN..MAX_CCSS_INLINE_BUDGET_BYTES`); non-numeric filter output is ignored and the stored budget is kept. Default `14 * 1024` (stored `file_optimisation.ccssInlineBudgetKb`). @since NEXT.

**Parameters:**
- `$budget` *(int)* — Budget in bytes. Default `14336`.

**Example:**

```php
add_filter( 'wppo_ccss_inline_budget', static function() { return 20 * 1024; } );
```

---

### `wppo_ccss_excluded_post_types`
Filters post types skipped by Critical CSS and Used CSS generation. The filter is always ADDITIVE over the built-in builder defaults (`fl-builder-template`, `elementor_library`): returned slugs are merged with the defaults, and an empty (or all-invalid) return is ignored so builder-template protection cannot be silently disabled — there is no opt-out. Backed by the additive `file_optimisation.ccssExcludedPostTypes` setting (one post type per line; empty or all-invalid keeps the defaults). Shared contract: the CCSS-named key/filter intentionally serves both pipelines for backward compatibility. Used-CSS retries are intentionally out of scope — excluded posts are never queued, so no retry counter exists there. Fail-open: non-array/non-string output is ignored and the setting-derived list is kept. Filter accepts string[] or a newline/comma-delimited string (parsed via the shared parser). @since NEXT.

**Parameters:**
- `$excluded` *(string[])* — Excluded post type slugs. Default from `file_optimisation.ccssExcludedPostTypes`.

**Example:**

```php
add_filter( 'wppo_ccss_excluded_post_types', static function( $excluded ) {
	$excluded[] = 'my_builder_library';
	return $excluded;
} );
```

---

### `wppo_ccss_max_retries`
Filters how many consecutive generation failures (generic + timeout combined) a Critical CSS template tolerates before escalating to the terminal `failed` state. Below the cap the template stays `queued` with a retry scheduled (exponential backoff); `0` means fail fast with no retries. Only the escalation is logged — retries below the cap stay silent. Stored values heal to the default `5` when missing or non-numeric; stored and filter values clamp to 0–5; non-numeric filter output is ignored and the stored cap is kept. Default `5` (stored `file_optimisation.ccssMaxRetries`). @since NEXT.

**Parameters:**
- `$cap` *(int)* — Retry cap. Default `5`.

**Example:**

```php
add_filter( 'wppo_ccss_max_retries', static function() { return 3; } );
```

---

### `wppo_ccss_field_lcp_preload`
Filters whether the Critical-CSS path emits the field-measured LCP image preload (`<link rel="preload" as="image" fetchpriority="high">` at `wp_head:0`). The candidate is the RUM field-LCP winner for the page (above the sample gate and freshness TTL) falling back to the stored PageSpeed heuristic; same-origin and image-type guards always apply. @since NEXT.

**Independence note:** this hint belongs to the critical-CSS feature and fires independently of the image-pipeline LCP toggles (`fieldLcpOverride`, `autoPreloadLCP`, `prioritizeLCPImages`, `autoLcpPreload`). When the image pipeline's auto-LCP path is enabled it owns the hint (with responsive `imagesrcset`/`imagesizes`) and the Critical-CSS path yields, so at most one preload prints per hero either way. Return `false` to disable the Critical-CSS-path hint without disabling critical CSS itself. Default `true`.

**Parameters:**
- `$allowed` *(bool)* — Whether the Critical-CSS-path LCP preload may emit. Default `true`.

**Example:**

```php
add_filter( 'wppo_ccss_field_lcp_preload', '__return_false' );
```

---

### `wppo_crawler_use_nproc`
Filters whether `nproc` may be probed (via `shell_exec`) as a fallback for CPU-count detection. Default `false`. @since 2.0.0.

**Parameters:**
- `$use_nproc` *(bool)* — Default `false`.

---

### `wppo_crawler_load_limit`
Filters the server-load ceiling above which the crawler idles. @since 2.0.0.

**Parameters:**
- `$limit` *(float)* — Default derived from CPU count (min `0.1`).

---

### `wppo_crawler_is_overloaded`
Filters the final overloaded verdict for the crawler. @since 2.0.0.

**Parameters:**
- `$overloaded` *(bool)* — Whether load exceeds the limit.
- `$load` *(float)* — Current 1-minute load average.
- `$limit` *(float)* — Configured load limit.

---

### `wppo_crawler_disable_curl`
Filters whether curl_multi parallel fetching is disabled (falling back to `wp_remote_get`). @since 2.0.0.

**Parameters:**
- `$disable` *(bool)* — Default `false`.

---

### `wppo_crawler_full_matrix`
Filters whether the crawler walks the full variant matrix per URL (Accept webp/avif × mobile/desktop × guest/role). @since 2.0.0.

**Parameters:**
- `$full` *(bool)* — Default `false` (core URLs only).

---

### `wppo_crawler_urls`
Filters the resolved list of URLs the crawler will warm. @since 2.0.0.

**Parameters:**
- `$urls` *(string[])* — URL list.

---

### `wppo_crawler_sitemap_urls`
Filters sitemap-discovered URLs before the crawler cap is enforced. @since 2.0.0.

**Parameters:**
- `$sitemap_urls` *(string[])* — Discovered URLs.
- `$cap` *(int)* — Discovery cap.

---

### `wppo_litespeed_purge_sync`
Filters whether LiteSpeed purges run synchronously instead of queueing. @since 2.0.0.

**Parameters:**
- `$purge_sync` *(bool)* — Resolved setting.

---

### `wppo_litespeed_effective_mode`
Filters the effective LiteSpeed coexistence mode after detection. @since 2.0.0.

**Parameters:**
- `$mode` *(string)* — Effective mode string.
- `$requested` *(string)* — Requested mode from settings.

---

### `wppo_litespeed_should_disable_optimizer`
Filters whether the LiteSpeed built-in optimizer should be disabled while WPPO owns caching. @since 2.0.0.

**Parameters:**
- `$disable` *(bool)* — Default derived from effective mode.
- `$mode` *(string)* — Effective mode.

---

### `wppo_litespeed_is_lscache_active`
Filters whether the LSCache engine is detected as active for the current request. @since 2.0.0.

**Parameters:**
- `$active` *(bool)* — Detection result.

---

### `wppo_litespeed_lscache_vary_value`
Filters the `_lscache_vary` cookie value (12-char hash). @since 2.0.0.

**Parameters:**
- `$value` *(string)* — Hash.
- `$payload` *(array)* — Active vary payload used to build it.

---

### `wppo_litespeed_vary_fallback`
Filters the vary cookie fallback header used when the vary bridge cannot seed a cookie. @since 2.0.0.

**Parameters:**
- `$fallback` *(string)* — Fallback header value.

---

### `wppo_litespeed_tag_post_id`
Filters the post ID used for `Po.{id}` LiteSpeed tag fan-out. Return `0` to skip the post tag. @since 2.0.0.

**Parameters:**
- `$post_id` *(int)* — Queried object ID.

---

### `wppo_litespeed_nocache_reason`
Filters the human-readable reason emitted with LiteSpeed no-cache headers. @since 2.0.0.

**Parameters:**
- `$reason` *(string)* — Reason slug.

---

### `wppo_litespeed_nocache_header`
Filters the final `X-LiteSpeed-Cache-Control: no-cache` header line. @since 2.0.0.

**Parameters:**
- `$header` *(string)* — Header value.
- `$reason` *(string)* — Reason slug.

---

### `wppo_litespeed_cache_control_header`
Filters the LiteSpeed `Cache-Control` header value for the resolved TTL. @since 2.0.0.

**Parameters:**
- `$header` *(string)* — Header value.
- `$ttl` *(int)* — Resolved TTL in seconds.

---

### `wppo_litespeed_esi_available`
Filters whether LiteSpeed ESI is considered available (gates the whole ESI bridge). Default `false`. @since 2.0.0.

**Parameters:**
- `$available` *(bool)* — Default `false`.

---

### `wppo_esi_should_punch_hole`
Filters whether an ESI block should punch a hole. Return `null` to defer to default detection. @since 2.0.0.

**Parameters:**
- `$punch` *(bool|null)* — Default `null` (auto).
- `$context` *(string)* — Block context.

---

### `wppo_esi_block`
Filters the ESI block name before the `<esi:include>` is assembled. @since 2.0.0.

**Parameters:**
- `$block` *(string)* — Block name.
- `$attrs` *(array)* — Block attributes.

---

### `wppo_esi_block_label`
Filters the accessible loading label announced on the OLS ESI placeholder (`role="status"` region) while the fragment is being fetched. The `nonce` block is hidden from assistive tech instead (audit #888 finding 7). @since 2.0.0.

**Parameters:**
- `$label` *(string)* — Loading label (default: localized per block name).
- `$block` *(string)* — Block name.

---

### `wppo_esi_placeholder`
Filters the ESI placeholder HTML rendered when ESI is unavailable. @since 2.0.0.

**Parameters:**
- `$html` *(string)* — Placeholder markup.
- `$block` *(string)* — Block name.
- `$attrs` *(array)* — Block attributes.

---

### `wppo_esi_fragment_html`
Filters the rendered ESI fragment HTML before output. @since 2.0.0.

Runs before the `wp_kses` sanitization contract (`wppo_esi_allowed_html`), so any markup added here must be permitted by that allowlist. The plugin's own `LiteSpeed_ESI::inject_nonce_replacement()` is attached to this filter: it rewrites `data-wppo-nonce` placeholders (including `__WPPO_ESI_NONCE__` / `__WPPO_NONCE__`) to a freshly minted nonce. A fragment supplied here carrying `data-wppo-nonce=""` therefore receives a real nonce automatically, and `data-*` attributes survive sanitization.
**WooCommerce fragment-caching guidance (ESI as the correct fragment answer):** catalog pages stay cacheable while per-session commerce state hydrates as fragments — the `cart` block renders live mini-cart count + cart hash via `LiteSpeed_ESI::render_woo_cart_fragment()` (Woo-guarded, zero merchant configuration), punched through the page cache via Enterprise `<esi:include>` where ESI is available and via the OLS AJAX hydration fallback (`src/esi.js` + `wppo_esi_fragment` endpoint with `DONOTCACHEPAGE`) otherwise. The same dynamic routes are never served from static HTML cache in either mode: cart / checkout / my-account (+ custom Woo slugs), `wc-ajax`, `add-to-cart`, Store API (`/wc/store/`), and faceted queries (see `Util::is_woo_excluded_url()`), with cookie vary on `woocommerce_items_in_cart` / `woocommerce_cart_hash` / `wp_woocommerce_session_*` as the second line of defense. Any detection failure degrades to uncached/dynamic — never a stale cross-session mini-cart. Structured guidance lives in `LiteSpeed_ESI::get_woo_fragment_guidance()`.


**Parameters:**
- `$fragment` *(string)* — Fragment markup.
- `$block` *(string)* — Block name.

---

### `wppo_esi_nonce_content`
Filters the content rendered inside a nonce ESI fragment. @since 2.0.0.

Applied by `LiteSpeed_ESI::inject_nonce_replacement()` after placeholder substitution. This is the content-level extension point; the never-applied `wppo_esi_nonce` / `wppo_litespeed_esi_nonce` names were removed in favour of the fragment filter above.

**Parameters:**
- `$content` *(string)* — Fragment content.
- `$nonce` *(string)* — Nonce value.

---

### `wppo_esi_private_headers_sent` (action)
Fires when private/no-cache headers were sent in the ESI path (used by the DB queue fallback to know headers are gone). @since 2.0.0.

**Parameters:**
- `$scope` *(string)* — `'private'` or `'no-cache'`.

---

### `wppo_litespeed_esi_nonces`
Filters the nonce allowlist map used by the LiteSpeed ESI bridge. @since 2.0.0.

**Parameters:**
- `$nonces` *(array)* — Nonce names → values.

---

### `wppo_video_placeholder_allowed`
Filters whether a video iframe may be replaced by a click-to-play placeholder. @since 2.0.0.

**Parameters:**
- `$allowed` *(bool)* — Default `true`.
- `$original_src` *(string)* — Iframe source URL.
- `$iframe_tag` *(string)* — Full iframe tag.

---

### `wppo_video_play_button_html`
Filters the play-button markup in the video placeholder. Overrides must keep an accessible label (e.g. `aria-label`) on the button so assistive-tech users can activate the placeholder. @since 2.0.0.

**Parameters:**
- `$play_button` *(string)* — Button HTML.
- `$video_id` *(string)* — Video ID.
- `$video_type` *(string)* — `youtube`|`vimeo`.

---

### `wppo_video_placeholder_html`
Filters the final click-to-play placeholder markup. Overrides must preserve an accessible name for the play control (`aria-label` or text content) and a meaningful `img` alt so the placeholder stays operable for assistive-tech users. @since 2.0.0.

**Parameters:**
- `$placeholder_html` *(string)* — Placeholder HTML.
- `$video_id` *(string)* — Video ID.
- `$video_type` *(string)* — `youtube`|`vimeo`.
- `$thumbnail_url` *(string)* — Poster image URL.

---

### `wppo_lazy_render_excluded_classes`
Filters the CSS class tokens excluded from below-fold lazy rendering when `image_optimisation.lazyRenderExcludeBuilders` is on. Matching is token-based (exact class names, case-insensitive). @since 2.0.0.

**Parameters:**
- `$excluded_classes` *(string[])* — Default `array( 'elementor-section', 'et_pb_section' )`.

**Example:**
```php
add_filter( 'wppo_lazy_render_excluded_classes', function( $classes ) {
    $classes[] = 'my-builder-section';
    return $classes;
} );
```

---

### `wppo_lazy_render_intrinsic_size`
Filters the `contain-intrinsic-size` reserve appended alongside `content-visibility:auto` in below-fold lazy rendering. @since 2.0.0.

**Parameters:**
- `$intrinsic_size` *(string)* — Default `'auto 600px'`.

**Example:**
```php
add_filter( 'wppo_lazy_render_intrinsic_size', function() {
    return 'auto 800px';
} );
```

---

## 🔧 CLI-Only Settings Keys

These `wppo_settings` keys have no SPA toggle — they are read by background
jobs / WP-CLI and edited via `wp wppo settings` (or `import_settings`).

| Key | Type / Default | Consumed by |
|-----|----------------|-------------|
| `image_optimisation.excludeWebPImages` | string (newline-separated URLs/handles), default `''` | `Img_Converter::__construct()` (`includes/class-img-converter.php`) — images matching these URLs/handles are skipped during WebP/AVIF conversion. |
| `image_optimisation.batch` | int, default `50` | `Cron` image-conversion worker (`includes/class-cron.php`) and `wp wppo image convert` (`includes/class-wppo-cli-command.php`) — number of images processed per batch. |
| `performance_audit.rum_sample_rate` | int 1–100, default `100` | `RUM::get_sample_rate()` / `RUM::get_effective_sample_rate()` (`includes/class-rum.php`) — percent of page views sending a RUM beacon. No SPA toggle (headless by design); edit via `wp wppo settings` or `import_settings`. The client (`src/rum.js`) and server each roll independently at the effective rate, so stored volume is ~rate²/100; the high-traffic auto-throttle halves each gate (see `wppo_rum_throttle_threshold` / `wppo_rum_effective_sample_rate`). |

---

## ⚠️ Deprecated Features

### `file_optimisation.removeQueryStrings` (removed in 2.0.0, #925, formerly tracked in #904)
Removed. The `?ver=` stripping path (`Main::strip_static_query_strings()` on
`script_loader_src` / `style_loader_src`, the `is_plugin_cache_url()` helpers,
the setting default, and the SPA toggle) is gone. `?ver=` **is** the
cache-busting mechanism (fingerprinting) — stripping it risked stale assets
with no measurable gain (see `docs/research/competitor-research-2026-09-08.md`
§5). A stored legacy value is ignored (fail-open): assets always keep `?ver`,
a one-time activity-log notice is written on `admin_init`
(`Main::maybe_notify_remove_query_strings_removal()`), and the key is dropped
on the next `file_optimisation` save (`Rest::update_settings()`).
