# Admin Dashboard Design Improvement Plan

> Goal: improve the WP-admin UI **bit by bit on every release** — small, safe,
> reviewable batches. Design quality drives adoption: good functionality that
> looks unpolished goes unnoticed and unused.
>
> Source: full static UI/UX audit of the React SPA (2026-09-18, post-2.2.0).
> The foundation is solid (`FeatureHeader` / `FeatureCard` / `NoticeBanner` +
> `useNotice()` / `LoadingSubmitButton` / CSS variables). The problems are
> drift and density, not missing primitives.

## Working rules for design batches

- One release = one phase-batch max; **no functionality change** inside design passes.
- Conventions (apply from Phase 1 on): page-level notice always directly below
  `FeatureHeader` in `.wppo-notices-container`; header save = `Save Settings`,
  in-card saves = `Save <Section>`; primary buttons reserved for Save/Enable
  only (purge/flush/export = secondary, import/disable/delete = danger).
- Every batch follows the verification order
  (`npm run lint:js` → `composer lint` → `npm test` → `npm run build`) and
  commits rebuilt `build/`.
- Copy wording is reviewed by the orchestrator; visual structure is owned by design.

## Phase 1 — Quick wins (target: v2.3.0, XS–S effort each)

1. Notices live in 3 different places → single rule (below header, `wppo-mb-20`).
2. Every tab labels "Save" differently → `Save Settings` / `Save <Section>`.
3. Destructive actions look primary (`Purge All Cache`, `Download JSON`) → secondary/danger.
4. Raw `.wppo-notice` divs bypass `NoticeBanner` → route all through it (icons, alert/status, dismiss).
5. Two stat-card languages (Dashboard vs ObjectCache) → neutral `--telemetry` variant.
6. Card footers built two ways → always use the `footer` prop.
7. Danger Zone nesting inverted in Tools → `FeatureCard className="wppo-danger-zone"`.
8. Duplicate card icons (`faMagic`×3, `faShieldAlt`×2, `faBolt`×2) → 1 icon per concept.
9. Helper-text walls (~80-word paragraphs) → 1 line + `Tooltip` / `<details>`.
10. Uppercase field labels blend into help text → labels `#0f172a` 13px semibold via SCSS variable.

## Phase 2 — Consistency and flow (one release, S–M effort)

11. Dashboard is one endless scroll (~2,100 lines) → section anchors + collapsible panels + mini-nav.
12. File-tab Save is far from the work → sticky save bar + persist `activeSubTab`.
13. Sub-tabs have no scroll affordance → drop fade overlay, edge shadow, 390px check.
14. Three boolean styles on one screen → `SwitchField` = on/off, chips = multi-pick, 36–40px targets.
15. Number inputs hide their affordance → `min/max/step` + unit suffixes ("px", "%", "days").
16. Woo results are "—" text soup → `StatusBadge` pills + mono paths + pass counts.
17. Dirty state invisible until tab-switch → "Unsaved changes" pill + Save pulse.
18. DB cards: 10 equal cards, no risk grouping → Safe/Review groups, fix Export placement.

## Phase 3 — Structure and polish (later releases, S–L effort)

19. Tab names don't match headers; Tools is a junk drawer → relabel first, then split.
20. No skeletons (tab switch flashes) → skeleton cards, shimmer, `prefers-reduced-motion`.
21. Breakpoints waste the middle (2-col only >992px) → collapse at `md` only; test 782px/390px.
22. RTL/LTR nits → logical props (`margin-inline-start`, `inset-inline-*`) throughout.
23. Feedback stacking has no system (up to 5 banners) → one page slot (latest wins) + standard timings.
24. Empty/loading/error states differ per card → shared empty-state component with action.

## Monitor wiring

The weekly WordPress Feature Monitor includes a `design-researcher` lane
(competitor admin-UX parity, WP admin design trends, usability pain points from
1–3 star reviews/forums, accessibility), so each cycle surfaces one design
finding batch to feed the next release's design bit.
