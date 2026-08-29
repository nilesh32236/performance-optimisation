# CONFLICT-DETECTION.md — External Optimizers

**Current:** Only LiteSpeed detected `Dashboard:706-758` + `FileOptimization:1267-1459` `effective_mode/lscache_active` `class-litespeed-integration.php:1343` `Server_Rules:191` pauses optimizer `FileOptimization:347-357` when `optimizer_disabled`. Missing WP Rocket/FlyingPress/SG Optimizer/Autoptimize/Cloudflare APO `SystemInfo:633`.

## Detection
- Server: `Server_Rules::get_server_type:191` Apache/LiteSpeed/Nginx + `$_SERVER LiteSpeed` vs CLI mismatch `class-litespeed-integration.php:201` (fix detection).
- Plugins: `is_plugin_active('wp-rocket/wp-rocket.php')`, `flying-press`, `sg-cachepress`, `autoptimize`, `wp-optimize`, `nitropack`, `perfmatters` — via `get_option active_plugins` `SystemInfo:633` extended.
- CDN/Edge: header `CF-Cache-Status` `CDN-Purge`, `x-varnish`, `cdnURL` `FileOptimization:1684` + `Cdn_Purger:238` `wppo_after_cache_clear` Purge All vs single-page skip edge `competitive-audit-2026.md:67` `class-cdn-purger.php:627-631`.
- Object Cache: `wp_using_ext_object_cache()` `ObjectCache:922` PONG, `WP_REDIS_*` constants.

## UX Messaging (prefer friendly over technical §18)
- Before: "Option X conflicts with filter Y." After: "Another plugin is already handling minify. We recommend leaving this off. Why? Details → Advanced override" with link to `Speed→JS` toggle.
- LiteSpeed: "LiteSpeed Cache is active — it handles page caching. Performance Optimisation cache paused (`effective_mode` `Dashboard:706` badge) — choose owner in Speed→Server."
- Cloudflare: "Cloudflare APO detected — edge cache clears on Purge All only `CDN_Purger:238` — configure Zone ID `Dashboard:1047` in Manage→CDN."
- WP Rocket active: "WP Rocket minify detected `SystemInfo:633` — disable our Minify `B1/B9` to avoid double processing."
- Nginx: "Nginx detected `Server_Rules:191 other` — Server Rules need manual `nginx.conf` copy `FileOptimization:1631-1649` — not .htaccess."

## Why / Details / Override
- Each banner has "Why?" collapsible explaining `class-cache.php:980` `wppo_inline_combined_css` inline-CSS budget, `class-main.php:1166` Woo dequeue etc, plus "Advanced override" link to `?tab=speed&advanced=1&override=1` that sets `wppo_litespeed_*` filter.

## Questa Detection Timing
- On Overview mount call `apiCall system_info` `SystemInfo:353` + `serverRules` `FileOptimization:1521` + plugins list REST `recent_activities` style — cache 1h `wppo_cache_size` `15m`.

