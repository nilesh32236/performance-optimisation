# Changelog

All notable changes to the Performance Optimisation plugin will be documented in this file.

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
