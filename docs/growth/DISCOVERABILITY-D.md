# Phase D — Feature Discoverability Audit & Report

**Campaign:** Phase D — Make existing features easier to discover
**Date:** 2026-09-25
**Issue:** #1633

## Principle

> No new optimization capabilities. Just shorter paths to the ones that exist.

Phase D keeps the existing seven-tab navigation, all advanced controls, and
all runtime behavior. It only names destinations and adds one compact
shortcut card.

## Task map (new `TaskMap` card on the Dashboard)

| Goal | Shortcut label | Destination (existing control) |
|------|---------------|--------------------------------|
| Speed up repeat visits | Open Dashboard → | Dashboard → Page Cache card |
| Improve LCP / Core Web Vitals | Open Preload → | Preload (fonts/CSS); diagnose via Dashboard audit |
| Shrink images | Open Image Optimisation → | Image Optimisation tab |
| Minify CSS/JS, critical CSS | Open File Optimisation → | File Optimisation tab |
| Clean database overhead | Open Database → | Database tab |
| Redis object cache | Open Object Cache → | Object Cache tab |
| Serve assets via CDN | Open CDN Settings → | File Optimisation → CDN Settings (edge purge on Dashboard) |

Source: `src/lib/taskMap.js` (`CORE_TASKS`, 7 entries, `@since NEXT`) rendered
by `src/components/TaskMap.js` (`FeatureCard`, `onNavigate(tab)` only).
Mounted in `Dashboard.js` directly after `<WelcomePanel />` so it stays
visible to returning users.

## Suggestion button labels (no navigation change)

`FIX_ACTION_TAB_MAP` values are unchanged — every button lands on exactly
the same tab as before. Only the visible and accessible names changed from
the generic "Fix It" to the destination feature:

| fix_action | Button label | Tab (unchanged) |
|------------|-------------|-----------------|
| open_object_cache_tab | Open Object Cache → | objectCache |
| open_image_optimization_tab | Open Image Optimisation → | imageOptimization |
| open_file_optimization_tab | Open File Optimisation → | fileOptimization |
| open_ccss_settings | Open Critical CSS → | fileOptimization |
| enable_server_rules | Open Server Rules → | fileOptimization |
| open_preload_tab | Open Preload → | preload |

Applied in both `SuggestionsPanel.js` (`getFixActionLabel()` +
`FIX_ACTION_LABELS`, `@since NEXT`) and `GuidedNextStep.js` (same helper).
Accessible names use `sprintf('%1$s: %2$s', label, description)`.
Good-status rows still show the "Passing" indicator with no button.

## Viewport verification (all seven tabs discoverable)

No Playwright config exists in this repo, so verification is a static +
existing-CSS review (no new layout introduced):

- The sidebar still renders all seven `App.js` items (Dashboard, File
  Optimisation, Preload, Image Optimisation, Database, Object Cache, Tools)
  with the existing `992px` breakpoint and mobile overlay + focus trap.
- The `TaskMap` card is a vertical `FeatureCard` list that stacks within
  the existing dashboard flow — no new grid, breakpoint, or positioning,
  so it inherits the dashboard's responsive behavior at 390×844, 768×800,
  and 1280×800.
- `aria-label`s mirror visible labels (WCAG 2.5.3 Label in Name preserved).

Manual viewport checklist (to confirm on a live admin during review):

- [ ] 390×844: mobile header + overlay list all 7 tabs; TaskMap stacks, no overflow.
- [ ] 768×800: sidebar or overlay lists all 7 tabs; TaskMap stacks, no overflow.
- [ ] 1280×800: sidebar lists all 7 tabs; TaskMap two-column-safe, no overflow.

## Queue metadata

"Queue metadata" for this phase is the `D-001` entry in
`docs/architecture/refactor-queue.yaml` (status/scope/non-goals only) plus
JSDoc `@since NEXT` tags on `CORE_TASKS`, `TaskMap`, `FIX_ACTION_LABELS`,
and `getFixActionLabel()`. No Action Scheduler, REST, or persistence
changes per the phase invariants.

## Non-goals honored

- No REST, API contract, settings persistence, cache, image, database,
  Redis, CDN, LiteSpeed, or runtime optimization behavior changes.
- No new optimization functionality; no fake telemetry or fabricated
  recommendations.
- Existing advanced controls remain available.
