# Competitive Deep Audit 2026 — Beyond LiteSpeed

**Date:** 2026-08-27 (verification pass 2026-08-27)  
**Baseline:** Performance Optimisation 1.9.0 — companion docs `litespeed-research.md` + `litespeed-integration-plan.md` + `COMPETITIVE_GAP_ANALYSIS.md` (2026-08-11)  
**Method:** 6 deep websearches (WP Rocket / LiteSpeed / FlyingPress / Perfmatters / Autoptimize / NitroPack / WP-Optimize / Hummingbird), Performance Lab 2026 experimental plugins, plus local code audit (`class-main.php`, `class-cache.php`, `src/components/*`)

> Purpose: extend the LiteSpeed research to the full competitor landscape, reconcile stale matrices, and identify 10 novel “no-one-has-it-yet” features.

---

## 1. What Changed Since COMPETITIVE_GAP_ANALYSIS.md (2026-08-11)

| Area | Gap doc 2026-08-11 | Reality 2026-08-27 | Action |
|---|---|---|---|
| **RUM** | ❌ We | ✅ We have RUM (`class-rum.php`, `web-vitals.js` beacon → `wppo_web_vitals_rum`, `rum_collect` token+IP, `rum_data`) since Tier-1 #1 | Flip matrix row to ✅, sync readme “Performance Monitor” → “RUM + telemetry” |
| **Cloudflare/Varnish purge** | ❌ We | ✅ `CDN_Purger::purge_all` on `wppo_after_cache_clear` (`Bearer WPPO_CLOUDFLARE_API_TOKEN`, `PURGE` to Varnish) since Tier-1 #5 | Flip row to ✅, note “Purge All clears edge, single-page skips edge” |
| **Background lazy (CSS background images)** | ❌ | ✅ `lazyLoadBackgroundImages` + `add_delay_load_backgrounds()` in `Cache::process_buffer_only` | Flip row to ✅ |
| **Brotli** | ❌ | ❌ (still gzip-only `.gz`, no `.br`) | Keep ❌, add “Phase 4 planned — LS-403” footnote |
| **FlyingPress object cache** | ✅ We vs ✅ FlyingPress (Redis) | ✅ correct — FlyingPress added Redis in v5.6 (early 2026, previously ❌) | Add footnote “since v5.6” |
| **NitroPack INP feature** | Not listed | **NEW 2026-01-19:** `Optimize Interactive Elements` — immediate visual feedback on click/tap (INP proxy) | Add watchlist row |
| **WP Rocket cloud CSS** | Implied local | Both WP Rocket + FlyingPress now **cloud-based CSS** (WP Rocket SaaS, FlyingPress browser) — article “WP Rocket minifies locally” outdated | Update copy |
| **Test counts** | “276 PHP / 315 JS green” | Will drift — make “≥276/≥315 @ 2026-08-11” or dymamic badge | Update |

**Verdict on gap doc:** Honest + conservative; no overclaim except TTFB universal claim (680→45ms) needs host-dependent disclaimer (“LiteSpeed hits ~90ms, Nginx/Apache PHP ~170-220ms warm”).

---

## 2. Reconciled Feature Matrix (2026-08-27)

Legend: ✅ native · 🟡 partial · ❌ missing — highlighted deltas vs gap doc.

