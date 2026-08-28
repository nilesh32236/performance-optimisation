# Agent A07 — JS Vanilla Loaders + RUM + Edge Workers Audit

**Base:** master@31fffc61
**Scope:** `src/lazyload.js` (~350→1035 lines actual), `src/main.js`, `src/rum.js`, `templates/cloudflare-worker.js` (101), `templates/bunny-edge.js` (67), `templates/app.html`, `templates/perf-translations.php` (42), `src/__tests__/lazyload.test.js` (705), `src/__tests__/main.test.js` (148)
**Date:** 2026-08-28
**Auditor:** Agent A07 `JS Vanilla Loaders + RUM + Edge Workers`
**Workspace:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation`
**Mode:** Audit-only, read-only, no production code modified

---

## Files Reviewed

| File | Lines | Purpose |
|------|-------|---------|
| `src/lazyload.js` | 1035 | Vanilla lazy loading: auto-sizes probe, native-lazy branching, IntersectionObserver + MutationObserver + safety scan, deferred script loading (idle/viewport/interaction + priority), video placeholders, lazy backgrounds |
| `src/main.js` | 239 | Admin-bar "Clear All Cache" / "Clear This Page" with fetch POST + 403 nonce refresh (pendingRefresh dedup) + notices |
| `src/rum.js` | 195 | Real-user Web Vitals IIFE beacon: TTFB/FCP via getEntriesByType, LCP/CLS/INP via PerformanceObserver, sendBeacon→fetch, visibilitychange/pagehide |
| `templates/cloudflare-worker.js` | 101 | Cloudflare Worker edge HTML cache adapter: SWR (HIT/MISS/REVALIDATE), placeholder replacement |
| `templates/bunny-edge.js` | 67 | Bunny CDN edge adapter (illustrative) |
| `templates/app.html` | 5 | SPA mount `<div id="performance-optimisation">` |
| `templates/perf-translations.php` | 42 | Template illustration for Performant Translations compiled PHP file |
| `src/__tests__/lazyload.test.js` | 705 | 21 tests for lazyload.js |
| `src/__tests__/main.test.js` | 148 | 5 tests for main.js |
| **Total** | **2537** | All 9 files read line-by-line via Read with offset; Grep traces for observers/nonce/beacons |

---

## Lines Reviewed

- `wc -l` verified: lazyload.js 1035, main.js 239, rum.js 195, cloudflare-worker.js 101, bunny-edge.js 67, app.html 5, perf-translations.php 42, lazyload.test.js 705, main.test.js 148 = **2537 lines**.
- Observers traced: 4× IntersectionObserver (lazy images/videos, viewport scripts, backgrounds), 1× MutationObserver (lazy + video placeholders), 3× PerformanceObserver (LCP/CLS/INP), plus scroll fallback, requestIdleCallback, setInterval safety scan.
- Nonce/beacon traces: `pendingRefresh`, `refreshNonce`, `postJsonRequest`, `wppoObject`, `wppoRum`, `sendBeacon`, `PerformanceObserver`.
- Edge SWR: Cloudflare `caches.default` + `ctx.waitUntil` + `Cache-Control public,max-age={{CACHE_TTL}},stale-while-revalidate={{SWR}}` + bypass checks.

---

## Findings

| # | File:Line | Category | Severity | Title | Evidence | Impact | Recommendation |
|---|-----------|----------|----------|-------|----------|--------|----------------|
| 01 | `lazyload.js:36-38` | Correctness | MEDIUM | useNativeLazy precedence mixes legacy false vs module data | `(typeof window.wppoNativeLazy!=='undefined' && window.wppoNativeLazy) \|\| !!moduleData.nativeLazy` – legacy set to `false` cannot veto module `true` | Native flag not deterministically controllable; minor drift between module/classic paths | `typeof window.wppoNativeLazy!=='undefined' ? !!window.wppoNativeLazy : !!moduleData.nativeLazy` matching rum-style fallback |
| 02 | `lazyload.js:63-76` | Performance | LOW | AUTO_SIZES_SUPPORTED probe forces reflow at module load | IIFE creates hidden img, appends to documentElement, getComputedStyle(contain) to test `sizes=auto` | 1 forced reflow even when no lazy images; wasteful in jsdom/tests | Lazy-evaluate on first `restoreSizes('auto')` call, cache result |
| 03 | `lazyload.js:102-103` | Dead Code | LOW | LAZY_SELECTOR alias never read | `const LAZY_SELECTOR=getLazySelector(); // Deprecated alias` + eslint-disable no-unused-vars, zero references | Dead bytes, misleads that selector is static | Remove alias, keep `getLazySelector()` |
| 04 | `lazyload.js:84-92` | Correctness | MEDIUM | getLazySelector reads wppoSettings.general.native_lazy live but USE_NATIVE_LAZY captures module load | `getLazySelector()` checks `wppoSettings.settings.general.native_lazy && NATIVE_LAZY_SUPPORTED` vs `USE_NATIVE_LAZY` constant; settings may change without reload | Selector may oscillate between calls; PH PSI race | Unify: always compute from live settings or document required reload |
| 05 | `lazyload.js:115-188` | Correctness | MEDIUM | Inline script execution errors silently resolve | `replacement.text=script.text; replaceChild; resolve()` – throws bubble to window, not reject; `loadScript` promise resolves | Broken inline `wppo/javascript` silently passes; DOMContentLoaded dispatched anyway | Wrap replaceChild in try/catch → reject; log via console.error |
| 06 | `lazyload.js:134-142` | Security | INFO | CSP nonce preserved correctly | `Array.from(script.attributes).forEach(attr=>replacement.setAttribute(attr.name,attr.value))` copies nonce | Correct CSP passthrough | No change (verified) |
| 07 | `lazyload.js:210-232` | Performance | LOW | loadScriptsByPriority uses Promise.allSettled per level | `for(level of [high,normal,low]) await Promise.allSettled(groups[level].map(loadScript))` sequential levels, parallel within level | Intended high→low; correct | No change |
| 08 | `lazyload.js:248-284` | Correctness | LOW | loadScripts dispatches DOMContentLoaded only if still loading | `if(document.readyState==='loading') dispatchEvent(new Event('DOMContentLoaded'))` – if already interactive, no dispatch | Scripts expecting DCL may miss when cache preload triggers late | Also dispatch if readyState==='interactive' and event not yet fired, or use custom wppo:scriptsLoaded |
| 09 | `lazyload.js:281-284` | Performance | LOW | loadImages called 200ms after deferred scripts | `setTimeout(()=>loadImages(),200)` inside loadScripts | Delayed image scan avoids layout thrash | Acceptable; document reason |
| 10 | `lazyload.js:310-347` | Resource Leak | MEDIUM | Viewport IntersectionObserver never disconnects | `new IntersectionObserver(... unobserve per entry ...)` only unobserves intersecting entries, never `disconnect()` nor handles removed nodes | 1 observer leaked for lifetime; SPAs accumulate | Track pending count; disconnect when `querySelectorAll(viewportScripts).length===0` or on pagehide |
| 11 | `lazyload.js:322-324` | Correctness | LOW | Viewport fallback immediate load not awaited | `if(!('IntersectionObserver' in window)) loadScriptsByPriority(viewportScripts); return;` – fire-and-forget | No await; error swallowed | Add `.catch(console.error)` or `void loadScriptsByPriority(...)` with phpcs ignore |
| 12 | `lazyload.js:377-395` | Performance | LOW | requestIdleCallback fallback uses Math.min deadline | `Math.min(2000, delayConfig.idleTimeout)` with comment deadline vs min-delay | Correct shorter fallback | No change |
| 13 | `lazyload.js:402-423` | Performance | LOW | TriggerEvents over-register | `['mouseenter','mousedown','mouseover','touchstart','scroll','keydown']` with {once:true}+manual removeEventListener | First interaction fires 2-3 handlers; deduped by scriptLoadPromise but wasteful | Reduce to `['pointerdown','keydown','scroll','touchstart']` |
| 14 | `lazyload.js:420-423` | Dead Code | LOW | Dead else-if for non-interaction strategies | `else if(document.querySelector('script[data-wppo-delay-strategy]')) { // nothing }` | Retains query+branch for comment | Remove branch, keep comment |
| 15 | `lazyload.js:541-599` | Duplication | LOW | Native iframe restore duplicated | `observeElement` 551-564 and `loadImages` 591-599 both do `loading=lazy; src=data-src; removeAttribute('data-src')` | Drift if one adds referrerpolicy | Extract `restoreIframeNative(el)` helper |
| 16 | `lazyload.js:607-770` | Resource Leak | MEDIUM | Safety scan global + runs forever on infinite scroll | `window.wppoSafetyScanId=setInterval(...,10000)` guarded once, only cleared when `remaining.length===0`; `checkCleanup` also clears | Never drains on infinite scroll → perpetual 10s QSA; pollutes window; not cleared on pagehide | Scope to module `let safetyScanId=null`; clear on pagehide/visibility hidden |
| 17 | `lazyload.js:692-711` | Correctness | MEDIUM | MutationObserver disconnected too eagerly | `checkCleanup` disconnects both observers when `remaining===0`; new HTMX/Ajax images after drain missed | Dynamic content after initial drain not observed | Keep MutationObserver until pagehide or re-create in observeElement if null |
| 18 | `lazyload.js:609-683` | Duplication | LOW | PICTURE source restore missing in scroll fallback | IO path 617-628 restores `source[data-srcset]` + sizes inside PICTURE; fallback 782-828 only handles VIDEO sources | No-IO browsers (IE11) show picture without WebP srcset → no art-direction | Mirror PICTURE block in fallback before placeholder handling |
| 19 | `lazyload.js:770-865` | Correctness | MEDIUM | Fallback isElementInViewport defined after use; resize not observed | `const isElementInViewport` hoisted after `lazyLoadFallback` closure; only `scroll` listener, not `resize/orientationchange` | Rotate/resize without scroll leaves images placeholder; TDZ fragile | Hoist function; add passive `resize` + `orientationchange` listeners |
| 20 | `lazyload.js:864` | Correctness | LOW | Fallback idempotent via wppoLazyLoadFallback guard | `if(window.wppoLazyLoadFallback) removeEventListener('scroll',prev)` then `window.wppoLazyLoadFallback=lazyLoadFallback` | Prevents duplicate listeners on HMR | OK but should use module let not window |
| 21 | `lazyload.js:884-901` | Accessibility | HIGH | Video placeholder mouse-only, no keyboard/a11y | `el.addEventListener('click',loadVideo)` on div without tabIndex/role/aria-label/keydown; title hardcoded 'YouTube video player' | WCAG 2.1.1 + 4.1.2 failure; keyboard users cannot activate | Add `tabIndex=0 role=button aria-label=data-wppo-a11y-label` + keydown Enter/Space + focus ring; localize title via PHP data attr |
| 22 | `lazyload.js:885-899` | Correctness | LOW | Global error handler fragile tagName check | `e.target.tagName==='IMG' && hasAttribute('data-wppo-fallback')` – target may be window | Throws if target is window | Use `e.target instanceof HTMLImageElement` |
| 23 | `lazyload.js:928` | Correctness | LOW | iframe.allowFullscreen property only | `iframe.allowFullscreen=true` without attribute | Works in HTML but XHTML strict needs attribute | Also `setAttribute('allowfullscreen','')` |
| 24 | `lazyload.js:933-950` | Security | LOW | data-wppo-iframe-attrs allows on* injection if server permits | `if(!['src','width','height','style'].includes(k)) setAttribute(k,v)` permits onload/onerror | Server filters today; client should denylist `^on` | Add `if(/^on/i.test(k)) continue` + `/^[a-z-]+$/` allowlist |
| 25 | `lazyload.js:967-971` | Resource Leak | MEDIUM | Video 30s fallback timeout retains closure | `setTimeout(()=>{if(contains && opacity!=='1') onLoad()},30000)` holds el/iframe/picture; not cleared on load or pagehide | Retains DOM subtree 30s per placeholder; galleries leak | Store timer; clear on iframe load {once:true} + pagehide |
| 26 | `lazyload.js:987-1023` | Correctness | MEDIUM | loadBackgrounds never observes dynamic .wppo-lazy-bg | Called once at DOMContentLoaded; MutationObserver ignores `.wppo-lazy-bg` selector; backgroundObserver only watches initial set | Ajax-injected lazy backgrounds stay lazy | Extend MutationObserver to detect `.wppo-lazy-bg` or expose `window.wppoLoadBackgrounds` |
| 27 | `lazyload.js:1015-1018` | Correctness | INFO | backgroundObserver counts via query each batch | `if(!document.querySelector('.wppo-lazy-bg')) disconnect()` per intersection | Extra scan but correct | Track pending count decrement instead |
| 28 | `lazyload.js:1025-1035` | Correctness | INFO | Double loadImages on DCL + immediate | Guard `if(!globalObserver)` makes second call no-op; intended rescan after deferred scripts via setTimeout 200ms | Confusing; scripts-injected images eventually scanned | Consider explicit `scanNewLazyElements()` after loadScripts |
| 29 | `main.js:6-7` | Resource Leak | HIGH | fallbackTimer singleton for multiple notices | Shared `let fallbackTimer` overwritten by second notice; first timeout ID lost, never auto-dismissed | Stale .wppo-admin-notice accumulation on rapid clicks | Per-notice timer: `noticeEl._wppoTimer=setTimeout(...)` + WeakMap |
| 30 | `main.js:17-49` | Correctness | INFO | postJsonRequest mirrors apiRequest.js but diverges on AbortSignal | No signal support, error message generic 'Network response was not ok' vs SPA's typed errors | Future 401 fix may miss admin bar | Extract `src/lib/nonce.js` or checklist both |
| 31 | `main.js:60-101` | Correctness | INFO | pendingRefresh thundering-herd dedup verified correct | `if(pendingRefresh) return pendingRefresh; ... pendingRefresh=refreshPromise.finally(()=>pendingRefresh=null)` | Exemplary | No change |
| 32 | `main.js:133-154` | Accessibility | MEDIUM | Dismiss button missing type, notice role always alert | `createElement('button')` without type => submit in form; `role=alert` for success (should be status/polite) | Form submission hazard + assertive success | Add `type='button'`; `role= error?'alert':'status'` + `aria-live` assertive/polite |
| 33 | `main.js:158-163` | Correctness | LOW | pagehide clears timer not DOM | `clearTimeout(fallbackTimer)` leaves `.wppo-admin-notice` in bfcache | Stale notices on pageshow restore | Also `querySelectorAll('.wppo-admin-notice').forEach(el=>el.remove())` |
| 34 | `main.js:200-215` | Security | MEDIUM | Path traversal double-encode bypass | `decodedPath.includes('..')` misses `%252e%252e`→`%2e%2e` after single decode; ignores `\` and `//` | Client bypass possible; server is source of truth but should harden | Recursive decode 3× + check `%2e`, `\`, `//` |
| 35 | `main.js:207` | Correctness | LOW | Path length 2048 arbitrary | `path.length>2048` fallback to '/' | May truncate long WPML slugs | Align with server MAX_PATH 512 |
| 36 | `rum.js:14-19` | Correctness | INFO | Early return gate verified correct | `!window.wppoRum || !apiUrl || typeof performance==='undefined'` exit | Prevents errors on cached pages without config | No change |
| 37 | `rum.js:27` | Resource Leak | LOW | scheduleTimerId cleared in send but not on visibilitychange re-entry | `visibilitychange hidden → send()` clears timer via send(); correct | No leak | Verified |
| 38 | `rum.js:56-96` | Correctness | MEDIUM | sent flag never resets on bfcache pageshow | `let sent=false` IIFE set true after first send; bfcache back nav never resets | Under-reports RUM for bfcache navigations | Listen `pageshow` event if `event.persisted` → reset sent + re-schedule |
| 39 | `rum.js:82-88` | Correctness | MEDIUM | sendBeacon return value ignored | `if(navigator.sendBeacon){ sendBeacon(...); return; }` ignores false (quota full) | Drop on beacon quota full | `if(navigator.sendBeacon && navigator.sendBeacon(url,blob)) return;` fallback to fetch |
| 40 | `rum.js:90-96` | Correctness | MEDIUM | fetch fallback missing keepalive + catch | `fetch(apiUrl,{method:'POST',headers,body,credentials:'omit'})` no keepalive, no .catch | Old Safari without sendBeacon loses payload on unload; unhandled rejection | Add `keepalive:true` + `.catch(()=>{})` |
| 41 | `rum.js:99-112` | Performance | LOW | TTFB/FCP reads getEntriesByType at load | `performance.getEntriesByType('navigation')[0].responseStart` + paint loop | Correct buffered read | No change |
| 42 | `rum.js:116-160` | Correctness | INFO | PerformanceObserver buffered:true verified correct | Captures entries before script load | Required for LCP/CLS | No change |
| 43 | `rum.js:130-139` | Correctness | LOW | CLS sum never resets session window | `cls+=entry.value` lifetime sum; spec uses 5s session window max | Over-reports vs CrUX lifetime vs session | Document lifetime convention or implement gap reset |
| 44 | `rum.js:146-151` | Correctness | LOW | INP uses last entry not max per batch | `values.inp=entries[entries.length-1].duration` overwrites per batch | Under-reports worst interaction if multiple INP in one batch | Track maxInp across batches: `values.inp=Math.max(values.inp||0, maxDuration)` |
| 45 | `rum.js:162-194` | Correctness | INFO | Settlement 5s + visibilitychange + pagehide guarded by sent | Both call send(); second no-op via sent flag; pagehide {once:true} | Correct; visibilitychange not once is intentional | No change |
| 46 | `cloudflare-worker.js:38` | Correctness | MEDIUM | Query bypass regex too narrow + uses searchParams.toString() | `/(?:^|&)(s|ver|v)(?:=|&|$)/.test(url.searchParams.toString())` – misses `?p=`, `?preview=true` in query, bypasses only 3 keys; utm etc cached incorrectly | Search pages may be cached as HTML; versioned assets bypass inconsistent | Use `url.searchParams.has('s') || has('preview') || has('ver') || has('v')` or bypass any `url.search` for precision |
| 47 | `cloudflare-worker.js:46` | Bug | HIGH | Preview bypass checks pathname not query | `url.pathname.includes('preview=true')` – preview is query param `?preview=true`, never in pathname | Preview requests cached as HIT, leaking draft content to edge | Change to `url.searchParams.has('preview')` or `url.search.includes('preview=true')` |
| 48 | `cloudflare-worker.js:43-49` | Security | MEDIUM | Bypass list missing wp-json, wp-cron, feed, logged-in cookies | Only checks /wp-admin, /wp-login, preview | Edge may cache /wp-json responses as HTML or serve cached HTML to logged-in users | Add `pathname.startsWith('/wp-json')`, `/wp-cron`, `/feed`, check `Cookie: wordpress_logged_in` or `Authorization` header → bypass |
| 49 | `cloudflare-worker.js:52` | Correctness | MEDIUM | cacheKey uses Request with original headers | `new Request(url.toString(), request)` clones headers → Vary by UA/Cookie creates cache fragmentation | Cache misses on different UA; storage blowup | Use `new Request(url.toString(), {method:'GET'})` or `cacheKey = url.toString()` normalized |
| 50 | `cloudflare-worker.js:59-74` | Correctness | MEDIUM | Revalidation uses originRes.body stream without cloning originResponse | `new Response(originRes.body, originRes)` then `cache.put(res.clone())` – body can be consumed once; clone after constructing may lock | Revalidation may throw "body already used" under load | Clone originResponse first: `const clone=originRes.clone(); new Response(clone.body, clone)` |
| 51 | `cloudflare-worker.js:63` | Correctness | LOW | Content-Type check case-sensitive | `content-type.includes('text/html')` – header may be `Text/HTML` | Misses uppercase | Lowercase: `(headers.get('content-type')||'').toLowerCase().includes('text/html')` |
| 52 | `cloudflare-worker.js:65-68` | Correctness | LOW | Placeholder strings remain if Edge_Cache fails to replace | `Cache-Control: public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}` literal | Invalid Cache-Control if deployed raw | Add JS fallback: `const ttl='{{CACHE_TTL}}'.startsWith('{{')?300:'{{CACHE_TTL}}'` |
| 53 | `cloudflare-worker.js:76-81` | Performance | LOW | HIT response creates new Response from cached body | `new Response(cachedResponse.body, cachedResponse)` loses body stream idempotency vs clone | Extra allocation | Use `new Response(cachedResponse.body, {headers:..., status:...})` or `cachedResponse.clone()` then set headers |
| 54 | `cloudflare-worker.js:85-96` | Security | MEDIUM | No check for Cache-Control: private / Set-Cookie from origin | Only checks `originResponse.ok && content-type text/html` – caches private responses | Logged-in HTML with Set-Cookie cached at edge, session leak | Add `!originResponse.headers.has('set-cookie') && !originResponse.headers.get('cache-control')?.includes('private')` |
| 55 | `bunny-edge.js:28-51` | Correctness | HIGH | Bunny adapter uses Cloudflare APIs not available on Bunny | `caches.default`, `event.waitUntil`, `handleRequest(event)` with `addEventListener('fetch')` – Bunny Edge Rules use different runtime (Bunny Edge Scripting `async function handleRequest`? Actually Bunny uses `addEventListener` but not caches.default) | Template not deployable as-is; will throw ReferenceError on Bunny | Rewrite to Bunny Perma-Cache API or document as pseudo-code reference; align with `Edge_Cache::get_bunny_edge_js()` output |
| 56 | `bunny-edge.js:29` | Bug | MEDIUM | cache.match(request) uses full Request object | `cache.match(request)` varies by headers as in Cloudflare | Same fragmentation as CF | Use URL string key |
| 57 | `bunny-edge.js:25` | Duplication | LOW | Duplicates Cloudflare query bypass regex bug | Same `/(?:^|&)(s|ver|v)/` on searchParams | Same fix as CF | Unify helper `shouldBypass(url)` shared via Edge_Cache PHP |
| 58 | `app.html:1-5` | Accessibility | LOW | Loading heading not live region | `<h2>Loading...</h2>` inside `#performance-optimisation` replaced by SPA; no aria-live | Screen readers miss load completion | Add `aria-live="polite" aria-busy="true"` |
| 59 | `perf-translations.php:32-33` | Security | INFO | ABSPATH guard verified correct | `if(!defined('ABSPATH')) exit;` | Standard WP guard | No change |
| 60 | `perf-translations.php:37-42` | Documentation | INFO | Template illustration not loaded directly | `return array('plural-forms'=>..., 'messages'=>...)` – illustrative only, actual files generated via `WP_Translation_File::transform` | Correct per AGENTS.md | No change |
| 61 | `lazyload.test.js:33-97` | Test Quality | HIGH | loadScriptImpl copy-pastes production loadScript | Parallel impl diverges: test auto-calls `replacement.onload()` synchronously, production waits for real load event | False green if production fixed but test not | Export real loadScript (refactor top-level init guard) and import |
| 62 | `lazyload.test.js:388-440` | Test Quality | HIGH | Fallback test never exercises real code | Defines local `lazyLoadFallback`, asserts `typeof wppoLazyLoadFallback==='undefined'` trivially | Zero integration coverage for no-IO branch | resetModules, delete IntersectionObserver, require lazyload, dispatch scroll |
| 63 | `lazyload.test.js:99-162` | Test Quality | MEDIUM | Prototype pollution not restored via descriptor | `Object.defineProperty(HTMLImageElement.prototype,'loading',...)` not saving descriptor | Order-dependent flakes | Save descriptor + restore in afterEach |
| 64 | `main.test.js:25-42` | Test Quality | MEDIUM | beforeAll require + manual DCL dispatch fragile | Single DCL listener closed over live wppoObject; no isolateModules; timer-assertions with setTimeout 50ms | Cannot test 403 retry; flakes under CI | Use isolateModules or helper reset; use waitFor + fake timers |
| 65 | `main.test.js:69-88` | Test Quality | MEDIUM | Path validation branch uncovered | Only tests path '/' from JSDOM; no cases for '..', 2049, empty, no-slash | Security guard zero coverage | Add parameterized cases for traversal + long path |
| 66 | `rum.js` vs `class-rum.php:195` | Security | LOW | RUM path includes query string | `wppo-rum-config` path = `esc_url_raw(REQUEST_URI)` includes `?x=...`; `sanitize_sample` does `wp_parse_url(path,PHP_URL_PATH)` stripping query but token is minted with full path including query | Token mismatch if query present; beacon rejected | Mint token and path consistently with PHP_URL_PATH |

