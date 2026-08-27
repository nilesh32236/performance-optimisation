# WordPress 6.8 → 7.2 Readiness

**Date:** 2026-08-27  
**Core latest fetched:** WordPress 7.1 Mary Lou 2026-08-19; 7.2 alpha trunk r63167, GA 2026-12-09  
**Plugin baseline:** 1.9.0 + `composer.json`/`package.json` as locked  
**Related:** `COMPETITIVE_GAP_ANALYSIS.md:1` (already adopted + 10 opportunity rows), `docs/competitive-audit-2026.md:5`

> Tracks what WordPress shipped since 6.8, what we adopted, and what remains — with file pointers and gated fallbacks. Every new API below is `function_exists()`/`class_exists()`-gated per `AGENTS.md`.

---

## 1. What Shipped (Verified)

### 6.8 “Cecil” 2025-04-15 — Speculative Loading + bcrypt
- **Speculative Loading:** `wp_get_speculation_rules_configuration()`, `wp_get_speculation_rules()`, `wp_print_speculation_rules()`, filters `wp_speculation_rules_configuration`, `wp_speculation_rules_href_exclude_paths`, action `wp_load_speculation_rules` — default `prefetch conservative`, logged-out + pretty permalinks, excludes `wp-*.php`, `wp-admin/*`, nonces, `.no-prefetch`.
- **bcrypt** + BLAKE2b via Sodium — no plugin action.
- **WP_Query cache keys** normalized — core handles.
- **We:** ✅ `preload_settings.speculationMode/Eagerness/ExcludeUrls` + respects `WP_SPECULATIVE_LOADING_DEFAULT_MODE/_EAGERNESS` constants (`PreloadSettings.js:43-49`).

### 6.9 “Gene” 2025-12-02 — Frontend Performance (7 perf landings)
1. `fetchpriority` for scripts & script modules (`wp_script_add_data($h,'fetchpriority','high|low|auto')` + `modulepreload`) — emoji detection moved to footer `script-module` `low`.
2. `styles_inline_size_limit` 20KB→40KB + minify+inline block styles (`wp_maybe_inline_styles()` reads `path` data).
3. Omit hidden-block assets (`hidden=>true` blocks no longer enqueue).
4. Load block styles on-demand in classic themes (was block-themes only).
5. **Template Enhancement Output Buffer** — `ob_start('wp_finalize_template_enhancement_output_buffer')` on `wp_before_include_template`, gated by `wp_should_output_buffer_template_for_enhancement()` + `has_filter('wp_template_enhancement_output_buffer')`. Hooks: `wp_before_include_template`, `wp_template_enhancement_output_buffer`, `wp_finalized_template_enhancement_output_buffer`, `wp_should_output_buffer_template_for_enhancement`.
6. `wp_head` classic-theme hoisting via `WP_HTML_Tag_Processor`.
7. Spawn Cron at shutdown + RSS feed caching fix + video block CLS fix.

Plus: **Abilities API** (`wp_register_ability`, 3 REST namespaces, categories), **HTML API** `WP_HTML_Processor`/`WP_Block_Processor`/`WP_HTML_Tag_Processor::serialize_token()`, **salted cache family** (`wp_cache_get_salted`, `set_salted`, `get_multiple_salted` etc.).

**We adopted (verified `class-cache.php`, `class-main.php`):**
- ✅ Template buffer dual-path: `wp_template_enhancement_output_buffer` when `function_exists('wp_should_output_buffer_template_for_enhancement') && is WP 6.9+`, else `template_redirect→ob_start` (`TODO #553`).
- ✅ Inline budget: `class-cache.php:1006` `styles_inline_size_limit` 40k/20k version-aware + `core_will_inline()` greedy accounting with `path&&src` gate + `is_readable` skip.
- ✅ Separate assets: `block_assets_are_separate()` → `separate` baked into combined-CSS filename (`index-separate.css` vs `index.css`).
- ✅ Script modules: `apply_module_loading_strategies()` `in_footer + low` via `wp_script_modules()`.
- ✅ Abilities: `class-abilities.php` registers base set.

**Remain (P1):**
- Salted-cache family completeness (`get_multiple_salted`/`set_multiple_salted`/`delete_multiple_salted` + query-cache eviction in `templates/object-cache.php` — currently single-key only).
- `wp_get_loading_optimization_attributes()` for occluded/below-fold images (`fetchpriority=low/auto` via `Image Prioritizer`).
- Emoji footer module: `disableEmojis` currently strips head; add `wp_dequeue_script_module('emoji')` (6.9+).
- `enqueue_empty_block_content_assets` filter pass-through for hidden-block omission (doc-only, one-line).

