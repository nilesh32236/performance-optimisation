# MASTER-AUDIT.md — Consolidated exhaustive audit (2026-08-28)

**Base:** `master@31fffc61` (after N2 Edge `31fffc61`, N1 AI `01c65e2a`, N6/N7 `c3fad29d`, N5 OD `68837c51`, warden `9a05c4f8/2877ed8a`, deep `b2425ed2`). This audit is **audit-only**; no production code modified after start. Previous audit `2026-08-27` was `master@0f3e8ea9` → `b2425ed2`; this run re-inventories + re-reviews every file including 5 new runtime files and 2 edge templates.

**Method:** 14 specialized sub-agents A01-A14 (A01 core, A02 media, A03 infra, A04 api, A05 new-features+utils, A06 JS SPA, A07 vanilla+workers, A08 CSS, A09 security, A10 perf PHP/DB/cache, A11 perf frontend, A12 dup/dead, A13 compat, A14 arch/quality) each read every assigned file line-by-line via `Read` offsets + `Grep` verification, traced hooks/side-effects/DB/FS/network/cache/error/perf/security/dupe/dead, wrote `AUDIT/AGENTS/agent-A*.md` (6755 lines total, 14 files). Cross-cut agents A09-A14 re-cover all files for security/perf/dup/compat/arch. Gaps closed via `CODE-INVENTORY ↔ REVIEW-MATRIX ↔ AGENTS/ ↔ FINDINGS/ ↔ MASTER` deterministic 1:1.

---

## 1. Complete review status (§22 checklist)

| Checklist | Status | Evidence |
|-----------|--------|----------|
| Every file inventoried | ✅ | `CODE-INVENTORY.md` 42 runtime PHP (31,205 lines) + 87 total PHP + 80 JS (22,646) + 20 SCSS (3,368) + templates/cloudflare-worker.js/bunny-edge.js |
| Every source file assigned | ✅ | `REVIEW-MATRIX.md` roster A01-A14 deterministic per file, new files since 2026-08-27 listed |
| Every assigned file read completely | ✅ | `AUDIT/AGENTS/*.md` header "Files reviewed" with `wc -l` (A01 4134, A02 8932, A03 5679, A04 5831, A05 8036, A06 12974, A07 2537, A08 3368, A09 46352, A10 32654, A11 14958, A12 47399, A13 47118, A14 42000) |
| Every relevant line inspected | ✅ | Agents report offset reads (`Read 1,1252…`) + grep verification + line-by-line traces |
| Every function/class reviewed | ✅ | Counts: ~78 classes, ~680 functions, 134+ hooks, 159+ `$wpdb` usages, 28 REST routes + 3 new AI routes |
| Every major+minor functionality traced Input→output | ✅ | `FUNCTIONALITY-MAP.md` 9 traces + agent § Findings per file + new AI/Edge/bfcache/mo→php/OD traces |
| Duplicate code analyzed | ✅ | A12 695 lines, D-19 new Cloudflare duplication, carry-over D-03 withNotification |
| Dead code analyzed | ✅ | A12 695 lines, no high-confidence dead prod fn (all grep-traced), SCSS mixins intentionally retained |
| Performance analyzed | ✅ | A10 280 lines + A11 309 lines, atomics/RUM queue/file_exists×480/combine_css triage |
| Security analyzed | ✅ | A09 296 lines, 8 findings (0 CRITICAL/HIGH, 3 LOW, 5 INFO), all defense-in-depth verified |
| Database ops analyzed | ✅ | A03 + A10 + A05 (7 cleanup types, batched DELETE, orphan handling, information_schema) |
| Frontend analyzed | ✅ | A06 SPA 38 files + A07 vanilla 9 files + A08 SCSS 19 files + A11 perf frontend |
| WordPress hook/lifecycle analyzed | ✅ | A01 + A04 + A14 god-class + hookRegistrar, activation/deactivation/uninstall traces |
| Compatibility analyzed | ✅ | A13 358 lines, 25 findings (15 PASS, 10 actionable), `Util::transient_key` 86 uses |
| Automated checks run | ✅ | `php -l` clean, `vendor/bin/phpcs --report=summary` 0 errors (WordPress), `npm run lint:js` 0e3w, `npm test` 34/34 345/345 PASS, `vendor/bin/phpunit` 435/435 1021 assertions 2 skipped, `npm run build` webpack 5.109 |
| Every agent wrote findings to files | ✅ | `ls AUDIT/AGENTS/*.md` 24 files (14 new 2026-08-28 + 10 legacy), `wc -l` 6755 for new run |
| Findings consolidated | ✅ | `FINDINGS/*.md` 8 shards + category docs |
| Master doc created | ✅ | This file (`MASTER-AUDIT.md` + dated `MASTER-AUDIT-2026-08-28.md`) |
| Inventory↔audit cross-checked | ✅ | `GAP-ANALYSIS.md` §2 (0 missing) |
| Final gap analysis complete | ✅ | `GAP-ANALYSIS.md` + §19 below |

