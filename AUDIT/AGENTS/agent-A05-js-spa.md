# Agent A05 — React SPA Audit (js-spa)

**Scope:** `src/App.js`, `src/index.js`, `src/lib/apiRequest.js`, `src/lib/useNotice.js`, `src/lib/util.js`, `src/lib/litespeed.js`, `src/components/*.js` (Dashboard `1327`, FileOptimization `2024`, PreloadSettings `623`, ImageOptimization `975`, ImageOptimizationCard `208`, DatabaseCleanup `644`, ObjectCache `902`, PluginSetting `968`, PageSpeedPanel `479`, PerformanceAudit `841`, CriticalCssPanel `123`, LlmsPanel `152`, AutoloadedOptions `133`, SuggestionsPanel `270`, SystemInfo `353`, WebVitalsTrends `229`, WebVitalsRum `177`, RecentActivityCard `70`, WelcomePanel `272`), `src/components/common/*.js` (SwitchField `62`, FeatureCard `43`, FeatureHeader `33`, StatusBadge `35`, MetricCard `27`, NoticeBanner `55`, Tooltip `51`, CheckboxOption `84`, ConfirmDialog `182`, LoadingSubmitButton `52`, ErrorBoundary `48`), `src/setupTests.js` (`56`)

**Date:** 2026-08-27
**Auditor:** agent A05 `js-spa`
**Mode:** read-only, exhaustive line-by-line, no production code modified

---

## Files Reviewed (line counts via `wc -l`)

| File | Lines | Notes |
|------|-------|-------|
| `src/App.js` | 527 | Orchestrator, lazy tabs, sidebar, data prefetch |
| `src/index.js` | 11 | Mount `createRoot` guard |
| `src/lib/apiRequest.js` | 249 | Central fetch, nonce refresh thundering-herd, global mutation |
| `src/lib/useNotice.js` | 74 | Shared notice hook with auto-dismiss timer |
| `src/lib/util.js` | 33 | `handleChange` generic form handler |
| `src/lib/litespeed.js` | 67 | Pure mode helpers, no WP deps |
| `src/components/Dashboard.js` | 1327 | Largest hub, 8 sub-cards, polling, cdn, cache settings |
| `src/components/FileOptimization.js` | 2024 | 5 sub-tabs, delayJS, CDN, core tweaks, litespeed |
| `src/components/PreloadSettings.js` | 623 | Cache warm-up, preconnect, speculation rules |
| `src/components/ImageOptimization.js` | 975 | Lazy, picture, WebP/AVIF, LCP preload |
| `src/components/ImageOptimizationCard.js` | 208 | Progress bars, savings |
| `src/components/DatabaseCleanup.js` | 644 | 9 cleanup types, risk badges |
| `src/components/ObjectCache.js` | 902 | Redis standalone/sentinel/cluster |
| `src/components/PluginSetting.js` | 968 | Activity log, PageSpeed key, export/import |
| `src/components/PageSpeedPanel.js` | 479 | Queue + poll PageSpeed |
| `src/components/PerformanceAudit.js` | 841 | Local telemetry scan + suggestions wiring |
| `src/components/CriticalCssPanel.js` | 123 | CCSS status list |
| `src/components/LlmsPanel.js` | 152 | llms.txt opt-in |
| `src/components/AutoloadedOptions.js` | 133 | Autoload bloat list |
| `src/components/SuggestionsPanel.js` | 270 | Engine output, Fix-It navigation |
| `src/components/SystemInfo.js` | 353 | On-demand info tables |
| `src/components/WebVitalsTrends.js` | 229 | SVG sparkline trends |
| `src/components/WebVitalsRum.js` | 177 | RUM daily aggregates |
| `src/components/RecentActivityCard.js` | 70 | 5-item preview |
| `src/components/WelcomePanel.js` | 272 | 3-step onboarding |
| `src/components/common/SwitchField.js` | 62 | ToggleControl wrapper |
| `src/components/common/FeatureCard.js` | 43 | Card shell |
| `src/components/common/FeatureHeader.js` | 33 | Hero header |
| `src/components/common/StatusBadge.js` | 35 | Pill badge |
| `src/components/common/MetricCard.js` | 27 | Metric + badge |
| `src/components/common/NoticeBanner.js` | 55 | Dismissible banner |
| `src/components/common/Tooltip.js` | 51 | Hover/focus tooltip |
| `src/components/common/CheckboxOption.js` | 84 | Checkbox + nested textarea |
| `src/components/common/ConfirmDialog.js` | 182 | Modal with focus trap |
| `src/components/common/LoadingSubmitButton.js` | 52 | Spinner button |
| `src/components/common/ErrorBoundary.js` | 48 | Class boundary |
| `src/setupTests.js` | 56 | Mocks for i18n, matchMedia, ToggleControl |
| **Total** | **12459** |  |

---

## Findings

