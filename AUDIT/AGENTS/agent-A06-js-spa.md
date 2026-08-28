# Agent A06 — JS React SPA Audit — Performance Optimisation

**Date:** 2026-08-28
**Base:** master@31fffc61
**Mode:** Audit-only (no production code modified)
**Scope:** React SPA (App.js, index.js, Dashboard, FileOptimization, PreloadSettings, ImageOptimization, DatabaseCleanup, ObjectCache, PluginSetting, AiPanel, EdgeCachePanel, LlmsPanel, AutoloadedOptions, CriticalCssPanel, PageSpeedPanel, PerformanceAudit, SuggestionsPanel, SystemInfo, WebVitalsRum, WebVitalsTrends, WelcomePanel, RecentActivityCard, ImageOptimizationCard, lib/apiRequest, lib/useNotice, lib/util, lib/litespeed, components/common/*, setupTests)

| Field | Value |
|-------|-------|
| **Files reviewed** | 38 files (see checklist below) |
| **Lines reviewed** | ~12,974 lines (summed wc -l; total src/ is 13,018 including ancillary) |
| **Tooling** | Read with offsets (large files), Grep for apiCall/wppoSettings, manual trace of state/apiCall/hooks/duplication/a11y/perf |
| **Verification** | `wc -l` + `grep -rn` executed; every assigned file read end-to-end |

## Files Reviewed Checklist

- [x] `src/App.js` (527)
- [x] `src/index.js` (11)
- [x] `src/components/Dashboard.js` (1329)
- [x] `src/components/FileOptimization.js` (2024)
- [x] `src/components/PreloadSettings.js` (623)
- [x] `src/components/ImageOptimization.js` (979)
- [x] `src/components/ImageOptimizationCard.js` (208)
- [x] `src/components/DatabaseCleanup.js` (644)
- [x] `src/components/ObjectCache.js` (922)
- [x] `src/components/PluginSetting.js` (978)
- [x] `src/components/AiPanel.js` (291)
- [x] `src/components/EdgeCachePanel.js` (279)
- [x] `src/components/LlmsPanel.js` (152)
- [x] `src/components/AutoloadedOptions.js` (133)
- [x] `src/components/CriticalCssPanel.js` (123)
- [x] `src/components/PageSpeedPanel.js` (479)
- [x] `src/components/PerformanceAudit.js` (841)
- [x] `src/components/SuggestionsPanel.js` (270)
- [x] `src/components/SystemInfo.js` (353)
- [x] `src/components/WebVitalsRum.js` (177)
- [x] `src/components/WebVitalsTrends.js` (229)
- [x] `src/components/WelcomePanel.js` (272)
- [x] `src/components/RecentActivityCard.js` (70)
- [x] `src/lib/apiRequest.js` (249)
- [x] `src/lib/useNotice.js` (74)
- [x] `src/lib/util.js` (36)
- [x] `src/lib/litespeed.js` (68)
- [x] `src/components/common/CheckboxOption.js` (84)
- [x] `src/components/common/ConfirmDialog.js` (182)
- [x] `src/components/common/ErrorBoundary.js` (48)
- [x] `src/components/common/FeatureCard.js` (43)
- [x] `src/components/common/FeatureHeader.js` (33)
- [x] `src/components/common/LoadingSubmitButton.js` (52)
- [x] `src/components/common/MetricCard.js` (31)
- [x] `src/components/common/NoticeBanner.js` (56)
- [x] `src/components/common/StatusBadge.js` (35)
- [x] `src/components/common/SwitchField.js` (62)
- [x] `src/components/common/Tooltip.js` (51)
- [x] `src/setupTests.js` (56)

---

## Findings

> Schema per row: **File:Line — Category — Severity — Problem / Why matters / Evidence / Impact / Recommended solution / Confidence**

---

### F-01 — Global frozen mutation (data loss / thrown in strict)

- **File:Line:** `src/lib/apiRequest.js:119` → `src/components/AiPanel.js:68-71,147-160` ; `src/components/EdgeCachePanel.js:74-81` ; `src/components/LlmsPanel.js:43-46`
- **Category:** State / Correctness
- **Severity:** High
- **Problem:** `apiCall` does `wppoSettings.settings = Object.freeze(data.data)` on every successful `update_settings`. Three Dashboard-embedded panels then *directly mutate* that frozen object: `wppoSettings.settings.ai_adaptive = {enabled}` (AiPanel), `wppoSettings.settings.edge_cache = {...}` (EdgeCache), `wppoSettings.settings.llms_txt = {enabled,source}` (Llms). In strict mode (Babel) this throws `TypeError: Cannot assign to read only property`. Even non-strict, the next `apiCall` freeze diverges from the manually-mutated shape.
- **Why matters:** Breaks SPA state sync; panel save appears to succeed but next mount or FileOptimization's `handleSaveLiteSpeedMode` (which re-freezes) overwrites the manual patch, or throws and swallows error.
- **Evidence:** `src/lib/apiRequest.js:119` `Object.freeze`; `src/components/AiPanel.js:68` `wppoSettings.settings.ai_adaptive = { enabled };` with no freeze-aware spread.
- **Impact:** Silent settings rollback, console TypeError, operator confusion that save didn't stick.
- **Recommended solution:** Centralise mutation in `apiRequest.js` only; panels should *never* write `wppoSettings`. Either rely solely on frozen replacement from server, or introduce a small store helper `setWppoSettings(next)` that freezes after merge. Remove all direct assignments and re-freeze: `wppoSettings.settings = Object.freeze({ ...wppoSettings.settings, ai_adaptive:{enabled}})` if manual patch is needed, or better, use `res.data`.
- **Confidence:** 98%

### F-02 — Shared AbortController cancels unrelated fetches (App.js)

- **File:Line:** `src/App.js:285-386`
- **Category:** Correctness / Perf
- **Severity:** High
- **Problem:** One `AbortController` is shared across three parallel fetches (`fetchActivities`, `fetchRules`, `fetchCcssStatus`) inside a single `useEffect`. The `rulesRetryTrigger`/`ccssRefreshTrigger` state bump aborts *in-flight* activities fetch as well, and unmount cleanup aborts all. Any tab switch that bumps a trigger also aborts the activities fetch mid-flight.
- **Why matters:** Causes spurious `AbortError` → activities never load when user quickly switches tabs or retries server rules; `hasFetched*` refs desync.
- **Evidence:** `src/App.js:285 const abortController = new AbortController();` then three `await fetch*(abortController.signal)` share it; `finally { setCcssRefreshTrigger(0)}` triggers effect re-run next tick.
- **Impact:** Flaky Dashboard activities, extra refetches, console noise.
- **Recommended solution:** Give each fetch its own `AbortController` (or at minimum separate signals), or keep single controller but do not abort on trigger bumps — only on unmount/tab unmount. Prefer `useRef` per fetch.
- **Confidence:** 95%

### F-03 — `setCcssRefreshTrigger(0)` re-render loop / guard inversion

- **File:Line:** `src/App.js:345-374`
- **Category:** State / Perf
- **Severity:** High
- **Problem:** `fetchCcssStatus` guards with `if (hasFetchedCcss.current && 0===ccssRefreshTrigger) return;` but unconditionally sets `setCcssRefreshTrigger(0)` in `finally`. This causes an extra render cycle on every tab switch, and the guard depends on timing of state vs ref. Increment path via `onCcssRefresh` sets `c`+1, then effect fetches, then resets to 0 — creating two effect runs per refresh.
- **Why matters:** Double fetch of `ccss_status`, unnecessary renders, violates "not re-fetched on every tab switch" comment.
- **Evidence:** `src/App.js:371 setCcssRefreshTrigger(0)` inside effect that depends on `ccssRefreshTrigger`.
- **Impact:** Extra API load, flicker of CCSS loading state.
- **Recommended solution:** Replace counter trigger with boolean or use `useReducer` / dedicated `refreshNonce` counter that does not reset to 0. Or lift `hasFetchedCcss` reset into handler only and remove the `finally` reset.
- **Confidence:** 92%

### F-04 — Combined `activities` + `length` dependency causes extra refetch

- **File:Line:** `src/App.js:381-387`
- **Category:** Hook
- **Severity:** Medium
- **Problem:** Effect deps include `recentActivities.length` *and* conditionally fetches when `recentActivities.length===0`. After first successful fetch, `length` flips from 0 → N, triggering effect again (even though `hasFetchedActivities` guards). Guard is ref-based but effect still re-runs fetchRules/ccss unnecessarily.
- **Why matters:** Extra effect invocations, unnecessary `fetchRules()/fetchCcssStatus()` calls on activities arrival.
- **Evidence:** `deps: [activeTab, recentActivities.length, serverRules, rulesRetryTrigger, ccssRefreshTrigger]`.
- **Impact:** 2-3x API calls on first Dashboard mount.
- **Recommended solution:** Remove `recentActivities.length` from deps; rely on `hasFetchedActivities` + `activeTab==='dashboard'` only, or use empty deps with mount fetch.
- **Confidence:** 88%

### F-05 — FileOptimization monolith (2024 LoC) + duplicated save logic

- **File:Line:** `src/components/FileOptimization.js:1-2024`
- **Category:** Architecture / Duplication
- **Severity:** High
- **Problem:** Single component hosts 5 sub-tabs, 30+ switches, 3 save paths (`handleSubmit`, `handleSaveLiteSpeedMode`, `handleRegenerateCss/UsedCSS`) with near-identical `withNotification` wrappers. Cognitive load, untestable, prop drilling of serverRules/ccss through App.
- **Why matters:** High change risk, duplicated validation, merge conflicts, no memo boundary — any `settings` change re-renders 1800 lines.
- **Evidence:** `src/components/FileOptimization.js:175 withNotification` reused as async wrapper around already-invoked promise (see F-06); sub-tabs rendered via `activeSubTab === 'assets' && <div>`.
- **Impact:** Maintenance burden, slow HMR, higher bug surface.
- **Recommended solution:** Split into `FileOptimizationAssets`, `FileOptimizationScripts`, `FileOptimizationNetwork`, `FileOptimizationCore`, `LiteSpeedIntegrationCard`; extract `useFileOptimizationSettings` hook; memoize sub-panels.
- **Confidence:** 95%

### F-06 — `withNotification` IIFE executes before loading guard

- **File:Line:** `src/components/FileOptimization.js:175-231` (calls at 214-230, 233-254)
- **Category:** Correctness
- **Severity:** Medium
- **Problem:** `withNotification(async () => { const res = await apiCall(...) } )()` style is not used; instead callers pass `(async()=>{...})()` — the async IIFE *already started* before `withNotification` sets `isLoading`. If `apiCall` throws synchronously (wppoSettings missing), `withNotification`'s catch still runs but loading state flickers. Also `handleRegenerateUsedCSS` first saves settings then queues regen — if save fails it returns `saveRes` but `withNotification` still treats it as result with `success=false` and shows error toast with misleading "Failed to regenerate used CSS."
- **Why matters:** Confusing UX message when failure was actually settings save.
- **Evidence:** `handleRegenerateUsedCSS:244 if (!saveRes.success) return saveRes;`.
- **Impact:** Operator misattributes failure.
- **Recommended solution:** Pass a thunk `() => apiCall(...)` into `withNotification` and invoke inside try; or split into two sequential toasts: "Save failed" vs "Regeneration failed".
- **Confidence:** 85%

### F-07 — Stale `settings` spread on save (partial write risk)

- **File:Line:** `src/components/FileOptimization.js:258-275` ; `src/components/Dashboard.js:509-648`
- **Category:** State / Correctness
- **Severity:** High
- **Problem:** `handleSubmit` spreads *local* `settings` (which may be stale vs server if another tab saved concurrently via frozen global) into `update_settings`. Dashboard's `savePageCacheSettings` correctly re-reads `wppoSettings.settings.cache_settings` at call-time, but `FileOptimization.handleSubmit` does *not* — it just spreads local `settings`. Concurrent Dashboard + FileOptimization saves race and clobber.
- **Why matters:** Lost-update anomaly across tabs via global store mutation.
- **Evidence:** `src/components/FileOptimization.js:263 settings: { ...settings, delayJSDefaultStrategy... }` (redundant spread of same object) — no re-read of global.
- **Impact:** One tab's save silently reverts another tab's recent save.
- **Recommended solution:** Adopt Dashboard's pattern everywhere: `const current = wppoSettings.settings?.file_optimisation ?? {}; settings: { ...current, ...settings }` or better, single `update_settings` server merge.
- **Confidence:** 90%

### F-08 — `JSON.stringify(options)` as useEffect dep (infinite / missed updates)

- **File:Line:** `src/components/PreloadSettings.js:43-49`; `src/components/ImageOptimization.js:74-95`
- **Category:** Hook / Perf
- **Severity:** High
- **Problem:** `useEffect(..., [JSON.stringify(options)])` serializes on every render, allocates string, compares by value but loses referential stability. If `options` contains non-deterministic key order or `undefined` values, `JSON.stringify` output flips spuriously, causing extra `setSettings` and render loops. The eslint-disable hides the real fix.
- **Why matters:** Unnecessary re-renders, potential loop when parent App re-creates `settings` object via `wppoSettings?.settings ?? {} ` every render.
- **Evidence:** `src/components/PreloadSettings.js:49 // eslint-disable-next-line react-hooks/exhaustive-deps` then `[JSON.stringify(options)]`.
- **Impact:** Performance regression, wasted renders of large forms.
- **Recommended solution:** Depend on stable primitive deps or use deep-compare hook `useDeepCompareEffect`; or normalize prop at App.js via `useMemo(() => settings.preload_settings, [settings])`.
- **Confidence:** 92%

### F-09 — Direct `typeof wppoSettings` checks scattered (no invariant)

- **File:Line:** `src/App.js:136,145,261,509`; `src/components/PreloadSettings.js:52`; `src/components/ImageOptimization.js:272` etc. (161 occurrences)
- **Category:** Duplication / Correctness
- **Severity:** Medium
- **Problem:** Every component repeats `typeof wppoSettings !== 'undefined' ? wppoSettings?.x : fallback`. No shared hook/guard. In Jest `setupTests.js` sets `global.wppoSettings={}` with missing keys, causing branches to take fallback and tests to pass while production would have real keys — mask of bugs. Also violates DRY and invites typo.
- **Why matters:** Maintenance burden, test/prod divergence, missing key silently defaults to disabled.
- **Evidence:** `grep -rn wppoSettings src/ --include=*.js | wc -l` = 161; `src/setupTests.js:23 global.wppoSettings = {};`.
- **Impact:** Bugs hidden in tests; inconsistent fallback values across components.
- **Recommended solution:** Create `src/lib/wppoSettings.js` helper `getWppoSettings()`, `getCacheSettings()`, `isLiteSpeed()` with defaults; mock it properly in tests with full shape.
- **Confidence:** 90%

### F-10 — Polling + timer leaks (Dashboard + PageSpeedPanel)

- **File:Line:** `src/components/Dashboard.js:198-199,256-332`; `src/components/PageSpeedPanel.js:121-143,149-230`
- **Category:** Perf / Correctness
- **Severity:** High
- **Problem:** Dashboard stores `pollingRef.current = setTimeout(...)` but `pollJobStatus` closes over `currentTimeout` comparison to avoid race, yet `optimizeImages` does `clearTimeout(pollingRef.current); pollingRef.current=setTimeout(pollJobStatus,5000)` while previous poll may still be in-flight (no abort). PageSpeedPanel's `pollRef` similarly uses `isMounted` flag but still calls `notify` after unmount (notify triggers setState). Both never cancel the underlying `apiCall` fetch on unmount — only the timer.
- **Why matters:** Memory leak, setState on unmounted component warnings, duplicated status toasts, thundering herd after tab switch.
- **Evidence:** `src/components/Dashboard.js:322 if (pollingRef.current===currentTimeout) pollingRef.current=setTimeout(...)`; no `AbortController` passed to `apiCall('image_job_status',...,signal)`.
- **Impact:** Orphaned network requests, UI jank.
- **Recommended solution:** Pass `AbortSignal` to all polling apiCalls, abort on cleanup; use `useRef` for controller per poll cycle; in PageSpeedPanel, guard `notify` with `isMounted.current`.
- **Confidence:** 93%

### F-11 — WelcomePanel non-transactional double write

- **File:Line:** `src/components/WelcomePanel.js:70-107`
- **Category:** Correctness / API
- **Severity:** High
- **Problem:** `handleStepAction` does `await apiCall(update_settings)` then `await apiCall(dismiss_welcome)` without transaction. If first succeeds and second fails, feature is enabled but welcome panel remains visible forever with no retry. No rollback. Also the two saves bypass `Object.freeze` handling for the target tab's payload.
- **Why matters:** Stuck onboarding UX, partial state.
- **Evidence:** `src/components/WelcomePanel.js:74 updateRes = await apiCall('update_settings', ...); 78 dismissRes = await apiCall('dismiss_welcome'); if (updateRes.success && dismissRes.success) setVisible(false)`.
- **Impact:** User clicks "Enable", gets error toast, but setting actually persisted — confusion.
- **Recommended solution:** Combine into single atomic REST transaction or handle partial success: on `updateRes.success && !dismissRes.success` still hide panel and show warning; retry dismiss on next mount.
- **Confidence:** 88%

### F-12 — `handleChange` number coercion silently clamps without feedback

- **File:Line:** `src/lib/util.js:1-36` (used by 9 components)
- **Category:** Correctness / A11y
- **Severity:** Medium
- **Problem:** `delayJSIdleTimeout` clamp to `[500,20000]` and fallback to `3000` is silent. Invalid input like `0`, `-1`, `99999` is auto-corrected with no error toast or field-level validation. User thinks they saved `10000` but actually got `10000` (ok) vs `999` → `3000` silently.
- **Why matters:** Violates principle of least surprise; user can't tell why value reverted.
- **Evidence:** `src/lib/util.js:17 if (!Number.isFinite || value<=0) nextValue=3000; else Math.min(20000,Math.max(500,nextValue))`.
- **Impact:** Misconfiguration, support burden.
- **Recommended solution:** Add client validation with `notify` or inline field error; disable Save when out of bounds; show clamp warning.
- **Confidence:** 85%

### F-13 — Saved settings not validated client-side (XSS / URL injection)

- **File:Line:** `src/components/FileOptimization.js:1752 cdnURL`; `src/components/PreloadSettings.js:246 preconnectOrigins`; `src/components/ImageOptimization.js:501 excludeImages` etc.
- **Category:** Security / Correctness
- **Severity:** Medium
- **Problem:** Free-form textarea/URL fields accept arbitrary strings and are POSTed verbatim to `update_settings`. No client sanitization for URL, handle, or selector. Server may sanitize, but SPA immediately reflects via `wppoSettings` freeze and renders via `dangerously`? Not directly, but CDN URL is later interpolated into HTML generation; malformed value could break output buffering. Also `cdnURL` `type=url` has browser validation but submit via `handleSubmit` bypasses form validation (button `onClick` not `form onSubmit`).
- **Why matters:** Stored misconfig can break frontend rendering until manually fixed via DB.
- **Evidence:** `src/components/FileOptimization.js:1745 <input type=url value={settings.cdnURL} onChange={handleChange}>` with no pattern/validate before `apiCall`.
- **Impact:** Site breakage, FOUC, support.
- **Recommended solution:** Add lightweight client validators (URL constructor try, handle regex); block save with inline error; use `<form onSubmit>` with `reportValidity()`.
- **Confidence:** 80%

### F-14 — DatabaseCleanup `database_cleanup_counts` duplicate fetch with Dashboard

- **File:Line:** `src/components/Dashboard.js:219-247`; `src/components/DatabaseCleanup.js:150-189`
- **Category:** Duplication / Perf
- **Severity:** Medium
- **Problem:** Both Dashboard and DatabaseCleanup fetch identical `database_cleanup_counts` on mount, each with own loading state. When user lands on Dashboard then navigates to Database, two sequential identical GETs fire. No shared cache, no dedupe.
- **Why matters:** Wasted API calls, inconsistent counts if DB changed between fetches (stale).
- **Evidence:** Both call `apiCall('database_cleanup_counts',{},'GET')`.
- **Impact:** Extra load, minor UX flicker.
- **Recommended solution:** Lift counts fetch to `App.js` and pass as prop, or add SWR cache in `apiRequest.js` or custom hook `useDatabaseCounts()`.
- **Confidence:** 90%

### F-15 — ObjectCache hit ratio NaN / string compare

- **File:Line:** `src/components/ObjectCache.js:198-212`
- **Category:** Correctness
- **Severity:** Medium
- **Problem:** `hitRatio` computes `Number.parseInt(keyspace_hits ?? '0',10) || 0` — if API returns numeric `0` vs string `"0"` fine, but if returns `null`/`undefined` parseInt(`'0'`) ok. However `hitRatio` is string `'0.0'` from `toFixed(1)` compared via `parseFloat(hitRatio)` for `aria-valuenow` — not a number range violation but initial `'0.0'` as string passed to width `${hitRatio}%` works. More subtle: `progress-bar aria-valuenow={parseFloat(hitRatio)}` when `hitRatio` is `'0.0'` string parses to 0 but if telemetry missing returns `'0.0'` string still. No handling for `hits+misses===0` returns `'0.0'` not number 0, but styling still works. `connectionBadge` returns `null` correctly.
- **Why matters:** Minor but indicates missing unit test for edge telemetry shape.
- **Evidence:** `src/components/ObjectCache.js:204 Number.parseInt(cacheStatus.telemetry.keyspace_hits ?? '0',10)`.
- **Impact:** Low — mostly correct; but if backend returns integer not string, parseInt(123) still works (implicit toString) — fragile.
- **Recommended solution:** Use `Number(value) || 0` instead of parseInt; keep hitRatio as number until render.
- **Confidence:** 75%

### F-16 — PluginSetting import flow: FileReader not aborted on unmount, stale `selectedFile`

- **File:Line:** `src/components/PluginSetting.js:333-451`
- **Category:** Correctness / Perf
- **Severity:** Medium
- **Problem:** `FileReader` started via `reader.readAsText(selectedFile)` is never aborted if component unmounts before `onload`. `cancelledRef` only guards `notify` but not reader. `selectedFile` captured via closure at click time may be stale if user re-selects quickly. `resetFileInput` clears input value but not `selectedFile` race if import in flight.
- **Why matters:** Potential `setIsImporting(false)` on unmounted component; double import.
- **Evidence:** `src/components/PluginSetting.js:347 reader.readAsText(selectedFile);` no `useEffect` cleanup for reader.
- **Impact:** Console warning, stuck loading state.
- **Recommended solution:** Store `readerRef`, abort in cleanup `useEffect(() => () => readerRef.current?.abort())`; disable file input while importing.
- **Confidence:** 82%

### F-17 — Password sent as plain text via `settings` in ObjectCache enable/ping

- **File:Line:** `src/components/ObjectCache.js:147-163` ; `src/lib/apiRequest.js:78-86`
- **Category:** Security
- **Severity:** High
- **Problem:** `handleAction('enable')` sends full `settings` including `password` as JSON body over REST. While REST is admin+nonce protected, the password is stored in React state and could be logged via `console.error` in `apiRequest.js:128 console.error('API call failed:', action, error)` — error includes action string but not body, but `settings` object lives in memory and could be exposed via React DevTools. More critical: `wppoSettings` is rendered into page source via `wp_localize_script` — if it contains password (it shouldn't, but check), it would leak. Current code stores password in `options` prop from PHP — need to verify PHP never echoes it.
- **Why matters:** Credential exposure risk; OWASP.
- **Evidence:** `src/components/ObjectCache.js:157 payload = {action, ...settings}` where `settings.password` is included for enable/ping.
- **Impact:** If browser extension or XSS reads state, credential leak.
- **Recommended solution:** Ensure PHP `allowedSettingsKeys` redacts password on GET; use separate `test-connection` endpoint that does not persist; clear password from state after enable; avoid logging. Document that `wppoSettings.settings.object_cache.password` must never be localized.
- **Confidence:** 88% (requires PHP verification — flagged as high pending review)

### F-18 — ApiRequest nonce refresh thundering herd guard incomplete

- **File:Line:** `src/lib/apiRequest.js:1-57,71-131`
- **Category:** Correctness / Perf
- **Severity:** Medium
- **Problem:** `pendingRefresh` dedupes concurrent `refreshNonce()` calls, but `doFetch` is called with `null` nonce first, then `handleResponse` may trigger `refreshNonce()` and retry. If 3 parallel `apiCall`s get 403 simultaneously, they each call `handleResponse` which each await `refreshNonce()` — deduped to one fetch via `pendingRefresh`, good. But the retry `doFetch(freshNonce)` is done *inside each* `handleResponse` individually, causing 3 retries. Also `refreshNonce` does not pass `signal`; abort during refresh leaks.
- **Why matters:** Extra load during nonce expiry window; failed abort.
- **Evidence:** `src/lib/apiRequest.js:110 const freshNonce = await refreshNonce(); const retryResponse = await doFetch(freshNonce);`.
- **Impact:** 3x retry requests on expiry burst.
- **Recommended solution:** Centralize retry queue or make `apiCall` serialise 403 retries; pass signal to `refreshNonce`; add jitter.
- **Confidence:** 80%

### F-19 — Missing `AbortSignal` propagation to most apiCall sites

- **File:Line:** `src/lib/apiRequest.js:164,176,191,211,222` (fetchSystemInfo, queuePagespeedScan, etc.); `src/components/AutoloadedOptions.js:29` ; `src/components/WebVitalsRum.js:53` etc.
- **Category:** Correctness
- **Severity:** Medium
- **Problem:** Newer `fetchRecentActivities`/`runPerformanceScan` accept `signal` but older helpers (`fetchSystemInfo`, `queuePagespeedScan`, `getPagespeedResults`, `fetchWebVitalsTrends`, `AutoloadedOptions.load`, `WebVitalsRum.load`) do not wire an `AbortSignal`. If user navigates away mid-fetch, `setState` after unmount warning.
- **Why matters:** React "can't perform state update on unmounted component" warnings; memory leak.
- **Evidence:** `src/lib/apiRequest.js:164 export const fetchSystemInfo = () => { return apiCall('system_info',{},'GET'); }` — no signal param.
- **Impact:** Noise, potential stale data overwrite.
- **Recommended solution:** Add optional `signal` param to all api helpers and pass from `useEffect` abort controllers; in components store controller ref and abort on unmount.
- **Confidence:** 92%

### F-20 — PageSpeedPanel polling timeout silent on success= false (error handling)

- **File:Line:** `src/components/PageSpeedPanel.js:174-188`
- **Category:** Correctness
- **Severity:** Medium
- **Problem:** `getPagespeedResults` returned `{success:false}` is treated as fatal and stops polling, but `response.message` may be generic. No retry. If transient 500, user must manually click Scan again. Also `MAX_POLL_ATTEMPTS=60` fixed interval 5s = 5 min; no exponential backoff, worst-case 60 API hits.
- **Why matters:** Poor resilience, hammering API.
- **Evidence:** `src/components/PageSpeedPanel.js:174 if (!response.success) { stopPolling(); setPending(false); ... }`.
- **Impact:** User-facing failure for transient backend hiccup.
- **Recommended solution:** Retry on 5xx with backoff; distinguish `not_ready` (202) vs error; cap retry with jitter.
- **Confidence:** 85%

### F-21 — PerformanceAudit `handleScan` missing URL validation + abort race

- **File:Line:** `src/components/PerformanceAudit.js:292-379`
- **Category:** Correctness / Security
- **Severity:** Medium
- **Problem:** `url` state is user-provided `<input type=url required>` but `handleScan` does not validate `new URL(url).origin` matches `homeUrl` origin before calling `runPerformanceScan`. Backend may restrict, but SPA allows arbitrary external URL scan which could be abused for SSRF probe via telemetry. Also `abortControllerRef.current.abort()` then immediately creates new controller but previous `runPerformanceScan` promise may still resolve and overwrite `result` after new scan started (no generation counter).
- **Why matters:** UX: scanning external URL silently fails; race causes stale result flash.
- **Evidence:** `src/components/PerformanceAudit.js:304 if (abortControllerRef.current) abortControllerRef.current.abort(); 307 abortControllerRef.current = new AbortController();`.
- **Impact:** Stale results, potential SSRF surface.
- **Recommended solution:** Validate URL via `URL` constructor and same-origin check before scan; add generation counter `scanIdRef` to ignore stale resolves; disable Run Scan when `! URL.canParse(url)` or origin mismatch.
- **Confidence:** 87%

### F-22 — SystemInfo `InfoRow` stringifies objects incorrectly + missing sanitization

- **File:Line:** `src/components/SystemInfo.js:27-36`
- **Category:** Correctness
- **Severity:** Low
- **Problem:** `InfoRow value !== null && value !== '' ? String(value) : '—'` — if PHP returns nested object (e.g. `wp_constants` includes array values), `String({})` → `[object Object]`. User sees meaningless text. No handling for boolean `false` (is `false !== ''` true → `String(false)` = "false" OK) but `0` also ok.
- **Why matters:** Admin sees broken System Info.
- **Evidence:** `src/components/SystemInfo.js:31 { value !== null && value !== undefined && value !== '' ? String(value) : '—' }`; `src/components/SystemInfo.js:346 data={info.wp_constants}` could be object map.
- **Impact:** Confusing display for `wp_constants` table.
- **Recommended solution:** Render with `JSON.stringify` fallback for objects/arrays, or flatten `wp_constants` entries as key/value rows already; guard `typeof value === 'object' ? JSON.stringify(value) : String(value)`.
- **Confidence:** 80%

### F-23 — SuggestionsPanel `FIX_ACTION_TAB_MAP` coupling (fragile)

- **File:Line:** `src/components/SuggestionsPanel.js:34-42`
- **Category:** Duplication / Correctness
- **Severity:** Medium
- **Problem:** Hardcoded map from PHP `fix_action` strings to SPA `activeTab` keys must stay in sync with `App.js:sidebarItems` names. Added `open_ccss_settings` correctly but if PHP adds new `fix_action` the SPA silently shows no Fix It button (fallback `null`). No runtime warning. `memo` on panel is pointless because parent `Dashboard` recreates `onNavigate` via `setActiveTab` (stable? Actually `setActiveTab` is stable from useState, but linter not enforcing).
- **Why matters:** New backend suggestions will silently not navigate.
- **Evidence:** `src/components/SuggestionsPanel.js:38 FIX_ACTION_TAB_MAP` comment "Must stay in sync with App.js".
- **Impact:** Dead Fix It button for future metrics.
- **Recommended solution:** Single source of truth: export `SIDEBAR_TABS` from `App.js` and derive map; fallback to `'dashboard'` with warning `console.warn('Unknown fix_action', fixAction)`.
- **Confidence:** 85%

### F-24 — FeatureCard/FeatureHeader props children misuse + className injection

- **File:Line:** `src/components/common/FeatureCard.js:20`; `src/components/common/FeatureHeader.js:11`
- **Category:** Security / Correctness
- **Severity:** Low
- **Problem:** `className` is interpolated directly into `className` attribute without sanitization. If caller passes user-controlled string (not currently, but future), could inject. `FeatureHeader` renders `title` as `{title}` without handling if title is React node vs string — `h2>{title}<` double-encodes? Actually fine. More subtle: `FeatureCard` always renders `__header` even if title is empty string falsy check `title || actions` passes if actions truthy but title empty, causing empty `<h3>` layout shift.
- **Why matters:** Minor layout bug; low security but hygiene.
- **Evidence:** `src/components/common/FeatureCard.js:20 <div className={`wppo-feature-card ${className||''}`.trim()}>`.
- **Impact:** Empty header spacing when only actions provided.
- **Recommended solution:** Normalize `className` via `clsx` allowlist; guard `title && <h3>` independently from `actions`.
- **Confidence:** 75%

### F-25 — Tooltip keyboard handling incomplete (a11y)

- **File:Line:** `src/components/common/Tooltip.js:26-47`
- **Category:** A11y
- **Severity:** Medium
- **Problem:** Container has `tabIndex=0` and `aria-describedby={id}` but `role=tooltip` content is always in DOM (hidden via CSS). Screen readers announce tooltip even when not visible. No `Esc` to dismiss, no `aria-hidden`. `onFocus/onBlur` toggles `visible` but focus trap not handled; keyboard user tabbing through 30 fields will see tooltip flash. When `children` is provided, wrapper `<span>` around interactive element (e.g. `<SwitchField>`) creates nested interactive inside focusable span — invalid HTML.
- **Why matters:** WCAG 1.4.13, fails axe.
- **Evidence:** `src/components/common/Tooltip.js:30 tabIndex="0" aria-describedby={id}` with always-present `<span role=tooltip id={id}>`.
- **Impact:** AT noise, nested interactive violation.
- **Recommended solution:** Render `role=tooltip` conditionally only when `visible`; use `@wordpress/components` Tooltip or add `aria-hidden={!visible}`; avoid wrapping interactive children — render as sibling with `aria-describedby` reference via `useId`.
- **Confidence:** 90%

### F-26 — SwitchField synthesizes event (anti-pattern)

- **File:Line:** `src/components/common/SwitchField.js:25-34`
- **Category:** Correctness / A11y
- **Severity:** Medium
- **Problem:** `handleToggle` fabricates `{target:{name,type:'checkbox',checked:newValue}}` to satisfy `handleChange` util that expects `e.target`. This bypasses native event, loses `event.preventDefault`, `stopPropagation`, and breaks if consumer expects real `event`. Also `ToggleControl` hidden label `hideLabelFromVision` + custom visible `<span>` label duplicates accessible name — screen reader hears label twice.
- **Why matters:** Fragile coupling; AT duplication; breaks if util evolves to read `e.persist()` etc.
- **Evidence:** `src/components/common/SwitchField.js:26 onChange({target:{name,type:'checkbox',checked:newValue}})`.
- **Impact:** Double announcement, potential stale `name` if prop missing.
- **Recommended solution:** Refactor `handleChange` to accept `(name,value)` directly or make SwitchField call `setSettings(prev=>({...prev,[name]:newValue}))` without synthetic event; use single label via `ToggleControl` `label` without extra span or set `aria-labelledby`.
- **Confidence:** 88%

### F-27 — ConfirmDialog body scroll lock leak

- **File:Line:** `src/components/common/ConfirmDialog.js:91-103`
- **Category:** A11y / Correctness
- **Severity:** Medium
- **Problem:** `useEffect(() => { if(isOpen) doc.body.style.overflow='hidden'; return()=>doc.body.style.overflow=''; },[isOpen])` — if dialog opens then *re-renders* with `isOpen` still true, effect cleans up previous effect (sets overflow `''`) then re-applies `hidden`. Works, but if two dialogs ever open (nested), second effect overwrites then cleanup of first resets to `''` while second still open, leaking scroll. Also focus return logic checks `previouslyFocused.isConnected` but not whether focus target is still visible.
- **Why matters:** Scroll jank, focus loss.
- **Evidence:** `src/components/common/ConfirmDialog.js:97 doc.body.style.overflow='hidden';` no refcount.
- **Impact:** Page scrollable while dialog open if multiple dialogs, or locked after close.
- **Recommended solution:** Use counter ref for body lock or use `@wordpress/components` Modal; guard cleanup to only reset if current dialog set the lock.
- **Confidence:** 82%

### F-28 — LoadingSubmitButton `aria-busy` without disabled semantics forAT

- **File:Line:** `src/components/common/LoadingSubmitButton.js:30-35`
- **Category:** A11y
- **Severity:** Low
- **Problem:** `aria-busy={isLoading}` on `<button>` is not valid for button role (use `aria-busy` on live region). Correct pattern is `disabled` + `aria-live` region for status text, which is present via inner `<span role=status aria-live=polite>`, good. But `aria-busy` on button is ignored/atypical and may confuse AT.
- **Why matters:** Minor AT noise.
- **Evidence:** `src/components/common/LoadingSubmitButton.js:34 aria-busy={isLoading}`.
- **Impact:** Low.
- **Recommended solution:** Remove `aria-busy` from button; rely on `disabled` + live region.
- **Confidence:** 80%

### F-29 — ErrorBoundary `window.location.reload()` without user intent

- **File:Line:** `src/components/common/ErrorBoundary.js:34-39`
- **Category:** A11y / Correctness
- **Severity:** Medium
- **Problem:** Hard reload loses unsaved form state (e.g. half-filled FileOptimization). No "Try again" (reset error state) fallback. Does not call `wppoSettings` save. Also `componentDidCatch` logs but not report to server.
- **Why matters:** Data loss on error.
- **Evidence:** `src/components/common/ErrorBoundary.js:35 onClick={() => window.location.reload()}`.
- **Impact:** Operator loses edits.
- **Recommended solution:** Add `this.setState({hasError:false})` reset button alongside Reload; persist draft to localStorage.
- **Confidence:** 85%

### F-30 — MetricCard dead code (tested but not rendered)

- **File:Line:** `src/components/common/MetricCard.js:1-31` ; `src/components/PerformanceAudit.js` comment implies inline
- **Category:** Dead Code / Duplication
- **Severity:** Low
- **Problem:** `MetricCard` is kept for "future metric grids" but not used in current `wppo-audit-overview-card` inline implementation. `AUDIT/DEAD-CODE.md` already flagged. Keeps bundle size small but duplicates `formatBytes` logic elsewhere.
- **Why matters:** Confusing for contributors; test covers unused primitive.
- **Evidence:** `src/components/common/MetricCard.js:10 @since NEXT Retained for backward compatibility — currently not rendered`.
- **Impact:** Bundle bloat minimal, conceptual debt.
- **Recommended solution:** Either use it in `PerformanceAudit.MetricOverview` or delete and document removal; remove dead tests.
- **Confidence:** 95%

### F-31 — formatBytes duplicated 3x (DRY)

- **File:Line:** `src/components/ImageOptimizationCard.js:19-29`; `src/components/PerformanceAudit.js:145-156`; `src/components/AutoloadedOptions.js:62-67` (also `Dashboard` has cache_size string variant)
- **Category:** Duplication
- **Severity:** Low
- **Problem:** Identical byte formatting logic repeated with slight variants (`toFixed(1)` vs `toFixed(2)`). No shared util.
- **Why matters:** Divergence risk, extra bytes.
- **Evidence:** All three `const formatBytes = (bytes)=>{ if(!bytes) return '0 B'; ... }`.
- **Impact:** Maintenance.
- **Recommended solution:** Extract `src/lib/formatBytes.js` and reuse.
- **Confidence:** 98%

### F-32 — App.js `renderContent` recreated on every render (no memo)

- **File:Line:** `src/App.js:134-195`
- **Category:** Perf
- **Severity:** Medium
- **Problem:** `renderContent` is a function that allocates `settings` object + `components` map (7 JSX elements) on every `App` render, even though only `activeTab` matters. `ErrorBoundary` + `Suspense` re-mount fallback unnecessarily. Also `settings` computed via `wppoSettings?.settings ?? {}` not memoized — new object each call.
- **Why matters:** Wasted reconciliation, Suspense fallback flash on rapid tab switch.
- **Evidence:** `src/App.js:135 const settings = typeof wppoSettings !=='undefined' ? wppoSettings?.settings ?? {} : {};`.
- **Impact:** Extra renders of heavy FileOptimization (2024 LoC) subtree.
- **Recommended solution:** Memoize `settings` via `useMemo(() => wppoSettings?.settings ?? {}, [wppoSettings?.settings])` or lift to state; memoize `components` map; or use routing `activeTab` conditional outside render.
- **Confidence:** 85%

### F-33 — Sidebar overlay `role=button` keyboard handler incomplete

- **File:Line:** `src/App.js:435-450`
- **Category:** A11y
- **Severity:** Medium
- **Problem:** Overlay `<div role=button tabIndex=0 onClick ... onKeyDown>` handles `Enter` and `' '` (space) but checks `e.key === ' '` (space character) — in many browsers space key is `' '` correct but also `'Spacebar'` legacy, and `e.key===' '` with modifier may not fire. More importantly, overlay is `role=button` with `aria-label Close Menu` but is visually just backdrop — should be `role=presentation` with click to close, not button. Focusable overlay steals tab order.
- **Why matters:** Keyboard trap, axe violation.
- **Evidence:** `src/App.js:438 onKeyDown={(e)=>{ if(e.key==='Enter'||e.key===' ') toggleMobileMenu(); }}`.
- **Impact:** AT confusion, tab focus landing on invisible overlay.
- **Recommended solution:** Make overlay `<div role=presentation onClick={toggle}>` without tabindex; handle Esc to close via document listener already present for focus trap.
- **Confidence:** 82%

### F-34 — Index.js no double-mount guard (React 18 StrictMode)

- **File:Line:** `src/index.js:6-11`
- **Category:** Correctness
- **Severity:** Low
- **Problem:** `createRoot(rootElement).render(<App/>)` called at import time. In React 18 StrictMode dev double-mount, root is created twice if HMR reloads module, causing `createRoot` warning: "already has root". No idempotent guard.
- **Why matters:** Dev console spam, potential memory leak.
- **Evidence:** `src/index.js:6 const rootElement = document.getElementById('performance-optimisation'); if(rootElement){ const root=createRoot(rootElement); root.render(<App/>); }`.
- **Impact:** Dev only.
- **Recommended solution:** Use `if (!rootElement._wppoRoot) { rootElement._wppoRoot = createRoot(...)}` or store on window.
- **Confidence:** 78%

### F-35 — setupTests mocks incomplete (false positive coverage)

- **File:Line:** `src/setupTests.js:23-55`
- **Category:** Testing / Correctness
- **Severity:** Medium
- **Problem:** Mocks `global.wppoSettings={}` (empty) and `@wordpress/components` only `ToggleControl` → checkbox. Production components render `<FontAwesomeIcon>` and many `@wordpress/i18n` usages (`_n`, `sprintf`). Tests pass with empty wppoSettings because every component has `typeof wppoSettings !=='undefined' ? wppoSettings.x : fallback` — but fallback paths are not the production path, so coverage does not exercise real branch. Also `matchMedia` mock always `matches:false` hides responsive sidebar tests.
- **Why matters:** Tests give false confidence; production bug where code assumes `wppoSettings.settings.file_optimisation` exists is not caught.
- **Evidence:** `src/setupTests.js:23 global.wppoSettings={}; 39 ToggleControl: ({checked,onChange}) => <input type=checkbox .../>`.
- **Impact:** Undetected regressions.
- **Recommended solution:** Provide realistic `wppoSettings` fixture matching PHP `wp_localize_script` shape (all tabs, litespeed, performance_audit, userRoles); mock FontAwesome; add test for frozen mutation path.
- **Confidence:** 90%

### F-36 — CriticalCssPanel `onRegenerate` error swallowed double-notify

- **File:Line:** `src/components/CriticalCssPanel.js:37-57` ; `src/components/FileOptimization.js:213-231`
- **Category:** Correctness
- **Severity:** Low
- **Problem:** `CriticalCssPanel.handleRegenerate` catches error and notifies, but `FileOptimization.handleRegenerateCss` also wraps via `withNotification` which notifies again on error. Results in duplicate error toasts (one from panel, one from parent `useNotice` not shared). Actually they use separate `useNotice` instances, so two banners appear in different places.
- **Why matters:** Duplicate toasts confuse.
- **Evidence:** `CriticalCssPanel` has own `useNotice`; `FileOptimization` passes `onRegenerate` which is `withNotification(...)` — both notify.
- **Impact:** Double error banner.
- **Recommended solution:** Remove notify from CriticalCssPanel and let parent handle; or make panel purely presentational without own notice.
- **Confidence:** 88%

### F-37 — WebVitalsTrends `buildPoints` min/max inversion when single value

- **File:Line:** `src/components/WebVitalsTrends.js:33-52`
- **Category:** Correctness
- **Severity:** Medium
- **Problem:** `const max = Math.max(...values,100); const min = Math.min(...values,0); const range = max-min ||1;` If values = `[95]`, max=100 min=0 range=100 — point computed correctly but chart is anchored to 0-100 not to actual range, flattening variation. If values = `[0,100]` range 100 fine. If values = `[95,96,97]` range 100 again, tiny 2px variation. Intended to always show 0-100 scale is okay for score, but `min` includes 0 even when all scores are 95-100, wasting vertical space. Also `values.length-1` denominator when `values.length===1` is `Math.max(0,1)` →1, so `stepX` = `width-2*PAD_X` /1 → point at PAD_X, not centered.
- **Why matters:** Misleading trend visualization.
- **Evidence:** `src/components/WebVitalsTrends.js:37 max=Math.max(...values,100); min=Math.min(...values,0);`.
- **Impact:** Chart looks flat despite real improvement.
- **Recommended solution:** If design wants 0-100 scale keep as is but document; else use actual `min=Math.min(...values)` with padding; center single point at `SPARK_WIDTH/2`.
- **Confidence:** 80%

### F-38 — RecentActivityCard slices without key stability

- **File:Line:** `src/components/RecentActivityCard.js:49`
- **Category:** Correctness
- **Severity:** Low
- **Problem:** `activities.slice(0,5).map(activity=> <li key={activity.id}>` assumes `activity.id` is stable and numeric. If backend returns duplicate id across pages (unlikely) React will mis-reconcile. Also `activities` prop may be `undefined` (App passes `recentActivities?.activities` which after first fetch is array but initially `[]` object). `activities?.length` check passes when `activities` is empty array `[]`? Actually initial `recentActivities` is `[]` array, so `recentActivities?.activities` is `undefined` → card shows empty state correctly. After fetch `recentActivities` becomes `{activities:[...]}` object, so `recentActivities.length` in App dep is stale (object length undefined). This ties to F-04.
- **Why matters:** Subtle prop shape mismatch.
- **Evidence:** `src/App.js:79 const [recentActivities,setRecentActivities]=useState([]);` then `activities={recentActivities?.activities}`.
- **Impact:** Initial render shows "No activity" correctly but App effect deps reading `recentActivities.length` reads `undefined` after fetch (array → object).
- **Recommended solution:** Initialise `recentActivities` as `{activities:[], total_pages:0}` or `null`; normalize shape.
- **Confidence:** 85%

### F-39 — AutoloadedOptions + WebVitalsRum no empty-state retry

- **File:Line:** `src/components/AutoloadedOptions.js:70-94`; `src/components/WebVitalsRum.js:96-135`
- **Category:** UX / Correctness
- **Severity:** Low
- **Problem:** Both show static empty message with no "Retry" button, unlike SystemInfo or PluginSetting activity log which have Retry. If transient fetch fails, user must reload page.
- **Why matters:** Operator friction.
- **Evidence:** `src/components/AutoloadedOptions.js:70 if(error) body=<p>{error}</p>` no button.
- **Impact:** Poor UX.
- **Recommended solution:** Add `<button onClick={load}>Retry</button>` and expose error dismiss via `useNotice`.
- **Confidence:** 90%

### F-40 — EdgeCachePanel numeric inputs allow empty / NaN

- **File:Line:** `src/components/EdgeCachePanel.js:169-198` (`ttl`, `swr` as `String` state, `<input type=number>`)
- **Category:** Correctness
- **Severity:** Medium
- **Problem:** `ttl` and `swr` stored as strings, sent via `parseInt(ttl,10)||300` — empty string → 300 fallback silently, `"abc"` → 300, negative `"-1"` → -1 then `||300`? Actually `-1 ||300` is `-1` truthy so -1 sent to server (invalid). No controlled validation, `min=60` attribute is not enforced when form submitted via `onClick` bypassing native validation.
- **Why matters:** Invalid edge cache TTL could poison edge.
- **Evidence:** `src/components/EdgeCachePanel.js:63 ttl: parseInt(ttl,10)||300`.
- **Impact:** Server may reject or store negative TTL.
- **Recommended solution:** Validate `ttl >=60 && ttl <= 86400`, `swr >=0`, disable Save when invalid, show inline error.
- **Confidence:** 88%

### F-41 — AiPanel `handleApply` injects arbitrary tab payload (trust boundary)

- **File:Line:** `src/components/AiPanel.js:139-189`
- **Category:** Security / Correctness
- **Severity:** High
- **Problem:** `suggestion.ai_payload` comes from REST `ai_suggestions` (server-controlled, but if attacker can poison model, they control `{tab,settings}`) is spread into `update_settings` without allowlist check against `ALLOWED_IMPORT_KEYS`. `tab` could be any string including `litespeed_integration` or `object_cache` with crafted settings. No validation that `payload.settings` is object not array. Client also merges with `wppoSettings.settings[payload.tab]` shallowly — nested arrays not sanitized.
- **Why matters:** Client-side trust of server payload is okay if server is trusted, but defense-in-depth missing; matches PluginSetting's `validateImportData` allowlist but not reused.
- **Evidence:** `src/components/AiPanel.js:149 const currentTabSettings = wppoSettings.settings?.[payload.tab] || {}; const merged={...currentTabSettings,...payload.settings};`.
- **Impact:** Potential elevation via model poisoning if server AI logic compromised.
- **Recommended solution:** Validate `payload.tab ∈ ALLOWED_IMPORT_KEYS` and `typeof payload.settings==='object'` before apiCall; reuse `validateImportData` logic.
- **Confidence:** 85%

### F-42 — Dashboard CDN purge service state diverges from props (stale)

- **File:Line:** `src/components/Dashboard.js:153-193`
- **Category:** State
- **Severity:** Medium
- **Problem:** `useEffect` syncs derived state when `cacheSettings` props change, deps list includes `cacheSettings.enableCache` etc. but `cacheSettings` is object; if parent re-creates object with same values, effect runs unnecessarily. Conversely, if `wppoSettings.settings.cache_settings` is mutated via frozen replacement (apiRequest) Dashboard does not re-read because `propCacheSettings` is stale (App's `settings` memo missing). Depends on App's `renderContent` re-render to pass new `cacheSettings`.
- **Why matters:** After Dashboard save via `savePageCacheSettings`, local `pageCacheEnabled` stays as saved value but derived sync may overwrite with stale prop if apiCall's freeze not reflected in prop.
- **Evidence:** `src/components/Dashboard.js:185 deps: [cacheSettings.enableCache, ...]`.
- **Impact:** Flicker back to old value after save.
- **Recommended solution:** Lift cache settings to React state at App and pass via context; use single source of truth; avoid derived state — read directly from prop.
- **Confidence:** 82%

### F-43 — `src/lib/litespeed.js` logic assumes browser detection matches PHP (drift risk)

- **File:Line:** `src/lib/litespeed.js:20-39`
- **Category:** Duplication / Correctness
- **Severity:** Low
- **Problem:** Comment says "Mirrors PHP LiteSpeed_Integration::effective_mode()" — but JS default `mode='auto'` param default differs from PHP where `mode` comes from DB string. If `isLiteSpeed` detection diverges (header sniff vs server var), UI shows mismatched badge vs actual optimization behaviour.
- **Why matters:** Confusing banner (says "WPPO owns cache" while PHP actually disabled optimizer).
- **Evidence:** `src/lib/litespeed.js:20 getEffectiveMode({mode='auto', isLiteSpeed=false,...})`.
- **Impact:** Support confusion only.
- **Recommended solution:** Don't duplicate logic; have PHP expose `effective_mode` already in `wppoSettings.litespeed.effective_mode` and use directly; deprecate JS mirror or add sync test.
- **Confidence:** 80%

### F-44 — FileOptimization `delayJSIdleTimeout` visible condition logic bug

- **File:Line:** `src/components/FileOptimization.js:1039-1074`
- **Category:** Correctness
- **Severity:** Medium
- **Problem:** `{ (settings.delayJSDefaultStrategy==='idle' || settings.delayJSIdleList) && <IdleTimeoutInput> }` — timeout field appears if *either* strategy is idle OR idleList is non-empty. If strategy is `interaction` but idleList has content, timeout is shown but irrelevant (timeout only applies to idle scripts). Confusing UX; also not disabled when `optimizerDisabled`.
- **Why matters:** Operator edits timeout thinking it applies to interaction strategy.
- **Evidence:** `src/components/FileOptimization.js:1039 {(settings.delayJSDefaultStrategy==='idle' || settings.delayJSIdleList) && (`.
- **Impact:** Misconfiguration.
- **Recommended solution:** Show timeout only when `strategy==='idle'` OR when `delayJSIdleList` non-empty *and* strategy==='idle'? Actually timeout applies to idle list regardless of default — so show when `strategy==='idle' || delayJSIdleList.trim() !== ''` but add help text clarifying scope. Simpler: always show when `delayJS` enabled.
- **Confidence:** 85%

### F-45 — Missing `aria-live` for async notices (inconsistent)

- **File:Line:** `src/components/AutoloadedOptions.js:98-119`; `src/components/WebVitalsRum.js:96-108`
- **Category:** A11y
- **Severity:** Low
- **Problem:** Those panels render plain `<p className=wppo-text-muted>{error}</p>` without `role=alert`/`aria-live`, while `NoticeBanner` correctly uses `role=alert` for errors. Screen reader won't announce fetch error.
- **Why matters:** Inconsistent AT experience.
- **Evidence:** `src/components/AutoloadedOptions.js:71 body=<p className=wppo-text-muted>{error}</p>`.
- **Impact:** AT users miss errors.
- **Recommended solution:** Use `NoticeBanner` or add `role=alert aria-live=assertive` to error blocks.
- **Confidence:** 90%

---

## Cross-Cutting Themes

| Theme | Occurrence | Recommendation |
|-------|------------|----------------|
| **Global mutable singleton** | 161 `wppoSettings` reads, 5 direct writes, 1 freeze point | Introduce `src/lib/store.js` with `getSettings/setSettings` and React context `WppoSettingsContext`; remove direct global mutation. |
| **JSON.stringify dep** | 2 files | Replace with deep-compare hook or lift memo. |
| **Polling without abort** | 2 panels + Dashboard | Standardize `usePolling` hook with AbortSignal + generation counter. |
| **Duplicated byte formatting** | 3 files | Shared `formatBytes` util. |
| **A11y inconsistent** | Tooltip, ConfirmDialog, SwitchField, notices | Run `axe-core` on SPA; adopt `@wordpress/components` primitives where possible. |
| **Test/prod divergence** | `setupTests.js` empty fixture | Provide faithful `wppoSettings` mock fixture. |
| **Monolithic FileOptimization** | 2024 LoC | Split by sub-tab. |

## Impact Summary

- **Critical path (settings persistence):** F-01, F-07, F-11, F-17 could cause settings not to stick or credential mishandling.
- **Reliability (polling/abort):** F-02, F-10, F-19 cause flaky Dashboard, orphaned requests, console errors.
- **UX correctness:** F-12, F-40, F-43, F-44 lead to silent clamping/misleading fields.
- **A11y:** F-25, F-27, F-33 flagged as medium — ally audit would fail.
- **Maintainability:** F-05, F-08, F-09, F-31, F-38 dominate future change risk.

## Verification Performed

- [x] `wc -l src/**/*.js` counted lines (13018 total, ~12974 assigned)
- [x] `grep -rn wppoSettings` / `apiCall` / `useNotice` executed in bash (161 / 264 / 203 hits)
- [x] Every assigned file opened via `Read` (with offset for 2024-line FileOptimization) and traced for state flow, hooks deps, apiCall error paths, global mutation, duplication, a11y attributes
- [x] No production code edited (audit-only per assignment)
- [x] Output written to `AUDIT/AGENTS/agent-A06-js-spa.md` (this file)

## Confidence Notes

- High confidence (≥90%) where evidence is direct code line + reproducible behavior.
- Medium confidence (80-89%) where impact depends on PHP backend validation not inspected in this JS-only scope.
- Low explicit (75%): marked as such when inference about backend or future contributor behaviour.

---

*Generated by Agent A06 — JS React SPA specialist. For follow-up, prioritize F-01, F-02, F-05, F-10, F-17, F-41 as first fix wave.*
