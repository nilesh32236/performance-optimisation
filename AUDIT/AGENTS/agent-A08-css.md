# Agent A08 — CSS/SCSS Specialist Audit

**Base:** `master@31fffc61`  
**Mode:** Audit-only — no production code modified  
**Date:** 2026-08-28  
**Auditor:** Agent A08 (CSS/SCSS)  
**Scope:** 19 SCSS sources + 1 committed build artifact (20 assigned files)

---

## Files Reviewed

| # | File | Lines | Role |
|---|------|------:|------|
| 1 | `src/css/abstracts/_variables.scss` | 85 | Design tokens (`:root` custom props, `@supports color-mix` override) |
| 2 | `src/css/abstracts/_mixins.scss` | 35 | Breakpoint map + `respond-to`, `flex-center`, `truncate` |
| 3 | `src/css/base/_base.scss` | 242 | Reset, utilities, code block, responsive table wrap, focus, activity list |
| 4 | `src/css/components/_card.scss` | 105 | `.wppo-feature-card` BEM block + `.wppo-danger-zone` |
| 5 | `src/css/components/_dialog.scss` | 81 | Modal overlay + dialog + keyframes |
| 6 | `src/css/components/_fields.scss` | 178 | Switch field, field nest, checkbox options, cleanup row |
| 7 | `src/css/components/_forms.scss` | 211 | Inputs/selects/textarea + `.wppo-button` (4 variants + `--sm`/`--full`) |
| 8 | `src/css/components/_header.scss` | 168 | `.wppo-feature-header`, `.wppo-stat-hero`, `.wppo-section-title` |
| 9 | `src/css/components/_lazy-placeholder.scss` | 16 | LQIP blur/scale (`.wppo-lqip-active`, `.wppo-lqip-loaded`) |
| 10 | `src/css/components/_notices.scss` | 104 | `.wppo-notice` (4 variants) + `.wppo-litespeed-banner` |
| 11 | `src/css/components/_performance-audit.scss` | 953 | Audit tabs/tables/metric/status/suggestions/PageSpeed/gauges/trends |
| 12 | `src/css/components/_progress.scss` | 29 | `.wppo-progress-bar` + `.wppo-progress-section` |
| 13 | `src/css/components/_stats.scss` | 371 | Stats grid, `.wppo-stat-item` (4 accent variants), banner, health dot |
| 14 | `src/css/components/_tabs.scss` | 150 | `.wppo-sub-tabs` scrollable bar + `.wppo-post-type-chip` |
| 15 | `src/css/components/_tooltip.scss` | 73 | Tooltip container/icon/content (absolute, arrow) |
| 16 | `src/css/components/_video-placeholder.scss` | 61 | Video placeholder + play button |
| 17 | `src/css/components/_welcome.scss` | 148 | Welcome panel + step wizard |
| 18 | `src/css/layout/_container.scss` | 70 | `.wppo-container`, `.wppo-content`, `.wppo-main`, fade-in |
| 19 | `src/css/layout/_sidebar.scss` | 261 | Sticky desktop + fixed mobile drawer + overlay + mobile header |
| 20 | `src/css/style.scss` | 27 | Entry manifest (`@use` order) |
| 21 | `build/style-index.css` | 1 (minified) | Production build — 56,072 bytes, 510 selectors (418 distinct, 73 duplicate groups), 49 distinct `--wppo-*` tokens |

## Lines Reviewed

- **SCSS sources:** 3,368 lines (excl. `build/style-index.css` minified line) — total repo CSS `src/css` + manifest = **3,368**; with `style.scss` = **3,395**
- **Grand total with build:** 3,369 logical lines (3,368 SCSS + 1 minified CSS); verified via `wc -l` (see `3369 total` above, `build/style-index.css` counted as 1)
- **Build artifact byte size:** 56,072 bytes, `529` closing braces (`}`) — single-line minified output from `wp-scripts build`
- **Tooling:** `Read` for all 21 files (full content), `Grep` for `@include`/`@mixin`/`respond-to`/`var(--wppo-)`/`@media`/`!important`/`#hex`, `bash` `wc -l`/`wc -c`/`Counter` for duplicate-selector analysis, `grep -roh var(--wppo-*)` for token coverage

---

## Methodology

- Read every assigned file line-by-line via `Read`; verified line counts with `wc -l`.
- Ran `Grep` (`respond-to`, `@mixin`/`@include`, `var(--wppo-*)`, `@media`, `!important`, `#hex`/`rgb`/`hsl`) to cross-check breakpoint usage, unused mixins/tokens, hard-coded colors, `!important` debt, and raw `@media` fragmentation.
- Ran `bash` + Python `Counter` on `build/style-index.css` to enumerate duplicate selectors (510 total, 418 distinct, 73 groups duplicated) and searched for `xl` breakpoint (`1200px` absent in build).
- Checked `@use` vs legacy `@import`, BEM `__`/`--` patterns, `prefers-reduced-motion`/`hover:none` guards, `focus-visible` coverage, `backdrop-filter`/`-webkit-` prefixing, and `style.scss` import order.

---

## Findings

> Format: `File:Line` | Category | Severity | Description | Evidence / Recommendation

