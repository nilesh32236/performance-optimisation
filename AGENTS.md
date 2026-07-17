# AGENTS.md — Performance Optimisation (WordPress Plugin)

**If you add or change anything that affects how an agent works in this repo, update this file.**

---

## Quick start

```sh
composer dev-setup           # install PHP deps (--ignore-platform-reqs)
npm install                  # install JS deps
npm run build                # wp-scripts build src/index.js src/lazyload.js
npm run start                # dev watch mode
```

## Commands

| Command | What |
|---------|------|
| `npm test` | Jest unit tests (config in `package.json`, not `jest.config.js`) |
| `npm run lint:js` | ESLint via `@wordpress/scripts` |
| `npm run lint:js:fix` | Auto-fix JS lint |
| `composer lint` | PHPCS (WordPress standard, config in `phpcs.xml`) |
| `composer lint:fix` | PHPCBF auto-fix |
| `composer makepot` | Generate `.pot` translation file (PHP + JS `@wordpress/i18n` strings) |
| `composer release` | `composer install --no-dev --optimize-autoloader` |

**Required order for full verification:** `npm run lint:js` → `composer lint` → `npm test` → `npm run build`

## Vendor directory

`vendor/` is **tracked in git** — do not gitignore it. Before pushing:

```sh
composer release    # strips dev dependencies, optimizes autoloader
git add vendor/     # include the production-only vendor in your commit
```

This ensures the plugin ships with only production dependencies (`voku/html-min`, `matthiasmullie/minify`, `woocommerce/action-scheduler`). If you need dev deps locally, run `composer dev-setup` after.

## Architecture

### Plugin entry
`performance-optimisation.php` → `includes/class-main.php` (orchestrator). Namespace `PerformanceOptimisation\Inc`. Classes are **manually loaded** via `Main::includes()` + `vendor/autoload.php` (Composer for vendor packages only, no PSR-4 autoload for plugin classes).

### React SPA
- Mounts at `<div id="performance-optimisation">` in WP admin
- Built from `src/index.js` + `src/lazyload.js` via `@wordpress/scripts`
- **No routing library** — tab switching via `useState` + conditional rendering
- **No state management library** — pure `useState` throughout
- **All settings pages** call `update_settings` API with `{tab: '...', settings: {...}}`
- Global `wppoSettings` object injected by PHP via `wp_localize_script` (includes `apiUrl`, `nonce`, `settings`, `translations`, `themeColors`, etc.)
- Each component reads its settings from `wppoSettings.settings[tabName]` on mount
- `apiCall('update_settings')` mutates `wppoSettings.settings` globally on success
- API calls use `src/lib/apiRequest.js` (centralized `apiCall()` function)

### Component tree

```
App.js
├── Dashboard.js (stats, audits, suggestions, pagespeed, system info, images)
├── FileOptimization.js (minify, defer, delay, CDN, server rules)
├── PreloadSettings.js (cache warm-up, preconnect, preload fonts/CSS)
├── ImageOptimization.js (lazy load, <picture>, WebP/AVIF, responsive limits)
├── DatabaseCleanup.js (7 types: revisions, drafts, trash, spam, transients, orphans)
├── ObjectCache.js (Redis standalone/sentinel/cluster, TLS, compression)
└── PluginSetting.js (activity log, PageSpeed API key, export/import)
```

Common components in `src/components/common/`: `Tooltip`, `SwitchField`, `CheckboxOption`, `ConfirmDialog`, `LoadingSubmitButton`, `FeatureCard`, `FeatureHeader`, `StatusBadge`, `MetricCard`.

Frontend lazy loading: `src/lazyload.js` (vanilla JS, not React) — IntersectionObserver + MutationObserver for images/iframes/videos, deferred script loading on user interaction.

Admin bar cache clearing: `src/main.js` — two buttons ("Clear All Cache", "Clear This Page") with automatic nonce refresh on 403.

### REST API
Namespace `performance-optimisation/v1`, defined in `includes/class-rest.php` (16 endpoints). All require `manage_options` capability + `X-WP-Nonce`.

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `clear_cache` | POST | Clear all or single page cache |
| `update_settings` | POST | Update settings per tab |
| `optimise_image` | POST | Queue/sync image conversion |
| `delete_optimised_image` | POST | Delete wppo/ directory |
| `recent_activities` | GET | Paginated activity log |
| `import_settings` | POST | Import settings JSON |
| `database_cleanup` | POST | Run cleanup by type |
| `database_cleanup_counts` | GET | Counts per cleanup type |
| `get_page_assets` | GET | Captured frontend assets |
| `image_job_status` | GET | Image conversion job status |
| `object_cache` | POST | Redis status/enable/disable/flush/ping |
| `system_info` | GET | PHP/DB/WP/Server/Cache info |
| `performance_scan` | POST | Local telemetry scan |
| `pagespeed_scan` | POST | Queue Google PageSpeed scan |
| `pagespeed_results` | GET | PageSpeed results |
| `suggestions` | GET | Performance suggestions |
| `server_rules` | GET | Apache/Nginx rules text |

