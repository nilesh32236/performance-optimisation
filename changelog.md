# Changelog

All notable changes to the Performance Optimisation plugin will be documented in this file.

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
