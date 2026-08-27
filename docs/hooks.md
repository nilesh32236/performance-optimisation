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