### PHP backend
25 files in `includes/`. Key classes:

| Class | Responsibility |
|-------|---------------|
| `class-main.php` | Hooks, admin page, enqueue, minification, preload, WooCommerce cleanup |
| `class-cache.php` | Static HTML cache (generate, invalidate, clear, CSS combine, CDN rewrite) |
| `class-object-cache.php` | Redis Object Cache (standalone/sentinel/cluster, enable/disable/flush/status) |
| `class-advanced-cache-handler.php` | `advanced-cache.php` drop-in (create/detect/remove) |
| `class-htaccess-handler.php` | `.htaccess` Gzip + Expires rules via `insert_with_markers()` |
| `class-server-rules.php` | Nginx rules (gzip, browser caching), server type detection |
| `class-database-cleanup.php` | 7 cleanup operations (batched, $wpdb queries) |
| `class-cron.php` | WP-Cron: preload (5h), image conversion (hourly), DB cleanup (daily) |
| `class-img-converter.php` | WebP/AVIF conversion (GD, Imagick), deferred option commits |
| `class-image-optimisation.php` | Next-gen serving, lazy load, picture wrap, preload, video lazy |
| `class-rest.php` | All 16 REST API endpoints |
| `class-pagespeed.php` | Google PageSpeed Insights API + Action Scheduler job |
| `class-suggestion-engine.php` | Performance suggestions from telemetry + PageSpeed |
| `class-telemetry.php` | Local cURL-based performance scanner |
| `class-system-info.php` | PHP/DB/WP/Server/Cache/Infrastructure info |
| `class-util.php` | Filesystem, URL processing, preload links, MIME types |
| `class-log.php` | Activity logging to `wppo_activity_logs` table |
| `class-metabox.php` | Per-page preload images + Asset Manager |
| `class-core-tweaks.php` | Disable emojis/embeds/dashicons/XML-RPC, Heartbeat control |
| `class-activate.php` / `class-deactivate.php` | Activation/deactivation hooks |

### Caching stack
1. **Static HTML cache** — `wp-content/cache/wppo/{domain}/{path}/index.html` (+ gzip variant). Generated via output buffer on `template_redirect`. Served via `advanced-cache.php` drop-in (skips WordPress entirely for cached pages).
2. **Redis Object Cache** — Custom `WP_Object_Cache` drop-in (`templates/object-cache.php` → `wp-content/object-cache.php`). Supports standalone, Sentinel, Cluster. Config stored in `wp-content/wppo-redis-config.php`.
3. **Cache clearing** triggered on: permalink change, theme switch, settings save, plugin activate/deactivate, `save_post` (smart purge: home, archive, taxonomies).

### Background jobs
- **Image conversion**: Action Scheduler (`wppo_convert_image_background`) + hourly cron (`wppo_img_conversation`)
- **PageSpeed scans**: Action Scheduler (`wppo_pagespeed_scan`)
- **Cache preload**: 5-hourly cron, processes 200 posts per batch, random delay 0-1800s per page
- **DB cleanup**: Daily/Weekly/Monthly based on settings

### Database
- **Custom table**: `{prefix}wppo_activity_logs` (id, activity, created_at) — created via `dbDelta()`
- **Option `wppo_settings`**: All plugin settings (serialized array)
- **Option `wppo_img_info`**: Image conversion status (non-autoloading, deferred shutdown commits)
- **Transients**: `wppo_cache_size` (15min), `wppo_total_js_css` (15min), audit results
- **Post meta**: `_wppo_preload_image_url`, `_wppo_disabled_scripts`, `_wppo_disabled_styles`

## Testing quirks

- Jest config lives in `package.json` (no `jest.config.js`). Environment is `jsdom`.
- `src/setupTests.js` mocks: `wppoSettings` (translations), `window.matchMedia`, and `@wordpress/components` (ToggleControl → plain checkbox)
- Global `wppoSettings` object must be extended per-test (`apiUrl`, `nonce`, `settings`, etc.)
- All 9 test suites use `@testing-library/react` + `@testing-library/jest-dom`
- **API mocking patterns**:
  - `jest.mock('../../lib/apiRequest', () => ({ apiCall: jest.fn() }))` (preferred for components)
  - `global.fetch = jest.fn()` (used in apiRequest.test.js)
  - `jest.spyOn(console, 'error').mockImplementation(() => {})` + `.mockRestore()` for sad paths
- **No PHP unit tests** exist — only WPCS linting + `parallel-lint` in CI
- **No pre-commit hooks** — all quality checks run in CI only

## JS conventions