| Feature | We (1.9.0) | WP Rocket 3.20 | FlyingPress 5.6 | LSCache 7.9 | Perfmatters | NitroPack | Autoptimize | W3TC | WPO | Hummingbird |
|---|---|---|---|---|---|---|---|---|---|---|
| Page cache + gzip/304 | ✅ file* | ✅ | ✅ | ✅ server | ❌ | ✅ cloud | 🟡 | ✅ | ✅ | ✅ |
| Sitemap preload / crawler | ✅ 5h/500-URL | ✅ | ✅ | ✅ +concurrency | ❌ | ✅ | 🟡 | ✅ | ✅ | ✅ |
| Logged-in / role cache | ✅ hash | 🟡 | ✅ | ✅ ESI† | ❌ | ✅ | ❌ | ✅ | ✅ | 🟡 |
| Object cache (Redis) | ✅ sentinel/cluster | ❌ | ✅ § | ✅/Memc/LSMCD | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |
| REST / Feed caching | ❌ / ❌ | ❌ / 🟡 | ❌ / ❌ | ✅ / 🟡 | ❌ | ✅ / 🟡 | ❌ | ✅ / ✅ | ✅ / 🟡 | ❌ / ✅ |
| Minify JS/CSS/HTML | ✅ | ✅ | ✅ | ✅ | 🟡 | ✅ | ✅ | ✅ | ✅ | ✅ |
| Defer + delay JS | ✅ 3-strategy | ✅ 2 | ✅ 3 | ✅ | ✅ | ✅ | 🟡 | ✅ | ✅ | ✅ |
| Critical/Used CSS | ✅ heuristic free | ✅ cloud SaaS | ✅ browser | ✅ QUIC credits | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Per-page asset manager | ✅ metabox | 🟡 | 🟡 | 🟡 | ✅ | 🟡 | 🟡 | ✅ | ❌ | 🟡 |
| Lazy images/iframes/videos | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | 🟡 | ✅ | ✅ | ✅ |
| Lazy CSS background / elements | ✅ | 🟡 | 🟡 | 🟡 | ✅ | ✅ | ❌ | ❌ | ❌ | 🟡 |
| LCP preload / fetchpriority | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| CLS width/height | ✅ | ✅ | ✅ | 🟡 | ✅ | ✅ | 🟡 | 🟡 | ❌ | 🟡 |
| WebP/AVIF | ✅ local | 🟡 Imagify | ✅ | ✅ QUIC | ❌ | ✅ | 🟡 | ✅ | ✅ | ✅ |
| Google Fonts self-host | ✅ woff2 | ✅ | ✅ | 🟡 | ✅ | ✅ | 🟡 | ❌ | ✅ | 🟡 |
| Speculation Rules (6.8) | ✅ full | ✅ | ✅ | 🟡 | ✅ | ✅ | ❌ | ❌ | ❌ | 🟡 |
| DB cleanup + scheduled | ✅ 9 types | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | 🟡 | ✅ | ✅ |
| CDN rewrite | ✅ origin | ✅ | ✅ | ✅ mapping | ❌ | ✅ | 🟡 | 🟡 | ❌ | ✅ |
| Cloudflare/Varnish purge | ✅Δ | ✅ | 🟡 | ✅ | ❌ | ✅ | ❌ | ✅ | 🟡 | ✅ |
| Brotli `.br` | ❌ | 🟡 | 🟡 | 🟡 server | ❌ | ✅ | ✅ | 🟡 | ✅ | ✅ |
| PageSpeed / audit | ✅ local+PSI | ✅ | ❌ | 🟡 | ❌ | 🟡 | ❌ | ❌ | ❌ | ✅ |
| RUM / CrUX | ✅ RUM | 🟡 synthetic | ✅ RUM § | ❌ | ❌ | 🟡 | ❌ | ❌ | ❌ | ✅ CrUX |
| Bloat toggles | ✅ 15 cur. | 🟡 | ✅ | ✅ | ✅ 50+ | ❌ | ✅ | ❌ | ❌ | ✅ |
| Heartbeat | ✅ | 🟡 | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Server rules | ✅* | ✅ | ❌ | ✅ | ❌ | ❌ | ✅ | ✅ | 🟡 | 🟡 |
| Woo | ✅ | ✅ | 🟡 | ✅ | ✅ | ✅ | ✅ | 🟡 | ✅ | ✅ |
| ESI / QUIC.cloud | ❌ | ❌ | ❌ | ✅† | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| `.mo→php` / LLMs.txt | ❌/❌ | ❌/❌ | ❌/❌ | ❌/❌ | ❌/❌ | ❌/❌ | ❌/❌ | ❌/❌ | ❌/❌ | ❌/❌ |
| INP “Optimize Interactive” | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |

