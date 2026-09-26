# Changelog

All notable changes to the Performance Optimisation plugin will be documented in this file.

## [2.0.0] - 2026-09-11

### ⚠️ Breaking Changes

- **Removed REST route `performance-optimisation/v1/get_page_assets`** and the `Rest::get_page_assets()` handler. Use the Abilities API operation `performance-optimisation/get-page-assets` or `Asset_Manager::get_page_assets()` instead.
- **Removed public static method `Cache::clear_ccss()`.** External callers must use `Critical_CSS::clear_all()`. No compatibility shim ships.
- **Removed the legacy `core_tweaks` settings tab/key.** `update_settings` with `tab=core_tweaks` and `import_settings` payloads containing a top-level `core_tweaks` key now return HTTP 400, so a settings export from 1.9.0 that contains `core_tweaks` can no longer be imported. Core-tweak values always lived under `file_optimisation`, so no live settings are lost.
- **Removed `file_optimisation.removeQueryStrings`** and the entire `?ver=` stripping path (`Main::strip_static_query_strings()`, `is_plugin_cache_url()`, default, SPA toggle). A stored legacy value is ignored (fail-open: `?ver` is always preserved) with a one-time activity-log notice, and the key is dropped on the next save.
- **Removed orphaned REST routes `performance-optimisation/v1/crawler` and `crawler_status`.**
- **Changed default: native lazy loading.** `image_optimisation.lazyLoadNative` now defaults to `true` (native `loading="lazy"` + `decoding="async"`); the legacy JS IntersectionObserver loader is opt-in via `lazyLoadNative=false`. Installs missing the key inherit native in memory; an explicit stored `false` is preserved.
- **Changed defaults: speculative loading.** `preload_settings.speculationMode` changed `prerender` → `prefetch` and `speculationEagerness` `moderate` → `conservative` (fallbacks in `class-main.php` match); `speculationRumGating` defaults on. Installs without stored values get the more conservative behaviour.
- **Changed defaults: safe-by-default toggles now ship enabled** where the key is absent for both existing and new installs: `file_optimisation.delayJSSafeMode`, Delay-JS presets (`delayJSBuilderPreset`, `delayJSCommercePreset`, `delayJSInteractionPreset`), `unusedCSSRegressionGuard`, `ccssRumPriority`, `usedCssRumPriority`, `image_optimisation.lcpHeroPreload`, `lcp_guardrails`, `avifFirst`, `smartQuality`, `cache_settings.wooSafeMode`, `speculationRumGating`.
- **Import/export contract is stricter:** unknown top-level setting keys are now rejected with HTTP 400 (previously only the old whitelist was enforced).
- **`object_cache` REST returns HTTP 400 for an unsupported `flush_group`** (previously a silent no-op path).
- **Deactivate/uninstall teardown** now removes `.htaccess` markers/rules and generated drop-ins (`advanced-cache.php`, object-cache drop-in) and cleans options on uninstall. Re-activation regenerates them.
- **Public signature changes (backward-compatible):** `Rest::permission_callback( ?\WP_REST_Request $request = null ): bool`; `Util::cached_home_url( string $path = '' ): string`; `Main::emit_server_timing_header( string $output = '' ): void`; `Critical_CSS::generate( string $url, ?string &$source_css = null, ?array &$resolved_urls = null )`; `Minify\JS::get_cache_file_path` visibility is now public (fixes a frontend 500).
- Minimum runtime remains WordPress 6.2 / PHP 8.2, now enforced by a runtime guard that cleanly self-deactivates on older versions.

### Added