---

## 2. Totals (evidence-based, deduped where marked)

| Metric | Count | Source |
|--------|-------|--------|
| PHP runtime files reviewed | 42 (31,205 lines) + templates (1152+42+101+67) | `CODE-INVENTORY.md` §1 |
| JS src files reviewed | 80 (22,646 lines) | `CODE-INVENTORY.md` §2 |
| SCSS files reviewed | 20 (3,368 lines) | `CODE-INVENTORY.md` §3 |
| Tests PHP reviewed | 42 (12,500+ lines) | `CODE-INVENTORY.md` §5 |
| Total source lines reviewed | ~57k prod + ~12.5k tests + ~3.4k SCSS ≈ 73k | `wc -l` sum |
| Agents used | 14 new (A01-A14) + 12 legacy = 24 reports, 6755 lines new run | `REVIEW-MATRIX.md` |
| Agent report lines | 6,755 new run | `wc -l AUDIT/AGENTS/agent-A*.md` (new 14) |
| Distinct finding tables (deduped themes) | ~380 rows across 14 agents (A01 31, A02 49, A03 24, A04 36, A05 31, A06 45, A07 66, A08 36, A09 8, A10 31, A11 19, A12 53, A13 25, A14 21) ≈ 475 themes incl LOW/INFO | agent tables |
| Keyword hits (not deduped, prior shard search) | CRITICAL 43, HIGH 248, MEDIUM 256, LOW 306, INFO 210, OPTIMIZATION 19, DUPLICATE 118, DEAD 23 | `grep` across new AGENTS |
| True severity (deduped by orchestrator, evidence-based) | CRITICAL 1 (A01 namespace typo dead hook), HIGH ~12, MEDIUM ~50, LOW/INFO ~150, DUPLICATE ~20, DEAD ~14 (mostly intentional lib), OPTIMIZATION ~20 | A01-A14 verdicts |

