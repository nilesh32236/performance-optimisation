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
Fires after a database cleanup operation completes.

**Parameters:**
- `$type` *(string)* — Cleanup type (`'all'`, `'revisions'`, `'auto_drafts'`, `'trashed_posts'`, `'spam_comments'`, `'expired_transients'`, `'orphan_postmeta'`).
- `$count` *(int)* — Total number of database rows deleted.
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
