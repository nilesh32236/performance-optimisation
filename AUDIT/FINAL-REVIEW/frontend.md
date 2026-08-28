# FINAL FRONTEND REVIEW — fix/audit-2026-08-28

**Reviewer:** Agent J  
**Branch:** `fix/audit-2026-08-28` @ `44d7bcbf`  
**Method:** `git diff origin/master...HEAD` on `src/`, `templates/`, `src/css/` + `Read` per file + `npm run lint:js` + `npm test` + `npm run build` + `node --check`.

---

## 1. Fixes Verified

| ID | File:Line | Before | After | Verdict | Evidence |
|---|---|---|---|---|---|
| **H-10 App.js AbortController** | `src/App.js:88-91,288-410` | `const abortController = new AbortController()` shared across 3 fetches (`fetchRecentActivities` `fetchServerRules` `apiCall ccss_status`). `useEffect` deps `activeTab`/`rulesRetryTrigger`/`ccssRefreshTrigger` — bump aborted siblings; `hasFetched*` refs desynced on `AbortError`. | Per-request `activitiesController`/`rulesController`/`ccssController` each `new AbortController()` stored in `useRef` (`activitiesControllerRef` etc.) `src/App.js:88-91` → at effect start abort previous if present `if(ref.current) ref.current.abort()` `src/App.js:290,298,304` + new `ref.current=controller` + per-signal `signal` + per-`signal.aborted` guards `src/App.js:316,328,359` + cleanup `activitiesController.abort(); rulesController.abort(); ccssController.abort();` `src/App.js:399-402` | **FIXED+VERIFIED** | `grep AbortController src/App.js` 3 hits (no shared name beyond locals). `apiRequest.js` already supports optional `signal` passed to `fetch({signal})` — propagated correctly. `npm run lint:js` 0e3w, `npm test` 345/345, `npm run build` OK (index 134 KiB). |
| **D-03 useApiCallWithNotice** | `src/lib/useApiCallWithNotice.js:1-124` `src/lib/apiWithNotice.js:1-13` `FileOptimization.js:127` `EdgeCachePanel.js:41` `ImageOptimization.js:100` | 10-line `setIsLoading→dismiss→try apiCall→notify(success/error)→catch→console.error→finally` copy-pasted `FileOptimization:175` vs 4 components | Hook `useApiCallWithNotice({notify,dismiss,setLoading})→callWithNotice` stable via `useCallback[notify,dismiss,setLoading]` `useApiCallWithNotice.js:102-115` + plain `withApiNotice` `useApiCallWithNotice.js:37-86` accepting Promise *or* thunk (thunk preferred so `setLoading(true)` before request). Re-export `apiWithNotice.js→useApiCallWithNotice` `apiWithNotice.js:10-13` | **FIXED+VERIFIED** | Callers migrated 4→1 scaffold: `FileOptimization:7,127` `withNotification+withLiteSpeedNotice`, `EdgeCachePanel:41`, `ImageOptimization:100`. Thunk path `typeof factory==='function'?factory():factory` `useApiCallWithNotice.js:48-50` — correct. No state management lib added, pure `useState` retained. |
| **P5-01 Sidebar transform** | `src/css/layout/_sidebar.scss:18-29` | `left: calc(-1*var(--wppo-sidebar-width))` + `left:0` on `.wppo-sidebar--mobile-open` — layout property, triggers reflow on open/close | `left:0; transform: translateX(-100%)` idle + `translateX(0)` open `src/css/layout/_sidebar.scss:20,26` | **FIXED+VERIFIED** | Compositor-only; `transition: var(--wppo-transition)` already `transform 0.2s` in design system. `build/style-index.css` 55.1 KiB, `grep translateX build` 5 hits verified. |
| **P5-02 xs breakpoint** | `src/css/abstracts/_mixins.scss:5` `src/css/components/_fields.scss:33` | Bespoke `@media (max-width:400px)` in `_fields.scss` not in `respond-to` map — design-system drift (A08 M-06) | Added `'xs':400px` to `$breakpoint-map` `mixins.scss:5` + replaced raw `@media` with `@include respond-to('xs')` `fields.scss:33` | **FIXED+VERIFIED** | Single-source breakpoint, `npm run build` OK, build contains `max-width:400px` via mixin (not raw). |
| **P5-03/04 reduced-motion** | `src/css/components/_lazy-placeholder.scss:18-21` `src/css/components/_video-placeholder.scss:63-75` | LQIP `blur(20px)` + video `scale` transitions without `prefers-reduced-motion` vestibular guard (A08 A-06/A-07) | Added `@media (prefers-reduced-motion:reduce){.wppo-lqip-loaded{transition:none}}` `lazy-placeholder.scss:18` + `video-placeholder.scss:63-75` disabling `transition` on `.wppo-video-play-btn`/`.wppo-play-btn-bg`/loading `picture` and resetting hover `scale(1.1)→translate(-50%,-50%)` | **FIXED+VERIFIED** | `grep prefers-reduced-motion src/css` 14 hits including new guards; `build/style-index.css` contains guards (14 hits in build). `npm run build` OK. |
| **Edge templates (FE perf/security)** | `templates/bunny-edge.js:37` `templates/cloudflare-worker.js:63` | Vary fragmentation + private leak (H-08/H-12) | See security.md — normalized `cacheKey=new Request(url,'GET')` both templates; private/Set-Cookie/Vary:Cookie guards lowercased | **FIXED+VERIFIED (FE perf)** | Reduces per-URL variants, improves hit-rate. |

