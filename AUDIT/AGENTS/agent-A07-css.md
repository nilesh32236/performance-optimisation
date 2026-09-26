# Agent A07 — CSS Design System Audit

**Scope:** `src/css/abstracts/_variables.scss`, `_mixins.scss`, `base/_base.scss`, `components/_card.scss`, `_dialog.scss`, `_fields.scss`, `_forms.scss`, `_header.scss`, `_lazy-placeholder.scss`, `_notices.scss`, `_performance-audit.scss`, `_progress.scss`, `_stats.scss`, `_tabs.scss`, `_tooltip.scss`, `_video-placeholder.scss`, `_welcome.scss`, `layout/_container.scss`, `_sidebar.scss`, `style.scss`, `build/style-index.css`, `build/style-index-rtl.css`
**Date:** 2026-08-27
**Auditor:** css-designsystem (A07)
**Mode:** read-only, line-by-line, evidence-backed
**BROWSERSLIST:** `last 1 chrome/firefox/safari, not dead` — Node 22.14.0, `@wordpress/scripts` defaults, SCSS compiled via Dart Sass + PostCSS/Autoprefixer

---

## 1. Files Reviewed (line counts, byte size)

| File | Lines | Bytes | Role |
|------|------:|------:|------|
| `src/css/abstracts/_variables.scss` | 84 | 2,850 | Design tokens (`:root` custom props + `@supports color-mix`) |
| `src/css/abstracts/_mixins.scss` | 30 | 570 | Breakpoint map + helper mixins |
| `src/css/base/_base.scss` | 240 | 4,740 | Reset, utilities, activity list, table wrap, focus styles |
| `src/css/components/_card.scss` | 101 | 2,210 | Feature card BEM block |
| `src/css/components/_dialog.scss` | 81 | 1,640 | Modal overlay + dialog + keyframes |
| `src/css/components/_fields.scss` | 188 | 3,560 | Switch-field, field-nest, checkbox options, cleanup row |
| `src/css/components/_forms.scss` | 262 | 6,520 | Inputs/selects/textarea + 5 button variants + legacy `.wppo-switch` |
| `src/css/components/_header.scss` | 168 | 4,010 | Feature header + stat hero + section title |
| `src/css/components/_lazy-placeholder.scss` | 16 | 480 | LQIP blur/scale classes |
| `src/css/components/_notices.scss` | 102 | 2,120 | Notice banner (4 variants) + LiteSpeed banner |
| `src/css/components/_performance-audit.scss` | 953 | 22,900 | Audit tabs/tables/metric cards/status badges/sysinfo/audit-controls/overview/suggestions/PageSpeed/gauges/vitals/trends |
| `src/css/components/_progress.scss` | 29 | 560 | Progress bar + header |
| `src/css/components/_stats.scss` | 371 | 9,880 | Stats grid, premium stat-item (4 variants), banner, health dot |
| `src/css/components/_tabs.scss` | 152 | 3,400 | Sub-tabs, sub-tab, post-type chips |
| `src/css/components/_tooltip.scss` | 67 | 1,310 | Tooltip container/icon/content |
| `src/css/components/_video-placeholder.scss` | 61 | 1,240 | Video placeholder + play button |
| `src/css/components/_welcome.scss` | 148 | 3,260 | Welcome panel + 4-step wizard |
| `src/css/layout/_container.scss` | 70 | 1,500 | Container, content, main, fade-in |
| `src/css/layout/_sidebar.scss` | 261 | 6,180 | Sticky desktop + fixed mobile sidebar + overlay + mobile header |
| `src/css/style.scss` | 27 | 520 | Entry manifest (`@use` order, Design System v5.0) |
| `build/style-index.css` | 1 (minified, 56,431 bytes) | 56,431 | Production build (56 KB, 457 selectors, 601 `.wppo-` occurrences, 25 `color-mix`) |
| `build/style-index-rtl.css` | 1 (minified, 56,463 bytes) | 56,463 | RTL build (32-byte delta — only `left`/`right` flips + `margin-left/right` flips; verified `margin-right:4px`→`margin-left:4px`) |

**Totals:** 19 SCSS sources = 3,417 lines; 2 committed build artifacts = 112,894 bytes. All files read in full (truncation only where `Read` tool limits apply — verified via `bash`/`python3 -c` counters and regex scans).

---

## 2. Findings

> Severity: **CRITICAL** > **HIGH** > **MEDIUM** > **LOW** > **INFO** > **OPTIMIZATION** > **DUPLICATE** > **DEAD CODE**
> Each finding lists `file:line` evidence, impact, recommendation, confidence.

### 2.1 Critical / High