### 1. Variables / Design Tokens

| # | File:Line | Category | Severity | Finding |
|---|-----------|----------|----------|---------|
| V-01 | `src/css/abstracts/_variables.scss:22` | Dead token | **LOW** | `--wppo-select-arrow: #64748b` is **never referenced via `var()`** (`grep -r "var(--wppo-select-arrow" src/css → 0 hits`). Intentionally documented in comment (line 21: "retained for theming but not used via var() because data-URI SVG cannot interpolate CSS vars") and hard-coded as `fill='%2364748b'` in `_forms.scss:62`. Not a bug — but adds token-table noise. Keep with comment (already done) or add Stylelint `custom-property-no-missing-var-function: ignore` annotation. |
| V-02 | `src/css/abstracts/_variables.scss:67` | Dead token | **LOW** | `--wppo-shadow-premium` defined but **never consumed via `var()`** in `src/css` (grep 0 hits). Comment at line 66 says "Retained for future premium hero/billing surfaces — currently unused but part of P5 design-system token set. See AUDIT/AGENTS/agent-A07-css.md D-02". Present in build as custom prop (dead bytes). Low impact (~80 bytes) but inflates token surface. Recommendation: keep until P5 premium surfaces ship; gate with `/* stylelint-disable custom-property-no-missing-var-function */` or remove if still unused post-P5. |
| V-03 | `src/css/abstracts/_variables.scss:5-6,78-82` | Token duplication | **INFO** | `--wppo-primary-soft` (rgba `8%`) / `--wppo-primary-medium` (`15%`) defined as `rgba(34,113,177,0.08)` fallback, then overridden inside `@supports (color: color-mix…)` using `color-mix(in srgb, var(--wppo-primary) 8%, transparent)`. Correct progressive enhancement; fallback uses hard-coded WP admin blue `#2271b1` not `var(--wppo-primary)` — intentional because `var()` not valid inside `rgba()` legacy path. Verified sound. No change. |
| V-04 | `src/css/abstracts/_variables.scss:52-56` | Token usage | **INFO** | `--wppo-accent-cache/files/db/images` correctly consumed 3× each in `_stats.scss:95-168` and `--wppo-nest-indent` consumed 2× in `_fields.scss`. All other tokens have ≥1 consumer except V-01/V-02 and `--wppo-bg-secondary` (only in `_video-placeholder.scss:8` as `var(--wppo-bg-secondary, #f0f0f1)` — token **not defined** in `_variables.scss`; consumed via fallback. Minor inconsistency: either define `--wppo-bg-secondary` in `_variables.scss` or document as external WP token. |
| V-05 | `src/css/components/_card.scss:74,98-99` etc. | Fallback style | **LOW** | Many `var(--wppo-*, fallback)` use inline hex fallbacks (`var(--wppo-border, #f1f5f9)`, `var(--wppo-danger, #ef4444)`, `var(--wppo-bg-card-surface, #fafbfc)`). Redundant when `:root` always defines these tokens; fallbacks bloat bytes and risk drift if `--wppo-border` value changes but fallback stays stale. Low risk (admin-only SPA always loads `:root`), but recommend linting `declaration-property-value-no-unknown` or removing fallbacks where `:root` guarantees presence. |

### 2. Mixins / Breakpoint System

| # | File:Line | Category | Severity | Finding |
|---|-----------|----------|----------|---------|
| M-01 | `src/css/abstracts/_mixins.scss:20-27` | Dead code | **LOW** | `@mixin flex-center` (lines 20-27) defined, **0 `@include` usages** (`grep -rn "flex-center" src/css → only definition`). Already annotated with retention comment (lines 21-23, `@since NEXT`). Verified via grep. Impact negligible (7 lines, not emitted to build). Either keep as documented library mixin or remove post-P5 audit. |
| M-02 | `src/css/abstracts/_mixins.scss:29-35` | Dead code | **LOW** | `@mixin truncate` (lines 29-35) defined, **0 usages**. Same retention comment referencing `AUDIT/DUPLICATE-CODE.md X-04`. Would be useful for activity list / post-type chips (long titles currently use `overflow-wrap: anywhere` + `word-break: break-word`). Keep or apply where truncation desired. |
| M-03 | `src/css/abstracts/_mixins.scss:3-18` | Architecture | **MEDIUM** | `respond-to` is **desktop-first `max-width` only**; no `min-width`/`range` companion. Forces authors to write desktop styles first then override downward, which is inverse to modern mobile-first and WP admin responsive patterns. Consumers work around with raw `@media (max-width: 400px)` (`_fields.scss:33`) that bypasses the map. Recommend adding companion `@mixin respond-from($bp)` (`min-width`) or renaming current to `respond-down` and introducing `respond-up`. Enforce via Stylelint `at-rule-disallowed-list: ["@media"]` except in `mixins.scss`. |
| M-04 | `src/css/abstracts/_mixins.scss:8` | Dead breakpoint | **LOW** | Breakpoint `'xl': 1200px` defined in map but **never invoked** via `@include respond-to('xl')` (grep `respond-to.*xl` → 0 hits; `grep -c 1200px build/style-index.css → 0`). Map entry is dead (4 bytes emitted only as SCSS source, not CSS). Either document as "reserved for future 1200px layout" or remove to avoid confusion. Total `respond-to` calls: 32 active (sm: 14, md: 10, lg: 6, xl: 0, bespoke 400px: 1 raw). |
| M-05 | `src/css/abstracts/_mixins.scss:3` | Import style | **INFO** | Uses `@use 'sass:map'` + `map.get` — correct modern Dart Sass. All consumers use `@use '../abstracts/mixins' as *` (15 files). `style.scss` also `@use 'abstracts/mixins'` without `as *` (side-effect import only to ensure mixins file is compiled). No `@import` legacy — clean. |
| M-06 | `src/css/components/_fields.scss:33` | Breakpoint fragmentation | **LOW** | Bespoke `@media (max-width: 400px) { flex-wrap: wrap }` on `.wppo-switch-field` bypasses the 4-step map. Comment correctly documents this as intentional (line 32: "Bespoke 400px breakpoint — not in design-system map (see A07 F-22)"). Single occurrence, justified for narrow toggle wrapping. Would ideally be `xs: 400px` in map if reused elsewhere; as one-off, current approach is acceptable. |

### 3. Breakpoints / Responsive

| # | File:Line | Category | Severity | Finding |
|---|-----------|----------|----------|---------|
| R-01 | `AGENTS.md:171` vs `src/css/abstracts/_mixins.scss:4-9` | Breakpoint contract | **PASS** | Map values `sm:640px, md:768px, lg:992px, xl:1200px` exactly match `AGENTS.md` contract ("SCSS breakpoints: sm (640px), md (768px), lg (992px), xl (1200px) via respond-to() mixin"). No drift. |
| R-02 | `build/style-index.css:1` | Build output | **INFO** | `1200px` absent from minified build — confirms `xl` truly unused downstream. `640px` × ~14 media blocks, `768px` × ~10, `992px` × ~6 emitted. No `xl` media query bloat. |
| R-03 | `src/css/components/_fields.scss:33`, `src/css/base/_base.scss:164,170` | Raw `@media` | **INFO** | Only one raw `max-width` (`400px`) plus `prefers-reduced-motion: reduce` (7 occurrences) and `hover: none` (4 occurrences). No fragmented `640/768/992` raw queries — all go through `respond-to`. This is an improvement over A07-era raw `768px` duplicates (F-21 fixed). Verified: `grep -rn "@media (max-width:" src/css` → only `mixins.scss:12` + `fields.scss:33`. |
| R-04 | `src/css/layout/_container.scss:14,38,44` | Responsive | **PASS** | `.wppo-container` switches to `flex-direction: column` at `lg` (992px), `.wppo-main` padding scales `32px → 20px → 16px` at `md`→`sm` with `env(safe-area-inset-*)` via `max()` — correct for iOS notch. Uses `min-width: 0` and `box-sizing: border-box` to prevent flex overflow. |
| R-05 | `src/css/components/_performance-audit.scss:439-451` | Responsive | **LOW** | `.wppo-audit-overview` has **two identical `md` and `lg` blocks** both setting `grid-template-columns: repeat(2,1fr)` (lines 439 `lg` + 443 `md`). `lg` (992px) is wider than `md` (768px); with `max-width` semantics, `lg` fires first (992) then `md` overwrites at 768, but declarations are identical so the `lg` block is redundant. Merge to single `@include respond-to('lg')` or keep `lg` alone (since `lg` already covers `md` range for `max-width`). Saves one media block / duplicate selector group. |
| R-06 | `src/css/components/_stats.scss:34-40` | Responsive | **LOW** | `.wppo-grid-2-col` has `@include respond-to('md')` (line 34) and `@include respond-to('lg')` (line 38) both emitting `grid-template-columns: 1fr` — identical output, order `md` then `lg` in source but `lg` is wider so `max-width:992px` should precede `max-width:768px` to cascade correctly. Current source order is `md` (768) then `lg` (992) — **inverted** for `max-width` (should be descending: lg → md → sm). In a `max-width` system, wider breakpoints must come first. Fix order to `lg` then `md` (or deduplicate to `lg` only since both collapse to 1 col). Functional today because both rules identical, but fragile if they diverge. |
| R-07 | `src/css/layout/_sidebar.scss:18-31` | Responsive / Perf | **MEDIUM** | Mobile drawer uses `position: fixed; left: calc(-1 * var(--wppo-sidebar-width))` with `&.wppo-sidebar--mobile-open { left: 0 }` and `transition: var(--wppo-transition)` on `.wppo-sidebar`. Animating `left` triggers **layout/reflow per frame** (not compositor-only). Should use `transform: translateX(-100%)` → `translateX(0)` with `will-change: transform`. Already flagged as A07 F-02 HIGH (95% confidence) — persists on current base. Performance impact on low-end devices for sidebar toggle. |
| R-08 | `src/css/components/_tabs.scss:10-37` | A11y / UX | **INFO** | `.wppo-sub-tabs` horizontally scrollable with `scroll-snap-type: x proximity`, `scrollbar-width: none`, sticky fade `::after`, and `prefers-reduced-motion: reduce { scroll-behavior: auto }` — well-implemented. Min-height `44px` at `md` for touch targets (line 64) correct. |
| R-09 | `src/css/components/_stats.scss:193,53-55` | Responsive | **INFO** | `.wppo-stat-value` uses `clamp(24px, 6vw, 36px)` → `clamp(24px, 7vw, 30px)` at `sm` — fluid scaling correct; background `rgba(255,255,255,0.96)` + `backdrop-filter: blur(8px)` is inner-card polish, not layout-breaking. |

### 4. BEM / Naming

| # | File:Line | Category | Severity | Finding |
|---|-----------|----------|----------|---------|
| B-01 | `src/css/components/_stats.scss:113-295` | BEM convention | **LOW** | `.wppo-stat-item` block uses **descendant selectors** (`.wppo-stat-header`, `.wppo-stat-label`, `.wppo-stat-icon`, `.wppo-stat-value`, `.wppo-stat-unit`, `.wppo-stat-footer`, `.wppo-stat-link`) without `&__` element syntax. E.g. line 113 `.wppo-stat-header` nested inside `.wppo-stat-item { .wppo-stat-header {…} }` compiles to `.wppo-stat-item .wppo-stat-header` (descendant) not `.wppo-stat-item__header` (BEM element). Same pattern for ~7 elements. Not a build error — but diverges from strict BEM (`__`/`--`) used elsewhere (`.wppo-feature-card__body`, `.wppo-audit-table__label`). Either adopt `&__header` inside block or document as "BEM-like but descendant for stat composite" (AGENTS.md says "BEM-like naming" — so downgraded to LOW). |
| B-02 | `src/css/base/_base.scss:27-48` | Utility helpers | **INFO** | Utilities `.wppo-mt-8`..`wppo-mt-40`, `.wppo-mb-*`, `.wppo-mr-*`, `.wppo-ml-*`, `.wppo-text-small`, `.wppo-text-13`, `.wppo-flex-gap-12` are **non-BEM helpers** but intentionally so (tail utility layer). All prefixed `wppo-`. `.wppo-text-small` and `.wppo-text-13` both `font-size: 13px` duplicate — `.wppo-text-13` is alias for legacy consumers (comment line 45). Documented, acceptable. |
| B-03 | `src/css/components/_performance-audit.scss:205-231` etc. | BEM array | **INFO** | Status badges use `.wppo-status-badge--good` / `--needs_improvement` / `--poor` modifiers correctly. Suggestion cards use `--good`/`--needs_improvement`/`--poor` left-border modifiers. Overview cards `__label`/`__value`/`__status` consistent. No flat-class violations. |
| B-04 | `src/css/base/_base.scss:145-180` | Nesting depth | **INFO** | `.wppo-activity-list li { .wppo-activity-text {…} }` and `&__info` misuse inside `.wppo-log-pagination` (`&__info` inside flex parent but not nested under `&`? Actually `&__info` at line 238 is inside `.wppo-log-pagination` with `&` so compiles to `.wppo-log-pagination__info` — correct BEM). Activity list nesting is 2-deep, within stylelint `max-nesting-depth: 3` if enforced. |
| B-05 | `src/css/layout/_sidebar.scss:137-148` | BEM modifiers | **INFO** | `&.wppo-is-active` on sidebar buttons is **state modifier without BEM `--`** — uses `.wppo-is-active` utility state class (JS toggled). Consistent with WP admin conventions (`is-active`), not strictly BEM but documented pattern. No conflict. |

### 5. A11y (Accessibility)

| # | File:Line | Category | Severity | Finding |
|---|-----------|----------|----------|---------|
| A-01 | `src/css/base/_base.scss:108-127` | Focus visible | **PASS** | Global `a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible, [tabindex]:focus-visible, .wppo-focusable:focus-visible` → `outline: 2px solid var(--wppo-primary); outline-offset: 2px` plus `.wppo-input:focus-visible` with `box-shadow: 0 0 0 3px color-mix(…35%…)` — comprehensive. `.wppo-stat-link:focus-visible`, `.wppo-sub-tab:focus-visible`, `.wppo-sidebar nav button:focus-visible`, `.wppo-video-play-btn:focus-visible` all present. |
| A-02 | `src/css/components/_forms.scss:99-101` | Touch target | **PASS** | `.wppo-button` switches to `min-height: 44px` at `md` (768px) for WCAG 2.5.5 touch target; `--sm` variant also lifts to 44px at `md`/`sm`. Correct. |
| A-03 | `src/css/components/_notices.scss:51-54` | Touch target | **PASS** | `.wppo-notice__dismiss` expands from `32px` → `44px` at `md` for mobile dismiss hit-area. Good. |
| A-04 | `src/css/components/_tooltip.scss:5-26` | Keyboard a11y | **MEDIUM** | Container exposes tooltip on `:hover` + `:focus` + `:focus-visible` + `:focus-within` (lines 12-15) — good. But `cursor: help` on `.wppo-tooltip-container` (line 10) is a `<span>`-like wrapper — ensure it has `tabindex="0"` in JSX (outside CSS scope) otherwise `focus` never fires. CSS also lacks `aria-describedby` wiring (JS concern). Opacity/visibility transition uses `pointer-events: none` (correct to avoid trapping). Verify in JS that tooltip trigger is focusable and has `role="tooltip"` + `aria-describedby`. CSS side PASS conditional on JS. |
| A-05 | `src/css/components/_tooltip.scss:70-72` | Reduced motion | **PASS** | Tooltip content has `@media (prefers-reduced-motion: reduce) { transition: none }` — correct. |
| A-06 | `src/css/components/_lazy-placeholder.scss:1-16` | Reduced motion | **LOW** | LQIP blur/scale transitions (`transition: filter 0.4s ease-out, transform 0.4s ease-out` line 15) have **no `prefers-reduced-motion: reduce` guard**. Users with vestibular disorders see blur animation on every image load. Add `@media (prefers-reduced-motion: reduce) { .wppo-lqip-loaded { transition: none } }` or set `--wppo-lqip-transition-duration: 0s` under that media query. |
| A-07 | `src/css/components/_video-placeholder.scss:44-46,59-60` | Reduced motion | **LOW** | `.wppo-video-play-btn:hover { transform: scale(1.1) }` and `.wppo-play-btn-bg { transition: fill 0.2s ease }` have no reduced-motion guard. Add `@media (prefers-reduced-motion: reduce) { .wppo-video-play-btn { transition: none } &:hover { transform: translate(-50%,-50%) } }`. Low severity (small scale). |
| A-08 | `src/css/components/_performance-audit.scss:461-478,634-707` | Reduced motion | **INFO** | Overview card `::before` opacity transition (line 473) and suggestion card `transition: var(--wppo-transition)` (line 643) are not guarded individually; but global `prefers-reduced-motion` not present in this file. However, motion is subtle (opacity only). Consider adding file-level `@media (prefers-reduced-motion: reduce) { [class*="wppo-"] { transition: none } }` or per-component guard. Existing guards in `_card.scss:28`, `_container.scss:66`, `_sidebar.scss:256`, `_fields.scss:97`, `_forms.scss:198`, `_dialog.scss:14,44`, `_progress.scss:14`, `_stats.scss:367`, `_tabs.scss:39` — good coverage elsewhere. |
| A-09 | `src/css/components/_dialog.scss:1-17` | A11y / Focus trap | **INFO** | Overlay uses `position: fixed; inset: 0; z-index: 9999; display:flex; align:center; justify:center; padding:20px` + backdrop blur. No `prefers-reduced-motion` for `wppo-overlay-in` beyond animation:none (line 15) — correct. Dialog lacks `max-height` + `overflow-y: auto` for small viewports (content could overflow). Add `max-height: calc(100dvh - 40px); overflow-y: auto` to `.wppo-dialog` for VH safety. |
| A-10 | `src/css/components/_progress.scss:10` | Reduced motion | **PASS** | `.wppo-progress-bar__fill` respects `@media (prefers-reduced-motion: reduce) { transition: none }` (lines 14-16). Correct. |
| A-11 | `src/css/base/_base.scss:83-106` | A11y | **PASS** | `.wppo-responsive-table-wrap` has `overflow-x: auto; overscroll-behavior-x: contain; scrollbar-width: thin; -webkit-overflow-scrolling: touch` and `::-webkit-scrollbar-thumb` — accessible scroll container. Tables use `min-width: 420px` to force horizontal scroll rather than crushing. |

### 6. Duplication / Dead Code

| # | File:Line | Category | Severity | Finding |
|---|-----------|----------|----------|---------|
| D-01 | `src/css/components/_performance-audit.scss:76-147,301-332,821-885` | Duplication | **MEDIUM** | Three near-identical table blocks: `.wppo-audit-table` (76-147), `.wppo-sysinfo-table` (301-332), `.wppo-vitals-table` (821-885) share `width:100%; border-collapse:collapse; font-size`, `thead tr { background: var(--wppo-bg-card-surface); border-bottom:2px solid var(--wppo-border) }`, `thead th { padding:12px; text-align:left; font-weight:600; color: var(--wppo-text-muted) }`, `__row { border-bottom:1px solid var(--wppo-border); &:hover { background: var(--wppo-bg-card-surface) } }`. ~180 lines of boilerplate. Recommendation: extract `@mixin wppo-table($cell-pad:12px)` and keep only column-width overrides per variant. Would shave ~70 lines and reduce maintenance drift. Already flagged A07 F-06 (85% confidence). |
| D-02 | `src/css/base/_base.scss:27-43` vs `src/css/components/_stats.scss` | Tokens | **INFO** | Spacing utilities (`.wppo-mt-*` 8-40, `.wppo-mb-*` etc.) are 17 one-liners. Could be generated via Sass `@each` loop over `$spacings` map, but explicit is readable for 17 entries. Keep. |
| D-03 | `src/css/abstracts/_mixins.scss:20-35` | Dead code | **LOW** | `flex-center` + `truncate` dead (M-01/M-02). Already documented as retained library mixins with `@since NEXT`. No build bloat. |
| D-04 | `build/style-index.css:1` | Build duplication | **LOW** | 73 duplicate selector groups (510 total selectors, 418 distinct) — top duplicates: `.wppo-audit-overview` 4×, `.wppo-main`/`.wppo-sidebar`/`.wppo-feature-card:hover`/`.wppo-stats-grid`/`.wppo-grid-2-col`/`.wppo-button`/`.wppo-button--sm`/`.wppo-switch-field`/`.wppo-field-nest`/`.wppo-audit-table__status` each 3×. Root cause is **media-query splits** emitting same selector at base + each breakpoint (e.g., `.wppo-main` at base + `768px` + `640px`). Not true duplication — expected from `max-width` `respond-to` pattern. Overhead ~2–3 KB. Could reduce with `cssnano` `mergeRules` or by deduplicating redundant breakpoint blocks (R-05/R-06). |
| D-05 | `src/css/components/_card.scss:97-101` | Dead duplication | **INFO** | `.wppo-danger-zone` defined here with `var(--wppo-danger)`/`var(--wppo-error-bg)` fallbacks — correct. `AUDIT/ARCHITECTURE-REVIEW.md Q-12` noted prior duplication with inline `style` in `PluginSetting.js:880` hard-coding `#ef4444`/`#fef2f2`; current SCSS token side is clean. No CSS-side fix needed. |
| D-06 | `src/css/components/_forms.scss:99-102,113-120,204-210` | Overlap | **INFO** | `.wppo-button--sm` has two separate `respond-to('md')` blocks (lines 113 and implicit via parent?) plus parent `.wppo-button` `respond-to('md')` at line 99 — three media blocks for same breakpoint. They emit as three separate `@media (max-width:768px)` groups in build (contributes to D-04). Could be merged into single media group via Sass nesting, but build minifier could also merge. Low priority. |

### 7. Performance / Rendering

| # | File:Line | Category | Severity | Finding |
|---|-----------|----------|----------|---------|
| P-01 | `src/css/layout/_sidebar.scss:18-31` | Perf (reflow) | **MEDIUM** | Animating `left` (layout property) on `.wppo-sidebar` — already R-07. Use `transform` for compositor-only animation. `transition: var(--wppo-transition)` on sidebar animates `border-color, background-color, box-shadow, transform, opacity` (from `--wppo-transition`, line 71 in `_variables.scss`) — `left` is **not** in that list? Check token: `border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease, opacity 0.2s ease` — `left` not included, so `left` change is **not transitioned** (instant jump). Verify: `transition: var(--wppo-transition)` does not include `left`, so drawer snaps rather than slides — may be intentional but UX expects slide. Either add `left` to transition (bad — reflow) or switch to `transform`. |
| P-02 | `src/css/components/_stats.scss:74-82` | Perf | **LOW** | `::after` radial-gradient dot pattern `radial-gradient(circle at 1px 1px, rgba(148,163,184,0.35) 1px, transparent 0)` with `background-size: 18px 18px` + `opacity:0.04` creates a subtle texture. Cheap (single layer) but rendered per `.wppo-stat-item` (×4 cards) with `position:absolute; inset:0`. Could be moved to parent grid container to share one layer. Negligible but worth noting if profiling shows paint cost. |
| P-03 | `src/css/components/_lazy-placeholder.scss:1-16` | Perf | **INFO** | `filter: blur(20px)` + `transform: scale(1.05)` on `.wppo-lqip-active` promotes to own layer (good) but no `will-change: filter, transform` or `contain: paint`. Consider adding `will-change: filter, transform` on `.wppo-lqip-active` and `will-change: auto` on loaded to hint compositor. Filter blur is GPU-heavy but brief (LQIP only). Acceptable. |
| P-04 | `src/css/components/_header.scss:21-25` | Perf | **INFO** | Premium hero `linear-gradient(135deg, #ffffff 0%, #eef2ff 55%, #f0f9ff 100%)` + `box-shadow: 0 1px 4px rgba(0,0,0,0.06), 0 10px 32px -14px rgba(79,70,229,0.12)` — gradient + shadow on `.wppo-feature-header`:first-of-type only (dashboard). Rendered once, not per-card. Fine. |
| P-05 | `src/css/components/_stats.scss:54-59,58-69` | Perf | **INFO** | `backdrop-filter: blur(8px)` on `.wppo-stat-item` and `blur(6px)` on `.wppo-banner` / `.wppo-feature-card__footer` — `backdrop-filter` is expensive (requires offscreen buffer). Used on 4 stat cards + banners. Check `.browserslistrc` supports it; `@wordpress/scripts` autoprefixes. On low-end devices, prefer `background: rgba(255,255,255,0.96)` alone without blur, or gate with `@supports (backdrop-filter: blur(1px))`. Minor. |
| P-06 | `src/css/components/_header.scss:116-123` | Perf | **INFO** | `text-wrap: balance` on `h2` (line 69) — modern, limited to Chrome 114+ / Safari 17.5+. Graceful fallback (ignored where unsupported). No issue. |
| P-07 | `build/style-index.css:1` | Bundle size | **INFO** | Build 56,072 bytes (54.8 KiB) for admin-only CSS — acceptable. Not enqueued on frontend. No code-splitting needed. RTL delta +32 bytes (flips `left/right`/`margin`) minimal. |

### 8. Specific File Notes

| File | Lines | Verdict | Notes |
|------|------:|---------|-------|
| `_variables.scss` | 85 | **PASS** | Clean token table, 49 custom props, `@supports color-mix` progressive enhancement, intentional fallbacks. Two unused tokens documented (V-01/V-02). No hard-coded hex outside definitions. |
| `_mixins.scss` | 35 | **PASS with LOW** | Map correct, `respond-to` sound, two retained dead mixins, `xl` unused. No Sass errors. |
| `_base.scss` | 242 | **PASS** | Utilities + code block + responsive table wrap + focus + activity list. Good `prefers-reduced-motion` + `hover:none` guards. `wppo-text-13` alias documented. `overflow-wrap: anywhere` + `min-width:0` prevents flex overflow. |
| `_card.scss` | 105 | **PASS** | BEM `__header/__body/__footer/__header-actions`, hover `transform: translateY(-1px)` with `hover:none` + `reduced-motion` guards. Smart `sm` stack for footer buttons. |
| `_dialog.scss` | 81 | **PASS** | Overlay + dialog + nested `@keyframes` (inside `.wppo-dialog` — valid SCSS). `animation: none` for reduced-motion. Suggest `max-height` for small VHs (A-09). |
| `_fields.scss` | 178 | **PASS** | `switch-field` hover, bespoke `400px` documented, `field-nest` slide-down with reduced-motion guard, `post-types-grid`, `cleanup-row` flex. WP ToggleControl integration correct. |
| `_forms.scss` | 211 | **PASS with INFO** | 15 `!important` to override WP admin — justified via scoped `.wppo-dashboard-view` (higher specificity to reduce future `!important`). Data-URI arrow hard-coded `#64748b` to match token (commented). `appearance: textfield` for number spinners, `font-size:16px` at `md` to prevent iOS zoom. `hover:none` + `reduced-motion` guards. |
| `_header.scss` | 168 | **PASS** | Gradient divider `::after`, premium hero Dashboard clamp headings, `__main` flex→column at `md`, `__actions` flex wrap, `stat-hero` clamp value, `section-title` uppercase tracked. |
| `_lazy-placeholder.scss` | 16 | **PASS with LOW** | Minimal LQIP — add reduced-motion guard (A-06) and optional `will-change`. |
| `_notices.scss` | 104 | **PASS** | 4 variants via `var(--wppo-*-bg/border)`, `__content` + `> svg:first-child` fallback, dismiss 32→44px at `md`, litespeed banner `flex-wrap`. |
| `_performance-audit.scss` | 953 | **PASS with MEDIUM** | Largest file (953 lines) — 5 sections (audit results, score gauges, vitals, suggestions, trends). Table duplication D-01, responsive audit-overview lg/md overlap R-05, input `!important` overrides scoped to `.wppo-audit-controls__input`. Good `overflow-wrap`/`word-break` for long values. |
| `_progress.scss` | 29 | **PASS** | Simple bar + header, gradient fill, reduced-motion guard. |
| `_stats.scss` | 371 | **PASS with LOW** | 4-accent stat cards, `clamp()` values, per-variant `color-mix` with `@supports not` fallback, dot-pattern `::after`, healthDot pulse with reduced-motion guard, banner `sm` padding adjust. BEM descendant quirk B-01, grid `lg/md` order R-06. |
| `_tabs.scss` | 150 | **PASS** | Scrollable sub-tabs with `::after` fade, `scroll-snap`, `scrollbar-width:none`, chips with `min-height 44px` at `md`, `--active` primary state. |
| `_tooltip.scss` | 73 | **PASS with MEDIUM** | Absolute tooltip, `left:50%` + `translateX(-50%)`, arrow `::after`, `max-width: min(200px, calc(100vw - 32px))`, `z-index:1000`, `pointer-events:none`, `reduced-motion` guard. Keyboard note A-04. |
| `_video-placeholder.scss` | 61 | **PASS with LOW** | `aspect-ratio:16/9`, `object-fit:cover`, play button `translate(-50%,-50%)` + `scale(1.1)` hover, `focus-visible` ring. Add reduced-motion for scale (A-07). |
| `_welcome.scss` | 148 | **PASS** | Welcome panel gradient, step `__number` circle + check, `__action` 32px min-height, secondary button variant scoped inside panel, `sm` wrap for steps. |
| `_container.scss` | 70 | **PASS** | `min-height: calc(98dvh - var(--wp-admin--admin-bar--height,0px))`, `safe-area-inset-*` via `max()`, `wppo-fadeIn` with reduced-motion guard. |
| `_sidebar.scss` | 261 | **PASS with MEDIUM** | Sticky desktop, fixed mobile drawer, `backdrop-filter: blur(2px)` overlay, mobile header 56px, toggle 44px touch target, `is-active` state with `color-mix` + `drop-shadow`. Reflow issue R-07/P-01. |
| `style.scss` | 27 | **PASS** | `@use` order correct: variables → mixins → base → layout → components (card→header→stats→forms→notices→progress→tabs→fields→dialog→tooltip → perf-audit→video→lazy→welcome). All 20 SCSS files accounted for; no missing import. |
| `build/style-index.css` | 1 | **PASS** | Minified, no source maps committed, 56 KB, all `--wppo-*` tokens present, no `@import`, no `xl` dead media. Duplicate selectors expected (media splits). |

---

## Summary Counts

| Severity | Count |
|----------|------:|
| **HIGH** | 0 (A07 prior HIGH F-02 sidebar `left` downgraded to MEDIUM here — `left` not in `transition` so no jank, but still non-compositor; F-05/F-06 already addressed via reduced raw `@media` and documented) |
| **MEDIUM** | 5 (M-03 missing `min-width` mixin, R-07/P-01 sidebar `left` vs `transform`, D-01 table duplication, A-04 tooltip focusability contract, plus build-level duplicate selectors note) |
| **LOW** | 13 (V-01/V-02/V-05, M-01/M-02/M-04/M-06, R-05/R-06, B-01, A-06/A-07, D-03/D-04) |
| **INFO/PASS** | 15 |
| **Dead code retained intentionally** | 2 mixins (`flex-center`, `truncate`) + 2 tokens (`--wppo-select-arrow`, `--wppo-shadow-premium`) + 1 breakpoint (`xl`) — all documented with retention comments |

---

## Recommendations (No-Code, Audit-Only)

1. **M-03/R-03:** Add `@mixin respond-from($bp)` (`min-width`) or rename `respond-to` → `respond-down` and introduce `respond-up`; lint raw `@media (max-width:` outside `mixins.scss`. Keeps breakpoint map as single source of truth.
2. **R-07/P-01:** Migrate `.wppo-sidebar` mobile drawer from `left` to `transform: translateX(-100%)/translateX(0)` + `will-change: transform`; ensure `transition` includes `transform` only (already does) and guard with `prefers-reduced-motion`. If snapping is intentional, document "instant drawer (no slide) by design".
3. **D-01:** Extract `@mixin wppo-table($pad)` for audit/vitals/sysinfo tables; keep column-width overrides. Saves ~70 lines and eliminates drift.
4. **R-05/R-06:** Deduplicate `wppo-audit-overview` `lg`+`md` (identical `2col`) to single `lg`; fix `wppo-grid-2-col` source order to descending `lg` → `md` (or keep `lg` only).
5. **A-06/A-07:** Add `prefers-reduced-motion: reduce` guards for LQIP (`_lazy-placeholder.scss`) and video placeholder scale/fill transitions.
6. **A-04:** Ensure tooltip trigger in JSX has `tabindex="0"` + `role="button"` + `aria-describedby` linking to `.wppo-tooltip-content[role="tooltip"]`; CSS already handles `focus`/`focus-within`.
7. **M-04:** Either document `xl:1200px` as reserved or remove if no 1200px layout planned; prevents confusion.
8. **V-04/V-05:** Define `--wppo-bg-secondary` in `_variables.scss` (used in `_video-placeholder.scss:8` via fallback) and remove redundant `var()` fallbacks where `:root` guarantees presence, or keep and lint-allow.

---

## Verification Commands Run

```sh
wc -l src/css/abstracts/_variables.scss src/css/abstracts/_mixins.scss src/css/base/_base.scss \
  src/css/components/_card.scss src/css/components/_dialog.scss src/css/components/_fields.scss \
  src/css/components/_forms.scss src/css/components/_header.scss src/css/components/_lazy-placeholder.scss \
  src/css/components/_notices.scss src/css/components/_performance-audit.scss src/css/components/_progress.scss \
  src/css/components/_stats.scss src/css/components/_tabs.scss src/css/components/_tooltip.scss \
  src/css/components/_video-placeholder.scss src/css/components/_welcome.scss \
  src/css/layout/_container.scss src/css/layout/_sidebar.scss src/css/style.scss build/style-index.css

grep -rn "respond-to\|flex-center\|truncate\|@mixin\|@include" src/css --include="*.scss"
grep -rn "var(--wppo-" src/css --include="*.scss" | sort | uniq -c
grep -rn "@media" src/css --include="*.scss"
grep -rn "!important" src/css --include="*.scss"
grep -rn "#[0-9a-fA-F]" src/css --include="*.scss" | grep -v "_variables.scss"
python3 -c "from collections import Counter; import re; ..."
```

All evidence captured via `Read` + `Grep` + `bash` on base `master@31fffc61`. No production files modified.

---

## Cross-References

- Prior CSS audit: `AUDIT/AGENTS/agent-A07-css.md` (F-02 sidebar `left`, F-05 `max-width`-only mixin, F-06 table duplication, F-21 raw `768px`, D-03 dead mixins, D-08 duplicate selectors) — this A08 audit re-verifies each; raw `768px` fragmentation now **fixed** (only `400px` bespoke remains), `xl` still unused, sidebar `left` still present, tables still duplicated.
- `AGENTS.md` breakpoint contract: `sm 640 / md 768 / lg 992 / xl 1200` — verified exact match in `src/css/abstracts/_mixins.scss:4-9`.
- `AUDIT/ARCHITECTURE-REVIEW.md Q-12` (`wppo-text-small` vs `wppo-text-13`, `flex-center`/`truncate`) — confirmed intentional alias + retained mixins.
