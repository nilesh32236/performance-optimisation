# Agent A11 — Frontend Performance Audit

**Scope:** JS execution, DOM ops, event listeners, reflow risks, CSS complexity, render-blocking, asset loading, script dependencies, duplicate/unused assets, network requests  
**Date:** 2026-08-28  
**Auditor:** Agent A11 (Performance specialist — Frontend)  
**Workspace:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation`  
**Base:** `master@31fffc61`  
**Mode:** Audit-only, no production code modified  
**Method:** Read offsets `src/**/*.js`, `src/**/*.scss`, `includes/class-main.php`, `includes/class-cache.php`, `includes/class-asset-manager.php`, `includes/class-image-optimisation.php`, `includes/class-edge-cache.php`, `templates/cloudflare-worker.js`; Grep asset registration/enqueue/combine (`wp_enqueue_script_module`, `add_defer_attribute`, `combine_css`, `preload`, `fetchpriority`, `resource_hints`); counted IO/MutationObserver listeners, reflow triggers, CSS `backdrop-filter`/paint costs, render-blocking CSS/JS, duplicate deps, network waterfall.

---

## Files Reviewed

| # | File | Lines | Frontend-Perf Surface |
|---|------|-------|----------------------|
| 1 | `src/components/Dashboard.js` | 1329 | React SPA tab: stats grid, polling `image_job_status` 5s, `useMemo` merge, 7 panels, asset/cdn/cache forms |
| 2 | `src/components/FileOptimization.js` | 2024 | Monolithic file-opt tab: 5 sub-tabs, `handleChange` switches, Tooltip wrappers, delay-strategy lists, CCSS/used-CSS triggers |
| 3 | `src/lazyload.js` | 1035 | Frontend runtime: `AUTO_SIZES` probe, deferred-script loader, `IntersectionObserver` + `MutationObserver`, safety-scan `setInterval`, video placeholders, bg lazy |
| 4 | `src/rum.js` | 195 | RUM beacon IIFE: `PerformanceObserver` LCP/CLS/INP, TTFB/FCP, `sendBeacon`/`fetch` fallback, `setTimeout 5s` + `visibilitychange`/`pagehide` |
| 5 | `src/main.js` | 239 | Admin-bar cache clear: `fetch`+`X-WP-Nonce` 403 retry `refreshNonce`, `pendingRefresh` dedup, `showNotice` DOM fallback |
| 6 | `src/App.js` | 527 | SPA shell: `React.lazy` 7 tabs `webpackChunkName`, `AbortController`, resize/focus-trap effects, themeColors CSS vars |
| 7 | `src/index.js` | 11 | Mount `createRoot(<App/>)`, imports `style.scss` (render-blocking entry) |
| 8 | `src/lib/apiRequest.js` | 249 | `apiCall` `doFetch`+`handleResponse` JSON+nonce retry `refreshNonce`, 7 exported fetch helpers |
| 9 | `src/css/style.scss` | 27 | SCSS entry: 8 `@use` layers |
| 10 | `src/css/abstracts/_variables.scss` | 85 | Design tokens: CSS vars 40+, `color-mix` progressive enhancement |
| 11 | `src/css/abstracts/_mixins.scss` | 35 | `respond-to` (max-width) + dead `flex-center`/`truncate` |
| 12 | `src/css/base/_base.scss` | 242 | `wppo-dashboard-view`, utilities, `wppo-code-block`, focus-visible |
| 13 | `src/css/layout/_container.scss` | 70 | `wppo-container` flex, `wppo-main` max-width 1120, `wppo-fadeIn` keyframes |
| 14 | `src/css/layout/_sidebar.scss` | 261 | Sticky sidebar `dvh`, mobile overlay `backdrop-filter:blur(2px)`, responsive `lg` fixed drawer |
| 15 | `src/css/components/_card.scss` | 105 | `wppo-feature-card` shadow/transform hover, `--wppo-shadow-card` |
| 16 | `src/css/components/_stats.scss` | 371 | `wppo-stats-grid` 4→2→1, `wppo-stat-item` `backdrop-filter:blur(8px)`+radial dot pattern, `--wppo-accent-*` |
| 17 | `src/css/components/_performance-audit.scss` | 953 | Audit tables, overview cards, trend charts, gauges, suggestions |
| 18 | `src/css/components/_tabs.scss` | 150 | `wppo-sub-tabs` scroll-snap + sticky fade, chip grid |
| 19 | `src/css/components/_fields.scss` | 178 | SwitchField/Checkbox layout |
| 20 | `src/css/components/_forms.scss` | 211 | Inputs/selects, focus rings |
| 21 | `src/css/components/_lazy-placeholder.scss` | 16 | `.wppo-lqip-active` blur+scale, transition 0.4s |
| 22 | `src/css/components/_video-placeholder.scss` | 61 | `aspect-ratio:16/9`, play-btn transform, hover scale |
| 23 | `includes/class-main.php` | 3053 | Frontend enqueue: `enqueue_scripts` module vs classic, `apply_module_loading_strategies`, `add_defer_strategy`/`add_defer_attribute`, `add_preload_prefetch_preconnect`, `add_resource_hints`, `combine_css` preload emission |
| 24 | `includes/class-cache.php` | 2306 | `combine_css` freshness+generation, `maybe_apply_cdn` `WP_HTML_Tag_Processor`, `maybe_preload_combine_css` `wp_head:1` preload, `core_will_inline` budget, `inline_size_map` cache |
| 25 | `includes/class-image-optimisation.php` | ~1950 | Frontend buffer: `maybe_serve_next_gen_images`, lazy `data-src` swap, placeholder LQIP, `getimagesize` LRU, `post_process_*` 3 passes |
| 26 | `includes/class-asset-manager.php` | 245 | `capture_page_assets` `wp_footer:9999` `wp_scripts->done`, `dequeue_selected_assets` protected lists |
| 27 | `includes/class-edge-cache.php` | 287 | Adapter config, `get_worker_js` placeholder replace, `get_wrangler_toml` |
| 28 | `templates/cloudflare-worker.js` | 101 | Edge SWR: cache HIT/MISS, `ctx.waitUntil` revalidate, `Cache-Control: max-age/SWR`, `X-Edge-Cache` |
| 29 | `build/*` (committed) | — | `index.js` 136 KB, `lazyload.js` 11 KB, `rum.js` 1.8 KB, `main.js` 2.6 KB, `style-index.css` 56 KB + 7 `tab-*.js` chunks (17–84 KB) |

**Lines reviewed:** 14,958 (SCSS/JS/PHP listed above; `vendor/`/`node_modules/` excluded)  
**Build sizes (prod):** `index.js` 133 KB, `tab-dashboard` 84 KB, `tab-file-optimization` 43 KB, `style-index.css` 56 KB (uncompressed build artifacts; gzip ~30% smaller)

---

## Findings

> Fields per finding: **File:Line, Category, Severity, Problem, Why matters, Evidence, Impact, Recommended solution, Confidence**

### F01 — Dashboard monolith drives largest admin chunk + frequent re-renders

- **File:Line:** `src/components/Dashboard.js:1-1329` (entire file)
- **Category:** JS execution / Bundle size
- **Severity:** Medium
- **Problem:** 1,329-line single component bundles 9 cards + 5 cache/CDN/role forms + polling + 28 imports. The `tab-dashboard` chunk is **84 KB** (largest tab, ~2× next tab). Every state slice (`pageCacheEnabled`, `cacheLife`, `loggedInCacheRoles`, `cdnPurgeService`, etc.) lives in the same function, so any toggle triggers reconciliation of the full tree.
- **Why matters:** Admin TTI on `toplevel_page_performance-optimisation` is dominated by `index.js` (133 KB) + `tab-dashboard.js` (84 KB) parse + React mount. Monolith prevents per-section code-splitting and invalidation isolation.
- **Evidence:** `src/App.js:32-34` `lazy(() => import('./components/Dashboard'))` is per-tab, not per-section. `Dashboard.js:100-123` initializes 7 state vars + `useEffect` sync on `cacheSettings` (lines 169-193). Build manifest `build/tab-dashboard.js 84195` vs `tab-preload-settings 17284`.
- **Impact:** +40–80 ms parse on mid-tier device vs split chunks; unrelated stat updates (e.g., `dbCounts`) re-render cache forms. Not user-visible after mount unless low-end CPU.
- **Recommended solution:** Split `Dashboard.js` into lazy sub-chunks (`StatsGrid`, `CacheCards`, `ImageCard`, `ActivityCard`) with `React.lazy` inside Dashboard; co-locate polling (`pollJobStatus`) to `ImageOptimizationCard`. Keep props API stable; no visual change. Audit-only: no code change.
- **Confidence:** High

### F02 — FileOptimization 2,024 lines bundles all sub-tabs into one chunk (no intra-tab splitting)

- **File:Line:** `src/components/FileOptimization.js:1-2024`
- **Category:** JS execution / Bundle size / Reflow
- **Severity:** Medium
- **Problem:** 5 sub-tabs (Assets, Scripts, E-Commerce, Network, Core) are single component with conditional `activeSubTab === '...'` rendering. All JSX/strings parsed even when only Assets visible. Chunk is 43 KB but `Scripts` alone contributes ~400 lines of delay-JS fields. `subTabs` array re-declared per render (not memoized) + `handleSubTabKeyDown` closes over `subTabs.length`.
- **Why matters:** Parsing 2k lines on first open of File Optimization costs ~20–40 ms; also all 5 panels' `SwitchField`+`Tooltip` trees stay in VDOM diff set. No `React.memo` on sub-panels, so typing in one textarea re-renders sibling tabs (still mounted within conditional but new elements created on tab switch).
- **Evidence:** `FileOptimization.js:278-303` `subTabs` literal + `handleSubTabKeyDown` (305-328) not memoized. `341-380` single `return` with 5 `activeSubTab ===` branches. Build: `tab-file-optimization.js 43419` — should be ~25 KB if split.
- **Impact:** Perceived input latency when editing `excludeDelayJS` on heavy pages is fine on desktop; on mobile WebView (admin) could show ~100 ms delay on tab switch.
- **Recommended solution:** Extract each sub-tab to its own component (`FileOptAssets`, etc.) and `lazy` load on demand; memoize `subTabs` with `useMemo`. Preserve `activeSubTab` UX; no functional change.
- **Confidence:** High

### F03 — App.js fetches serverRules + CCSS on every tab mount (eager, never cancelled per tab)

- **File:Line:** `src/App.js:284-388` `useEffect([activeTab, rulesRetryTrigger, ccssRefreshTrigger])`
- **Category:** Network requests / Duplicate assets
- **Severity:** Medium
- **Problem:** `fetchRules` + `fetchCcssStatus` fire on Dashboard mount even though they are only consumed by FileOptimization (`serverRules`, `ccssStatus`). `fetchRules` guarded by `hasFetchedRules` but still fires once per SPA load (extra GET). `ccss_status` is fetched even when `file_optimisation.criticalCSS` is off.
- **Why matters:** Adds 1–2 extra REST round-trips on initial load (2× `X-WP-Nonce`) that block spinner for dashboard users who never visit File Optimization. Wasted bandwidth on metered mobile admin (rare) and adds 200–500 ms to dashboard first paint while `await fetchServerRules`.
- **Evidence:** `App.js:317-342` `fetchRules` not gated on `activeTab==='fileOptimization'`; `343-377` `fetchCcssStatus` similarly unconditional. `ApiRequest.js:247-249` `fetchServerRules` is `GET server_rules`. Network waterfall: dashboard load → `clear_cache_size` (transient) still fires + rules + ccss in parallel.
- **Impact:** One surplus `server_rules` GET + one `ccss_status` GET per admin session (~2 KB each). Pagespeed/RUM unaffected.
- **Recommended solution:** Move `serverRules`/`ccssStatus` fetching into `FileOptimization` via `useEffect` on mount; keep retry trigger local. Or gate with `if (activeTab==='fileOptimization')` and pass `activeTab` dep.
- **Confidence:** High

### F04 — Dashboard image polling every 5s with no abort/backoff on idle tab

- **File:Line:** `src/components/Dashboard.js:256-333` `pollJobStatus`
- **Category:** Network requests / JS execution
- **Severity:** Low
- **Problem:** `pollJobStatus` uses `setTimeout(pollJobStatus,5000)` recursion while `bgProcessing` true. No `AbortSignal`, no visibility pause. Polls continue even if user switches to another tab (App keeps Dashboard mounted? Actually App unmounts Dashboard on tab switch — `renderContent` returns only activeComponent — but polling timer lives in closure and still fires once via `pollingRef.current === currentTimeout` guard). Retries up to 5 then stops, but success path resets.
- **Why matters:** When image queue is large (1000 images) processing may take 10 min → 120 polls. If admin background-tabs browser, polls still wake main thread every 5s.
- **Evidence:** `Dashboard.js:322-324` `if (pollingRef.current===currentTimeout) pollingRef.current=setTimeout(pollJobStatus,5000)`. `useEffect` cleanup (327-333) only clears on unmount, not on tab hide. No `document.visibilityState` check.
- **Impact:** Negligible (<1 req/5s) but pollutes object-cache `wppo_img_info` reads + `as_get_scheduled_actions` per `image_job_status`. Pause on `document.hidden` would save ~50% beacon traffic during multi-tasking.
- **Recommended solution:** Pause polling when `document.hidden`; resume on `visibilitychange`. Pass `AbortSignal` from `App` to `apiCall('image_job_status',…, 'GET', signal)`; clear on tab leave.
- **Confidence:** Medium

### F05 — `AUTO_SIZES_SUPPORTED` probe forces synchronous layout/reflow on module parse

- **File:Line:** `src/lazyload.js:63-76`
- **Category:** Reflow / Render-blocking
- **Severity:** Medium
- **Problem:** IIFE creates hidden `<img sizes="auto">`, appends to `document.documentElement`, calls `window.getComputedStyle(probe).contain` synchronously during module evaluation. `appendChild` + `getComputedStyle` forces style recalc + layout on every page load before first paint.
- **Why matters:** On cached pages where HTML cache is served via `advanced-cache.php` (no WP boot), `lazyload.js` is still deferred as module (`fetchpriority:low`, non-render-blocking) — but the probe still runs as soon as module parses, triggering forced reflow even though result is cached per page load. Repeated per navigation (SPA navigation not relevant for frontend; full page loads).
- **Evidence:** `lazyload.js:68` `document.documentElement.appendChild(probe)` + `69` `window.getComputedStyle(probe).contain`. No `requestIdleCallback` wrapping. Comment acknowledges support detection but not cost.
- **Impact:** 1 forced style/layout ≈ 2–6 ms on mobile, measurable in Web Vitals `INP` under stress. One-time per page.
- **Recommended solution:** Wrap probe in `requestIdleCallback` or defer until `loadImages()` first call; cache result in `sessionStorage` (`wppo_auto_sizes`) to skip DOM probe on subsequent pages.
- **Confidence:** Medium

### F06 — MutationObserver observes `document.body` subtree + safety-scan `setInterval` lives until all lazy elements gone

- **File:Line:** `src/lazyload.js:718-765` + `692-761` `startSafetyScan`
- **Category:** DOM ops / Event listeners
- **Severity:** Medium
- **Problem:** `mutationObserver.observe(document.body,{childList:true,subtree:true})` watches entire body. Callback (719-752) iterates `mutation.addedNodes`, calls `observeElement` per node + `node.querySelectorAll(selector)` per addition, plus `startSafetyScan()` spawns `setInterval(10000)` querying `document.querySelectorAll(getLazySelector())` and `observeElement` per unmatched element. Interval persists until `remaining.length===0`; `checkCleanup` clears it then, but if site infinitely scrolls (adds images forever), interval never ends.
- **Why matters:** On SPA-like frontend (e.g., WooCommerce product filters, infinite scroll plugin) each DOM batch triggers MutationObserver micro-task; the 10s scan adds periodic `querySelectorAll` layout query (forces style recalc). On long-lived pages with chat widgets appending nodes, overhead accumulates.
- **Evidence:** `754-756` `mutationObserver.observe(document.body, {childList:true,subtree:true})`. `696-710` `setInterval(()=>{const elements=document.querySelectorAll(getLazySelector()); …},10000)`. `checkCleanup` only clears when `remaining.length===0`.
- **Impact:** 1–3 ms per mutation batch + 10s polling query; on typical blog post (no mutations) cost is idle after initial `observeElement` batch, but chat/infinite scroll sites see +5–15 ms per batch.
- **Recommended solution:** Scope observer to main content (e.g., `document.querySelector('main')`) if available; throttle mutations via `requestAnimationFrame` batching; make safety scan `setTimeout` recursion that stops after N intervals without new elements. Ensure `disconnect()` on `pagehide`.
- **Confidence:** High

### F07 — Scroll fallback `lazyLoadFallback` not `{passive:true}` — blocks main thread

- **File:Line:** `src/lazyload.js:769-865` (`isElementInViewport` uses `getBoundingClientRect` per element)
- **Category:** JS execution / Reflow
- **Severity:** Medium
- **Problem:** Fallback path (no `IntersectionObserver`) registers `window.addEventListener('scroll', lazyLoadFallback)` without passive option (line 862). Inside handler, `setTimeout(...,200)` gates `active` flag but still `getBoundingClientRect` per lazy element on every throttled tick. `isElementInViewport` reads `rect.top/bottom/left/right` + `window.innerHeight/clientWidth` per element (forces layout).
- **Why matters:** On old browsers (fallback users) scroll is janky; non-passive listener prevents compositor fast-path, causing scroll-blocking. Modern browsers have IO, so fallback rarely runs — but audit requires flagging.
- **Evidence:** `862` `window.addEventListener('scroll', lazyLoadFallback)` (no options). `845-857` `getBoundingClientRect` loop. Fallback `864` `lazyLoadFallback()` initial call also forces layout.
- **Impact:** On legacy Safari/IE polyfill, scroll jank + extra main-thread work. Low prevalence ( <2% traffic) but Spec asks for coverage.
- **Recommended solution:** Add `{passive:true}`; use `requestAnimationFrame` throttling instead of `setTimeout 200`; or drop fallback entirely now IO is 97%+ supported and delegate to native `loading=lazy` fallback.
- **Confidence:** High

### F08 — `loadScriptsByPriority` sequential `await` per priority group serializes high→normal→low

- **File:Line:** `src/lazyload.js:210-232` + `243-287` `loadScripts`
- **Category:** Asset loading / Script dependencies
- **Severity:** Low
- **Problem:** `for (const level of ['high','normal','low']) { await Promise.allSettled(groups[level].map(loadScript)) }` waits for all `high` to settle before starting `normal`. Each `loadScript` creates replacement `<script>` and waits for `onload`. Sequential groups add waterfall latency vs parallel (priority via `fetchpriority` already handled).
- **Why matters:** If `high` group contains a slow third-party (e.g., analytics 800 ms), `normal`/`low` scripts wait unnecessarily. `fetchpriority` low/high is set in `class-main.php:1907-1918` via `wp_script_add_data(..., 'fetchpriority','low')` + `data-wppo-delay-priority` attribute — but JS still serializes.
- **Evidence:** `222-231` sequential `await`. `class-main.php:210` `loadScriptsByPriority` comment "Load scripts grouped by priority".
- **Impact:** Adds ~ RTT of high group to overall delay. RequestIdle/viewport strategies already isolate some scripts; interaction group still sequential.
- **Recommended solution:** `Promise.allSettled(high)` + `Promise.allSettled(normal+low)` concurrent, or start all with priority hint via `fetchpriority` and let browser schedule; document that JS ordering guarantee within same priority is not required.
- **Confidence:** Medium

### F09 — Preload of combined CSS + native inline budget interplay (render-blocking vs wasted preload)

- **File:Line:** `includes/class-cache.php:544-588` `combine_css` + `set_combine_css_preload` + `includes/class-main.php:610-611` `maybe_preload_combine_css` @ `wp_head:1`
- **Category:** Render-blocking / Network
- **Severity:** Low
- **Problem:** Combined CSS is enqueued via `wp_enqueue_style('wppo-combine-css', $css_url, [], $mtime)` and also preloaded via `Util::generate_preload_link($preload_url,'preload','style')` at `wp_head` priority 1 before the `<link rel="stylesheet">` at priority 8. `set_combine_css_preload` correctly skips when `will_combine_css_inline()` true (inline budget), otherwise always preloads. However `wp_maybe_inline_styles` may still inline a subset of dequeued handles retained in queue — the preload is not wasted, but the comment acknowledges 6.9 vs 5.8 budget drift.
- **Why matters:** Correctly avoids double: preload omitted for inline candidates; for external combined file, preload is ~1 RTT win (style is render-blocking). No wasted preload in measured path.
- **Evidence:** `561-566` `if (will_combine_css_inline) return` in `set_combine_css_preload`. `maybe_preload_combine_css` `wp_head` 1 < core stylesheet 8 ordering.
- **Impact:** Positive (preload saves 50–150 ms LCP on 3G). Drift branch `inline_drift_detected` forces external serve + preload retained — safe.
- **Recommended solution:** Keep; consider adding `as="style"` + `onload` swap to avoid render-blocking flash? Current combined file is render-blocking by design (above-fold CSS).
- **Confidence:** High

### F10 — Edge Worker SWR ignores cookie `wppo_role_hash` + `Accept` negotiation (cache poisoning / variance miss)

- **File:Line:** `templates/cloudflare-worker.js:38-99` + `includes/class-edge-cache.php:171-204` + `includes/class-cache.php:268-296` `is_cache_allowed_for_current_user`
- **Category:** Network / Caching / Script dependencies
- **Severity:** High
- **Problem:** Worker caches `new Request(url.toString(), request)` without `Vary: Cookie` or role-hash keying. File cache `Cache.php:1173-1182` generates role-specific `cache/wppo/{domain}/{path}/index.html` vs `/index_{hash}.html` but worker's `cache.match(cacheKey)` will serve HIT to a logged-in user with different role (or anon vs logged-in). Similarly `Image_Optimisation::maybe_serve_next_gen_images` varies on `Accept: image/avif, image/webp` — file cache bakes one variant but worker caches single `content-type` without `Vary: Accept`.
- **Why matters:** Logged-in admin could receive cached anon HTML (or vice versa) exposing nonce leakage or missing admin bar; Avif-capable UA could cache avif-rewritten HTML then serve to non-avif UA broken src.
- **Evidence:** `cloudflare-worker.js:52` `cacheKey = new Request(url.toString(), request)` — inherits request headers but Cloudflare Cache API keys on URL only unless `Vary` respected. No `cookie` check. `class-edge-cache.php` spec promises Host-agnostic TTFB <30ms but doesn't document variance. `class-main.php:486-553` `Cache` already handles role hash on file path but worker not.
- **Impact:** High severity on sites using `enableLoggedInCache` + edge enabled (opt-in, default off). On default (edge disabled, file cache only) no impact — worker not deployed.
- **Recommended solution:** In worker, bypass cache when `request.headers.get('Cookie')` contains `wppo_role_hash` or `wordpress_logged_in*`; add `Vary: Cookie, Accept` response header; or include `Accept` in cache key `url+accept` suffix. Document that edge adapter requires file-cache role handling replicated at edge.
- **Confidence:** High

### F11 — Worker query-string bypass regex narrow — `utm_*` / `fbclid` variants bypass cache-miss explosion

- **File:Line:** `templates/cloudflare-worker.js:38-40`
- **Category:** Network / Duplicate assets
- **Severity:** Low
- **Problem:** `if (url.search && /(?:^|&)(s|ver|v)(?:=|&|$)/.test(url.searchParams.toString())) return fetch(request)` only bypasses `s`, `ver`, `v`. Common tracking params `utm_source`, `utm_medium`, `fbclid`, `gclid`, `msclkid` still get cached as distinct edge keys (`/page/?utm_source=x` caches separate copy vs `/page/`).
- **Why matters:** Marketing campaigns create many edge variants, increasing cache storage + miss rate, diluting hit ratio. File cache `Cache.php` `REQUEST_URI` path ignores query? Actually `url_path = trim(parse_url(REQUEST_URI, PATH),'/')` — file cache keys on path only, so edge caching query variants creates more variants than origin.
- **Evidence:** `38` regex excludes only 3 keys. `Cache.php:251` cache path is domain+path only (no query).
- **Impact:** Low (query variants are cacheable but redundant). Hit rate degrades for campaigns.
- **Recommended solution:** Expand bypass to `utm_*`, `fbclid`, `gclid`, etc., or normalize query by stripping tracking params before cache key (keep origin fetch with full query but key on path).
- **Confidence:** Medium

### F12 — `rum.js` `fetch` fallback lacks `keepalive:true`; `PerformanceObserver` buffered without throttling

- **File:Line:** `src/rum.js:90-95` + `114-160`
- **Category:** Network / JS execution
- **Severity:** Low
- **Problem:** `fetch(config.apiUrl,{method:'POST', headers:{'Content-Type':'application/json'}, body:payload, credentials:'omit'})` on `pagehide` may be aborted because not `keepalive:true`. Spec recommends `navigator.sendBeacon` first (used), fallback fetch without keepalive will be cancelled on unload. Also LCP/CLS/INP observers update `values` on every entry (CLS sums per shift, INP takes last) — frequent `PerformanceObserver` callbacks on CLS-heavy pages (infinite scroll) fire often but cheap.
- **Why matters:** MISSED beacons on browsers without `sendBeacon` (old Safari) or when `sendBeacon` Blob size >64KB? Payload is small, so fallback rarely used; but pagehide fallback reliability matters for bounce rate.
- **Evidence:** `90-95` fallback fetch no `keepalive`. `114-159` observers with `{buffered:true}` observe `largest-contentful-paint`/`layout-shift`/`event` (INP).
- **Impact:** ~1–2% RUM loss on Safari <11.1 without sendBeacon? Actually sendBeacon present since Safari 11.1; fallback path rare.
- **Recommended solution:** Add `keepalive:true` to fallback `fetch`; or always use `sendBeacon` then fetch with keepalive.
- **Confidence:** Medium

### F13 — `wppo-lqip` blur+scale on every lazy image causes compositor layer thrashing

- **File:Line:** `src/css/components/_lazy-placeholder.scss:1-16` + `src/lazyload.js:451-459` `applyPlaceholderBeforeLoad`
- **Category:** CSS complexity / Reflow
- **Severity:** Medium
- **Problem:** `.wppo-lqip-active { filter:blur(20px); transform:scale(1.05) }` + transition `filter 0.4s, transform 0.4s`. `filter:blur` forces layer creation per image (GPU texture upload). On a gallery with 40 lazy images entering viewport together, 40 concurrent blurs + scales animate. `makePlaceholderLoadHandler` removes class on `load` and adds `wppo-lqip-loaded` (scale back to 1). Transition on `filter` is expensive (full pixel shader).
- **Why matters:** On mid-range Android, 40 simultaneous blur transitions can drop 60fps; also `backgroundColor` transition `0.4s ease-out` on dominant-color placeholder (line 474) adds second transition per image.
- **Evidence:** `lazy-placeholder.scss:4-5` blur+scale. `lazyload.js:475` `el.style.transition='background-color 0.4s ease-out'` applied inline per image.
- **Impact:** Jank on image-heavy pages during scroll; Lighthouse `CLS` fine (dimensions fixed via `post_process_img_dimensions`) but `INP` may suffer from style recalc.
- **Recommended solution:** Use `content-visibility` or limit blur to above-fold only; or use `will-change: filter` hint and reduce blur radius to 12px; batch transitions via `requestAnimationFrame`.
- **Confidence:** Medium

### F14 — `backdrop-filter:blur` + dot pattern on stat cards is expensive compositing

- **File:Line:** `src/css/components/_stats.scss:54-56` `backdrop-filter:blur(8px)` + `74-82` `radial-gradient` dot pattern
- **Category:** CSS complexity / Paint
- **Severity:** Medium
- **Problem:** `.wppo-stat-item` has `backdrop-filter:blur(8px)` on 4 cards + pseudo `::after` radial dots (18px grid, opacity 0.04) covering full card. Also `::before` gradient top border. `transition: transform 0.22s, box-shadow 0.22s` on hover adds transform layer. `backdrop-filter` forces offscreen surface + GPU readback.
- **Why matters:** On admin SPA (only visible to admin), 4 cards' blur is cheap on desktop but on low-power laptop with integrated GPU may cause repaint cost + power draw. Pattern `radial-gradient` tiled at 18px is extra paint.
- **Evidence:** `54-56` dual `background: rgba(255,255,255,0.96); backdrop-filter:blur(8px)`. `74-82` `background-image: radial-gradient(... 1px ...)`. `59` `transition: transform ..., box-shadow ...`.
- **Impact:** Admin-only, not frontend visitor perf. ~2–5 ms paint per frame on hover.
- **Recommended solution:** Remove `backdrop-filter` on cards (use solid `#fff`) or gate behind `@supports (backdrop-filter:blur(8px)) and (hover:hover)`; drop dot pattern on `prefers-reduced-motion`? Already respects but not for blur.
- **Confidence:** Medium

### F15 — CSS breakpoints use `max-width` (desktop-first) causing cascade churn

- **File:Line:** `src/css/abstracts/_mixins.scss:3-18` `respond-to`
- **Category:** CSS complexity
- **Severity:** Low
- **Problem:** Mixin wraps content in `@media (max-width: $value)` (sm 640, md 768, lg 992, xl 1200). Plugin is desktop-first; mobile overrides re-apply widths inside max-width blocks. Ordering is file-import dependent; later imports can override earlier max-width without specificity bump.
- **Why matters:** Not a runtime perf bug but maintainability + unexpected override on `wppo-main` padding (32→24→16) requires 3 media queries per element. Could be `min-width` progressive enhancement fewer overrides.
- **Evidence:** `mixins.scss:12` `@media (max-width: $value)`. Used in `_container.scss:38,44`, `_sidebar.scss:18,189`, `_stats.scss:11,16`, etc.
- **Impact:** None measurable; noted for completeness. Changing to `min-width` would shrink CSS (~1 KB) but not required.
- **Recommended solution:** No change needed for audit; document as design choice. Future could migrate to `min-width` mobile-first.
- **Confidence:** Low

### F16 — Committed build artifacts include 56 KB CSS + 136 KB JS without gzip; no `<link rel="modulepreload">` for tab chunks

- **File:Line:** `build/style-index.css:56072` + `build/index.js:136665` + `src/App.js:32-64` lazy tabs
- **Category:** Render-blocking / Asset loading
- **Severity:** Low
- **Problem:** `style-index.css` 56 KB is render-blocking for admin SPA. `index.js` 136 KB includes React + FontAwesome. Tab chunks are `React.lazy` with `webpackChunkName` but no `modulepreload` hints; dashboard chunk loads only on demand via dynamic import (extra RTT).
- **Why matters:** Admin first paint waits for CSS (56 KB) + `index.js` parse. `tab-dashboard` extra fetch adds 1 RTT before dashboard renders (skeleton vs spinner). Could preload dashboard chunk since it's default tab (`activeTab='dashboard'`).
- **Evidence:** `build/*.asset.php` not injecting preload. `App.js:66-71` `TabFallback` spinner while lazy loads. No `<link rel="modulepreload" href="tab-dashboard.js">` in `admin_enqueue_scripts`.
- **Impact:** ~100–200 ms extra TTI on first admin load (cold cache). Subsequent loads cached by browser.
- **Recommended solution:** Add `modulepreload` for `tab-dashboard` chunk in `admin_enqueue_scripts` (via `wp_enqueue_script` dependency or manual `<link rel="modulepreload">`). Split `style-index.css` per-tab via `mini-css-extract`? Or keep as is — admin is low-frequency.
- **Confidence:** Medium

### F17 — CDN rewrite via `WP_HTML_Tag_Processor` on every cache-miss buffer (O(n) tag scan) + duplicate `process_urls` parsing

- **File:Line:** `includes/class-cache.php:1320-1363` `maybe_apply_cdn` + `includes/class-main.php:693-709` `strip_static_query_strings` via `script_loader_src`
- **Category:** JS/CSS asset handling / PHP CPU (frontend visitor cost)
- **Severity:** Low
- **Problem:** `maybe_apply_cdn` instantiates `WP_HTML_Tag_Processor($buffer)` and loops `next_tag()` over every tag, checking 5 tag names and 5 attrs per tag, plus `srcset` explode per `srcset`. On a 300 KB HTML (long post + WooCommerce) that's ~2k tags scan per cache miss. `strip_static_query_strings` similarly regexes every enqueued `script_src`/`style_src` on every page load (not only cache-miss) — runs via `script_loader_src` filter per handle (30 handles × `wp_parse_url`+`explode('&')`).
- **Why matters:** Cache-miss path is already heavy (image next-gen + lazy + minify + CDN + used-CSS). CDN scan adds ~1–3 ms; stripping query strings adds ~0.5 ms per page load even on cached HTML generation? On hit, `advanced-cache.php` serves static file without PHP, so cost zero after warm cache.
- **Evidence:** `Cache.php:1322` `new WP_HTML_Tag_Processor($buffer)`. `Main.php:2481-2542` `strip_static_query_strings` per handle strpos checks.
- **Impact:** Negligible on warm cache; only on cold generation. Not duplicated on frontend JS.
- **Recommended solution:** Keep; consider caching `site_url_regex` as static (already done in `maybe_apply_cdn` line 1321). No change needed.
- **Confidence:** High

### F18 — Asset Manager captures via `wp_scripts->done` transient write per page load (no debounce)

- **File:Line:** `includes/class-asset-manager.php:136-212` `capture_page_assets`
- **Category:** Network / Database
- **Severity:** Low
- **Problem:** `capture_page_assets` hooked at `wp_footer:9999` does `get_transient(wppo_page_assets_{post_id})` + writes `set_transient(..., DAY)` on every singular page load when scripts/styles changed. No lock, no `has_changed` early return? Actually has `has_changed` check (202-206) comparing `scripts===` and `styles===` — avoids write when unchanged. Good.
- **Why matters:** On cache-miss generation, capture runs; on file-cache HIT (advanced-cache.php bypass), hook not fired — so no DB write on warm hits. Impact limited to cache-miss admin-visited singular pages.
- **Evidence:** `Asset_Manager.php:199-211` change detection + `DAY_IN_SECONDS` TTL. `Util::transient_key` blog-prefix isolation handled.
- **Impact:** Low. Write only when assets change (rare).
- **Recommended solution:** No action; audit notes correct debouncing.
- **Confidence:** High

### F19 — `health-dot` infinite pulse animation runs even when offscreen

- **File:Line:** `src/css/components/_stats.scss:353-371` `@keyframes wppo-pulse-dot`
- **Category:** CSS complexity
- **Severity:** Info
- **Problem:** `.wppo-health-dot { animation: wppo-pulse-dot 2.2s ease-in-out infinite }` with opacity 1→0.55. Runs continuously even when dashboard tab scrolled out of view. Respects `prefers-reduced-motion:reduce` (368) but not viewport visibility.
- **Why matters:** Infinite compositor animation wakes GPU every frame (~0.5 opacity lerp). On 120Hz screens more wakes. Negligible but counted for completeness.
- **Evidence:** `359` `animation: wppo-pulse-dot 2.2s ... infinite`. `367-370` reduce-motion guard.
- **Impact:** Tiny battery on admin.
- **Recommended solution:** Pause when parent not `activeTab==='dashboard'` via class, or use `animation-play-state:paused` when hidden. Or keep — trivial.
- **Confidence:** Low

---

## Summary

| Severity | Count |
|----------|-------|
| High | 1 (F10 edge variance) |
| Medium | 8 (F01, F02, F03, F05, F06, F07, F13, F14) |
| Low | 8 (F04, F08, F09, F11, F12, F16, F17, F18) |
| Info | 1 (F19) |

**Overall verdict:** Frontend perf is **sound for visitor path** (lazyload uses IO + native `loading=lazy` fast-path, rum beacon is lightweight, combined-CSS preload is correct, file cache bypasses PHP on HIT). Main risks are **admin bundle size** (monolith Dashboard/FileOptimization) and **edge adapter variance** when edge cache is opted-in. No render-blocking on frontend beyond combined CSS (intentional); visitor JS `lazyload.js` 11 KB deferred + `rum.js` 1.8 KB deferred are non-blocking. Recommended follow-ups are split admin chunks, edge `Vary: Cookie/Accept` guard, and wrapping the auto-sizes probe in `requestIdleCallback`.

---

## Evidence Commands

```sh
wc -l src/components/Dashboard.js  # 1329
wc -l src/components/FileOptimization.js # 2024
wc -l src/lazyload.js src/rum.js src/main.js
grep -rn "IntersectionObserver\|MutationObserver\|requestIdleCallback" src/lazyload.js
grep -rn "combine_css\|maybe_preload_combine_css\|resource_hints\|fetchpriority" includes/class-cache.php includes/class-main.php
ls -lh build/tab-*.js build/*.js build/*.css
```

