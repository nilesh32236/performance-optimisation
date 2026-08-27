# Design Proposals — Performance Optimisation SPA

**Date:** 2026-08-27  
**Audit:** Verification sub-agent on `src/css` v5.0 (19 SCSS partials, `App.js` 519 LOC) — dark 260px sidebar, 16px radius, `color-mix` theming, premium shadows.  
**Choice required:** Review the 3 static HTML variants below and pick one. The unchosen variants are reference only — no code is mutated until you confirm.

- Open each variant as a standalone HTML file: `file://…/designs/variant-*.html` (or `npx serve designs` → `http://localhost:3000/variant-*.html`).
- Each variant is **static** — no build, no WP, no React — pure HTML+CSS with inline styles so you can judge layout instantly.
- Vote via issue #709 comment or direct revert: keep `variant-a`, `variant-b`, or `variant-c`.
- Issue #709: https://github.com/nilesh32236/performance-optimisation/issues/709

| Variant | Name | Layout | When to pick |
|---|---|---|---|
| **A** | **Native WordPress** | WP primitives (`tabs` top, no dark sidebar, Inter, 8px radius, `f0f0f1` bg) | Longevity — survives WP 6.8 admin redesign, smallest maintenance, perfect RTL/a11y |
| **B** | **Premium Command Deck** | Refined v5 (dark 260→64 collapsible sidebar, gradients, 16px, `container-type`) | Sales sparkle — most wow screenshots, premium SaaS feel |
| **C** | **Dense Utility** | Toolbar+table+inspector (light 220px nav, tabular KPIs, `⌘K` palette, vertical tree tabs) | Power-user — 50k images, LiteSpeed 4 modes without scroll bloat |

**Not decided now — the plugin's current v5 design remains live on `master`.** These are non-destructive previews under `designs/` (gitignored from `build`).

## Technical notes (for implementer)

- All 3 variants preserve `App.js` tab names (`dashboard/fileOptimization/preload/imageOptimization/databaseCleanup/objectCache/tools`) and `wppoSettings` contract — adaptation is CSS + `App.js` sidebar/tabs reshuffle only.
- Variant B's collapsible sidebar (`localStorage wppo_sidebar_collapsed`, `782px` breakpoint, `container-type:inline-size` cards, `Inter` via `wp_enqueue_style`) is the most work (~3 days) but is backwards-compatible (falls back to current v5 if JS disabled).
- Variant A's WP Tabs (`@wordpress/components Tabs`) requires `@wordpress/components ^29` already in `package.json` — zero deps.
- Dark mode: Variants B ships `html[data-wppo-theme=dark]` tokens; A inherits WP `auto`; C defers.
- Litespeed banner slots: All variants reserve a top `InfoBanner` (Dashboard + Network tab) for `is_litespeed` per `litespeed-integration-plan.md §12`.

## Verification

Sub-agent audits flagged in current v5: `992px` breakpoint too late (WP collapses at `782px`, leaves 420px chrome at 782–992), `98dvh` bug, `1120px` centered wastes 25% on 1440p, `transition: all` expensive, no `container queries`, no dark mode, tooltip `Esc`/`role=tooltip` gaps, missing `URL hash` for sub-tabs. Each variant fixes its own subset — see per-variant file headers.

