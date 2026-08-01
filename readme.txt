=== Performance Optimisation ===
Contributors: nilesh912
Tags: cache, performance, speed, pagespeed, minify
Requires at least: 6.2
Requires PHP: 8.2
Tested up to: 7.0
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Speed up WordPress with page caching, JS/CSS minify, lazy load, WebP/AVIF images, Redis object cache, and database cleanup. Simple and powerful.

== Description ==

**Performance Optimisation** is a free, all-in-one speed plugin that makes your WordPress site faster — without the complexity. Fully compatible with popular themes and page builders (Elementor, Divi, Astra, GeneratePress, Kadence, WooCommerce, Yoast SEO, Rank Math). Page caching, file minification, image optimization, lazy loading, database cleanup, and Redis object cache — all from one clean dashboard.

= Why choose Performance Optimisation? =

Most performance plugins either do too little or overwhelm you with dozens of confusing settings. This plugin gives you **everything you need to speed up WordPress** in one place, with safe defaults and clear explanations for every option.

 - **Simple to use:** Clean, modern dashboard. Enable what you need, leave the rest off. No guesswork.
 - **Powerful features:** Page cache, JS/CSS/HTML minify, WebP/AVIF image conversion, lazy loading, Redis object cache, database cleanup — the full stack.
 - **Safe by default:** Aggressive options like defer JS, delay JS, and WooCommerce asset removal are off by default with clear warnings when you turn them on.
 - **Works everywhere:** Shared hosting, VPS, dedicated servers, Apache, Nginx — it adapts to your environment.

= What does this plugin do? =

**🚀 Page Caching**
Generate static HTML files for your pages so they load instantly. Includes Gzip compression, CDN support, and smart cache clearing when you update content.

**📦 File Optimization**
Minify and combine JavaScript, CSS, and HTML. Defer or delay render-blocking scripts. Remove WordPress bloat like emojis, embeds, dashicons, and XML-RPC.

**🖼️ Image Optimization**
Convert images to next-gen WebP and AVIF formats automatically. Lazy load images, iframes, and videos with lightweight SVG placeholders. Preload critical images for faster LCP.

**⚡ Preloading & Prefetching**
Warm up your cache automatically. Preconnect to third-party origins, prefetch DNS, and preload critical fonts and CSS for faster page rendering.

**🗄️ Database Cleanup**
Remove post revisions, auto-drafts, spam comments, expired transients, and orphaned metadata. Schedule automated cleanups daily, weekly, or monthly.

**🔴 Redis Object Cache**
Built-in Redis object cache with support for standalone, Sentinel, and Cluster topologies. TLS/SSL encryption included. No separate plugin needed.

**📊 Performance Monitor**
Built-in performance scanner that measures real load times, TTFB, DNS resolution, and Core Web Vitals — right from your WordPress dashboard.

**🛠️ Developer Friendly**
System Info dashboard, Google PageSpeed Insights integration, per-page asset manager, and import/export settings.

= Who is this plugin for? =

 - **Site owners** who want a faster website without hiring a developer.
 - **Freelancers and agencies** who need a reliable speed plugin they can deploy across client sites.
 - **Developers** who want granular control over caching, minification, and delivery without vendor lock-in.

This plugin uses `voku/html-min` for HTML minification, `matthiasmullie/minify` for JavaScript and CSS minification, and `woocommerce/action-scheduler` for background job processing.

== Installation ==

1. Install the plugin from the **WordPress Plugin Directory** (search for "Performance Optimisation") or upload it manually to `/wp-content/plugins/performance-optimisation`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to the **Performance Optimisation** menu in your WordPress admin panel.
4. Start with the **Dashboard** to see your current performance status, then enable features one at a time.

After activation, you can manage the following from the settings tabs:

- **Dashboard** — See your cache size, optimized file counts, image status, and recent activity at a glance.
- **File Optimization** — Minify JS/CSS/HTML, combine CSS, defer/delay JS, and remove WordPress bloat.
- **Preload** — Cache warm-up, preconnect, DNS prefetch, and font/CSS preloading.
- **Image Optimization** — Lazy load images with SVG placeholders, convert to WebP/AVIF, and preload feature images.
- **Database** — Clean up revisions, auto-drafts, transients, spam, and orphaned data — manually or on a schedule.
- **Object Cache** — One-click Redis integration with standalone, sentinel, and cluster support.
- **Tools** — Import/export settings for quick deployment across multiple sites.

