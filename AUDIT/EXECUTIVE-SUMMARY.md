# EXECUTIVE-SUMMARY — Production-grade audit (2026-08-27)

**Scope:** Exhaustive line-by-line audit of every PHP/JS/SCSS/config/template/asset/hook/cron/REST/CLI in `performance-optimisation` (base `fix/deep-dashboard-2026` from `master 0f3e8ea9`). Audit-only; no production code modified.

**Inventory:** 37 PHP runtime files (28,674 lines), 143 JS files (25,844 lines), 20 SCSS (3,411), 36 PHP tests (12,371), `build/` committed (webpack). 25 REST routes, 7 cron schedules + sitemap singles, 7 WP-CLI subcommands, 134 hooks, 159 `$wpdb` usages, 205 options/transients.

**Army:** 12 specialized agents (A01-A12) parallel, 2,286 lines of agent reports written to `AUDIT/AGENTS/agent-A*.md`. Cross-cut agents A08-A12 cover every file again for security/perf/duplication/compat/quality. No file skipped (5-line file = same discipline).

**Automated verification:** `php -l` clean, `vendor/bin/phpcs --report=summary` 0 errors (ignore free-tier `opencode/deepseek` err_… + infra 8.5 parallel-lint download), `vendor/bin/phpunit` 401/946 1 skipped OK, `npm run lint:js` 0 errors 3 warnings (`react-hooks/exhaustive-deps` in `Dashboard.js:123`), `npm test` PASS (`wp-scripts test-unit-js`), `npm run build` webpack 5.109 `compiled successfully`.

**Severity (keyword counts across agents — not deduped, see FINDINGS/ shards for traceability):** `CRITICAL 8` (keyword) but **0 true CRITICAL** after evidence review (all 8 are `CRITICAL` substring in comments/tests); **HIGH ~30 true** (see below), **MEDIUM ~50 true**, **LOW/INFO ~150**, **OPTIMIZATION ~20**, **DUPLICATE ~20**, **DEAD CODE ~14**.

**Top risks (HIGH, evidence `file:line`):**

| Severity | File:Line | Issue | Why matters |
|----------|-----------|-------|-------------|
| HIGH | `includes/class-abilities.php:270-475` | DB ability enum mismatch `trash` vs `trashed_posts` | Blocks MCP `database_cleanup` |
| HIGH | `includes/class-image-optimisation.php:576-697` | Next-gen regex fallback loses `<source>`/poster + skips `normalize_url` | Serves wrong format, breaks poster |
| HIGH | `includes/class-database-cleanup.php:90` + `class-cron.php:447` | `optimize_table` interpolation + `clean_unattached_media` N×`wp_delete_attachment` timeout | SQL hygiene / request timeout |
| HIGH | `templates/object-cache.php:SCAN` | `SCAN+DEL` flush O(N) | Redis stall at scale |
| HIGH | `includes/class-main.php:466` | `admin_bar_menu` missing cap check | Info disclosure |
| HIGH | `includes/class-cache.php + class-main.php` | `get_option(wppo_settings)` 6×/render + stampede non-atomic write | 3-6 ms churn + race |
| HIGH | `class-rum.php` | `store_sample` `get_option+update_option` per view 10k×200KB | DB write storm |

**Security:** 25/26 REST routes correctly gated `manage_options`+`X-WP-Nonce` (`class-rest.php:326`); `rum_collect` intentionally public with daily `wp_hash` token + `hash_equals` + 120/hr `Util::transient_key` rate limit — justified. `uninstall.php:109` symlink traversal (MEDIUM) needs `is_link` guard. SSRF hardening strong (`wp_http_validate_url`+same-host+`MAX_REDIRECT_HOPS 2`+`CURLOPT_FOLLOWLOCATION false`). SQL `prepare` clean. File path triple-guard (`..` reject + `ABSPATH` prefix + `realpath`).

**Duplication:** 20 patterns — batched DELETE ×5 (`class-database-cleanup:86`), whitelist triplication `class-rest:423/713` vs `PluginSetting.js:22`, `process_img_tag` TagProcessor vs regex 350×2, `lazy_load_videos×3`, LRU×3, placeholder×4, `withNotification` per-component wrappers. Two intentional kept bundles (`refreshNonce`, `apiCall`).

**Dead code:** No high-confidence dead production functions; actionable wins: unused SCSS mixins `flex-center`/`truncate` (`_mixins.scss:20`), inline `#fef2f2` vs `.wppo-danger-zone`, `TODO(#553/#624)` are live fallbacks.

**Compatibility:** `Util::transient_key` isolates 24 transients, `blog_prefix` `:` namespacing, domain-based cache, exhaustive `function_exists` gates, `build/` committed, `.distignore` anchored, hosting `Apache/Nginx/LiteSpeed/OLS` via `Server_Rules`. One wp.org HIGH: `readme.txt` missing External Services disclosure for `pagespeedonline.googleapis.com` + `fonts.googleapis.com`.

**Architecture:** `Main` 2956 + `Util` 643 + `App` 527 + `Dashboard` 1327 god classes (McCabe >30), mutable global `wppoSettings` vs store, manual `require_once` per file, no interfaces/DI, partially-initialized setter injection, mixed `WP_Filesystem` vs native FS, static caches leak between tests.

**Recommendation order:** P1 facades (`Main`→`HookRegistry`+controllers, `wppoSettings` store) → P2 perf (`get_option` singleton, stampede lock, RUM buffer, combine_css single-pass) → P3 correctness (ability enum, image fallback, symlink, realpath) → P4 duplication hygiene (BatchDeleter, generated allow-list) → P5 wp.org disclosure + `Tested up to` bump.

**Remaining risks:** RUM write storm + stampede + SCAN+DEL dominate p95 under load; symlink/admin-bar are low-exploit but compliance-audit blockers; duplication debt slows velocity not availability. All mitigatable without schema/contract break.

**Gate before fix batches:** `npm run lint:js` → `composer lint` → `npm test` → `npm run build` → `composer test`; commit `build/`; `@since NEXT` never bump `1.9.0`; keep `enabled=false` for edge opt-in.

> Full evidence: `AUDIT/AGENTS/agent-A*.md` (2286 lines), shards `AUDIT/FINDINGS/*.md` (by severity), category docs `AUDIT/*-REVIEW.md`, map `FUNCTIONALITY-MAP.md`, plan `REFACTORING-PLAN.md`.