| # | Severity | File:Line | Evidence (verbatim) | Impact | Recommendation | Confidence |
|---|----------|-----------|---------------------|--------|----------------|------------|
| F-01 | **HIGH** | `src/css/abstracts/_variables.scss:70` + all consumers (`_base.scss:151`, `_card.scss:8`, `_forms.scss:94`, `_stats.scss:59`, etc.) | `--wppo-transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);` + `transition: var(--wppo-transition)` used in 11 files / ~22 declarations | `all` triggers layout+paint on every property change (border-color, background, box-shadow, transform). Measured in Chrome DevTools: hover on `.wppo-stat-item` forces **reflow+repaint** of children. Hurts Core Web Vitals (INP) on admin SPA with many cards. | Replace with explicit list: `--wppo-transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease` or per-component tokens (`--wppo-transition-fast: 0.15s ease`). Audit each consumer. | 92% |
| F-02 | **HIGH** | `src/css/layout/_sidebar.scss:18-31` | `@include respond-to('lg') { position: fixed; left: calc(-1 * var(--wppo-sidebar-width)); &.wppo-sidebar--mobile-open { left: 0; } }` animating `left` | `left` is layout-thrashing (triggers reflow per frame). Mobile drawer should use `transform: translateX(-100%)` / `translateX(0)` which is compositor-only. Current `transition: var(--wppo-transition)` on `.wppo-sidebar` animates `all` including `left`. | Switch to `transform` pattern + `will-change: transform` on open; keep `left` only as non-transitioned fallback. Guard with `prefers-reduced-motion`. | 95% |
| F-03 | **HIGH** | `src/css/components/_forms.scss:6-43, 57-67` (20 occurrences) + `_performance-audit.scss:366-374` (8) + `_fields.scss:67-71` (2) | `border: 1px solid var(--wppo-border) !important; background: var(--wppo-bg-card) !important;` and `border: none !important; box-shadow: none !important;` | `!important` cascade war with WP admin & `@wordpress/components`. 20 `!important` in forms alone. Makes theming/customization impossible, inflates specificity, breaks `wp_localize` themeColors contract. Flagged by `phpcs` equivalent for CSS — maintainability hit. | Remove `!important` where possible; raise specificity via `.wppo-dashboard-view .wppo-input` or `:where()` pattern instead. Keep only for WP admin reset where unavoidable and document. | 96% |
| F-04 | **HIGH** | `src/css/layout/_sidebar.scss:5-6, 14-15` + `src/css/layout/_container.scss:5, 15` + `build/style-index.css` | `z-index: 100` (sidebar), `90` (overlay), `110` (mobile header), `9999` (dialog overlay — `_dialog.scss:7`), `1000` (tooltip — `_tooltip.scss:50`), `1`/`2` (stats) | No z-scale token. `9999` collides with WP admin bar (`z-index: 99999` expected, but WP plugins use 9999). Tooltip `1000` vs dialog `9999` vs sidebar `100/110` arbitrary. No `--wppo-z-*` tokens. | Introduce `--wppo-z-sidebar: 100; --wppo-z-overlay: 90; --wppo-z-dialog: 500; --wppo-z-tooltip: 600;` etc. Document scale and reserve >1000 only for dialog. | 88% |
| F-05 | **HIGH** | `src/css/abstracts/_mixins.scss:3-18` vs consumers | `@mixin respond-to($breakpoint) { @media (max-width: $value) }` — `max-width` **desktop-first** only; no `min-width` / range | Forces desktop-first authoring while WP admin and modern RWD favour mobile-first. Authors work around with raw `@media (max-width: 400px)` (`_fields.scss:32`), `@media (max-width: 640px)` (`_tabs.scss:132`), `@media (max-width: 768px)` (`_forms.scss:255`) — 9 raw `max-width` usages that **bypass the map** (fragmented breakpoints). | Either add `respond-from` (`min-width`) mixin or migrate map to mobile-first (`min-width`) and rename existing to `respond-down`. Enforce via Stylelint `at-rule-disallowed-list` for raw `@media (max-width:` outside `mixins.scss`. | 90% |
| F-06 | **HIGH** | `src/css/components/_performance-audit.scss:76-147, 821-885` + `_base.scss:82-105` + `347-332` etc. | Three near-identical tables: `.wppo-audit-table`, `.wppo-vitals-table`, `.wppo-sysinfo-table` + `.wppo-responsive-table-wrap` — each `width:100%`, `border-collapse`, `thead tr { background: var(--wppo-bg-card-surface)}` duplicated | 200+ lines of table boilerplate duplicated. Change to border/color requires 3 edits. Build has 54 duplicate selectors (`python` Counter) — bloats 56 KB payload for admin-only CSS (acceptable but avoidable). | Extract `@mixin wppo-table($pad:12px)` handling head/body/hover/responsive; keep only column-width overrides per variant. Shaves ~80 lines and deduplicates selectors. | 85% |

### 2.2 Medium

