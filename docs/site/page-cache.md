# Page Cache

Beginner-first guide to the static HTML page cache.

## What it does

Generates a static HTML file for each visited page so repeat visits are
served without running WordPress and PHP. Cached pages live under
`wp-content/cache/wppo/{domain}/{path}/index.html` (plus a gzip variant)
and can be served directly by the `advanced-cache.php` drop-in.

Sources: `includes/Cache/class-cache.php`,
`includes/Cache/class-advanced-cache-handler.php`, `readme.txt`.

## When to use it

- Your site is mostly public content (blog, business site, docs).
- Time to First Byte (TTFB) is slow on uncached pages.
- You want the biggest single speed improvement with the least risk.

If your site is mostly logged-in users or personalized pages, read
[Compatibility](compatibility.md) and the logged-in cache notes below first.

## Safe default

Page cache is **on** by default (`enableCache: true`), but logged-in user
caching is **off** by default (`enableLoggedInCache: false`), WooCommerce
safe mode is **on** (`wooSafeMode: true`), and the stampede guard is **on**.

Source: `Settings_Store::get_default_settings()` in
`includes/Settings/class-settings-store.php`.

## How to enable

1. Go to **Performance Optimisation → Dashboard**.
2. Page Caching is already enabled for guests. Leave it on.
3. Only enable **Logged-in cache** if you understand the personalization
   risk; restrict it by role if offered.
4. Save, then clear the cache once (admin bar → **Clear All Cache**).

Related: [Preload](preload.md) warms the cache; [CDN](cdn.md) puts it on edge servers.

## What changes

- First visit to a URL builds the page normally; repeat visits serve the
  stored `index.html` until it expires or is purged.
- Cache Life (`cacheLife`, default `0` = no expiry) controls how long a
  cached page is reused; per-role/per-URL TTL overrides are available.
- Saving a post smart-purges the home page, archives, and taxonomies —
  not just the edited post (see `includes/Cache/class-cache-invalidator.php`).
- Permalink changes, theme switches, settings saves, and plugin
  activate/deactivate clear the cache automatically.

## Compatibility

| Integration | Status | Notes |
|---|---|---|
| WooCommerce cart / checkout / account | `verified` | Excluded from full-page caching by `class-woo-detect.php`; Store API, `wc-ajax`, and add-to-cart signals are dynamic; self-test at `woo_cache_self_test` REST endpoint |
| Logged-in users | `supported` | Off by default; enabling it can leak personalized content — test roles on staging |
| LiteSpeed / LSCache | `supported` | See [LiteSpeed](litespeed.md); pick one full-page cache owner |
| Other cache plugins | `known limitation` | Run only **one** full-page cache at a time; the plugin detects competing drop-ins and will not overwrite them |

See [Compatibility](compatibility.md) for the full matrix.

## Verify

1. Visit a page in an incognito window (logged out).
2. Reload it — the second load should be noticeably faster.
3. Edit a post and confirm the home page and archive show the change
   (smart purge works).
4. Check **Dashboard** cache size/statistics move as pages are cached.

## Undo

1. Turn **off** Page Caching and save.
2. Click **Clear All Cache** (admin bar or Dashboard).
3. If the `advanced-cache.php` drop-in was created by this plugin, removing
   or disabling the plugin removes it; the plugin never overwrites another
   plugin's drop-in (marker check in `class-advanced-cache-handler.php`).

## Troubleshooting

- **Stale content:** clear the cache manually; confirm the URL is not
  excluded; check for a second cache layer (host, Cloudflare — see
  [CDN](cdn.md)) before assuming this plugin is stale. Details in
  [Troubleshooting](troubleshooting.md#stale-cache).
- **WooCommerce cart shows wrong items:** safe mode excludes these paths by
  default — if you customized exclusions, restore `cart`, `checkout`,
  `my-account` and run the self-test. See
  [Troubleshooting](troubleshooting.md#woocommerce).
- **Logged-in users see each other's content:** turn logged-in cache off
  immediately and purge.

## FAQ

**Will caching break my contact form or comments?**
Dynamic endpoints (`wc-ajax`, Store API, admin, login) are never cached.
Standard comment submission still works because it is a POST request.

**Do I need preload too?**
No, but [Preload](preload.md) fills the cache before visitors arrive, so
the first visitor is fast as well.

## Technical reference

- Generation/invalidation: `includes/Cache/class-cache.php`
- Smart purge targets: `includes/Cache/class-cache-invalidator.php`
- Capacity caps/eviction: `includes/Cache/class-cache-capacity.php`
  (`cacheMaxSizeMB` 512, `cacheMaxFiles` 5000 by default)
- Drop-in lifecycle: `includes/Cache/class-advanced-cache-handler.php`
- Settings tab: `cache_settings` · REST: `clear_cache`, `preload_status`