---

## No-Issues Confirmed

| Area | File:Line | Why correct |
|------|-----------|-------------|
| Module data JSON parse | `lazyload.js:20-25` | try/catch + warn + return {} prevents crash on corrupt tag |
| NATIVE_LAZY_SUPPORTED | `lazyload.js:44` | 'loading' in HTMLImageElement.prototype spec compliant |
| loadScriptsByPriority priority grouping | `lazyload.js:210-224` | Promise.allSettled per level high→normal→low, correctly sequential levels |
| scriptLoadPromise singleton | `lazyload.js:243-247` | Dedupes concurrent loadScripts (performance exemplary) |
| requestIdleCallback fallback comment | `lazyload.js:388-392` | Math.min(2000,idleTimeout) handles deadline vs delay, commented |
| MutationObserver nodeType guard | `lazyload.js:723` | nodeType===1 excludes text/comment prevents QSA on non-element |
| Placeholder handler ordering | `lazyload.js:633-644` | addEventListener('load') before src= prevents cached-image miss |
| Dominant-color transition | `lazyload.js:474` | transition before transparent smooth |
| Nonce thundering-herd | `main.js:60-98` | pendingRefresh shared + finally clear verified safe |
| postJsonRequest retry-on-403 | `main.js:29-39` | isRetry once prevents loop |
| RUM sent + pagehide once | `rum.js:56-58,183-194` | sent flag prevents double send |
| RUM buffered:true | `rum.js:122-125,140,154` | Captures entries before script, required for LCP |
| RUM 5s settlement | `rum.js:162-170` | setTimeout after load for INP window, balanced |
| Edge SWR header | `cloudflare-worker.js:65,78,91` | `X-Edge-Cache HIT/MISS/REVALIDATE` + `X-WPPO-Edge` for debugging correct |
| ABSPATH guard | `perf-translations.php:32` | Standard WP file guard |
| Translation template | `perf-translations.php:37` | Illustrative return array correct per docblock |
| path sanitization | `class-rum.php:273-275` | wp_parse_url path + substr 512 verified in PHP collector (client mirrors partially) |