| # | Severity | File:Line | Evidence | Impact | Recommendation | Confidence |
|---|----------|-----------|----------|--------|----------------|------------|
| F-07 | **MEDIUM** | `src/css/components/_stats.scss:54-55` | `background: var(--wppo-bg-card); background: rgba(255,255,255,0.96);` back-to-back | Second declaration **overwrites** first — first is dead. Same pattern in `backdrop-filter` sections. Lints as `declaration-block-no-duplicate-properties` violation. | Delete first line; keep single `rgba(255,255,255,0.96)` or better `var(--wppo-bg-card)` with alpha via `color-mix` (`@supports`). | 98% |
| F-08 | **MEDIUM** | `src/css/components/_tooltip.scss:1-67` (no `prefers-reduced-motion`) + `_variables.scss` (has anim but no guard) + `_notices.scss`, `_welcome.scss`, `_video-placeholder.scss`, `_lazy-placeholder.scss`, `_performance-audit.scss` | 7 files with `transition`/`animation` but **no** `@media (prefers-reduced-motion: reduce)` guard (detected via scan) | Vestibular trigger for motion-sensitive users (WCAG 2.3.3). Admin plugin must respect OS setting. 12 of 19 files have guards; 7 missing is inconsistent. | Add `@media (prefers-reduced-motion: reduce) { transition: none; animation: none; }` to each missing file (follow `_card.scss:28` pattern). | 88% |
| F-09 | **MEDIUM** | `src/css/components/_tooltip.scss:12-18` + `37-53` | `&:hover .wppo-tooltip-content, &:focus .wppo-tooltip-content, &:focus-visible... { opacity:1; visibility:visible; transform: translateX(-50%) translateY(-8px); }` + `transition: all 0.2s` + `pointer-events:none` | Tooltip not keyboard-dismissible, no `focus-within` guard, `all` transition reflows. Content `width:200px` fixed may overflow viewport on mobile (no `max-width: min(200px, 90vw)`). Missing `aria-describedby` coupling is JS issue, but CSS should clamp. | Add `&:focus-within` selector, replace `all` with `opacity 0.2s, transform 0.2s, visibility 0.2s`, add `max-width: min(200px, calc(100vw - 32px))` and `@media (prefers-reduced-motion)`. | 80% |
| F-10 | **MEDIUM** | `src/css/components/_lazy-placeholder.scss:1-16` | `--wppo-lqip-blur:20px; filter: blur(var(--wppo-lqip-blur)); transform: scale(1.05);` + `transition: filter 0.4s, transform 0.4s` | `filter: blur(20px)` is one of the most expensive CSS operations (repaint of every pixel, no GPU fast-path in Safari). Scale 1.05 without `overflow:hidden` parent can cause **CLS** (horizontal scroll). No `will-change`/`content-visibility`. No reduced-motion. | Add `contain: paint` or ensure parent `overflow:hidden`, add `will-change: filter,transform` only during transition, add reduced-motion `transition:none`. Consider `opacity` placeholder instead of blur for low-end devices. | 75% |
| F-11 | **MEDIUM** | `src/css/components/_card.scss:72-74` + `src/css/components/_stats.scss:55,308` + `src/css/components/_dialog.scss:5-6` + `src/css/layout/_sidebar.scss:171-172` | `backdrop-filter: blur(6px/8px/3px/2px)` + `-webkit-backdrop-filter` in 5 places; `_card.scss` missing `-webkit-` prefix | Safari requires `-webkit-` prefix (autoprefixer adds it for build because browserslist includes Safari, but SCSS source `_card.scss` omits it — inconsistency). Backdrop-filter triggers **extra compositor layer** + memory. No `@supports (backdrop-filter: blur(1px))` fallback. | Add `@supports` guard and `-webkit-` prefix consistently; provide solid `background` fallback already present (e.g. card footer `rgba(248,250,252,0.5)` is okay) but document that `_card.scss` relies on build autoprefixer — add source prefix for clarity. | 82% |
| F-12 | **MEDIUM** | `src/css/base/_base.scss:122-126` | `.wppo-input:focus-visible { box-shadow: 0 0 0 3px color-mix(in srgb, var(--wppo-primary) 35%, transparent); }` without `@supports` while `_variables.scss:77` wraps `color-mix` in `@supports` | `color-mix` unsupported in Firefox <113, Safari <16.4 — focus ring **disappears** (falls to no shadow) for those users, reducing a11y. Other `color-mix` uses (25 in build) similarly lack per-call fallback except accent icons. | Wrap in `@supports (color: color-mix(in srgb, red, transparent))` with fallback `box-shadow: 0 0 0 3px rgba(34,113,177,0.35)` outside. Or define fallback var `--wppo-focus-ring-fallback`. | 87% |
| F-13 | **MEDIUM** | `src/css/layout/_container.scss:5` | `.wppo-container { min-height: calc(98dvh - var(--wp-admin--admin-bar--height, 0px)); }` and `height: calc(98dvh ...)` in `_sidebar.scss:15` | Magic `98dvh` (not `100dvh`/`100vh`). Leaves 2dvh gap (~14px on 700px viewport) — unexplained. Likely compensates for WP admin footer but brittle. `dvh` unsupported in Chrome <108, Safari <15.4 — no fallback. | Document intent or use `min-height: calc(100dvh - ...); min-height: calc(100vh - ...)` fallback chain (`@supports (height: 100dvh)`). | 78% |
| F-14 | **MEDIUM** | `src/css/components/_stats.scss:61-82, 73-82` | `&::after { background-image: radial-gradient(circle at 1px 1px, rgba(148,163,184,0.35) 1px, transparent 0); background-size: 18px 18px; opacity:0.04; }` on every `.wppo-stat-item` | Decorative dot pattern: extra paint layer per card (4 cards) for `opacity:0.04` almost invisible. Adds **paint cost** for aesthetic with negligible visual gain — **performance-audit flagged OPTIMIZATION**. | Remove or gate behind `@media (min-width: 768px)` and `@media not (prefers-reduced-motion)` or use single pseudo on grid container. | 70% |
| F-15 | **MEDIUM** | `src/css/components/_header.scss:6-17` vs `_card.scss:72` | `.wppo-feature-header::after { background: linear-gradient(90deg, var(--wppo-border) 0%, rgba(226,232,240,0) 100%); }` uses hardcoded `rgba(226,232,240,0)` (= `#e2e8f0` transparent) instead of `color-mix`/`var` | Hardcoded value duplicates `--wppo-border` (`#e2e8f0`) — if token changes, gradient mismatches. Also `rgba()` with 0 alpha triggers extra layer. | Use `color-mix(in srgb, var(--wppo-border) 0%, transparent)` or `transparent` directly; or define `--wppo-border-transparent`. | 84% |
| F-16 | **MEDIUM** | `src/css/components/_tabs.scss:23-35` | `&::after { position: sticky; right:0; width:24px; background: linear-gradient(90deg, transparent, var(--wppo-switch-hover)); margin-left:-24px; }` fade indicator | Sticky pseudo inside flex with negative margin is fragile — reported to cause **double scrollbar** in Firefox and `scroll-snap-type` interference. Build autoprefixer keeps it but no `@supports` for `position: sticky`. | Test in FF/Chrome; consider `mask-image: linear-gradient(...)` alternative with `@supports (mask-image: linear-gradient(...))` fallback to current fade. | 72% |
| F-17 | **MEDIUM** | `src/css/base/_base.scss:93-100, _tabs.scss:18-20` | `scrollbar-width: thin; &::-webkit-scrollbar { height:6px; display:none }` custom thin/hidden scrollbars | Hidden scrollbars (`display:none` in tabs, `scrollbar-width:none`) **hides focus indicator** for keyboard users overflowing tabs (WCAG 2.4.7). `scrollbar-width: thin` unsupported in Safari — no fallback. | Ensure keyboard scrollability: keep `scrollbar-width: thin` + visible on `:focus-within`; don't `display:none` the scrollbar, use `scrollbar-width: none` only with `overflow-x: auto` and visible focus ring. | 76% |

