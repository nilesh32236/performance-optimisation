# Trust and legitimate conversion

Phase F record: how a new user verifies value, free/open-source status, safe
defaults, recovery, support, and compatibility — with no fake reviews,
ratings, scores, or guarantees.

## Value proposition (truthful)

Performance Optimisation is one free plugin combining page caching, CSS/JS
optimization, image optimization (WebP/AVIF, lazy loading, LCP preload),
preloading, database cleanup, Redis object cache, and Core Web Vitals
monitoring (PageSpeed + first-party RUM). Start with the features that match
the hosting and site setup; test one change at a time.

## Free and open source

- GPLv2 or later; no premium version, no upsells, no feature restrictions.
- Canonical statement: `readme.txt` FAQ “Is this plugin free?” (mirrored in
  `readme.md`).
- Never invent ratings, review counts, installation counts, or scores in
  copy, docs, or tests.

## Safe defaults and first run

- Fresh install: page cache available, native lazy loading, LCP guardrails,
  WooCommerce safe mode on; aggressive options (defer/delay JS, WooCommerce
  asset stripping, server rules) off by default.
- First-run path (WelcomePanel intro): **Start Safe → Test → Review →
  Advanced only when needed.** Safe is the baseline; test logged-out before
  Balanced/Aggressive.
- Preset behavior is unchanged: Safe (beginner baseline), Balanced
  (recommended after a baseline), Aggressive (requires testing, guards stay
  forced on). Every preset apply shows a diff preview first.

## Recovery path

- Every `update_settings` save keeps an automatic restore point:
  `restore_settings` (Undo button on the Dashboard preset card and
  Tools → Undo Last Settings Change).
- Redacted JSON export before major changes (Tools → Export Configuration,
  Dashboard → Export JSON); secrets are stripped/redacted, never exported
  in cleartext. Re-import restores configuration; stored secrets stay
  server-side.
- Upgrade guidance: export before major upgrades; deactivation removes
  owned cron/drop-ins/created `WP_CACHE` guard; uninstall additionally
  removes options, activity data, generated cache, converted copies, and
  post meta — never original Media Library uploads.

## Support workflow (redacted)

1. Reproduce on one URL with one recent setting change.
2. Collect WordPress/PHP/server versions, exact steps, and redacted
   `wp wppo system-info` / `wp wppo verify` output.
3. Never share passwords, API keys, cookies, session IDs, authorization
   headers, database dumps, or personal data; rotate anything pasted
   accidentally.
4. Ask via the WordPress.org support forum or GitHub issues.
5. Full page: `docs/site/support.html` (Support guide), linked from
   Troubleshooting, FAQ, Compatibility, Tools → Support And Safe Upgrades,
   `readme.txt`, and `readme.md`.

## Compatibility and troubleshooting entry points

- Compatibility matrix: `docs/site/compatibility.html` (Verified /
  Supported / Best effort / Known limitation — labels are boundaries, not
  blanket guarantees).
- Symptom-first fixes: `docs/site/troubleshooting.html`.
- Install + first-run sequence: `docs/site/installation.html`.
- Short answers: `docs/site/faq.html`.

## Invariants (must never regress)

- No fake reviews, ratings, support posts, installations, scores, or
  guarantees in copy, docs, tests, or fixtures.
- Safe/Balanced/Aggressive behavior unchanged; docs/copy/E2E only.
- Existing optimization behavior, settings, REST/CLI contracts, and
  compatibility claims stay truthful.
- Credentials, cookies, API keys, and private data are never requested,
  logged, or exposed.

## New-user Playwright journey

Spec: `tests/e2e/new-user-journey.spec.js` (requires a running WordPress
with the plugin active; `@playwright/test` is an external runner invoked
via `npx playwright test` and is not a bundled npm dependency — no new
package was added to `package.json`).

Journey: install → activate → welcome panel shows the Start Safe intro →
apply Safe preset with diff preview → verify cache/status → Undo via
restore point → Tools tab shows the Support And Safe Upgrades card with
support/troubleshooting/compatibility links (HTTP reachability is probed
only when `PLAYWRIGHT_DOCS_BASE_URL` is set, since published docs live on
an external host, not the WP origin) → assert zero unexplained browser
console errors.

### Friction report

- Baseline friction (pre-Phase F): value/free/OSS status, safe defaults,
  Undo location, and redacted-support steps were scattered across
  readme/FAQ/docs with no single support page; first-run intro did not
  name the Start Safe → Test → Review → Advanced path.
- Post-Phase F: one support page + cross-links, first-run intro naming
  the path and the automatic restore point, Tools support card with
  export-before-change guidance, readme FAQ entries for recovery/support.
- Residual: Playwright journey needs a live WP environment (not run in
  Jest/PHPUnit CI); docs publishing still flows through the Boltfolio
  pipeline (`docs/site/README.md`).