\* Page cache on LS via `advanced-cache.php` PHP path — LS-native path planned (Phase 3). Server rules LS bug fixed Phase 0.  
† ESI Enterprise/ADC only — OLS no.  
§ FlyingPress Redis since v5.6 (2026 Q1), RUM since v5.2 (2025-09) — new vs 2025 reviews.  
Δ Purge All clears edge; single-page skips edge (documented in `litespeed-research.md:4.4`).

**Key correction:** Our matrix in `litespeed-research.md:7` already shows RUM ✅ and Cloudflare ✅ — correct after Tier-1. Gap doc snapshot is stale; this table is canonical as of 2026-08-27.

---

## 3. Where We Over/Under-State (2026 Reality)

### Overstated — add disclaimer
- **Universal TTFB/performance claim** (readme `PERFORMANCE.md` “680→45ms, 52→98”): PHP file cache cannot hit 45ms on generic Nginx/Apache; LS server cache hits ~90ms, PHP file ~170-350ms per WitsCode 2026. Fix: “Lab warm-cache, Astra + controlled env; host-dependent table (LS vs Nginx/Apache)”.
- **“Works everywhere”** without LS rules: before Phase 0 fix, LS hosts see “Unrecognised server” warning. Fix landed Phase 0.

### Understated — market as moat
- **Telemetry + Suggestion Engine + RUM + Autoload audit** = “Performance Intelligence” — **no competitor has local telemetry** (WP Rocket SaaS, others none). Umbrella brand, don't fragment.
- **Heuristic CSS (Used/CCSS) local, credit-free, per-template, SSRF-gated depth 3** — QUIC.cloud CCSS/UCS costs credits + cloud latency; headline “No credits, no cloud”.
- **Per-page Asset Manager** (`_wppo_disabled_scripts/styles`, `_wppo_preload_image_url`) = Perfmatters-level without extra plugin.
- **Multisite domain-isolated cache** (`wppo/{domain}/{path}/index.html.gz`) vs single-dir plugins needing config — free unique.
- **WP 6.9–7.1 adopter** (`salted cache`, `WP_HTML_Processor`, template buffers, `should_load_separate_core_block_assets`, Abilities, client-side media) — ahead of LSCWP/WP Rocket dev audience cares.

---

## 4. 10 Novel “No-One-Has-It-Yet” Features (White-Space 2026)

Validated against 10 big + 20 niche + Performance Lab roadmap — none of incumbents ships these integrated, one-click, free.