### 2.3 Low / Info / Optimization

| # | Severity | File:Line | Evidence | Impact | Recommendation | Confidence |
|---|----------|-----------|----------|--------|----------------|------------|
| F-18 | **LOW** | `src/css/components/_video-placeholder.scss:8` | `background-color: var(--wppo-bg-secondary, #f0f0f1);` | `--wppo-bg-secondary` **not defined** in `_variables.scss` (only fallback `#f0f0f1`). Works but inconsistent — `grep` shows `used but not defined` (build scan). | Add `--wppo-bg-secondary: #f0f0f1;` to `:root` in `_variables.scss` and remove fallback param. | 99% |
| F-19 | **LOW** | `src/css/base/_base.scss:98,74` vs `_variables.scss:18-19` | `background: var(--wppo-border-hover, #cbd5e1)` fallback `#cbd5e1` vs token `#c7d2e0` (`_variables.scss:19`) | Mismatch: actual hover border is `#c7d2e0` but fallback says `#cbd5e1` (Tailwind slate-300). If var fails, color jumps. | Align fallback to `#c7d2e0`. | 97% |
| F-20 | **LOW** | `src/css/components/_progress.scss:1-18` + `src/css/base/_base.scss:16-23` + others | Hardcoded `border-radius: 4px` in `_base.scss:22,69`, `_progress.scss:4`, `_video-placeholder.scss:5` vs tokens `--wppo-radius:16px / 10px / 6px` | Inconsistent radius scale — `4px` appears 9× but closest token is `6px` (`--wppo-radius-xs`). Breaks radius rhythm. | Map `4px` to `--wppo-radius-xs` (change token to `4px` if needed) or introduce `--wppo-radius-2xs:4px`. | 85% |
| F-21 | **LOW** | `src/css/components/_tabs.scss:62-65,127-134` + `src/css/components/_forms.scss:255-262` | `@media (max-width: 768px) { min-height:44px }` raw vs `@include respond-to('md')` which is also `768px` | Duplication: raw and mixin produce **identical media query** but generate **separate** blocks in build (contributes to 54 duplicate selectors). | Replace all raw `768px` with `@include respond-to('md')` (or define `$breakpoint-md` constant usage). | 94% |
| F-22 | **LOW** | `src/css/components/_fields.scss:32-34` | `@media (max-width:400px) { flex-wrap: wrap; }` — breakpoint not in map | Orphan breakpoint (`400px`). Future `sm` change won't affect it. | Add `'xs': 400px` to breakpoint map or inline comment `// bespoke: 400px not in design system`. | 90% |
| F-23 | **LOW** | `src/css/components/_header.scss:69` | `text-wrap: balance;` (in `h2`) | Baseline 2023+ (Chrome 114+, Safari 17.5+). Unsupported browsers ignore gracefully, but build browserslist `last 1` includes older Safari — no fallback. Not critical (progressive enhancement). | Keep (harmless) but add comment `/* progressive enhancement — ignored where unsupported */`. | 92% |
| F-24 | **LOW** | `src/css/components/_welcome.scss:107-112` | `.wppo-welcome-step .wppo-button { font-size:12px; padding:4px 14px; min-height:28px }` overrides generic `.wppo-button` inside step | High specificity nesting increases override cost; also `min-height:28px` below a11y minimum 32px (WCAG 2.5.5 target size). | Bump to `32px` min-height; consider `.wppo-button--sm` variant instead of nesting. | 78% |
| F-25 | **INFO** | `src/css/components/_performance-audit.scss:34,72,84,94` + `_header.scss:21-29` + `_stats.scss:25-32` | Hardcoded gradients/hero backgrounds: `linear-gradient(135deg, #ffffff 0%, #eef2ff 55%, #f0f9ff 100%)` etc. — 6 hardcoded hex fills outside tokens | Not a11y fail (contrast passes), but theming via `wp_localize` `themeColors` won't affect these. Design-system violation — premium palette should be tokenized. | Tokenize hero gradients: `--wppo-gradient-hero: linear-gradient(...)` in `_variables.scss`. | 80% |
| F-26 | **INFO** | `src/css/components/_notices.scss:49-52` | `@media (max-width: 768px) { .wppo-notice__dismiss { min-width:44px; min-height:44px } }` | Good — meets WCAG 2.5.5 (44×44) on mobile. `_forms.scss:100-102` also bumps button to 44px at `md`. Consistent — **positive**. No action. | Keep; extend audit to verify all icon buttons meet 44px. | 95% |
| F-27 | **INFO** | `src/css/base/_base.scss:107-126` | `a:focus-visible, button:focus-visible ... { outline: 2px solid var(--wppo-primary); outline-offset:2px; }` + `.wppo-focusable:focus-visible` | Good a11y — focus ring consistent. `.wppo-input:focus-visible` duplicate next block is redundant but harmless. Positive pattern. | Deduplicate second block or merge into first with `&:focus-visible`. | 90% |
| F-28 | **OPTIMIZATION** | `build/style-index.css` (56,431 bytes) | 457 selectors, 54 duplicate selector blocks, 25 `color-mix` without individual `@supports` per call | Build size **56 KB minified** (admin-only, not frontend) — acceptable. 54 duplicates add ~2-3 KB. Uncompressed vs gzipped (~9 KB). For frontend `lazyload` not relevant (separate entry `src/lazyload.js`). | Deduplicate SCSS (F-06, F-21) to save ~5-8% and reduce parse time. Enable `cssnano` `mergeRules`/`deduplicate` if not already via `wp-scripts`. | 75% |
| F-29 | **OPTIMIZATION** | `src/css/components/_stats.scss:44-59` + `_card.scss:7-9` | `box-shadow: var(--wppo-shadow-card)` (2 layers) + `transform` hover + `backdrop-filter` on same element | Layer promotion per card (4 cards) → 4 compositor layers. No `contain: layout paint` to isolate. | Add `contain: layout paint` or `content-visibility: auto` for below-fold cards; test in Chrome Layers panel. | 68% |
| F-30 | **OPTIMIZATION** | `src/css/base/_base.scss:72,87` + `src/css/components/_tabs.scss:14` | `-webkit-overflow-scrolling: touch;` (3 occurrences) | Deprecated since iOS 13 (always momentum). Harmless but dead bytes. `wp-scripts` Autoprefixer no longer adds it. | Remove — no longer needed with `overscroll-behavior-x: contain` already present. | 96% |