---

## Duplicate / Dead-Code Inventory

| Type | Location | Description |
|------|----------|-------------|
| DEAD CODE | `lazyload.js:102-103` | LAZY_SELECTOR never used (deprecated alias) |
| DUPLICATE | `lazyload.js:551-564` ↔ `591-599` | Native iframe restore duplicated |
| DUPLICATE | `lazyload.js:617-628` vs `782-828` | PICTURE restore missing in fallback |
| DUPLICATE | `cloudflare-worker.js` ↔ `bunny-edge.js:19-63` | 80% identical SWR logic duplicated; should share via PHP Edge_Cache helper |
| DUPLICATE | `main.js:1-101` ↔ `src/lib/apiRequest.js` | pendingRefresh/postJsonRequest 85% identical; intentional bundle isolation per comment line 4 |
| DUPLICATE | `__tests__/lazyload.test.js:33-97` | loadScriptImpl copy-paste production |
| DEAD CODE | `lazyload.js:420-423` | Empty else-if does nothing |
| DEAD CODE | `bunny-edge.js` | `caches.default` API not valid on Bunny – dead template |

---

## Tracing Summary

**IntersectionObserver (4 instances):**
- Lazy images/videos: `globalObserver` rootMargin 200px (lazyload.js:609) observes `img[data-src]/iframe/video.wppo-lazy-video` via `observeElement` (542) guarded by WeakSet; unobserves on intersection (682) + checkCleanup (517).
- Viewport scripts: `observeViewportScripts` (314) observes `script[data-wppo-delay-strategy=viewport]` rootMargin 200px, unobserves per entry (333), no disconnect.
- Backgrounds: `backgroundObserver` (1007) observes `.wppo-lazy-bg` rootMargin 200px, unobserves per entry, disconnects when zero remain.
- Fallback when absent: scroll-based `lazyLoadFallback` (771) with 200ms debounce, `isElementInViewport` (845) rect vs viewport, only scroll listener.

