# Redis Object Cache

Beginner-first guide to persistent object caching with Redis.

## What it does

Caches database query results and computed objects in Redis (RAM) so
WordPress does not re-query MySQL on every request. This speeds up dynamic,
logged-in, and uncacheable pages that the [Page Cache](page-cache.md) cannot
serve. Supports standalone, Sentinel, and Cluster topologies with optional
TLS and compression, plus a circuit breaker that auto-disables on failure.

Sources: `includes/Cache/class-object-cache.php`,
`includes/Cache/class-redis-config-policy.php`,
`templates/object-cache.php`, `readme.txt`.

## When to use it

- Admin, logged-in, or WooCommerce pages are slow despite page caching.
- Your host offers Redis (or you run your own) and has the PHP Redis
  extension installed.
- High-traffic sites where the database is the bottleneck.

If your host has no Redis server, skip this page — everything else works
without it.

## Safe default

Object cache is **inactive until you configure it**
(`object_cache: array()` by default). Enabling requires the PHP `Redis`
extension **and** a reachable Redis server; status reports `redis_missing`
and `redis_reachable` explicitly. Config is stored in
`wp-content/wppo-redis-config.php`; the drop-in is installed to
`wp-content/object-cache.php`.

Source: `Settings_Store::get_default_settings()`,
`Object_Cache::get_status()` in `includes/Cache/class-object-cache.php`.

## How to enable

1. Confirm with your host that Redis is installed **and** the PHP Redis
   extension is enabled (`redis_missing` must be false in status).
2. Go to **Performance Optimisation → Object Cache**.
3. Choose standalone (most sites), Sentinel, or Cluster; enter hosts/ports,
   TLS, and password if required.
4. **Enable**, then **Ping** to confirm connectivity.
5. Flush once after major migrations.

## What changes

- `wp-content/object-cache.php` drop-in routes WordPress object-cache calls
  to Redis with per-site key namespacing (`get_current_blog_id()`).
- Cache Life/TTL behavior for pages is unchanged; this cache is for objects,
  not HTML.
- The circuit breaker disables Redis automatically on repeated failures and
  shows an admin notice with a recovery probe.

## Compatibility

| Integration | Status | Notes |
|---|---|---|
| Hosts without Redis | `known limitation` | Feature cannot work; no fallback is fabricated — status tells you plainly |
| Multisite | `supported` | Per-site key prefixing + transient isolation via `Util::transient_key()` |
| Other object-cache drop-ins | `known limitation` | Only one `object-cache.php` can exist; the plugin will not silently replace a foreign drop-in |
| TLS / Sentinel / Cluster | `supported` | First-class settings; test failover on staging where possible |

See [Compatibility](compatibility.md) for the full matrix.

## Verify

1. Open **Object Cache → Status**: `redis_missing: false`,
   `redis_reachable: true`, telemetry shows version/memory/hit rates.
2. Use **Ping** and **Flush** actions; both should succeed without errors.
3. Browse logged-in pages — they should feel faster while content stays correct.

## Undo

1. **Disable** the object cache and save.
2. **Flush** once.
3. The plugin removes its own drop-in; a foreign drop-in is never touched.
   Delete `wp-content/wppo-redis-config.php` only if you are sure no other
   tool reads it.

## Troubleshooting

- **`redis_missing`:** install/enable the PHP Redis extension (host task) —
  see [Troubleshooting](troubleshooting.md#redis).
- **Reachable but errors:** wrong host/port/password, TLS mismatch, or
  firewall; check status telemetry errors with `WP_DEBUG`.
- **Stale objects after migration:** flush once; the circuit breaker state
  is file/option-backed and survives Redis outages by design.

## FAQ

**Page cache vs object cache — do I need both?**
Page cache serves static HTML to guests; object cache speeds up everything
else (logged-in, dynamic, admin). They complement each other.

**Will Redis cache WooCommerce sessions?**
Session/cart data paths stay dynamic; use the [Page Cache](page-cache.md)
WooCommerce notes alongside this page.

## Technical reference

- Manager: `includes/Cache/class-object-cache.php`
- Config policy (keys, bounds, password precedence):
  `includes/Cache/class-redis-config-policy.php`
- Drop-in: `templates/object-cache.php`
- Settings tab: `object_cache` · REST: `object_cache` (status/enable/disable/flush/ping)