- **LiteSpeed / OpenLiteSpeed coexistence:** four modes (`auto` / `wppo` / `litespeed` / `standalone`), server + LSCache detection, native `X-LiteSpeed-*` header protocol, cache-control bridge, ESI punch-holing with AJAX fallback, per-page and per-post-type TTL overrides, CVE guard filter, Vary/guest parity and CDN mapping parity.
- **LiteSpeed cache crawler:** background preloader with variant matrix, concurrency, load limits and sitemap discovery.
- **Edge cache, CDN & purge:** Edge HTML Cache adapter (Cloudflare Workers / Bunny Edge), purge fan-out for Cloudflare, Bunny and Varnish, per-mapping LiteSpeed CDN URL rewrite with attribute controls, and a builder-update purge watcher for Elementor/Divi/Bricks/WPBakery.
- **Real-User Monitoring (RUM):** anonymised field Web Vitals (LCP/INP/CLS) collection, aggregation, `rum_collect`/`rum_data`/`web_vitals_trends` REST routes and trend charts.
- **AI Adaptive:** RUM/trend heuristic auto-tune with an optional WordPress AI client, read-only suggestions, multi-metric anomaly detection with cooldown + RUM corroboration, and `ai_model`/`ai_learn`/`ai_suggestions` routes.
- **Optimization Detective bridge** for real-visit LCP data.
- **Configurable static-HTML Cache Life (TTL)** baked into the advanced-cache drop-in, with per-URL/role variants.
- **Optimised CSS pipeline:** safe-by-default Used CSS with coupled purge and builder-drift requeue, a user safelist with checksum auto-regeneration, and RUM-prioritised critical/used-CSS queues.
- **Critical CSS:** max-size cap with per-template variants, file-first delivery, localhost synchronous fallback and a staleness probe.
- **Redis object-cache circuit breaker** with auto-disable, recovery probe and admin notice; serializer defaults, flush hygiene and in-app failure logging.
- **bfcache support for logged-in users** and `.mo` → `.php` performance translations.
- **`llms.txt` / `llms-full.txt`** virtual files refreshed daily.
- **Delay-JS presets** (INP-first, builder, commerce, interaction), a per-page kill switch and safe mode.
- **LCP guardrails:** never lazy-load above-the-fold content, preload the hero with `fetchpriority`, LCP-aware lazy load and field-measured LCP targeting.
- **`content-visibility` lazy-render** of below-fold DOM.
- **Speculation rules:** high-value URL lists, mode/eagerness validation via `WP_Speculation_Rules`, WP 7.1 host overrides, core-API narrowing with commerce/nonce exclusions and RUM-gated eagerness.
- **Native `fetchpriority` and `in_footer`** via the core Script Loader / footer script-module APIs on WP 6.9+.
- **Images:** AVIF-first `<picture>` output, smart quality, skip-small threshold, HDR bit-depth respect, UltraHDR gain-map skip, max-longest-edge cap and pixel-budget OOM guard.
- **Google Fonts self-hosting** with font-metric fallback and backoff.
- **WooCommerce safe cache defaults:** dynamic-page safety, cart/checkout exclusion, `wooSafeMode` toggle and a checkout/cart self-test REST route (`woo_cache_self_test`).
- **WordPress Abilities API (WP 6.9+)** surface (13 core operations + operational/image/asset actions) and a `wp wppo verify` WP-CLI command.
- **Autoloaded-options audit** (`autoloaded_options`, `autoload_remediate` dry-run/apply/revert/revert_all) and a read-only expired-transients export (`expired_transients_export`).
- **Runtime guard** with clean self-deactivation for the PHP 8.2 / WP 6.2 floor.
- **Redesigned dashboard and all settings tabs** with WCAG AA contrast, equal-height metrics, segmented tabs, full mobile/RTL support and 44×44 touch targets.

### Changed

- Canonical default settings are single-sourced in `Util::get_default_settings()` and consumed by REST/CLI/Main.
- Speculative loading is narrowed to the core `WP_Speculation_Rules` API with commerce/auth/nonce exclusions and cache awareness.
- CSS combine defers to core 6.9+ block-style hoisting and inline-style budgets, and skips small block-theme bundles.
- Lazy loading honours core's `loading` decision for LCP images and preserves `auto-sizes`/`contain`.
- Native core APIs adopted on WP 6.9+: template enhancement buffer, salted-cache deletes, `serialize_token`, `WP_Block_Processor`, `wp_maybe_inline_styles` budget, fetchpriority/`in_footer`.
- Redis serializer resolution never selects msgpack; serializer support is reported in status.
- Settings access hardened: nonce-verified `permission_callback`, sensitive-value redaction from REST responses and memoised settings invalidation.
- Dependency bumps: `woocommerce/action-scheduler` 3.9.3 → 4.1.0; `voku/html-min` ^5.0 (PHP 8.5); `squizlabs/php_codesniffer` → 3.13.6 (CVE-2026-67434).