== Screenshots ==

1. **Dashboard**: Real-time overview of cache status, optimized files, image conversion progress, and recent activity log.
2. **File Optimization**: Minify JavaScript, CSS, and HTML with granular controls for defer, delay, combine, and exclude rules.
3. **Preload**: Cache warm-up, preconnect, DNS prefetch, and critical asset preloading with fetchpriority hints.
4. **Image Optimization**: One-click WebP/AVIF conversion, smart lazy loading with SVG placeholders, and LCP image preloading.
5. **Database Cleanup**: Safe manual and automated cleanup with fine-grained revision control (by age and count).
6. **Object Cache**: Redis integration with standalone, sentinel, and cluster topology support — no separate plugin needed.
7. **Tools**: One-click import/export for deploying your performance configuration across multiple sites.

== Changelog ==

= 1.8.1 (2026-08-01) =
* New: Inline the combined/minified CSS via WordPress core's `wp_maybe_inline_styles()` when the file is within the `styles_inline_size_limit` budget, eliminating a render-blocking stylesheet round-trip on first load. Use the `wppo_inline_combined_css` filter (return falsy) to disable inlining, e.g. when serving the combined file from a CDN.

= 1.8.0 (2026-07-28) =
* New: WordPress 6.9+ object cache key salt support for cache key space invalidation.
* New: Lazy loading and HTML Tag Processor support for `iframe` elements.
* Performance: Pre-cached delayJS script exclusion parsing in HTML minification worker loops.
* Performance: Cached `home_url()` path per blog ID in URL utility resolution.
* Accessibility: Added `aria-describedby` accessibility associations and modal focus trap management.
* Safety: Integrated React ErrorBoundary wrapper for SPA runtime exception handling.
* Fix: Corrected key-indexed return array structure in Redis Object Cache fallback methods.

= 1.7.0 (2026-07-26) =
* Improvement: Cached minification status checks to reduce disk I/O overhead.
* Improvement: Optimized minification detection using streaming file readers.
* Accessibility: Made Tooltip component fully keyboard accessible and theme-adaptive.
* Accessibility: Added ARIA labels and accessible descriptions across Performance Audit and Database Cleanup settings.
* UI: Integrated LoadingSubmitButton for granular database cleanup actions.
* Fix: Throttled WP_CACHE environment verification checks on failure.
* Fix: Aligned PageSpeed result strategy labels with scan output and prevented stale suggestion responses on consecutive scans.
* Localization: Migrated client-side translations to @wordpress/i18n.

= 1.6.0 (2026-04-26) =
* New: Google PageSpeed Insights integration — audit Mobile/Desktop performance from your dashboard.
* New: Suggestion Engine — actionable performance tips based on real-time site telemetry.
* New: Nginx Support — dynamic configuration snippets for Gzip and Browser Caching.
* New: WP_CACHE Self-Healing — automatically repairs wp-config.php constant issues.
* Improvement: Modernized telemetry with Zstd support and detailed network timing breakdown.
* Security: Implemented SSRF protection for PageSpeed scans and automated API key redaction.
* Fix: Standardized Object Cache return contracts for multi-key operations.
* Improvement: Updated minimum PHP requirement to 8.2 for enhanced library compatibility.

= 1.5.1 (2026-04-23) =
* Performance: Optimized wppo_img_info database option to reduce memory overhead.
* Fix: Implemented atomic write protection for image metadata.
* Fix: Consolidated build patterns and performance guidelines.

= 1.5.0 (2026-04-20) =
* New: Performance Monitor — High-precision local telemetry engine using raw cURL for granular network diagnostics (DNS, Connect, SSL, TTFB).
* New: System Info Dashboard — Real-time environment diagnostic tool providing detailed PHP, Database, WordPress, and Server metrics.
* New: Developer Mode — Advanced UI toggle for granular performance metrics and environment data.
* Improvement: Enhanced SSRF protection for local telemetry scans.
* Improvement: Modernized UI with dynamic WordPress admin color scheme adaptation using `color-mix()`.