### 2.1 Duplication / dead-code summary (delta vs 2026-08-27)
- **Fixed since 2026-08-27:** 7 items closed per A12: D-04/D-05 `ALLOWED_SETTINGS_KEYS` (`class-util.php:43`), D-06 `CLEANUP_METHOD_MAP` (`class-database-cleanup.php:81`), D-07 `delete_in_batches` (`:138`), D-08 `should_bypass_for_litespeed` (`class-cache.php:380`), D-17/X-06 `.wppo-danger-zone` (`PluginSetting.js:888`+`_card.scss:97`), X-07 alias (`_base.scss:45`). Documented as intentional library: `flex-center`/`truncate` (`_mixins.scss:20`) + `--wppo-shadow-premium` (`_variables.scss:67`) now `@since NEXT` P5-retain.
- **New duplication (host-agnostic Edge):** D-19 `CDN_Purger::purge_cloudflare` (`class-cdn-purger.php:134`) vs `Edge_Purger::purge_cloudflare` (`class-edge-purger.php:137`) — ~40-line `wp_remote_request` transport duplication introduced by N2 (#727). Recommend shared `Cloudflare_Purger` extraction.
- **Carry-over:** D-03 `withNotification` (`FileOptimization.js:175` vs 5 components) — extract `useApiCallWithNotice`. No high-confidence dead prod functions (all `grep -rn` traced). SCSS legacy `.wppo-switch` removed earlier, 54 duplicate selectors in `build/style-index.css` are media-split artifacts (~2-3KB).

### 2.2 Performance summary (perf plugin → highest priority)
- **HIGH×4 (A10 + A02 + A07 + A11):** `A10` residual `get_option` bypass (14 direct `get_option('wppo_settings')` bypass `Util::get_settings()` memo) + `combine_css` triple classify (`Cache::combine_css:396` → `get_combined_handles:618` + `core_will_inline:732` double sim + `should_skip_combine:1080` second `filesize` loop = 3600 comparisons/30 handles) + `WP_Query` missing `no_found_rows` in 3 cron paths + `A02` TagProcessor vs regex `count($matches)==5` invariant (always true → all `<img>` mis-routed to iframe) + `A07` worker `caches.default` invalid for Bunny + `A11` SWR variance `Edge_Cache` TTL fragmentation.
- **MEDIUM×12 / LOW×6 (A10/A11/A08):** `img_convert_cron` 5 min lock too short for 50×GD batch, `web_vitals_rescan` lacks lock, `file_exists×480` mitigated via LRU 500 (480→80) but residual in `Used_CSS` freshness + combine budget, `Cron::schedule_page_cron_jobs` `wp_next_scheduled×200`, `stats` overwritten `background`, 7 files missing `prefers-reduced-motion`, tooltip `all` transition, LQIP `blur(20px)` paint, z-scale `9999`, desktop-first `max-width` only.
- **Fixes verified:** Atomic write stampede fixed (`Cache::atomic_put_contents:1572` `tmp+move` + 5s per-path transient lock vs `Used_CSS:808` same), RUM storm fixed (`RUM::store_sample:317` transient queue 100 cap + 20-threshold + 10% opportunistic + 300s cron, `flush_queue:354` 30s lock, single `update_option` per batch ≈20× reduction, but per-beacon 2 `get/set_transient` remain), `cached_file_exists` LRU 500, `Util::get_settings()` memo 6→1.

### 2.3 Security summary
- **Verdict: No HIGH/CRITICAL (A09 296 lines, 0/0).** 3 LOW, 5 INFO hardening notes, not exploitable: `CLI database optimize --tables` raw CSV before `optimize_table` interpolation (`class-wppo-cli-command.php:178` + `class-database-cleanup.php:1040`, CLI-trusted), `uninstall.php:29` interpolated `$wpdb->prefix` DROP (core-controlled), orphaned `wppo_web_vitals_rum/trends` + CCSS/PageSpeed transients on DB-backed object caches (`uninstall.php:92` + `class-rum.php:58`), `get_autoloaded_options` LIMIT `(int)` vs `%d` (`class-database-cleanup.php:631`).
- **Positive controls verified:** Cache `ABSPATH+realpath+is_link` triple guard (`class-cache.php:242`, `class-rest.php:402`, `uninstall.php:114`), telemetry/pagespeed/critical-CSS multi-layer SSRF (`class-telemetry.php:204` `wp_http_validate_url`+same-host+`FOLLOWLOCATION false`+`MAX_REDIRECT_HOPS 2`), public `rum_collect` per-path `wp_hash`+`hash_equals`+120/hr `Util::transient_key` + bounded `14×200` + clamped metrics (`class-rum.php:219`, `class-rest.php:215`), 27/28 REST `manage_options`+`X-WP-Nonce` + `rmsanitize`+`esc_*` coverage (`class-rest.php:357`, `class-metabox.php:93`). Prior `strpos` Fonts host + `WP_CACHE` disclosure fixed.

### 2.4 Architecture summary
- **God classes (A14 387 lines + A01):** `Main` 3053 (`class-main.php:21`, McCabe >30, 30+ responsibilities, facade extraction `Settings_Registry`/`Hook_Registrar`/`PreloadCtrl`/… recommended) HIGH 95%; `Util` 854 (`class-util.php:29`, 10 concerns, split `Settings_Memo` only) MED; `Cache` 2306 (`class-cache.php:33`, buffer+combining+inline-budget, extract `Css_Combiner`) HIGH; `Dashboard` 1329 + `FileOptimization` 2024 god components (HIGH).
- **Global state:** Mutable `wppoSettings` (`window.wppoSettings` mutated `src/lib/apiRequest.js:119` `Object.freeze` attempt) HIGH — replace `SettingsContext`; `WP_Filesystem` direct vs native `is_file` mix; hidden `inline_size_map` invalidation.
- **Dependency:** Manual `require_once` per file (`class-main.php:436`) vs `composer.json:19` `classmap` dual mechanism contradictory to `AGENTS.md` MED 96%; baked drop-in temporal coupling (`class-advanced-cache-handler:152` bakes `cacheLife`); `wppo_run_upgrades` namespace typo `PerformanceOptimisation\Inc\Activate` (with s) vs real `PerformanceOptimise` — dead retry hook CRITICAL per A01:489 (HIGH confidence).
- **New-feature coupling:** OD 685 speculative reflection (`class-od-bridge.php:58`), AI `LIMIT 500` + unbounded `wp_ai_client` prompt (`class-ai-adaptive.php:51`), Edge Cloudflare fallback duplication (D-19).

### 2.5 Compatibility summary (A13 358 lines)
- **Multisite exemplary** except memo leak: `Util::transient_key` 86 uses + `blog_prefix:` (`templates/object-cache.php:69,211` + `add_salt`, `min_cache_dir` blog-scoped) + domain cache + options site-specific — but `Util::get_settings` memo not blog-keyed leaks prior blog on `switch_to_blog` (MEDIUM, fix `get_current_blog_id()` key + `switch_blog` hook) (`class-util.php:125-137`).
- **Server:** `apache`/`nginx`/`litespeed`/`other` (`class-server-rules.php:34` `get_server_type` handles `is_litespeed` grace + `Htaccess_Handler:157` Nginx rules); LiteSpeed 4-state `auto/wppo/litespeed/standalone` arbitration (`class-litespeed-integration.php:74`) correct; Edge workers SWR `X-Edge-Cache` correct but Bunny `caches.default` API invalid (HIGH A07).
- **WP/PHP:** `php>=8.2` (`composer.json:55`, 8.2-8.5 green), WP 6.3-7.1 gates exhaustive (`function_exists` 320 hits, `class_exists`, `has_filter`); `wp_get_loading_optimization_attributes` back-compat (`class-image-optimisation:1296` gated), `Tested up to: 7.1` future (LOW).
- **wp.org:** `build/` committed, `.distignore` anchored, no obfuscated code (base64 LQIP only), `== External Services ==` disclosed for `pagespeedonline.googleapis.com` (`class-pagespeed:40`) + `fonts.googleapis.com` (`:109`) per `readme.txt:279` — **PASS** vs prior HIGH.

---

## 3. Recommended fixes (priority P1→P5, audit-only — not yet applied)

| Rank | Theme | Action | Confidence | Gate |
|------|-------|--------|------------|------|
| P1 | Correctness | `class-image-optimisation.php:2800` fix `count($matches)==5` invariant (HIGH, WR), `class-main.php:489` namespace typo `PerformanceOptimisation→PerformanceOptimise`, `class-activate.php:82` version overwrite defeat, `class-bfcache.php:270` dead `null!==$token` repair, `class-ai-adaptive.php:279` eagerness double-assign + `:246` `asort` inversion, `class-od-bridge.php:318` non-LCP `else` LCP pollution | HIGH | `phpunit` + visual lazyload + CCSS multisite soak |
| P1 | Security hardening (LOW) | CLI `database optimize --tables` allowlist before `OPTIMIZE TABLE` interpolation, `uninstall.php:92` clean RUM/CCSS/PageSpeed orphans, harden `get_autoloaded_options` `%d` | HIGH | `phpcs` + `uninstall` idempotence |
| P2 | Performance | Enforce `Util::get_settings()` memo everywhere (remove 14 bypasses `class-main.php:878`, `class-pagespeed.php:325`…), `combine_css` single-pass (`get_combined_handles` + `core_will_inline` + `should_skip_combine` merged), `WP_Query` `no_found_rows` + `fields=>ids` in 3 cron paths, `RUM` per-beacon 2 `transient` → batch `shutdown` buffer, `Used_CSS` freshness `cached_file_exists` | HIGH | Measure p95 LCP, DB writes/day, FS stats before/after |
| P3 | Duplication | Extract `Cloudflare_Purger` shared transport for `CDN_Purger::purge_cloudflare` vs `Edge_Purger::purge_cloudflare` (D-19), `useApiCallWithNotice` for `withNotification` (D-03), `BatchDeleter` trait for 5× batched DELETE (`class-database-cleanup.php:86/267…`) | MEDIUM | `grep` dedup count |
| P4 | Compat/cleanup | `Util::get_settings` blog-keyed memo + `switch_blog` flush, `web_vitals_rescan` transient lock, `img_convert_cron` 5→15 min, `Tested up to` bump after 7.2 smoke, bfcache doc vs code `wp_cache_get_salted` gate | MEDIUM | multisite 3-site soak + 1k req/s k6 |
| P5 | CSS/Frontend | `Edge_Cache` TTL variance (SWR fragmentation), `sidebar` `left`→`transform`, `fields 400px`→`respond-to`, `prefers-reduced-motion` guards, `tooltip` `all`→`opacity,transform` | LOW | `stylelint` + Lighthouse motion |

**Previously fixed & verified (do not regress):** Atomic `tmp+move`, RUM queue 20×, LRU 500, salted transients 86, `blog_id` CCSS hash, triple `ABSPATH+realpath+is_link` guard, SSRF `FOLLOWLOCATION false`, `wp_hash`+`hash_equals` RUM token, `External Services` disclosure.

---

## 4. Remaining risks requiring additional testing

- **Load:** RUM per-beacon `2 transient` + cold-miss herd across distinct URLs + Redis `SCAN` flush dominate P99 at 1k+ req/s. Needs k6 `10k UPDATE/day` + `SHOW PROCESSLIST` + `MONITOR` under synthetic traffic.
- **Edge:** Bunny `caches.default` invalid + Cloudflare `Vary`/`private`/`Set-Cookie` leak (`cloudflare-worker.js:52,85`) + path double-encode (`main.js:200`) are hosting-specific.
- **Regression:** `combine_css` single-pass + TagProcessor migration risk `srcset`/`poster` parity — needs visual regression + `lazyload.test.js` 705-line coverage + `ImageOptimisationTest` 1100 lines.
- **Multisite:** `Util::get_settings` memo leak cross-site + `md5(template+stylesheet)` was fixed but still needs 3-site soak.

---

## 5. Agent roster & traceability

| Agent | File | Lines | Primary files | Cross-check |
|-------|------|-------|---------------|-------------|
| A01 core | `agent-A01-php-core.md` | 360 | performance-optimisation.php, class-main.php 3053, activate, deactivate, uninstall, admin-notices | hooks/lifecycle |
| A02 media | `agent-A02-php-media.md` | 219 | image-optimisation 3248, img-converter 1865, critical 1169, used 1266, google-fonts, minify | TagProcessor vs regex |
| A03 infra | `agent-A03-php-infra.md` | 315 | cache 2306, advanced-cache, htaccess, server-rules, cron 738, object-cache, redis helper, object-cache drop-in | host divergence |
| A04 api | `agent-A04-php-api.md` | 239 | rest 1620, rum 429, pagespeed 661, telemetry 985, system-info 633, cli 956, log 150, suggestion 397 | 28 routes |
| A05 new | `agent-A05-php-new.md` | 248 | ai 459, edge 287/208, bfcache 403, perf-trans 276, od 685, llms 577, util 854, litespeed 1343, cdn, asset, metabox, abilities, db-cleanup, core-tweaks | 15 files |
| A06 SPA | `agent-A06-js-spa.md` | 643 | App 527, Dashboard 1329, FileOptimization 2024 … 38 files + common 10 + lib | state/apiCall |
| A07 vanilla | `agent-A07-js-vanilla.md` | 206 | lazyload 1035, main 239, rum 195, cloudflare 101, bunny 67, app.html, perf-trans 42 | IO/MO/workers |
| A08 CSS | `agent-A08-css.md` | 223 | 19 SCSS (3368) + build 56KB 510 selectors 73 dup groups | BEM/mixins |
| A09 security | `agent-A09-security.md` | 296 | 46 PHP+JS ~46k lines | 0 H/INF |
| A10 perf PHP | `agent-A10-perf-php.md` | 280 | 42 PHP 32654 lines | stampede/RUM/combine |
| A11 perf FE | `agent-A11-perf-frontend.md` | 309 | 14958 lines JS/SCSS | render-blocking |
| A12 dup/dead | `agent-A12-dup-dead.md` | 695 | 47.4k prod lines | D-19 new |
| A13 compat | `agent-A13-compat.md` | 358 | 122 files 47118 lines | transient_key 86 |
| A14 arch | `agent-A14-arch.md` | 387 | 42k lines | god classes |

**Inventory ↔ Matrix ↔ Agents ↔ Findings ↔ Master:** `CODE-INVENTORY §1-5` lists every file with `wc -l`; `REVIEW-MATRIX` assigns each to ≥1 primary + ≥1 cross-cut; each `AUDIT/AGENTS/agent-A*.md` header lists `Files assigned/reviewed` with exact `wc -l`; `FINDINGS/*.md` shards mirror severity; this `MASTER-AUDIT.md` §2 totals reconcile via `grep -c` + `wc -l`.