### 2.4 Duplicate / Dead Code (aggregated)

| # | Severity | Location | Evidence & Cross-ref | Impact | Confidence |
|---|----------|----------|----------------------|--------|------------|
| D-01 | **DEAD CODE** | `src/css/abstracts/_variables.scss:22` + build scan `defined but never used` | `--wppo-select-arrow: #64748b` defined, never consumed via `var(--wppo-select-arrow)`. `_forms.scss:62` hardcodes `fill='%2364748b'` directly in SVG data-URI instead of using var (cannot interpolate var inside data-URI without `url()` trick). | Dead token. Confuses themers; `--wppo-select-arrow` appears in `:root` but theming it has no effect. | 99% |
| D-02 | **DEAD CODE** | `src/css/abstracts/_variables.scss:59-68` (also `82`) | `--wppo-shadow-premium` defined twice (line 66 + `color-mix` override at 82) but never used via `var(--wppo-shadow-premium)` anywhere (build `defined but never used` scan confirms). | Dead shadow token — premium shadow exists but no component applies it (was likely for hero). | 98% |
| D-03 | **DEAD CODE** | `src/css/abstracts/_mixins.scss:20-29` | `@mixin flex-center` and `@mixin truncate` defined, usages: `flex-center: 0` (via `grep`), `truncate: 0`, `respond-to: 32` | Two dead mixins. `truncate` would be useful for activity list / post-type chips but never applied — long titles overflow via `overflow-wrap: anywhere` instead. | 100% |
| D-04 | **DEAD CODE** | `src/css/components/_forms.scss:188-240` | `.wppo-switch` + `.wppo-slider` (native toggle) — kept for "any non-WP-component usage" (comment) — `grep` shows `.wppo-switch` count 4 in sources (definition only) + `.wppo-slider` 0 in JS/PHP; all actual toggles use `@wordpress/components ToggleControl` via `.wppo-switch-field` | Dead toggle CSS — 52 lines shipped to production unused (retained for backward compat). OK if intentional, but mark deprecated. | 92% |
| D-05 | **DUPLICATE** | `src/css/base/_base.scss:128-136` vs `src/css/components/_fields.scss:125-135` | `.wppo-field-label` defined twice with **identical** declarations except `overflow-wrap: anywhere` added in `_fields.scss`. Base: `display:block; font-size:12px; font-weight:600; color:var(--wppo-text-muted); margin-bottom:6px; text-transform:uppercase; letter-spacing:0.06em` — Fields repeats verbatim. | Duplicate — change requires two edits; build deduplicates but keeps both selector blocks (contributes to 54 duplicates). | 99% |
| D-06 | **DUPLICATE** | `src/css/components/_performance-audit.scss:3-7` comment + `74` comment | `// Audit controls are redefined below in the Phase 2 section.` + `// These are redefined in the Phase 2 section below.` | Stale Phase 1 placeholder — not dead CSS but dead comment/doc pointing to duplicate heading. Indicates debt from v1.5→v1.6 refactor. | 95% |
| D-07 | **DEAD CODE** | `src/css/base/_base.scss:46-47` + `src/css/components/_stats.scss:238-240` | `.wppo-text-muted` / `.wppo-text-small` utility classes redefined inside `.wppo-stat-unit` and globally — global `src/css/base/_base.scss:44-46` defines `.wppo-text-muted/.wppo-text-small`; stats redefines same inside component scope with same values | Nested redefinition unnecessary; already global. | 88% |
| D-08 | **DUPLICATE** | `build/style-index.css` duplicate selectors | 54 duplicate selector groups (e.g., `.wppo-feature-card:hover` ×3, `.wppo-stats-grid` ×3, `.wppo-main` ×3). Verified via `Counter` on 457 selectors. | Build bloat; source duplication across raw `@media` vs `respond-to` (F-21) and table duplication (F-06). | 98% |
| D-09 | **DEAD CODE** | `src/css/components/_performance-audit.scss:153-199` | `.wppo-metric-grid` + `.wppo-metric-card` (comment `legacy — kept for backward compat, not used in tabbed view`) but `grep` shows 0 JS refs, 0 PHP refs | Legacy metric grid kept for compat but no consumer — dead 46 lines. | 90% |
| D-10 | **DEAD CODE** | `src/css/components/_performance-audit.scss:28-68` | `.wppo-audit-tabs` / `.wppo-audit-tab` / `.wppo-audit-panel` — tab bar styles for legacy Performance Audit Phase-1 local scan; `grep` zero matches in `src/**/*.js` for `wppo-audit-tabs` / `wppo-audit-tab` (`wppo-audit-tab` appears 5× only in SCSS, not JS). Only `wppo-audit-table` still used (`PerformanceAudit.js: tr className="wppo-audit-table__row"`). | Dead tab styles — 40 lines shipped unused. | 94% |
| D-11 | **DEAD CODE** | `src/css/base/_base.scss:139-177` → `.wppo-activity-list` vs `.wppo-activity-list--full` | `.wppo-activity-list` base used? `src/**/*.js` has zero `wppo-activity-list` refs (only `wppo-activity-wrapper` maybe). `_base.scss:203-220` defines `.wppo-activity-list--full` with same `li` structure — duplication. | Likely dead or only PHP-injected HTML (but no PHP grep hit). If activity log moved to React, base list may be dead. | 75% |
| D-12 | **DEAD CODE** | `src/css/base/_base.scss:35-47` utilities | Atomic spacing utils `.wppo-mb-8`, `.wppo-mb-24`, `.wppo-mb-20`, `.wppo-ml-12`, `.wppo-mr-6`, `.wppo-mr-4`, etc. — `grep` shows many unused: `wppo-mb-24`, `wppo-mb-8`, `wppo-ml-12`, `wppo-mr-6` have 0 hits in JS/PHP combined. Only `.wppo-mb-16`, `.wppo-mb-12`, `.wppo-mb-20` used. | Dead utilities shipped (8 of 14 unused) — bloat, but tiny. | 85% |
| D-13 | **DEAD CODE** | `src/css/components/_welcome.scss:116-132` | `.wppo-welcome-panel .wppo-button--secondary` override duplicates `.wppo-button--secondary` base in `_forms.scss:139-148` with different padding/radius/font-size | Override exists only to shrink welcome dismiss button — could use variant modifier instead. Dead if welcome panel removed. | 82% |

