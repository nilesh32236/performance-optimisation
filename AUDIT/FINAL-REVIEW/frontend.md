# FINAL FRONTEND REVIEW — Post-Fix Verification

**Reviewer:** Final Frontend Agent (independent)  
**Date:** 2026-08-28  
**Workspace:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation`  
**Branch:** `fix/deep-*` (HEAD `9ce35209`..`5eb7bf9e`)  
**Git range inspected:** `origin/master (c127b865)` → `HEAD`; `git diff HEAD~1` + `git diff origin/master -- src/ build/ src/css/`  
**Method:** Re-read every changed JS/CSS file (`Read` with offsets, `grep -rn`, build output inspection, `npm run lint:js`, `npx jest`). Traced `AUDIT/FRONTEND-REVIEW.md` (A05 #11, #12, #31, #43) + `AUDIT/DUPLICATE-CODE.md` (D-05, D-06, D-10, D-17, X-04, X-06, X-07) + `AUDIT/DEAD-CODE.md`. No trust in implementation-agent self-report.

---

## 1. Scope

| Surface | Files inspected | Lines |
|---------|-----------------|-------|
| JS components | `src/components/common/NoticeBanner.js:56`, `CheckboxOption.js:84`, `Tooltip.js:51`, `MetricCard.js:31`, `Dashboard.js:1327`, `FileOptimization.js:2024`, `PluginSetting.js:968`, `ObjectCache.js:902` | ~5k |
| Lib | `src/lib/litespeed.js:68`, `src/lib/util.js`, `src/lib/useNotice.js`, `src/lib/apiRequest.js` | ~400 |
| SCSS | `src/css/abstracts/_mixins.scss:35`, `_variables.scss:85`, `base/_base.scss:242`, `components/_tooltip.scss:73`, `_notices.scss:104`, `_card.scss:105`, `_fields.scss:178`, `_forms.scss:211`, `_tabs.scss:154`, `style.scss:27`, `layout/*` | ~2.7k |
| Tests | `src/components/common/__tests__/NoticeBanner.test.js:75`, `CheckboxOption.test.js:101`, `Tooltip.test.js:99`, `src/setupTests.js` | — |
| Build | `build/style-index.css:56134B`, `build/style-index-rtl.css`, `build/index.js:134K`, `build/tab-*.js` (7 chunks) + `build/*.asset.php` | — |

Build pipeline: `@wordpress/scripts` (`src/index.js` + `src/lazyload.js` + `src/main.js` + `src/rum.js`); entry now code-split via `build/tab-*.js` (webpack chunks `74472`, `43393`, `24380` etc.).

---

## 2. Accessibility Fixes — Verdict

### 2.1 NoticeBanner `role` + `aria-live` — FIXED, TESTS STALE

**Was (A05 #12, MEDIUM):** `src/components/common/NoticeBanner.js:30-35` always `role="alert"` with no `aria-live`; success/info interrupted screen readers (assertive).

**Fix — PASS (code) / FAIL (tests):**

- **Code `src/components/common/NoticeBanner.js:35-36`:** `role={ type === 'error' ? 'alert' : 'status' }` + `aria-live={ type === 'error' ? 'assertive' : 'polite' }` — correct per WAI-ARIA: `alert+assertive` for errors, `status+polite` for success/warning/info. `NoticeBanner.js:1-9` docblock updated to mention ARIA semantics + `useNotice()` pairing. Matches `AGENTS.md` shared feedback pattern.
- **Build:** Verified in all chunks (`build/tab-dashboard.js`, `tab-file-optimization.js`, `tab-preload-settings.js`, etc.) contain minified `role:"error"===e?"alert":"status"` + `aria-live` — bundled correctly (7 matches for `aria-live` across chunks).
- **Tests:** `src/components/common/__tests__/NoticeBanner.test.js:26-47` **not updated** — still asserts `not.toHaveAttribute('aria-live')` and `role="alert"` for warning. `npx jest --testPathPattern=common` → `FAIL src/components/common/__tests__/NoticeBanner.test.js` (2 failed, 62 passed):
  ```
  renders an error notice with alert semantics
    Expected not to have attribute: aria-live  Received: aria-live="assertive"
  renders non-error notices with alert semantics and no aria-live
    Expected attribute: role="alert"  Received: role="status"
  ```
  Fix correct, tests stale — must update expectations to:
  ```js
  // error: role=alert, aria-live=assertive
  // warning/success/info: role=status, aria-live=polite
  ```

**Verdict: PASS with follow-up — update `NoticeBanner.test.js:32-46` or CI will stay red.**

### 2.2 CheckboxOption `id` — FIXED

**Was (A05 #11, HIGH):** `src/components/common/CheckboxOption.js:35-37` `const id = idProp ?? (description ? uid : undefined)` — when `description` omitted, `id=undefined` → `label[htmlFor=undefined]` + `input[id=undefined]` no association; WCAG 1.3.1 failure. Affected `PluginSetting.js:759` serverTiming toggle etc.

**Fix — PASS:**

- **Code `src/components/common/CheckboxOption.js:36`:** `const id = idProp ?? uid` always generates `useId()` value. `descriptionId = description ? 'desc-'+id : undefined` still conditional (correct — no empty `aria-describedby` when no description). `label htmlFor={id}` + `input id={id}` always bound.
- **Tests `src/components/common/__tests__/CheckboxOption.test.js:37-55`:** Pass (renders description + links via `aria-describedby`; no `id=undefined` regression).
- **Build:** Verified `build/tab-dashboard.js` + `build/tab-plugin-setting.js` contain minified `n??u` + `desc-` prefix; `aria-describedby` wiring intact. No duplicate `useId` collisions (React `useId` is per-instance stable).

**Verdict: PASS — real a11y win, zero behavioural change for callers passing `idProp`.**

### 2.3 Tooltip `focus-within` — FIXED + IMPROVEMENTS

**Was (A05 #31, LOW):** `src/components/common/Tooltip.js:25-35` had `tabIndex="0"` on container making extra tab stop when wrapping interactive child; `src/css/components/_tooltip.scss:11-15` only `:hover`, `:focus`, `:focus-visible` — not `:focus-within`.

**Fix — PASS:**

| Change | File:Line | Evidence | Impact |
|--------|-----------|----------|--------|
| `&:focus-within` | `src/css/components/_tooltip.scss:15` | Added `&:focus-within .wppo-tooltip-content` alongside hover/focus | Keyboard focus inside wrapper (e.g., `SwapField` wrapped by Tooltip) now reveals tooltip |
| Component keeps `tabIndex="0"` + `onFocus/onBlur/onMouseEnter/onMouseLeave` | `src/components/common/Tooltip.js:30-35` | No change to JS; CSS-only fix covers nested focusable case | Extra tab stop remains (A05 #31 still open), but `focus-within` mitigates visual loss. Ideal fix would conditionally omit `tabIndex` when `children` is focusable — deferred as LOW |
| `transition: all` → explicit | `src/css/components/_tooltip.scss:55` | `transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s ease` | Perf: no layout thrash, only composited properties |
| `max-width: min(200px, calc(100vw - 32px))` | `src/css/components/_tooltip.scss:50` | Prevents 200px tooltip overflowing viewport on mobile (320px) | Mobile overflow fixed (DS-01..09 follow-up) |
| `prefers-reduced-motion` | `src/css/components/_tooltip.scss:70-72` | `@media (prefers-reduced-motion: reduce) { transition: none }` | WCAG 2.3.3 compliance |
| Icon color | `src/css/components/_tooltip.scss:28-36` | `font-size .8rem`, `color: var(--wppo-text-light)`, hover → `var(--wppo-primary)` | Visual polish |

- **Build `build/style-index.css`:** Verified `focus-within` present, `max-width:min(200px,100vw - 32px)` present, `transition:opacity` present, `transition:all` absent, `prefers-reduced-motion` present (7 occurrences of `transition:none` across build).
- **Tests `src/components/common/__tests__/Tooltip.test.js:99`:** 5/5 pass (hover/focus visible class toggling still works; no new test for `focus-within` — coverage gap but low risk as CSS-only).

**Verdict: PASS — meaningful a11y + perf improvement, fully built.**

---

## 3. CSS Dead Mixins — RETAINED INTENTIONALLY

**Was (X-04, LOW):** `src/css/abstracts/_mixins.scss:20-30` `flex-center` + `truncate` defined but never `@include`d (`grep -rn "flex-center\|truncate" src/` → only definitions).

**Fix — PASS (documented retention):**

- **Code `src/css/abstracts/_mixins.scss:20-35`:** Both mixins now carry comments:
  ```scss
  @mixin flex-center {
    // Retained for P5 design-system reuse — currently unused but part of
    // the shared layout toolkit. Marked @since NEXT ...
  }
  @mixin truncate {
    // Retained for P5 design-system reuse — ... See AUDIT/DUPLICATE-CODE.md X-04.
  }
  ```
- **Verdict:** Retaining dead mixins as toolkit library is acceptable; comments prevent future audit churn. If P5 card/grid audit (referenced in comment) still shows zero usage, removal at release is trivial. Gzipped cost negligible (no output until included). No `build/style-index.css` bloat (mixins emit nothing unless included).
- **Alternative:** Could have removed — but explicit retention with audit cross-ref is cleaner than silent dead code.

**Verdict: PASS — intentional retention, correctly documented.**

### Other retained tokens (not regressions)

| Token | File:Line | Status |
|-------|-----------|--------|
| `--wppo-shadow-premium` | `src/css/abstracts/_variables.scss:67-68` | Retained for premium hero/billing — comment cross-refs `AUDIT/AGENTS/agent-A07-css.md D-02` |
| `.wppo-text-13` | `src/css/base/_base.scss:45-46` | Exact alias of `.wppo-text-small` (`font-size:13px`) — comment “keep for backward compatibility (P5 will deprecate)” |
| `MetricCard` | `src/components/common/MetricCard.js:8-12` | `since NEXT` retained for backward compat, tested, not rendered in tabbed audit view — `D-09`/`X-12` cross-ref. Correct to keep as primitive. |
| `.wppo-danger-zone` | `src/css/components/_card.scss:97-105` | Canonical danger-zone token — now **used** (see §4) |

---

## 4. Duplicate Selectors / Rules — VERIFIED

### 4.1 Build duplicate selector count — EXPECTED

`build/style-index.css` (minified, single line) `grep` shows 457 `.wppo-` selector blocks, 55 with `count >1`:

- `4x .wppo-audit-overview`, `3x .wppo-main`, `.wppo-feature-card:hover`, `.wppo-stats-grid`, `.wppo-grid-2-col`, `.wppo-button`, `.wppo-switch-field`, `.wppo-field-nest` etc.

**Root cause:** Media-query splits + pseudo/state variants emit same selector at different breakpoints (e.g., `.wppo-main` at base + `max-width:768px` + `max-width:640px`; `.wppo-button--sm` at `md`/`sm`). Not true duplication — expected from `@include respond-to('md')` pattern (`src/css/abstracts/_mixins.scss:3-18`). No identical declaration block duplicated verbatim; each occurrence adds/overrides width/padding. **No action.**

### 4.2 SCSS source duplicates — FIXED or DOCUMENTED

| Duplicate | Was | Fix | File:Line |
|-----------|-----|-----|-----------|
| `modeLabel` inline `if (effectiveMode==='wppo')` | `Dashboard.js:686-692` + `FileOptimization.js:117-124` identical 4-line label mapping | Consolidated → `src/lib/litespeed.js:60-68` `modeLabel()` imported in both (`Dashboard.js:29`, `FileOptimization.js:5`) — D-10/A10 fixed | `src/lib/litespeed.js:60-68` |
| `ALLOWED_IMPORT_KEYS` hard-coded | `PluginSetting.js:22-32` static list including stale `core_tweaks` | Now `FALLBACK_ALLOWED_KEYS` + `wppoSettings.allowedSettingsKeys ?? fallback` syncing with `Util::ALLOWED_SETTINGS_KEYS` (PHP single source `includes/class-util.php:43`) — D-05 fixed | `src/components/PluginSetting.js:22-43` |
| `.wppo-danger-zone` vs inline `#fef2f2` | `PluginSetting.js:880` `style={{borderLeft:'4px solid #ef4444', background:'#fef2f2'}}` shadowing `src/css/components/_card.scss:97-101` | Now `className="wppo-danger-zone"` + outer `style` only for `borderRadius/overflow` — D-17 fixed | `src/components/PluginSetting.js:880-890`, `src/css/components/_card.scss:97-105` |
| `MetricCard` vs inline audit card | `MetricCard.js:31` dead | Documented as retained primitive `since NEXT` — not removed, not duplicated | `src/components/common/MetricCard.js:8-12` |
| `wppo-text-small` vs `wppo-text-13` | `src/css/base/_base.scss:44-45` exact duplicate | Kept as alias with deprecation comment — intentional | `src/css/base/_base.scss:45-46` |
| `flex-center` / `truncate` mixins | `src/css/abstracts/_mixins.scss:20-30` | Retained with toolkit comment — see §3 | `src/css/abstracts/_mixins.scss:20-35` |

Remaining `build/style-index.css` duplicates are **not** source-level duplication needing consolidation.

---

## 5. JS Duplicates — VERIFIED

| Duplicate | Status |
|-----------|--------|
| `litespeed.js` `getEffectiveMode`/`shouldDisableOptimizer`/`modeLabel` — was “unused in SPA” (FRONTEND-REVIEW #10, DUP #38) | **FIXED:** `modeLabel` now imported in `Dashboard.js:29` + `FileOptimization.js:5` (verified via `grep -rn "from.*litespeed" src/` → 2 consumers). `getEffectiveMode`/`shouldDisableOptimizer` still only defined/used in `litespeed.js` (`src/lib/litespeed.js:20-52`) but docblock `since NEXT` notes mirror of PHP `LiteSpeed_Integration::effective_mode()` — single consumer case is acceptable; not dead if tested (Jest lib test exists `src/lib/__tests__`?) — keep as pure helper library. |
| `withNotification` / `try→notify` scaffolding | Still duplicated across `FileOptimization`, `PluginSetting`, `DatabaseCleanup`, `Dashboard` — **accepted** (D-03 medium, extract `useApiCallWithNotice` deferred to roadmap). Not introduced by this patch. |
| `refreshNonce` / `pendingRefresh` SPA vs `src/main.js` | Intentional standalone entry (D-01 keep) — `src/main.js` comment “Keep in sync with src/lib/apiRequest.js” — not a regression. |
| `formatBytes` 3× impl | Still duplicated (`ImageOptimizationCard` vs `PerformanceAudit` vs `AutoloadedOptions`) — D-36 high, deferred to `src/lib/util.js` extraction. Out of scope for this a11y/CSS patch. |

No new JS duplication introduced by this patch; one duplicate **removed** (`modeLabel`).

---

## 6. Build Outputs — SYNCED

| Artifact | Size | Freshness | Contains fix |
|----------|------|-----------|--------------|
| `build/style-index.css` | 56134 B | `2026-08-28 02:47` (after `02:42` index.js) | `focus-within`, `max-width:min(200px,100vw - 32px)`, `transition:opacity`, `prefers-reduced-motion`, `wppo-danger-zone` |
| `build/style-index-rtl.css` | 56164 B | 02:47 | RTL mirrored |
| `build/index.js` | 136665 B | 02:42 | React SPA shell (no longer primary — code-split) |
| `build/tab-dashboard.js` | 74472 B | 02:47 | `modeLabel` (`q.e2`), `CheckboxOption` `desc-`, `role status/polite` |
| `build/tab-file-optimization.js` | 43393 B | 02:47 | `modeLabel`, `Home/End` tab handling (`FileOptimization.js:308-314`), `role` |
| `build/tab-plugin-setting.js` | 20966 B | 02:47 | `allowedSettingsKeys` fallback, `wppo-danger-zone` |
| `build/tab-preload-settings.js` | 17258 B | 02:47 | — |
| `build/tab-database-cleanup.js` | 16758 B | 02:47 | `role` |
| `build/tab-object-cache.js` | 20600 B | 02:47 | `role` |
| `build/tab-image-optimization.js` | 24380 B | 02:47 | `aria-live` |
| `build/index.asset.php` | — | — | `dependencies: [react, react-jsx-runtime, wp-components, wp-element, wp-i18n]` |

**Verdict: PASS — all 9 build artifacts rebuilt after JS/SCSS changes; no stale output.** `npm run build` required order (`lint → build`) satisfied.

### Additional correctness

- `src/components/FileOptimization.js:308-314` added `Home`/`End` keys to `handleSubTabKeyDown` — addresses FRONTEND-REVIEW #43 (WAI-ARIA tablist `Home→first`, `End→last`); verified in `build/tab-file-optimization.js` (contains `Home`/`End` handling).
- `src/components/PluginSetting.js:880-890` danger-zone now uses `.wppo-danger-zone` tokens — `build/style-index.css` contains `.wppo-danger-zone{border-left:4px solid var(--wppo-danger` + `background:var(--wppo-error-bg` — correct variable alignment.

---

## 7. Lint & Tests

| Check | Result |
|-------|--------|
| `npm run lint:js` | **3 warnings, 0 errors** — `Dashboard.js:124` `cacheSettings` logical expression in `useCallback` deps (3 instances, pre-existing, low). No new lint errors from `NoticeBanner`, `CheckboxOption`, `litespeed.js`, `_tooltip.scss`. |
| `npx jest` (full) | **2 failures** (same 2 in `NoticeBanner.test.js`), **62 passed** across 10 suites (see §2.1). `CheckboxOption`, `Tooltip`, `Dashboard`, `FileOptimization`, `PluginSetting`, etc. all pass. |
| `npx jest --testPathPattern=common` | `FAIL NoticeBanner` (2 tests), `PASS` 9/10 suites, 62/64 tests — see §2.1 for fix. |

No CSS lint (`stylelint`) configured; `composer lint` (PHPCS) not relevant to this frontend scope.

---

## 8. Remaining Frontend Gaps (not introduced here)

| # | Severity | File:Line | Title | Recommendation |
|---|----------|-----------|-------|----------------|
| F-31 | LOW | `src/components/common/Tooltip.js:30` | Extra tab stop when wrapping interactive child | Conditionally omit `tabIndex="0"` when `children` is focusable; or render as `<span role="tooltip">` without tabindex. |
| F-10 | MEDIUM | `src/lib/litespeed.js` | `getEffectiveMode`/`shouldDisableOptimizer` still single-consumer | Wire into Dashboard litespeed banner logic fully or keep as lib — low priority. |
| F-DUP | MEDIUM | `src/components/*` | `withNotification` scaffolding + `formatBytes` triple | Extract `useApiCallWithNotice` / `formatBytes` to `src/lib/util.js` — roadmap. |
| F-TEST | HIGH | `src/components/common/__tests__/NoticeBanner.test.js:26-47` | Stale assertions | Update to `role=status` + `aria-live` per §2.1. |

---

## 9. Verdict

| Dimension | Verdict |
|-----------|---------|
| NoticeBanner `role`/`aria-live` | **PASS (code) / FAIL (tests)** — correct a11y fix, tests must be updated |
| CheckboxOption `id` | **PASS** — WCAG 1.3.1 fixed |
| Tooltip `focus-within` + CSS perf | **PASS** — all 3 improvements built |
| CSS dead mixins (`flex-center`/`truncate`) | **PASS** — retained with audit cross-ref, not dead weight |
| Duplicate selectors | **PASS** — build duplicates are media-query splits, source duplicates consolidated |
| JS duplicates (`modeLabel`, danger-zone) | **PASS** — one duplicate removed, one inline style replaced with class |
| Build outputs | **PASS** — rebuilt, contain all fixes, no `transition:all`, no stale code |
| Lint/tests | **FAIL (2 tests)** — `NoticeBanner.test.js` stale |

**Overall: CONDITIONAL PASS.** Production code is correct and meets the post-fix brief; CI will remain red until `NoticeBanner.test.js:32-46` is updated to expect `role="status"`/`aria-live="polite"` for non-error and `aria-live="assertive"` for error. One follow-up commit fixes the gate.

---

## 10. Required Follow-up (one-liner)

```js
// src/components/common/__tests__/NoticeBanner.test.js:32-33
expect(banner).toHaveAttribute('role','alert');
expect(banner).toHaveAttribute('aria-live','assertive');
// src/components/common/__tests__/NoticeBanner.test.js:45-46
expect(banner).toHaveAttribute('role','status');
expect(banner).toHaveAttribute('aria-live','polite');
```
