# WPPO Competitive & User-Demand Research — 2026-09-08

Scope: external research for Performance Optimisation (WPPO). Fresh as of 2026-09-08. All facts sourced from live plugin changelogs, WP.org support forums, Reddit, and performance-engineering sources; URLs and dates in the appendix. WPPO's current feature set was supplied internally (see repo AGENTS.md) and was used to eliminate false gaps — only genuinely missing capabilities are listed as gaps.

---

## 1. Executive summary

1. **The market shifted from "features" to "trust + automation + AI surface area."** WP Rocket 3.23 (Jul 2026) shipped a full MCP server (cache purge tools, OAuth for Claude); FlyingPress 5.x replaced Action Scheduler with an in-house queue and added built-in image optimization; Perfmatters shipped a Code Snippets manager and Cloudflare Early Hints.
2. **Cloudflare is now a first-class cache tier, not a CDN checkbox.** FlyingPress 5.1 added full Cloudflare page caching with zone detection + API tokens; WP Rocket 3.20.2 made cache lifespan purge Cloudflare APO; NitroPack's stack is Cloudflare-native. WPPO purges CF/Bunny/Varnish but has no Cloudflare page-cache/APO mode.
3. **Object caching went mainstream in optimizer plugins** — FlyingPress 5.6 added Redis (standalone), LiteSpeed 7.8–7.9 hardened object-cache resilience (auto-disable on connection failure, Redis zstd). WPPO's standalone/sentinel/cluster/TLS Redis is still ahead; **protect that lead, but add the resilience patterns** (auto-disable, subsite fallback) competitors shipped.
4. **2025–2026 changelogs are dominated by breakage repair**: W3TC 2.10.x is ~30 security-hardening/fix entries after the 2.9.x output-buffer fiasco; LiteSpeed 7.9.1 replaced IP-based QUIC.cloud callbacks with signatures; Perfmatters 2.6.x fixed MU-mode plugin deactivation bugs. Reliability is now a differentiator users cite in reviews.
5. **Top user complaints are unchanged and still unsolved industry-wide**: JS delay/delay-everything breaking Elementor menus/popups, RUCSS breaking hover states and mobile menus, cache-vs-page-builder stale CSS, checkout nonce breakage, and 3rd-party breakage after minify.
6. **Dark patterns are an open flank**: WP Fastest Cache was called out (Aug 2026) for injecting App-Store ad banners into wp-admin; NitroPack for billing/cancellation friction and gating TTL control behind plan upgrades; WP Rocket for banner-heavy upsells. A "no ads in admin, no paid-only safety controls" stance is a marketable, verifiable position.
7. **"Remove query strings" and `Cache-Control: no-store` are now formally bad practice** (cache-busting consensus = fingerprinting; no-store kills bfcache — WP Fastest Cache support thread, web.dev). WPPO's query-string strip feature falls in the obsolete bucket (see §5) and should be deprecated/hidden.
8. **WordPress core moved under the plugins**: WP 6.9 (Dec 2025) shipped Abilities API + native fetchpriority/in_footer handling; WP 7.0 (May 2026) requires PHP 7.4, adds AI Client + script-modules dependencies; WP 7.1 (Aug 2026) extended Abilities API and broke at least one optimizer (WP Rocket 3.23.2.2 hotfix). WPPO's WP-7.1-aligned speculation rules + Abilities API registration are correctly timed.
9. **Kinsta still bans Cache Enabler (list updated Jun 3, 2026); WP Engine bans W3TC/WP Super Cache/Hyper Cache (list updated Jul 28, 2025)** — host-aware behavior (detect managed host → disable own page cache → fan out purge to host API) is now table stakes; FlyingPress expanded host purge integrations in 5.5.
10. **Biggest WPPO gaps by impact**: Cloudflare page-cache/APO mode, Early Hints (103), host-level purge fan-out, automatic Lazy Render (content-visibility), per-page asset unloading at scale, RUM-attributed third-party cost reporting, and safety automation (auto-rollback on RUM regression) — the last one is novel white space nobody ships.

---

## 2. Feature matrix — top 8 competitors vs WPPO

✅ = shipped · 🟡 = partial/paid-tier/server-gated · ❌ = absent

| Feature | WPPO | WP Rocket 3.23.3 | LiteSpeed 7.9.1 | FlyingPress 5.6.5 | Perfmatters 2.6.7 | W3TC 2.10.6 | WP-Optimize 4.6.1 | NitroPack 1.20.0 | Autoptimize 3.1.15 |
|---|---|---|---|---|---|---|---|---|---|
| Static page cache + drop-in | ✅ | ✅ | 🟡 (server) | ✅ | ❌ | ✅ | ✅ | 🟡 (cloud) | ❌ |
| Redis object cache | ✅ (sa/sentinel/cluster/TLS) | 🟡 (external only) | 🟡 (Memcached/Redis) | ✅ (5.6, standalone) | ❌ | ✅ | ❌ | ❌ | ❌ |
| Brotli pre-compression | ✅ (.br) | ❌ | ❌ | 🟡 (gzip only) | ❌ | ❌ | ❌ | 🟡 (edge) | 🟡 (filter, gzip) |
| bfcache enablement (logged-in) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Speculation rules control | ✅ (WP 7.1-aligned) | 🟡 (beacon) | 🟡 (prefetch) | ✅ (native, disables core) | ✅ (mode/eagerness) | 🟡 (preload reqs, Pro) | ❌ | 🟡 (prefetch/prerender) | ❌ |
| Minify/combine JS+CSS+HTML | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ (cloud) | ✅ |
| Delay JS (multi-strategy) | ✅ (interaction/idle/viewport+priority) | ✅ (interaction) | ✅ | ✅ (interaction/idle) | ✅ | 🟡 (Pro) | ❌ | ✅ (cloud) | ❌ |
| Critical CSS + Remove Unused CSS | ✅ local | ✅ (SaaS) | ✅ (QUIC.cloud) | ✅ | ✅ | 🟡 (Pro) | 🟡 (Pro) | ✅ (cloud) | ✅ (CCSS) |
| Google Fonts self-host + preload | ✅ | ✅ (3.18) | ✅ | ✅ | ✅ | ❌ | 🟡 (Pro, 4.5.0) | ✅ (CDN+subset) | 🟡 (combine/async) |
| WebP/AVIF conversion | ✅ (+responsive srcset) | 🟡 (via Imagify) | ✅ | ✅ (5.3, local) | ❌ | 🟡 (Pro API) | ✅ | ✅ (cloud) | 🟡 (ShortPixel) |
| Lazy load img/iframe/video/bg | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | 🟡 (Pro) | ✅ | ✅ |
| Automatic Lazy Render (content-visibility) | ❌ | ✅ (3.17 LRC) | ❌ | ✅ | ✅ (Lazy Elements) | ❌ | ❌ | ✅ | ❌ |
| LCP detect + autopreload | ✅ (+OD bridge) | ✅ (beacon) | ✅ (VPI, fetchpriority=high in 7.9) | ✅ | ✅ | 🟡 (Pro) | 🟡 (Pro, 4.6.0) | ✅ | 🟡 (manual per-page) |
| DB cleanup + scheduling | ✅ (9 types) | ❌ (basic) | ✅ | ✅ | ✅ | ✅ | ✅ (flagship) | ❌ | ❌ |
| CDN rewrite + edge purge | ✅ (CF/Bunny/Varnish) | 🟡 (RocketCDN paid/free tier) | ✅ (QUIC.cloud) | ✅ (CF integration 5.1) | ✅ | ✅ | 🟡 | ✅ (built-in) | 🟡 |
| Cloudflare page-cache/APO mode | ❌ (purge only) | 🟡 (APO purge on TTL, 3.20.2) | 🟡 | ✅ (5.1/5.2) | 🟡 (Early Hints) | 🟡 | ❌ | 🟡 | ❌ |
| Early Hints (103) | ❌ | ❌ | ❌ | 🟡 | ✅ (2.4.6+) | ❌ | ❌ | ❌ | ❌ |
| LiteSpeed server integration (Vary/ESI/crawler) | ✅ (Vary, ESI-Ent, crawler, purge tags, TTL) | ❌ | ✅ (native) | ❌ | ❌ | ❌ | ❌ | 🟡 (compat layer) | ❌ |
| Per-page asset unloading UI | 🟡 (metabox Asset Manager) | 🟡 (per-page exclusions, limited) | ❌ | 🟡 (AI Assets Scanner via partner) | ✅ (Script Manager) | 🟡 (manual minify groups) | ❌ | 🟡 (shortcodes/AJAX) | 🟡 (per-page rules in Pro) |
| RUM real-user vitals | ✅ (beacon+trends) | 🟡 (Rocket Insights/GTmetrix lab+field) | ❌ | ✅ (5.2, country/page filters) | ❌ | ❌ | ❌ | ✅ (telemetry script, 1.19.9) | ❌ |
| AI/MCP surface | ✅ (Abilities API reg.) | ✅ (full MCP server + OAuth, 3.23) | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| WP-CLI | ✅ (7 subcommands) | 🟡 | ✅ | ✅ | ✅ (2.5.0+) | ✅ | 🟡 (Pro) | ✅ | ❌ |
| Import/export settings | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | 🟡 (CCSS tab) |
| llms.txt generation | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Pricing (2026) | free | $59/$119/$299 yr | free (QUIC.cloud metered) | from $49–59/yr | $29.95/$59.95/$124.95 yr | free / $99/yr Pro | free / $49+ yr | free / $7+/mo | free |

