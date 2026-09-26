# Phase D — In-Plugin Feature Discoverability

**Campaign:** Phase D — Autonomous In-Plugin Feature Discoverability Campaign
**Issue:** #1633

## Objective

Make existing Performance Optimisation features easier to find and understand without adding optimization capabilities.

## Core task map

| User task | Existing control | Before | After | User task improved | UI files |
| --- | --- | --- | --- | --- | --- |
| Enable caching | Dashboard → Page Cache | The setting was buried in the Dashboard card flow | A Dashboard shortcut identifies `Dashboard → Page Cache` and calls it Recommended | A beginner can find the master cache control without scanning every card | `src/components/FeatureTaskLinks.js` |
| Improve LCP | Preload → Critical Assets Preloading | “Preload” did not state its relationship to LCP | The task map points to `Preload → Critical Assets Preloading` and labels it Recommended | LCP intent maps directly to the existing preload controls | `src/components/FeatureTaskLinks.js` |
| Improve Core Web Vitals | Dashboard → Performance Audit | Audit was discoverable only through the Dashboard feature list | The task map names `Dashboard → Performance Audit` | Core Web Vitals intent has a clear starting point | `src/components/FeatureTaskLinks.js` |
| Optimize images | Image Optimization | The tab name was present but not exposed as a task | A direct `Optimize images → Image Optimization` path is visible | Image work has an obvious destination | `src/components/FeatureTaskLinks.js` |
| Optimize CSS and JavaScript | File Optimization → Assets / Scripts | The tab contained several sub-tabs with technical names | The task map names File Optimization and the existing Assets/Scripts destination | Users can connect the task to the right feature without API knowledge | `src/components/FeatureTaskLinks.js` |
| Clean database | Database Cleanup | The destructive cleanup destination was not presented as a task | The task map names Database Cleanup and marks it Requires confirmation | The action is findable while its risk remains clear | `src/components/FeatureTaskLinks.js` |
| Enable Redis | Object Cache → Redis connection | Redis was described through deployment fields | The task map names Redis Object Cache and marks it Advanced | Advanced users retain a direct path without presenting Redis as beginner-safe | `src/components/FeatureTaskLinks.js` |
| Configure CDN | File Optimization → Network | CDN controls were nested in a technical sub-tab | The task map names `File Optimization → Network` and marks it Advanced | CDN intent is mapped without adding a CDN feature | `src/components/FeatureTaskLinks.js` |

## Existing feature audit

- **Page Cache:** Dashboard, `Page Cache` / `Enable Page Cache`; explanation covers static HTML and TTFB; risk is moderate because cache invalidation matters; existing Snapshot/Undo remains available; the new task map makes the path explicit.
- **Image Optimization:** Image Optimization tab, `Image Optimisation`; explanation covers lazy loading, next-generation formats, and preloading; image exclusions and LCP guardrails remain visible; the task map provides the shortest path.
- **JavaScript/CSS Optimization:** File Optimization → Assets / Scripts; existing minify, combine, defer, delay, and safe-mode controls remain in place; the task map avoids exposing internal keys.
- **Preloading / LCP:** Preload → Critical Assets Preloading; font, CSS, and LCP hero controls retain their existing descriptions; the new path makes the LCP relationship explicit.
- **Core Web Vitals:** Dashboard → Performance Audit, RUM, trends, suggestions, and PageSpeed; the task map points to the existing audit surface and does not invent scores.
- **Database Cleanup:** Database Cleanup; cleanup operations and confirmation dialogs remain unchanged; the task map marks the path as requiring confirmation.
- **Redis Object Cache:** Object Cache; connection mode, authentication, compression, and danger-zone controls remain available; the task map marks Redis as advanced.
- **CDN:** File Optimization → Network; existing CDN configuration and purge controls remain unchanged; the task map identifies the existing Network sub-tab.
- **Server Rules:** File Optimization → Network/Core controls and suggestion action `enable_server_rules`; suggestion CTAs now say `Review Server Rules`.
- **Critical CSS / Used CSS:** File Optimization advanced controls remain available; no advanced control is removed or made a new feature.

## Suggestion wording

`src/components/SuggestionsPanel.js` still consumes real telemetry/suggestion objects and the existing `fix_action` allowlist. Only the user-facing action is clearer:

- `Fix It` → `Review Redis Object Cache`
- `Fix It` → `Review Image Optimization`
- `Fix It` → `Review File Optimization`
- `Fix It` → `Review CSS Optimization`
- `Fix It` → `Review Server Rules`
- `Fix It` → `Review Advanced Preloading`

The destination callback and all existing telemetry are unchanged.

## Safety and behavior

- No backend keys or API contracts changed.
- No new recommendations or scores are created.
- The task map is static navigation, not a recommendation engine.
- Existing warnings, save flows, undo/restore behavior, and dirty-form navigation remain unchanged.
- Advanced controls remain available in their current tabs.
- LTR/RTL behavior remains unchanged; the new layout uses logical flow and responsive grid rules.

## Tests

- Focused Jest coverage: `FeatureTaskLinks.test.js` and `SuggestionsPanel.test.js`
- Full Jest suite and required repository validation are recorded in the Phase D queue entry and PR.
- Playwright checks cover Dashboard, File Optimization, Preload, Image Optimization, Database, Object Cache, and Tools at the required viewports.

## Remaining discoverability backlog

- Consider adding a direct sub-tab callback for future Contextual Links without changing the current router-free architecture.
- Expand task copy only when real telemetry supports a more specific path.
- Continue auditing legacy physical directionality and per-feature explanations in a separate accessibility phase.
