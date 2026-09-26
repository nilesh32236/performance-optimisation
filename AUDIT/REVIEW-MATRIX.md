# REVIEW-MATRIX.md — Agent assignments (deterministic, every file ≥1 agent)

_Gap rule: CODE-INVENTORY ↔ REVIEW-MATRIX ↔ AGENTS/ ↔ FINDINGS/ must 1:1. No file skipped because small. 5-line file = same discipline._

## Army roster (12 specialized agents, parallel)

| # | Agent ID | Specialty | Scope (files) | Primary output |
|---|----------|-----------|---------------|----------------|
| A01 | `php-correctness` | PHP correctness, WP hooks, edge cases | `includes/class-main.php`, `class-cache.php`, `class-advanced-cache-handler.php`, `class-htaccess-handler.php`, `class-server-rules.php`, `performance-optimisation.php`, `uninstall.php` | `AUDIT/AGENTS/agent-A01-php-correctness.md` |
| A02 | `php-media` | Media / CSS / image pipeline | `includes/class-image-optimisation.php`, `class-img-converter.php`, `class-critical-css.php`, `class-used-css.php`, `class-google-fonts.php`, `class-asset-manager.php`, `class-abilities.php` | `AUDIT/AGENTS/agent-A02-php-media.md` |
| A03 | `php-infra` | Infra: DB, cache, cron, object-cache, telemetry, system-info, log, CDN, RUM, PageSpeed, suggestion | `includes/class-database-cleanup.php`, `class-cron.php`, `class-object-cache.php`, `templates/object-cache.php`, `includes/redis-connect-helper.php`, `class-telemetry.php`, `class-system-info.php`, `class-log.php`, `class-cdn-purger.php`, `class-rum.php`, `class-pagespeed.php`, `class-suggestion-engine.php`, `class-litespeed-integration.php`, `class-llms.php` | `AUDIT/AGENTS/agent-A03-php-infra.md` |
| A04 | `php-rest-cli-metabox` | REST + CLI + metabox + activate/deactivate + util + core-tweaks + admin-notices | `includes/class-rest.php`, `class-wppo-cli-command.php`, `class-metabox.php`, `class-activate.php`, `class-deactivate.php`, `class-util.php`, `class-core-tweaks.php`, `class-admin-notices.php` | `AUDIT/AGENTS/agent-A04-php-rest-cli.md` |
| A05 | `js-spa` | React SPA + state + API | `src/App.js`, `src/index.js`, `src/lib/*.js`, `src/components/*.js` (Dashboard, FileOptimization, Preload, Image, Database, ObjectCache, PluginSetting, PageSpeedPanel, PerformanceAudit, etc.), `src/components/common/*.js` | `AUDIT/AGENTS/agent-A05-js-spa.md` |
| A06 | `js-vanilla-a11y` | Vanilla JS loaders + a11y + RUM beacons | `src/lazyload.js`, `src/main.js`, `src/rum.js`, `src/setupTests.js`, `src/__tests__/lazyload.test.js` + `main.test.js` | `AUDIT/AGENTS/agent-A06-js-vanilla.md` |
| A07 | `css-designsystem` | SCSS design system + responsive + RTL | `src/css/**/*.scss` (abstracts, base, components, layout), `src/css/style.scss`, `build/style-index*.css` | `AUDIT/AGENTS/agent-A07-css.md` |
| A08 | `security` | AuthN/Z, nonces, caps, sanitization, SQLi, XSS, CSRF, file ops, SSRF, RUM token | All PHP: `class-rest::permission_callback`, `class-cache`, `class-database-cleanup`, `class-img-converter`, `class-rum`, `class-llms`, `class-util` | `AUDIT/AGENTS/agent-A08-security.md` |
| A09 | `performance` | Runtime, WP, DB, frontend, caching, assets (largest priority for perf plugin) | All PHP hot paths: `class-cache::process_buffer_for_cache`, `class-image-optimisation::process_*`, `class-cron::*`, `class-database-cleanup::*`, `class-main::enqueue_scripts`, JS `lazyload`/`App` | `AUDIT/AGENTS/agent-A09-performance.md` |
| A10 | `duplication-deadcode` | Duplicates, dead code, unused symbols | All `includes/*.php` + `src/**/*.js` + `src/css/**/*.scss` (regex + manual trace) | `AUDIT/AGENTS/agent-A10-duplication-deadcode.md` |
| A11 | `compatibility` | WP/PHP/multisite/object-cache/hosting (Apache/Nginx/OLS/LiteSpeed), wp.org compliance | `phpcs.xml`, `composer.json` `php>=8.2`, `readme.txt`, `performance-optimisation.php` headers, `Util::transient_key`, `object-cache` drop-in | `AUDIT/AGENTS/agent-A11-compatibility.md` |
| A12 | `quality-architecture` | Architecture, coupling, god classes, maintainability, error handling, naming, testing | All `includes/*.php` + `src/**` + `tests/php/**` + `package.json` Jest config | `AUDIT/AGENTS/agent-A12-quality-architecture.md` |

## Coverage map (every source file → agent)

- PHP 37 runtime files → A01-A04 + A08-A12 (each file in exactly one primary A01-A04, plus cross-cut A08-A12)
- JS 143 src files → A05 (SPA) + A06 (vanilla) + cross-cut A08-A12
- SCSS 20 → A07 + cross-cut
- Config/build/tests/docs → A11/A12

## Status tracking (updated by orchestrator after agents write)

| Agent | Files | Lines | Reviewed | Findings | Severity max | Report exists |
|-------|-------|-------|----------|----------|--------------|---------------|
| A01 | 8 | ~5.8k | ☐ | — | — | ☐ |
| A02 | 7 | ~7.3k | ☐ | — | — | ☐ |
| A03 | 14 | ~9.8k | ☐ | — | — | ☐ |
| A04 | 8 | ~6.2k | ☐ | — | — | ☐ |
| A05 | ~60 | ~18k | ☐ | — | — | ☐ |
| A06 | 5 | ~2k | ☐ | — | — | ☐ |
| A07 | 20 | ~3.4k | ☐ | — | — | ☐ |
| A08 | all | 57k | ☐ | — | — | ☐ |
| A09 | all | 57k | ☐ | — | — | ☐ |
| A10 | all | 57k | ☐ | — | — | ☐ |
| A11 | — | — | ☐ | — | — | ☐ |
| A12 | — | — | ☐ | — | — | ☐ |

> After agents finish, orchestrator reconciles this table against `CODE-INVENTORY.md` and `AUDIT/AGENTS/*.md` existence + `wc -l` before declaring gap-closed.