= 1.4.0 (2026-04-18) =
* New: Enterprise Redis Object Cache support including Sentinel, Cluster, and TLS/SSL encryption modes.
* New: Atomic, batched processing for database cleanup and cron tasks to ensure stability on large-scale sites.
* New: Upgraded Design System v2.1 with modular components (FeatureCard, FeatureHeader, SwitchField) and optimized Sass architecture.
* New: Real-time status reporting on the dashboard for background optimization visibility.
* Improvement: Hardened security architecture with AJAX-based nonce resilience and DoS protection for image conversion.
* Improvement: Standardized REST API permission callbacks and input sanitization for enterprise compliance.
* Fix: Resolved structural regressions in REST cleanup controllers and improved reliability of revision management.

= 1.3.0 (2026-04-15) =
* New: Core Tweaks section to remove WordPress bloat (Emojis, Embeds, Dashicons) and control Heartbeat limits.
* New: Active WP-Cron scheduling for Database cleanups (Daily, Weekly, Monthly) with precise age/revision limits.
* New: Injected `fetchpriority` support to preload links for faster asset transmission.
* New: Implemented an active MutationObserver to track and lazy-load dynamically generated DOM content.
* New: Extended server-level cache expiration and Deflate/Gzip compression rules within `.htaccess`.
* New: Added logic to exclude specific self-hosted videos from lazy-loading routines.
* Improvement: Automatically inject `font-display: swap` into CSS payloads to eliminate render-blocking text.
* Improvement: Substantially refined UI aesthetics by migrating to native WP CSS variables and expanding component descriptions.
* Improvement: Added logic toggles to conditionally skip heavy HTML, CSS, and JS minification overhead.
* Fix: Mitigated false-positive SQL placeholder warnings (`WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare`) in database garbage collection routines.

= 1.2.3 (2026-04-14) =
* Fix: Resolved fatal error where `Advanced_Cache_Handler` was not found during activation or admin notice checks.
* Performance: Refactored `Advanced_Cache_Handler` to use lazy loading ("require when needed") to reduce memory footprint.
* Fix: Shortened plugin short description to meet WordPress.org's 150-character limit.

= 1.2.1 (2026-04-14) =
* Fix: Implemented handle whitelisting in Metabox to prevent unauthorized script/style handle persistence.
* Fix: Support parent directory locations for `wp-config.php` (core-mirroring behavior).
* Fix: Properly handle transient deletion and `WP_CACHE` constant guards during activation.
* Fix: Alignment and escaping in admin notices for WPCS compliance.
* Fix: Add `WP_CACHE` to wp-config.php when the constant was previously undefined (correct activation logic).
* Safety: `advanced-cache.php` includes a plugin marker; do not overwrite or delete another plugin’s drop-in.
* UX: Admin notices for foreign drop-in, wp-config issues, competing full-page cache plugins, and a short post-activation welcome notice.
* UI: Stronger warning when enabling WooCommerce asset removal.
* Docs: Expanded readme description, FAQ, and screenshot placeholders.
* Meta: Plugin header `Requires at least` now matches readme.txt (6.2).

= 1.2.0 (2026-04-13) =
* New: Automatic Gzip compression and browser caching for faster page loads.
* New: CDN support — serve static assets from your own CDN domain.
* New: Smarter cache clearing — related pages update automatically when you edit content.
* New: Safety prompts before deleting data, removing images, or importing settings.
* New: Helpful warnings when enabling advanced options like Defer JS or Server Rules.
* New: Plugin UI matches your chosen WordPress admin color scheme.
* Improvement: Faster loading — removed external font dependency.
* Improvement: Better form inputs, loading indicators, and keyboard navigation.
* Improvement: Faster database operations for image processing.
* Security: Fixed several file path security issues.
 * Compatibility: Tested up to WordPress 6.9.
 * Compatibility: Tested up to WordPress 7.0.

= 1.1.4 (2026-04-08) =
* Security: Fixed path traversal vulnerability in the Image Optimisation REST endpoint.
* Security: Added directory traversal protection in URL-to-path resolution.
* Performance: Optimized image queue database writes by caching in memory and flushing once on shutdown.
* Fix: Updated CheckboxOption component to use unique IDs for proper accessibility (label/input association, aria-describedby).