Note: LiteSpeed page caching is server-gated (OpenLiteSpeed/LSWS/QUIC.cloud); its review base shows friction on Nginx ("Unusable for me… requires QUIC CDN", Sep 2, 2026) — WPPO's host-agnostic cache is a durable differentiator there.

---

## 3. Per-competitor 2025–2026 changelog highlights

**WP Rocket (current 3.23.3.3, Aug 27, 2026 — wp-rocket.me/changelog)**
- 3.23 (Jul 9, 2026): MCP server for AI clients; 3.23.1 MCP OAuth for Claude; 3.23.2.1 MCP cache-purge tools + cache-status tool; Site Health check for MCP OAuth discovery.
- 3.22 (Jun 11, 2026): RocketCDN **Free tier** (3 pages, 10 PoPs) — freemium CDN onramp.
- 3.21 (Mar 19, 2026): Rocket Insights (GTmetrix-powered monitoring) free for all users + recommendations engine.
- 3.20 (Oct 6, 2025): Rocket Insights add-on; 3.20.2: RUCSS fonts preload off by default, cache lifespan purges Cloudflare APO.
- 3.19 (Jun 5, 2025): automatic per-page font preload (desktop+mobile) and fully automated preconnect to external domains.
- 3.18 (Jan 7, 2025): Self-host Google Fonts; minify on by default; Delay JS one-click exclusion UI. Ongoing: PHP 8.4/8.5 deprecation cleanup (3.23.3.1), options auto-backup on update (3.23.1), rollback-version system.

**LiteSpeed Cache (current 7.9.1, Sep 1, 2026; 8.0 "OptiMax" announced — wp.org)**
- 7.9 (Aug 5, 2026): min PHP 7.4 / WP 6.0; Redis zstd compression via phpredis; VPI preload links get `fetchpriority=high`; JS-delay blob + JS-combine template-literal fixes; WP 7.0 HTML attribute parse compat; dropped legacy `img_optm` table; Gravity Forms ESI nonce detection.
- 7.9.1: security overhaul — all QUIC.cloud callbacks verified by signature instead of source-IP allowlists; ESI bound to signed query payload; static-cache folder access blocked.
- 7.8 (Mar 3, 2026): transients always use object cache; WebP/AVIF for Safari ≥16.4; network subsite config efficiency; 7.8.0.1: object cache auto-disables on failed connection, subsites fall back to DB.
- Reviews (Aug–Sep 2026) confirm positioning: powerful but "requires some technical knowledge to configure properly."

