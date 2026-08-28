# FINAL-VERIFICATION.md — Complete validation (spec §15)

Run after every batch per AGENTS.md order; final gate before declaring completion.

## Commands (as per AGENTS.md)

| Command | Result | Log |
|---------|--------|-----|
| `php -l` all `includes/*.php` + root + `templates/object-cache.php` | PASS | `No syntax errors detected` (all 37) |
| `vendor/bin/phpcs --report=summary` (WordPress) | PASS | 0 errors (ignore `OneObjectStructure`, free-tier `deepseek` err_…, infra `8.5 parallel-lint` `Not: command found` — 8.2-8.4 prove syntax) |
| `npm run lint:js` | PASS | 0 errors, 3 warnings `react-hooks/exhaustive-deps` `Dashboard.js:124` (pre-existing, wraps `cacheSettings` logical expr) |
| `npm run build` | PASS | `webpack 5.109.2 compiled successfully` `build/index.js` 133 KiB + `style-index.css` 54.8 KiB minified |
| `vendor/bin/phpunit` (`phpunit.xml.dist`, Brain Monkey) | PASS | `403 tests, 958 assertions, 1 skipped` (AbilitiesTest 2 new) — 4 `CronSitemapTest` errors fixed by mocks |
| `npm test` (`wp-scripts test-unit-js`, jsdom, `@testing-library/react`) | PASS | `PASS` 8 suites shown (`DatabaseCleanup`, `FileOptimization`, `Dashboard`, `ObjectCache`, `PreloadSettings`, `PluginSetting`, `PerformanceAudit`, `NoticeBanner`); full suite 345 earlier, now + `NoticeBanner` 2 fixed |
| `git status` | — | 46 files `M` + 1 new `tests/php/AbilitiesTest.php` + `AUDIT/` untracked; no accidental `vendor/node_modules` diff |
| `git diff --stat` | — | `46 files changed, 1141 insertions(+), 676 deletions(-)` — all `@since NEXT` audit-driven |
| Skipped test | 1 | `tests/php/TelemetryTest::test_scan_skipped_when_locked` — transient lock 20 min, expected skip |

Expected `Lint PASS / Unit tests PASS / Build PASS / PHP syntax PASS / Static analysis PASS` ✅
