---
name: wppo-ui-improver
description: Iteratively enhances React SPA components in the WP Performance Optimisation plugin, focusing on UI/UX, user-friendliness, and accessibility. Use when the user asks to improve the design, UX, or accessibility of the admin SPA.
---

# WPPO UI/UX Improver

Iteratively improves the design, user experience (UX), and accessibility (a11y) of
the React SPA in the WordPress **Performance Optimisation** plugin.

## When to Use

- The user asks to improve the design of a specific React component.
- The user requests accessibility (a11y) audits or fixes for the admin UI.
- The user wants an interface made more "user-friendly" or polished.
- Work inside `src/components/` and the `.wppo-` SCSS in `src/css/`.

## Steps

1. **Analyze the current state.** Read the component and its SCSS. Identify
   structural issues, poor UX patterns, and accessibility gaps (missing ARIA
   attributes, non-semantic markup, no keyboard navigation).
2. **Propose small, logical improvements** rather than one monolithic rewrite.
   Explain *why* each change helps a site owner. The user has asked for this
   work to be done **bit by bit** — a PR that changes one thing is reviewable; a
   PR that changes the admin is not.
3. **Enhance accessibility.**
   - Real semantic elements: `<button>` not `<div onClick>`.
   - `aria-*` only where it carries meaning a role cannot.
   - Screen-reader-only text where visual affordance is not enough.
   - **Every `Tab` stop must have a visible focus ring**, the same one everywhere.
4. **Improve UI/UX.**
   - Reuse `.wppo-` tokens and components; do not invent a second visual language.
   - Prefer `@wordpress/components` for native WP admin feel.
   - Give the UI real visual hierarchy and whitespace.
5. **Implement safely.** Keep props, hooks, state, and event handlers intact.
6. **Verify in a browser before claiming anything.** See the hard rules below.

## Hard rules learned the expensive way

These are not stylistic preferences. Each one cost a real defect.

- **A name is not a definition, and a search hit is not a proof.** Grepping
  `src/css` for a class name finds nothing when the rule is written as a nested
  `&__element`. Check the **built** `build/style-index.css`, which has no such
  ambiguity: `grep -o '.<class>{[^}]*}' build/style-index.css`.
- **An element box can be inside the viewport while its text is not.** Measure
  **ink** with `Range.getClientRects()` over text nodes, and compare it to the
  nearest **clipping ancestor** — not the viewport. A card with
  `overflow: hidden` will hide text that `scrollWidth` says is fine.
- **A check that cannot fail is not evidence.** A responsive assertion of
  `documentElement.scrollWidth > innerWidth` is structurally always false
  because the container uses `overflow-x: clip`.
- **A fix deserves the same suspicion as the bug.** A mechanism added to repair
  a real defect introduced five regressions, three of them worse than the
  original, because it only checked one edge and its positioning code was dead.
- **Verify against real geometry, on the built CSS**, at 320/360/390/414/768/992/1280.

## Verification recipe

There is no Playwright MCP in this environment. Drive Playwright directly:

```sh
export NODE_PATH=<plugin>/node_modules
export WPPO_PASS="$(cat /var/tmp/ux-campaign/.pwtest-pass)"   # admin: pwtest
```

```js
const { chromium } = require( 'playwright' );
// The bundled chromium-1234 CANNOT launch on this host. Use:
chromium.launch( { executablePath:
  '/home/admin/.cache/ms-playwright/chromium-1246/chrome-linux64/chrome' } );
```

- **Log in once, then resize** the viewport. Repeated logins time out.
- Use `ignoreHTTPSErrors: true`; the site is behind a self-signed TLS cert.
- `wp` CLI 2.12.0 is available: `wp --path=/var/www/nileshportfolio.duckdns.org`.
  `composer` is **not** on PATH — use `vendor/bin/phpcs` and `vendor/bin/phpunit`.
- **`object_cache` is throttled to 5 requests/minute.** A 429 and its honest
  "Unavailable" row are expected, not defects. A sweep that calls it 18 times
  will trip it every time.
- `scripts/deploy-guard.sh <worktree> <live>` must **gate** the deploy, and its
  **exit status must terminate the command**. Never pipe a guard into `grep`,
  never `&&` on its output. A guard that is overwritten is a guard that is
  decorative.

## Gotchas

- **Don't break functionality for aesthetics.** Verify React props, state hooks,
  and handlers still work — a jest mock proves a call happened, not that it
  does anything.
- **WordPress admin context.** Avoid large third-party CSS libraries; use core
  components or vanilla SCSS.
- **Only `npm run build`.** Bare `npx wp-scripts build` builds one entry and
  **silently deletes** `esi.js`, `lazyload.js`, `main.js` and `rum.js`.
- **WordPress core ships id-keyed `h1` rules** that beat any number of classes.
  Scope plugin headings to `#performance-optimisation`, the plugin's own mount
  node, rather than reaching for `!important`.
- **Never modify** `includes/Compatibility/class-llms.php` (pre-existing
  uncommitted work), and never touch a WordPress option without being asked.
- **Verify each PR independently** before merging. Never merge because CI is
  green, and never deploy while a review is in flight.
