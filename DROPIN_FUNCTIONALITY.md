# Advanced Cache Drop-in Functionality

## Overview
The plugin now automatically creates and manages the `advanced-cache.php` drop-in file when page caching is enabled/disabled.

## How It Works

### Automatic Management
When you enable or disable page caching through the settings API, the plugin automatically:

1. **When Enabling Cache:**
   - Creates `/wp-content/advanced-cache.php` with optimized caching logic
   - Adds `WP_CACHE` constant to `wp-config.php` if not already present
   - Configures cache TTL based on your settings

2. **When Disabling Cache:**
   - Removes `/wp-content/advanced-cache.php`
   - Clears all cached pages

### API Integration
The drop-in management is integrated into the Settings API:

```bash
# Enable page cache (creates drop-in)
curl -X POST "http://localhost/awm/wp-json/performance-optimisation/v1/settings" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=xxx" \
  -d '{"settings":{"cache_settings":{"page_cache_enabled":true}}}'

# Disable page cache (removes drop-in)
curl -X POST "http://localhost/awm/wp-json/performance-optimisation/v1/settings" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=xxx" \
  -d '{"settings":{"cache_settings":{"page_cache_enabled":false}}}'
```

### Manual Testing (Debug Mode Only)
When `WP_DEBUG` is enabled, you can access a test page:

**URL:** `/wp-admin/admin.php?page=wppo-test-dropin`

This page allows you to:
- View drop-in status
- Manually create/remove the drop-in
- Enable/disable cache
- Preview the drop-in content

## Code Structure

### PageCacheService Methods

#### `create_advanced_cache_dropin(): bool`
Creates the advanced-cache.php file in wp-content directory.

#### `remove_advanced_cache_dropin(): bool`
Removes the advanced-cache.php file.

#### `enable_cache(): bool`
- Creates the drop-in file
- Enables WP_CACHE constant in wp-config.php
- Returns true on success

#### `disable_cache(): bool`
- Clears all cached pages
- Removes the drop-in file
- Returns true on success

#### `get_advanced_cache_content(): string`
Generates the PHP content for the drop-in file with:
- Cookie-based logged-in user detection
- POST request exclusion
- Query string parameter filtering
- GZIP compression support
- Configurable cache TTL
- Cache hit/miss headers

### SettingsController Integration

The `update_settings()` method now:
1. Detects when `page_cache_enabled` changes
2. Calls `manage_advanced_cache_dropin()` to handle the drop-in
3. Logs the action for debugging

## Advanced Cache Drop-in Features

The generated `advanced-cache.php` file:

- **Loads Early:** Executes before WordPress fully loads (wp-settings.php line 99)
- **Fast Serving:** Serves cached HTML directly without database queries
- **GZIP Support:** Automatically serves .gz files when browser supports it
- **Smart Exclusions:** Skips caching for:
  - Logged-in users (checks cookies)
  - POST requests
  - URLs with query parameters (search, preview, etc.)
- **Cache Headers:** Adds X-Cache headers (HIT, HIT-GZIP, or MISS)
- **TTL Respect:** Uses configured cache expiration time

## File Locations

- **Drop-in:** `/wp-content/advanced-cache.php`
- **Cache Files:** `/wp-content/cache/wppo/pages/{host}/{path}/index.html`
- **GZIP Files:** `/wp-content/cache/wppo/pages/{host}/{path}/index.html.gz`

## Requirements

- Write permissions on `/wp-content/` directory
- Write permissions on `/wp-config.php` (for WP_CACHE constant)
- PHP 7.4+
- WordPress 6.2+

## Troubleshooting

### Drop-in Not Created
- Check file permissions on wp-content directory
- Verify plugin is activated
- Check error logs for exceptions

### Cache Not Working
- Ensure WP_CACHE constant is defined and true in wp-config.php
- Check that advanced-cache.php exists
- Verify you're not logged in when testing
- Clear browser cache

### WP_CACHE Constant Not Added
- Check write permissions on wp-config.php
- Manually add: `define( 'WP_CACHE', true );` after `<?php` in wp-config.php

## Security Considerations

- Drop-in only serves cached content to non-logged-in users
- Respects WordPress authentication cookies
- Excludes sensitive query parameters
- No execution of user-provided code
