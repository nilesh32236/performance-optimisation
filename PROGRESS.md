# PROGRESS.md — Audit & Repair Progress Log

## Phase 1 — Diagnostics (2026-08-10)
- `vendor/bin/phpunit`: 167 passed, 6 PHPUnit deprecations (doc-comment metadata).
- `vendor/bin/phpcs`: clean.
- `npm test -- --watchAll=false`: 255 passed / 23 suites.
- `npm run lint:js`: clean.
- `npm run build`: success.
- Psalm: CI-only (Docker). Not installed locally.
- Baseline captured in `AUDIT_PLAN.md`.

## Fix Log
(entries appended as fixes complete)

### AUDIT-0002 + AUDIT-0003 (commit: pending)
- Removed duplicate `/**` doc-block openings in `includes/class-main.php` and
  `includes/class-system-info.php`.
- Converted `@dataProvider` doc-comments to `#[DataProvider]` attributes in `tests/php/CacheTest.php`
  and removed redundant `@before` annotations in four Util test files.
- Files modified: `includes/class-main.php`, `includes/class-system-info.php`, `tests/php/CacheTest.php`,
  `tests/php/UtilCachedContentUrlTest.php`, `tests/php/UtilGetLocalPathTest.php`,
  `tests/php/UtilIsUrlExcludedTest.php`, `tests/php/UtilMinCacheDirTest.php`.
- Verification: `phpunit` OK (167 tests, 377 assertions, 0 deprecations); `phpcs` clean.

### AUDIT-0004 + AUDIT-0005 + AUDIT-0006 + AUDIT-0009 (commit: pending)
- Hardened `Util::is_url_excluded()` in `includes/class-util.php`:
  - Scheme normalization: `http://` / `https://` rules now match interchangeably.
  - Empty and whitespace-only rules are skipped instead of matching the homepage.
  - Home base resolved once per request via a blog-id-keyed static cache (perf); re-resolved only
    when a `home_url` filter is active. Multisite-safe.
- Resolved `@since NEXT` → `@since 2.16.0` in `includes/class-metabox.php` (3 helpers).
- Added 7 tests to `tests/php/UtilIsUrlExcludedTest.php` (scheme mismatch, empty/whitespace rules,
  root-relative wildcard prefix matching + sibling non-match).
- Files modified: `includes/class-util.php`, `includes/class-metabox.php`,
  `tests/php/UtilIsUrlExcludedTest.php`.
- Verification: `phpunit` OK (174 tests, 384 assertions); `phpcs` clean.

### AUDIT-0010a — Log class tests (commit: pending)
- Added `tests/php/LogTest.php` (6 tests): insert + salt bump, failed-insert no-op, paginated
  DB path, cached path, bounds clamping, versioned fallback cache path.
- Added `ARRAY_A`/`ARRAY_N`/`OBJECT` constants to `tests/php/bootstrap.php`.
- Files modified: `tests/php/LogTest.php` (new), `tests/php/bootstrap.php`.
- Verification: `phpunit` OK (180 tests, 400 assertions); `phpcs` clean.

### AUDIT-0010b + AUDIT-0010c + AUDIT-0010d — PHP class tests (commit: pending)
- Added `tests/php/AdvancedCacheHandlerTest.php` (11 tests): drop-in path, marker/legacy/foreign
  detection, create/remove behavior.
- Added `tests/php/ServerRulesTest.php` (9 tests): server-type detection, Nginx gzip/browser-
  caching rules + filter, Apache proxy to Htaccess_Handler.
- Added `tests/php/SystemInfoTest.php` (14 tests): all info groups, PHP/database/WordPress/server/
  cache/infrastructure/opcache details, request microtime, WooCommerce presets.
- Added `FS_CHMOD_FILE`/`FS_CHMOD_DIR` constants and pre-registered `__`/`esc_html__`/`esc_html`/
  `esc_url_raw` stubs in `tests/php/bootstrap.php`.
- Files modified: `tests/php/AdvancedCacheHandlerTest.php`, `tests/php/ServerRulesTest.php`,
  `tests/php/SystemInfoTest.php` (all new), `tests/php/bootstrap.php`.
- Verification: `phpunit` OK (214 tests, 464 assertions); `phpcs` clean.

### AUDIT-0011a–e — JS component/lib tests (commits: f7507e50, pending)
- Added common-component tests: `StatusBadge`, `MetricCard`, `FeatureCard` (+ FeatureHeader),
  and `lib/util` (`handleChange`).
- Added `CriticalCssPanel` tests (6), `PluginSetting` tests (8), `Dashboard` tests (6).
- Files modified: `src/components/common/__tests__/StatusBadge.test.js`, `MetricCard.test.js`,
  `FeatureCard.test.js`, `src/lib/__tests__/util.test.js`,
  `src/components/__tests__/CriticalCssPanel.test.js`, `PluginSetting.test.js`,
  `Dashboard.test.js`.
- Verification: `npm test` OK (296 tests / 30 suites); `eslint` clean.