### Fixed

- **Fatals / hard errors:** Redis drop-in `WP_PLUGIN_DIR` undefined before object-cache boot; wp-login/admin fatals from a typed property and filter signature; `CDN::rewrite_srcset` non-array filter arg; `Minify\JS::get_cache_file_path` visibility causing a frontend 500; `FS_CHMOD_FILE` fatal in a namespaced context; `WP_REST_Response::remove_header()` on an undefined method; `RedisSentinel` constructor strict-types instantiation; escaped `preg_match` delimiter in the drop-in; CLI fresh-install and sibling-tab settings wipe.
- **Cache:** static-cache path traversal containment and atomic writes; `advanced-cache.php` atomic write with tmp+rename+backup; `.htaccess` atomic writes, identical-content skip and post-write verification; static-cache TTL/freshness; `flush_group` REST 400; object-cache drop-in ownership/legacy cleanup.
- **CSS/JS:** preserve the `wppo-critical-css` id when minifying inline styles; localhost synchronous CCSS fallback; null-byte-free noscript tokenization; media-print deadlock when defer/delay swaps scripts; core inline-styles budget drift; JS switch ARIA, dialog leak, lazyload teardown and App.js loop.
- **Images:** HEIC/JXL client-side MIME UI, HEIC early-exit and wasm gating; HDR bit depth + per-size quality; UltraHDR gain-map skip; smart AVIF/WebP quality with core deference and crop safety; srcset rewrite hardening.
- **RUM:** `keepalive:true` beacon fallback; observer disconnect + nonce refresh + fallback-timer dedupe; page-scoped RUM tokens.
- **Settings/REST/CLI:** settings verify schema decoupled from runtime defaults; strict top-level key validation; `core_tweaks` rejection; import/export edge cases; `wp wppo verify` correctness.
- **WP compatibility:** WP 6.9 block styles, WP 7.0 drop-in boot, WP 7.1 client-side media and speculation defaults; PHP 8.5 `curl_close`/image destroy; `:void` return-type PHP-version guard.
- **UI:** unsaved-changes guard on tab switch; per-action loading state split; tooltip keyboard/Esc handling; notice-banner a11y; dialog scroll-lock compensation; RTL tab fade/focus ring; feature-header wrap/i18n overflow; mobile input 16px rules; sidebar RTL drawer; disabled-button a11y and warning contrast; responsive rhythm overflow.
- **Release:** plugin ZIP missing `vendor/`.

### Performance

- Centralised `home_url()`/`content_url()` static caching, eliminated N+1 permalink resolution, memoised settings access, Google Fonts backoff and autoload dedup.
- Optimised URL-to-path resolution and removed regex in the CSS minifier; cached parsed content-URL parts in asset enqueue hooks.
- Memoised URL-exclusion rules in `Util::is_url_excluded`, optimised WooCommerce script exclusion and string normalisation.
- `SRC_STAT` LRU(500) and Cloudflare purger dedupe.
- Parallelised React initial data fetching (WelcomePanel/Dashboard).
- Critical CSS size cap, Used-CSS safelist/checksum and RUM-prioritised queues reduce generation cost.
- Native lazy loading removes the JS IntersectionObserver payload for most sites; `content-visibility` lazy-renders below-fold content; LCP guardrails avoid above-the-fold penalties.

### Security