| # | Severity | File:Line | Title | Evidence | Impact | Recommendation | Confidence |
|---|----------|-----------|-------|----------|--------|----------------|------------|
| 01 | MEDIUM | `src/App.js:284-387` | CCSS refresh trigger ping-pongs via `setCcssRefreshTrigger(0)` inside same effect | `finally { if(!abort.signal.aborted) setCcssRefreshTrigger(0); }` with dep `[ccssRefreshTrigger]` and guard `if(hasFetchedCcss.current && 0===ccssRefreshTrigger) return;` at `348` | Extra render + effect invocation on every CCSS fetch; convoluted guard obscures intent; future dev may remove guard and cause infinite loop | Replace trigger pattern with explicit `ccssFetchKey` increment only on demand; or fetch CCSS via callback `refreshCcss()` instead of effect-signal. Keep `hasFetchedCcss` but do not mutate watched dep inside effect. | HIGH |
| 02 | MEDIUM | `src/App.js:434-449` | Sidebar overlay keyboard semantics incomplete | `<div role="button" tabIndex="0" onClick={toggleMobileMenu} onKeyDown={if(e.key==='Enter'\|\|e.key===' ')toggleMobileMenu()}>` | Space fires on `keydown` (page scroll) vs expected `keyup`; missing `preventDefault` on Space causes scroll; Enter/Space duplication inconsistent with `ConfirmDialog` handling | Add `e.preventDefault()` on Space inside `onKeyDown`; or use `<button>` instead of `<div role="button">` so native activation handles it. | HIGH |
| 03 | MEDIUM | `src/App.js:159-174` | Duplicate retry handlers for CCSS | `onCcssRefresh` and `onCcssRetry` are byte-identical closures both doing `hasFetchedCcss.current=false; setCcssError(false); setCcssRefreshTrigger(c=>c+1)` | Maintenance drift risk; callers may assume different semantics; two props where one suffices | Collapse to single `onCcssRefresh` prop; if distinction desired, make retry debounce vs refresh semantics explicit with comment. | HIGH |
| 04 | MEDIUM | `src/App.js:260-282` | Theme-color side-effect without cleanup or dep | `useEffect(()=>{ root.style.setProperty(...) },[])` with `wppoSettings?.themeColors` read | If SPA hot-reloads or user switches admin color scheme without full reload, property stale; leaked style persists after unmount in tests | Add cleanup: return ()=>`removeProperty`; or read via dependency `[wppoSettings?.themeColors?.primary]` (stringify). Document that mount is single-shot in WP admin. | MEDIUM |
| 05 | LOW | `src/App.js:211-257` | Mobile focus trap does not handle `Escape` to close drawer | Effect traps `Tab` but no `Escape => setMobileMenuOpen(false)` | Keyboard users must tab to toggle; WCAG 2.1 expectation for drawer | Add `if(e.key==='Escape'){ e.preventDefault(); setMobileMenuOpen(false); toggleBtnRef.current?.focus(); }` inside sidebar keydown handler. | HIGH |
| 06 | HIGH | `src/lib/apiRequest.js:119` | Global settings mutation via shallow `Object.freeze` – nested mutation still possible + prop-state desync | `wppoSettings.settings = Object.freeze(data.data);` then multiple consumers read `wppoSettings.settings.cache_settings` directly and also via props; freeze is shallow | Code appears safe but `Dashboard:504-545` defends with re-read at call-time; elsewhere `FileOptimization` does not re-read (saves `...settings` from local state only, not re-merged with global). Stale closure risk; frozen top-level could throw in strict mode if anyone writes `wppoSettings.settings.foo =` | Use deep-freeze or make `apiCall` return data and let callers update local React state via lift; or document global as *shallow* frozen store and enforce `...currentSettings` spread pattern everywhere (currently inconsistent). | HIGH |
| 07 | MEDIUM | `src/lib/apiRequest.js:164-198` | Inconsistent AbortSignal wiring; `fetchSystemInfo` etc. ignore cancellation | `fetchSystemInfo = ()=> apiCall('system_info',{},'GET')` vs `fetchRecentActivities(page,signal)` takes signal | Navigating away from Dashboard during SystemInfo / PageSpeed poll cannot cancel in-flight fetch → setState on unmounted component warnings possible | Thread `signal` through all `apiCall` wrappers; create an `AbortController` per panel or pass from parent App effect; update `getPagespeedResults`/`fetchSystemInfo` signatures to accept optional signal. | HIGH |
| 08 | LOW | `src/lib/apiRequest.js:52-54` | `pendingRefresh` guard uses `.finally` on promise then discards rejection swallowing timing | `pendingRefresh = refreshPromise.finally(()=>{pendingRefresh=null})` | If two concurrent 403s both await `refreshNonce()`, second gets the shared promise; `finally` clears ref *after* microtask, but if refresh fails, shared rejection propagates to both – correct. No bug but `finally` on rejected promise still resolves pendingRefresh to rejection then clears; however callers `await refreshNonce()` will throw – okay. Just document expiry | No change; keep. Add comment that concurrent callers share rejection. | MEDIUM |
| 09 | MEDIUM | `src/lib/util.js:20-22` | Coercion of `0` to `3000` for `delayJSIdleTimeout` makes `0` unrepresentable and `''` → `3000` via number parsing path | `if('delayJSIdleTimeout'===name && !nextValue){nextValue=3000}` | User typing `0` then blur gets silently changed to `3000`; input `type="number"` with `min=500` already prevents 0, but util layer still surprises. Empty field also becomes `3000` not `''`; Form shows `3000` immediately – intentional but should validate against `min` attribute | Keep but add explicit comment and consider validating in `handleChange` that non-empty numeric must be `>=500`. | MEDIUM |
| 10 | INFO | `src/lib/litespeed.js:19-38` | Pure helper duplicated vs PHP but not imported anywhere in SPA | `getEffectiveMode`, `shouldDisableOptimizer`, `modeLabel` defined and tested, but grep shows zero imports in `src/` | Functionally dead in SPA (Dashboard/FileOptimization re-derive `effectiveMode` inline from `wppoSettings.litespeed`) – logic duplication risk on drift | Import and use `getEffectiveMode` in `Dashboard.js:686` and `FileOptimization.js:98-103` to single-source; or delete module if intentionally example-only. | HIGH |
| 11 | HIGH | `src/components/common/CheckboxOption.js:35-37` | `id` generation conditional on `description` – unlabeled inputs lose htmlFor association | `const id = idProp ?? (description ? uid : undefined);` `label htmlFor={id}` `input id={id}` `aria-describedby={descriptionId}` | When `description` omitted (e.g., `PluginSetting:759` serverTiming toggle, `DatabaseCleanup` role checkboxes use `CheckboxOption` without id? Actually DB uses CheckboxOption with label only), `id` is `undefined` → `label[htmlFor=undefined]` renders without attribute, `input[id=undefined]` no id, screen reader loses label-input binding; WCAG 1.3.1 failure | Always generate `id`: `const id = idProp ?? uid;` and always set `descriptionId = description ? 'desc-'+id : undefined` – regardless of description presence. | HIGH |
| 12 | MEDIUM | `src/components/common/NoticeBanner.js:30-35` | Always `role="alert"` even for `success/info` should be `polite` | `role="alert"` + no `aria-live` attribute; App spec says assertive for error else polite | Success messages interrupt screen readers aggressively; should be `role="status"` or `aria-live="polite"` for non-errors | Branch: `role={type==='error'?'alert':'status'}` `aria-live={type==='error'?'assertive':'polite'}`. `SystemInfo` / `AutoloadedOptions` etc. already handle via parent. | HIGH |
| 13 | MEDIUM | `src/components/Dashboard.js:322-330` | Image poll timeout not aborted on unmount → stray `setState` after unmount | `useEffect(()=>()=>{if(pollingRef.current) clearTimeout(pollingRef.current)},[])` clears pending timer but currently-running `apiCall('image_job_status')` may resolve after unmount and call `setBgJobsQueued`, `updateState`, `notify` | React warning “Can't perform state update on unmounted component”; noise in console, possible notify after nav | Use `AbortController` for poll requests (add signal to `apiCall`); check `isMounted.current` (like PageSpeedPanel does) before state updates; clear pollingRef on cleanup as done but also gate `updateState`. | HIGH |
| 14 | MEDIUM | `src/components/Dashboard.js:504-640` | Cache-setting saves correctly re-read global, but FileOptimization save path does NOT – inconsistency | Dashboard `savePageCacheSettings` does `const currentSettings = wppoSettings.settings?.cache_settings ?? cacheSettings` while FileOptimization `handleSubmit` uses `...settings` locally without merging global | If two tabs write concurrently (user saves FileOptimization then quickly saves Dashboard), second wins clobbers first due to stale `...settings` spread not including other tab's recent success-frozen global | Adopt Dashboard's pattern in FileOptimization: read `wppoSettings.settings?.file_optimisation` at call-time and merge: `settings: {...currentGlobal, ...settings, delayJS...}`. | HIGH |
| 15 | MEDIUM | `src/components/PreloadSettings.js:43-49` | Effect dep `JSON.stringify(options)` recomputes every render → effect runs every render | `useEffect(()=>{setSettings(prev=>({...prev,...options}))},[JSON.stringify(options)])` | Since `options` is prop object from `App`, new frozen global spread creates new identity each time App renders (e.g., on tab switch), `JSON.stringify` may still produce same string but effect body still calls `setSettings`, causing extra renders; disabling exhaustive-deps via eslint comment hides problem | Replace with per-field deps like Dashboard does (`[options.enablePreloadCache, options.excludePreloadCache, ...]`) or `useEffect(()=>..., [options])` with shallow compare guard `usePrevious`. Better: derive `settings` from props on demand and keep local only for edits as FileOptimization does. | HIGH |
| 16 | LOW | `src/components/PreloadSettings.js:52-57` | `speculationRules` read from `wppoSettings.speculation_rules` without null guard on `speculationRules?.eagerness_override` later used as string | Fine but `speculationRules` defaults to `{}` -> `eagernessOverride` nullish | Minor; not a bug | — | LOW |
| 17 | MEDIUM | `src/components/FileOptimization.js:237-259` | `handleRegenerateUsedCSS` saves subset of settings before triggering regeneration, but server expects full settings validated | Saves only `delayJS*` fields via `...settings` then `delayJSDefaultStrategy: settings.delayJS...` duplicate keys redundant; also drops `cdnURL`, `removeWooCSSJS`, etc. – if those were dirty but unsaved, regeneration uses stale persisted values | User may expect “Regenerate” to use current UI values for all fields; partial save silently discards unsaved edits elsewhere in the form | Either include `...settings` fully (already does) – remove redundant explicit assignments (they override with same value, harmless) – but document that regenerate auto-saves full form. Or call `handleSubmit` then chain. | MEDIUM |
| 18 | MEDIUM | `src/components/FileOptimization.js:51-55` | Fallback via `\|\|` instead of `??` masks intentional empty string for `delayJSIdleList` etc. | `delayJSIdleList: options.delayJSIdleList \|\| ''` | Empty string intentional value forced to '' anyway same; low risk. But for `delayJSDefaultStrategy` value `'idle'` falsy? no. For `delayJSIdleTimeout` `options.delayJSIdleTimeout \|\| 3000` treats `0` as `3000` (desired) but also `''` as 3000. Not bug but inconsistent with `??` elsewhere | Use `??` for strings, keep `\|\|` for numeric with comment. | LOW |
| 19 | MEDIUM | `src/components/ImageOptimization.js:74-91` | Sync effect spreads options then immediately overrides arrays; but `clientSideMimeTypes` fallback to `prev.clientSideMimeTypes` may retain stale array when server says `[]` (empty override) | `clientSideMimeTypes: Array.isArray(options.clientSideMimeTypes)? options.clientSideMimeTypes : prev.clientSideMimeTypes` | If user deselects all MIME types (empty array) intending to disable processing, `Array.isArray([])` true → sets `[]` correctly (empty list is supported by core per description). Actually works. But if server omits key (undefined), keeps prev – correct. | Keep but add test for empty array case. | MEDIUM |
| 20 | MEDIUM | `src/components/PageSpeedPanel.js:125-142` | `isMounted` ref update not tied to `stopPolling` deps could leak poll after strategy switch | `stopPolling` is callback with empty deps (stable), `isMounted.current=false` on unmount only; if user switches `strategy` during polling, prior poll continues with old strategy until next fetch resolves | Poll uses `scanUrl`+`scanStrategy` closed over at call-time, so strategy change mid-poll does not restart correctly; handler starts new poll without cancelling previous if `handleScan` called twice quickly (guard `submittingRef` prevents but strategy toggle disabled while scanning so okay) | Acceptable as-is; document disabled toggle during scan. | LOW |
| 21 | HIGH | `src/components/PluginSetting.js:269-307` | Export creates object URL and revokes with `setTimeout(...,0)` race on Firefox | `link.click(); setTimeout(()=>URL.revokeObjectURL(link.href),0)` | Firefox may abort download if revocation happens before save dialog reads blob; spec recommends ≥100ms or revoke after `click` event completes via `requestAnimationFrame` + 1000 ms | Use `setTimeout(...,1000)` or `setTimeout(..., 4*1000)` as used in many plugins; keep link in DOM briefly. | MEDIUM |
| 22 | MEDIUM | `src/components/PluginSetting.js:69-75` | `apiKeyConfigured` state initialized from global once then never synced if global mutated by other flow | `const [apiKeyConfigured,setApiKeyConfigured]=useState(wppoSettings.performance_audit?.pagespeedApiKeyConfigured ?? false)` | After saving API key via `saveApiKey`, local `setApiKeyConfigured(true)` updates, but if user navigates away and returns via full page reload, global re-read is correct; SPA tab switch without reload leaves stale if API key cleared server-side via other method. Minor | Add effect syncing from global as Dashboard does for cacheSettings. | LOW |
| 23 | MEDIUM | `src/components/PluginSetting.js:94-109` | `highValueUrls` derived from `wppoSettings.settings.performance_audit.high_value_urls` but not resynced when settings prop changes | Same pattern: storedAudit captured at mount, state initialized once, no effect to resync if App global mutated via Dashboard telemetry save | Local `highValueUrls` textarea may show stale after external mutation | Add `useEffect(()=>{setHighValueUrls(...)} ,[storedAudit.high_value_urls])`. | MEDIUM |
| 24 | LOW | `src/components/DatabaseCleanup.js:279-282` | `parseInt(val)` missing radix | `Object.values(counts).reduce((sum,val)=>sum+(parseInt(val)\|\|0),0)` | In older engines, leading-zero strings interpreted as octal; plus linter warning | Use `parseInt(val,10)` consistently (also `246` in Dashboard). | LOW |
| 25 | MEDIUM | `src/components/PerformanceAudit.js:360-377` | Second fetch `fetchSuggestions(url, abort.signal)` uses same `AbortController` that may already be aborted if scan fetch threw? Actually controller only aborted on unmount or next scan, not after success – okay. But if suggestions fetch lags and user starts new scan, first suggestions fetch still runs with old signal then aborted, but new scan's abort aborts old request – okay. However suggestions state may be set after component unmounted without isMounted guard | `onSuggestionsReady` may call `setTelemetrySuggestions` after Dashboard unmounted if user switches tab quickly | Add `isMounted` ref similar to PageSpeedPanel, or gate with `!abort.signal.aborted` check already does first half but not mounted guarantee; aborted signal already tied to unmount via effect that aborts on unmount – okay. Keep. | LOW |
| 26 | LOW | `src/components/CriticalCssPanel.js:14-35` | `STATUS_CONFIG` labels `__(...)` executed at import time, not per-render; if locale changes (admin language switch) without reload, labels stale | Static import-level translation | SPA never changes locale without reload; low impact | Keep or make config a factory `getStatusConfig()` called per render. | LOW |
| 27 | LOW | `src/components/AutoloadedOptions.js:25-56` | `load` effect runs once, but no retry UI on error, only `<p>` error; duplicate pattern vs WebVitalsTrends which shows retry-less error. Consistent but missing affordance | Users cannot retry without remounting component (tab switch does not refetch because deps `[]` – actually `[load]` where load is stable callback so runs once) | Add retry button invoking `load()` as PageSpeedPanel/SystemInfo do. | LOW |
| 28 | MEDIUM | `src/components/WebVitalsTrends.js:33-52` | `buildPoints` uses `Math.max(...values,100)` and `Math.min(...values,0)` – forces 0–100 range even when all values 92–95, flattening chart | `range = max-min` where max=100, min=0 always → 0-100 range; variations 92.1 vs 94.8 appear as 2px difference on 108px height | Misleads performance regression visibility | Use `const max=Math.max(...values); const min=Math.min(...values); const pad= (max-min)*0.1; range=(max+pad)-(min-pad) \|\|1` to auto-scale, or clamp to 0–100 only when values outside. | MEDIUM |
| 29 | LOW | `src/components/WebVitalsRum.js:23-39` | `dayAverages` averages weighted by sample count `n` but divides `sum/n` per metric independently, not per page; correct site-wide average, but loses per-path cardinality | Minor accuracy: mixing paths with different decision? Weighting by sample count already correct for site-wide mean | Document as site-wide mean; alternatively show median per metric. | INFO |
| 30 | MEDIUM | `src/components/common/LoadingSubmitButton.js:17-49` | `children` vs `label` precedence unclear; `label \|\| children` but if both passed, label wins silently, children ignored; several callers pass children incorrectly via `label={<><FontAwesomeIcon/> Save</>}` vs children prop | In `Dashboard:776-798` `label={<><FontAwesomeIcon/> Purge All Cache</>}` – no children; in `PluginSetting:522` `children` as JSX inside button without `label` – works via fallback `label \|\| children` but `isLoading` then shows `loadingLabel \|\| children` which shows same children even while loading (should show loadingLabel) | Normalise API: always use `label` + `loadingLabel`; deprecate `children` path or warn. Current usage in PluginSetting `Load Activity Log` passes children without label, so loadingLabel works but shows children when not loading via fallback – okay but inconsistent. | LOW |
| 31 | LOW | `src/components/common/Tooltip.js:25-35` | Container `tabIndex="0"` makes tooltip trigger focusable even when `children` is already a focusable control (e.g., wrapping SwitchField) → nested focusable violation | `<span tabIndex=0><SwitchField>...</SwitchField></span>` results in extra tab stop | Only set `tabIndex` when `children` not already focusable; or render as `span` with `role="button"`? Better: render tooltip container as `span` without tabIndex when wrapping interactive child, rely on child's focus. | MEDIUM |
| 32 | LOW | `src/components/common/ConfirmDialog.js:68-103` | Body scroll lock via `doc.body.style.overflow='hidden'` unconditionally hides, but does not restore original value if it was `overflow:auto` or `scroll` explicitly | `doc.body.style.overflow=''` clears inline style, but if page had `overflow:hidden` via inline style before opening, it gets lost | Store original `body.style.overflow` before hiding: `const prev=doc.body.style.overflow; doc.body.style.overflow='hidden'; return ()=>{doc.body.style.overflow=prev}`. | LOW |
| 33 | LOW | `src/components/common/ErrorBoundary.js:34-37` | Hard reload via `window.location.reload()` loses WP admin unsaved state without confirm | Okay for boundary | Consider `onReset` prop to allow parent App to reset state without full reload. | INFO |
| 34 | MEDIUM | `src/setupTests.js:37-55` | Mock of `@wordpress/components` `ToggleControl` renders plain checkbox with `onChange(e.target.checked)` but real component passes `newValue` boolean via prop – mock does `onChange(e.target.checked)` correct but strips other props `disabled`, missing `__nextHasNoMarginBottom` passthrough okay. However mock does not reflect real WP ToggleControl a11y (`role="switch"`), causing false-positive tests for SwitchField | Tests asserting `aria-label` etc may pass differently on real UI (`ToggleControl` renders `<input role="checkbox">`? Actually Toggle renders checkbox) | Mock intentionally minimal; ensure tests use `getByLabelText` not implementation detail. Document drift. | LOW |
| 35 | OPTIMIZATION | `src/components/PerformanceAudit.js:121-129` | `numericStatus` thresholds for `load_time` (2.5/4) and `ttfb` (200/500) are hardcoded, duplicate of PHP SuggestionEngine thresholds – drift risk | If PHP changes thresholds, UI badge color diverges | Extract constants to shared `src/lib/metrics.js` or import from `litespeed.js`-style config; document sync with `Suggestion_Engine::THRESHOLDS`. | MEDIUM |
| 36 | DUPLICATE | `src/components/ImageOptimizationCard.js:19-29` vs `src/components/PerformanceAudit.js:145-156` vs `src/components/AutoloadedOptions.js:62-67` | Three `formatBytes` implementations with different rounding (`toFixed(1)` vs `toFixed(2)` on MB) | Maintenance overhead; inconsistency: Dashboard savings shows `1.5 MB` via ImageCard vs PerformanceAudit shows `1.50 MB` for same bytes | Extract single `formatBytes(bytes, precision?)` to `src/lib/util.js` and import. | HIGH |
| 37 | DUPLICATE | `src/components/Dashboard.js:246-251` vs `src/components/DatabaseCleanup.js:279-282` | `dbOverhead` total pattern repeated: `Object.values(dbCounts).reduce((s,v)=>s+(parseInt(v,10)\|\|0),0)` | Two copies, minor | Extract helper `sumCounts(counts)` to util. | LOW |
| 38 | DEAD CODE | `src/components/common/MetricCard.js:27` | Component never imported/used anywhere in SPA (grep shows zero `import MetricCard`) | Ships to `build/index.js` dead weight (~300 B gzipped) | Remove file or wire into `PerformanceAudit MetricOverview` (currently uses inline div). Keep if intended for future. | MEDIUM |
| 39 | DEAD CODE | `src/lib/litespeed.js:67` | `modeLabel` and helpers unused in SPA (no imports) – build still bundles due to tree-shaking? `@wordpress/scripts` webpack will include dead module if imported anywhere? It is not imported, so not bundled, but file sits in repo misleading | Confusing “pure” phase 1 helpers claimed mirrored but never used – reviewers may think integration incomplete | Either use in FileOptimization/Dashboard as suggested in #10 or mark file as reference-only with JSDoc `@deprecated unused in SPA`. | MEDIUM |
| 40 | HIGH | `src/components/WelcomePanel.js:70-93` | `handleStepAction` sequentially calls `update_settings` then `dismiss_welcome` without handling first-call success but second-call failure – panel hides only if BOTH succeed, else shows error but first settings mutation already persisted via frozen global | User clicks Enable: first apiCall succeeds (cache enabled globally frozen), second dismiss fails → `visible` stays true, notify error, but wppoSettings.settings already frozen to new value; second click re-tries same flow but first mutation repeats (duplicate write) | Make calls transactional: if `updateRes.success` but `dismissRes` fails, still hide panel and show dismiss error as toast but not keep panel visible; or roll back optimistic UI. | HIGH |
| 41 | MEDIUM | `src/components/SystemInfo.js:69-107` | `handleLoad` sets `loaded=true` only on success, leaving `loaded` false on error so trigger stays “Load System Info” not “Refresh” even after successful prior load then subsequent failure | Second fetch failure then immediate success should show Refresh – works because loaded already true; but initial failure retains Load label correctly. Minor inconsistency when error recovery | Keep `loaded` true after first success forever, or track `attempted` separately. Already does (`setLoaded(true)` only on success, keeps true after). Okay but upon error `notice` shows, button still says Load vs Refresh – acceptable. | LOW |
| 42 | MEDIUM | `src/App.js:389-393` | Transition `setTimeout(400)` duration mismatched with CSS `wppo-fadeIn` likely `300ms` animation | If CSS duration changes, JS timeout desync leaves transition class longer/shorter than animation | Use `onTransitionEnd` event instead of fixed timeout, or read CSS variable. | LOW |
| 43 | MEDIUM | `src/components/FileOptimization.js:309-328` | `handleSubTabKeyDown` wraps index without handling `Home`/`End` keys expected for tablist ARIA pattern | `role="tablist"` expects `ArrowRight/Left`, `Home` → first, `End` → last per WAI-ARIA | Add `Home`/`End` cases per spec. | LOW |
| 44 | INFO | `src/setupTests.js:6-15` | `sprintf` mock supports `%d`/`%s` with positional `%2$s` but not `%02d` or float formatters used elsewhere – sufficient for current tests | No impact now but may mask future format bugs | Keep; note limitation. | INFO |
| 45 | LOW | `src/lib/apiRequest.js:193-195` | `encodeURIComponent` on `url` and `strategy` inside template literal for `getPagespeedResults` vs `fetchSuggestions` etc. direct encode – consistent, safe. No double-encode issue | Query param built via `URLSearchParams` in `fetchWebVitalsTrends:211-221` correctly uses `params.set` + `params.toString()` double-encodes? Actually `fetchWebVitalsTrends` uses URLSearchParams correctly vs manual encode for PageSpeed – two patterns coexist | Standardise on `URLSearchParams` everywhere. Minor style, not bug. | INFO |