= 1.1.3 (2026-04-07) =
* Fix: Anchored build paths in .distignore to prevent accidental exclusion of vendor files.

= 1.1.2 (2026-04-07) =
* Fix: Cache the Img_Converter instance to reduce PHP overhead during image conversion.
* Fix: Validate and sanitize imported REST API settings before saving.
* Fix: Improve sidebar accessibility and keyboard navigation in the admin UI.
* Update: Use `@wordpress/element` for React rendering compatibility in WordPress.

= 1.1.1 (2026-04-06) =
* Improvement: Optimized JS Defer and Delay loading by caching exclusion lists.
* Improvement: Enhanced backend performance by reducing redundant string parsing.
* Security: Implemented protection against potential directory traversal vulnerabilities.
* Fix: Standardized REST API key sanitization to prevent settings synchronization issues.
* Localization: Added translated ARIA labels for sidebar accessibility.

= 1.1.0 (2026-04-05) =
* Improvement: Visually enhanced the 'File Optimization' settings for easier configuration.
* Improvement: Hardened overall plugin security and input validation.
* Fix: Automatically clear cache when changing permalink settings or switching themes.
* Fix: Prevented unnecessary CSS files from generating on 404 error pages.
* Update: Improved image lazy loading reliability for smoother page rendering.


= 1.0.0 (2024-12-18) =

Initial release with full functionality:
Dashboard overview.
Cache management.
JavaScript, CSS, and HTML optimization.
Advanced image optimisation and lazy loading.
Preloading settings for cache, fonts, and images.
Import/export settings tools.

== Frequently Asked Questions ==

= How do I speed up my WordPress site with this plugin? =
Install and activate the plugin, then visit the **Dashboard**. Start by enabling **Page Caching** for the biggest speed boost. Then enable **JS/CSS Minification** and **Lazy Loading** for images. Each feature can be turned on independently — enable one at a time and test your site.

= Will this work with WooCommerce? =
Yes. The plugin is fully compatible with WooCommerce. WooCommerce-specific asset removal is **optional** and off by default. If you enable it, the plugin shows a clear warning reminding you to test cart, checkout, and product pages.

= Can I use this alongside another cache plugin? =
You should only run **one** full-page caching solution at a time. If another plugin (WP Super Cache, LiteSpeed Cache, WP Rocket, etc.) already manages caching, this plugin will detect it and won't overwrite the existing setup. You can still use the minification, image optimization, and database cleanup features alongside most other plugins.

= Does this plugin improve Core Web Vitals and PageSpeed scores? =
Yes. In benchmark testing on a standard WordPress install (Astra theme, 5 images), PageSpeed scores increased from 52 to 98/100 on Mobile and 74 to 100/100 on Desktop. Time to First Byte (TTFB) dropped from 680ms to 45ms (-93%), LCP improved from 4.1s to 1.2s (-71%), and total page size was reduced from 3.2 MB to 820 KB (-74%). Features like lazy loading, static HTML caching, WebP/AVIF image conversion, font preloading, and script deferral directly target Core Web Vitals metrics.

= Does this work on shared hosting? =
Yes. The plugin works on any standard WordPress hosting — shared hosting, VPS, dedicated servers, and managed WordPress hosts. Redis Object Cache requires Redis to be installed on your server, but all other features work everywhere.

= Is this compatible with page builders like Elementor or Divi? =
Yes. The plugin works with all major page builders including Elementor, Divi, Beaver Builder, and WPBakery. If you experience any layout issues after enabling minification, you can exclude specific files using the built-in exclusion rules.

= Is this plugin compatible with popular themes, WooCommerce, and SEO plugins? =
Yes. It is fully tested and compatible with major themes (Astra, GeneratePress, Kadence, OceanWP, Blocksy, Twenty Twenty-Four), e-commerce (WooCommerce), and SEO plugins (Yoast SEO, Rank Math, All in One SEO, SEOPress). WooCommerce cart, checkout, and account pages are automatically excluded from full-page caching. If minification or deferral affects specific scripts or style handles, you can add them to the exclusion rules in the File Optimization tab.