- Fixed path traversal → arbitrary file write / `.htaccess` overwrite, symlink traversal and `wppo_delete_directory` path containment.
- Fixed host-header cache poisoning → stored XSS, generated CSS/JS callback + lazy-load rewriter XSS and RUM config reflection XSS.
- Fixed SQL injection in the database `optimize_table` path.
- Hardened ESI/AJAX nonce verification, ESI hydration and permission-callback nonce checks.
- Hardened telemetry/SSRF: redirect SSRF, Critical-CSS SSRF guard and inline-CSS sanitisation; redacted System Info output, rejected external image URLs and gated CDN purge.
- Atomic write + verify/rollback for `wp-config` `WP_CACHE`, `advanced-cache.php` and `.htaccess`.
- Strict nonce/capability enforcement on all REST routes; `rum_collect` remains public but is token + IP rate-limited.

### Removed

- `performance-optimisation/v1/get_page_assets` REST route and `Rest::get_page_assets()` handler.
- `Cache::clear_ccss()` public static method (replaced by `Critical_CSS::clear_all()`).
- `core_tweaks` from `Util::ALLOWED_SETTINGS_KEYS` / allowed `update_settings` tabs (rejected with HTTP 400).
- `file_optimisation.removeQueryStrings` setting and the `?ver=` stripping path.
- Orphaned REST routes `performance-optimisation/v1/crawler` and `crawler_status`.
- Legacy `advanced-cache.php` / object-cache drop-ins on uninstall.

### Deprecated

- No new deprecations. The former `get_page_assets` deprecation is superseded by its hard removal (see Removed).


## [1.9.0] - 2026-08-11

### Added

- **WordPress 7.1+ Client-Side Media Processing Toggle:** Added admin option and `filter_client_side_supported_mime_types` filter callback to control in-browser Web Worker image processing formats, safely intersected with core capabilities.
- **Inline Combined CSS:** Support for inlining combined stylesheets via WordPress core's `wp_maybe_inline_styles()` when within the inline size budget, plus `wppo_inline_combined_css` filter for CDN opt-out.

### Performance

- **Centralized `content_url()` Static Caching:** Asset minification loops now reuse static `content_url()` lookups via `Util::cached_content_url()`. Keys static cache per blog ID (`get_current_blog_id()`) for multisite safety under `switch_to_blog()` and gates caching with `has_filter('content_url')`.

### Changed

- **Resource Hints Migration:** Preconnect and DNS-prefetch emission now leverage core's `wp_resource_hints` filter API. Added automatic scheme-less bare-hostname (`example.com`) normalization to protocol-relative (`//example.com`) form so origins survive core's host guard, while retaining `crossorigin="anonymous"` attributes.
- **Image Conversion Quality:** Integrated WordPress 7.1+ size-aware `wp_get_image_encode_quality()` and WP 6.7–7.0 `wp_image_quality()` for WebP/AVIF conversions while maintaining fixed low-quality (40) for LQIP placeholders.
- **Block Asset Loading on WordPress 6.9+:** The "Load Block Assets On Demand" toggle now matches WordPress 6.9's default for classic themes, which load separate core block styles on demand.
- **WordPress 7.1 Compatibility:** Updated "Tested up to" to WordPress 7.1.

### Multisite & Safety

- **Transient Key Isolation:** All transient keys are qualified with `{blog_id}_` on multisite networks via `Util::transient_key()` to prevent collisions on shared object cache backends (Redis, Memcached).
- **React UI Safety:** Added `Array.isArray()` defensive checks for stored options in React admin SPA components and single-sourced `DEFAULT_CLIENT_SIDE_MIME_TYPES` constants.

## [1.8.1] - 2026-07-29

### Changed

- **WordPress 7.0 Compatibility:** Updated "Tested up to" to WordPress 7.0.

## [1.8.0] - 2026-07-28

### Added

- **WordPress 6.9+ Object Cache Salt Support:** Added object cache key salt prefixing for key space invalidation.
- **Iframe Lazy Loading:** Added `iframe` element support in HTML Tag Processor and frontend lazy loader.
- **React ErrorBoundary Integration:** SPA tab rendering is now wrapped in an ErrorBoundary component to prevent UI crashes.
- **Enhanced Edge Case Tests:** Added test suites for `PerformanceAudit`, `SystemInfo`, `LoadingSubmitButton`, and `DatabaseCleanup`.