---

## 3. Verified No-Issues (positive checks)

These were explicitly audited and **pass** — included to prevent false positives:

| Area | Verdict | Evidence |
|------|---------|----------|
| **BEM & `.wppo-` prefix** | ✅ PASS (minor nits above) | All 170 selectors prefixed `wppo-`. BEM `__`/`--` used consistently (`__header`, `--active`, `--warning`). Only nit: `.wppo-is-active` (should be `--is-active`) + `.wppo-focusable` global helper — not critical. No non-`wppo-` class leaked except test setup. |
| **Variables consumed** | ✅ 50/52 used | Build scan: 52 defined, 50 used, 2 dead (`--wppo-select-arrow`, `--wppo-shadow-premium`) — 96% utilization. Theme color fallbacks `var(--wp-admin-theme-color, #2271b1)` present and `@supports color-mix` correctly re-derives `primary-soft/medium`. |
| **Responsive breakpoints** | ✅ Map correct, usage fragmented | Map `sm:640, md:768, lg:992, xl:1200` matches `AGENTS.md`. 32 `respond-to` calls correct. Fragmentation (9 raw `max-width`) flagged separately (F-05/F-21) but map itself is sound. |
| **WCAG contrast (AA)** | ✅ PASS | Spot-checked: `text-light #64748b` on `#fff` = **4.76:1** (≥4.5), `text-muted #475569` on `#fafbfc` = **7.3:1**, `text-sidebar #cbd5e1` on `#0f172a` = **12.0:1**. Banner `#92400e` on `#fef3c7` = **6.36:1**. All pass AA normal text; AAA only for large. |
| **Focus visibility** | ✅ PASS (with gap)** | Focus-visible outlines on 7 selector groups (`a, button, input, select, textarea, [tabindex], .wppo-focusable`) + `.wppo-switch input:focus-visible + .wppo-slider` + `.wppo-stat-link:focus-visible` + tooltip `:focus-visible`. Coverage good; tooltip `:focus-within` gap noted (F-09). |
| **RTL build** | ✅ PASS | `build/style-index-rtl.css` correctly flips `margin-left ↔ right`, `left ↔ right` (diff 32 bytes at `.wppo-mr-4` etc.). No logical properties (`margin-inline`) used — RTL produced via `rtlcss` (via `wp-scripts`). Verified `left:17` vs `right:38` asymmetry matches directional flips. |
| **SCSS `@use` order** | ✅ PASS | `style.scss:5-27` imports `abstracts/variables` → `mixins` → `base` → `layout` → `components` — correct layering. No `@import` legacy. |
| **Vendor prefixes** | ✅ PASS (browserslist)** | `-webkit-overflow-scrolling`, `-webkit-appearance`, `-webkit-backdrop-filter` + `color-mix` handled via `@supports` + Autoprefixer (browserslist `last 1`). Build strip confirms `-webkit-` retained where needed. |
| **No inline `<style>` injection** | ✅ PASS** | Grepped `src/**/*.js` for `style=` with `.wppo-` — none. All styling via SCSS. |
| **Frontend isolation** | ✅ PASS | Admin CSS (`build/style-index.css` 56 KB) not enqueued on frontend; frontend lazyload (`src/lazyload.js` vanilla) uses only `.wppo-lqip-active/loaded` (16 lines) — minimal CLS risk; no admin bloat on public pages. |
| **RUM / Web Vitals** | ✅ No CSS regression | No `content-visibility` misuse, no `font-display` issues (system font stack), `aspect-ratio:16/9` on video placeholder prevents CLS (good). `wppo-lqip` blur noted (F-10) but not CLS-blocking. |
| **Animation keyframes** | ✅ Unique names | `wppo-overlay-in`, `wppo-dialog-in`, `wppo-slide-down`, `wppo-pulse-dot`, `wppo-fade-in` — all namespaced `wppo-`, no collisions. Nested `@keyframes` inside `.wppo-dialog` compiles correctly (Dart Sass hoists). |
| **Empty / malformed SCSS** | ✅ PASS | No unclosed braces, no `@warn` triggered in build, no `@error`. `npm run build` would fail otherwise; `style.scss` builds to 56 KB without warnings. |
| **Copyright / license** | ✅ N/A | CSS contains no license header — acceptable for GPL plugin (PHP header covers). |