= How do I convert images to WebP or AVIF? =
Go to the **Image Optimization** tab, enable image conversion, and choose your format (WebP, AVIF, or both). Click **Optimize Now** to start converting your existing images. New uploads are converted automatically in the background.

= Can I exclude specific files from minification? =
Yes. In the **File Optimization** tab, you can list specific JavaScript or CSS files to exclude from minification, defer, or delay. This is useful for scripts that break when minified.

= Does the plugin support lazy loading? =
Yes. The plugin lazy loads images, iframes, and videos using an IntersectionObserver. You can use lightweight SVG placeholders for a better loading experience. A MutationObserver also catches dynamically injected content.

= How do I clean up my WordPress database? =
Go to the **Database** tab. You can manually clean post revisions, auto-drafts, spam comments, expired transients, trashed posts, and orphaned metadata. You can also schedule automatic cleanups to run daily, weekly, or monthly.

= Can I import/export plugin settings? =
Yes. Use the **Tools** tab to export your current configuration as a JSON file and import it on another site. This is useful for agencies deploying the same setup across multiple client sites.

= Is this plugin free? =
Yes. Performance Optimisation is 100% free and open source. There is no premium version, no upsells, and no feature restrictions.

== Upgrade Notice ==

= 1.8.0 (2026-07-28) =
Feature and performance release introducing WordPress 6.9+ object cache salt support, iframe lazy loading, delayJS parsing optimizations, and accessibility enhancements.

= 1.7.0 (2026-07-26) =
Performance, accessibility, and stability release adding cached minification checks, keyboard-accessible tooltips, ARIA accessibility labels, request cancellation safety, and 100% React component test coverage.

= 1.6.0 (2026-04-26) =
Major feature release bringing official Google PageSpeed Insights integration to the WordPress dashboard. Introduces Nginx configuration support, automatic wp-config.php self-healing, and enhanced telemetry with modern compression support (Zstd). Now requires PHP 8.2 for high-performance library compatibility.

= 1.5.1 (2026-04-23) =
Performance and stability release optimizing the `wppo_img_info` database option for reduced memory overhead and implementing atomic write protection for image metadata.

= 1.5.0 (2026-04-20) =
Introduces the Performance Monitor (high-precision local telemetry engine), System Info Dashboard for real-time environment diagnostics, and a new Developer Mode for granular network timings.

= 1.4.0 (2026-04-18) =
Major stability and feature release introducing Enterprise Redis Support, batched processing architecture for long-running tasks, and a refined Design System v2.1. Includes critical security hardening and AJAX-based session resilience.

= 1.3.0 (2026-04-15) =
Feature release introducing automated database optimization scheduling, comprehensive "Core Tweaks", MutationObserver-based lazy loading, and numerous systemic UI/UX improvements utilizing native WordPress CSS schemas.

= 1.2.3 (2026-04-14) =
Stability and performance release: Fixed a fatal error during activation/admin notices, implemented lazy loading for cache handlers to reduce overhead, and aligned documentation with official directory limits.

= 1.2.1 (2026-04-14) =
Stability and security release with wp-config path resolution fixes, asset handle whitelisting, and improved activation logic for WP_CACHE management.

= 1.2.0 (2026-04-13) =
Major feature release completing the "Cache Core" milestone: .htaccess automation, CDN URL rewriting, and smart cache purging. Includes a full Design System v2.0 with WordPress admin color scheme sync, confirmation dialogs, and polished form controls. Significant security and performance improvements throughout.

= 1.1.4 (2026-04-08) =
Security release with path traversal fixes, image queue performance improvements, and accessibility fixes.

= 1.1.3 (2026-04-07) =
Maintenance release to fix vendor file exclusion in build packages.

= 1.1.2 (2026-04-07) =
Compatibility release ensuring React rendering compatibility with @wordpress/element and sanitized REST API imports.

= 1.1.1 (2026-04-06) =
Minor release with JS performance optimizations and security hardening.

= 1.1.0 (2026-04-05) =
Feature release introducing Database Cleanup tools, Asset Manager monitoring, and a major UI overhaul of File Optimization settings.

= 1.0.0 (2024-12-18) =
Initial release with core performance features.