### Changed

- **Minification & Utility Performance:** Pre-cached delayJS exclusion patterns in `Minify\HTML` constructor and blog-specific home URL path resolution in worker loops.
- **Accessibility Improvements:** Added `aria-describedby` associations, modal focus trap management, and accessible labels across components.

### Fixed

- **Redis Object Cache Indexing:** Corrected key-indexed return array structure in Redis Object Cache fallback methods.

## [1.7.0] - 2026-07-26

### Added

- **Keyboard-Accessible Tooltips:** Tooltip component now supports full keyboard navigation and focus management.
- **Granular Loading Action Buttons:** Replaced generic buttons in Database Cleanup with accessible `LoadingSubmitButton` components.
- **Component Test Suite:** Complete React component test coverage (`FileOptimization`, `PreloadSettings`, `SystemInfo`, `PerformanceAudit`, `DatabaseCleanup`, etc.).

### Changed

- **Minification Check Caching:** Cached minification checks to prevent excessive disk I/O on asset detection.
- **Streaming Minification Checkers:** Refactored `is_css_minified` and `is_js_minified` to use streaming file readers for enhanced performance.
- **Translation Migration:** Migrated client-side text domains and strings to `@wordpress/i18n`.

### Fixed

- **PageSpeed Result Strategy Alignment:** Strategy labels rendered under PageSpeed results now match the scanned output strategy (`result.strategy`).
- **Request Abort Safety:** Ensured pending `fetchSuggestions` requests are aborted when initiating a new scan to prevent race conditions.
- **WP_CACHE Throttling:** Throttled `WP_CACHE` constant verification on failure to eliminate redundant checks.

## [1.6.0] - 2026-04-26

### Added

- **Google PageSpeed Insights Integration:** Run Lighthouse audits directly from the dashboard for Mobile and Desktop strategies.
- **Actionable Optimization Suggestions:** New suggestion engine that correlates PageSpeed results with server telemetry to provide specific fix recommendations.
- **Nginx Infrastructure Support:** Detailed Nginx configuration snippets for Gzip and Browser Caching, dynamically updated based on plugin settings.
- **Enterprise Redis Improvements:** Enhanced support for Redis Sentinel and Cluster modes with improved connection reliability.

### Changed

- **PHP 8.2 Requirement:** Bumped minimum PHP requirement to 8.2 to ensure compatibility with modern Composer libraries.
- **WP_CACHE Self-Healing:** Automatic monitoring and fixing of the `WP_CACHE` constant in `wp-config.php` during activation and hourly maintenance.
- **Telemetry Breakdown:** Real-time reporting of specific compression types (zstd, gzip, br) and raw Cache-Control headers.
- **Modernized UI:** Refined React state management with polling cleanups and ref-based fetch guards to prevent memory leaks and duplicate requests.

### Fixed

- **Object Cache Contract:** Fixed `WP_Object_Cache` multi-set/delete return contracts to comply with WordPress core.
- **LCP Logic:** Corrected Largest Contentful Paint suggestion logic to handle missing or invalid data.
- **React Hooks:** Resolved various linting violations and dependency issues in dashboard components.
- **A11y:** Improved form label associations for screen readers in the optimization panels.

### Security

- **SSRF Hardening:** Implemented strict URL and host validation for PageSpeed scans to prevent Server-Side Request Forgery.
- **API Key Redaction:** Automated redaction of PageSpeed API keys from all debug logs and exported settings.

## [1.5.1] - 2026-04-23

### Changed

- **Optimized Autoloading:** Disabled autoloading for the `wppo_img_info` database option to reduce memory footprint on frontend requests.
- **Persistence Hardening:** Implemented a short-circuit flag in `Img_Converter` to prevent redundant database writes during the request shutdown.
- **Documentation Consolidation:** Merged duplicate optimization guidelines in `.jules/bolt.md` into a single canonical entry.

## [1.5.0] - 2026-04-20