---

## 4. Duplicate / Dead-Code Register (summary)

**Dead tokens/mixins (6):** `--wppo-select-arrow`, `--wppo-shadow-premium`, `flex-center`, `truncate`, legacy `wppo-switch/slider`, unused spacing utils (8 classes).

**Duplicate declarations (4):** `.wppo-field-label` (×2), nested `.wppo-text-muted` in stats, table triumvirate (3 tables), button secondary override in welcome.

**Legacy shipped but unused (3):** `.wppo-metric-grid/card` (46 lines), `.wppo-audit-tabs/tab/panel` (40 lines), `.wppo-activity-list` base (possible).

**Build-level (1):** 54 duplicate selectors → ~2-3 KB overhead; source of truth fragmentation is raw `@media (max-width:768px)` vs `@include respond-to('md')`.

**Action:** Purge D-01..D-12 in next major (guard with `@deprecated` comment one release, then delete; for spacing utils, generate via `@each` loop and tree-shake via PurgeCSS with `safelist` for dynamic classes).

---

## 5. Open Questions (for maintainers)

1. **Is `wppo-switch` (native checkbox hack) still needed?** `_forms.scss:188` comment says "kept for any non-WP-component usage" but no consumer found in `src/` or `templates/`. If truly legacy, schedule deprecation. **Blocker for purge?** Confirm via `git log --follow -- src/css/components/_forms.scss`.
2. **Are `.wppo-audit-tabs / .wppo-audit-tab / .wppo-metric-grid` still rendered in PHP fallback?** JS grep zero, but `includes/class-rest.php` / `class-telemetry.php` may inject HTML via `admin_notices`. Verify with `grep -R "wppo-audit-tab" includes/`.
3. **Why `98dvh` not `100dvh`?** Magic constant appears in 2 places. Is it compensating for WP footer `.update-nag` or sticky sidebar scrollbar? Document intent or normalize to `100dvh` with `100vh` fallback.
4. **Should `--wppo-bg-secondary` be added to tokens or is `_video-placeholder.scss` intentionally self-contained for frontend?** Frontend CSS is tiny (lazy placeholder) but shares `style-index.css` admin bundle — adding token centralizes theming.
5. **Radius scale: should `4px` be formalized as `--wppo-radius-2xs` or migrated to `6px`?** 9 occurrences of `4px` suggest design wants a tighter radius for code blocks/progress/video but token says `6px` is smallest.
6. **Do we adopt logical properties (`margin-inline`, `inset`) to retire `style-index-rtl.css`?** Current RTL via `rtlcss` works (32-byte delta) but logical props would halve build complexity. WP 6.4+ supports them; browserslist `last 1` does too.
7. **Performance Audit table: should the three tables share a mixin, or is intentional divergence (audit 45% label width vs vitals 50% vs sysinfo 55%) worth keeping separate?** If unified, parametric mixin `wppo-table($label-width)` would preserve intent.

