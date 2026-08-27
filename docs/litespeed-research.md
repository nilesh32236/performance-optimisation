# LiteSpeed / OpenLiteSpeed Deep Research

**Date:** 2026-08-27  
**Status:** Planning & Research Phase  
**Environment:** OpenLiteSpeed 1.9.1 · `server: LiteSpeed` · `lsphp83` · Debian 13 · `nileshportfolio.duckdns.org`  
**Plugin baseline:** Performance Optimisation 1.9.0 · Static HTML file cache + `advanced-cache.php` + Redis object cache + minify/combine/defer/delay + WebP/AVIF + lazy load + critical/used CSS + DB cleanup + RUM + PageSpeed  
**Comparison target:** LiteSpeed Cache (LSCWP) 7.9 · 7M+ installs · WordPress.org `litespeed-cache`

> This document is the research foundation for LiteSpeed/OpenLiteSpeed integration. It is reviewable and editable — propose changes via PR or direct edits. The companion plan is `docs/litespeed-integration-plan.md`.

---

## Table of Contents

1. [Current Environment](#1-current-environment)
2. [LiteSpeed vs OpenLiteSpeed vs Apache vs Nginx](#2-litespeed-vs-openlitespeed-vs-apache-vs-nginx)
3. [How LiteSpeed Cache Plugin Works](#3-how-litespeed-cache-plugin-works)
4. [What This Plugin Does Today](#4-what-this-plugin-does-today)
5. [Server Detection Gap](#5-server-detection-gap)
6. [Conflict Matrix (If Both Plugins Active)](#6-conflict-matrix-if-both-plugins-active)
7. [Feature Comparison Matrix](#7-feature-comparison-matrix)
8. [LSCWP APIs / Hooks Available for Integration](#8-lscwp-apis--hooks-available-for-integration)
9. [What LSCWP Does That We Don't (Gaps)](#9-what-lscwp-does-that-we-dont-gaps)
10. [What We Do That LSCWP Doesn't (Advantages)](#10-what-we-do-that-lscwp-doesnt-advantages)
11. [Sources & Verification](#11-sources--verification)

---

## 1. Current Environment

### 1.1 Server

| Property | Value |
|---|---|
| `SERVER_SOFTWARE` header | `LiteSpeed` (via `curl -I`) |
| Actual binary | `openlitespeed (lshttpd - main)` PID 36824, `/usr/local/lsws` |
| OLS version | 1.9.1 (`/usr/local/lsws/VERSION`) |
| PHP | `lsphp83` 8.3.33 |
| DB | MariaDB 11.8.6 |
| `.htaccess` LSCACHE block | Present (ghost from previous install / OLS template) — `CacheLookup on`, `CacheKeyModify -qs:utm*`, async marker |
| `X-LiteSpeed-Cache` header | Absent (plugin not active → no `X-LiteSpeed-Cache-Control` emitted → `enableCache 0` means no caching) |
| Server cache config | `module cache { ls_enabled 1, enableCache 0 … }` — opportunistic, plugin-driven (reported, not verified — `conf/httpd_config.conf` permission denied; values from OLS docs) |

### 1.2 WordPress plugins on this host

`akismet` (inactive), `all-in-one-wp-migration` (active), `duoport-connect-for-opencode` (inactive), `hello`, `performance-optimisation` 1.9.0 (active), `seo-by-rank-math`, `wpforms-lite`. `litespeed-cache` **not installed** (verified via `wp plugin list` + `glob`).

### 1.3 Why `enableCache 0` matters

OLS/LSWS ships with `enableCache 0` intentionally. Caching is gated per-request by the plugin's response headers (`X-LiteSpeed-Cache-Control`). Without LSCWP active, no pages are cached at server level — even though `ls_enabled 1` and `CacheLookup on` exist. Installing LSCWP flips caching on automatically; uninstalling leaves the `.htaccess` block but no headers → inert.

---

## 2. LiteSpeed vs OpenLiteSpeed vs Apache vs Nginx

| Dimension | Apache | Nginx | LiteSpeed Enterprise (LSWS) | OpenLiteSpeed (OLS) |
|---|---|---|---|---|
| License | Apache 2.0 | BSD | Commercial | GPLv3 |
| `.htaccess` support | Native | No (server block) | Full (reads `.htaccess` + `mod_rewrite/expires/deflate` compat, live reload) | Rewrite-focused (rewrite respected, `mod_expires/deflate` directives limited, **restart required**) |
| Server-level page cache | No (needs mod_cache) | No (needs fastcgi_cache/proxy_cache) | Yes — shared memory + disk `cachedata`, QUIC-aware | Yes — same engine |
| ESI (Edge Side Includes) | No | No | Yes (hole-punching for Woo/cart/admin bar/nonce) | **No** — requires Enterprise/ADC/QUIC.cloud |
| QUIC / HTTP/3 | Via mod | Via patch | Native `quicEnable` + `quicShmDir /dev/shm` | Native (same) |
| Brotli | Via mod_brotli | Via ngx_brotli | Native `brStaticCompressLevel 6` | Native (same) |
| LSCache module | N/A | N/A | Built-in `module cache` | Built-in `module cache` |
| PHP handler | mod_php / php-fpm | php-fpm | `lsphp` (LSAPI) | `lsphp` (LSAPI) |
| `SERVER_SOFTWARE` banner | `Apache/2.4.x` | `nginx/1.2x.x` | `LiteSpeed` | `LiteSpeed` (often `OpenLiteSpeed` — banner varies) |
| Typical host share (WP) | ~30%* | ~35%* | ~30% combined* (Hostinger, NameHero, A2, etc.) | Subset of LS share | *estimates, source varies |

**Key takeaway for this plugin:** LiteSpeed Enterprise is Apache-compatible for `.htaccess` (`mod_rewrite/expires/deflate`). OpenLiteSpeed is **rewrite-focused** — it respects rewrite but not all `mod_expires/deflate` directives and requires `systemctl restart lsws` (vs LSWS live reload). Our current `Server_Rules::get_server_type()` returning `other` for `litespeed` is a bug — even the rewrite subset should be exposed for LS/OLS instead of hiding all rules.

---

## 3. How LiteSpeed Cache Plugin Works

### 3.1 Architecture — Not a PHP File Cache

```
Request → OLS/LSWS checks CacheLookup on + CacheKey → 
  HIT  → serve from cachedata/priv/{hash}/ (0 PHP, ~50-90ms TTFB estimated) → X-LiteSpeed-Cache: hit
  MISS → pass to lsphp → WordPress → LSCWP emits headers → LSWS stores object + tags + TTL → serve
```

Compare to our model:

```
Request → advanced-cache.php drop-in (PHP include) → readfile wp-content/cache/wppo/{domain}/{path}/index.html.gz
          (needs PHP wakeup, ~170-350ms TTFB estimated, domain-isolated files)
```

> TTFB numbers are **estimated** from published benchmarks (WitsCode 2026: LS cache ~90ms vs PHP file ~180ms) — not measured on this host. Lab benchmark in `PERFORMANCE.md` (680→45ms, 52→98) is warm-cache, controlled env.

### 3.2 Header Protocol (the wire between plugin and server)

| Header | Emitter (LSCWP class) | Example | Purpose |
|---|---|---|---|
| `X-LiteSpeed-Cache-Control` | `Control` | `public,max-age=604800` / `private,no-vary,max-age=1800` / `no-cache` | Cacheable? Public vs private, TTL, stale flag |
| `X-LiteSpeed-Tag` | `Tag` | `Po.123, T.5, A.1, F, H, URL./blog/, REST, HTTP.404` | Dependency tags for purge granularity |
| `X-LiteSpeed-Purge` | `Purge` | `tag=Po.123, T.5` or `*` | Invalidation — LSWS atomically drops matching objects |
| `X-LiteSpeed-Vary` | `Vary` | `cookie=_lscache_vary=...` | Multiple cache variants (mobile, role, currency) |
| `X-LiteSpeed-Purge2` | `Purge` | private purge variant | Private cache purge |
| `X-LiteSpeed-Debug` | `Core` | hit/miss detail | Debug (IP-gated) |

Tag taxonomy: `F` frontpage, `H` home, `PGS` pages, `Po.{id}` post, `PT.{type}` posttype archive, `T.{id}` term, `A.{id}` author, `D.` date, `B.{id}` blog, `W.{id}` widget, `ESI.`, `REST`, `HTTP.{code}`.

Control bitmask: `BM_CACHEABLE 1`, `BM_PRIVATE 2`, `BM_SHARED 4`, `BM_NO_VARY 8`, `BM_FORCED_CACHEABLE 32`, `BM_PUBLIC_FORCED 64`, `BM_STALE 128`, `BM_NOTCACHEABLE 256`.

### 3.3 Storage

Per-object file under `/usr/local/lsws/cachedata/priv/{hex}/` — exact hash composition (`host+URI+qs+vary+mobile+esi`) per LS docs but **unverifiable from this shell** (`priv/` listing permission denied). Nonce ESI blocks cached ~12h independent of page TTL when ESI available. `Vary` cookies written to `CacheVary` rewrite rules in `.htaccess`.

### 3.4 What LSCWP writes to `.htaccess`

```
# BEGIN LSCACHE
CacheLookup on
RewriteRule .* - [E=Cache-Control:no-autoflush]
CacheKeyModify -qs:fbclid -qs:gclid -qs:utm* -qs:_ga
# Vary rules for mobile/role/login cookie
# END LSCACHE
```

Plus `expires { enableExpires 1 }` + `tuning { gzipStaticCompressLevel, brStaticCompressLevel }` in `httpd_config.conf` / `vhconf.conf`.

### 3.5 LSCWP Admin Surface (9 tabs, 100+ toggles)

- **Cache / Purge / ESI / Object** — TTLs (`ttl_pub 604800`, `ttl_priv 1800`, `ttl_browser 31557600`), excludes (URI/Cat/Tag/Cookie/UA/Role), vary groups, stale purge, mobile, REST, login cookie, error-code TTLs.
- **Page Optimization** — CSS/JS min+combine, UCSS/CCSS via QUIC.cloud, `css_async`, `js_defer/delay`, `dns_prefetch/preconnect`, `guest_only`, `html_min/lazy`, `qs_rm`, `ggfonts`, `emoji_rm`, `font_display swap`, `instant_click`.
- **Image Optimization** — WebP/AVIF via QUIC.cloud, lossless, pull cron, `jpg_quality 82`.
- **CDN** — Mapping, Cloudflare API, QUIC.cloud CDN.
- **DB Optimizer**, **Crawler** (sitemap, concurrency, 3-strike blacklist, cron 84h, load_limit), **Toolbox** (purge all/front/403/404/500/cssjs, `edit_htaccess`, heartbeat, debug log 3M, report, import/export, presets).
- **CLI:** `wp litespeed-option/purge/crawler/image/online/debug/presets/database` (8 commands).

### 3.6 OLS Limitations

- ESI requires Enterprise/ADC — **not available on OLS 1.9.1**. QUIC.cloud CDN can provide **CDN-level** ESI but not a drop-in replacement for local hole-punching. Any ESI-related plan must degrade gracefully (private `no-cache` or role-hash fallback).
- `.htaccess` changes need OLS restart (`systemctl restart lsws`) vs LSWS live reload — document for operators.

---

## 4. What This Plugin Does Today

### 4.1 Static HTML cache (`includes/class-cache.php` + `class-advanced-cache-handler.php`)

- **Location:** `WP_CONTENT_DIR/cache/wppo/{domain}/{path}/index.html` + `.gz` (gzip only, no `.br`).
- **Buffer pipeline:** `process_buffer_only()` → image next-gen + Google Fonts + HTML minify (`voku/html-min`) + used CSS + CDN rewrite (`WP_HTML_Tag_Processor`), then `save_processed_buffer()`.
- **Dual path (WP version gated):** WP 6.9+ uses `wp_template_enhancement_output_buffer` / `wp_finalized_template_enhancement_output_buffer` (gated by `function_exists('wp_should_output_buffer_template_for_enhancement')`, `TODO #553`); legacy uses `template_redirect` + `ob_start()`.
- **Eligibility:** `DONOTCACHEPAGE`, empty domain, `..` traversal, query strings `s|ver|v`, XML/sitemap/feed, Woo `is_cart/is_checkout/is_account_page`, Woo cookies, `is_404()`, **any file extension** (`pathinfo` non-empty → not cacheable), `preload_settings.excludePreloadCache`.
- **Advanced-cache drop-in:** `WP_CONTENT_DIR/advanced-cache.php` with marker `WPPO_ADVANCED_CACHE_DROPIN`, `cacheLife` baked in (0 = never expire), logged-in guard via `is_user_logged_in_without_wp()` (checks `wordpress_logged_in_*`, `wp-rs-*` cookies), `.wppo-no-cache` marker, gzip `Content-Encoding`, `304`/`ETag`/`Last-Modified`, security headers.
- **Smart purge:** `invalidate_dynamic_static_html($page_id)` — current post + home + `page_for_posts` + post-type archive + all term archives → schedules `wppo_generate_static_page`.
- **Clear triggers:** `permalink_structure`, `switch_theme`, `update_option_wppo_settings` (if cache/file/image/preload/core tab changed), `activated_plugin`, `deactivated_plugin`, admin bar "Clear All / Clear This Page" (`src/main.js` + `class-rest.php` `ajax_get_nonce` with 403 refresh).
- **Logged-in variant:** `Util::get_role_hash()` = `substr(md5(sorted_roles+wp_salt),0,12)` → cookie `wppo_role_hash` → file `index-{12hex}.html` → regex purge `^index-[a-f0-9]{12}\.html(\.gz)?$`. Gated by `cache_settings.enableLoggedInCache` + `loggedInCacheRoles` whitelist.

### 4.2 Minify / Combine / Defer / Delay

| Feature | Where | How |
|---|---|---|
| HTML minify | `Cache::minify_buffer()` | `voku/html-min` + `matthiasmullie/minify`, options `minifyHTML`, `minifyInlineCSS/JS`, `removeHTMLComments` |
| CSS minify | `Main::minify_css()` `style_loader_tag` | `Minify\CSS` → `cache/wppo/min/{blog_id}/css/{hash}.css`, respects `exclude_css`, `wppo_exclude_minification`, `is_minified_asset_name()` |
| CSS combine | `Cache::combine_css()` `wp_enqueue_scripts PHP_INT_MAX` | Budget-aware (`styles_inline_size_limit` 20KB <6.9 / 40KB 6.9+), excludes `core_will_inline`, block assets, `excludeCombineCSS`, media≠`all`, emits `rel=preload` + `wppo-combine-css` handle |
| JS minify | `Main::minify_js()` `script_loader_tag:10` | `Minify\JS` → `cache/wppo/min/{blog_id}/js/`, excludes `jquery` + `excludeJS` |
| Defer JS (6.3+) | `Main::add_defer_strategy()` `wp_enqueue_scripts:1000` | `wp_script_add_data(strategy=defer)` + `fetchpriority=low` + `in_footer` (6.9), respects `excludeDeferJS` |
| Defer JS (<6.3) | `add_defer_attribute_legacy()` | Inserts ` defer` before `src` |
| Delay JS | `add_defer_attribute()` `script_loader_tag:10` | Rewrites `src→wppo-src`, `type→wppo/javascript wppo-type=text/javascript`, `data-wppo-delay-strategy=interaction/idle/viewport`, `data-wppo-delay-priority`, restored by `src/lazyload.js` on user interaction |
| Script modules | `apply_module_loading_strategies()` | Forces `in_footer + low` via `wp_script_modules()` |
| Query strings | `strip_static_query_strings()` `script/style_loader_src` | Removes `?ver` unless plugin cache URL |
| Woo CSS/JS | `remove_woocommerce_scripts()` `wp_enqueue_scripts:999` | Dequeues unless `excludeUrlToKeepJSCSS` matches |
| Google Fonts | `Google_Fonts::process_style_tag()` + buffer | Downloads `woff2` via Chrome 120 UA to `cache/wppo/fonts/`, injects `font-display:swap` |

### 4.3 Image optimisation (`includes/class-image-optimisation.php` + `class-img-converter.php`)

- **Conversion:** GD/Imagick → WebP/AVIF/both, queue + Action Scheduler `wppo_convert_image_background` + hourly cron, accept negotiation `Accept: image/avif|webp`, `<picture>` wrap optional.
- **Lazy:** `IntersectionObserver+MutationObserver` (`src/lazyload.js`), `excludeFirstImages` 1-3 hero skip, `lazyLoadNative`, `lazyLoadBackgroundImages`, `placeholderType` `none/svg/dominant_color/lqip` (20×20 blur, max 4096).
- **Preload:** Merges front-page + `autoPreloadLCP` (from `wppo_lcp_url_{mobile|desktop}_{md5(url)}` + ` _wppo_lcp_image_url_{strategy}`) + post meta `_wppo_preload_image_url` + post-type featured image srcset with responsive media queries, `mobile:`/`desktop:` prefixes.
- **Modern WP 7.1:** `filter_client_side_supported_mime_types()` + `forceServerSideConversion` for `wasm-vips` coexistence.

### 4.4 Other stacks

- **Object cache:** `templates/object-cache.php` → `wp-content/object-cache.php`, standalone/sentinel/cluster, TLS, compression (`none/lzf/lz4/zstd`), `wppo-redis-config.php` (password never persisted, `WPPO_REDIS_PASSWORD` / DB flag).
- **Critical/Used CSS:** Heuristic local extraction (no headless Chrome) — `cache/wppo/ccss/{md5}.css` + `domain/path/used-css.css`, SSRF-gated `@import` depth 3, `font-display:swap`, per-template/ per-page, transient `wppo_ccss_status_{hash}`.
- **Cron / warm-up:** 5-hourly `wppo_page_cron_hook` 200 posts/batch + sitemap single-events `wppo_generate_static_url` (500 URLs / 15s wall-clock, `Cron::get_sitemap_urls()` + `schedule_sitemap_url_jobs()`), random delay 0-1800s, lock `wppo_preload_cron_lock` 20min.
- **CDN:** `maybe_apply_cdn()` rewrites `src/href/data-src/srcset` for `wp-content/wp-includes` to `cdnURL`; `CDN_Purger` does Cloudflare (`Bearer WPPO_CLOUDFLARE_API_TOKEN`) + Varnish `PURGE` on `wppo_after_cache_clear` (type `all` only, single-page skips edge).
- **Preload hints:** `preconnect`/`dnsPrefetch`/`preloadFonts`/`preloadCSS` + Speculation Rules (`prefetch|prerender` × `conservative|moderate|eager`, 6.8+ `wp_speculation_rules_configuration`).
- **Core tweaks / Abilities / RUM / Telemetry:** 15+ bloat toggles, `class-abilities.php` (6.9), `class-rum.php` (`web-vitals.js` beacon, token+IP rate-limited), `class-telemetry.php` (cURL scan), `class-suggestion-engine.php`, `class-pagespeed.php` (Action Scheduler), `class-cron.php`, `class-system-info.php`, `class-util.php`, `class-log.php` (`wppo_activity_logs`).

### 4.5 Server Rules Today

- `Htaccess_Handler::get_rules()` → `mod_deflate` + `mod_expires` (1-year for static, 0s for HTML) via `insert_with_markers()` into `get_home_path().'.htaccess'`, marker `wppo_rules`.
- `Server_Rules::get_nginx_rules()` → `gzip on` (only if `minifyJS|CSS`) + `expires 365d` (only if `enableServerRules`), filter `wppo_nginx_rules`.
- React `FileOptimization.js` Network tab: `apache` → toggle + textarea, `nginx` → copy-paste `<pre>`, `other` → warning, toggle disabled.

---

## 5. Server Detection Gap

### 5.1 Current Code

```php
// includes/class-server-rules.php:34-47
public static function get_server_type(): string {
    $server_software = strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) ) );
    if ( false !== strpos( $server_software, 'apache' ) ) return 'apache';
    if ( false !== strpos( $server_software, 'nginx' ) )  return 'nginx';
    return 'other';
}
```

- Only `apache` / `nginx` / `other`. `litespeed` / `openlitespeed` → `other`.
- LiteSpeed is Apache-compatible — `.htaccess` rules work unmodified — but we hide them.
- Meanwhile `System_Info::normalize_server_software()` (`includes/class-system-info.php:464-486`) **does** map `openlitespeed→OpenLiteSpeed`, `litespeed→LiteSpeed` correctly — but only for Dashboard display, not for rule generation.

### 5.2 Impact

| Area | Effect on OLS/LSWS |
|---|---|
| FileOptimization → Network tab | Shows "Unrecognised server" warning, disables toggle, shows no rules |
| `on_settings_update()` | Never calls `Htaccess_Handler::update_rules()` (only on `apache`) — rules can drift |
| REST `GET server_rules` | Returns `server_type=other`, `nginx=""`, `apache=""` (empty because not apache) — operator gets nothing |
| Cache plugin detection | `System_Info::get_active_cache_plugin()` **does** list `litespeed-cache` — but only informational, no behavior change |
| `advanced-cache.php` | Foreign drop-in check leaves LSCWP drop-in untouched (correct) but continues buffering redundantly |

---

## 6. Conflict Matrix (If Both Plugins Active)

| Area | Our Behaviour | LSCWP Overlap | Severity | Effect |
|---|---|---|---|---|
| **Page cache (file vs server)** | `cache/wppo/index.html.gz` + `advanced-cache.php` | Server memory/disk `cachedata` via `X-LiteSpeed-Cache` headers | 🔴 High | Drop-in collision (only one `advanced-cache.php`), double cache, stale matrix (clearing one doesn't purge the other), logged-in hash vs ESI `Vary` desync |
| **Minify/combine HTML/CSS/JS** | `voku/html-min` + `Minify\CSS/JS` + `wppo-combine-css` | Own min/combine + `Load CSS Async` + `Inline CSS/JS` | 🔴 High | Double-minify corruption → FOUC, broken inline scripts, hash mismatch 404 |
| **Defer/delay JS** | `defer` + `wppo-src` delay | `Delayed JS` + `Defer JS` + `Defer jQuery` | 🔴 High | Attribute collision (`wppo-src` vs `data-src`/`defer` duplication) → scripts never restore, checkout/analytics broken |
| **Server rules** | Apache `.htaccess` or Nginx block | Own `.htaccess` LiteSpeed directives (`CacheLookup`, `CacheKeyModify`, `CacheVary`) | 🟡 Medium | Duplicate `Expires`/`Cache-Control` + order matters (`LSCACHE` must stay above `WordPress`); our `other` path gives no guidance |
| **CDN rewrite** | `cdnURL` rewrite for `wp-content/wp-includes` | CDN Mapping + QUIC.cloud | 🟡 Medium | Double rewrite → `https://cdn → https://cdn-quic` malformed `srcset` |
| **Image opt** | WebP/AVIF `<picture>` + lazy + preload | WebP/AVIF + lazy + placeholders + VPI | 🟡 Medium | Double `<picture>` nesting, format fight (`.htaccess` rewrite vs `wppo/` copies), duplicate preload links |
| **Preload / preconnect / speculation** | Resource hints + Speculation Rules API | Preload + DNS prefetch + `Instant Click` | 🟡 Medium | Duplicate `<link>` → priority contention, double prerender → 2× origin load |
| **Warm-up / crawler** | 5-hourly cron + sitemap single-events | Native crawler with concurrency + sitemap | 🟡 Medium | Thundering herd → 2× DB load, lock `wppo_preload_cron_lock` doesn't block LS crawler |
| **Object cache drop-in** | `wp-content/object-cache.php` (Redis only) | Own `object-cache.php` (Redis/Memcached/LSMCD) | 🔴 High | Fatal collision — only one `object-cache.php` can exist, second activation fails / white-screen |
| **DB cleanup** | 9 types batched | Own DB cleaner | 🟢 Low | `Clean All` may delete `wppo_*` transients used as locks |
| **Telemetry / RUM / audit** | Local cURL scan + suggestions + RUM beacons | None | 🟢 Low | No conflict — complementary |

---

## 7. Feature Comparison Matrix

Legend: ✅ native · 🟡 partial · ❌ missing

| Feature | We | LSCWP |
|---|---|---|
| Page cache + gzip/304 | ✅ file | ✅ server (zero PHP) |
| Sitemap preload / crawler | ✅ cron 5h | ✅ native + concurrency + blacklist |
| Logged-in / role cache | ✅ role-hash variants | ✅ `Vary: role` + ESI (Enterprise only) |
| Object cache (Redis) | ✅ sentinel/cluster/TLS | ✅ Redis/Memcached/LSMCD |
| REST API caching | ❌ | ✅ |
| Feed caching | ❌ (WP-compat gap) | 🟡 |
| Minify JS/CSS/HTML | ✅ | ✅ |
| Defer + delay JS | ✅ | ✅ |
| Critical CSS / used CSS | ✅ local heuristic | ✅ QUIC.cloud (credits) |
| Per-page asset manager | ✅ | 🟡 |
| Lazy images/iframes/videos | ✅ | ✅ |
| Lazy CSS background images | ✅ (recent) | 🟡 |
| LCP preload / fetchpriority | ✅ | ✅ |
| CLS width/height | ✅ | 🟡 |
| WebP/AVIF conversion | ✅ local | ✅ QUIC.cloud |
| Google Fonts self-host | ✅ woff2 | 🟡 |
| Speculation rules | ✅ 6.8+ | 🟡 |
| DB cleanup + scheduled | ✅ | ✅ |
| CDN rewrite | ✅ origin | ✅ mapping + QUIC.cloud |
| Cloudflare / Varnish purge | ✅ (Tier-1 #5) | ✅ |
| Brotli precompression | ❌ | 🟡 (via server `brotli on`) |
| PageSpeed / audit | ✅ | 🟡 |
| RUM / CrUX field data | ✅ | ❌ |
| Server rules (.htaccess/Nginx) | ✅ (bug: not for LS) | ✅ |
| Woo handling | ✅ | ✅ |
| 50+ bloat toggles | ✅ (15+) | 🟡 |
| Heartbeat control | ✅ | ✅ |
| Activity log | ✅ | 🟡 |
| WP-CLI | ✅ | ✅ |
| ESI hole-punching | ❌ | ✅ (Enterprise only) |
| QUIC.cloud CDN / CCSS cloud | ❌ | ✅ |
| Uptime monitoring | ❌ | ❌ |
| `.mo→.php` translations | ❌ | ❌ |
| LLMs.txt | ❌ | ❌ |

---

## 8. LSCWP APIs / Hooks Available for Integration

All `do_action`/`apply_filters` — no `function_exists()` guard needed, but check `has_action()`/`defined('LITESPEED_ALLOWED')` to detect presence.

### Purge API (most relevant)

```php
do_action('litespeed_purge_all', $reason);          // drops lscache+cssjs+localres+object+opcache+cloudflare
do_action('litespeed_purge_post', $post_id);
do_action('litespeed_purge_url', $url);
do_action('litespeed_purge', $tags);                // Tag::add() — string|array
do_action('litespeed_purge_private', $tags);
do_action('litespeed_purge_private_all');
do_action('litespeed_purge_posttype', $post_type);
do_action('litespeed_purge_widget', $widget_id);
do_action('litespeed_purge_esi', $tag);
do_action('litespeed_purge_all_object');            // Redis/Memcached flush
do_action('litespeed_purge_all_cssjs');             // min+combine
// Listen side:
add_action('litespeed_purged_all', $cb);
add_action('litespeed_purged_post', $cb);
add_action('litespeed_purge_finalize', $cb);
add_filter('litespeed_purge_tags', fn($tags,$is_private)=>..., 10,2);
```

### Cache Control

```php
do_action('litespeed_control_set_nocache', 'reason');
do_action('litespeed_control_set_private', 'reason');
do_action('litespeed_control_set_cacheable', 'reason');
do_action('litespeed_control_force_cacheable', 'reason');
do_action('litespeed_control_force_public', 'reason');
do_action('litespeed_control_set_ttl', $ttl);
apply_filters('litespeed_control_cacheable', $is);
apply_filters('litespeed_control_ttl', $ttl);
apply_filters('litespeed_can_optm', $can);
apply_filters('litespeed_can_cdn', $can);
```

### Tag / Vary / ESI

```php
do_action('litespeed_tag', 'MyCustomTag');
do_action('litespeed_tag_post', $pid);
add_action('litespeed_tag_finalize', $cb);
apply_filters('litespeed_vary', $vary);
apply_filters('litespeed_vary_cookies', $vary_cookies);
apply_filters('litespeed_esi_status', $bool);
do_action('litespeed_nonce', $action);
apply_filters('litespeed_esi_nonces', $list);
add_action('litespeed_esi_load-{$block}', $cb);
```

### Optimize / Media / Config

```php
apply_filters('litespeed_optm_gm_js_exc', $exc);
apply_filters('litespeed_optm_js_defer_exc', $exc);
apply_filters('litespeed_optimize_js_excludes', $exc);
apply_filters('litespeed_optimize_css_excludes', $exc);
apply_filters('litespeed_media_lazy_img_excludes', $list);
apply_filters('litespeed_is_from_cloud', $bool);
apply_filters('litespeed_conf', $conf);
do_action('litespeed_disable_all', $reason); // defines LITESPEED_DISABLE_ALL
```

Constants: `LITESPEED_DISABLE_ALL`, `LSCWP_V`, `LITESPEED_SERVER_TYPE` (verified). `LITESPEED_GUEST` / `LITESPEED_ESI_OFF` appear commented-out in LSCWP 7.9 — not active.

> Full reference: `https://docs.litespeedtech.com/lscache/lscwp/api/` + `src/api.cls.php`
> Fetched copy: `/tmp/lscache/litespeed-cache/` (7.9, unzipped on this host)

---

## 9. What LSCWP Does That We Don't (Gaps)

| Gap | Severity | Note |
|---|---|---|
| Server-level cache (zero PHP via LSWS shared memory) | High | We always boot PHP via `advanced-cache.php` — 200-600ms vs <50ms |
| ESI hole-punching (Woo cart, personalized blocks) | High (Enterprise) | We have only role-hash variants + `DONOTCACHEPAGE` all-or-nothing; no fragment cache |
| REST API / feed caching | High | We explicitly skip `is_feed()`/XML/sitemap + no REST cache (matrix ❌) |
| Brotli precompression (`.br` alongside `.gz`) | Med-High | We only ship `.gz`; `woff2` correctly omitted from deflate but no `.br` variant |
| QUIC.cloud CDN + image CDN + QUIC/HTTP-3 hinting | Med-High | We have origin CDN + Cloudflare/Varnish purge only |
| Crawler throughput / HTTP Auth / vary-aware | Med | Our warm-up is 200 posts/5h + 500 URL sitemap/15s — less throughput than LS crawler |
| `Vary: Accept` next-gen at server level (rewrite to `.webp` if exists) | Med | We do PHP-level `Accept` negotiation only; no `.htaccess Vary: Accept` rule |
| Tag-based purge granularity (`post_123`, `front`, `widget`, `esi`) | Med | We have smart per-post purge (home/archive/tax) but no tag propagation to CDN |
| Per-URL / per-post-type cache TTL | Low-Med | We have global `cacheLife` only |

---

## 10. What We Do That LSCWP Doesn't (Advantages)

| Area | We | LSCWP |
|---|---|---|
| **Portability** | ✅ Works on any host (Apache/Nginx/OLS/LSWS, shared). LSCWP requires LS server. | Requires LSWS/OLS |
| **Telemetry + suggestions + RUM** | ✅ Local cURL scan + PageSpeed + Web Vitals trends + RUM beacons + autoloaded-options audit | None |
| **Heuristic CSS control** | ✅ Conservative per-page PurgeCSS + safelist + one-click regenerate | Cloud-only (QUIC.cloud credits), per-URL |
| **Critical CSS control** | ✅ Local heuristic, no credits, per-template, SSRF-safe | QUIC.cloud credits, cloud latency |
| **Bloat toggles + per-page Asset Manager** | ✅ 15 toggles + Heartbeat + `class-metabox.php` per-page | Fewer toggles, no per-page manager |
| **Speculation Rules** | ✅ Native + `WP_SPECULATIVE_LOADING_DEFAULT_*` awareness | Only basic prefetch |
| **WP 6.9–7.1 modern core** | ✅ Salted cache keys, `WP_HTML_Processor`, template-enhancement buffers, block-assets, client-side media | Not assessed for LSCWP (we are ahead on 6.9+ adoption) |
| **WP-Cron + Action Scheduler** | ✅ 3-tier: cache preload + sitemap walker + image hourly + DB daily | Similar via crawler |
| **Multisite safety** | ✅ `Util::transient_key()` blog-prefix + domain cache dirs | Requires extra config |
| **System diagnosis** | ✅ `class-system-info.php` + adminbar cache clear + nonce refresh | Basic |

---

## 11. Sources & Verification

- **Local codebase:** `includes/class-cache.php`, `class-advanced-cache-handler.php`, `class-server-rules.php`, `class-htaccess-handler.php`, `class-system-info.php`, `class-main.php`, `class-rest.php`, `class-cron.php`, `class-image-optimisation.php`, `class-object-cache.php`, `class-cdn-purger.php`, `class-critical-css.php`, `class-used-css.php`, `src/components/FileOptimization.js`, `src/components/Dashboard.js`, `performance-optimisation.php`, `composer.json`
- **Gap & planning docs:** `COMPETITIVE_GAP_ANALYSIS.md` (gap analysis), `AGENTS.md` (repo conventions)
- **Performance note:** `PERFORMANCE.md` (lab benchmarks, warm-cache controlled env)
- **Live host probe (2026-08-27):** `ps aux` (OLS main + lscgid + workers), `curl -I` (LiteSpeed + alt-svc h3), `ls /usr/local/lsws` (1.9.1), `ls plugins` (no `litespeed-cache`), `.htaccess` LSCACHE ghost block (plus empty `NON_LSCACHE` + `LiteSpeed SetEnv noabort`), `cachedata/.cacheman.shm`
- **Fetched LSCWP:** `https://downloads.wordpress.org/plugin/litespeed-cache.latest-stable.zip` → `/tmp/lscache/litespeed-cache` 7.9 (360 files), `data/const.default.json`, `src/*.cls.php`, `purge.cls.php` / `control.cls.php` / `tag.cls.php` / `api.cls.php`
- **Docs:** `https://docs.litespeedtech.com/lscache/`, `https://docs.litespeedtech.com/lscache/lscwp/api/`, `https://wordpress.org/plugins/litespeed-cache/` ; **ESI absence** verified via `openlitespeed.org/support` + `forum.openlitespeed.org`, **7.9 changelog** via `wordpress.org/plugins/litespeed-cache` (login cache default flipped to false, REST header handling)
- **Server conf:** `conf/httpd_config.conf` / `vhconf.conf` unreadable from this shell (permission denied) — values reported from OLS docs, not verified on this host

---

*Next: `docs/litespeed-integration-plan.md` — architecture, coexistence modes, phased implementation, data model, risks.*