### 7.0 “Armstrong” 2026-05-20 — AI Foundations + Admin
- **AI Client** `wp-includes/ai-client/` (adapters, cache, event dispatcher) + **Connectors API** + **Client-Side Abilities** + **Admin View Transitions** (`view-transitions.css` + `view-transitions.php`, admin slide on submenu change; frontend ticket #64471 not merged, feature-plugin only).
- **Heading/Gallery/Grid/Icon/Breadcrumbs blocks**, responsive block visibility (hide/show per viewport), block-level custom CSS, **Interactivity API** `watch()` + `state.url` + `data-wp-watch---`.
- **PHP min 7.4** (we require 8.2 — safe).

**We:** Abilities present but not AI Client (no need). `module_dependencies` classic↔module (7.0) **missing** — add exclusion in combine/minify (`TODO`).

### 7.1 “Mary Lou” 2026-08-19 — Client-Side Media
- **Client-Side Media** wasm-vips + Web Worker + `SharedArrayBuffer` + `Document-Isolation-Policy: isolate-and-credentialless` — compression/resize/crop/format (JPEG/PNG/WebP/AVIF/GIF), EXIF rotation, HEIC→JPEG, AVIF HDR gain-map, GIF→MP4 via `mediabunny`/`WebCodecs`, sideload `POST /wp/v2/media/{id}/sideload`. Gate `wp_is_client_side_media_processing_enabled()` / `wp_client_side_media_processing_enabled`.
- **Media Editor modal**, **infinite scroll default**, **responsive styling controls** (`mobile:`/`desktop:`), **Notes everywhere**, **accessible tooltips API**.

**We adopted:**
- ✅ Client-side media coexistence: `filter_client_side_supported_mime_types()` + `forceServerSideConversion` + two-pass `wp_generate_attachment_metadata` idempotency (`ImageOptimization.js:504`, `class-image-optimisation.php`).
- ✅ Speculation env pinning: `WP_SPECULATIVE_LOADING_DEFAULT_*` env/constant → setting (7.1 feature) respected.

### 7.2 Upcoming 2026-12-09 — Secrets API (Proposal Stage)
- **Secrets API** (2026-08-25 proposal): `wp_set_secret($name)`, `wp_get_secret($name) → WP_Secret|WP_Error`, `wp_delete_secret`, `WP_Secret::reveal()/fingerprint()`, per-secret envelope key wrapped by master key (libsodium or `sodium_compat`), `autoload=no`, excluded from `options.php`/REST, `secrets.php` drop-in, `manage_secrets` cap, 2 version slots (`CURRENT`/`PREVIOUS` via `WP_Secret_Version`).
- **Perf Chat 2026-08-25:** eliminate script/style concatenation in favor of prefetching next-screen assets (login→dashboard) — PR #13084 >70% LCP, Server-Timing strict_types.

**We:** Cloudflare token `WPPO_CLOUDFLARE_API_TOKEN` / `wppo_settings` plaintext — plan Doc migration to `wp_set_secret('wppo/cloudflare-token')` behind `function_exists('wp_set_secret')` at 7.2 GA (no auto-migrate, use `wp_import_option_as_secret()` with rotation flag).

---

## 2. Library Audit (2026-08-27 Packagist)

| Package | Constraint | Locked | Latest | Status |
|---|---|---|---|---|
| `voku/html-min` | `^5.0` | 5.0.0 2026-04-23 | 5.0.0 | ✅ current |
| `matthiasmullie/minify` | `^1.3` | 1.3.75 2025-06-25 | 1.3.75 | ✅ current |
| `symfony/css-selector` | `^7.4` | v7.4.17 2026-08-21 | v7.4.17 latest 7.4 (v8.1.5 exists) | ✅ latest in major; bump to `^8.0` optional |
| `woocommerce/action-scheduler` | `^3.8` | 3.9.3 2025-07-15 | 4.1.0 2026-08-05 | ⚠️ 1 major behind |
| `matthiasmullie/path-converter` | — | 1.1.3 | 1.1.3 | ✅ |
| `@wordpress/scripts` | `^33.0.0` | — | 33.x | ✅ current for 7.1 |
| `@wordpress/components` | `^29.0.0` | — | 29.x | ✅ |
| Node | 22.14.0 `.nvmrc` | — | 22 LTS | ✅ |
| `browserslistrc` | last 1 Chrome/Firefox/Safari not dead | — | — | ✅ |

**Action:** bump `woocommerce/action-scheduler 3.9.3→4.1.0` (update `composer.json ^4.1`, `composer update`, smoke cron/image/PageSpeed via `composer test`). Symfony 8.1 needs PHP 8.2+ so safe to stay on 7.4 or widen to `^7.4 || ^8.0` post-7.2.

---

## 3. Action Plan (Gated)

| Priority | Item | Files | Effort |
|---|---|---|---|
| **P0** | Bump `action-scheduler` | `composer.json`/`composer.lock` | 1 day |
| **P1** | Salted-cache family in `templates/object-cache.php` (`get_multiple_salted` + `*-queries` eviction) | `templates/object-cache.php`, `Tests` | 2 days |
| **P1** | `wp_get_loading_optimization_attributes()` for occluded images | `class-image-optimisation.php`, `ImageOptimization.js` | 2 days |
| **P1** | Emoji footer module dequeuing | `class-core-tweaks.php` | 0.5 day |
| **P2** | `module_dependencies` exclusion in combine/minify | `class-cache.php`, `class-main.php` | 1 day |
| **P2** | OD-aware LCP (detect `OD_URL_Metric`) | `class-image-optimisation.php` | 1 day |
| **P2** | Abilities refinements (`wp_get_abilities($args)`, categories, lifecycle hooks) | `class-abilities.php` | 1-2 days |
| **P3** | Secrets migration doc + stub (no auto-migrate) | `docs/*`, `class-cdn-purger.php` | Doc now, code at 7.2 |
| **P3** | `symfony/css-selector ^8.0` widening | `composer.json` | 0.5 day |

Keep `voku/html-min`/`matthiasmullie/minify` as-is. Re-assess `combineCSS` once 7.2 concat-elimination lands (`TODO #624`).

*Sources: wordpress.org/news (7.1 Mary Lou), make.wordpress.org/core field guides (6.8 2025-03-28, 6.9 2025-11-18, 7.0 2026-05-14, 7.1 2026-08-05) + client-side media deep dive 2026-07-22, proposal 7.2 Secrets 2026-08-25, Perf Chat 2026-08-25, developer.wordpress.org since 6.8-7.1, packagist.org 2026-08-27.*