---

## Detailed Analysis

### State, Props & wppoSettings Global

- `wppoSettings` is the implicit shared store. `apiRequest:119` shallow-freezes `wppoSettings.settings` after every `update_settings` success. Consumers (`Dashboard`, `FileOptimization`, `PreloadSettings`, `PluginSetting`, etc.) correctly read initial state via `typeof wppoSettings !== 'undefined' ? wppoSettings?.settings?.X ?? {}` and sync via `useEffect` or re-read at call-time. **Inconsistency:** Dashboard’s three save handlers defensively re-read global to avoid stale closure; FileOptimization’s `handleSubmit` does not – it trusts local `settings` object captured at render. Under rapid sequential saves across tabs, stale write risk (#14).

- `App.js` holds the only routing state (`activeTab`) and top-level fetch orchestration (activities, serverRules, ccssStatus) via refs `hasFetched*`. The `AbortController` per effect correctly aborts on tab switch; the CCSS retry dance (#01) is the only convoluted state.

- `useNotice` timers are correctly cleared on re-notify, dismiss, and unmount (`useRef` + `useCallback` + `useEffect` cleanup). No leaks observed – pattern exemplary.

- `handleChange` (`util.js`) generically maps `name/type/value/checked` to `setSettings`. Numeric branch handles `inputMode="numeric"` and special `delayJSIdleTimeout` coalescing. Behavior verified against all number inputs (`excludeFirstImages`, `maxWidthImgSize`, `port`, `database`, `delayJSIdleTimeout`).

### a11y

- **Good:** `FeatureCard` semantics minimal, `ConfirmDialog` focus trap with `previouslyFocused` restore, body scroll lock, `role="dialog" aria-modal`, `PageSpeedPanel` `role="img"` on SVG with `aria-label`, progress bars have `aria-valuenow`+`aria-labelledby`, tablist uses `role="tab"`+`aria-selected`+`aria-controls`+`tabIndex` roving focus.

- **Gaps:** `CheckboxOption` label binding (#11), `NoticeBanner` always assertive (#12), overlay `role="button"` on div (#02), Tooltip extra tab stop (#31), `SystemInfo` tables missing `<caption>`/scope but `InfoTable` uses data keys as labels – okay for screen readers via `th` not present (it uses `td` only – should be `<th scope="row">`).

### Performance

- `buildPoints` flattening (#28) hides regressions; fix for observability.
- `PreloadSettings` `JSON.stringify(options)` effect causes unnecessary setState every render (#15).
- `Dashboard` poll loop correctly debounces via `pollingRef.current === currentTimeout` guard and `submittingRef`.
- `PageSpeedPanel` `MAX_POLL_ATTEMPTS=60` * 5s = 5 min – reasonable vs typical 15–60s API latency; uses `submittingRef` to guard double-submit without re-render.
- No `React.memo` overuse – only `SuggestionsPanel`, `SystemInfo`, `RecentActivityCard` memoized; correctly applied.

### Security (XSS)

- No `dangerouslySetInnerHTML`, no `innerHTML`, no `eval`. All API data rendered via `{value}` interpolation. `serverRules.nginx` and `serverRules.litespeed` shown inside `<code>` text node. `activity` log text likewise. Input validation in `validateImportData` (#22 plugin) whitelists keys and rejects arrays – prevents prototype pollution via imported JSON (though `JSON.parse` on file input is user-controlled, validation ensures no `__proto__` key because key not in allowlist). Nonce refresh uses `URLSearchParams` correctly. **No XSS findings.**

---

## No-Issues Confirmed (intentionally verified as correct)

| Area | File:Line | Why it is correct | Review note |
|------|-----------|-------------------|-------------|
| Nonce refresh thundering-herd | `apiRequest.js:16-57` | `pendingRefresh` shared promise + `.finally` clear | Verified safe despite duplicate callers |
| Abort on unmount | `PerformanceAudit.js:280-286`, `App.js:378-380` | `AbortController.abort()` + `signal.aborted` guard before setState | Pattern exemplary |
| `useNotice` timer lifecycle | `useNotice.js:30-69` | `clearTimer` on re-notify/dismiss/unmount; `timerRef` type `number\|null` | No leak |
| `preloadSitemap` description | `PreloadSettings.js:198-209` | Correctly gated behind toggle | No issue |
| `WelcomePanel` global mutation | `WelcomePanel.js:79` | `wppoSettings.settings = Object.freeze(data.data)` in `apiCall` then `dismiss_welcome` separate | Verified consistent with other panels |
| `SuggestionsPanel` memoization | `SuggestionsPanel.js:270` | `memo()` avoids re-render when suggestions unchanged; `key={suggestion.metric}` stable | OK |
| `ConfirmDialog` focus restore | `ConfirmDialog.js:105-119` | Restores `previouslyFocused` only if `isConnected` | Prevents focus on detached node |
| `App` lazy chunk names | `App.js:32-64` | `webpackChunkName` per tab enables code splitting | Verified working |
| `setupTests` ToggleControl mock | `setupTests.js:37-55` | Translates `onChange(checked)` → synthetic event bridging `SwitchField` | Acceptable for JSDOM |

---

## Duplicate / Dead-Code Inventory

| Type | Location | Description | Savings if removed |
|------|----------|-------------|---------------------|
| DUPLICATE | `ImageOptimizationCard.js:19-29` + `PerformanceAudit.js:145-156` + `AutoloadedOptions.js:62-67` | `formatBytes` triplicated with 1 vs 2 decimal drift | Single `src/lib/util.js: formatBytes` saves maintenance; ~40 lines |
| DUPLICATE | `Dashboard.js:246` + `DatabaseCleanup.js:279` | `reduce` parseInt summation pattern | Helper `sumCounts` |
| DUPLICATE | `FileOptimization.js:179-215` `withNotification` vs `PluginSetting:111-161` `saveMonitoring/saveApiKey` etc. | Notification+loading boilerplate repeated >10x | Factor into `useAsyncWithNotice()` hook |
| DEAD CODE | `src/components/common/MetricCard.js` | Zero imports | Remove or adopt in `PerformanceAudit.MetricOverview` |
| DEAD CODE | `src/lib/litespeed.js` (if unused) | Pure helpers not imported | Either wire up (#10) or delete; not bundled today but confusing |
| DUPLICATE | `App.js:159-174` `onCcssRefresh` / `onCcssRetry` identical | One handler suffices | Deduplicate |

---

## Open Questions

1. **CCSS status `onCcssRetry` vs `onCcssRefresh`** – Are these intended as separate affordances (refresh = polling trigger, retry = manual after error) with different error handling or just accidental duplication during extraction from class component? Confirm with PHP `class-rest::ccss_status` owner. Evidence `App.js:159-174` identical.

2. **`wppoSettings.themeColors` injection timing** – Is this value available synchronously at `wp_localize_script` time (as `include/class-main.php: enqueue` does) guaranteeing effect with `[]` is sufficient, or can it arrive async via `getSettings` REST? If async, `App.js:260-282` needs dependency. Confirm with PHP enqueue.

3. **`preloadSettings.preloadSitemap` cron budget** – SPA toggles `preloadSitemap` but UI gives no feedback on sitemap discovery cap (500 URLs) / 15s budget mentioned in AGENTS.md. Should UI show count or truncated warning? Out of scope for SPA audit but worth product Q.

4. **`formatBytes` rounding policy** – Should `1.0 KB` keep trailing `.0` (ImageCard `toFixed(1)`) or collapse to `1 KB` (AutoloadedOptions integer check)? Design system choice.

5. **`MetricCard` intended reuse** – Was `MetricCard` meant to replace the inline `wppo-audit-overview-card` JSX in `PerformanceAudit.js:206-263`? If so, ticket LS-xxx should wire it; otherwise delete to satisfy `no dead code`.

6. **`litespeed.js` standalone coverage** – Do SPA tests cover `getEffectiveMode` (`standalone` when `!isLiteSpeed`) as seen in PHP `LiteSpeed_Integration::effective_mode()`? If not imported, tests for that file run in isolation but not integration – risk of drift. Should `FileOptimization` import it for single source of truth?

---

## Audit Checklist Coverage

- [x] Every file read completely, line-by-line, not sampled
- [x] `useState`/`useRef`/`useEffect`/`useMemo`/`useCallback` traced for stale closure, missing deps, leak
- [x] `apiCall` + `wppoSettings` global mutation + nonce refresh + AbortSignal pathways traced
- [x] `useNotice` timer owned + auto-dismiss + dismiss button + unmount cleanup verified
- [x] a11y: roles, aria-live, focus trap, tablist keyboard, label associations, live regions
- [x] perf: polling loops, memoization, JSON.stringify deps, buildPoints flattening
- [x] security: XSS via interpolation vs dangerouslySetInnerHTML, JSON import validation, encodeURIComponent, freeze
- [x] duplicates: `formatBytes`×3, `sumCounts`×2, `withNotification` boilerplate, `onCcss*` duplicate
- [x] dead code: `MetricCard`, `litespeed.js` unused export
- [x] `setupTests.js` mocks verified

**No production code modified.**