### Added

- **Performance Monitor:** Transitioned to a high-precision local telemetry engine using raw cURL for granular network diagnostics (DNS, Connect, SSL, TTFB).
- **System Info Dashboard:** Real-time environment diagnostic tool providing detailed PHP, Database, WordPress, and Server metrics for troubleshooting.
- **Developer Mode:** Advanced UI toggle in Performance Audit for granular network timings and environment info.

### Changed

- **Modernized UI Aesthetics:** Implemented dynamic WordPress admin theme color adaptation using CSS `color-mix()` with static fallbacks for older browsers.
- **SSRF Hardening:** Enhanced local telemetry security with strict URL validation and host verification.

## [1.4.0] - 2026-04-18

### Added

- **Enterprise Redis Support:** Implemented high-availability Redis Object Cache with support for Sentinel and Cluster modes, including TLS/SSL encryption.
- **Batched Processing:** Migrated database cleanup and cron tasks to atomic, batched processing to prevent memory exhaustion and timeout issues on large sites.
- **Design System v2.1:** Introduced new modular UI components (`FeatureCard`, `FeatureHeader`, `SwitchField`) and a refactored Sass-based styling architecture for better performance and maintainability.
- **Status Reporting:** Added real-time status reporting to the dashboard for clearer visibility into background optimization tasks.

### Changed

- **Hardening & Stabilization:** Significant refactoring of HTML processing and image optimization logic to improve reliability and security.
- **AJAX Nonce Resilience:** Migrated security token management to an AJAX-based system, resolving issues with stale or expired nonces in long-running admin sessions.
- **REST API Security:** Hardened REST API permission callbacks and input sanitization across all endpoints.

### Fixed

- **Decompression Bomb Protection:** Implemented image dimension and file size validation in image conversion routines to prevent resource exhaustion attacks.
- **UI Consistency:** Resolved several CSS layout conflicts and improved theme adaptation for dashboard components.
- **Database Reliability:** Fixed issues with uninitialized variables in REST cleanup controllers and improved error handling in revisions management.

## [1.3.0] - 2026-04-15

### Added

- **Core Tweaks:** A new section under File Optimization to remove WordPress bloat (Emojis, Embeds, Dashicons, XML-RPC) and control Heartbeat API limits.
- **Database Automation:** Active WP-Cron scheduling for Database cleanups (Daily, Weekly, Monthly) with granular controls for minimum post revisions and maximum revision age.
- **Advanced Preloading:** Injected `fetchpriority` support to preload links indicating critical assets.
- **Web Font Optimization:** Automatically injects `font-display: swap` into CSS payloads to eliminate render-blocking text.
- **Lazy Loading Enhancements:** Implemented an active `MutationObserver` to track and lazy-load dynamically generated DOM content and added improved `<picture>` element support.
- **Htaccess Hardening:** Extended server-level cache expiration and Deflate/Gzip compression rules for various asset MIME types.
- **Video Lazy Loading:** Added logic to exclude specific self-hosted videos from lazy-loading routines.

### Changed

- **Admin UI Theme Consistency:** Substantially refined UI aesthetics. Replaced hardcoded variables with native WP CSS colors and added missing option descriptions across all toggles.
- **Conditional Minification Processes:** Added smart logic toggles that conditionally skip the heavy HTML, CSS, and JS minification runs if those settings aren't enabled.
- **Image Observer Stability:** Refactored intersection observer fallbacks for non-image `iframe` lazy loading blocks.

### Fixed

- **WPCS Compliance:** Mitigated false-positive SQL placeholder warnings (`WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare`) in database garbage collection routines.

## [1.2.3] - 2026-04-14

- **Stability:** Fixed a fatal error where `Advanced_Cache_Handler` was not found during activation/admin notices.
- **Performance:** Refactored `Advanced_Cache_Handler` to use lazy loading ("require when needed") to reduce memory footprint.
- **Documentation:** Shortened the plugin's short description in `readme.txt` to comply with the WordPress.org 150-character limit.

## [1.2.1] - 2026-04-14

