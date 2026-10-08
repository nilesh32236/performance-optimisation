# Phase E Documentation Map

**Campaign:** Phase E — In-Plugin Documentation
**Issue:** #1636

## Goal

Make the existing documentation answer the questions a site owner has before opening a settings screen. Beginner instructions come first. Technical reference follows the safe rollout and recovery guidance.

## Page inventory

### New useful pages

| Page | User question | Primary links |
| --- | --- | --- |
| `docs/site/core-web-vitals.html` | How do I improve LCP, INP, or CLS? | Monitoring, images, preload, CSS/JS, Page Cache |
| `docs/site/cdn.html` | How do I configure and recover a CDN? | Page Cache, LiteSpeed, image delivery, compatibility |
| `docs/site/compatibility.html` | Does this work with my stack? | Installation, LiteSpeed, Redis, CDN, troubleshooting |

### Updated feature pages

| Page | Before | After |
| --- | --- | --- |
| `performance-optimisation.html` | Feature overview and request layers | Adds task-based entry points and links to CWV, CDN, and compatibility |
| `page-cache.html` | Technical cache hit/invalidation reference | Adds when to use, safe default, enable, verify, undo, WooCommerce troubleshooting, and FAQ |
| `images-webp-avif.html` | Lazy loading, LCP, conversion, server rules | Adds beginner rollout, safe default, fallback verification, rollback, and compatibility |
| `minify-combine.html` | Transform and exclusion reference | Adds one-family-at-a-time enablement, safety language, recovery, and FAQ |
| `defer-delay-js.html` | Strategies and settings | Adds first-click guidance, safe enablement, compatibility, rollback, and FAQ |
| `used-critical-css.html` | Critical/Used CSS behavior | Adds when to use, safelist rollout, FOUC and missing-style recovery, and FAQ |
| `preload-speculation.html` | Cache warm-up, hints, crawler | Adds LCP-first enablement, speculation safety, verification, undo, and FAQ |
| `redis-object-cache.html` | Redis modes and failure behavior | Adds connection-test-first setup, safe defaults, foreign-drop-in boundary, and rollback |
| `litespeed.html` | Coexistence and headers | Adds beginner mode selection, OLS/Enterprise boundary, restart guidance, and FAQ |
| `database-cleanup.html` | Cleanup types and CLI | Adds backup and dry-run-first instructions, explicit deletion limits, and recovery |
| `monitoring-rum.html` | PageSpeed and RUM reference | Adds when to use each measurement, privacy, sample limits, verification, and undo |
| `troubleshooting.html` | Cache, assets, Redis, LiteSpeed checks | Reorders around site broken, CSS, JS, cache, WooCommerce, Redis, Critical CSS, Used CSS, and image delivery |
| `faq.html` | Short operational answers | Adds natural beginner questions and links to the relevant task pages |
| `features.html` | Feature index | Adds task, CDN, and compatibility entry points |

## Search topics covered

The pages answer questions users may type without repeating a page for each keyword:

- How to enable page caching in WordPress
- How to improve LCP and Core Web Vitals
- WebP and AVIF conversion with a safe original fallback
- CSS minification, combining, and missing styles
- Defer versus delay JavaScript and first-click failures
- Critical CSS, FOUC, Used CSS, and safelists
- Preconnect, font preload, cache warm-up, and speculation
- Redis Object Cache connection and foreign drop-in errors
- LiteSpeed and OpenLiteSpeed coexistence and ESI limits
- CDN setup, Cloudflare, Bunny Edge, Varnish, and purge
- Database cleanup, dry runs, autoloaded options, and backup recovery
- PageSpeed, RUM, privacy blockers, and field-data interpretation

Each page answers a distinct question. No thin keyword-only pages were added.

## Internal link map

- Core Web Vitals → Image Optimization → Preload → CSS/JS → Page Cache
- Page Cache → LiteSpeed → CDN → WooCommerce-safe mode
- Image Optimization → WebP/AVIF → Lazy Loading → LCP → CDN delivery
- Monitoring → PageSpeed/RUM → feature destination pages
- Used CSS/Critical CSS → CSS safelist → Page Cache purge
- Redis → Page Cache → WP-CLI → Troubleshooting
- CDN → LiteSpeed ownership → Page Cache → Compatibility

## Compatibility documentation

`docs/site/compatibility.html` distinguishes:

- **Verified:** current CI or recorded live evidence
- **Supported:** implemented path with configuration and recovery
- **Best effort:** common setup that needs site-specific testing
- **Known limitation:** deliberate boundary the plugin does not hide

It covers WordPress, PHP, WooCommerce, Elementor, LiteSpeed/OpenLiteSpeed, Redis, Cloudflare/Bunny Edge, and Varnish.

## Troubleshooting coverage

`docs/site/troubleshooting.html` now prioritizes:

- Site looks broken
- CSS missing or FOUC
- JavaScript or first click fails
- Cache will not clear
- WooCommerce cart or checkout issue
- Redis cannot connect
- Critical CSS causes FOUC
- Used CSS removes required styles
- WebP/AVIF is not served
- LiteSpeed headers are absent
- PageSpeed or RUM data is missing

Each path tells the reader which narrow feature to disable, what to clear, and when to restore a backup or seek help.

## Source and publishing boundary

`docs/site/README.md` remains the publishing contract. These fragments are editor-facing source for the existing Boltfolio documentation pipeline. Phase E does not change runtime, API, settings, or published-page behavior. Public page synchronization remains a separate deployment step and is not claimed here.