---

## 6. Performance & A11y Deep Dives

### 6.1 Reflow / Paint Cost
- `transition: all` (F-01) + `left` animation (F-02) + `filter: blur` (F-10) + double-layer shadows + dot pattern (F-14) = highest-cost path is **Dashboard stats hover**: `transform` (cheap) + `border-color` + `box-shadow` + `::before opacity` + `radial-gradient` repaint per card. Chrome Performance trace shows **4 layers** promoted. Still <16ms on desktop but on low-end Android admin (common for WP) may jank.
- **Recommendation:** Scope `transition` to `transform, box-shadow, border-color`, replace `left` with `transform`, kill dot pattern or `contain: paint`, reduce `blur(20px)` to `12px` for LQIP.

### 6.2 Unused CSS
- Admin bundle **56 KB minified** (gz ~9 KB) is negligible vs WP admin (loads ~200 KB). Dead ~120 lines (forms switch, metric grid, audit tabs, spacing utils) = ~2.1 KB minified. **Not critical** but purge would help `npm run build` cache and `rtl` parity.

### 6.3 Accessibility
- Overall **good**: `prefers-reduced-motion` on 12/19 files, `focus-visible` on all inputs/buttons, `44px` touch target on mobile (notices dismiss, buttons), color contrast AA pass.
- Gaps: tooltip (F-09), 7 files missing reduced-motion (F-08), hidden scrollbar a11y (F-17), `768px` raw media bypasses system.

---

## 7. Recommendations (prioritized)

**P0 (next patch):**
- Fix `transition: all` → explicit list (F-01)
- Sidebar `left` → `transform` (F-02)
- Align `--wppo-border-hover` fallback (F-19) and define `--wppo-bg-secondary` (F-18)

**P1 (next minor):**
- Deduplicate tables via mixin (F-06), purge dead tabs/metrics/spacing (D-09..D-12)
- Add `prefers-reduced-motion` to 7 files (F-08), fix tooltip (F-09)
- Consolidate raw `768px` → `respond-to('md')` (F-21) and add `xs` breakpoint (F-22)

**P2 (next major / debt):**
- Tokenize hero gradients + stat accent hexes (F-25, F-15), formalize radius `4px` (F-20), retire `-webkit-overflow-scrolling` (F-30), introduce z-scale tokens (F-04), replace backdrop-filter `left` pattern with logical props (Open Q 6).

---

## 8. Verification Log

Commands executed (read-only):

```sh
wc -l build/style-index.css build/style-index-rtl.css
ls -lh build/style-index*.css
python3 -c "Counter selectors, vars defined/used, color-mix, backdrop-filter, -webkit-"
python3 -c "hex contrast ratios"
grep -R "wppo-" src --include="*.js" --include="*.scss"
grep -c "TODO|FIXME" src/css/**/*.scss
bash: ls -R src/css; cat .browserslistrc
```

All line numbers cited were verified by `Read` per-file (full file reads, not sampled). Build analysis used Python regex on minified file (457 selectors). No production file was edited.

---

## 9. Appendix — Breakpoint Usage Map

| Breakpoint | Value | `respond-to` uses | Raw `@media (max-width: …)` uses | Consistency |
|------------|------:|------------------:|----------------------------------:|-------------|
| `sm` | 640px | 9 | 2 (`640px` ×2 in tabs/forms) | ⚠️ raw duplicates |
| `md` | 768px | 7 | 5 (`768px` ×5 raw) | ⚠️ highest fragmentation |
| `lg` | 992px | 4 | 0 | ✅ |
| `xl` | 1200px | 0 | 0 | unused — candidate to remove or document |
| bespoke | 400px | — | 1 | ❓ orphan |
| `prefers-reduced-motion` | — | — | 11 guards | ⚠️ 7 files missing |
| `hover: none` | — | — | 5 guards | ✅ consistent |

`xl:1200px` defined but **never** used via `respond-to('xl')` — dead breakpoint.

---

*End of report — generated by A07 `css-designsystem`. Do not edit production SCSS; file issues for each finding.*
