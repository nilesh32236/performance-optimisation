# Documentation Map — Phase E

**Issue:** #1636 · **Status:** active · **Queue:** docs-only, no runtime change.

This manifest is authoritative for `docs/site/` user documentation.
Every entry must exist as a file, use the beginner-first template,
and cite code/readme sources for Compatibility and Verify claims.

## Page template (beginner-first order)

1. What it does
2. When to use it
3. Safe default
4. How to enable
5. What changes (files, headers, frontend behavior)
6. Compatibility (status labels — see below)
7. Verify (observable steps)
8. Undo (safe rollback)
9. Troubleshooting
10. FAQ
11. Technical reference (last)

No page may put technical reference before the beginner sections.
No thin or near-duplicate keyword pages: each page answers distinct
user questions; hubs (Core Web Vitals, Compatibility, CDN) consolidate
cross-cutting topics instead of duplicating them. The two meta-hubs
(`troubleshooting.md`, `faq.md`) carry a lighter contract — What-it-does,
internal links, sources, and a label reference — instead of the full
feature template, because they index answers rather than document a feature.
`compatibility.md` shares the lighter contract (its matrix *is* the
compatibility section, so nested How-to-enable/What-changes/Compatibility
headings would be redundant).

## Compatibility status labels

- `verified` — covered by automated tests or a built-in self-test in this repo.
- `supported` — named integration path exists in code (mode, exclusion, purge sync), test on staging.
- `best effort` — coexistence guidance only, no dedicated integration path.
- `known limitation` — documented behavior that will not work; includes workaround or opt-out.

Universal compatibility claims ("works with everything") are forbidden.

## Pages

| Page | File | Hub |
|---|---|---|
| Page Cache | `docs/site/page-cache.md` | cache |
| Images | `docs/site/images.md` | cwv |
| CSS and JS | `docs/site/css-js.md` | cwv |
| Deferred JavaScript | `docs/site/deferred-js.md` | cwv |
| Critical CSS | `docs/site/critical-css.md` | cwv |
| Used CSS | `docs/site/used-css.md` | cwv |
| Preload | `docs/site/preload.md` | cwv |
| Redis Object Cache | `docs/site/redis.md` | cache |
| LiteSpeed Coexistence | `docs/site/litespeed.md` | cache |
| Database Cleanup | `docs/site/database-cleanup.md` | maintenance |
| Monitoring and Core Web Vitals | `docs/site/monitoring.md` | cwv (hub) |
| Compatibility | `docs/site/compatibility.md` | hub |
| CDN and Edge Cache | `docs/site/cdn.md` | hub |
| Troubleshooting | `docs/site/troubleshooting.md` | hub |
| FAQ | `docs/site/faq.md` | hub |

## Internal-link map (natural links, relative paths only)

- `monitoring.md` ↔ `images.md` ↔ `preload.md` ↔ `css-js.md` ↔ `page-cache.md`
- `page-cache.md` ↔ `litespeed.md` ↔ `cdn.md`
- `page-cache.md` ↔ `compatibility.md` (WooCommerce exclusions)
- `redis.md` ↔ `compatibility.md` ↔ `troubleshooting.md`
- `critical-css.md` ↔ `used-css.md` ↔ `troubleshooting.md`
- `database-cleanup.md` ↔ `troubleshooting.md`
- `faq.md` links to every feature page; every feature page links back to `faq.md` and `troubleshooting.md`.

## Sources of truth

- `readme.txt` (user-facing behavior, FAQ, External Services)
- `includes/Settings/class-settings-store.php` (`get_default_settings()` — safe defaults)
- `includes/Cache/class-cache.php` (static HTML cache)
- `includes/Images/class-image-optimisation.php`, `includes/Images/class-img-converter.php`
- `includes/CSS/class-critical-css.php`, `includes/CSS/class-used-css.php`
- `includes/Cache/class-object-cache.php`, `includes/Cache/class-redis-config-policy.php`
- `includes/Integrations/class-litespeed-integration.php`, `includes/Integrations/class-woo-detect.php`
- `includes/Database/class-database-cleanup.php`
- `includes/Insight/class-rum.php`, `includes/Insight/class-pagespeed.php`
- `includes/Edge/class-cdn.php`
- `docs/growth/KEYWORD-MAP.md`, `docs/growth/WORDPRESS-ORG.md` (terminology only; no score guarantees)

## Checks (gate before merge)

`npm run docs:check` runs `scripts/docs-check.sh`:

1. **manifest** — every table entry exists and contains the template headings.
2. **links** — every relative `.md` link resolves to an existing file.
3. **sources** — every page names its code/readme source paths (anti-drift rule).

CI wiring (requires maintainer with workflow permission — not applied by this change):
add a docs job running `npm run docs:check`, e.g. in `webpack.yml` or a new
`docs.yml` workflow.
