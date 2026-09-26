# Agent A06 — Vanilla JS + Web Vitals Audit (js-vanilla-a11y)

**Scope:** `src/lazyload.js` (vanilla IntersectionObserver + MutationObserver), `src/main.js` (admin-bar cache buttons), `src/rum.js` (Web Vitals beacon), `src/setupTests.js`, `src/__tests__/lazyload.test.js` (705), `src/__tests__/main.test.js` (148)
**Date:** 2026-08-27
**Auditor:** agent A06 `js-vanilla-a11y`
**Workspace:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation`
**Mode:** read-only, exhaustive line-by-line, no production code modified

---

## Files Reviewed (line counts via `wc -l`)

| File | Lines | Notes |
|------|-------|-------|
| `src/lazyload.js` | 1025 | Vanilla lazy: IO + MutationObserver + safety interval; deferred scripts (idle/viewport/interaction); video placeholders; lazy backgrounds; auto-sizes probe |
| `src/main.js` | 239 | Admin-bar Clear All / Clear This Page; postJsonRequest + refreshNonce thundering-herd; showNotice fallback |
| `src/rum.js` | 195 | RUM beacon IIFE: TTFB/FCP + PerformanceObserver LCP/CLS/INP; sendBeacon→fetch fallback; visibilitychange/pagehide |
| `src/setupTests.js` | 56 | Jest globals: wppoSettings, matchMedia, @wordpress/i18n (__/_n/sprintf), @wordpress/components ToggleControl mock |
| `src/__tests__/lazyload.test.js` | 705 | 21 tests: loadScript, hasInteractionScripts, observeElement, loadImages, restoreSizes, initVideoPlaceholders, config resolution |
| `src/__tests__/main.test.js` | 148 | 5 tests: admin-bar POST payloads, notice DOM, preventDefault |
| **Total** | **2368** | All 6 files read completely; lint `npm run lint:js` 0 errors 3 warnings; grep `wppoObject`/`wppo-lazyload`/`wppoRum` in `includes/class-main.php:1581-1604` |

---

## Findings

| # | Severity | File:Line | Title | Evidence | Impact | Recommendation | Confidence |
|---|----------|-----------|-------|----------|--------|----------------|------------|
| 01 | MEDIUM | `lazyload.js:36-38` | useNativeLazy precedence mixes legacy false with module data | `(typeof window.wppoNativeLazy !== 'undefined' && window.wppoNativeLazy) \|\| !!moduleData.nativeLazy` – legacy false still falls through to module truthy | No way for legacy false to override module true; invariant legacy-wins violated (both set from same PHP, low drift) | `typeof window.wppoNativeLazy !== 'undefined' ? !!window.wppoNativeLazy : !!moduleData.nativeLazy` | HIGH |
| 02 | LOW | `lazyload.js:63-76` | AUTO_SIZES_SUPPORTED probe forces reflow at module load | `probe.setAttribute('sizes','auto'); document.documentElement.appendChild(probe); getComputedStyle(probe).contain` | 1 reflow even when no images; wasteful in jsdom | Lazy-evaluate on first restoreSizes('auto') instead of IIFE | MEDIUM |
| 03 | DEAD CODE | `lazyload.js:94-95` | LAZY_SELECTOR never read | `const LAZY_SELECTOR = getLazySelector();` with eslint-disable no-unused-vars; zero refs | Bundle dead bytes; misleads selector is static | Remove; keep getLazySelector() | HIGH |
| 04 | MEDIUM | `lazyload.js:107-181` | Inline script path never rejects on runtime error | `replacement.text=script.text; replaceChild; resolve()` – throw bubbles to window, not reject | Broken inline wppo/javascript silently resolves; downstream DOMContentLoaded still fires | Wrap replaceChild in try/catch → reject; consider console.error on window error | MEDIUM |
| 05 | INFO | `lazyload.js:126-143` | External replacement preserves CSP nonce | `Array.from(script.attributes).forEach(attr=>replacement.setAttribute(...)); removeAttribute('wppo-src')` retains nonce | Correct CSP passthrough | No change (verified) | HIGH |
| 06 | MEDIUM | `lazyload.js:286-297` | loadIdleScripts re-queries vs loadScripts full set | idleScripts queried at top level and again inside function; relies on DOM mutation to avoid double exec | Race benign but implicit; extra query | Use WeakSet or data-wppo-loaded marker; pass snapshot array | MEDIUM |
| 07 | MEDIUM | `lazyload.js:306-339` | observeViewportScripts observer never disconnects | `new IntersectionObserver(...); forEach(observe)` – only unobserve per entry, never disconnect | Leak 1 observer for lifetime; SPAs accumulate | Disconnect when `querySelectorAll('script[data-wppo-delay-strategy="viewport"]').length===0` | MEDIUM |
| 08 | LOW | `lazyload.js:393-412` | 6 triggerEvents over-trigger | `['mouseenter','mousedown','mouseover','touchstart','scroll','keydown']` with {once:true} + manual remove | First interaction fires 2-3 handlers synchronously; deduped via scriptLoadPromise but wasteful | Reduce to `['pointerdown','keydown','scroll']`; keep once:true only | LOW |
| 09 | LOW | `lazyload.js:412-415` | Dead else-if branch | `else if (document.querySelector('script[data-wppo-delay-strategy]')) { // nothing }` | Retains query + branch for comment | Remove | HIGH |
| 10 | DUPLICATE | `lazyload.js:542-556` vs `583-591` | Native iframe restore duplicated | Both do `loading=lazy; src=data-src; removeAttribute('data-src')` for IFRAME when USE_NATIVE_LAZY | Drift if one adds referrerPolicy | Extract restoreIframeNative(el) helper | HIGH |
| 11 | MEDIUM | `lazyload.js:609-642` vs `760-818` | PICTURE source restore missing in scroll fallback | IO path restores `source[data-srcset]` inside PICTURE; fallback only handles VIDEO sources, not IMG picture | IE11/no-IO browsers show picture without WebP srcset → no art-direction | Mirror PICTURE block in fallback before placeholder handling | HIGH |
| 12 | MEDIUM | `lazyload.js:835-853` | isElementInViewport hoisted after use; resize not observed | fallback closes over isElementInViewport defined later; only scroll listener, not resize/orientationchange | Rotate/resize without scroll leaves images placeholder; TDZ fragile | Hoist function; add resize + orientationchange listeners passive | MEDIUM |
| 13 | MEDIUM | `lazyload.js:684-703` | Safety scan on window.wppoSafetyScanId global, runs forever | `window.wppoSafetyScanId=setInterval(...,10000)` guarded once; only cleared when remaining===0 | Infinite scroll never drains → perpetual 10s query; pollutes global; not cleared on pagehide | Scope to module let; clear on pagehide/visibility hidden | MEDIUM |
| 14 | LOW | `lazyload.js:711-747` | MutationObserver disconnected too eagerly | observe(document.body,subtree) – checkCleanup disconnects when remaining===0; new HTMX images after disconnect missed | Dynamic content after initial drain not observed | Keep observer alive until pagehide or re-create in observeElement | MEDIUM |
| 15 | HIGH | `lazyload.js:893-965` | Video placeholder mouse-only, no a11y | `el.addEventListener('click',loadVideo)` on div without tabIndex/role/aria-label/keydown | WCAG 2.1.1 + 4.1.2 failure; keyboard users cannot activate YouTube placeholder | Add `tabIndex=0 role=button aria-label` + keydown Enter/Space + focus ring | HIGH |
| 16 | MEDIUM | `lazyload.js:875-891` | Global error handler uses tagName check without instanceof | `e.target.tagName==='IMG' && hasAttribute('data-wppo-fallback')` – window has no tagName | Throws if target is window; fallback loop safe but fragile | Use `e.target instanceof HTMLImageElement` | LOW |
| 17 | LOW | `lazyload.js:913-918` | iframe.allowFullscreen property only | `iframe.allowFullscreen=true; iframe.loading='lazy'` – property vs attribute | Works in HTML but XHTML strict needs attribute | Also `setAttribute('allowfullscreen','')` | LOW |
| 18 | LOW | `lazyload.js:924-940` | data-wppo-iframe-attrs allows on* injection | `if(!['src','width','height','style'].includes(k)) setAttribute(k,v)` permits onload/onerror if server allows | Server filters sandbox/referrerpolicy today; client should denylist /^on/i | Add `/^on/i.test(k)` reject + `/^[a-z-]+$/` | MEDIUM |
| 19 | HIGH | `lazyload.js:949-961` | Video 30s fallback setTimeout retains closure | `setTimeout(()=>{ if(contains && opacity!=='1') onLoad()},30000)` holds el/iframe/picture; not cleared on load or pagehide | Retains DOM subtree 30s per placeholder; galleries leak | Store t; clear on iframe load {once:true}; clear on pagehide | MEDIUM |
| 20 | MEDIUM | `lazyload.js:977-1013` | loadBackgrounds never observes dynamic .wppo-lazy-bg | Called once at DOMContentLoaded; MutationObserver ignores .wppo-lazy-bg | AJAX-injected lazy backgrounds stay lazy | Extend MutationObserver to observe .wppo-lazy-bg or expose wppoLoadBackgrounds | MEDIUM |
| 21 | LOW | `lazyload.js:997-1006` | backgroundObserver queries document each batch | `if(!querySelector('.wppo-lazy-bg')) disconnect()` per intersection | Extra scan | Track pending count decrement instead | INFO |
| 22 | LOW | `lazyload.js:1015-1025` | loadImages called twice (DOMContentLoaded + loadScripts setTimeout) | Guard `if(!globalObserver)` makes second call no-op; intended rescan after scripts never happens | Confusing comment; scripts-injected images never scanned | After loadScripts call dedicated scanNewLazyElements() iterating observedElements | MEDIUM |
| 23 | HIGH | `main.js:6-7` | fallbackTimer singleton for multiple notices | Shared `let fallbackTimer` overwritten by second notice; first timeout ID lost, never auto-dismissed | Stale .wppo-admin-notice accumulation on rapid clicks; leak | Per-notice timer: `noticeEl._wppoTimer=setTimeout(...)` + WeakMap; remove shared var | MEDIUM |
| 24 | HIGH | `main.js:110-156` | Dismiss button missing type and role divergence | `createElement('button')` without type => submit in form; `role=alert` always assertive | Form submission hazard + success asserts | Add `type='button'`; role `error?'alert':'status'` + `aria-live` `assertive`/`polite` | MEDIUM |
| 25 | MEDIUM | `main.js:133-146` | Dismiss clearTimeout races shared timer | `clearTimeout(fallbackTimer)` may clear other notice's timer | Cross-notice cancellation (same root as 23) | Per-notice timer (23) | MEDIUM |
| 26 | MEDIUM | `main.js:158-163` | pagehide clears timer not DOM | `clearTimeout(fallbackTimer)` leaves .wppo-admin-notice in bfcache | Stale notices on pageshow | Also `querySelectorAll('.wppo-admin-notice').forEach(el=>el.remove())` | LOW |
| 27 | MEDIUM | `main.js:200-215` | Path traversal double-encode bypass | `decodedPath.includes('..')` misses `%252e%252e` → `%2e%2e`; ignores `\\` and `//` | Server is source of truth but client bypass possible | Recursive decode 3× + check `%2e`, `\\`, `//` | MEDIUM |
| 28 | INFO | `main.js:1-3` | Keep-in-sync comment drift vs apiRequest.js | postJsonRequest/pendingRefresh mirrored but diverges on AbortSignal, error messages | Future 401 fix may miss admin bar | Extract src/lib/nonce.js or checklist both | INFO |
| 29 | MEDIUM | `rum.js:56-96` | sent flag never resets on bfcache pageshow | `let sent=false` IIFE; set true after first send; bfcache back nav never resets | Under-reports RUM for bfcache | Listen `pageshow` persisted → reset sent + scheduleSend | MEDIUM |
| 30 | MEDIUM | `rum.js:82-88` | sendBeacon return ignored | `if(navigator.sendBeacon) { sendBeacon(); return; }` ignores false | Drop on beacon quota full | `if(navigator.sendBeacon && sendBeacon(url,blob)) return;` fallback to fetch | MEDIUM |
| 31 | MEDIUM | `rum.js:90-95` | fetch fallback missing keepalive + catch | `fetch(url,{method:'POST', headers, body, credentials:'omit'})` no keepalive, no catch | Old Safari without sendBeacon loses payload on unload; unhandled rejection | Add `keepalive:true` + `.catch(()=>{})` | MEDIUM |
| 32 | MEDIUM | `rum.js:128-137` | CLS sum never resets session window | `cls+=entry.value` lifetime sum; spec uses 5s window max | Over-reports vs CrUX | Document lifetime vs spec or implement gap reset | LOW |
| 33 | LOW | `rum.js:145-158` | INP uses last entry not max | `values.inp=entries[entries.length-1].duration` overwrites per batch | Under-reports worst interaction | Track maxInp across batches | MEDIUM |
| 34 | INFO | `rum.js:178-194` | visibilitychange+pagehide double send guarded by sent flag | Both call send(); second no-op via sent | Not bug; note once:true not needed for visibilitychange | No change | INFO |
| 35 | MEDIUM | `setupTests.js:37-55` | ToggleControl mock stubs only ToggleControl | `Dashboard` doesn't use Modal/Spinner today; future imports silently undefined | Masked regression | Merge with actual: `...requireActual('@wordpress/components')` | LOW |
| 36 | DUPLICATE | `__tests__/lazyload.test.js:33-97` | loadScriptImpl copy-pastes production loadScript | Test maintains parallel impl with auto `replacement.onload()` divergence | False green if production fixed | Export real loadScript for test (refactor init guard) | HIGH |
| 37 | HIGH | `__tests__/lazyload.test.js:388-440` | Fallback test never exercises real code | Defines local lazyLoadFallback, asserts `typeof wppoLazyLoadFallback==='undefined'` trivially | Zero integration coverage for no-IO branch (IE11) despite title | ResetModules, delete IntersectionObserver, require lazyload, dispatch scroll | HIGH |
| 38 | MEDIUM | `__tests__/lazyload.test.js:99-162` | Prototype pollution not restored | Sets `HTMLImageElement.prototype.loading` without saving descriptor | Order-dependent flakes | Save descriptor + restore in afterEach | MEDIUM |
| 39 | MEDIUM | `__tests__/main.test.js:25-42` | require in beforeAll + manual DOMContentLoaded dispatch | Single DOMContentLoaded listener closure over live wppoObject; no reset; timer-based notice assertion fragile | Cannot test 403 retry; flakes under slow CI | Use isolateModules or reset helper; use waitFor | MEDIUM |
| 40 | LOW | `__tests__/main.test.js:69-88` | Path validation branch uncovered | Only tests path '/' from JSDOM; no cases for '..', 2049 chars, empty, no-slash | Security guard zero coverage | Add param cases for traversal + long path | MEDIUM |

---

## No-Issues Confirmed (intentionally verified as correct)

| Area | File:Line | Why correct | Note |
|------|-----------|-------------|------|
| Module data JSON parse | `lazyload.js:20-25` | try/catch + warn + return {} | Prevents crash on corrupt tag |
| NATIVE_LAZY_SUPPORTED check | `lazyload.js:44` | 'loading' in HTMLImageElement.prototype | Spec compliant |
| loadScriptsByPriority grouping | `lazyload.js:202-224` | Promise.allSettled per priority high→normal→low | High not blocked by low |
| scriptLoadPromise singleton | `lazyload.js:235-238` | Dedupes concurrent loadScripts | Exemplary |
| requestIdleCallback fallback | `lazyload.js:373-386` | Math.min(2000,idleTimeout) handles deadline vs delay | Commented |
| MutationObserver nodeType guard | `lazyload.js:713` | nodeType===1 excludes text/comment | Prevents QSA on non-element |
| Placeholder handler ordering | `lazyload.js:623-636` | addEventListener('load') before src= | Prevents cached-image miss |
| Dominant-color transition | `lazyload.js:466` | transition before transparent | Smooth |
| Nonce thundering-herd | `main.js:60-101` | pendingRefresh shared + finally clear | Verified safe |
| postJsonRequest retry-on-403 | `main.js:29-39` | isRetry once | Prevents loop |
| RUM sent + pagehide once | `rum.js:56-58,183-194` | sent flag prevents double | Correct |
| RUM buffered:true | `rum.js:122-125,140,152-156` | Captures entries before script | Required for LCP |
| RUM 5s settlement | `rum.js:162-176` | setTimeout after load for INP window | Balanced |
| setupTests sprintf | `setupTests.js:6-15` | Positional %2$s | Matches WP i18n |

---

## Duplicate / Dead-Code Inventory

| Type | Location | Description | Action |
|------|----------|-------------|--------|
| DEAD CODE | `lazyload.js:94-95` | LAZY_SELECTOR never used (eslint-disable) | Remove |
| DUPLICATE | `lazyload.js:542-556` ↔ `583-591` | Native iframe restore duplicated | Extract restoreIframeNative |
| DUPLICATE | `lazyload.js:286-297` ↔ `202-224` | Idle query vs generic priority | Unify snapshot |
| DUPLICATE | `lazyload.js:684-703` vs `711-747` | Safety scan vs MutationObserver both scan getLazySelector | Merge evaluate |
| DUPLICATE | `main.js:1-101` vs `lib/apiRequest.js` | pendingRefresh/postJsonRequest 85% identical | Extract lib/nonce.js or accept |
| DUPLICATE | `__tests__/lazyload.test.js:33-97` | loadScriptImpl copy-paste production | Export real impl |
| DUPLICATE | `__tests__/lazyload.test.js:444-454` | restoreSizesImpl duplicates 493-504 | Same |
| DUPLICATE | `__tests__/lazyload.test.js:295-324` | observeElement native branch reimpl | Integration gap |
| DUPLICATE | `rum.js:29-54` | disconnectObservers 3× if/try | Helper disconnectOne |
| DEAD CODE | `lazyload.js:412-415` | empty else-if does nothing | Remove |
| DEAD CODE | `build/` excluded via .distignore check | build/*.asset.php committed (correct) | No action |

---

## Open Questions

1. **Module side-effects vs testability:** lazyload.js top-level probe + delayedScripts query prevents `import {loadScript}` without side-effects. Tests copy-paste impl (evidence lazyload.test.js:33). Should expose initLazyload() and lazy probe so Jest can import real code? Confirm boundary with `class-main.php:1585` module vs classic enqueue.
2. **Native lazy granularity:** When useNativeLazy true but NATIVE_LAZY_SUPPORTED false (Safari 16), USE_NATIVE_LAZY false → IO only. Should Safari still get loading=lazy (ignored) + IO fallback, or is IO-only intentional to avoid sizes=auto interaction? Verify `class-image-optimisation.php:1881` wppo-lazyload stripping.
3. **Video placeholder a11y API:** Placeholder title hardcoded 'YouTube video player' (919) not localized; should PHP render data-wppo-a11y-label via `data-wppo-placeholder-label` so initVideoPlaceholders sets aria-label without JS i18n? Confirm i18n pipeline with `class-main.php`.
4. **RUM token + caching:** rum.js:77 sends token via inline `window.wppoRum` printed by `class-rum.php:168` before build/rum.js. Is inline config correctly excluded from `advanced-cache.php` drop-in buffer on multisite? Check `class-cache.php` exclusion for wppo-rum-config tag.
5. **Admin bar selector stability:** main.js:165 uses `#wp-admin-bar-wppo_clear_all .ab-item` (underscore). WP core may use hyphen `wppo-clear-all` after sanitization. Should use `[id*="wppo_clear"]` or localized `wppoObject.selector` from PHP `admin_bar_menu`?
6. **Fallback timer policy:** main.js:129 shows single notice via shared timer overwrite. Is intended UX single latest notice or queued? WP core/notices queues snackbars; fallback div overwrites. Decide and implement per-notice timer vs shared.
7. **Build entries:** package.json builds 4 entries (index + lazyload + main + rum). rum.js 1820 B minified enqueued only when rum_enabled. Should rum remain conditional or always shipped? Confirm `WPPO_PLUGIN_URL:'build/rum.js'` conditional in `class-rum.php`.

---

## Audit Checklist Coverage

- [x] Every file read line-by-line 2368 lines (no sampling)
- [x] Listeners traced: lazyload 6 triggers + DOMContentLoaded + scroll vs main 2 clicks + DOMContentLoaded + pagehide vs rum load + visibilitychange + pagehide + 3 PerformanceObservers
- [x] Observers traced: IntersectionObserver (images/videos/viewport/backgrounds), MutationObserver (lazy+placeholders), 3× PerformanceObserver
- [x] DOM ops: createElement/script replaceChild, style.backgroundImage, dataset, classList, attr shredding (data-src/data-sizes/wppo-type/wppo-src)
- [x] Nonce refresh pendingRefresh dedup + finally + 403 retry traced (mirrored apiRequest.js)
- [x] Fetch: postJsonRequest JSON + X-WP-Nonce, rum sendBeacon→fetch keepalive, FormData nonce refresh
- [x] Performance: probe reflow, safety 10s, idle requestIdleCallback→setTimeout Math.min(2000,idleTimeout), priority allSettled, rootMargin 200px
- [x] a11y: video placeholder keyboard missing, notice role/alert, dismiss type, focus handling
- [x] Security: path traversal double-encode, iframe attrs on* denylist, CSP nonce passthrough, backgroundImage origin, token exposure
- [x] Error handling: JSON parse try, probe try, onerror forwarding, inline throw gap, sendBeacon return ignored, fetch catch missing
- [x] Leaks: viewport observer no disconnect, safety forever, background observer, 30s video timeout, fallbackTimer shared
- [x] Duplicates: LAZY_SELECTOR vs getLazySelector, iframe native, idle vs priority, test copy-pastes
- [x] Dead code: LAZY_SELECTOR unused, empty else-if, CLS/INP spec drift not dead but flagged
- [x] No production code modified

**No production code modified.**
