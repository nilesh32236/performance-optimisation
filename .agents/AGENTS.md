# Performance Optimisation Plugin — Workspace Rules for AI Agents

This directory contains AI agent configuration and skills for autonomously maintaining, reviewing, and improving the Performance Optimisation WordPress plugin.

## Project Overview

A WordPress performance optimization plugin (PHP 8.2+, WP 6.2+) providing:
- Static HTML cache with `advanced-cache.php` drop-in
- Redis Object Cache (standalone/sentinel/cluster)
- JS/CSS/HTML minification
- Image conversion (WebP/AVIF)
- Lazy loading (images, iframes, videos)
- Database cleanup (7 types)
- Google PageSpeed Insights integration
- Performance telemetry and suggestions
- CDN support, .htaccess rules, Nginx config

## Verification Commands (Required Order)

```bash
npm run lint:js        # ESLint via @wordpress/scripts
composer lint           # PHPCS (WordPress standard)
npm test                # Jest unit tests (jsdom)
npm run build           # wp-scripts build
```

## Key Files

| File | Purpose |
|------|---------|
| `performance-optimisation.php` | Plugin entry, version constant |
| `composer.json` | PHP deps + lint/release scripts |
| `package.json` | JS deps + build/test commands |
| `.distignore` | WordPress.org SVN exclusion rules |
| `scripts/build-release.sh` | Builds release ZIP |
| `includes/class-main.php` | Main orchestrator class |
| `includes/class-rest.php` | 16 REST API endpoints |

## Vendor Directory

`vendor/` is **tracked in git**. Before committing, ALWAYS run:
```bash
composer release    # strips dev deps, optimizes autoloader
git add vendor/     # include production-only vendor
```

## Build Output

`build/` output (JS/CSS from `npm run build`) is **committed to git**. Always stage it:
```bash
npm run build
git add build/
```

## File Conventions

- **PHP**: WordPress Coding Standards (`phpcs.xml`), no PSR-4 autoload for plugin classes (manual `require_once`)
- **JS**: ES modules via `@wordpress/scripts`, no routing/state libraries (pure `useState`)
- **SCSS**: `.wppo-` prefix, BEM-like naming, CSS custom properties
- **React**: All settings via `wppoSettings` global, `apiCall()` for REST, no external state managers

## Workflow Permissions

Agents in this repository have:
- `contents: write` — for creating branches, committing, pushing, creating releases
- `pull-requests: write` — for reviewing, approving, merging PRs
- `issues: write` — for creating and managing issues
- `actions: read` — for checking workflow status

**Merge gate**: NEVER merge unless ALL verification commands pass AND AI confidence score >= 95%.

## Autonomous Operations

### Daily Audit
- Run full verification suite
- Run AI codebase audit (all PHP, JS, SCSS files)
- Create GitHub issues for problems found
- Review open PRs and merge if confidence >= 95% and all checks pass

### Tri-Weekly Merge & Release (every 3 days)
- Resolve merge conflicts on open PRs
- Merge all ready PRs
- Bump version and create release tag

### WordPress Feature Monitor (weekly)
- Search WordPress developer news for new APIs/hooks
- Check plugin code for update opportunities
- Create improvement PRs with backward-compatible fallbacks
