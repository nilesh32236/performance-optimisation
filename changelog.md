# Changelog

All notable changes to the Performance Optimisation plugin will be documented in this file.

## [1.2.1] - 2026-04-14

### Added

- `Admin_Notices` class: post-activation welcome notice; dismissible activation notices for `wp-config.php` / `WP_CACHE` issues; notice when another full-page cache plugin is active; transients `wppo_activation_notices` and `wppo_show_welcome_notice`.
- Stronger WooCommerce asset-removal warning in the File Optimization UI (`removeWooCSSJSWarning`).

### Changed

- Plugin header `Requires at least` aligned with `readme.txt` (**WordPress 6.2**).
- `readme.txt`: expanded short description, FAQ (WooCommerce, competing caches, Core Web Vitals), Screenshots section, changelog entry for 1.2.1.
- `readme.md`: WordPress badge and requirements updated to 6.2+, safe-defaults note, NPM snippet trimmed to reference current `package.json`.

### Fixed

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
