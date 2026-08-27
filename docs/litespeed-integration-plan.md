# LiteSpeed / OpenLiteSpeed Integration Plan

**Date:** 2026-08-27  
**Status:** Draft — Review Required  
**Companion:** `docs/litespeed-research.md` (deep research)  
**Principles:** Zero breakage on non-LS hosts — every LiteSpeed path is an additive, opt-in or auto-detected path with fallback. No invention of version numbers — new symbols get `@since NEXT`.

---

## Table of Contents

1. [Goals & Non-Goals](#1-goals--non-goals)
2. [Product Decision — Coexistence Modes](#2-product-decision--coexistence-modes)
3. [Architecture Overview](#3-architecture-overview)
4. [Phased Implementation](#4-phased-implementation)
5. [Phase 0 — Fix Server Detection (Required Prerequisite)](#5-phase-0--fix-server-detection-required-prerequisite)
6. [Phase 1 — Compatibility & Safe Coexistence](#6-phase-1--compatibility--safe-coexistence)
7. [Phase 2 — Purge Sync & Cache Control Bridge](#7-phase-2--purge-sync--cache-control-bridge)
8. [Phase 3 — Server-Level Acceleration (LiteSpeed-Native)](#8-phase-3--server-level-acceleration-litespeed-native)
9. [Phase 4 — Image & CDN — Server-Level Next-Gen](#9-phase-4--image--cdn--server-level-next-gen)
10. [Phase 5 — Optional Enterprise / QUIC.cloud Features](#10-phase-5--optional-enterprise--quiccloud-features)
11. [Data Model & Settings](#11-data-model--settings)
12. [REST & SPA Wiring](#12-rest--spa-wiring)
13. [Drop-in Arbitration (`advanced-cache.php` / `object-cache.php`)](#13-drop-in-arbitration-advanced-cachephp--object-cachephp)
14. [Security & Privacy](#14-security--privacy)
15. [Multisite & Backward Compatibility](#15-multisite--backward-compatibility)
16. [Testing Strategy](#16-testing-strategy)
17. [Risks & Mitigations](#17-risks--mitigations)
18. [File Checklist (Where Changes Land)](#18-file-checklist-where-changes-land)
19. [Open Questions for Review](#19-open-questions-for-review)

---

## 1. Goals & Non-Goals

### Goals

- On **LiteSpeed / OpenLiteSpeed**, the user gets **full advantage of the server** — not just parity with Apache/Nginx, but LiteSpeed-native acceleration when available.
- On **non-LS hosts**, behaviour is **unchanged** — no LiteSpeed code runs, no settings surface, no overhead.
- If **LSCache plugin is also installed**, we **do not corrupt pages** — we detect, warn, and enforce a clear coexistence mode (user-chosen, not silent).
- Ship **incrementally** — each phase is shippable and testable alone, with feature flags / settings defaults that preserve current behaviour.

### Non-Goals (explicitly out of scope for this plan)

- Re-implementing LSCache's cloud pipeline (QUIC.cloud CCSS/UCSS/Image CDN) — we keep our local, credit-free pipeline and document when the cloud alternative is better.
- Replacing LSCache's ESI on Enterprise — we provide a best-effort alternative on OLS and document the Enterprise advantage.
- Forking or vendoring LSCache code — we integrate via **public hooks/headers/constants only**.

---

## 2. Product Decision — Coexistence Modes

When `Server_Rules::get_server_type() === 'litespeed'` and optionally `is_plugin_active('litespeed-cache/litespeed-cache.php')`, the user must be able to choose. We propose a single new setting:

**`litespeed_integration.mode`** (stored in `wppo_settings` — see §11):

| Mode | Meaning | Who owns page cache | Who owns minify/combine/defer/delay | Purge |
|---|---|---|---|---|
| `auto` (default) | Auto-detect, safe coexistence | **WPPO** if LSCache not active; **LSCache** if active (WPPO file cache auto-paused) | Only the cache owner runs its optimizer; the other disables matching toggles with a notice | Each plugin's own purge; we additionally emit cross-purge (Phase 2) |
| `wppo` | Force WPPO owns cache | WPPO (emit `X-LiteSpeed-Cache-Control: no-cache` to bypass LS) | WPPO | WPPO + cross-purge to LS |
| `litespeed` | Force LSCache owns cache | LSCache (WPPO file cache + combine/minify/defer disabled, shows banner) | LSCache | LSCache + cross-purge to WPPO |
| `standalone` | Ignore LS server | WPPO (no LS headers emitted) | WPPO | WPPO only |

- On **non-LS servers**, `mode` is hidden and treated as `standalone`.
- On **LS server without LSCWP**, `auto` === `wppo` (no conflict).
- On **LS server with LSCWP**, `auto` recommends `litespeed` but lets the user override.

Alternative name considered: `cache_engine` — rejected as ambiguous with object cache. `litespeed_integration` is explicit.

---

## 3. Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│  Detection Layer                                                │
│  Server_Rules::get_server_type() → litespeed|apache|nginx|other │
│  System_Info::normalize_server_software() (already LS-aware)    │
│  System_Info::get_active_cache_plugin() (already lists LSCWP)   │
│  LiteSpeed_Integration::is_litespeed() / is_lscache_active()    │
├─────────────────────────────────────────────────────────────────┤
│  Integration Layer (new)                                        │
│  includes/class-litespeed-integration.php                       │
│  - emits X-LiteSpeed-* headers when mode=wppo                   │
│  - emits X-LiteSpeed-Cache-Control: no-cache when mode=litespeed│
│  - purge bridge (wppo_* → litespeed_purge_*, and reverse)      │
│  - optimizer guard (litespeed_can_optm filter)                  │
│  - vary bridge (role-hash → litespeed_vary)                     │
├─────────────────────────────────────────────────────────────────┤
│  Existing Layers (modified)                                     │
│  class-cache.php: maybe_store_cache() gating by mode            │
│  class-advanced-cache-handler.php: drop-in + foreign check      │
│  class-main.php: optimizer filter guards + mode-aware hooks     │
│  class-cdn-purger.php: add LS purge method                      │
│  class-system-info.php: expose litespeed fields to REST/SPA     │
│  class-rest.php: GET system_info + server_rules + new endpoints │
├─────────────────────────────────────────────────────────────────┤
│  SPA Layer (new UI)                                             │
│  src/components/LiteSpeedPanel.js (or section in Dashboard/     │
│  FileOptimization → Network tab)                                 │
│  src/lib/litespeed.js helper                                    │
└─────────────────────────────────────────────────────────────────┘
```

**Class naming:** `PerformanceOptimise\Inc\LiteSpeed_Integration` (per project `PerformanceOptimise\Inc` namespace, manual loading via `Main::includes()`). Alias `WPPO_LiteSpeed` is not needed.

**File placement:** `includes/class-litespeed-integration.php` + `templates/litespeed-check.php` (if needed for `advanced-cache.php` LS check). No vendor deps.

---

## 4. Phased Implementation

| Phase | Title | Effort | User-visible | Shippable alone |
|---|---|---|---|---|
| **0** | Fix server detection | S | Yes — correct banner + Network tab on LS | Yes |
| **1** | Compatibility & safe coexistence | M | Yes — warnings, auto-pause of conflicting toggles, drop-in arbitration UI | Yes (depends on 0) |
| **2** | Purge sync & cache-control bridge | M | Yes — "Purge All" becomes atomic across both layers | Yes (depends on 0-1) |
| **3** | Server-level acceleration (LS-native) | M-L | Yes — TTFB estimated ~200→~90ms via LS cache-control headers + vary | Depends on 0-2 |
| **4** | Image & CDN — server-level next-gen | M | Yes — `.htaccess Vary: Accept` + rewrite, CDN mapping awareness | Depends on 0 |
| **5** | Optional Enterprise / QUIC.cloud | L | Only on Enterprise — ESI-lite, CCSS cloud opt-in | Depends on 0-3 |

Tag: `S < 1 day`, `M 1-3 days`, `L 1 week`.

---

## 5. Phase 0 — Fix Server Detection (Required Prerequisite)

### 5.1 Code change

```php
// includes/class-server-rules.php — new return value 'litespeed'

public static function get_server_type(): string {
    $raw = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '';
    $s   = strtolower($raw);
    if ( false !== strpos($s, 'litespeed') || false !== strpos($s, 'openlitespeed') ) {
        return 'litespeed';
    }
    if ( false !== strpos($s, 'apache') ) return 'apache';
    if ( false !== strpos($s, 'nginx') )  return 'nginx';
    return 'other';
}

// New helper — keep normalize_server_software() as display helper, but detection is canonical here.

public static function is_litespeed(): bool {
    return 'litespeed' === self::get_server_type();
}
```

- Check order matters: `litespeed` before `apache`/`nginx` (OLS banner is `LiteSpeed`, not `Apache`, but ordering is defensive).
- Optional secondary signal: `$_SERVER['LSWS_EDITION']` if exposed — not relied upon, `SERVER_SOFTWARE` is canonical.
- Add unit tests: `Server_RulesTest::test_get_server_type_litespeed` with banners `LiteSpeed`, `OpenLiteSpeed`, `LiteSpeed/1.9.1 Open`, `Apache` (negative), etc.

### 5.2 UI change — `src/components/FileOptimization.js` Network tab

- `server_type === 'litespeed'` → show **same rules as Apache** (LiteSpeed reads `.htaccess`) with a label "LiteSpeed (Apache-compatible)". Reuse `get_apache_rules()` output; do not show Nginx block.
- `server_type === 'other'` → keep current warning.
- REST `GET server_rules` should now return `{ server_type: 'litespeed', apache: '...', nginx: '...' }` — populate `apache` even for LS so SPA has content.

### 5.3 System info exposure

- `System_Info::get_server()` already normalizes correctly — no change.
- Add `litespeed_detected` bool + `lscache_active` bool to `System_Info::get_cache()` or a new `get_litespeed()` group so Dashboard can render without extra endpoint.

### 5.4 Verification

- `composer lint`, `npm run lint:js`, `composer test`, `npm test` — add 4 new assertions.
- Manual: `curl -H "Server-Software: LiteSpeed" -I` + `wp eval "echo Server_Rules::get_server_type();"` — expect `litespeed`.

---

## 6. Phase 1 — Compatibility & Safe Coexistence

**Goal:** Never corrupt pages when both plugins are active. Show the user exactly what's happening.

### 6.1 New class — `includes/class-litespeed-integration.php`

```php
final class LiteSpeed_Integration {
    public const MODE_AUTO       = 'auto';
    public const MODE_WPPO       = 'wppo';
    public const MODE_LITESPEED  = 'litespeed';
    public const MODE_STANDALONE = 'standalone';

    public static function is_litespeed(): bool;       // delegates to Server_Rules
    public static function is_lscache_active(): bool;  // active_plugins + sitewide + class_exists('LiteSpeed\...') + defined('LSCWP_V')
    public static function get_mode(): string;          // from wppo_settings['litespeed_integration']['mode'] ?? 'auto'
    public static function effective_mode(): string;    // resolves auto → concrete
    public static function is_wppo_cache_owner(): bool; // true if effective_mode === wppo or (auto && !lscache_active)
    public static function should_disable_wppo_optimizer(): bool; // true if lscache_active && effective_mode !== wppo
}
```

- Loaded in `Main::includes()` after `Server_Rules` + `System_Info`.
- All methods are cheap — cache `effective_mode` per request.

### 6.2 Optimizer guards (prevent double-processing)

When `should_disable_wppo_optimizer() === true`, our optimizer hooks return early:

| Hook site | Guard |
|---|---|
| `Main::minify_css()` `style_loader_tag` | `if (LiteSpeed_Integration::should_disable_wppo_optimizer()) return $tag;` |
| `Cache::combine_css()` | early return, do not enqueue `wppo-combine-css` |
| `Main::minify_js()` | early return |
| `Main::add_defer_strategy()` / `add_defer_attribute_legacy()` | early return |
| `Main::add_defer_attribute()` / delayJS path | early return (do not add `wppo-src`) |
| `Cache::minify_buffer()` HTML min | early return (keep `removeHTMLComments` only if requested) |
| `Google_Fonts::process_style_tag()` | keep — low conflict, but gate if LS also localizes fonts (future filter) |
| `Cache::maybe_apply_cdn()` | gate — if LS also rewrites CDN, skip our rewrite when `litespeed_can_cdn` says LS will |

- Also respect `litespeed_can_optm` filter when LSCWP is active — if it returns `false`, we also skip (LS is telling the ecosystem not to optimize this route).

```php
// Example guard in Main::minify_css() — before any work
if ( LiteSpeed_Integration::should_disable_wppo_optimizer() ) {
    return $tag;
}
if ( has_filter('litespeed_can_optm') && ! apply_filters('litespeed_can_optm', true) ) {
    return $tag;
}
```

### 6.3 Admin notices & SPA banner

- **PHP admin notice** (`class-admin-notices.php`): when `is_litespeed && is_lscache_active && effective_mode === auto`, show dismissible warning: "Both Performance Optimisation and LiteSpeed Cache are active. In Auto mode, file cache & minify are paused to avoid double processing. Choose the cache owner in Performance → File Optimization → Network → LiteSpeed." — capability `manage_options`, nonce, dismiss per user or per site.
- **SPA banner** (Dashboard + FileOptimization Network tab): `LiteSpeedPanel` or inline `NoticeBanner` — `useNotice()` — with `StatusBadge` showing `Detected: LiteSpeed`, `LSCache: Active|Inactive`, `Effective: WPPO|LSCache`.
- When optimizer is paused, individual toggles (`minifyJS`, `combineCSS`, `deferJS`, `delayJS`, `minifyHTML`) show `disabled` + tooltip "Paused — LiteSpeed Cache owns optimization (change in Network → LiteSpeed)".

### 6.4 Drop-in arbitration UI

- `class-advanced-cache-handler.php::foreign_dropin_present()` already correct — keep.
- `class-object-cache.php::get_status()` already surfaces `foreign_dropin` — extend to `page-cache` drop-in status.
- New REST field `litespeed.dropin` → SPA shows "Object cache drop-in: WPPO | LSCache | None — Only one `object-cache.php` can exist. Switch in Tools → Object Cache."

### 6.5 `.htaccess` ordering

- Document and enforce: `# BEGIN LSCACHE` must stay **above** `# BEGIN WordPress` and `# BEGIN wppo_rules`. LSCWP already enforces this; our `Htaccess_Handler::update_rules()` must not reorder it. Add comment in code and docs.
- On `litespeed`, `Htaccess_Handler::update_rules()` **does** run (unlike today where only `apache` triggers it) — because LS reads `.htaccess`. Gate `Main::on_settings_update()` to call it for `litespeed` as well as `apache`.

---

## 7. Phase 2 — Purge Sync & Cache-Control Bridge

**Goal:** "Purge All" is atomic across both cache layers.

### 7.1 WPPO → LSCache purge (we clear LS when we clear ours)

In `Cache::clear_cache()` (fires `wppo_before_cache_clear` / `wppo_after_cache_clear`), add after file deletion:

```php
// includes/class-cache.php — at end of clear_cache(), before return
if ( LiteSpeed_Integration::is_litespeed() && LiteSpeed_Integration::is_lscache_active() ) {
    // Purge LS server cache — tag=* is atomic in LSWS shared memory
    if ( 'all' === $type_or_path_or_null ) {
        do_action('litespeed_purge_all', 'wppo clear_all');
    } elseif ( is_string($type_or_path_or_null) && '' !== $type_or_path_or_null ) {
        // Single page purge — map our path to URL then purge that URL tag
        $url = home_url($type_or_path_or_null);
        do_action('litespeed_purge_url', $url);
    }
    // Also purge CDN-layer caches LS might own — no extra code, LSCWP handles its CDN mapping on purge_all
}
```

- Similarly hook `invalidate_dynamic_static_html($page_id)` → `do_action('litespeed_purge_post', $page_id)` for smart purge parity.
- Hook `wppo_after_cache_clear` so extensions can listen, but do the bridge directly in `Cache` so it works even if hook is suppressed.

### 7.2 LSCache → WPPO purge (LS clears us when it purges)

In `LiteSpeed_Integration::init()` (called from `Main::setup_hooks()`):

```php
add_action('litespeed_purged_all', function() {
    if ( LiteSpeed_Integration::get_mode() !== LiteSpeed_Integration::MODE_LITESPEED ) return; // avoid loops
    \PerformanceOptimise\Inc\Cache::clear_cache(); // our file cache
});
add_action('litespeed_purged_post', function($post_id) {
    \PerformanceOptimise\Inc\Cache::invalidate_dynamic_static_html($post_id);
});
add_action('litespeed_purge_finalize', function() {
    // Catch-all — any LSCWP purge kind that we don't explicitly handle
});
```

- Guard with `has_action` check to avoid infinite loop: set a transient `wppo_litespeed_purge_lock` (60s blog-prefixed via `Util::transient_key()`) when we initiate a purge, skip the reverse hook if lock present.

### 7.3 Selective purge — CDN purger extension

- `class-cdn-purger.php`: add `purge_litespeed($type)` private method:
  ```php
  private static function purge_litespeed(string $type, ?string $url = null): void {
      if ( ! LiteSpeed_Integration::is_lscache_active() ) return;
      if ( 'all' === $type ) do_action('litespeed_purge_all', 'wppo cdn purge');
      elseif ( null !== $url ) do_action('litespeed_purge_url', $url);
  }
  ```
- Call from `CDN_Purger::purge_all()` alongside Cloudflare/Varnish. Low risk.

### 7.4 OLS restart note

- Purging LSCache server entries is instant via shared memory; `.htaccess` changes still need OLS restart — purge does not. Document that `CacheLookup`/`CacheKeyModify` changes require restart, but `X-LiteSpeed-Purge` does not.

---

## 8. Phase 3 — Server-Level Acceleration (LiteSpeed-Native)

**Goal:** On LiteSpeed without LSCWP, let the server serve from its own cache — TTFB estimated ~50-90ms vs PHP ~170-350ms — instead of PHP `advanced-cache.php`.

### 8.1 Header emission — when WPPO owns cache but wants LS to accelerate

Even when we own the cache, we can ask LSWS to **also** cache the response in its server layer for the hot path. This is done by emitting `X-LiteSpeed-Cache-Control` instead of `Cache-Control`:

```php
// In Cache::process_buffer_for_cache() or via send_headers filter — when mode === wppo && is_litespeed()
add_action('send_headers', function() {
    if ( ! LiteSpeed_Integration::is_litespeed() ) return;
    if ( ! LiteSpeed_Integration::is_wppo_cache_owner() ) return;
    if ( headers_sent() ) return;
    if ( defined('DONOTCACHEPAGE') && DONOTCACHEPAGE ) {
        do_action('litespeed_control_set_nocache', 'wppo donotcachepage');
        return;
    }
    if ( Cache::is_not_cacheable() ) { // already exists — reuse validation
        do_action('litespeed_control_set_nocache', 'wppo not cacheable');
        return;
    }
    // Cacheable — emit LS header with our TTL
    $ttl = (int) (get_option('wppo_settings', [])['cache_settings']['cacheLife'] ?? 0);
    $max_age = $ttl > 0 ? $ttl * 3600 : 604800; // WARNING: file-cache `0` = never expire; LS layer maps `0` → 1 week (explicit policy change, documented)
    do_action('litespeed_control_set_ttl', $max_age);
    // Optionally tag for purge granularity
    do_action('litespeed_tag', 'WPPO');
    if ( is_singular() ) {
        do_action('litespeed_tag_post', get_queried_object_id());
    }
}, 0);
```

- Alternative: emit raw header directly: `header('X-LiteSpeed-Cache-Control: public,max-age=604800')` — works even if LSCWP not active (OLS still honors it). Use `do_action` when LSCWP present for bitmask correctness, raw header as fallback when LSCWP absent (OLS honors raw header without plugin).
- Decision: **emit raw header always when `is_litespeed && is_wppo_cache_owner && is_cacheable`**, and also fire `do_action` if `has_action('litespeed_control_set_ttl')` for bitmask path.

### 8.2 Bypass path — when LSCache owns cache

```php
// In Cache::maybe_store_cache() gating
if ( LiteSpeed_Integration::is_litespeed() && !LiteSpeed_Integration::is_wppo_cache_owner() ) {
    // Do not write our file cache — LS layer will handle it. Still run processing pipeline
    // (CDN rewrite / used CSS) via process_buffer_only() but skip save_processed_buffer()
    return false; // skip store
}
```

- And emit `X-LiteSpeed-Cache-Control: no-cache` via `do_action('litespeed_control_set_nocache')` only when we detect we are on a non-cacheable route that LS would otherwise cache — helps LS make the right decision without double-caching.

### 8.3 Vary bridge — role-hash → LiteSpeed Vary

- Our logged-in cache uses cookie `wppo_role_hash` → file `index-{hash}.html`. LS uses `X-LiteSpeed-Vary: cookie=_lscache_vary=...`.
- When `is_litespeed && is_wppo_cache_owner && enableLoggedInCache`, vary by our role cookie. LSCWP filter is `litespeed_vary` (verified in `src/vary.cls.php`) — use that when hook exists:
  ```php
  add_filter('litespeed_vary', function($vary) { $vary['wppo_role_hash'] = 'wppo_role_hash'; return $vary; });
  // Fallback: raw header when LSCWP not active
  // header('X-LiteSpeed-Vary: cookie=wppo_role_hash');
  ```
- Only when LSCWP is active (filter exists). When LSCWP not active, raw `Vary: Cookie` header is sufficient for OLS to vary by our cookie.

### 8.4 Stale & TTL alignment

- Map `cacheLife` (hours: 0/1/6/12/24/48/168) to LS `max-age` seconds. LS respects `max-age` + `stale` window (`maxStaleAge` server setting, 200s default). No extra code.
- Clear both layers atomically via Phase 2.

---

## 9. Phase 4 — Image & CDN — Server-Level Next-Gen

### 9.1 `Vary: Accept` + rewrite to `.webp/.avif` at server level

**Problem:** We serve WebP/AVIF via PHP `Accept` negotiation (`Image_Optimisation::maybe_serve_next_gen_images`) — extra PHP on every image request. Server-level rewrite avoids PHP.

**Solution:** When `is_litespeed && convertImg === true`, add to `.htaccess` (via `Htaccess_Handler::get_rules()`):

```apache
# WPPO Next-gen delivery (LiteSpeed/Apache) — sibling .webp/.avif
<IfModule mod_rewrite.c>
RewriteEngine On
# Serve .webp when client supports it and file exists
RewriteCond %{HTTP:Accept} image/webp
RewriteCond %{REQUEST_FILENAME}.webp -f
RewriteRule ^(.+)\.(jpe?g|png)$ $1.webp [T=image/webp,E=accept:1]
# Serve .avif when client prefers it (prefer AVIF over WebP — order after)
RewriteCond %{HTTP:Accept} image/avif
RewriteCond %{REQUEST_FILENAME}.avif -f
RewriteRule ^(.+)\.(jpe?g|png)$ $1.avif [T=image/avif,E=accept:1]
</IfModule>
<IfModule mod_headers.c>
Header append Vary Accept env=accept
</IfModule>
AddType image/webp .webp
AddType image/avif .avif
```

- Gate behind new setting `image_optimisation.enableNextGenHtaccessRewrite` (default `false` — opt-in, because rewrite must match the converter's output layout `wppo/` vs sibling `.webp`).
- Our converter currently writes to `wppo/` directory copies — rewrite target depends on layout. Decide: sibling `.webp` (same dir) or `wppo/` path. Document and test.
- Nginx equivalent via `Server_Rules::get_nginx_rules()` — `map $http_accept` → `try_files`.

### 9.2 Brotli alongside gzip

- When `is_litespeed` or `apache` with `mod_brotli` / server `brStaticCompressLevel`, generate `.br` alongside `.gz` in `save_processed_buffer()` if `function_exists('brotli_compress')` or `extension_loaded('brotli')`.
- Serve `.br` from `advanced-cache.php` when `Accept-Encoding` contains `br` before falling back to `.gz`. OLS already serves Brotli at server level if `EnableBr 1` — but our file cache should still have `.br` for the `advanced-cache.php` path.

### 9.3 CDN mapping awareness

- When `litespeed-cache` active and its CDN mapping is enabled, skip our `cdnURL` rewrite (our `maybe_apply_cdn()` gated by `litespeed_can_cdn` filter). When LS not active, keep our rewrite.
- Document that only one CDN rewrite should be active — LSCWP's mapping is more granular (per-filetype), ours is `wp-content/wp-includes` broad.

---

## 10. Phase 5 — Optional Enterprise / QUIC.cloud Features

These are **not required** for OLS value, but document the path for completeness:

| Feature | OLS Availability | Plan |
|---|---|---|
| ESI private blocks (Woo cart, admin bar, nonce 12h) | ❌ OLS no | Provide "ESI-lite" — render cart fragment via AJAX / `wp-ajax` with `DONOTCACHEPAGE`, document that LS Enterprise would be faster |
| QUIC.cloud CCSS/UCS cloud (headless Chrome) | Optional (credits) | Keep our local heuristic as default, add filter `wppo_litespeed_use_quic_ccss` that defers to QUIC.cloud when configured |
| LQIP / VPI via cloud | Optional | Keep local `lqip` 20×20 blur, document cloud as alternative for heavy sites |
| Guest Mode (super-optimized guest-only cache) | OLS no (needs ESI) | No action — document as Enterprise advantage |
| HTTP/2 push / QUIC hinting | OLS yes | Already covered by preconnect/preload + speculation rules |

---

## 11. Data Model & Settings

### 11.1 New settings key

In `wppo_settings` — single option, serialized array — add top-level key `litespeed_integration` (mirrors `cache_settings`, `file_optimisation`, etc.):

```php
// Default in class-main.php:170-242
'litespeed_integration' => array(
    'mode'                   => 'auto',  // auto|wppo|litespeed|standalone
    'enableNextGenRewrite'   => false,   // Phase 4 — opt-in .htaccess Vary: Accept
    'enableBrotli'           => false,   // Phase 4 — opt-in .br generation
    'purgeSync'              => true,    // Phase 2 — cross-purge LS ↔ WPPO (default on when LS detected)
),
```

- Add to `Main::get_default_settings()` with safe defaults (all `false`/`auto` so non-LS hosts unchanged).
- Sanitize in `Util::sanitize_settings_recursively()` — `mode` via `in_array(..., ['auto','wppo','litespeed','standalone'], true)`.
- Expose to SPA via `wppoSettings.settings.litespeed_integration` (redacted — no secrets).

### 11.2 REST allowlist

In `class-rest.php::update_settings` whitelist tabs (`class-rest.php:423-433`), add `litespeed_integration` to allowed tabs. Same pattern as `file_optimisation`, `cache_settings`, etc. — `sanitize_text_field` + `esc_url_raw` for url-like keys (none in this group).

### 11.3 Transients / locks

| Key | Purpose | Prefix |
|---|---|---|
| `wppo_litespeed_purge_lock` | Prevent purge loop (LS→WPPO→LS) | blog-prefixed via `Util::transient_key()` |
| `wppo_litespeed_server_check` | Cache `is_litespeed` + `is_lscache_active` negative for 1h (optional, low cost) | blog-prefixed |

No new tables. No options outside `wppo_settings`.

---

## 12. REST & SPA Wiring

### 12.1 REST

| Endpoint | Change |
|---|---|
| `GET /performance-optimisation/v1/system_info` | Add `litespeed: { detected, server_type, lscache_active, effective_mode, wppo_owns_cache, dropin }` group |
| `GET /performance-optimisation/v1/server_rules` | Return `server_type=litespeed` + `apache` rules populated (LS is Apache-compat), plus new `litespeed: { htaccess_nextgen, brotli }` snippet when Phase 4 settings on |
| `POST /performance-optimisation/v1/update_settings` | Accept `tab=litespeed_integration` |
| `POST /performance-optimisation/v1/clear_cache` | Extend to purge LS when `litespeed_integration.purgeSync` true (Phase 2) |
| `GET /performance-optimisation/v1/suggestions` | Add suggestion "LiteSpeed detected — choose cache owner" when `auto && lscache_active` |

### 12.2 SPA — where LiteSpeed surfaces

**Option A (recommended for minimal disruption):** Add a **LiteSpeed card** to existing tabs:

- `Dashboard.js` → top banner when `is_litespeed` — `FeatureHeader` + `StatusBadge` + `NoticeBanner` with mode selector (dropdown `auto / WPPO / LiteSpeed / Standalone`).
- `FileOptimization.js` → Network tab → new section "LiteSpeed" above Apache/Nginx rules, with mode selector (if `is_litespeed`), rule preview `<pre><code class="wppo-nginx-rules">`, and warnings when optimizer paused.

**Option B (if design prefers isolation):** New tab `LiteSpeed` in `App.js` (`lazy()` code-split) — `src/components/LiteSpeedPanel.js` — shows detection, mode, purge sync, optimizer status, server rules, Vary bridge. Overkill for Phase 0-2.

- Shared pattern: `useNotice()` + `NoticeBanner` for feedback, `FeatureCard` for sections, `SwitchField`/`CheckboxOption`/`ConfirmDialog` as needed.
- Global `wppoSettings` already injected — extend `wppoSettings.litespeedDetected` / `wppoSettings.lscacheActive` if needed for non-REST detection on mount.

### 12.3 Build

- Entry points stay `src/index.js` + `src/lazyload.js` via `@wordpress/scripts`. Add `src/lib/litespeed.js` helper (pure JS, no dep).
- `npm run build` after any JS change — build output committed (`build/index.js`, etc.).

---

## 13. Drop-in Arbitration (`advanced-cache.php` / `object-cache.php`)

### `advanced-cache.php`

- `class-advanced-cache-handler.php` already handles foreign drop-in correctly: `foreign_dropin_present() === true` → `create()` returns early, `remove()` only deletes ours. **No change** — just add UI in SPA so operator sees "Page cache drop-in: WPPO | LSCache | Other | None".
- When `effective_mode === litespeed`, we intentionally **do not** try to overwrite LSCWP's `advanced-cache.php` — our file cache is bypassed (Phase 3 §8.2) and `advanced-cache.php` stays as LSCWP's.

### `object-cache.php`

- Same — only one `object-cache.php` can exist. `class-object-cache.php` already surfaces `foreign_dropin`. Add SPA warning: "Object cache drop-in collision — choose Redis driver in Tools → Object Cache. LSCache object cache uses same file."
- No auto-overwrite — operator must choose via UI (disable ours → delete `wp-content/object-cache.php` → LSCWP can enable; or vice versa).

### `wp-config.php` constants

- `WP_CACHE` — `Main::maybe_fix_wp_cache()` already auto-fixes. On LS, both plugins need `WP_CACHE true`. No change.
- Document that `LITESPEED_DISABLE_ALL` (constant to disable LS entirely) is not set by us — operator sets it manually if they want WPPO-only even with LSCWP active.

---

## 14. Security & Privacy

- **No new secrets** — LiteSpeed integration needs no API keys (unlike Cloudflare constant `WPPO_CLOUDFLARE_API_TOKEN`). QUIC.cloud would need a key — deferred to Phase 5 opt-in.
- **Header injection safety:** All `X-LiteSpeed-*` values are allowlisted (`public,max-age=N`, `*`, `tag=...` with sanitized id). No user input reaches headers without `sanitize_text_field` + `in_array` allowlist.
- **SSRF / open redirect:** Purge URL construction via `home_url()` + `wp_http_validate_url()` (same pattern as `class-critical-css.php` `is_safe_stylesheet_url()` depth-3 SSRF gating).
- **Version redaction:** Continue `System_Info::redact_version()` / `normalize_server_software()` discipline — never expose patch versions of LS/OLS.
- **Rate limiting:** Purge bridge inherits `Cache::clear_cache()` capability `manage_options` — same as today. `rum_collect` public endpoint remains token+IP rate-limited.

---

## 15. Multisite & Backward Compatibility

- **Multisite:** All new transients via `Util::transient_key()` blog prefix. Domain cache dirs already isolate HTML cache. `active_sitewide_plugins` already checked in `get_active_cache_plugin()`.
- **WP version gates:** Every new WP API behind `function_exists()` / `class_exists()` / `method_exists()`. LS header emission is version-agnostic — just `header()` + `do_action()` — no WP version gate needed.
- **Settings backward compat:** New `litespeed_integration` key defaults to `auto` / `false` — existing installs see no behaviour change until they actively set a non-default or are on LS with LSCWP. Non-LS hosts never see the setting.
- **Filters:** New filters `wppo_litespeed_mode`, `wppo_litespeed_should_disable_optimizer`, `wppo_litespeed_purge_sync` — prefix `wppo_` per repo rule, document in `docs/hooks.md`.
- **`@since` tags:** All new symbols get `@since NEXT` — never invent a version number.
- **Vendor dir:** No new Composer deps for LS integration (zero).

---

## 16. Testing Strategy

### 16.1 PHP unit (Brain Monkey + PHPUnit — `composer test`)

| Test file | Covers |
|---|---|
| `tests/php/ServerRulesTest.php` | `get_server_type()` for `LiteSpeed`, `OpenLiteSpeed`, `Apache`, `Nginx`, empty, unknown |
| `tests/php/LiteSpeedIntegrationTest.php` (new) | `is_litespeed()`, `is_lscache_active()` (stub `active_plugins`), `get_mode()`, `effective_mode()` (auto→wppo/litespeed/standalone), `should_disable_wppo_optimizer()` |
| `tests/php/CacheTest.php` (extend) | `clear_cache()` emits `litespeed_purge_all` when mode/purgeSync, single-page → `litespeed_purge_url` |
| `tests/php/HtaccessHandlerTest.php` (extend) | `get_rules()` includes next-gen Vary block when `enableNextGenRewrite` true + is_litespeed |
| `tests/php/MainTest.php` (extend) | optimizer guards — `minify_css` early return when `should_disable_wppo_optimizer()` |

- Use `Brain\Monkey\Functions\when('get_option')->justReturn(...)`, `Filters\has()` / `expectAdded()` for filter assertions, `ReflectionMethod` for private methods per repo quirks.

### 16.2 JS unit (Jest + @testing-library/react — `npm test`)

| Test file | Covers |
|---|---|
| `src/components/__tests__/FileOptimization.test.js` (extend) | Network tab renders "LiteSpeed (Apache-compatible)" when `server_type=litespeed`, warning when `other` |
| `src/components/__tests__/LiteSpeedPanel.test.js` (new, if panel exists) | mode dropdown, status badge, optimizer-paused tooltip |
| `src/lib/__tests__/litespeed.test.js` (new) | helper `getEffectiveMode()` pure logic |

- Global `wppoSettings` per-test: `{ server_type: 'litespeed', lscache_active: true, settings: { litespeed_integration: { mode: 'auto' } } }`

### 16.3 Manual / integration

- On this OLS 1.9.1 host: install `litespeed-cache` via `wp plugin install litespeed-cache --activate`, verify banner + auto-pause, test purge both directions via `curl -I` (check `X-LiteSpeed-Cache: hit/miss` vs `X-WPPO-Cache`), then `wp plugin deactivate litespeed-cache --uninstall` and verify WPPO re-takes ownership.
- `curl -I https://nileshportfolio.duckdns.org/` — check headers per mode.
- OLS restart check: change `.htaccess` LSCACHE block ordering, restart `systemctl restart lsws` (or `openlitespeed`) and verify `CacheLookup` still above `WordPress`.

### 16.4 Verification order (AGENTS.md required)

`npm run lint:js` → `composer lint` → `npm test` → `npm run build` — must all pass; `build/` committed.

---

## 17. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Double-cache stale (purge sync missed) | Medium | High | Phase 2 purge bridge both directions + lock to avoid loop; add action `wppo_litespeed_purge_lock` |
| Double-minify corruption when LSCWP active | High if no guard | High | Phase 1 optimizer guard — early return on every `style/script_loader_tag` filter when `should_disable_wppo_optimizer()` |
| `.htaccess` LSCACHE block reordered | Low | Medium | `Htaccess_Handler` must never call `insert_with_markers('wordpress', ...)` above LSCACHE; add comment + test; document operator manual ordering |
| ESI expectation mismatch on OLS (no ESI) | Medium | Medium | Never emit ESI headers on OLS — `is_litespeed && !defined('LITESPEED_ESI_OFF')` check + doc that ESI-lite is AJAX fallback |
| OLS restart required for `.htaccess` change but operator doesn't restart | High on OLS | Low | Document in UI: "LiteSpeed — restart OLS after changing server rules" near the textarea; `Server_Rules::get_litespeed_rules()` comment |
| Object-cache drop-in collision white-screen | Low (guard exists) | High | UI warning + never auto-overwrite `object-cache.php`; operator chooses via toggle |
| Vary cookie explosion (too many variants) | Low | Medium | Only vary on `wppo_role_hash` when `enableLoggedInCache` true; cap variants, document |
| Non-LS host sees LS UI | Low | Low | `is_litespeed()` gate — early return everywhere when false; SPA banner only when `server_type === 'litespeed'` |
| WP version drift (6.9 template buffer vs legacy) | Low | Low | Keep both paths, LS header emission on `send_headers` (version-agnostic) not tied to buffer choice |

---

## 18. File Checklist (Where Changes Land)

| File | Phase | Change |
|---|---|---|
| `includes/class-server-rules.php` | 0 | `get_server_type()` → `litespeed` branch + `is_litespeed()` helper |
| `includes/class-litespeed-integration.php` | 1 | **New** — detection, mode, optimizer guard, purge bridge, vary bridge |
| `includes/class-main.php` | 0-3 | `includes()` loads new class, `setup_hooks()` registers it, optimizer filter guards, `on_settings_update()` handles `litespeed` like `apache`, `maybe_fix_wp_cache()` comment |
| `includes/class-cache.php` | 2-3 | `clear_cache()` + `invalidate_dynamic_static_html()` → LS purge, `maybe_store_cache()` gating, optional `Vary: Accept` header |
| `includes/class-advanced-cache-handler.php` | 1 | comment + test coverage only (logic already correct) |
| `includes/class-object-cache.php` | 1 | expose `litespeed` drop-in status to REST |
| `includes/class-htaccess-handler.php` | 1,4 | allow `litespeed` to trigger update, add next-gen `Vary: Accept` block behind setting |
| `includes/class-cdn-purger.php` | 2 | `purge_litespeed()` method + call in `purge_all()` |
| `includes/class-system-info.php` | 0 | `get_litespeed()` group or extend `get_cache()` with `litespeed_detected` / `lscache_active` / `effective_mode` |
| `includes/class-rest.php` | 0-2 | whitelist `litespeed_integration` tab, extend `system_info` + `server_rules` + `clear_cache` purge |
| `src/components/FileOptimization.js` | 0-1 | Network tab — LS branch, rule preview, optimizer-paused tooltips |
| `src/components/Dashboard.js` | 1 | LS banner when `is_litespeed` |
| `src/components/LiteSpeedPanel.js` | 1-2 | **New (optional)** — dedicated LS card/panel |
| `src/lib/litespeed.js` | 1 | **New** helper — pure mode logic for SPA |
| `docs/hooks.md` | 1-2 | Document new filters `wppo_litespeed_*` + actions |
| `tests/php/*Test.php` | 0-2 | `ServerRulesTest`, `LiteSpeedIntegrationTest`, extend `CacheTest`/`MainTest` |
| `src/components/__tests__/*` | 0-1 | Extend `FileOptimization.test.js`, new `LiteSpeedPanel.test.js` |
| `package.json` | — | no new deps |
| `composer.json` | — | no new deps |

---

## 19. Open Questions for Review

1. **Mode default:** Is `auto` → `litespeed` when both active the right default? Alternative: `auto` → `wppo` (keep incumbent) and recommend switching. Vote.
2. **Optimizer granularity:** Should we allow per-optimizer choice (e.g. LS owns cache but WPPO owns image AVIF) or strictly "one owner owns all combinable optimizers"? Proposal: strict owner model for Phase 1-3; per-optimizer toggles deferred.
3. **`.htaccess` vs server-level next-gen:** Our converter writes to `wppo/` directory — rewrite must target same layout. Do we standardise on sibling `.webp` (current) or keep `wppo/`? Document before implementing Phase 4 rewrite.
4. **Header method:** Prefer `do_action('litespeed_control_set_*')` (bitmask-correct when LSCWP active) vs raw `header('X-LiteSpeed-...')` (works without LSCWP). Proposal: both — action when hook exists, raw header as fallback (cover OLS without LSCWP).
5. **Scheduling:** Do we install `litespeed-cache` now on this host for integration testing, or keep this host WPPO-only and test LS paths via header spoofing (`$_SERVER['SERVER_SOFTWARE']='LiteSpeed'` stub)? Proposal: install on staging clone, not production.
6. **Docs location:** Keep these two docs (`research.md` + `integration-plan.md`) or merge into `COMPETITIVE_GAP_ANALYSIS.md` Tier-4 section? Proposal: keep separate + link from `COMPETITIVE_GAP_ANALYSIS.md` and `AGENTS.md`.
7. **Release naming:** All new symbols `@since NEXT` — confirm `NEXT` placeholder flow (replaced at `v*` tag by `scripts/build-release.sh` / CI).

---

*After review, Phase 0 can be PR'd immediately (small, high-confidence, zero risk to non-LS hosts). Phase 1 should follow in the same release train. Phases 2-4 can be iterated per sprint.*
