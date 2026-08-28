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

## 🎛️ Filter Hooks

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

---

### `wppo_invalidation_urls`
Filters the list of URL paths to purge when a post is invalidated. Merges G-03+G-27: single URL list for both filesystem and CDN purge. Sanitized via `wp_normalize_path` + `ABSPATH`/`cache_root` guard and deduped before deletion. @since NEXT.

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

### `wppo_litespeed_ttl`
Filters LiteSpeed TTL seconds mapped from `cacheLife` hours. File-cache `0` (never expire) maps to `604800` (1 week) for the LS server layer as an explicit policy change — LS cannot store infinite. @since NEXT.

**Parameters:**
- `$seconds` *(int)* — TTL in seconds.
- `$hours` *(int)* — Original `cacheLife` hours.

**Example:**
```php
add_filter( 'wppo_litespeed_ttl', function( $seconds, $hours ) {
    return $hours === 0 ? 86400 : $seconds; // 1 day instead of 1 week for never-expire
}, 10, 2 );
```

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

### `wppo_litespeed_can_cdn`
Filters whether WPPO CDN rewriting is allowed. When `false`, `maybe_apply_cdn()` is skipped to avoid double CDN mapping when `litespeed_can_cdn` (LSCWP) is active. Respects `litespeed_can_cdn` ecosystem filter. @since NEXT.

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

### `wppo_ai_adaptive_speculation_rules`
Filters AI-injected speculation rules. @since NEXT.

**Parameters:**
- `$rules` *(array)* — Speculation rules array.
- `$urls` *(string[])* — Top-2 predicted prefetch URLs.

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
