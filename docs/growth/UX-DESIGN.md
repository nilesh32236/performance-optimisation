# UX Design — Admin Experience (Phase C)

Scope: simplify the existing Performance Optimisation admin experience for
beginners. Copy, hierarchy, and progressive disclosure only. No REST, cache,
database, settings-persistence, or optimization behavior changes, and no
whole-application redesign.

## 1. IA map (seven tabs)

Sidebar order is fixed (`src/App.js` `sidebarItems`):

1. Dashboard — onboarding, safe presets, health, page cache
2. File Optimisation — minify, defer, delay, CDN, server rules
3. Preload — cache warm-up, preconnect, preload fonts/CSS
4. Image Optimisation — lazy load, picture, WebP/AVIF, limits
5. Database — 9 cleanup operations
6. Object Cache — Redis standalone/sentinel/cluster
7. Tools — activity log, PageSpeed key, export/import

No routing library (tab switching via `useState`), no state-management
library (pure `useState` + the shared `wppoSettings` global). Phase C does
not reorder tabs; it prioritizes *within* the Dashboard.

## 2. Safe/beginner path

Onboarding order on the Dashboard:

1. `WelcomePanel` — step 1 (Page Caching) always visible; steps 2–4
   (minify, lazy load, WooCommerce check) collapse into one native
   `<details>` disclosure. The intro names the Safe preset and links to
   `#wppoSafeStart`. Step `update_settings` payloads are unchanged.
2. `OptimizationPresets` (`#wppoSafeStart`) — Safe is first, default
   selected, and badged Recommended (badge is `aria-hidden` so the
   accessible button name stays `Safe`). Descriptions are plain language:
   Safe = "nothing that can break your layout", Balanced = "most sites
   stop here", Aggressive = "for advanced users, check pages afterwards".
3. `GuidedNextStep` — the single RUM-driven next action stays below the
   presets flow in the stacked cards.

Advanced controls remain available: every tab, every switch, and the full
suggestions list are untouched — only the beginner ordering, defaults,
and copy changed.

## 3. Preset matrix

| Preset     | Audience     | What it turns on                                  |
| ---------- | ------------ | ------------------------------------------------- |
| Safe       | Beginners    | Page cache + lazy-load images                     |
| Balanced   | Most sites   | Safe + low-risk wins (HTML/CSS tidy, defer, RUM)  |
| Aggressive | Advanced     | Full pipeline (JS minify, delay, combine, CCSS)   |

Preview: the diff list is capped at `DIFF_PREVIEW_LIMIT` (8) rows with an
"…and N more change(s)" line. The cap is render-only; the apply payload
is never truncated. Undo contract (shown under the actions):
"Undo returns to the snapshot taken when you applied the preset. Export
JSON is a manual backup file you keep yourself."

## 4. Guard / a11y / mobile / RTL contracts

- **Unsaved changes**: `UnsavedChangesContext` + `ConfirmDialog` with
  Cancel/Discard verbs verbatim, `beforeunload` blocking, and focus trap
  preserved. Only the title/message copy was clarified; Cancel never
  discards, Discard never fires on Escape while busy (`isBusy` blocks
  Escape and disables both buttons).
- **Dialogs**: `role="dialog"`, `aria-modal`, labelled title/message,
  focus trap cycling (including single-control and empty-list edges),
  Escape-to-cancel with focus restore.
- **Mobile**: preset buttons/actions go full-width at `sm`; preset diff
  uses `overflow-wrap: anywhere`; dialog capped at
  `min(440px, calc(100vw - 2rem))`; dialogs stack actions vertically.
  Verified at 390×844, 768×800, 1280×800
  (`tests/e2e/ux-phase-c.spec.js` against the shipped
  `build/style-index.css`: no horizontal overflow, dialog fits, no
  unexplained console/network errors).
- **RTL**: styles use logical properties (`margin-block`,
  `padding-inline`, `inset-inline-start`) exclusively; no new physical
  `left`/`right` declarations. LTR and RTL share one code path.
- **Reduced motion**: existing `prefers-reduced-motion` handling kept.

## 5. Regression coverage

- `OptimizationPresets.ux.test.js` — Safe default + badge, diff cap +
  remainder line, undo copy.
- `WelcomePanel.order.test.js` — Safe-shortcut link target, step-1 /
  disclosure split, unchanged `update_settings` payloads.
- `ConfirmDialog.guard.test.js` — dialog semantics, Esc semantics,
  Cancel-vs-Discard, focus trap, busy blocking.
- `tests/e2e/ux-phase-c.spec.js` — viewport overflow, dialog usability,
  disclosure toggle, console/network allowlist.