**MutationObserver (1):**
- `mutationObserver` (719) observes `document.body childList subtree`; for each addedNodes nodeType===1, if IMG/IFRAME/VIDEO or matches selector → observeElement; if matches selector or has child selector → startSafetyScan; if .wppo-video-placeholder → initVideoPlaceholders. Guarded against re-entry (715) but disconnected by checkCleanup.

**Deferred Script Loading:**
- Strategies: interaction (default), idle (requestIdleCallback 3000ms → setTimeout min 2000), viewport (IO). `delayConfig` from `window.wppoDelayConfig || moduleData.delayConfig` (197). Priority grouping high/normal/low via `data-wppo-delay-priority` (210). Singleton `scriptLoadPromise` (243) dedupes interaction. Triggers: idle via rIC/setTimeout, viewport via IO, interaction via 6 events mouseenter/mousedown/mouseover/touchstart/scroll/keydown with {once:true}.

**Admin Bar Cache Clearing (main.js):**
- Two buttons `#wp-admin-bar-wppo_clear_all .ab-item` (165) POST `/clear_cache {action:clear_cache}` and `#wp-admin-bar-wppo_clear_this_page` (193) POST `{action:clear_single_page_cache, path}` with path validation (200-215) decodedPath '..' check. Shared `postJsonRequest` (17) POST JSON + X-WP-Nonce, 403→refreshNonce→retry once. `refreshNonce` (60) deduplicates concurrent 403s via pendingRefresh + FormData `wppo_get_nonce` to ajaxUrl. Notices via `wp.data.dispatch('core/notices')` or fallback div with shared fallbackTimer + pagehide clear.