### Added

- `Admin_Notices` class: post-activation welcome notice; dismissible activation notices for `wp-config.php` / `WP_CACHE` issues; notice when another full-page cache plugin is active; transients `wppo_activation_notices` and `wppo_show_welcome_notice`.
- Stronger WooCommerce asset-removal warning in the File Optimization UI (`removeWooCSSJSWarning`).

### Changed

- Plugin header `Requires at least` aligned with `readme.txt` (**WordPress 6.2**).
- `readme.txt`: expanded short description, FAQ (WooCommerce, competing caches, Core Web Vitals), Screenshots section, changelog entry for 1.2.1.
- `readme.md`: WordPress badge and requirements updated to 6.2+, safe-defaults note, NPM snippet trimmed to reference current `package.json`.

### Fixed

- **Asset Manager:** Implemented handle whitelisting in Metabox to prevent unauthorized or stale script/style handle persistence.
- **WP-config Path Resolution:** Enhanced path resolution to support parent directory locations (mirroring WordPress core behavior).
- **Activation Logic:** Fixed activation logic to properly handle transient deletion when no notices are present and ensuring `WP_CACHE` constant guards only apply when necessary.
- **Admin Notices:** Fixed alignment and escaping in `Admin_Notices` for WPCS compliance.
- **`WP_CACHE` / `wp-config.php`:** Activation now adds the guarded `WP_CACHE` block when the constant was **undefined** (previous logic only ran in a narrow case). Clearer handling when `WP_CACHE` is false, the file is not writable, or write fails (reported via admin notices).

### Security / safety

- **`advanced-cache.php`:** Drop-in includes a `WPPO_ADVANCED_CACHE_DROPIN` marker; the plugin does **not** overwrite or delete another plugin’s drop-in. Legacy drop-ins without the marker are still recognized. `Advanced_Cache_Handler::create()` skips installation if a foreign drop-in is present.

## [1.2.0] - 2026-04-13

### Added

- Server-side `.htaccess` automation — Gzip/Deflate compression and browser caching rules via `insert_with_markers()`.
- New `Htaccess_Handler` class for safe rule insertion with automatic rollback.
- CDN URL rewriting for `src`, `href`, and `srcset` attributes with configurable CNAME.
- Smart cache purging — granular invalidation of post, front page, and archive caches on content updates.
- WordPress Admin Color Scheme sync — UI adapts to all 9 admin color schemes via `var(--wp-admin-theme-color)` CSS variable cascade.
- Frontend theme color extraction from `theme.json` (block themes) and Customizer (classic themes) for accent syncing.
- Reusable `ConfirmDialog` component with focus trap, Escape key dismiss, `aria-modal`, body scroll lock, and danger/warning variants.
- Confirmation dialogs for destructive actions: database cleanup (individual + "Clean All" with breakdown), image removal, and settings import.
- Contextual warning notices for Defer JS, Delay JS, and Server-Side Rules settings.
- Info notices for Lazy Load and Image Conversion settings with best-practice guidance.
- Danger button variant and inline notice components (info, warning, success).
- Reusable `LoadingSubmitButton` and `CheckboxOption` components.
- Visual loading spinners on all action buttons.
- JavaScript test suite for `apiRequest.js` API client.
- PHPCS configuration (`phpcs.xml`) and Psalm/WPCS GitHub Actions CI workflow.
- Browserslist configuration (`.browserslistrc`) for CSS/JS target compatibility.

### Changed

