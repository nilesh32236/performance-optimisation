# Audit: Performance & Caching Patterns

You are auditing a WordPress performance plugin for internal performance anti-patterns. It's a plugin designed to speed up other sites — it must not slow down the admin itself.

## What to Check

### Database Query Efficiency
- No N+1 query patterns (`get_posts()` or `$wpdb->get_results()` inside loops)
- All `get_option()` calls for large options should use `autoload=false` or be loaded once
- `wp_cache_get()`/`wp_cache_set()` used for repeated expensive queries
- `$wpdb->get_results()` with unbounded `LIMIT` — large tables need pagination
- `count()` on `$wpdb->get_results()` when `COUNT(*)` SQL would suffice

### Cache Usage
- Static HTML cache generated correctly — no accidental `wp_send_headers()` after output started
- Transients used for cacheable external API calls (PageSpeed API, image processing status)
- Cache keys namespaced with `wppo_` to avoid collisions
- Cache invalidation is complete: when settings change, relevant caches cleared
- `wppo_cache_size` and `wppo_total_js_css` transients refreshed when underlying data changes

### Asset Loading
- JS/CSS assets only enqueued on the `performance-optimisation` admin page (not all admin pages)
- Lazy-loading IntersectionObserver correctly disconnected after all targets observed
- No `DOMContentLoaded` listeners added redundantly (MutationObserver should handle late-added elements)
- CDN rewrite regex patterns compiled once, not on every URL

### Background Jobs
- Action Scheduler jobs (`wppo_convert_image_background`, `wppo_pagespeed_scan`) have proper deduplication
- Cron job handlers are idempotent — safe to run multiple times concurrently
- Long-running processes (image batch conversion) yield correctly via deferred commits

### Memory & Resource Usage
- `file_get_contents()` on potentially large HTML files — check for memory limit issues
- Image conversion (GD/Imagick) destroys resources after use
- HTML minification via `voku/html-min` applied only when output buffer is active

## Output Format

Write findings to the output file in JSON Lines format:

```jsonl
{"type":"summary","text":"Audited {target_dir}. Found X issues."}
{"type":"issue","severity":"critical|important|minor","file":"relative/path","line":42,"message":"What the issue is","suggestion":"How to fix it","inline":false}
```

## Severity Guide

- **critical**: N+1 queries in page load path, unbounded DB queries, cache invalidation missing entirely
- **important**: Autoloaded large options, missing transient for expensive external call, asset on all admin pages
- **minor**: Cache key without namespace, missing LIMIT on low-traffic query, minor memory waste