| ID | Feature | Why novel | Effort | Phase |
|---|---|---|---|---|
| **N1** | **AI Adaptive Optimization (RUM → ML → auto-tune + predictive prerender)** — close loop: RUM beacon per-breakpoint + ML learns unused CSS/JS per URL pattern, auto-generates `exclude/defer` lists + adaptive Speculation Rules (Uxify Navigation AI: predict next click, prerender top-2). Use WP 7.0 AI Client + Abilities bus, on-device TF-Lite optional. | FlyingPress RUM display-only, WP Rocket synthetic, Perfmatters manual, Uxify paid standalone. No free plugin closes RUM→ML→auto-tune. | L | Next |
| **N2** | **Edge HTML Cache Adapter (CF Workers / Bunny Edge, host-agnostic)** — one-click deploy `cache/wppo/{domain}` logic to Cloudflare Workers/Bunny Edge (stale-while-revalidate, purge via `CDN_Purger` extension). <30ms global TTFB without LS. Bunny roadmap plans edge HTML but not shipped; LSCWP QUIC LS-only. | No free plugin offers host-agnostic edge HTML with file-cache semantics. | L | Next |
| **N3** | **HTTP/3 + Early Hints 103 + Priority Hints Orchestrator** — auto-negotiate `Alt-Svc`, emit `103 Early Hints` for CCSS + LCP `fetchpriority=high` before PHP finishes, `Priority u=0/i` + `fetchpriority=low` for deferred modules. Integrate OLS `quicEnable` detection. | Plugins emit `preconnect/preload` but none orchestrates 103/Early Hints or HTTP/3 priority. | M | Next |
| **N4** | **View Transitions API (SPA-like navigations)** — opt-in `add_theme_support('view-transitions')`, `document.startViewTransition()`, per-template `view-transition-name` + animations, integrated with speculation + cache (skip transition cached→dynamic). Lab plugin experimental Issue #1997 (2026-01-02). | No commercial cache integrates it. First free wins “modern” narrative. | M | WP 7.2 |
| **N5** | **Optimization Detective (OD) Integration** — when OD active, consume URL Metrics (viewport groups, LCP per breakpoint) to drive lazy/load `fetchpriority` / CSS background lazy / Embed Optimizer instead of `excludeFirstImages 1-3` heuristic. | OD → Core, no commercial plugin feeds OD into optimization yet. Strategic moat. | M | WP 6.9+ |
| **N6** | **Instant Back/Forward (bfcache) for Logged-In + ESI-lite** — `nocache_bfcache` privacy-safe `pageshow` restore via session-token invalidation + AJAX/shadow-DOM hole-punch for cart/admin bar/nonce. LS ESI Enterprise only, OLS none, our role-hash all-or-nothing. | No free plugin achieves bfcache + personalized hole-punch on OLS. | M | Next |
| **N7** | **Performant Translations (`.mo→.php`) + OPcache File Object Cache** — toggle `.mo` compile to `.php` (Lab, huge on 20+ locales) + OPcache-backed file object cache (Docket Cache style) as Redis-free fallback. | No cache plugin does `.mo→php`; LSCWP needs Redis/Memcached/LSMCD. Only one with zero-config OPcache path. | M | Next |
| **N8** | **LLMs.txt + AI-Digest** — auto-generate `/llms.txt` + `/llms-full.txt` + chunked markdown top-URL digest + `Link: <llms.txt>; rel=alternate"` + embeddings pre-warm + AI crawl prioritization via `wppo_web_vitals_trends`. Only Hostinger does `llms.txt`. | Zero perf plugin touches AI discoverability — SEO 2026. | S | Next |
| **N9** | **Security-Aware Performance (CVE guard)** — lightweight AI CVE feed (Patchstack 7000+ plugins real-time) → auto-exclude/isolate vulnerable plugin assets from cache/optimization, surface risk in System Info + Suggestions (“CVE-2026-XXXX — defer disabled until patch”). | Perf + security siloed; no perf plugin integrates posture. | M | Next |
| **N10** | **Volatility-AI Cache TTL + Stale-Bot Serving** — AI-learned per-post-type/route TTL from `save_post` frequency + RUM churn (`product 1h, page 1w, feed 15m`) + serve-stale-to-bots with background revalidate (Hyper Cache feature). W3TC/LS manual TTL, no AI; bot stale niche. | Unique combo, high value commerce/news thunder-herd reduction. | M | Next |

*Bonus swap:* **Client-Side AVIF Transcode Fallback** — WASM-Vips Web Worker transcodes when server lacks AVIF, caches result (WP 7.1 client-side media bridge).

**Recommended ship order (narrative + effort):** N8 LLMs.txt (S, sales), N5 OD (M, core alignment), N1 AI Adaptive (L, flagship) — others per sprint.

---

## 5. Library & WordPress Core Readiness (2026-08-27 snapshot)

### Composer (`composer.json` → `composer.lock`)