**RUM Beacon (rum.js):**
- IIFE gated on `window.wppoRum.apiUrl` + performance. Collects TTFB (navigation responseStart 100) + FCP (paint 104) synchronously; LCP/CLS/INP via PerformanceObserver buffered:true (116/132/146). `send()` (56) guards sent + hasMetric, clears timer, disconnects observers, JSON `{token,path,...values}` via `navigator.sendBeacon(Blob)` (82) else `fetch POST credentials:omit` (90). Settlement `scheduleSend` 5000ms after load (162) + `visibilitychange hidden` (178) + `pagehide once` (183).

**Edge Workers SWR:**
- Cloudflare: GET-only, bypass query `s|ver|v` + pathname /wp-admin /wp-login /preview=true, edge cache `caches.default.match(cacheKey)` (55), HIT→waitUntil revalidate (59) + serve stale with Cache-Control + X-Edge-Cache HIT, MISS→fetch origin (85) + cache if ok+text/html. Placeholders {{ORIGIN_URL}} {{CACHE_TTL}} {{SWR}} replaced by Edge_Cache PHP. Bunny: same logic with `handleRequest(event)` + `addEventListener('fetch')` but uses same Cloudflare API (invalid).

---

## Open Questions

1. **Module side-effects vs testability:** lazyload.js top-level probe + delayedScripts query prevents `import {loadScript}` without side-effects. Tests copy-paste impl (lazyload.test.js:33). Should expose `initLazyload()` and lazy probe so Jest can import real code? Confirm boundary with `class-main.php` module vs classic enqueue.
2. **Native lazy granularity:** When useNativeLazy true but NATIVE_LAZY_SUPPORTED false (Safari 16), USE_NATIVE_LAZY false → IO only. Is IO-only intentional to avoid sizes=auto interaction? Verify `class-image-optimisation.php` wppo-lazyload stripping.
3. **Video placeholder a11y API:** Placeholder title hardcoded 'YouTube video player' (lazyload.js:929) not localized; should PHP render `data-wppo-a11y-label` so initVideoPlaceholders sets aria-label without JS i18n? Confirm i18n pipeline with `class-main.php`.
4. **RUM token + caching:** rum.js:77 sends token via inline `window.wppoRum` printed by `class-rum.php:204` before build/rum.js. Is inline config correctly excluded from `advanced-cache.php` drop-in buffer on multisite? Check `class-cache.php` exclusion for wppo-rum-config tag.
5. **Edge preview bypass:** cloudflare-worker.js:46 uses pathname.includes('preview=true') which never matches query; was this caught in Edge_Cache PHP tests? Check `tests/php/EdgeCacheTest.php` if exists.
6. **Bunny adapter target runtime:** bunny-edge.js uses `caches.default` + `addEventListener('fetch')` – is Bunny Edge Scripting actually Cloudflare-compatible or is template purely illustrative? Confirm `Edge_Cache::get_bunny_edge_js()` PHP output vs template.
7. **Admin bar selector stability:** main.js:165 uses `#wp-admin-bar-wppo_clear_all .ab-item` (underscore). WP core sanitizes node IDs via `sanitize_html_class` – does underscore survive? Should use `[id*="wppo_clear"]` or localized selector from PHP `admin_bar_menu`?
8. **RUM bfcache:** rum.js sent flag not reset on `pageshow persisted` – is under-reporting intentional or should bfcache re-beacon be suppressed?

