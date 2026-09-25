# Phase C — Admin UX Simplification Design Record

**Campaign:** Phase C — Autonomous Admin UX Simplification Campaign
**Date:** 2026-09-25
**Issue:** #1630

## UX principle

> Simple by default. Powerful when needed. Safe at every step.

Phase C keeps the existing seven-tab navigation and all advanced controls. It does not create separate products, a router, a state store, or new optimization behavior.

## Current IA audit

The current shell exposes:

1. Dashboard
2. File Optimisation
3. Preload
4. Image Optimisation
5. Database
6. Object Cache
7. Tools

The existing Dashboard already has a welcome/onboarding path, `GuidedNextStep`, safe/balanced/aggressive preset previews, an automatic restore point, and an explicit undo action. The main beginner friction is that the three preset levels are visually similar and their risk difference is not explicit enough at the point of choice. A full five-area regrouping would add navigation churn and is not justified for this phase.

## Changes in this phase

### Preset progressive disclosure

The existing preset behavior is unchanged. The UI now makes the audience and risk level visible without requiring a preview:

- **Safe — Beginner:** low-risk optimizations; page cache and lazy loading; aggressive options stay off.
- **Balanced — Recommended:** more performance with compatibility considerations; low-risk HTML/CSS/JS, preload, and RUM improvements.
- **Aggressive — Advanced:** advanced optimizations that may require testing; safety guards remain forced on.

Each preset button remains a preview control. The selected level's explanation is connected with `aria-describedby`; the existing automatic restore point and Undo action remain visible.

### Copy and hierarchy

The preset introduction now tells users to choose a level, preview changes, and keep a restore point. The explanation identifies the beginner baseline, recommended middle path, and advanced/testing path in one sentence without adding another control or changing persistence.

### Preserved safety behavior

- Dirty forms still use the existing App-level tab-change guard.
- Cancel keeps the current tab and dirty state.
- Discard closes the dialog, clears the dirty flag, and navigates to the pending tab.
- Browser unload protection remains active while dirty.
- No form is silently submitted or discarded by this phase.
- Existing warnings, save buttons, snapshots, and undo actions remain intact.

## Accessibility audit

The preset change adds explicit audience text and programmatic description links. Existing dialog focus trapping, Escape handling, notice roles/live regions, loading status, and mobile sidebar focus handling were reviewed and left unchanged because they already have dedicated coverage.

The UI still avoids relying on color alone: selected state is exposed with `aria-pressed`, and the level names are visible text.

## Mobile and RTL

- Existing responsive breakpoints and mobile sidebar behavior are preserved.
- Preset buttons stack to full width on narrow screens to reduce tap-target and label ambiguity.
- No new physical left/right positioning was introduced.
- Existing RTL styles and logical safe-area handling remain unchanged.
- Playwright verification covers 390×844, 768×800, and 1280×800 after the UI change.

## No behavior changes

This phase does not change:

- REST routes, auth, or response shapes
- Cache generation, purge, or cache invalidation
- Database cleanup
- Image conversion or lazy loading
- Redis/CDN/LiteSpeed configuration
- Settings persistence or preset payloads
- File Optimization, Preload, Image, Database, Object Cache, or Tools behavior

## Remaining UX backlog

- Revisit the five-area navigation grouping only after measured user testing; the current seven-tab structure is intentionally retained.
- Consider a compact “recommended next step” treatment for returning intermediate users if telemetry supports it.
- Continue auditing physical CSS directionality in legacy components without broad RTL churn.
- Preserve the existing advanced controls; future progressive disclosure should be per-card and evidence-driven.

## Verification

The Phase C PR records the full local gates, Playwright viewport results, accessibility checks, direct review, and post-merge WordPress verification. See `docs/architecture/refactor-queue.yaml` for the immutable issue/PR/merge metadata.