**FlyingPress (current 5.6.5, Aug 20, 2026 — flyingpress.com/changelog)**
- 5.0 (May 28, 2025): CloudOptimizer (cloud-assisted auto optimization), CPU-friendly DB-based preload, separate mobile cache, native Speculation Rules link preloading (disables core's), "Delay all JS" reliability push.
- 5.1–5.2 (Aug–Oct 2025): **Cloudflare integration for full-page caching** (zone detection, API token, cache rules); built-in RUM Core Web Vitals tracking (5.2).
- 5.3 (Jan 20, 2026): **built-in Image Optimization** (compress + WebP/AVIF, stop button, per-image stats); 5.3.4 (Mar 2026): htaccess/nginx fallback rules serving originals when WebP/AVIF unsupported; CWV tracking on by default.
- 5.5 (Jun 2, 2026): own lightweight background queue replacing Action Scheduler; expanded Cloudflare integration (country/page RUM filters); expanded **host purge integrations**; 5.6 (Jun 24, 2026): Redis object caching.

**Perfmatters (current 2.6.7, Jun 8, 2026 — perfmatters.io/docs/changelog)**
- 2.5.3 (Nov 19, 2025): **Code Snippets** (PHP/JS/CSS/HTML, flat-file storage, MU integration).
- 2.4.6 (Jun 17, 2025): **Cloudflare Early Hints** for preloads (+sizes/media, crossorigin, fetchpriority attributes); WP-CLI import-settings.
- 2.4.3 (Apr 15, 2025): speculative loading mode/eagerness controls; deprecated Instant Page on WP 6.8+.
- 2.6.0 (Mar 25, 2026): min PHP 8.1; PHP Scoper for vendor libs; separate used-CSS handling of CSS layers; 2.5.6 (Jan 2026): **separate used CSS files per WooCommerce product type**; 2.6.2 (Apr 2026): Elementor Atomic YouTube lazy-load support.
- Pattern to note: nearly every release adds "built-in CSS selector exclusions" for specific themes (Kadence, GenerateBlocks, Elementor slideshow…) — RUCSS safety is maintained via curated selector lists.

**W3 Total Cache (current 2.10.6, ~Sep 5, 2026 — wp.org)**
- 2.9.0 (2025): Pro AVIF conversion; 2.9.2–2.9.4: mfunc security patches, broken-access-control patch, output-buffer reverts.
- 2.10.0: massive security hardening release (authz, nonce/CSRF, XSS, SSRF, credential encryption); 2.10.1–2.10.6: at-rest credential rollback fixes, Redis/Memcached TLS-on-by-default (Bunny CDN too), path-traversal fixes, `w3tc_pgcache_rules_required` filter.
- Support-forum damage from 2.9.3 (JSON API breakage, Mar 31, 2026) and Redis auth errors (Apr 6, 2026) shows update-safety is the weak point users feel.

**WP-Optimize (current 4.6.1, Jul 29, 2026 — wp.org)**
- 4.4.0 (Dec 12, 2025): onboarding wizard. 4.5.0 (Feb 11, 2026): Premium "cache only selected URLs", **Capo.js-based asset ordering**, local Google Fonts (Premium); CSS/JS merging now **off by default**.
- 4.6.0 (Jul 6, 2026): Premium **automatic LCP preload**; 4.6.1: cache excluded when WooCommerce cart has content; WebP Firefox detection fix; crawler/preload-bot cache-growth guard.
- Security: path-traversal fix (4.5.3, Apr 2026), Heartbeat smush capability hardening (4.5.1).

**NitroPack (current 1.20.0, Aug 24, 2026 — wp.org)**
- 1.19.5 (May 2026): skip serving cache to AI bots; Edge-cache (EFPC) support; 1.19.9 (Jul 2026): autoscale + web-vitals telemetry script; 1.20.0: disconnect modal, refactor.
- 1.19.4 (Apr 2026): reduced WooCommerce product invalidations; vulnerability fix for Events Calendar & Gravity Forms.
- Steady cadence of Elementor (1.18.8–1.18.9) and Cloudflare plugin 4.14 compatibility; 304 Not Modified support to improve TTFB/LCP (1.18.9).

**SiteGround Speed Optimizer (current 7.8.2, Aug 25, 2026 — wp.org)**
- 7.7.x (Nov 2025–May 2026): purge improvements, query-string exclude support for Dynamic Cache, third-party compat, security releases.
- 7.8.0 (Jun 22, 2026) / 7.8.1 (Aug 12, 2026): CSS/JS combine+minify overhaul, cache flushing, file caching, lazy-load + security improvements. Still ships "Remove Query Strings" (see §5).

**Others (compressed)**
- **Autoptimize 3.1.15.1** (mid-Aug 2026): fetchpriority=high on Extra-tab preloads; two stored-XSS fixes; confirmed WP 6.9-compatible; FAQ explicitly downplays "remove query strings" and inlining all CSS.
- **WP Fastest Cache 1.5.1** (Aug 2026): Polylang domain-mapping allowlist (security), feed cache-poisoning protection, WP 7.0 compat (1.4.9); free+premium lifetime model.
- **Cache Enabler 1.8.16** (~Mar 2026): caching-logic flaw fix, HTML minifier corrupting JSON scripts, multisite activation fix — last updated ~6 months ago; **banned on Kinsta**.
- **Breeze 2.5.14** (Sep 2026): currency-cookie cache-file manipulation fix; WCML/CURCY/WOOCS/Aelia/Weglot compatibility (2.5.13); **removed local hosting of GA/Facebook Pixel** as risk without benefit (2.5.14).
- **Hummingbird** (WPMU DEV; repositioned title around Core Web Vitals + Critical CSS; ~70k installs; Pro from $7.5/mo) — steady minify/CCSS/lazy-load line, no notable 2026 innovation surfaced in this research.
- **Swift Performance 3.x / Hyper Cache**: Swift remains in "22 best" roundups (bloggerpilot) as cache-replacement; Hyper Cache's only 2026 news is negative (on WP Engine's disallowed list).
- **New entrants/watchlist 2026**: BerqWP + Berq Used CSS (May 2026, free RUCSS), FastPixel (cloud optimizer), BoostPro ("smart defaults" delay/defer), Elementor One (suite bundling), Asset CleanUp (now ranked top-5 by 2026 buyer guides alongside WP Rocket/Perfmatters/FlyingPress/NitroPack — pagespeedmatters.com, Jun 23, 2026).

---

## 4. Missing-in-WPPO features (ranked; cross-checked against the internal feature list)

Only genuinely absent capabilities are listed. Rank = impact / effort / novelty (H/M/L).

1. **Cloudflare full-page cache + APO integration mode** — impact H / effort M / novelty M.
   FlyingPress 5.1–5.5 ships zone auto-detection, API tokens, managed cache rules, static-asset handling; WP Rocket purges APO on cache lifespan (3.20.2). WPPO purges Cloudflare but has no page-cache-on-Cloudflare mode or APO-awareness. Expected behavior: detect APO/Super-Page-Cache-style setups, emit `Cache-Control`/`Edge-Cache-TTL` semantics, include APO in purge fan-out and pre-warm.
2. **Early Hints (103) for critical assets** — impact M / effort M / novelty M.
   Perfmatters 2.4.6+ sends 103 headers for preloads (with imagesrcset/crossorigin/fetchpriority), and 2.4.7 sends Early Hints for the used-CSS file. WPPO already knows its critical set (fonts, LCP image, used CSS) — an `early_hints` emitter before `wp_head` is a natural fit.
3. **Host-level purge fan-out (Kinsta, WP Engine, SiteGround, Cloudways, SpinupWP/RunCloud/GridPane/Rocket.net)** — impact H / effort M / novelty L.
   FlyingPress "expanded cache purging integrations for supported hosting providers" (5.5); earlier purged Kinsta/Rocket.net (4.1.0), RunCloud/WP Engine/GridPane (4.2.3), SpinupWP (4.4.0). WPPO's purge fan-out covers CDNs only. Expected behavior: host detection (WP Rocket's HostResolver pattern) + purge calls on `clear_cache`, with a no-op on unknown hosts.
4. **Automatic Lazy Render (content-visibility based offscreen render skipping)** — impact M / effort M / novelty M.
   WP Rocket Automatic Lazy Rendering (3.17, 2024) with `rocket_lrc_exclusions`; FlyingPress lazy render; Perfmatters Lazy Elements. WPPO lazy-loads media/backgrounds but doesn't defer *rendering* of below-fold DOM. Expected behavior: opt-in, auto-detected candidates, exclusions for sticky/animated elements, remove properties on intersection (FlyingPress 5.0.3 pattern).
