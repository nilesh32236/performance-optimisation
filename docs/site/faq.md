# Frequently Asked Questions

Hub answers with links to the full guides.

## What it does

Short answers to the most common questions, each linking to the full
beginner-first guide. Compatibility claims across these answers follow the
honest labels (`verified`, `supported`, `best effort`, `known limitation`)
defined in [Compatibility](compatibility.md). Sources: `readme.txt`,
`includes/Settings/class-settings-store.php` (defaults).

## Getting started

**How do I speed up my site with this plugin?**
Install, open the **Dashboard**, and enable [Page Cache](page-cache.md)
first — the biggest win. Then [Images](images.md), then
[CSS and JS](css-js.md) one option at a time. Measure each step with
[Monitoring](monitoring.md).

**What order should I enable features?**
Page Cache → Images → Preload → CSS/JS minify → Used/Critical CSS →
Defer → Delay. Mildest first; [Deferred JavaScript](deferred-js.md) last.

**Is this plugin free?**
Yes — 100% free and open source, no premium version or upsells.

## Cache and speed

**Will this improve my PageSpeed score?**
Its features (static HTML, lazy loading, WebP/AVIF, preloads, defer)
target exactly what Core Web Vitals and PageSpeed measure — but results
vary with hosting, theme, plugins, and content. Your own before/after
measurements are the only reliable guide. No score is guaranteed. See
[Monitoring](monitoring.md).

**Why don't my changes appear? (stale cache)**
Clear the cache, then check each layer (plugin, LiteSpeed, CDN, host,
browser). Full routine: [Troubleshooting](troubleshooting.md#stale-cache).

**Can I use this with another cache plugin?**
Only one full-page cache at a time. The plugin detects competing drop-ins
and won't overwrite them. Minification, images, and database tools still
work alongside most plugins. See [Compatibility](compatibility.md).

## Images, CSS, and JavaScript

**How do I convert images to WebP or AVIF?**
[Image Optimization](images.md) → enable conversion → pick formats →
**Optimize Now**. Originals stay untouched; copies live in `wppo/`.

**Does it support lazy loading?**
Yes — native `loading="lazy"` by default plus an opt-in JS loader with SVG
placeholders and a MutationObserver for dynamic content. See
[Images](images.md).

**Can I exclude files from minification?**
Yes — `excludeJS` / `excludeCSS` / `excludeDeferJS` / `excludeDelayJS` /
`excludeCombineCSS` boxes, plus per-page kill switches. See
[CSS and JS](css-js.md) and
[Troubleshooting](troubleshooting.md#broken-css-js).

**Critical CSS vs Used CSS — which one?**
[Used CSS](used-css.md) trims per-URL stylesheets (milder).
[Critical CSS](critical-css.md) inlines above-the-fold rules (faster paint,
preview/promote care). Either or both, enabled separately.

**Defer or delay JavaScript?**
Defer first (milder), delay after defer proves stable. Keep shop gateways
and consent banners eager. See [Deferred JavaScript](deferred-js.md).

## Shop, builders, hosting

**Will this work with WooCommerce?**
Yes, with safeguards: dynamic route detection, cart/checkout/account cache
exclusions, Store API awareness, an optional self-test, and asset removal
off by default. Test shop flows on staging. See
[Compatibility](compatibility.md) and [Page Cache](page-cache.md).

**Elementor / Divi / page builders?**
Builder-aware handling and exclusions are included; test layouts after each
asset change. See [Compatibility](compatibility.md).

**Shared hosting?**
Yes — everything except [Redis](redis.md) works anywhere. Redis needs a
Redis server plus the PHP Redis extension.

**LiteSpeed hosting?**
Coexistence modes, purge sync, and headers are built in; ESI needs LSWS
Enterprise (not OLS). See [LiteSpeed](litespeed.md).

**Do I need a CDN?**
Optional. Add one for global audiences; purge fan-out keeps it fresh. See
[CDN](cdn.md).

## Data, privacy, settings

**What data leaves my site?**
PageSpeed scans (your URL + key, only when you scan), Google Fonts hosting
(only when enabled), edge purges (zone IDs + purged URLs, only when
configured), and first-party RUM (path + LCP/INP/CLS, never leaves your
database). Full per-service disclosures: `readme.txt` External Services.
RUM details: [Monitoring](monitoring.md).

**Can I import/export settings?**
Yes — Tools tab JSON export/import, useful for agencies. Staged asset
experiments use the sandbox preview before promoting.

**How do I clean the database?**
Back up, review counts, run once, optionally schedule. Full guide:
[Database Cleanup](database-cleanup.md). Autoload fixes support dry-run and
revert.

**Something broke — where do I start?**
[Troubleshooting](troubleshooting.md): every section leads with the safe
rollback (disable → purge → re-test).
