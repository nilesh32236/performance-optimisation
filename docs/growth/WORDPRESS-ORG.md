# WordPress.org Positioning Audit

**Campaign:** Phase A — Autonomous WordPress.org Searchability Campaign
**Audit date:** 2026-09-25
**Listing:** [Performance Optimisation on WordPress.org](https://wordpress.org/plugins/performance-optimisation/)

## Current listing baseline

The official listing and the repository readme currently show:

- **Name:** Performance Optimisation
- **Version shown by the directory:** 2.4.0
- **Current short description:** “Speed up WordPress with page caching, JS/CSS minify, lazy load, WebP/AVIF images, Redis object cache, and database cleanup. Simple and powerful.”
- **Current tags:** `cache`, `performance`, `speed`, `pagespeed`, `minify`
- **Current opening:** a broad all-in-one speed-plugin description, followed by a long feature catalogue that placed RUM, AI Adaptive, LiteSpeed, Edge Cache, crawler, bfcache, llms.txt, ESI, and Abilities API alongside primary user benefits.
- **Current compatibility language:** “fully compatible” and “fully tested” claims for multiple themes, page builders, WooCommerce, and SEO plugins. Repository evidence supports integration safeguards and targeted tests, but not a blanket guarantee for every version of every integration.
- **Current section order:** Description, Installation, Changelog, FAQ, External Services, Upgrade Notice.

The current listing was read from the [official WordPress.org page](https://wordpress.org/plugins/performance-optimisation/), the [official markdown representation](https://wordpress.org/plugins/performance-optimisation/?output_format=md), and the [official plugin information API](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=performance-optimisation). These sources can change after deployment; this report does not claim that the directory has already updated.

## Research findings

### Search terminology

Current WordPress.org tag/category pages expose terminology around [performance](https://wordpress.org/plugins/tags/performance/), [cache](https://wordpress.org/plugins/tags/cache/), [core-web-vitals](https://wordpress.org/plugins/tags/core-web-vitals/), [image compression](https://wordpress.org/plugins/tags/image-compression/), [WebP](https://wordpress.org/plugins/tags/webp/), and [AVIF](https://wordpress.org/plugins/tags/avif/). The existing plugin supports all of these concepts, but the old opening copy did not make Core Web Vitals or image optimization as immediately visible as caching and PageSpeed.

The full phrase-to-feature mapping is in [KEYWORD-MAP.md](KEYWORD-MAP.md).

### Competitor terminology comparison

The official plugin API was used to inspect the current tag sets of [LiteSpeed Cache](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=litespeed-cache), [WP Super Cache](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=wp-super-cache), [W3 Total Cache](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=w3-total-cache), and [Autoptimize](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=autoptimize). The useful directional observation is terminology alignment: performance/caching/pagespeed are common category language, while image and Core Web Vitals tags are directly relevant to this plugin's existing functionality.

This comparison is not a ranking, popularity, or conversion analysis. No active-install or search-position claim is used to choose wording.

## Phase A changes

### Name

**Kept:** `Performance Optimisation`.

The current name is descriptive, distinctive enough for the existing brand, and does not need keyword stuffing. No materially clearer compliant name was established by the research.

### Short description

**Before:**

> Speed up WordPress with page caching, JS/CSS minify, lazy load, WebP/AVIF images, Redis object cache, and database cleanup. Simple and powerful.

**After:**

> Free WordPress performance plugin for faster sites with page caching, Core Web Vitals monitoring, image optimization, and CSS/JS optimization.

The new wording leads with the broad performance intent and the strongest verified feature clusters without listing every advanced option. It remains natural and concise.

### Tags

**Before:** `cache, performance, speed, pagespeed, minify`

**After:** `performance, cache, optimization, core-web-vitals, pagespeed`

**Reasoning:**

- `performance` — primary category intent.
- `cache` — direct page-cache intent and the existing directory tag.
- `optimization` — broad umbrella intent for the plugin's existing performance suite.
- `core-web-vitals` — PageSpeed monitoring, LCP/INP/CLS RUM, and preloading are existing features and a current WordPress.org tag category.
- `pagespeed` — existing PageSpeed Insights integration and current directory terminology.

The set contains no competitor names, no unsupported terms, and exactly five tags. `minify` remains in feature prose because it accurately describes the functionality, but it is not one of the five selected category tags. Image optimization remains prominent in the short description and readme even though `image-optimization` is not one of the five selected category tags.

### Readme hierarchy

The new hierarchy is:

1. Description
2. Why Performance Optimisation?
3. Core Performance Features
4. Advanced Features
5. Compatibility
6. Installation
7. Frequently Asked Questions
8. External Services
9. Changelog
10. Upgrade Notice

The first screenful now emphasizes free WordPress performance, page caching, Core Web Vitals, images, and CSS/JS optimization. Advanced capabilities remain documented lower in the page.

### Compatibility claims

Compatibility wording is now qualified. The repository and tests support targeted safeguards and integration paths, but do not prove every version of every third-party product.

| Integration | Classification | Readme treatment |
|---|---|---|
| Elementor | supported safeguard / tested paths | Builder-aware handling and exclusion guidance; staging test recommended |
| Divi | supported safeguard / tested paths | Builder-aware handling and exclusion guidance; staging test recommended |
| Astra | compatibility safeguard / internal benchmark context | No blanket “fully tested” guarantee |
| GeneratePress | supported safeguard / referenced compatibility path | No blanket guarantee |
| Kadence | supported safeguard / referenced compatibility path | No blanket guarantee |
| WooCommerce | supported safeguard / targeted tests | Dynamic route detection, cache exclusions, self-test, and staging guidance |
| Yoast SEO | best effort / coexistence guidance | No blanket guarantee |
| Rank Math | best effort / coexistence guidance | No blanket guarantee |
| All in One SEO | best effort / coexistence guidance | No blanket guarantee |
| SEOPress | best effort / coexistence guidance | No blanket guarantee |
| LiteSpeed/OpenLiteSpeed | supported integration with server configuration | Auto/WPPO/LiteSpeed/Standalone modes, headers, purge sync, and server notes remain documented |
| Redis | supported feature with prerequisite | Redis installation/configuration requirement remains explicit |
| CDN providers | supported optional integration | External Services disclosures and opt-out instructions remain authoritative |

### Copy and information-hierarchy safeguards

- No performance algorithm, cache behavior, REST route, database behavior, or admin UI was changed.
- Advanced terms remain present, but below the primary value proposition.
- Compatibility language no longer promises universal compatibility.
- External Services disclosures remain detailed and are not abbreviated away.
- Upgrade Notice and historical changelog entries are retained.

## Phase B — Visual conversion assets

Phase B adds real visual assets for the existing product without changing functionality.

### Delivered assets

- `assets/banner-1544x500.png` — 1544×500 WordPress.org banner using the plugin's navy/cyan/lightning identity. It communicates the plugin name, WordPress performance, page cache, Core Web Vitals, and image optimization without “best”, “#1”, or guaranteed-speed claims.
- `assets/icon-256x256.png` — 256×256 small-size lightning mark with no tiny text.
- `assets/screenshot-1.png` through `assets/screenshot-7.png` — 1200×900 captures of the current live admin UI: Dashboard, File Optimisation, Image Optimisation, Performance Audit/PageSpeed, Database Cleanup, Object Cache/Redis, and Preload Settings.

### Screenshot evidence

Screenshots were captured from the real WordPress installation with Playwright/Chromium. The responsive QA set was checked at 1280×800, 1024×768, and 390×844. The captured flows reported no console errors, horizontal overflow, or secret values. Live domain text was replaced with `example.com`; the captures show no API keys, passwords, authentication headers, private customer information, or unsaved-change dialogs. The readme now has seven matching screenshot captions.

The published assets are real UI captures; the banner and icon are presentation assets only and do not imply performance guarantees.

## Validation and publication status

Repository validation is recorded in the Phase A issue/PR. Directory publication is **not yet claimed**: after merge, the WordPress.org deployment/sync workflow must be observed, then the actual listing must be checked again for title, short description, tags, headings, links, screenshots, and markdown rendering. WordPress.org directory updates can be delayed after repository publication.

## Remaining opportunities

1. Verify the post-sync directory page and record its timestamp/version.
2. Confirm screenshot assets and banner/icon rendering after the readme update.
3. Review search-console or directory-provided impressions only when those data are available; do not infer rankings from local wording changes.
4. Keep future copy improvements evidence-based; do not add terms unless they map to an existing feature or a clearly documented compatibility/configuration path.
5. Re-capture screenshots after a future admin UI redesign; the current captures intentionally reflect the current v2.4.0 UI.