---

## Audit Checklist Coverage

- [x] Every file read line-by-line 2537 lines (no sampling) via Read offsets
- [x] Listeners traced: lazyload 6 triggerEvents + DCL + scroll vs main 2 clicks + DCL + pagehide vs rum load+visibilitychange+pagehide+3 PerformanceObservers vs edge fetch
- [x] Observers traced: IntersectionObserver (lazy/viewport/backgrounds), MutationObserver (lazy+placeholders), 3× PerformanceObserver, plus scroll fallback + rIC + setInterval safety scan
- [x] DOM ops: createElement/script replaceChild, style.backgroundImage, dataset, classList, attr shredding (data-src/data-sizes/wppo-type/wppo-src)
- [x] Nonce refresh: pendingRefresh dedup + finally + 403 retry traced (mirrored apiRequest.js)
- [x] Fetch: postJsonRequest JSON+X-WP-Nonce, rum sendBeacon→fetch keepalive, FormData nonce refresh, edge fetch origin
- [x] Performance: auto-sizes probe reflow, safety 10s, idle rIC→setTimeout 2000, priority allSettled, rootMargin 200px, edge SWR
- [x] a11y: video placeholder keyboard missing, notice role/alert, dismiss type, app.html loading h2
- [x] Security: path traversal double-encode, iframe attrs on* denylist, CSP nonce passthrough, backgroundImage, token exposure, edge private cache/Set-Cookie leak, preview bypass bug
- [x] Error handling: JSON parse try, probe try, onerror forwarding, inline throw gap, sendBeacon return ignored, fetch catch missing, edge revalidation catch swallow
- [x] Leaks: viewport observer no disconnect, safety forever, background observer, 30s video timeout, fallbackTimer shared, scheduleTimerId
- [x] Duplicates: LAZY_SELECTOR vs getLazySelector, iframe native, idle vs priority, test copy-pastes, CF vs Bunny duplication
- [x] Dead code: LAZY_SELECTOR unused, empty else-if, Bunny caches.default invalid
- [x] Edge workers: Cloudflare vs Bunny API comparison, placeholder replacement, bypass checks, cacheKey fragmentation, content-type case, private/Set-Cookie missing
- [x] No production code modified

**No production code modified.**
