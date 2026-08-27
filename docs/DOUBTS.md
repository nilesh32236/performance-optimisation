# Doubts & Skipped Items — Autonomous Run 2026-08-27

**Branch:** `master` @ `a41ad0fe` (PR #713 merged)  
**Instruction:** Work autonomously, skip doubtful parts after noting them, report at end.

---

## 1. Skipped / Deferred (Intentionally Not Fixed in This PR)

| Item | Why skipped | Tracking |
|---|---|---|
| **3 pre-existing JS test failures** — `PluginSetting` export button not found, `ObjectCache` Hit Ratio `0%` vs `0.0%`, `DatabaseCleanup` waitFor 15 | Fail on `master` before LS changes (verified via stash 337 vs 338 pass). Fixing would widen Phase 0 scope; design overhaul (Aug 24-27) likely introduced regressions. | **#710**, **#711**, **#712** created |
| **Old open issues** — #673 (media print deadlock removeUnusedCSS+criticalCSS+combineCSS), #671 (settings wipes sibling tabs), #670 (CLI tabs empty), #669 (release ZIP missing vendor/), #667 (audit 0/3/8), #646 (v2.0.0 remaining) | Out of Phase 0 scope (Phase 0 is detection only, <1 day). #671 is data-loss high severity but requires tab merge logic; #669 requires release script change. | Reported verbally, not re-filed (already open) |
| **Phases 1-5 LS tasks** — optimizer guards, purge sync, header emission, next-gen rewrite, Brotli, ESI-lite, QUIC.cloud | Planned in `litespeed-roadmap.md` LS-101..LS-404 but intentionally not implemented in Phase 0 PR to keep <1 day, low-risk, shippable. | **#686-708** open, keep for next sprints |
| **Library bump** — `woocommerce/action-scheduler 3.9.3 → 4.1.0` | Detected stale 1 major behind (Packagist 2026-08-27). Not bumped in Phase 0 to avoid cron regression risk. | **#708** (LS-904) |
| **WordPress 7.2 Secrets API migration** — `wp_set_secret` for Cloudflare token | Proposal stage (alpha trunk r63167, GA Dec 9 2026). No code until GA per `wordpress-7x-readiness.md` P3. | Doc only |
| **Design overhaul implementation** — picking Variant A/B/C | Static previews only in `designs/` per request “if issue with direct update then create static designs”. Direct SPA overhaul would be 3-day + churn risk; chooser needed before code. | **#709** (+ 3 HTML files) |

---

## 2. Doubts / Unverified / Needs Human Review

| Doubt | Detail | Where noted |
|---|---|---|
| **Server cache config exact values** — `module cache { ls_enabled 1, enableCache 0, maxCacheObjSize 10M … }` | Could not read `conf/httpd_config.conf` / `vhconf.conf` (permission denied even as admin). Values from OLS docs, marked “reported, not verified” in `litespeed-research.md:32`. | `docs/litespeed-research.md` patched |
| **OLS `.htaccess` compatibility** — research claimed “Full same compat”, actual is rewrite-focused + restart required | Verification sub-agent flagged `mod_deflate/mod_expires` not fully supported on OLS; plan fixed to “rewrite-focused”. | Fixed in docs, impl treats LS like Apache for rewrite |
| **LSCWP filter name `litespeed_vary_cookies` vs `litespeed_vary`** | `litespeed-integration-plan.md:364` used `litespeed_vary_cookies` but actual LSCWP 7.9 filter is `litespeed_vary` (per `src/vary.cls.php`). Plan patched to `litespeed_vary` with raw header fallback. | Fixed |
| **LiteSpeed detection substring `ols`** | Plan initially used `strpos $s, 'ols'` which matches `tools`/`console` etc — too broad. Patched to `litespeed`/`openlitespeed` only. | Fixed in `class-server-rules.php` |
| **Rewrite rule bug `$1.$1.webp`** | Plan had `RewriteRule … $1.$1.webp` → double path. Fixed to `$1.webp` / `$1.avif`. | Fixed |
| **TTFB numbers** — `200→50ms` / `90ms` | Estimates from WitsCode 2026 benchmarks, not measured on this host. Marked “estimated” in docs; `ab -n 100` measurement pending LS-305. | Fixed |
| **Host share ~30%** | Unsourced estimate, marked `*estimates`. | Fixed |
| **Brotli level `brStaticCompressLevel 6` / `quicShmDir`** | Config unreadable, marked unverified. | Fixed |
| **LITESPEED_GUEST / LITESPEED_ESI_OFF constants** | Research listed as active but actually commented-out in LSCWP 7.9. | Fixed |
| **PERFORMANCE.md as gap doc** | Research cited it as gap document — mis-categorized, now “Performance note” under Sources. | Fixed |
| **LSCWP REST `Cache-Control` override bug** — `rum_collect` public token+IP endpoint may be overwritten by LSCWP even when “Do Not Cache URIs” set (wordpress.org/support 2026-05-04) | Not fixed in this PR; may affect RUM beacon. Needs LS-301 header emission awareness. | Noted, not fixed |
| **LSCWP 7.9 login cache default flipped to false** | Affects `enableLoggedInCache` parity; not documented in research gap. | Noted for LS-101 mode docs |
| **CacheLife 0 “never expire” → 604800 for LS** | Plan maps file-cache `0` (never) to LS `1 week` — silent policy change, now explicit warning comment in code snippet but not yet implemented. | Patched doc |
| **3 dashboard eslint warnings** — `cacheSettings` logical expression changing useCallback deps | Pre-existing in `Dashboard.js:122` (3 warnings). Not fixed (requires useMemo refactor). | npm lint shows 3 warnings |
| **No pre-commit hooks** — lint only in CI | `AGENTS.md` notes “no pre-commit hooks — all quality checks run in CI only”. Local verify done manually. | By design |
| **Translation `.mo→.php` / LLMs.txt / ESI / QUIC.cloud** | Not in scope for OLS value; white-space N-features documented but not coded. | `competitive-audit-2026.md:4` |

---

## 3. Verification Performed (Before Merge)

- **PHP:** `vendor/bin/phpunit` 380/380 OK (1 skipped) — 5 new ServerRules litespeed cases passed
- **JS:** `npm test` 338/341 pass (3 pre-existing failures on master → #710-712), FileOptimization litespeed test passed
- **Lint:** `npm run lint:js:fix` clean (3 Dashboard warnings pre-existing), `phpcs` 2/2 clean on touched files
- **Build:** `npm run build` webpack 5.109.2 success — `build/index.js` + `tab-file-optimization.js` committed
- **Host:** `curl -I https://nileshportfolio.duckdns.org` 200 LiteSpeed, `wp plugin status` active 1.9.0, `Server_Rules::get_server_type()` returns `litespeed` for LiteSpeed/OpenLiteSpeed banners (CLI simulated), `is_litespeed()` true, `other` for unknown
- **Logs:** `wp-content/debug.log` no new fatal (only pre-existing cron + password-strength-meter notices from WP 6.9.1), no `patchwork.json` error when run from plugin root
- **Composer:** `update --lock` done, vendor present (`voku/html-min 5.0.0`, `matthiasmullie/minify 1.3.75`, `symfony/css-selector 7.4.17` current; `action-scheduler` stays 3.9.3 until #708)

---

## 4. Next Human Decisions Needed

1. **Pick design variant** — open `designs/variant-a-native.html`, `variant-b-premium.html`, `variant-c-dense.html` (or `designs/README.md` table) and comment on **#709** with choice (A Native WP vs B Premium vs C Dense). No code mutated until pick.
2. **Confirm Phase 1 scope** — optimizer guards (LS-103) pause WPPO minify when LSCache owns cache — strict “one owner owns all” vs per-optimizer granularity (plan §19 Q2).
3. **Mode default** — `auto` → `litespeed` when both active vs `auto` → `wppo` keep incumbent (plan §19 Q1). PR defaults to `auto` → `wppo` when no LSCWP, `auto` → `litespeed` when active is recommended but user can override.
4. **Fix or keep 3 JS failures** — approve fixing #710-712 in next PR or accept CI red.
5. **Action-scheduler bump** — approve `3.9.3 → 4.1.0` update (1-day smoke) per #708.