## 2. Architecture / State Management (RETAINED, not regressed)

- **No routing lib** — `useState(activeTab)` + conditional rendering `src/App.js:221` retained, no change.
- **No state lib** — pure `useState` + global `wppoSettings` mutated via `apiCall('update_settings')` `src/lib/apiRequest.js:119` `Object.freeze` attempt retained — not worsened. `useApiCallWithNotice` hook captures `notify/dismiss/setLoading` via closure, not global state.
- **`hasFetched*` refs** `src/App.js:86-90` (`hasFetchedActivities/Rules/Ccss`) guard duplicate fetches on tab re-entry — now correctly paired with per-controller aborts (previous siblings no longer pollute `hasFetched` on `AbortError`).
- **`build/` committed** `build/index.js` `build/style-index.css` etc. — `npm run build` webpack 5.109 OK, no custom config, `@wordpress/scripts` defaults.

## 3. A11y / Lints

- `npm run lint:js` 0 errors 3 warnings: `Dashboard.js:126` `cacheSettings` exhaustive-deps (pre-existing, triaged, not introduced this branch). `precommit` not run (CI only).
- `role status/polite` + `focus-within/max-width` + `Home/End` tablist already fixed prior branch (A11) — retained, not regressed. New `prefers-reduced-motion` guards improve vestibular a11y.

## 4. New Issues / Remaining

| ID | Severity | Detail |
|---|---|---|
| F-01 | INFO | `apiWithNotice.js` thin re-export adds one extra file for import-path stability. Not dead code (re-export used by audit carry-over references), but could be removed after imports migrate to `useApiCallWithNotice`. Harmless. |
| F-02 | INFO | `App.js` three `useRef` + three `AbortController` per effect is more verbose than single `AbortSignal.any` or `AbortController` per-fetch factory, but explicitly isolates siblings and handles cleanup correctly. Not over-complex. |
| P5-05/06/08/09 | WONT_FIX (intentional) | `tooltip transition all` already explicit (no `all` to fix) `P5-05 VERIFIED`; Edge TTL variance `P5-06 VERIFIED`; `flex-center/truncate` library `P5-08 VERIFIED keep`; build duplicate selectors `P5-09 VERIFIED skip` — all correctly triaged as no-change per IMPLEMENTATION-LOG `P5-05..09` |

## 5. Verdict

**PASS.** Abort isolation correctly fixes sibling cancellation without architectural churn. `useApiCallWithNotice` centralizes notice scaffolding cleanly. CSS compositor + reduced-motion + breakpoint tokens all verified via `build` output. No a11y or lint regression (`0e3w`). `npm test` 345/345 and `npm run build` 55.1 KiB stable.