| Package | Constraint | Locked | Latest 2026-08-27 | Status |
|---|---|---|---|---|
| `voku/html-min` | `^5.0` | 5.0.0 2026-04-23 | 5.0.0 | ✅ current |
| `matthiasmullie/minify` | `^1.3` | 1.3.75 2025-06-25 | 1.3.75 | ✅ current |
| `symfony/css-selector` | `^7.4` | v7.4.17 2026-08-21 | v7.4.17 latest 7.4 (v8.1.5 exists) | ✅ latest in major; bump to `^8.0` optional (needs PHP 8.2+) |
| `woocommerce/action-scheduler` | `^3.8` | 3.9.3 2025-07-15 | 4.1.0 2026-08-05 | ⚠️ **1 major behind — update to `^4.1`** |

Core does **not** bundle `symfony/css-selector` (uses `WP_HTML_Tag_Processor`), so our dep is via `voku/simple_html_dom` only.

### npm (`package.json`)

`@wordpress/scripts 33.x` + `@wordpress/components 29.x` + `@wordpress/i18n 6.20` + node 22.14.0 — current for WP 7.1 train. No risk.

### WordPress 6.8 → 7.2 delta (top gaps we still have)

| Core | Feature | Our status |
|---|---|---|
| **6.8** | Speculation Rules API (`wp_get_speculation_rules_configuration`) | ✅ `PreloadSettings` mode/eagerness + `WP_SPECULATIVE_LOADING_DEFAULT_*` constants |
| **6.9** | Template Enhancement Buffer, `styles_inline_size_limit` 40KB, `fetchpriority` for scripts/modules, block-assets on-demand, Abilities API, `WP_HTML_Processor` | ✅ dual-path buffer, inline budget, `apply_module_loading_strategies`, Abilities registration — **remain:** salted-cache family completeness (`get_multiple_salted` + query-cache eviction), `wp_get_loading_optimization_attributes()` for occluded images, emoji footer module |
| **7.0** | AI Client, Admin View Transitions, `module_dependencies` classic↔module, responsive block visibility | 🟡 Abilities tactile but not AI Client; `module_dependencies` **missing** |
| **7.1** | Client-side media (wasm-vips), speculation env pinning, responsive styling controls, accessible tooltips | ✅ client-side media coexistence `filter_client_side_supported_mime_types` + `forceServerSideConversion`; speculation env pinned — **remain:** Secrets API is 7.2 proposal |
| **7.2 (Dec 9 2026)** | **Secrets API** `wp_set_secret`/`WP_Secret`/`secrets.php` drop-in (proposal 2026-08-25) | 🔲 Doc now, code at GA — migrate Cloudflare token `WPPO_CLOUDFLARE_API_TOKEN` / `wppo_settings` plaintext → secrets when available |

**Action:** bump `action-scheduler 3.9.3→4.1.0` (1-day, `composer test`), then salted-cache family + `wp_get_loading_optimization_attributes` (P1).

---

## 6. Priority Reconciliation (Updated Order)

1. **P0 now:** Phase 0 LS detection fix `Server_Rules::get_server_type()` + `litespeed-roadmap.md:LS-001-005`
2. **P1 next:** Sync matrices (flip RUM/CDN rows ✅) + TTFB disclaimer + `action-scheduler` bump + design pick (this doc + `designs/` chooser)
3. **P2 next:** White-space N8 (LLMs.txt S) → N5 OD (M) → N1 AI Adaptive (L flagship) — each gated `function_exists()`/`@since NEXT`

---

*Sources: WitsCode 2026-01-08, Gaurav Tiwari 2026-03/07, PageSpeedMatters 2026-07-13, WPMayor, FluxPlugins, TheOceanMarketing, LaFactory, Bunny.net 2025-06-13, Uxify Nav AI, NitroPack 2026-01-19, WP Performance Lab 2026-07-01, Make WP Core (7.0 2026-05-14, 7.1 2026-08-05, 7.2 call 2026-08-12, Secrets 2026-08-25), packagist.org 2026-08-27.*