5. **Per-URL/per-post asset unloading at scale** — impact H / effort H / novelty L.
   Perfmatters Script Manager (global + per-post, MU mode), Asset CleanUp's model. WPPO has a per-page metabox (preload images + Asset Manager) — the gap is scale ergonomics: bulk rules, per-post-type defaults, "disable plugin everywhere except…" logic. Rank behind items 1–3 because effort is high and WPPO's metabox is a start.
6. **Third-party domain auto-detection → preconnect/DNS-prefetch suggestions** — impact M / effort L / novelty M.
   WP Rocket 3.19 made preconnect fully automatic; WPPO's preconnect is manual. WPPO already captures frontend assets (`get_page_assets`) — deriving third-party origins and proposing (not auto-enabling) hints is low-novelty-but-expected.
7. **Built-in raster compression (JPEG/PNG) alongside WebP/AVIF** — impact M / effort M / novelty L.
   FlyingPress 5.3 compresses originals and converts; LiteSpeed/WP-Optimize/NitroPack do full pipelines. WPPO converts formats; lossy/lossless compression of originals with restore-original is the visible gap.
8. **RUM attribution filters (country, page, device) and third-party cost ledger** — impact M / effort M / novelty H.
   FlyingPress 5.5 added country/page filters to its Vitals tab; NitroPack added a telemetry script (1.19.9). WPPO has RUM+trends — segmentation and "which third-party cost me X ms of INP" reporting are unclaimed.
9. **Options auto-backup + one-click rollback point on update** — impact M / effort L / novelty M.
   WP Rocket 3.23.1 auto-backs up plugin options every update and maintains a rollback version. WPPO has import/export; automation is missing.
10. **Font subsetting** — impact L / effort M / novelty L. NitroPack does it; Google Fonts self-hosting covers most of the win. Optional.
11. **Image CDN mode (on-the-fly transform at edge)** — impact M / effort H / novelty L. NitroPack/Imagify/ShortPixel territory; WPPO's local conversion avoids a paid dependency — treat as optional strategy, not a gap to rush.
12. **Settings import from other cache plugins** — impact L / effort L / novelty L. LiteSpeed advertises it; nice onboarding touch.

---

## 5. Features now considered inappropriate / bad practice (2026 consensus) — with honest WPPO flags

1. **"Remove query strings from static resources"** — obsolete. Autoptimize FAQ: "the impact of these is almost non-existent" (FAQ, current); 2026 cache-busting consensus is content fingerprinting with immutable long TTLs (MDN Cache-Control, updated Aug 12, 2026; dchost cache-busting guide, Jan 2, 2026), and WP's `?ver=` *is* the cache-busting mechanism — stripping it without fingerprinted filenames risks stale assets (ovkit, Apr 11, 2026). SiteGround Speed Optimizer and several plugins still ship it; buyer guides no longer ask for it.
   **WPPO flag**: WPPO ships query-string strip (context list). Recommend: hide behind an "advanced/legacy" toggle with an explanatory warning, or deprecate. Do not auto-enable.
2. **`Cache-Control: no-store` on HTML to force freshness** — anti-pattern. web.dev bfcache article (updated Jul 2, 2026): no-store blocks bfcache; use `no-cache`/`max-age=0` for revalidation instead. Chrome's 2025 rollout partially rescues no-store pages (developer.chrome.com bfcache-ccns, updated Sep 9, 2025) but "best practice remains to minimize use of no-store." WP Fastest Cache was asked to remove it (Dec 20, 2024 support thread; author refused).
   **WPPO flag**: WPPO's bfcache-for-logged-in-users work is the *opposite* and correct. Verify no WPPO-generated header rule emits `no-store` on non-sensitive HTML (cart/checkout may still warrant it).
3. **Defer/delay *everything* with no curated exclusions** — recurring breakage class. Evidence: LiteSpeed JS-delay → Elementor popups need 2 clicks, menu closes instantly (support thread, Jul 25, 2025 → Apr 2026); sliders/nav-menu broken until user asked for "automatic exclusion support for Elementor's Nav Menu" like WP Rocket's (Nov 25, 2025); dev guides codify what must never be delayed (devmamun, Jan 28, 2026; srworks, Jan 26, 2026: "delay broke functionality on 3 of my 15 test sites… above-the-fold slider, cookie banners, nav scripts").
   **WPPO flag**: WPPO's 3-strategy delay with priority is good; the moat is the curated exclusion library (Perfmatters ships quick exclusions for dozens of plugins; Perfmatters 2.6.x shows the maintenance cost). Keep auto-exclusions for consent banners + builders.