- ESLint extends `plugin:@wordpress/eslint-plugin/recommended`
- Globals (ESLint): `wppoSettings`, `wppoObject`, `ScrollTrigger`, `jQuery`, `alert`
- `console.log` is error-level; only `console.error` and `console.warn` allowed
- CSS: custom SCSS design system with `.wppo-` prefix, BEM-like naming, CSS custom properties, no Tailwind/CSS-in-JS
- SCSS breakpoints: `sm` (640px), `md` (768px), `lg` (992px), `xl` (1200px) via `respond-to()` mixin
- All translatable strings come from `wppoSettings.translations` with English fallback

## PHP conventions

- WordPress Coding Standards (`phpcs.xml`)
- PHP 8.2 minimum
- PHPCS excludes: `vendor/*`, `node_modules/*`, `build/*`
- Composer deps: `voku/html-min`, `matthiasmullie/minify`, `woocommerce/action-scheduler`
- No WP-CLI commands registered
- All REST endpoints require `manage_options` + `X-WP-Nonce`
- Settings stored as serialized array in single `wppo_settings` option

## Build

- No custom Webpack config — uses `@wordpress/scripts` defaults
- Entry points: `src/index.js` (React SPA) + `src/lazyload.js` (frontend lazy loader)
- **Build output is committed** to git: `build/index.js`, `build/lazyload.js`, `build/style-index.css`, `build/style-index-rtl.css`, `build/*.asset.php`
- Always rebuild after JS/SCSS changes (`npm run build`)
- Node version: 22.14.0 (`.nvmrc`)
- `.browserslistrc`: `last 1` Chrome/Firefox/Safari, `not dead`

## Release

- Tag `v*` triggers `.github/workflows/release.yml`
- `scripts/build-release.sh` creates ZIP using `.distignore` patterns
- Deploys to WordPress.org SVN via `10up/action-wordpress-plugin-deploy`
- `.distignore` excludes dev files (configs, tests, `.github`, `.jules`, `.qoder`, dev vendor packages)

## CI workflows

| Workflow | Trigger | What it does |
|----------|---------|-------------|
| `release.yml` | `v*` tag | Production build + ZIP + GitHub Release + WordPress.org SVN deploy |
| `webpack.yml` | Push/PR to master | `npm ci` → `npm run lint:js` → `npm run build` |
| `psalm-wpcs-check.yml` | Push/PR to master + weekly | `parallel-lint` (PHP 8.2-8.5), `phpcs` + Psalm security scan, GitHub Issue/PR comment |
| `qoder-auto-review.yml` | PR opened/synced | `QoderAI/qoder-action` auto-review |
| `qoder-assistant.yml` | Comment with `@qoder` | `QoderAI/qoder-action` on-demand |
| `daily-audit.yml` | Daily (2 AM UTC) + manual | Runs full verification suite + AI codebase audit + reviews open PRs + auto-merges at 95%+ confidence |
| `tri-merge-cycle.yml` | Every 3 days (3 AM UTC) + `workflow_dispatch` | Verifies codebase, resolves merge conflicts, merges ready PRs, bumps version, creates release tag |
| `wordpress-monitor.yml` | Weekly Sunday (4 AM UTC) + manual | Researches new WP features via web + Context7, analyzes plugin code, creates improvement PR with fallbacks |

## Autonomous Workflows (`.agents/`)

This plugin has autonomous AI agent workflows. See `.agents/AGENTS.md` for agent workspace rules.

### Skills
- **`.agents/skills/wppo-reviewer/SKILL.md`** — Code review skill with confidence scoring (95%+ gate for merge)
- **`.agents/skills/wppo-fixer/SKILL.md`** — Auto-fix skill with verification loop + WP backward-compat patterns
- **`.agents/skills/wppo-wordpress-monitor/SKILL.md`** — WP feature monitor skill for researching/implementing new core features

### Automation rules
- **Merge gate**: AI confidence must be >= 95% AND all verification checks must pass
- **All changes must maintain backward compatibility**: Use `function_exists()`, `has_filter()`, and version-gated fallbacks
- **Scripts**: `.github/scripts/` contains setup/utility scripts for CI (setup-opencode, gather-context, etc.)

## Required GitHub Secrets

| Secret | Required | Purpose |
|--------|----------|---------|
| `GH_PAT` | Yes | GitHub Personal Access Token with `repo` + `workflow` scopes for cross-repo operations, merge, and tag push |
| `CONTEXT7_API_KEY` | Yes | Upstash Context7 MCP key for querying latest WordPress/core developer documentation |
| `GITHUB_TOKEN` | Auto | Built-in token (used as fallback when `GH_PAT` is unavailable) |
| `OPENAI_API_KEY` | No | OpenCode model fallback |
| `ANTHROPIC_API_KEY` | No | OpenCode model fallback |
| `GEMINI_API_KEY` | No | OpenCode model fallback |
| `SVN_USERNAME` | Yes (exists) | WordPress.org SVN username for plugin deployment |
| `SVN_PASSWORD` | Yes (exists) | WordPress.org SVN password for plugin deployment |
