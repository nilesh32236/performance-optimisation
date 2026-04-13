# Changelog

All notable changes to the Performance Optimisation plugin are documented in this file.

## [1.2.0] — 2026-04-13

### Cache Core Completion

- **Server-Side Rules (.htaccess Automation):** New `Htaccess_Handler` class generates Gzip/Deflate compression and browser caching rules using `insert_with_markers()`, placed safely after the WordPress block to avoid permalink overwrites.
- **CDN URL Rewriting:** Rewrites `src`, `href`, and `srcset` attributes from the local domain to a user-configured CNAME. Regex supports both quoted and unquoted HTML attribute values.
- **Smart Cache Purging:** Granular invalidation on content updates — purges the specific post cache, the front page, and associated category/archive caches automatically.

### Design System v2.0

- **Admin Color Scheme Sync:** All UI colors resolve through `var(--wp-admin-theme-color, ...)` with plugin defaults as fallback. The interface automatically adapts to all 9 WordPress admin color schemes.
- **Frontend Theme Color Extraction:** New `get_frontend_theme_colors()` method reads primary/secondary/text colors from `theme.json` (block themes) or `get_theme_mod()` (classic themes), injected into the React frontend as CSS custom properties for accent syncing.
- **Confirmation Dialogs:** Reusable `ConfirmDialog` component with focus trap, Escape key dismiss, `aria-modal`, body scroll lock, and danger/warning variants. Applied to:
  - Database Cleanup — individual items and "Clean All" (with quantified category breakdown list)
  - Dashboard — "Remove Optimized Images"
  - Plugin Settings — "Import Settings"
- **Contextual Notices:** Inline warning notices (yellow) appear when enabling Defer JS, Delay JS, and Server-Side Rules. Info notices (blue) appear for Lazy Load and Image Conversion with best-practice guidance.
- **Danger Button Variant:** Red button style for destructive actions.
- **System Font Stack:** Removed render-blocking `@import url('https://fonts.googleapis.com/...')` in favor of the native WordPress system font stack (`-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, ...`).
- **Dynamic Shadows:** All `hsla(230,...)` box-shadow values replaced with `rgba(var(--wppo-primary-rgb), ...)` for automatic theme color adaptation.
- **Polished Form Controls:** Fixed input heights (44–46px), hover states, `box-sizing: border-box`, `font-family: inherit`, custom number input (hidden spinners), custom select dropdown (SVG chevron arrow), disabled state styling, and uppercase field labels.

### UI/UX & Accessibility

- **Reusable Components:** New `LoadingSubmitButton` (with spinner states) and `CheckboxOption` (with toggle, description, nested content, and textarea) components for consistent UI patterns.
- **Visual Loading Spinners:** All action buttons display a spinner during async operations.
- **Focus-Visible States:** `:focus-visible` outlines on all interactive elements (buttons, links, inputs, selects) with `4px` primary-soft ring.
- **Dynamic ARIA Labels:** Sidebar navigation and form controls have translated `aria-label` attributes for screen reader support.
- **Style Consolidation:** Notification and import field styles migrated from inline styles to SCSS. Dashboard styling and sidebar state management modularized.

### Performance

- **Transient Caching:** Expensive filesystem operations (cache size calculation, file counts) cached using WordPress transients.
- **Batched Database Writes:** Image conversion queue writes batched in memory, flushed once on `shutdown` hook — eliminates N+1 query overhead.
- **Atomic Array Merging:** Deferred image queue uses atomic array merge instead of individual option updates.
- **Lazy Script Enqueuing:** `lazyload.js` only enqueued when lazy loading is enabled. Image info option has `autoload` disabled.
- **Code Cleanup:** Replaced `include_once` with `require_once`. Defined `WPPO_TRANSIENT_PREFIX` constant. Refactored `schedule_page_cron_jobs` for clarity.

### Security

- **Path Traversal (Cache):** Fixed path traversal vulnerability in cache implementation with proper `realpath()` validation and filesystem abstraction (two separate fixes across multiple attack vectors).
- **Path Traversal (Images):** Fixed directory traversal in URL-to-path resolution for image optimisation endpoints.
- **.htaccess Safety:** Improved rule insertion with expanded cache expiration rules and validation.
- **CDN Regex Hardening:** Refined regex for unquoted HTML attributes to prevent potential injection through malformed markup.

### Code Quality & Testing

- **JavaScript Tests:** Initial test suite for `apiRequest.js` API client with edge case coverage.
- **PHPCS Configuration:** Added `phpcs.xml` with WordPress Coding Standards rules.
- **CI Pipeline:** GitHub Actions workflow for Psalm static analysis and WPCS linting with report artifacts.
- **Browserslist:** Added `.browserslistrc` for CSS/JS compilation target consistency.
- **Standards Compliance:** WordPress Coding Standards applied to `class-asset-manager.php` and `class-main.php`. Shared POST helper refactored. REST API method signatures corrected.
- **Bug Fixes:** FileReader failure paths in import UX, `fetchRecentActivities` pagination, and frontend lint errors resolved.
- **Compatibility:** Tested up to WordPress 6.9.

---

## [1.1.4] — 2026-04-08

- Security: Fixed path traversal vulnerability in the Image Optimisation REST endpoint.
- Security: Added directory traversal protection in URL-to-path resolution.
- Performance: Optimized image queue database writes by caching in memory and flushing once on shutdown.
- Fix: Updated CheckboxOption component to use unique IDs for proper accessibility.

## [1.1.3] — 2026-04-07

- Fix: Anchored build paths in `.distignore` to prevent accidental exclusion of vendor files.

## [1.1.2] — 2026-04-07

- Fix: Cache the `Img_Converter` instance to reduce PHP overhead during image conversion.
- Fix: Validate and sanitize imported REST API settings before saving.
- Fix: Improve sidebar accessibility and keyboard navigation in the admin UI.
- Update: Use `@wordpress/element` for React rendering compatibility in WordPress.

## [1.1.1] — 2026-04-06

- Improvement: Optimized JS Defer and Delay loading by caching exclusion lists.
- Improvement: Enhanced backend performance by reducing redundant string parsing.
- Security: Implemented protection against potential directory traversal vulnerabilities.
- Fix: Standardized REST API key sanitization to prevent settings synchronization issues.
- Localization: Added translated ARIA labels for sidebar accessibility.

## [1.1.0] — 2026-04-05

- Improvement: Visually enhanced the 'File Optimization' settings for easier configuration.
- Improvement: Hardened overall plugin security and input validation.
- Fix: Automatically clear cache when changing permalink settings or switching themes.
- Fix: Prevented unnecessary CSS files from generating on 404 error pages.
- Update: Improved image lazy loading reliability for smoother page rendering.

## [1.0.0] — 2024-12-18

Initial release with full functionality:
- Dashboard overview with cache, JS/CSS, and image optimisation stats.
- Cache management tools.
- JavaScript, CSS, and HTML optimization (minify, combine, defer, delay).
- Advanced image optimisation with WebP/AVIF conversion and lazy loading.
- Preloading settings for cache, fonts, DNS, and images.
- Import/export settings tools.