4. **RUCSS selector removal breaking hover/active states** — the classic. Perfmatters 2.5.8 added a Kadence "active state class" exclusion; 2.6.0 removed a Bricks layer exclusion and told users to clear used CSS; the canonical WP Rocket RUCSS mobile-menu thread remains the template of the failure mode. Fine-grained selector safelists + fast "restore full CSS per-URL" are the mitigation; blanket "remove unused CSS" toggles without per-URL regenerate are what users blame.
5. **Inlining all CSS / aggressive HTML minification** — Autoptimize's own FAQ: inlining all CSS bloats HTML, re-sent per navigation, and pushes SEO/social meta tags below scraper cut-off; "probably not." Keep minify conservative; never minify inside `<script type="speculationrules">`, JSON-LD, or template elements (Cache Enabler 1.8.16 fixed "HTML minifier corrupting JSON scripts"; LiteSpeed 7.9 fixed JS-combine corrupting template literals).
6. **ESI as a default hammer** — LiteSpeed 7.9.1 shipped ESI security fixes (signed payload binding, hash validation, duplicate comment-form bug, nonce lifetime issues). ESI is powerful but fragile; use for nonce/dynamic holes (cart, comments, Gravity Forms) only. **WPPO flag**: WPPO's ESI is Enterprise-only and scoped — correct posture; resist expanding ESI surface without the signed-payload discipline LiteSpeed just had to retrofit.
7. **Database "optimize everything" without backup/pagination** — WP-Optimize disables table optimization on InnoDB in many cases and markets UpdraftPlus backup-first; Kinsta's bans cite DB-load risk from cleanup plugins. Batched, dry-run-able cleanup (WPPO's model) is right; auto-truncate options/tables is not.
8. **Local-hosting analytics/pixels "for speed"** — Breeze 2.5.14 explicitly **removed** GA/Facebook-Pixel local hosting: "hosting them locally does not improve performance and added unnecessary risk." Keep GA/GTM out of WPPO's local-font-style hosting scope.
9. **Ads/upsells in wp-admin; paid-only safety controls** — WP Fastest Cache review: "Integrates advertising banners from apps.apple.com into the admin backend" (Aug 10, 2026); NitroPack: cache-expiry control gated behind plan upgrade criticized in the WooCommerce checkout saga (Aug 12, 2026); NitroPack billing friction (May 29, 2026). WPPO is free and ad-free — codify it as policy (docs + marketing).
10. **Plugin self-bloat** — the market polices it: NitroPack removed Flowbite/Select2 (1.19.2/1.19.4); Perfmatters added PHP Scoper to silo vendor libs (2.6.0); W3TC 2.10 hardened everything after exploitation history; WP Rocket fixed its own Mixpanel calls "blocking critical paths" (3.23.2.1). WPPO should keep a public footprint budget (autoloaded options, front-end KB, admin asset weight) — the suggestion-engine/telemetry features must be opt-in and cheap.
11. **Aggressive prerender eagerness** — Speculation Rules guidance 2026: `moderate` default; never prerender auth/checkout/side-effect URLs (WPPoland Speculation Rules guide, Jan 22, 2026; core's own conservative defaults). **WPPO flag**: WPPO is WP-7.1-aligned; ensure AI adaptive override can't set `immediate/eager` on WooCommerce dynamic pages.

---

## 6. User demands (quoted, sourced, classified)

Classification: (a) WPPO satisfies today · (b) missing in WPPO · (c) inappropriate to build as requested.

1. "Like many users, I have set sites to WordPress auto update… the post-css of the individual pages giving back a 404, presumably because the auto update … does not trigger the W3-Total-Cache cache clear. This makes pages look very broken to end users." — *Elementor + Cache Plugin = broken pages on update*, WP.org (Mar 12, 2026). → **(b)**: purge builder-generated CSS (e.g. `/uploads/elementor/css/`) on `upgrader_process_complete` for builder plugins.
2. "On mobile it takes 2 clicks to open the mobile menu… When Javascript delay is deactivated it works. Excluding whole wp-content folder, plugins folder and elementor does not seem to work." — *Struggeling with LiteSpeed javascript delay*, WP.org (Jul 25, 2025, unresolved for months). → **(a/b)**: WPPO's interaction-strategy delay + exclusions help, but the *exclusion UX* (test-mode, one-click builder exclusions) is where to beat LiteSpeed.
3. "Could you add automatic exclusion support for Elementor's Nav Menu in future versions? For the JS delay feature of WP Rocket… it automatically excludes the Nav Menu, so the issue never occurs." — same plugin family, *js delay cause slider and nav-menu issue*, WP.org (Nov 25, 2025). → **(a)**: maintain the curated auto-exclusion library; this is the requested feature verbatim.
4. "With NitroPack enabled, our checkout would frequently fail to initialize… WooCommerce PayPal Payments would result in a 'Could not validate nonce' error… manually configuring a shorter cache expiration period requires upgrading to a higher NitroPack plan." — WP.org review (Aug 12, 2026). → **(a)** for exclusion mechanics; **(c)** for the pricing pattern — never gate TTL/safety controls.
5. "Large ads for language learning are being inserted into my admin backend… You have no business being in my admin area." — WP Fastest Cache review (Aug 10, 2026). → **(c)**: opposite of what to build; use as positioning.
6. "Worked great for years… then one day it will no longer recognize my redis auth… millions of errors a day… changing settings, reinstalling, nothing will stop the errors." — W3 Total Cache review (Apr 6, 2026). → **(b)**: WPPO object-cache status telemetry exists; add auto-disable + circuit-breaker on repeated auth/connection failure (LiteSpeed 7.8.0.1 pattern).
7. "After updating [2.9.3], the JSON API stopped working and I was unable to edit content for a while." — W3 Total Cache review (Mar 31, 2026). → **(b)**: staged/safe output-buffer handling; test suite for non-HTML responses (REST/AJAX) before buffer commit.
8. "First time, the checkout page would just sit there… I deactivated [Speed Optimizer] and the checkout page magically appeared." — SiteGround review (Jul 27, 2026). → **(a/b)**: WooCommerce guardrails (auto-exclude checkout, buffer skip on `wc-ajax`).
9. "I always thought that after installing and uninstalling a plugin, all the leftovers would disappear, but this one doesn't." — Breeze review (Dec 30, 2025). → **(a/b)**: clean uninstall incl. drop-ins (`advanced-cache.php`, object-cache.php), cron events, transients — verify WPPO uninstall covers both drop-ins on multisite.
10. "Not one but this happened twice in about 6 months across 2 sites" (title: "Injects Malware") — Autoptimize review (Mar 21, 2026). → **(c)**: not actionable as a feature; highlights why signature-verified external callbacks (LiteSpeed 7.9.1) and SRI-style hygiene matter for anything WPPO pulls remotely.
11. "This plugin [WP Fastest Cache] adds 'no-store' to Cache-Control headers in htaccess, which prevents browser back/forward cache (bfcache) from working… This causes a page refresh effect when users click the back button." — WP.org (Dec 20, 2024). → **(a)**: WPPO's bfcache work is exactly the ask; market it.
12. "Sadly delay JS is not working for me. Tried multiple settings." / "The Debloat… conflict for the gt translate plugin… removed the gt translate from the website." — Debloat support (Jan 5, 2026 / Jun 24, 2025). → **(a/b)**: delay reliability + translation-plugin purge safety (Polylang/Weglot/TranslatePress/GTranslate).
13. "I am running on litespeed cache, perfmatter, and cloudflare plugin. But cloudways suggest remove all these with just keeping their own breeze. Which one is best pls?" — r/Wordpress (2024, still emblematic of 2026 host-conflict threads; cf. FreshySites case study, Dec 16, 2025: object cache disabled to stop Elementor styles disappearing). → **(b)**: host-conflict detection: when a managed host's cache/Breeze is detected, WPPO should disable its own page cache and offer purge fan-out instead.
14. "I am running WP on Nginx web server (on Rocky 9). This plugin [LiteSpeed Cache] is unusable for me. it required QUICK CDN." — LiteSpeed review (Sep 2, 2026). → **(a)**: WPPO's host-agnostic cache + server-rules generator is the counter-position.
15. "One of the best cache plugin… but it requires some technical knowledge to configure properly." — LiteSpeed review (Aug 31, 2026). → **(b)**: safe defaults + "recommended profile" wizard (WP-Optimize shipped an onboarding wizard Dec 2025; W3TC has a Setup Guide Wizard).
16. "My site is on elementor — what is the best plugin combo for speed pls?" (recurrent) → **(a/b)**: publish tested Elementor/Divi/Bricks presets; competitor changelogs show Elementor-specific fixes every month (Perfmatters 2.6.2, NitroPack 1.18.8–1.19.2, WP Fastest Cache 1.4.x element-cache detection).
17. "We have noticed the post-css of the individual pages giving back a 404" (see #1) plus Aelia's WP Engine currency-caching saga ("the cache should always be used when the cookie is present… skipping when present can yield wrong results", Aelia, Jan 15, 2026) → **(b)**: cookie-based cache varies beyond LiteSpeed (generic segmented-cache UI).
18. "An AI search engines, seo, reddit. Anyone looking up this plugin… will see my content warning people about this company going forward." — W3TC review (Apr 6, 2026). → **(c)**: sentiment to monitor, not to build.
19. "Do not give them a credit card. No cancel option on website…" — NitroPack review (May 29, 2026). → **(c)**: policy stance.
20. "I started using the plugin according to its recommendations. Once I started analyzing with Lighthouse I realized the program was delaying load speeds. Piece by piece I removed functions…" — SiteGround review (Jun 9, 2026). → **(b)**: per-feature measured impact ("what did enabling X do to field data?") — WPPO's RUM + suggestions engine is uniquely positioned to answer this; competitors don't close the loop.

---

## 7. Novel white-space ideas (evidence-based; nobody ships these well in 2026)

1. **Auto-revert on RUM regression** — when field CLS/INP/LCP regress N% after an optimization change, notify + offer one-click revert of the last change (WPPO already has RUM trends + AI adaptive; WP Rocket only has manual rollback versions). Effort M–H, novelty H. This is the direct answer to demand #20.
2. **Dry-run / blast-radius preview for cache rules & purges** — "saving this post will purge: this URL + home + these archives"; and per-URL "show me the optimized HTML diff before enabling." No competitor offers a preview; breakage threads (#1, #7) are all *after* the fact. Effort M, novelty H.
3. **Builder-update watcher** — on `upgrader_process_complete` for Elementor/Divi/Bricks/WPBakery, purge builder asset caches and used/critical CSS, with a changelog-style notice (fixes demand #1 class). Effort L, novelty M (fastest high-certainty win).
4. **Bfcache health panel** — extend Site Health: detect `no-store` emitters, `unload` handlers, header rules that block bfcache; per-URL bfcache eligibility from RUM `pageshow` telemetry. Builds directly on WPPO's bfcache leadership; Performance Lab only has a basic test. Effort M, novelty H.
5. **Third-party cost ledger** — attribute INP/TBT/CLS in RUM to specific third-party origins; recommend delay/exclusion per script with measured delta after enabling. FlyingPress delays "third-party scripts" generically; nobody closes the measurement loop. Effort H, novelty H.
6. **Host-aware mode** — detect Kinsta/WP Engine/SiteGround/Cloudways/SpinupWP; disable WPPO page cache where the host caches, wire purge fan-out + admin-bar integration; show a "who caches what" map (answers demand #13; Kinsta list updated Jun 3, 2026, WPE list Jul 28, 2025). Effort M, novelty M.
7. **Speculation-rules guardrails with tests** — auto-exclude cart/checkout/account/logged-in and Data-Saver; a "speculation simulator" listing which links would prefetch/prerender per URL. Perfmatters added cart/checkout disabling manually (2.4.6); making it automatic + visible is the step further. Effort L–M, novelty M.
8. **Generic cookie-varies cache segments UI** (currency/geo/language cookies) with allowlist validation — echoes Aelia/WP Engine segmentation plugin (Jan 2026) and Breeze 2.5.13's cookie hardening; pairs with WPPO's LiteSpeed Vary work generalized to the static cache. Effort M, novelty M.
9. **"Explain this setting" via Abilities API** — read-only diagnostic abilities (why is my cache hit rate low; which rule excluded this asset) consumable by Claude/ChatGPT/MCP clients. WP Rocket's MCP only does purge/status — diagnosis is open. Effort M, novelty H (WPPO already registers Abilities).
10. **Optimized-asset 404 fallbacks + integrity watchdog** — serve last-known-good used/combined CSS/JS if a generated file goes missing (Autoptimize's 404 fallback is the only analog) + periodic verification that cached HTML references resolve. Effort M, novelty M.
11. **Perceived-speed profile** — a one-toggle "perceived fast" preset (font-display swap+fallback metrics, instant paint priorities, bfcache, moderate speculation) targeted at INP/feel rather than lab scores. Effort L, novelty M.
12. **WP-CLI "verify" command** — `wp wppo verify` re-runs telemetry checks post-change and prints a pass/fail table (drop-in present, headers correct, RUM flowing, Redis auth OK). Extends the existing 7 subcommands; cheap trust-builder after the W3TC/NitroPack reliability saga. Effort L, novelty M.

---

## 8. Compatibility pain-point playbook (2025–2026)

**Elementor / Divi / Bricks / WPBakery**
- Failure modes seen in 2025–26: stale `post-css` after plugin auto-update (W3TC thread, Mar 2026); element-cache TTL interplay (WP Fastest Cache 1.4.0/1.4.5/1.4.6 detection logic); lazy-load inside Elementor text/HTML elements (Perfmatters 2.6.3); Atomic YouTube widgets (2.6.2); Divi white-screen on used-CSS clear (WP Rocket 3.18.1.1); Bricks layer CSS churn (Perfmatters 2.6.0).
- Best-in-class approach: purge builder CSS directories on builder updates; detect Elementor element cache & CSS print method and warn (WPFC pattern); curated delay/RUCSS exclusions per builder; never minify inside builder-injected `<style data-elementor-*>` blocks with dynamic hashes.
- Verify in WPPO: builder-update purge hook, `/uploads/elementor/css/` handling, element-cache warnings, per-builder exclusion presets.

**WooCommerce**
- Failure modes: checkout nonce/AJAX breakage under page cache (NitroPack review, Aug 2026; direct-to-checkout `?add-to-cart=` URLs compound it); currency cookies corrupting cache files (Breeze 2.5.13); cart-fragment interplay; stock-update purge storms (NitroPack 1.19.4 "reduced WooCommerce product invalidations"; Breeze 2.5.12).
- Best-in-class: auto-exclude cart/checkout/account (all majors do); treat `wc-ajax` as never-cached and never buffer-minified; ESI or AJAX for fragments (LiteSpeed ESI / W3TC fragment cache); cookie-varies for currency; granular product-invalidations; separate used-CSS per product type (Perfmatters 2.5.6).
- Verify in WPPO: `wc-ajax` skip path in the output buffer, cart-content cache guard (WP-Optimize 4.6.1 added one), currency-cookie varies, purge blast-radius on stock updates.

**Multilingual (WPML / Polylang / Weglot / TranslatePress / GTranslate)**
- Failure modes: translated URLs not cached (WP Rocket 3.19.2 TranslatePress fix; FlyingPress 4.16.2 TranslatePress cache fix), WPML refactors (FlyingPress 4.15.8), Polylang domain-mapping security (WPFC 1.5.1 allowlist), Breeze WCML/CURCY/Weglot compat (2.5.13), GTranslate conflicts (Debloat thread).
- Best-in-class: per-language cache roots, purge all language variants of a post, domain-mapping allowlists, per-language used-CSS.
- Verify in WPPO: language-variant purge on post save, per-language preload via sitemap, domain-mapped multisite safety.

**Multisite**
- WP-Optimize gates multisite ops behind Premium; LiteSpeed improved network subsite config loading (7.8); Breeze handles settings network-wide. WPPO is multisite-safe by design (transient-key isolation, domain-based cache dirs, blog-prefixed Redis). Verify: network admin UI surface, per-site drop-in and per-site `object-cache.php` story, cron scheduling per site (WP-Optimize 4.6.0 unschedules crons per site on deactivation).

**Popular hosts**
- Kinsta: caching plugins discouraged/banned (Cache Enabler on list, Jun 3, 2026); WP Rocket went from banned to partner — the path is cooperation: detect Kinsta MU, disable WPPO page cache, purge via host. Verify: Kinsta MU detection, no `advanced-cache.php` install attempt.
- WP Engine: W3TC/WP Super Cache/Hyper Cache/Quick Cache disallowed (Jul 28, 2025); Edge Full Page Cache ignores custom cookie exclusions (Aug 20, 2026 docs) → don't rely on cookie varies there; cache-exclusions API exists.
- SiteGround: Speed Optimizer + Dynamic Cache; WPPO must not fight its file-based cache (query-string exclude support added 7.7.5). Cloudways: Breeze + Varnish — purge Varnish, expect "remove other plugins" advice (demand #13). LiteSpeed hosts: WPPO's coexistence modes are the best-in-class blueprint — keep leading (TTL filters, purge tags, Vary).
- Generic: host purge fan-out (see gap #3) covers Rocket.net/RunCloud/GridPane/SpinupWP patterns proven by FlyingPress.

**Cloudflare APO / Super Page Cache vs plugin page cache**
- Failure mode: double-caching and stale HTML; FlyingPress lists Super Page Cache as incompatible (5.2.4); WP Rocket syncs cache lifespan to APO purge (3.20.2). Best-in-class: detect APO/EFPC, degrade WPPO static cache to bypass, route purge + pre-warm through Cloudflare; document Edge-cache TTL semantics.

**Object-cache drop-in conflicts**
- Only one `object-cache.php` can exist: FlyingPress now bundles Redis (5.6.x, simplified drop-in), Redis Object Cache plugin, Batcache, W3TC object cache all collide. LiteSpeed 7.8.0.1: auto-disable on connection failure + subsite fallback; 7.9: zstd, host validation, never-expire transients respected (TTL dropped).
- Best-in-class for WPPO: pre-install detection of existing drop-ins with clear handoff UI; circuit-breaker on repeated auth failure (answers demand #6); respect `wp_using_ext_object_cache` and infinite-TTL transients; verify multisite blog-prefix behavior.

**PHP 8.4 / 8.5 and WP 6.9 / 7.0 / 7.1**
- PHP 8.4/8.5: WP Rocket fixed Mobile Detect/Action Scheduler deprecations on 8.4–8.5 (3.23.3.1); WP-Optimize fixed `$http_response_header` on 8.5 (4.5.2); Perfmatters raised floor to 8.1 (2.6.0). Action: run CI on 8.4/8.5; watch dynamic-property and implicit-nullable deprecations.
- WP 6.9 (Dec 2, 2025): Abilities API (6.9), Command Palette, script-modules maturity — WPPO's Abilities registration is aligned; keep abilities read-only-safe (7.1 added `wp_ability_invoked` observability and `readonly/destructive/idempotent` annotations).
- WP 7.0 (May 2026): PHP 7.4 minimum; scripts can depend on ESM modules — minify/combine must not break `type="module"` graphs (WPFC excluded module tags from combining back in 1.3.8; keep that); admin UI overhaul broke plugin styling (LiteSpeed "GUI styles lost on WP 7.0 when heartbeat is disabled"; WP-Optimize matched WP 7.0 buttons).
- WP 7.1 (Aug 5, 2026 field guide): Abilities API expansion; WP Rocket shipped 3.23.2.2 hotfix for a WP 7.1 fatal; LiteSpeed fixed WP 7.0 HTML attribute parsing for Font Display (7.9). Action: regression-test WPPO's output buffer against 7.1 parser changes; re-verify speculation-rules output under 7.1; template-enhancement buffer interplay — ensure WPPO's buffer starts late enough to coexist and that cached HTML doesn't double-apply core's enhancements.

---

## 9. Sources appendix

**Changelogs / product pages**
- WP Rocket changelog — https://wp-rocket.me/changelog/ (accessed 2026-09-08; entries Jan 2025–Aug 27, 2026)
- Perfmatters changelog — https://perfmatters.io/docs/changelog/ (accessed 2026-09-08; entries 2023–Jun 8, 2026)
- FlyingPress changelog — https://flyingpress.com/changelog (accessed 2026-09-08; entries 2020–Aug 20, 2026)
- LiteSpeed Cache wp.org page/changelog — https://wordpress.org/plugins/litespeed-cache/#developers (accessed 2026-09-08; 7.8–7.9.1, 8.0 notice)
- W3 Total Cache wp.org — https://wordpress.org/plugins/w3-total-cache/#developers (accessed 2026-09-08; 2.9.0–2.10.6)
- WP-Optimize wp.org — https://wordpress.org/plugins/wp-optimize/#developers (accessed 2026-09-08; 4.3.1–4.6.1)
- NitroPack wp.org — https://wordpress.org/plugins/nitropack/#developers (accessed 2026-09-08; 1.17.6–1.20.0)
- Autoptimize wp.org — https://wordpress.org/plugins/autoptimize/#developers (accessed 2026-09-08; 3.1.10–3.1.15.1 + FAQ)
- Cache Enabler wp.org — https://wordpress.org/plugins/cache-enabler/#developers (accessed 2026-09-08; 1.8.12–1.8.16)
- Breeze wp.org — https://wordpress.org/plugins/breeze/#developers (accessed 2026-09-08; 2.5.10–2.5.14)
- WP Fastest Cache wp.org — https://wordpress.org/plugins/wp-fastest-cache/#developers (accessed 2026-09-08; 1.3.0–1.5.1)
- SiteGround Speed Optimizer wp.org — https://wordpress.org/plugins/sg-cachepress/#developers (accessed 2026-09-08; 7.7.x–7.8.2)
- Hummingbird (listing/title evidence) — https://wordpress.org/plugins/hummingbird-performance/

**Market / comparison (2026)**
- Best WordPress Speed Plugins 2026 (Tested) — https://www.pagespeedmatters.com/resources/guides/best-wordpress-speed-plugins (Jun 23, 2026)
- WP Rocket vs Perfmatters: Do You Need Both in 2026 — https://gauravtiwari.org/wp-rocket-vs-perfmatters/ (pub Aug 16, 2025; upd Aug 20, 2026)
- FlyingPress vs WP Rocket (2026) — https://www.pagespeedmatters.com/resources/guides/flyingpress-vs-wp-rocket (Jul 13, 2026); https://schoolswp.com/en/flyingpress-wp-rocket-comparison/ (May 26, 2026); https://mcstarters.com/blog/flyingpress-vs-wp-rocket/ (Mar 30, 2026)
- 15 Best WordPress Speed Optimization Plugins in 2026 — https://theoceanmarketing.com/blog/wordpress-speed-optimization-plugins-2026/ (May 2, 2026)
- Best Cache Plugin for WooCommerce Compared — https://nitropack.io/blog/best-cache-plugin-for-woocommerce (Jun 24, 2026)
- 22 Best WordPress Performance Plugins — https://bloggerpilot.com/en/wordpress-performance-plugins (Swift Performance; long-running, 2026 current)

**User demands / support threads / reviews (WP.org & Reddit)**
- Elementor + Cache Plugin = broken pages on update — https://wordpress.org/support/topic/elementor-cache-plugin-broken-pages-on-update/ (Mar 12, 2026)
- Struggeling with LiteSpeed javascript delay — https://wordpress.org/support/topic/struggeling-with-litespeed-javascript-delay/ (Jul 25, 2025 → Apr 2026)
- js delay cause slider and nav-menu issue — https://wordpress.org/support/topic/js-delay-cause-slider-and-nav-menu-issue/ (Nov 25, 2025)
- NitroPack WooCommerce checkout saga (review) — https://wordpress.org/support/topic/paid-product-broke-woocommerce-checkout-and-support-sent-us-in-circles/ (Aug 12, 2026)
- WP Fastest Cache admin ad banners (review) — https://wordpress.org/support/topic/integrates-advertising-banners-from-apps-apple-com-into-the-admin-backend/ (Aug 10, 2026)
- W3TC Redis auth errors (review) — https://wordpress.org/support/topic/errors-that-will-not-stop/ (Apr 6, 2026)
- W3TC 2.9.3 JSON breakage (review) — https://wordpress.org/support/topic/reliability-concerns-after-plugin-update/ (Mar 31, 2026)
- SiteGround broke checkout (review) — https://wordpress.org/support/topic/broke-checkout-page-twice/ (Jul 27, 2026); Amelia conflict (Jun 19, 2026); "delaying load speeds" (Jun 9, 2026)
- Breeze leftovers (review) — https://wordpress.org/support/topic/cancerous-plugin%ef%bc%81/ (Dec 30, 2025)
- Autoptimize "Injects Malware" (review) — https://wordpress.org/support/topic/injects-malware/ (Mar 21, 2026)
- WP Fastest Cache no-store/bfcache — https://wordpress.org/support/topic/cache-control-no-store/ (Dec 20, 2024)
- Debloat delay-JS + GTranslate conflicts — https://wordpress.org/plugins/debloat/ support (Jan 5, 2026; Jun 24, 2025)
- LiteSpeed on Nginx review — https://wordpress.org/support/topic/unusable-for-me-2/ (Sep 2, 2026); "requires technical knowledge" — https://wordpress.org/support/topic/one-of-the-best-cache-plugin-4/ (Aug 31, 2026)
- NitroPack billing (review) — https://wordpress.org/support/topic/worse-than-useless-refused-to-top-billing-my-credit-card/ (May 29, 2026)
- r/Wordpress host-vs-plugin cache conflict — https://www.reddit.com/r/Wordpress/comments/1bt0n3e/best_cache_combo_for_elementor_site/
- Elementor caching conflict case study — https://freshysites.com/resources/elementor-caching-conflict-resolution/ (Dec 16, 2025)
- WP Rocket RUCSS mobile menu (canonical) — https://wordpress.org/support/topic/wp-rocket-remove-unused-css-option-is-causing-conflict-in-mobile-menu/

**Best-practice / engineering**
- web.dev Back/forward cache — https://web.dev/articles/bfcache (updated Jul 2, 2026)
- Chrome: Enabling bfcache for Cache-Control: no-store — https://developer.chrome.com/docs/web-platform/bfcache-ccns (updated Sep 9, 2025; 100% rollout Mar–Apr 2025)
- MDN Cache-Control (cache-busting pattern) — https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Cache-Control (Aug 12, 2026)
- Instant Back/Forward plugin + core trac #63636 (no-store/bfcache in WP) — https://wordpress.org/plugins/nocache-bfcache/
- Cache-busting strategies 2026 — https://www.dchost.com/blog/en/cache-busting-strategies-with-cdns-and-browser-caching (Jan 2, 2026); https://martinuke0.github.io/posts/2026-03-31-mastering-cache-busting-strategies-to-break-the-cache-effectively/ (Mar 31, 2026)
- Defer vs Delay JS in WordPress 2026 — https://devmamun.com/defer-vs-delay-javascript-wordpress/ (Jan/Feb 2026); https://srworks.co/blog/javascript-delay-vs-defer-wordpress/ (Jan 26, 2026)
- Speculation Rules API for WordPress/WooCommerce — https://wppoland.com/en/speculation-rules-api-wordpress-woocommerce-performance-2026-en (Jan 22, 2026)

**WordPress core (2026)**
- Abilities API improvements in WordPress 7.1 — https://make.wordpress.org/core/2026/07/31/abilities-api-improvements-in-wordpress-7-1/ (Jul 31, 2026)
- WordPress 7.1 Field Guide — https://make.wordpress.org/core/2026/08/05/wordpress-7-1-field-guide/ (Aug 5, 2026)
- Client-Side Abilities API in WordPress 7.0 — https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0
- WordPress 7.0 vs 6.9 guide (dates/PHP floors) — https://spexoaddons.com/blog/wordpress-7-0-vs-6-9-what-changed/ (Jul 23, 2026); https://simplior.com/wordpress-7-0/ (Jun 26, 2026)
- WordPress 6.9 release guide — https://www.dreamhost.com/blog/whats-coming-in-wordpress-6-9/ (Aug 11, 2026)

**Hosts / compatibility**
- Kinsta banned & incompatible plugins — https://kinsta.com/docs/wordpress-hosting/wordpress-plugins-themes/wordpress-banned-incompatible-plugins/ (Jun 3, 2026)
- Kinsta caching docs — https://kinsta.com/docs/wordpress-hosting/caching/ (May 28, 2026) and server caching (Jun 3, 2026)
- WP Engine disallowed plugins — https://wpengine.com/support/disallowed-plugins/ (Jul 28, 2025)
- WP Engine server/browser caching + Edge Full Page Cache — https://wpengine.com/support/cache/ (Aug 20, 2026)
- Aelia × WP Engine currency caching & segmentation plugin — https://aelia.freshdesk.com/support/solutions/articles/3000107540 (Jan 15, 2026)

---

*Prepared by @librarian (external research lane), 2026-09-08. Version/price claims reflect vendor pages on the access date; verify before quoting in release notes.*