- Replaced external Google Fonts `@import` with WordPress system font stack (zero network requests).
- All `hsla()` shadow values replaced with `rgba(var(--wppo-primary-rgb), ...)` for dynamic theme adaptation.
- Enhanced form controls — fixed heights (44–46px), hover states, `box-sizing: border-box`, custom number inputs (hidden spinners), custom select dropdowns (SVG chevron), disabled state styling.
- Focus-visible states and ARIA attributes across all interactive elements.
- Dynamic ARIA labels with full i18n translation support.
- Centralized notification and import field styles into SCSS (removed inline styles).
- Modularized dashboard styling and sidebar state management.
- Cached expensive filesystem operations (cache size, file counts) using transients.
- Batched image conversion queue database writes — eliminates N+1 query overhead.
- Atomic array merging for deferred image queue writes.
- Restricted `lazyload.js` enqueuing and disabled autoload for image info option.
- Replaced `include_once` with `require_once` and defined `WPPO_TRANSIENT_PREFIX` constant.
- Refactored `schedule_page_cron_jobs` method for clarity.
- WordPress Coding Standards applied to `class-asset-manager.php` and `class-main.php`.
- Shared POST helper refactored for REST API calls.
- Tested up to WordPress 6.9.

### Security

- Fixed path traversal vulnerability in cache implementation (two separate fixes with `realpath()` validation).
- Fixed path traversal guard and filesystem abstraction in `class-cache.php`.
- Improved htaccess safety with expanded cache expiration rules.
- Refined CDN regex for unquoted HTML attributes to prevent injection.

### Fixed

- Frontend static analysis lint errors.
- FileReader failure paths and import UX edge cases.
- REST API method signatures and PHP version compatibility.
- `fetchRecentActivities` pagination parameter.

## [1.1.4] - 2026-04-08

### Security

- Fixed path traversal vulnerability in the Image Optimization REST endpoint by rejecting image paths containing `..` sequences.
- Added directory traversal protection in `Util::get_file_path()` to return an empty string for unsafe paths.

### Performance

- Optimized image queue database writes by caching `wppo_img_info` in memory and flushing to the database only once on shutdown, reducing per-request DB writes during bulk image conversion.

### Changed

- Refactored REST API image info handling to use the new `Img_Converter::get_img_info()` / `set_img_info()` static cache methods consistently across `class-rest.php` and `class-cron.php`.
- Completed image info cache reset now only occurs after the `wppo` directory is successfully deleted.

### Fixed

- Updated `CheckboxOption` component to use unique IDs (via `useId`) for proper label/input association and added `aria-describedby` on inputs and `aria-label` on textareas for improved accessibility.

## [1.1.3] - 2026-04-07

### Fixed

- Anchored exclusion patterns in `.distignore` and `build-release.sh` to prevent accidental vendor file exclusion.

## [1.1.2] - 2026-04-07

### Added

- No new major features; this is a maintenance and compatibility release.

### Changed

- Use `@wordpress/element` for React rendering compatibility in WordPress.

### Fixed

- Cache the Img_Converter instance to reduce PHP overhead during image conversion.
- Validate and sanitize imported REST API settings before saving.
- Improve sidebar accessibility and keyboard navigation in the admin UI.

## [1.1.1] - 2026-04-06

### Added

- Specialized JS exclusion properties for finer control over defer/delay behavior.
- Translated ARIA labels for the sidebar toggle for improved accessibility.

### Changed

- Optimized JS Defer and Delay loading by caching exclusion lists at setup, significantly reducing per-request overhead.
- Enhanced backend performance by moving string parsing out of the script tag processing loop.

### Fixed

- Hardened plugin security by implementing protection against directory traversal in cache management.
- Normalized REST API key sanitization to preserve camelCase keys, fixing a critical synchronization bug between the UI and database.

## [1.1.0] - 2026-04-05

### Added

- New "Database Cleanup" toolset to remove revisions, spam comments, and auto-drafts.
- "Asset Manager" to monitor and capture enqueued scripts and styles per page.

### Changed

- Major UI overhaul of the "File Optimization" settings for a more intuitive experience.
- Improved image lazy loading reliability with smoother SVG placeholder transitions.

### Fixed

- Automatically clear all cache when changing permalinks or switching themes.
- Prevented redundant CSS generation on 404 pages.

## [1.0.0] - 2024-12-18

- Initial release with core features:
- Dashboard with cache and optimization status.
- JS/CSS/HTML minification and combination.
- Modern image conversion (WebP/AVIF).
- Advanced preloading and lazy loading rules.
- Settings Import/Export.
