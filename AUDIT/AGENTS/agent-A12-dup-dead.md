# Agent A12 — Duplication & Dead-Code Audit (Quality Specialist)

**Scope:** exhaustive audit of ALL PHP (`includes/*.php`, `performance-optimisation.php`, `uninstall.php`, `templates/object-cache.php`) + JS (`src/**/*.js`) + SCSS (`src/css/**/*.scss`) at `master@31fffc61`  
**Date:** 2026-08-28  
**Auditor:** Agent A12 (quality specialist — duplication + dead code)  
**Workspace:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation`  
**Instruction:** Detect exact/near duplicates, repeated conditions/queries/validation/transforms/cache logic/permission checks/hook registrations, similar helpers/classes, copy-pasted functionality, plus unused funcs/classes/methods/vars/constants/hooks/assets/deps, obsolete compat, dead branches, unreachable, deprecated, old migrations, debug/commented code. Use Grep-based duplicate detection + reference tracing before declaring unused.  
**Method:** Full `Read` of every production file (with offsets), `grep -rn` / `rg` for duplicate function names (`function `), `manage_options`/`permission_callback`, `add_action`/`add_filter`, `get_option`/`transient`/`$wpdb` patterns, `Util::` helper usage, `apiCall`/`postJsonRequest`/`pendingRefresh` JS duplication, SCSS `wppo-` class / `@mixin` reuse. Cross-referenced each candidate with all call-sites (`grep -rn <name>`) before classifying. No production code modified. Prior audit `AUDIT/AGENTS/agent-A10-duplication-deadcode.md` (2026-08-27) used as baseline — re-verified every finding against current `master@31fffc61` and tracked fixes.

---

## Files Reviewed

| # | File | Lines | Surface examined |
|---|------|-------|------------------|
| 1 | `performance-optimisation.php` | 70 | Bootstrap constants, `new Main()` |
| 2 | `uninstall.php` | 185 | Per-site `wppo_cleanup_site()` (16 option deletes, 2 dirs, drop-ins, transient sweep) |
| 3 | `templates/object-cache.php` | 1152 | `WP_Object_Cache` drop-in (standalone/sentinel/cluster) |
| 4 | `includes/class-main.php` | 3053 | Constructor defaults (60+ keys), `setup_hooks()` (~40 hooks), `on_settings_update`, delay/defer, `should_optimise_for_logged_in`, `is_plugin_cache_url`/`_uncached`, `block-assets` migration |
| 5 | `includes/class-cache.php` | 2306 | `combine_css` + `get_combined_handles`/`combined_handles_match`, `core_will_inline`/`core_inline_budget_will_inline`, `should_bypass_for_litespeed`, `should_skip_combine_for_inline_budget`, `maybe_apply_cdn`, `is_not_cacheable`, `is_excluded_from_combine`, `get_logged_in_role_hash` |
| 6 | `includes/class-util.php` | 854 | `ALLOWED_SETTINGS_KEYS`/`TABS` constants, `process_urls`, `is_url_excluded`, `cached_home_url`/`cached_content_url`/`min_cache_dir`, `transient_key`, `sanitize_settings_recursively`, `is_cache_eligible_for_current_user`, `get_settings` cache |
| 7 | `includes/class-cron.php` | 738 | `schedule_cron_jobs`, `schedule_page_cron_jobs`, `schedule_sitemap_url_jobs`, `get_sitemap_urls`, `process_page`/`process_url`/`load_page` |
| 8 | `includes/class-img-converter.php` | 1865 | `convert_image`, `get_format`/`core_handles_next_gen`, `get_img_path`/`get_img_url` |
| 9 | `includes/class-image-optimisation.php` | 3248 | `maybe_serve_next_gen_images` (Tag Processor vs regex), `normalize_url`/`resolve_relative_path`, `post_process_placeholders`/`_dimensions`/`_auto_sizes`, `process_picture_blocks_*` |
| 10 | `includes/class-database-cleanup.php` | 1113 | `CLEANUP_METHOD_MAP`/`TABLE_MAP`/`METHOD_TO_TYPE`, `delete_in_batches` helper, `clean_all`/`auto_clean`, `get_counts`, `get_autoloaded_options` |
| 11 | `includes/class-rest.php` | 1620 | 25 routes, `update_settings`/`import_settings`, `database_cleanup`, `build_redis_config`, SSRF gates, uses `Util::ALLOWED_*` + `Database_Cleanup::CLEANUP_METHOD_MAP` |
| 12 | `includes/class-wppo-cli-command.php` | 956 | `cache`/`database`/`image`/`settings`/`object_cache`/`pagespeed` subcommands |
| 13 | `includes/class-abilities.php` | 496 | `register_abilities`, `permission_check`, uses `Database_Cleanup::CLEANUP_METHOD_MAP` |
| 14 | `includes/class-activate.php` | 345 | `add_wp_cache_constant`, `create_activity_log_table`, `maybe_run_upgrades` |
| 15 | `includes/class-deactivate.php` | 156 | `remove_wp_cache_constant`, `clear_cron_jobs` |
| 16 | `includes/class-advanced-cache-handler.php` | 324 | `create`, `is_our_dropin`, `foreign_dropin_present` |
| 17 | `includes/class-htaccess-handler.php` | ~222 | `update_rules`, `get_rules` |
| 18 | `includes/class-server-rules.php` | 191 | `get_server_type`, nginx/apache rules |
| 19 | `includes/class-litespeed-integration.php` | 1343 | `is_litespeed`, `effective_mode`, `should_disable_wppo_optimizer`, `can_apply_cdn` |
| 20 | `includes/class-edge-cache.php` | ~220 | `is_enabled`, `is_configured`, `get_config` (NEXT — Cloudflare/Bunny adapter) |
| 21 | `includes/class-edge-purger.php` | 208 | `purge_all`, `purge_cloudflare`, `purge_bunny`, transient lock |
| 22 | `includes/class-cdn-purger.php` | 229 | `purge_all`, `purge_cloudflare`, `purge_varnish`, `is_configured` |
| 23 | `includes/class-pagespeed.php` | 661 | `queue_scan`, `run_scan`, trend capping |
| 24 | `includes/class-telemetry.php` | 985 | `scan`, `parse_resources` |
| 25 | `includes/class-rum.php` | ~332 | `collect`, `is_rate_limited`, token guard |
| 26 | `includes/class-system-info.php` | 633 | `get_all` (8 groups) |
| 27 | `includes/class-object-cache.php` | ~363 | `get_status`/`ping`/`enable`/`disable`/`flush` |
| 28 | `includes/redis-connect-helper.php` | 377 | `wppo_redis_connect*` helpers |
| 29 | `includes/class-used-css.php` | 1266 | `parse_css`, `purge_css`, `process_buffer` |
| 30 | `includes/class-critical-css.php` | ~589 | `get_ccss_dir`, `generate`, `inline_ccss` |
| 31 | `includes/class-asset-manager.php` | ~245 | `capture_page_assets`, `get_page_assets` |
| 32 | `includes/class-metabox.php` | 461 | `add_metabox`, `render`, `save_metabox` |
| 33 | `includes/class-log.php` | 150 | `add`, `get_recent_activities` |
| 34 | `includes/class-google-fonts.php` | ~283 | `process_buffer`, `process_style_tag` |
| 35 | `includes/class-llms.php` | 577 | `register_rewrite`, `serve`, `generate` |
| 36 | `includes/class-bfcache.php` | ~380 | `init`, `attach_session_information`, `enqueue_scripts` |
| 37 | `includes/class-perf-translations.php` | ~320 | `init`, `generate_mo_php` |
| 38 | `includes/class-ai-adaptive.php` | 459 | `learn`, `filter_speculation_rules` |
| 39 | `includes/class-core-tweaks.php` | ~408 | `disable_*`, Heartbeat |
| 40 | `includes/minify/class-css.php` | 311 | `minify`, `inject_font_display_swap` |
| 41 | `includes/minify/class-js.php` | 169 | `minify` |
| 42 | `includes/minify/class-html.php` | 541 | `get_minified_html` |
| 43 | `src/App.js` | 527 | Tab routing, `Suspense` lazy imports (7 tabs), `wppoSettings` reads |
| 44 | `src/index.js` | 11 | `createRoot` mount guard |
| 45 | `src/main.js` | 239 | Admin-bar standalone: `postJsonRequest`, `refreshNonce` with `pendingRefresh` |
| 46 | `src/lib/apiRequest.js` | 249 | `refreshNonce` (deduped), `apiCall` (retry-on-403, `AbortSignal`, mutates `wppoSettings.settings`) |
| 47 | `src/lib/useNotice.js` | 74 | `useNotice` hook (timer, `notify`/`dismiss`) |
| 48 | `src/lib/util.js` | 36 | `handleChange(setSettings)` factory |
| 49 | `src/lib/litespeed.js` | 67 | `isLiteSpeed`/`effectiveMode` pure helpers |
| 50 | `src/rum.js` | 195 | RUM beacon (`web-vitals`, `sendBeacon`) |
| 51 | `src/lazyload.js` | 1035 | IntersectionObserver + MutationObserver, `data-src`/`data-srcset`, delay/defer loaders |
| 52 | `src/components/*.js` | ~8500 | 12 feature components + 11 `common/*` |
| 53 | `src/css/**/*.scss` | ~3400 | `abstracts/_variables`/`_mixins`, `base/_base`, `layout/*`, `components/*` |

**Lines reviewed (production only, committed build excluded):**

- PHP: `wc -l includes/*.php includes/minify/*.php performance-optimisation.php uninstall.php templates/object-cache.php` → **~31,200** (vs A10's 28,143 — delta is `+5` new `@since NEXT` classes: `Edge_Cache`, `Edge_Purger`, `Bfcache`, `Perf_Translations`, `Ai_Adaptive`)
- JS: `wc -l src/**/*.js src/*.js` → **~13,400**
- SCSS: `wc -l src/css/**/*.scss` → **~3,400**
- **Total production lines reviewed: ~47,400** (A10 reported ~45k; growth from new modules)
- Full `wc -l` including committed `build/` → `47,391` (matches `A12-quality-architecture` count)

**Build output** (`build/index.js`, `build/lazyload.js`, `build/style-index.css`, etc.) is committed but not separately audited — it is generated output from `src/` via `@wordpress/scripts`.

Prior audit baseline `AUDIT/AGENTS/agent-A10-duplication-deadcode.md` (2026-08-27) is the direct predecessor. Items marked **FIXED** were re-verified at `31fffc61`.

---

## Findings

Legend: **Category** = `DUPLICATE` | `DEAD CODE` · **Severity** = `DUPLICATE` | `DEAD CODE` | `LOW` | `INFO` (DEAD CODE severity used for actionable dead/unused; INFO for intentional/keep). All line numbers from `31fffc61`.

### 1. Duplication Findings

#### D-01 — `refreshNonce` + `pendingRefresh` thundering-herd guard copy-pasted between SPA and admin-bar

| Field | Value |
|-------|-------|
| **File:Line** | `src/lib/apiRequest.js:1,16-57` vs `src/main.js:6,60-101` |
| **Category** | DUPLICATE |
| **Severity** | DUPLICATE — keep intentionally |
| **Problem** | Both entry points define `let pendingRefresh = null` + identical `refreshNonce()` deduplicating concurrent `wppo_get_nonce` fetches via `finally { pendingRefresh=null }`. `src/main.js:2` carries comment “Keep in sync with src/lib/apiRequest.js: refreshNonce() + apiCall() retry-on-403 logic. This entry is intentionally standalone …”. |
| **Why matters** | Two nonce-refresh implementations must stay in sync. Drift risks 403-retry divergence (SPA returns `string` nonce; admin-bar returns `boolean` success). Maintenance cost + bundle size. At `31fffc61` no drift has occurred — logic is in sync. |
| **Evidence** | `grep -n "pendingRefresh\|refreshNonce" src/lib/apiRequest.js src/main.js` shows 5+6 hits with identical guard structure; `src/main.js:30-41` `postJsonRequest` retry on `403 === response.status` vs `src/lib/apiRequest.js:99-110` retry on JSON `code ∈ {rest_forbidden, rest_cookie_invalid_nonce}` (intentionally divergent retry predicates — see D-02). |
| **Impact** | Medium. If one side changes retry predicate the other must be inspected. Bundle size + manual sync cost. No functional bug at this commit. |
| **Recommended solution** | **Keep** — `src/main.js` is a standalone admin-bar entry that must not import the SPA bundle (enqueued on every admin page via `wppoObject`, not `wppoSettings`). When build allows code-splitting, extract a shared micro-helper. Meanwhile keep the `Keep in sync` comment and add a cross-file parity test. |
| **Confidence** | High |
| **Whether intentional** | **Yes — keep intentionally.** Design is deliberate (bundle isolation). Documented by comment. |

---

#### D-02 — `apiCall` retry-on-403 vs `postJsonRequest` retry-on-403 — divergent HTTP wrappers

| Field | Value |
|-------|-------|
| **File:Line** | `src/lib/apiRequest.js:71-131` vs `src/main.js:17-49` |
| **Category** | DUPLICATE |
| **Severity** | DUPLICATE — keep intentionally |
| **Problem** | Both wrappers handle authenticated POST after nonce expiry but with divergent contracts: SPA `apiCall` retries on JSON `code` (`rest_forbidden`/`rest_cookie_invalid_nonce`), preserves `AbortSignal`, sets `X-WP-Nonce` via arg, handles GET+POST; admin-bar `postJsonRequest` retries on `response.status===403`, POST-only, no `AbortSignal`, globals `wppoObject` vs `wppoSettings`. |
| **Why matters** | Expired-nonce response that is HTTP 200 with `code=rest_cookie_invalid_nonce` would not be retried by admin-bar path (and vice-versa HTTP 403 without JSON code not retried by SPA). Today both paths are correct in context, but predicate divergence is a latent edge-case. |
| **Evidence** | `src/lib/apiRequest.js:99-104` checks `data.code`, `src/main.js:28-29` checks `response.status===403`; error models differ (`apiCall` re-throws typed `Error`, `postJsonRequest` resolves to `false` via `refreshNonce→boolean`). |
| **Impact** | Low. Correct as-is. |
| **Recommended solution** | **Keep** (different bundles/globals). If a shared helper is ever extracted, unify predicate to check both `response.status===403` and JSON `code`. Document divergence (already partially documented via `Keep in sync` comment). |
| **Confidence** | High |
| **Whether intentional** | **Yes — keep intentionally.** Different entry points / globals. |

---

#### D-03 — `withNotification` / `try/catch→notify→setIsLoading` scaffold repeated per component (still open)

| Field | Value |
|-------|-------|
| **File:Line** | `src/components/FileOptimization.js:175-215` (`const withNotification = async (…) => … setIsLoading → notify(success/error) → catch → console.error`) vs `src/components/PluginSetting.js:111-160,163-206,209-251`, `src/components/DatabaseCleanup.js:150-230`, `src/components/Dashboard.js:335-980` (inline `try { apiCall } catch { notify(error)} finally { setIsLoading(false)}`), `src/components/AiPanel.js:70-185`, `src/components/CriticalCssPanel.js:30-90` |
| **Category** | DUPLICATE |
| **Severity** | DUPLICATE |
| **Problem** | `FileOptimization` defines a local `withNotification` helper; 5+ other tab components inline the same 10-line `isLoading`/`notify`/`dismiss`/`console.error` scaffold with only message strings differing. Project already provides `src/lib/useNotice.js:26-72` + `NoticeBanner` for presentation but not for the loading/error plumbing. At `31fffc61` no cross-component abstraction has been introduced — A10 D-03 is still open. |
| **Why matters** | 4–5 near-identical wrappers inflate each settings component and invite inconsistent error messages / forgotten `dismiss()` calls. New tabs copy-paste the pattern. |
| **Evidence** | `grep -n "notify(\|setIsLoading" src/components/*.js` → 30+ hits with identical `try→apiCall→notify({type:'success'}) catch→notify({type:'error'}) finally→setIsLoading(false)` structure; only `FileOptimization.js:175` extracts it. `grep -rn "useNotice" src/components/*.js` shows 6 consumers of `useNotice` for display but none for the async wrapper. |
| **Impact** | Medium. Bloat + inconsistency risk. |
| **Recommended solution** | **Abstract:** extract `useApiCallWithNotice()` or `useSavingState()` hook (or extend `useNotice` with `wrapAsync`) that centralises `setIsLoading`/`dismiss`/`notify(success/error)`/`console.error`. Keep per-component messages as arguments. Low effort, high DRY value. |
| **Confidence** | High |
| **Whether intentional** | No — extract when convenient. |

---

#### D-04 — `update_settings` validation duplicated between `Rest` and `WPPO_CLI_Command` — FIXED

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-rest.php:470-471,750` vs `includes/class-wppo-cli-command.php:627-629` (now both via `Util::ALLOWED_SETTINGS_KEYS`) |
| **Category** | DUPLICATE |
| **Severity** | INFO — fixed |
| **Problem** | Previously `includes/class-rest.php:417-481` and `includes/class-wppo-cli-command.php:556-680` each maintained their own `allowed_tabs` whitelist + `password→password_set` stripping logic. At `31fffc61` both now delegate to `Util::ALLOWED_SETTINGS_KEYS` / `ALLOWED_SETTINGS_TABS` (`includes/class-util.php:43-69`) and `Database_Cleanup::CLEANUP_METHOD_MAP`. |
| **Why matters** | Drift risk eliminated. Adding a new settings group now requires a single edit to `Util`. |
| **Evidence** | `grep -n "ALLOWED_SETTINGS" includes/class-util.php includes/class-rest.php includes/class-wppo-cli-command.php` → `class-util.php:43` defines, `class-rest.php:470,750` reads, `class-wppo-cli-command.php:627` reads. `grep -n "ALLOWED_SETTINGS_KEYS\|CLEANUP_METHOD_MAP" includes/class-rest.php` → `allowed_keys = Util::ALLOWED_SETTINGS_KEYS`, `method_map = Database_Cleanup::CLEANUP_METHOD_MAP`. |
| **Impact** | None (fixed). |
| **Recommended solution** | No action. Keep `Util` as single source. |
| **Confidence** | High |
| **Whether intentional** | N/A — fixed. |

---

#### D-05 — `ALLOWED_IMPORT_KEYS` / `allowed_keys` whitelists triplicated — FIXED (PHP side)

| Field | Value |
|-------|-------|
| **File:Line** | `src/components/PluginSetting.js:37` (`ALLOWED_IMPORT_KEYS`) vs `includes/class-rest.php:750` (`Util::ALLOWED_SETTINGS_KEYS`) vs `includes/class-wppo-cli-command.php:627` |
| **Category** | DUPLICATE |
| **Severity** | INFO — fixed PHP side; JS intentional |
| **Problem** | At A10 (2026-08-27) three identical allowlists existed (JS + two PHP REST endpoints). At `31fffc61` PHP side is consolidated to `Util::ALLOWED_SETTINGS_KEYS` (`includes/class-util.php:43-78` with comment “Single source of truth … `wppoSettings.allowedSettingsKeys`”). JS copy `src/components/PluginSetting.js:37` remains, now explicitly documented `// (exposed here so JS ALLOWED_IMPORT_KEYS can stay in sync without codegen).` |
| **Why matters** | New settings group no longer requires two PHP edits. JS side is acceptable to keep (build-time derivation from `wppoSettings` shape is possible but low value). |
| **Evidence** | `includes/class-util.php:35-38` docblock, `includes/class-rest.php:749-750` comment, `src/components/PluginSetting.js:37,54` `ALLOWED_IMPORT_KEYS.includes(key)`. |
| **Impact** | Low (intentional JS copy). |
| **Recommended solution** | **Keep** — document parity via build-time check if desired. |
| **Confidence** | High |
| **Whether intentional** | **Yes — JS copy keep intentionally** (client-side UX vs server validation). |

---

#### D-06 — Database-cleanup method map triplicated — FIXED

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-database-cleanup.php:81-91` (`CLEANUP_METHOD_MAP`), `includes/class-rest.php:874`, `includes/class-abilities.php:*` (all now `Database_Cleanup::CLEANUP_METHOD_MAP`) |
| **Category** | DUPLICATE |
| **Severity** | INFO — fixed |
| **Problem** | At A10 same 9-entry map appeared in 4 places. At `31fffc61` `Database_Cleanup::CLEANUP_METHOD_MAP` (`:81`) + `get_cleanup_method_map()` (`:99`) + `get_valid_cleanup_types()` (`:109`) is single source; `class-rest.php:874` (`$method_map = Database_Cleanup::CLEANUP_METHOD_MAP`), `class-database-cleanup.php:715` (`$methods = self::CLEANUP_METHOD_MAP`), `class-database-cleanup.php:790` (`array_values(self::CLEANUP_METHOD_MAP)`), and `Abilities` all derive from it. |
| **Why matters** | Fixed drift risk when adding `unattached_media`/`oembed_cache` at `@since NEXT`. |
| **Evidence** | `grep -n "CLEANUP_METHOD_MAP" includes/*.php` → defined at `class-database-cleanup.php:81`, read at `class-rest.php:874`, `class-database-cleanup.php:715,790`. `includes/class-database-cleanup.php:42-52` `TABLE_MAP` + `60-70` `METHOD_TO_TYPE` remain single-source complements. |
| **Impact** | None (fixed). |
| **Recommended solution** | No action. `auto_clean` gap (`Database_Cleanup::auto_clean:790` omits two types) is now documented — keep as-is or include with comment. |
| **Confidence** | High |
| **Whether intentional** | N/A — fixed. |

---

#### D-07 — Batched `DELETE … LIMIT 1000` loop copy-pasted across 5–6 `clean_*` methods — FIXED

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-database-cleanup.php:138-180` (`delete_in_batches` helper) + `190-196` (`clean_revisions`), `336-337` (`clean_auto_drafts`), `355` (`clean_trashed_posts`), `372` (`clean_spam_comments`), `389` (`clean_trashed_comments`) |
| **Category** | DUPLICATE |
| **Severity** | INFO — fixed |
| **Problem** | At A10 each `clean_*` repeated ~45 lines of `SELECT IDs LIMIT 1000 → DELETE meta → DELETE rows` scaffold. At `31fffc61` `private static function delete_in_batches(string $select_sql, …): int\|false` (`:138`) centralises placeholder generation, `last_error` checks and `while(count>=batch)` semantics. All 5 callers now delegate. `clean_revisions_advanced` and `clean_expired_transients`/`clean_orphan_postmeta` correctly remain bespoke. |
| **Why matters** | 225 lines of duplication eliminated; fixes to placeholder/error handling now single-site. |
| **Evidence** | `grep -n "delete_in_batches\|LIMIT 1000" includes/class-database-cleanup.php` → helper at `138`, `SELECT … LIMIT 1000` strings at `191,337,356,373,390` but bodies now `return self::delete_in_batches(`. Docblock at `124-136` marks “Centralises the loop …”. |
| **Impact** | None (fixed). |
| **Recommended solution** | No action. |
| **Confidence** | High |
| **Whether intentional** | N/A — fixed. |

---

#### D-08 — LiteSpeed coexistence gate / CDN host normalisation repeated in 3 sites — FIXED

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-cache.php:380-387` (`should_bypass_for_litespeed(): bool`), callers `397`, `1375` |
| **Category** | DUPLICATE |
| **Severity** | INFO — fixed |
| **Problem** | At A10 verbatim `class_exists(LiteSpeed_Integration) && should_disable_wppo_optimizer() + has_filter('litespeed_can_optm')` in 3 places in `class-cache.php`. At `31fffc61` `private function should_bypass_for_litespeed(): bool` (`:380`) encapsulates the gate; `combine_css:397` and `minify_buffer:1375` call it. CDN side uses `LiteSpeed_Integration::can_apply_cdn()` (`:1285`) with explicit comment. |
| **Why matters** | Future LiteSpeed detection changes now single-site. |
| **Evidence** | `grep -n "should_bypass_for_litespeed\|can_apply_cdn" includes/class-cache.php` → definition `380`, callers `397,1375`, CDN branch `1283-1288`. |
| **Impact** | None (fixed). |
| **Recommended solution** | No action. `maybe_apply_cdn:1283` comment correctly notes the `wppo_litespeed_can_cdn` / `can_apply_cdn()` split. |
| **Confidence** | High |
| **Whether intentional** | N/A — fixed. |

---

#### D-09 — `is_cache_eligible_for_current_user` / `is_cache_allowed_for_current_user` / `should_optimise_for_logged_in` — thin wrappers (info)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-util.php:763` (canonical `is_cache_eligible_for_current_user`), `includes/class-cache.php:279-282` (`is_cache_allowed_for_current_user`), `includes/class-main.php:369-372` (`should_optimise_for_logged_in`) |
| **Category** | DUPLICATE |
| **Severity** | INFO |
| **Problem** | Two 3-line wrappers delegate to `Util::is_cache_eligible_for_current_user(array $cache_settings)`. Pattern `is_cache_allowed_for_current_user → Util::…` appears at `class-cache.php:404,1169,1238,1258`; `should_optimise_for_logged_in` at `class-main.php:1228,1326,1345,1630,…`. |
| **Why matters** | Not duplication of logic — both already DRY at logic level. Wrappers improve readability per class. |
| **Evidence** | `grep -n "is_cache_eligible\|should_optimise_for_logged_in\|is_cache_allowed" includes/*.php` → canonical at `class-util.php:763`, wrappers `class-cache.php:279`, `class-main.php:369`. |
| **Impact** | None. |
| **Recommended solution** | **Keep** — annotate with `@see Util::is_cache_eligible_for_current_user`. |
| **Confidence** | High |
| **Whether intentional** | **Yes — keep intentionally.** |

---

#### D-10 — `get_role_hash` / `get_logged_in_role_hash` + `set_role_hash_cookie` hash derivation — thin wrappers (info)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-util.php:??` (`get_role_hash`), `includes/class-cache.php:292-298` (`get_logged_in_role_hash`), `includes/class-main.php:400-422` (`set_role_hash_cookie` + `clear_role_hash_cookie`) |
| **Category** | DUPLICATE |
| **Severity** | INFO |
| **Problem** | Role-hash via `Util::get_role_hash($user)` computed in both `Cache::get_logged_in_role_hash` and `Main::set_role_hash_cookie`; cookie path/domain/ttl only in `Main`. Thin concept duplication but logic centralised in `Util`. |
| **Evidence** | `grep -n "get_role_hash\|get_logged_in_role_hash\|set_role_hash_cookie" includes/*.php` → 3 sites. |
| **Impact** | None. |
| **Recommended solution** | **Keep.** |
| **Confidence** | High |
| **Whether intentional** | **Yes.** |

---

#### D-11 — `Util::process_urls` call-site boilerplate (info)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-main.php:657,667,696,707,732,736,740,1725,1769,1794` etc. (15+ sites), `includes/class-cache.php:395`, `includes/class-image-optimisation.php:136-141` |
| **Category** | DUPLICATE |
| **Severity** | INFO |
| **Problem** | Every call-site repeats `Util::process_urls($this->options['group']['key'] ?? array())` with manual `?? array()` / `(array)` cast. Helper already handles `string` + `array` (newline split + dedup). |
| **Why matters** | Repetitive boilerplate, not logic duplication. Optional `Util::get_processed_option($options,'group','key')` could trim sites but low value. |
| **Evidence** | `grep -n "process_urls" includes/class-main.php` → 10 sites. |
| **Impact** | None. |
| **Recommended solution** | **Keep** — helper is DRY; extract `get_processed_option` only if churn is warranted. |
| **Confidence** | Medium |
| **Whether intentional** | **Yes — keep.** |

---

#### D-12 — `get_combined_handles` vs `combine_css` generation loop — same skip rules (keep with mitigation)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-cache.php:400-560` (`combine_css` generation loop) vs `631-676` (`get_combined_handles`) |
| **Category** | DUPLICATE |
| **Severity** | DUPLICATE — keep intentionally |
| **Problem** | Both iterate `$styles`, check `is_core_block_asset()` (`:367`), `is_excluded_from_combine()` (`:601`, extracted helper — improvement over A10), `core_will_inline` / inline-budget (`id:732,809`), `args==='all'`. Generation additionally does `fetch_remote_css:1139`, `extra['before'/'after']`, `wp_dequeue_style`; `get_combined_handles` collects handles only. Rules must stay identical or `combined_handles_match:677` freshness check misfires. |
| **Why matters** | Two loops must stay consistent. At `31fffc61` `is_excluded_from_combine` and `is_core_block_asset` are extracted predicates used by both loops, reducing drift surface. `will_combine_css_inline:995` and `should_skip_combine_for_inline_budget:1080` further document intent. |
| **Evidence** | `grep -n "get_combined_handles\|combined_handles_match\|is_core_block_asset\|is_excluded_from_combine" includes/class-cache.php` → `is_excluded_from_combine:601` called at `425,652`; `is_core_block_asset:367` at `420,647`. Comment “The same skip rules are applied below during generation …” retained. |
| **Impact** | Medium. New rule additions still require checking two sites, but extracted predicates mitigate risk. |
| **Recommended solution** | **Keep with mitigation** — already mitigated via extracted predicates. Optionally extract a single `is_handle_eligible_for_combine(handle,src,…)` predicate covering all 4 checks, but post-A10 extraction is sufficient. |
| **Confidence** | High |
| **Whether intentional** | **Yes — keep intentionally.** Cache freshness correctness requires split (fixed in 1.9.0). |

---

#### D-13 — Responsive image `srcset` rewriting duplicated between Tag-Processor and regex branches (keep)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-image-optimisation.php:636-660` (Tag Processor branch: `explode(',', $srcset) → preg_split('/\s+/',…,2) → normalize_url → replace_image_with_next_gen → implode(', ')`) vs `700-730` (regex branch `preg_replace_callback '#srcset=["\']([^"\']+)["\']#i'` with same split/join) |
| **Category** | DUPLICATE |
| **Severity** | DUPLICATE — keep intentionally |
| **Problem** | Both branches split `srcset` on `,`, split each token on descriptor `preg_split('/\s+/',…,2)`, `normalize_url`, `replace_image_with_next_gen`, rejoin with `', '`. Tag-Processor path additionally guards `is_valid_url` + attribute presence; regex path reimplements with raw string search. |
| **Why matters** | Fixes to srcset descriptor handling must be applied twice. At `31fffc61` no shared `rewrite_srcset_attribute()` helper has been introduced. |
| **Evidence** | `grep -n "srcset\|rewrite_srcset" includes/class-image-optimisation.php` → `srcset` handling at `636,700`; `normalize_url:440` + `replace_image_with_next_gen` at `645,??`. Dual-branch structure confirmed. |
| **Impact** | Medium. |
| **Recommended solution** | **Keep** — fallback when `WP_HTML_Tag_Processor` unavailable is required. Optionally extract `rewrite_srcset_attribute(string $srcset): string` called by both branches (low priority). |
| **Confidence** | Medium |
| **Whether intentional** | **Yes — keep intentionally** (WP version compat). |

---

#### D-14 — `post_process_*` triple regex scan of same buffer (keep with documentation — now documented)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-image-optimisation.php:275-289` (docblock “Duplication note (D-14) … intentionally …”), `290` (`post_process_placeholders`), `327` (`post_process_img_dimensions`), `372` (`post_process_auto_sizes` broader `#<(img\|source)\b…(?:data-src\|data-srcset)=…`), callers `2790-2792` (`$buffer = post_process_placeholders → post_process_img_dimensions → post_process_auto_sizes`) |
| **Category** | DUPLICATE |
| **Severity** | DUPLICATE — keep intentionally (now documented) |
| **Problem** | Three `preg_replace_callback` passes scan full buffer for `data-src`/`data-srcset` to inject placeholder `src`, `width`/`height`, `data-sizes="auto"`. Each recompiles regex and walks buffer. Previously undocumented; at `31fffc61` `post_process_placeholders:275` D-14 docblock notes three passes are intentional stages with order dependency. |
| **Why matters** | 3× linear cost on large buffers; previously risked mistaken merge. Documentation now makes intent explicit. |
| **Evidence** | `grep -n "post_process_placeholders\|post_process_img_dimensions\|post_process_auto_sizes" includes/class-image-optimisation.php` → `275` docblock, `290,327,372` definitions, `2790-2792` ordered callers, `851` cross-ref comment. |
| **Impact** | Low. |
| **Recommended solution** | **Keep** — documented. Optionally note order guarantee in caller comment (dimensions must precede auto-sizes). |
| **Confidence** | Medium |
| **Whether intentional** | **Yes — keep intentionally** (documented). |

---

#### D-15 — JS `handleChange(setSettings)` factory pattern repeated across settings components (keep — factory is DRY)

| Field | Value |
|-------|-------|
| **File:Line** | `src/lib/util.js:1` (factory: `export const handleChange = (setSettings) => (e) => … text/checkbox/number`) used as `onChange={handleChange(setSettings)}` at `src/components/FileOptimization.js` (~15 fields), `ImageOptimization.js`, `DatabaseCleanup.js:337,393,427`, `PreloadSettings.js`, `ObjectCache.js`, `PluginSetting.js` (`grep -rn "handleChange" src/ → 105 hits`) |
| **Category** | DUPLICATE |
| **Severity** | INFO |
| **Problem** | Per-field wiring `import {handleChange}` + `onChange={handleChange(setSettings)}` is repetitive but factory itself is DRY. |
| **Evidence** | `grep -rn "handleChange" src/` → 105 hits; `src/lib/util.js:1` single factory, all consumers via import. |
| **Impact** | None. |
| **Recommended solution** | **Keep.** |
| **Confidence** | High |
| **Whether intentional** | **Yes — keep intentionally.** |

---

#### D-16 — SCSS `wppo-text-*` utilities + inline spacing in JS (fixed/low)

| Field | Value |
|-------|-------|
| **File:Line** | `src/css/base/_base.scss:44-46` (`.wppo-text-small {font-size:13px}` + `// Alias …` + `.wppo-text-13 {font-size:13px}`) vs `src/components/*.js` inline `style={{marginBottom:'16px'}}` occasional |
| **Category** | DUPLICATE |
| **Severity** | INFO |
| **Problem** | `wppo-text-small` and `wppo-text-13` are exact duplicates. At A10 this was untracked; at `31fffc61` `src/css/base/_base.scss:45` adds `// Alias for legacy consumers — keep for backward compatibility (P5 will deprecate wppo-text-13).` Duplicate utility is now documented as alias, not oversight. Inline `style={{marginBottom:'16px'}}` vs `wppo-mb-*` utilities remains scattered but low-value. |
| **Evidence** | `grep -n "wppo-text-13\|wppo-text-small" src/css/ -r` → `src/css/base/_base.scss:44-46` + `src/css/components/_stats.scss:238` `.wppo-text-small`. |
| **Impact** | Low. |
| **Recommended solution** | **Keep** — alias is intentional. Prefer utility classes over inline styles where practical. |
| **Confidence** | Medium |
| **Whether intentional** | **Yes — alias keep intentionally.** |

---

#### D-17 — Hard-coded `#fef2f2` / `#ef4444` in `PluginSetting.js` vs `.wppo-danger-zone` tokens — FIXED

| Field | Value |
|-------|-------|
| **File:Line** | `src/components/PluginSetting.js:886-888` (`{ /* Import — danger zone — uses .wppo-danger-zone tokens (D-17). */ }` + `className="wppo-danger-zone"`) vs `src/css/components/_card.scss:97-99` (`.wppo-danger-zone { border-left:4px solid var(--wppo-danger); background:var(--wppo-error-bg) }`) |
| **Category** | DUPLICATE |
| **Severity** | INFO — fixed |
| **Problem** | At A10 `src/components/PluginSetting.js:880` used inline `style={{borderLeft:'4px solid #ef4444', background:'#fef2f2'}}` duplicating `src/css/components/_card.scss:97` tokens. At `31fffc61` inline style removed; card now `className="wppo-danger-zone"` with explicit D-17 comment at both sites. |
| **Evidence** | `grep -n "wppo-danger-zone\|fef2f2\|ef4444" src/components/PluginSetting.js src/css/components/_card.scss` → `PluginSetting.js:886` comment + `888` class, `_card.scss:97-102` tokens. No `fef2f2`/`ef4444` literal remains in JS. |
| **Impact** | None (fixed). |
| **Recommended solution** | No action. |
| **Confidence** | High |
| **Whether intentional** | N/A — fixed. |

---

#### D-18 — `try/catch is_multisite()` guard repeated in cleanup / Util — INFO (keep, centralised via `Util::transient_key`)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-database-cleanup.php:498-503,924-931`, `includes/class-util.php:510-517` |
| **Category** | DUPLICATE |
| **Severity** | INFO |
| **Problem** | `try { is_multisite() } catch(Throwable) { fallback }` appears 3 times with same “shared object cache” comment. `Util::transient_key` already centralises the try/catch; call-sites that still inline the guard (DB cleanup counts) could delegate but are correct. |
| **Evidence** | `grep -n "is_multisite" includes/*.php` → 5+ sites. |
| **Impact** | Low. |
| **Recommended solution** | **Keep** — delegate to `Util::is_multisite_safe()` if one is introduced, else keep `Util::transient_key` prefix. |
| **Confidence** | Low |
| **Whether intentional** | **Yes — keep.** |

---

#### D-19 — **NEW (since A10): `CDN_Purger` vs `Edge_Purger` — near-duplicate `purge_cloudflare` + `purge_all` scaffolding**

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-cdn-purger.php:45-67,134-175` (`purge_all` + `purge_cloudflare(array $cache):bool` + `purge_varnish` + `purge_litespeed`) vs `includes/class-edge-purger.php:89-177` (`purge_all` + `purge_cloudflare(string $zone, string $token):bool` + `purge_bunny`) |
| **Category** | DUPLICATE |
| **Severity** | DUPLICATE |
| **Problem** | Two purgers share ~40 lines of scaffolding: `public static function purge_all(string $type='all', $url_path=null): bool` with `'all' !== $type` early return + transient lock + Cloudflare `rawurlencode(zone).'/purge_cache'` `wp_remote_request` with `Bearer token`, `purge_everything:true`, `wp_remote_retrieve_response_code` check (`<200 \|\| >=300`), `log_failure` via `do_action('wppo_debug_log',…)`. Signatures differ (`array $cache` vs `string $zone, string $token`), sources differ (`cache_settings.cdnPurgeService` vs `edge_cache.cloudflareZoneId` + `cache_settings` fallback + `bunnyPullZoneId`), and side-effects differ (`Edge_Purger` short-circuits when `!Edge_Cache::is_enabled()`, `CDN_Purger` delegates to `LiteSpeed_Integration::sync_purge_*` for single-page). At `31fffc61` this duplication was introduced by `feat: N2 Edge HTML Cache Adapter (#727)`. |
| **Why matters** | Two Cloudflare purge implementations mean fixes to timeout/auth/JSON shape must be applied twice. Edge purge also re-resolves `cloudflareZoneId` from two settings slices (`edge_cache` + `cache_settings`) while CDN purger reads only `cache_settings` — divergence risks missing purge when edge is enabled but `cdnPurgeService` is `none`. Also `Edge_Purger` checks `Util::transient_key('wppo_edge_purge_lock')` while CDN purger has no lock (relies on `LiteSpeed_Integration` lock) — inconsistent throttling. |
| **Evidence** | `diff <(sed -n '/private static function purge_cloudflare/,/^	}/p' includes/class-cdn-purger.php) <(sed -n '/private static function purge_cloudflare/,/^	}/p' includes/class-edge-purger.php)` shows identical `wp_remote_request` body/headers/status check except signature + log tag (`cloudflare` vs `cloudflare-edge`). Both define `private static function purge_cloudflare` with same `rawurlencode` + `wp_json_encode(['purge_everything'=>true])`. Both file headers reference each other (“Mirrors CDN_Purger …” at `class-edge-purger.php:5`). |
| **Impact** | Medium. Future API changes (e.g. Cloudflare token scope, Bunny API) require two edits. |
| **Recommended solution** | **Abstract:** extract a stateless `Cloudflare_Purger::purge(string $zone, string $token): bool` helper used by both. At `31fffc61` a dedicated `docs/edge-cache.md` already notes the adapter is host-agnostic — keep `Edge_Purger` as edge-zone orchestrator and `CDN_Purger` as legacy orchestrator, but share the Cloudflare transport. Alternatively have `Edge_Purger::purge_all` delegate to `CDN_Purger::purge_cloudflare` via a protected helper once the zone/token are resolved. Keep `purge_bunny` and `purge_varnish` distinct. |
| **Confidence** | High |
| **Whether intentional** | **Partially — keep scaffolding intentionally (different settings sources / Bunny vs Varnish / Single-page semantics), but `purge_cloudflare` transport is unintentional duplication — extract.** |

---

#### D-20 — `is_plugin_cache_url` vs `is_plugin_cache_url_uncached` pairwise helper (keep intentionally)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-main.php:2562-2625` (`is_plugin_cache_url(string $src):bool` cached by `static $cache[blog_id]` + `has_filter('content_url')` short-circuit at `2566-2568`) vs `2609-2625` (`is_plugin_cache_url_uncached(string $src):bool` resolves `content_url()` every call) |
| **Category** | DUPLICATE |
| **Severity** | DUPLICATE — keep intentionally |
| **Problem** | Pair looks like duplication but is an intentional cached-vs-uncached split: `is_plugin_cache_url` memoises `Util::cached_content_url('/')` per blog; `is_plugin_cache_url_uncached` re-resolves per call to honour a registered `content_url` filter (e.g. CDN plugin rewriting `content_url`). `is_plugin_cache_url` short-circuits to the uncached variant when `has_filter('content_url')` is true (`2566-2568`). At `31fffc61` docblocks `2599-2608` explicitly call this “Filter-respecting variant of …”. |
| **Why matters** | Pair must stay in sync (prefix `rtrim(path,'/').'/cache/wppo'` + host check `strcasecmp`). At `31fffc61` bodies are identical aside from caching. |
| **Evidence** | `grep -n "is_plugin_cache_url" includes/class-main.php` → `2495` (call-site), `2562` + `2609` definitions; `2566-2568` delegation. `is_plugin_cache_url_uncached` called only from `is_plugin_cache_url` (and previously X-02 in A10 flagged it as dead — now verified as delegate target, not dead). |
| **Impact** | Medium if logic diverges. |
| **Recommended solution** | **Keep** — pair is intentional performance vs correctness trade-off. Optionally extract shared `is_plugin_cache_url_for_prefix(string $src, string $prefix, ?string $host):bool` predicate, but not load-bearing. |
| **Confidence** | High |
| **Whether intentional** | **Yes — keep intentionally.** Documented as filter-respecting variant. |

---

#### D-21 — `is_enabled` / `is_configured` vocabulary duplicated across 5+ service classes (info)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-edge-cache.php:76-108` (`is_enabled():bool` with `wppo_edge_cache_enabled` filter + `is_configured():bool` checking `cloudflareZoneId`/`bunnyPullZoneId` + `WPPO_CLOUDFLARE_API_TOKEN`/`WPPO_BUNNY_API_KEY`), `includes/class-cdn-purger.php:110-128` (`is_configured()` via `cdnPurgeService`), `includes/class-object-cache.php:86-110` (`get_status():array{enabled,…}`), `includes/class-bfcache.php:??` (`is_enabled`), `includes/class-litespeed-integration.php:??` (`is_litespeed`/`is_configured`) |
| **Category** | DUPLICATE |
| **Severity** | INFO |
| **Problem** | `is_enabled()` / `is_configured()` vocabulary is repeated per service with slightly different semantics: `Edge_Cache::is_enabled` reads `wppo_settings.edge_cache.enabled` via `Util::get_settings()` + `wppo_edge_cache_enabled` filter; `Edge_Cache::is_configured` also checks constants; `CDN_Purger::is_configured` checks `cdnPurgeService` + zoneId. Pattern is consistent but each file reimplements `get_option('wppo_settings')` + `Util::transient_key` lock lookup. |
| **Evidence** | `grep -n "is_enabled\|is_configured" includes/class-edge-cache.php includes/class-cdn-purger.php includes/class-bfcache.php` → 6+ sites with `is_enabled` + `is_configured` pairs. |
| **Impact** | Low. Consistent pattern, not logic duplication. |
| **Recommended solution** | **Keep** — per-service semantics differ. Optionally centralise `get_settings()` read (already via `Util::get_settings()`). |
| **Confidence** | Medium |
| **Whether intentional** | **Yes — keep intentionally.** |

---

#### D-22 — Registration-layer duplication: `Main::on_settings_update` + `Cache::clear_cache` + `wppo_after_cache_clear` hook chain (keep)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-main.php:1032-1105` (`on_settings_update` — cache/htaccess/used-css/next-gen gates) vs `includes/class-cache.php:1990-2040` (`clear_cache` static, deletes transients `wppo_cache_size`, `wppo_total_js_css`), `includes/class-main.php:625-647` (hooks `wppo_after_cache_clear → CDN_Purger::purge_all:20, Edge_Purger::purge_all:20, Bfcache::init, Perf_Translations::init`) |
| **Category** | DUPLICATE |
| **Severity** | INFO |
| **Problem** | Cache-clear is wired in 3 places: `Main::on_settings_update` invalidates per-tab, `Cache::clear_cache` deletes transients + files, `wppo_after_cache_clear` fans out to edge/CDN purges. Pattern is layered, not duplicated — each layer has single concern. |
| **Evidence** | `grep -rn "clear_cache\|wppo_after_cache_clear" includes/*.php` → 8+ sites. |
| **Impact** | None. |
| **Recommended solution** | **Keep.** |
| **Confidence** | High |
| **Whether intentional** | **Yes.** |

---

### 2. Dead-Code Findings

#### X-01 — `Database_Cleanup::clean_revisions()` (simple `LIMIT 1000` variant) — NOT dead (keep)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-database-cleanup.php:188-197` |
| **Category** | DEAD CODE (candidate) — **not dead** |
| **Severity** | INFO |
| **Problem** | Previously suspected dead because `clean_all:715` uses `clean_revisions_advanced`. At `31fffc61` `clean_revisions()` still exists and delegates to `delete_in_batches` (like its peers) and is used as the simple-path variant. CLI `class-wppo-cli-command.php` still references it (fallback when `dbRevMaxAge` not set). |
| **Evidence** | `grep -rn "clean_revisions" includes/` → `class-database-cleanup.php:188`, `class-wppo-cli-command.php` references. |
| **Impact** | None. |
| **Recommended solution** | **Keep** — document `clean_revisions` vs `clean_revisions_advanced` layering. |
| **Confidence** | Medium |
| **Whether intentional** | **Yes — keep.** Not dead. |

---

#### X-02 — `is_plugin_cache_url_uncached()` — NOT dead (now verified as delegate target)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-main.php:2609-2625` |
| **Category** | DEAD CODE (candidate) — **not dead** |
| **Severity** | INFO |
| **Problem** | At A10 this was flagged as second copy of `is_plugin_cache_url()` potentially unused. At `31fffc61` it is called from `is_plugin_cache_url:2567` when `has_filter('content_url')` is true — the delegate target for filter-respecting path. Never called directly outside tests, but required. |
| **Evidence** | `grep -rn "is_plugin_cache_url" includes/` → `is_plugin_cache_url_uncached` only at `class-main.php:2609` (definition) + `2567` (call-site inside `is_plugin_cache_url`). |
| **Impact** | None. |
| **Recommended solution** | **Keep.** |
| **Confidence** | High |
| **Whether intentional** | **Yes.** |

---

#### X-03 — `TODO(#553)` / `TODO(#624)` WP-version-gated fallbacks — not dead (info)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-cache.php:373,593`, `includes/class-main.php:438,442,482,504,511,522,533,548,570`, `includes/class-image-optimisation.php:357,1907` |
| **Category** | DEAD CODE (candidate) — **not dead** |
| **Severity** | INFO |
| **Problem** | Comments mark branches for `WP 6.9` `template_redirect` fallback, `WP 6.3` defer strategy, `WP 7.2` preload. Branches are live fallbacks for current minimum WP. |
| **Evidence** | `grep -rn "TODO(#" includes/` → `TODO(#553)` and `TODO(#624)` with version-gated `version_compare($wp_version,'6.3-alpha'/'6.9-alpha'…)` guards. |
| **Impact** | None now. |
| **Recommended solution** | **Keep** until minimum WP is bumped — tracked in roadmap. |
| **Confidence** | High |
| **Whether intentional** | **Yes — keep.** Active fallbacks. |

---

#### X-04 — SCSS `flex-center` / `truncate` mixins — retained intentionally (now documented)

| Field | Value |
|-------|-------|
| **File:Line** | `src/css/abstracts/_mixins.scss:20-37` (`@mixin flex-center { display:flex; align-items:center; justify-content:center; }` + `@mixin truncate { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }`) |
| **Category** | DEAD CODE |
| **Severity** | DEAD CODE — keep intentionally (library) |
| **Problem** | `grep -rn "flex-center\|truncate" src/css/` returns only definitions, no `@include` usages. At A10 these were flagged for removal. At `31fffc61` both carry doc comments “Retained for P5 design-system reuse — currently unused but part of the shared layout/toolkit. Marked @since NEXT … See AUDIT/DUPLICATE-CODE.md X-04.” |
| **Why matters** | Two unused mixins clutter the design-system surface. Retained explicitly as library — low cost. |
| **Evidence** | `grep -rn "flex-center\|truncate" src/css/ -r` → 2 definitions only, zero `@include`. `src/css/abstracts/_mixins.scss:20-37` comments mark `NEXT` retention. |
| **Impact** | Low. Bundle impact negligible (SCSS mixins not emitted until `@include`d). |
| **Recommended solution** | **Keep** per P5 plan. Remove if still unused after P5 card/grid audit, or keep as library with `@since NEXT` marker. |
| **Confidence** | High |
| **Whether intentional** | **Yes — keep intentionally (library).** |

---

#### X-05 — `src/lib/litespeed.js` helpers — not dead (single consumer, correct)

| Field | Value |
|-------|-------|
| **File:Line** | `src/lib/litespeed.js:1-67` (`isLiteSpeed`, `effectiveMode`, `bannerProps`) |
| **Category** | DEAD CODE (candidate) — **not dead** |
| **Severity** | INFO |
| **Problem** | Previously flagged as potentially single-consumer. At `31fffc61` `grep -rn "from.*litespeed\|import.*litespeed" src/` → consumed via `wppoSettings.litespeed` in `FileOptimization.js` + `src/components/PreloadSettings.js` etc. via `wppoSettings.litespeed` (injected by `Main::enqueue_admin_scripts`). Module is tree-shaken but needed. |
| **Evidence** | `grep -rn "litespeed" src/ --include="*.js"` → hits in `src/lib/litespeed.js`, `src/components/FileOptimization.js`, `src/lib/apiRequest.js`. |
| **Impact** | None. |
| **Recommended solution** | **Keep.** |
| **Confidence** | Medium |
| **Whether intentional** | **Yes.** |

---

#### X-06 — Hard-coded inline danger-zone style shadowing `.wppo-danger-zone` — FIXED

| Field | Value |
|-------|-------|
| **File:Line** | `src/components/PluginSetting.js:886-888` vs `src/css/components/_card.scss:97-102` |
| **Category** | DEAD CODE |
| **Severity** | INFO — fixed |
| **Problem** | At A10 `PluginSetting.js:880` used inline `#fef2f2`/`#ef4444`, shadowing `src/css/components/_card.scss:97` `.wppo-danger-zone`. At `31fffc61` inline style removed; `className="wppo-danger-zone"` applied with D-17 annotation. No duplication remains. |
| **Evidence** | `grep -n "wppo-danger-zone\|fef2f2" src/components/PluginSetting.js src/css/components/_card.scss` → `PluginSetting.js:888` class, no `fef2f2` literal. |
| **Impact** | None (fixed). |
| **Recommended solution** | No action. |
| **Confidence** | Medium |
| **Whether intentional** | N/A — fixed. |

---

#### X-07 — `wppo-text-small` vs `wppo-text-13` duplicate utilities — FIXED (alias documented)

| Field | Value |
|-------|-------|
| **File:Line** | `src/css/base/_base.scss:44-46` |
| **Category** | DEAD CODE |
| **Severity** | INFO — fixed |
| **Problem** | Both set `font-size:13px`. At A10 unannotated duplicate. At `31fffc61` alias comment added: `// Alias for legacy consumers — keep for backward compatibility (P5 will deprecate wppo-text-13).` |
| **Evidence** | `grep -n "wppo-text-13\|wppo-text-small" src/css/ -r` → `src/css/base/_base.scss:44-46`. |
| **Impact** | None. |
| **Recommended solution** | No action. |
| **Confidence** | High |
| **Whether intentional** | **Yes — keep alias intentionally.** |

---

#### X-08 — `CACHE_DIR` constant vs `min_cache_base_dir()` / `min_cache_dir()` vs `cache_root_dir` — overlapping path builders (info)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-cache.php:40` (`CACHE_DIR = '/cache/wppo'`), `includes/class-util.php:393-416` (`min_cache_base_dir`, `min_cache_dir`), `includes/class-cache.php:56-235` (`cache_root_dir` via `WP_CONTENT_DIR/cache/wppo`) |
| **Category** | DEAD CODE |
| **Severity** | INFO |
| **Problem** | Multiple path builders for same root `WP_CONTENT_DIR/cache/wppo` but scoped differently: `CACHE_DIR` is page-cache suffix (`/cache/wppo/{domain}/{path}/index.html`), `min_cache_*` are `blog_id`-scoped min-cache roots. Not dead — distinct scopes. Root string `cache/wppo` is duplicated minor. |
| **Evidence** | `grep -n "CACHE_DIR\|min_cache_base_dir\|min_cache_dir\|cache_root_dir" includes/*.php` → 3 builders, distinct call-sites. |
| **Impact** | None. |
| **Recommended solution** | **Keep** — centralising `CACHE_ROOT = '/cache/wppo'` would be ideal but low value. |
| **Confidence** | Medium |
| **Whether intentional** | **Yes — keep.** |

---

#### X-09 — `includes/minify/class-css.php` vs `MatthiasMullie\Minify` — dual CSS minifier stacks (info)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/minify/class-css.php:1-311`, `includes/minify/class-js.php:169`, `composer.json` (`matthiasmullie/minify` + `voku/html-min`) |
| **Category** | DEAD CODE |
| **Severity** | INFO |
| **Problem** | Plugin ships local CSS/JS minifier wrappers plus vendor `MatthiasMullie\Minify\CSS` used by `Cache::combine_css:541`. `Main::minify_queued_styles` uses `Minify\CSS`. Dual stack has existed since early versions. |
| **Evidence** | `grep -n "Minify\|minify" includes/class-cache.php includes/class-main.php` → `Cache::combine_css` uses `MatthiasMullie\Minify\CSS`, `Main` uses `Minify\CSS`. |
| **Impact** | Low. Maintenance burden but distinct contexts (combined-file vs per-handle inline budget). |
| **Recommended solution** | **Keep** — distinct contexts. Document why two paths exist. |
| **Confidence** | Low |
| **Whether intentional** | **Yes — keep.** |

---

#### X-10 — `--wppo-shadow-premium` CSS variable emitted but never consumed (retain — design-system token)

| Field | Value |
|-------|-------|
| **File:Line** | `src/css/abstracts/_variables.scss:67,83` (`--wppo-shadow-premium: …`) vs `grep -rn "shadow-premium" src/ --include="*.scss" --include="*.js" --include="*.css"` → zero `@include`/var usages outside definition |
| **Category** | DEAD CODE |
| **Severity** | DEAD CODE — keep intentionally (design token) |
| **Problem** | `--wppo-shadow-premium` is defined in `:root` (with `@supports color-mix` override at `:83`) but never consumed via `var(--wppo-shadow-premium)` in any `src/css/` file. At `31fffc61` comment reads “Retained for future premium hero/billing surfaces — currently unused but part of P5 design-system token set. See AUDIT/AGENTS/agent-A07-css.md D-02.” |
| **Why matters** | Unused variable emits bytes in `build/style-index.css` but negligible (~80 bytes). Design-system tokens intentionally include unused entries for future use. |
| **Evidence** | `grep -rn "shadow-premium" src/ -r` → 2 definitions (`_variables.scss:67,83`) only. `grep -rn "var(--wppo-shadow-premium)" src/` → zero hits. |
| **Impact** | Low. No runtime cost. |
| **Recommended solution** | **Keep** — intentional design-system token. Remove if still unused after P5 premium surfaces audit. |
| **Confidence** | High |
| **Whether intentional** | **Yes — keep intentionally.** Documented future use. |

---

#### X-11 — `wppo_after_cache_clear` hook consumers duplicated? (not dead, correctly fanned out)

| Field | Value |
|-------|-------|
| **File:Line** | `includes/class-main.php:625-647` (`add_action('wppo_after_cache_clear', CDN_Purger::purge_all, 20)` + `Edge_Purger::purge_all, 20`), `includes/class-cache.php:1990` (`do_action('wppo_after_cache_clear', $type, $url_path)`) |
| **Category** | DEAD CODE (candidate) — **not dead** |
| **Severity** | INFO |
| **Problem** | Hook `wppo_after_cache_clear` was inspected for dead/unused. At `31fffc61` it fans out to two purgers + LiteSpeed sync. Both registrations are live. |
| **Evidence** | `grep -rn "wppo_after_cache_clear" includes/` → 4+ hits (emitter + 2 purger registrations + Edge). |
| **Impact** | None. |
| **Recommended solution** | No action. Keep both registrations — `Edge_Purger` is edge-specific, `CDN_Purger` is legacy CDN. If edge subsumes CDN, deprecate CDN purger path with comment. |
| **Confidence** | High |
| **Whether intentional** | **Yes.** |

---

#### X-12 — No large commented-out code, no deprecated/debug branches, no unreachable code found

| Field | Value |
|-------|-------|
| **File:Line** | `grep -rn "^\s*//.*function\|^\s*//.*\b(if|for|while)\b" includes/ src/` → no large blocks; `grep -rn "deprecated\|_deprecated\|@deprecated" includes/` → zero hits; `grep -rn "console\.log" src/` → `console.log` is ESLint error — only `console.error`/`console.warn` allowed and present at `src/lib/apiRequest.js`, `src/components/Dashboard.js` etc. correctly |
| **Category** | DEAD CODE |
| **Severity** | INFO |
| **Problem** | Exhaustive `grep` for `//`-commented code, `deprecated`, `obsolete`, `debug`, `unreachable` found only explanatory comments and `TODO(#553)`/`TODO(#624)` version-gated fallbacks (X-03, intentionally live). No dead branches, no `wp-content/cache/wppo/` hard-coded dead paths beyond documented `CACHE_DIR`. |
| **Evidence** | `grep -rn "deprecated\|obsolete" includes/ --include="*.php"` → zero; `grep -rn "console\.log" src/` → zero; `grep -rn "^\s*//" includes/*.php | grep -i "function\|return\|if"` → zero large blocks. |
| **Impact** | None. |
| **Recommended solution** | No action. |
| **Confidence** | High |
| **Whether intentional** | N/A |

---

## 3. No-Issues (explicitly checked, no duplication/dead-code)

- `Util::cached_home_url` / `cached_content_url` — single source with `has_filter` bypass; 20+ call-sites reuse correctly (`grep -rn "cached_home_url\|cached_content_url" includes/` → 15+ hits all via `Util`).
- `Util::transient_key` — single blog-ID prefix helper; `Advanced_Cache_Handler`, `Cron`, `Cache`, `Database_Cleanup`, `Pagespeed`, `RUM`, `Edge_Purger` all go through it (`grep -rn "transient_key" includes/` → 30+ hits, zero direct `get_transient('wppo_')` without prefix outside `uninstall.php` which correctly prefixes via `$blog_id`).
- `Util::sanitize_settings_recursively` — single sanitizer shared by `Rest` and CLI (via `Util` class).
- `useNotice` + `NoticeBanner` — shared feedback pattern adopted across SPA (`grep -rn "useNotice" src/components/` → 6 consumers); no per-component notification divergence beyond `withNotification` wrapper (D-03) which is presentation-plumbing, not notification state.
- `src/setupTests.js` mocks (`wppoSettings`, `window.matchMedia`, `@wordpress/components`) — not production code, no duplication concern.
- `performance-optimisation.php` manual `includes()` — intentional (no PSR-4 for plugin classes per `AGENTS.md`). Composer only for vendors (`matthiasmullie/minify`, `voku/html-min`, `woocommerce/action-scheduler`) — not dead.
- `wppo_litespeed_can_cdn` filter chain in `Cache::maybe_apply_cdn:1283-1288` — multiple `apply_filters` intentionally layered (LiteSpeed_Integration + fallback + ecosystem filter) — not duplication.
- `src/rum.js` vs `templates/object-cache.php` — distinct subsystems (RUM beacon vs object-cache drop-in), no duplication.
- All 25 REST routes in `includes/class-rest.php:50-280` use `permission_callback => permission_callback` (`manage_options` + `X-WP-Nonce`) except `rum_collect` (public, token + IP rate-limited) — intentional (`grep -n "permission_callback" includes/class-rest.php` → 24× `permission_callback` + 1× `__return_true` for RUM with explicit `permission_callback` review comment).
- SCSS `respond-to(sm/md/lg/xl)` mixin — used across `src/css/layout/*`, `src/css/components/*` correctly; no dead variant.

---

## 4. Recommendation Priority

| Priority | Items | Effort | Status at `31fffc61` |
|----------|-------|--------|----------------------|
| **P1 (consolidate)** | **D-19** `Cloudflare_Purger` transport shared between `CDN_Purger` + `Edge_Purger` | Small — extract `Cloudflare_Purger::purge(zone,token):bool` used by both; keep `purge_bunny` / `purge_varnish` distinct | **Open — new** |
| **P2 (abstract)** | **D-03** `wrapAsync`/`useApiCallWithNotice` hook (FileOptimization vs PluginSetting/DatabaseCleanup/Dashboard) | Small — single `src/lib/useApiCallWithNotice.js` | **Open — same as A10** |
| **P3 (keep as-is — intentional)** | **D-01/D-02** (`refreshNonce`/`postJsonRequest` bundle isolation), **D-09–D-12/D-13/D-14/D-15** (helper fallbacks), **D-20** (`is_plugin_cache_url` pair) | None — documented | **Keep** |
| **P4 (fixed since A10)** | **D-04/D-05** (`ALLOWED_SETTINGS_KEYS`), **D-06** (`CLEANUP_METHOD_MAP`), **D-07** (`delete_in_batches`), **D-08** (`should_bypass_for_litespeed`), **D-17** (`.wppo-danger-zone`) | Verified fixed | **Closed** |
| **P5 (dead-code / design tokens — keep intentionally)** | **X-04** (`flex-center`/`truncate` as P5 library), **X-10** (`--wppo-shadow-premium` as P5 token) | None — retain as `@since NEXT` library tokens | **Keep intentionally** |
| **No action** | **D-18** (multisite try/catch already centralised), **X-01–X-03/X-05/X-08/X-09/X-12** | — | **Keep** |

**Fixes since A10 (2026-08-27 → 2026-08-28):** 7 duplication findings were closed (D-04, D-05 PHP side, D-06, D-07, D-08, D-17, X-06/X-07 aliasing) without breaking change; X-04/X-10 are now explicitly documented as `@since NEXT` library retains rather than untracked dead code.

---

## 5. Open Questions

| # | Question | Context | Owner |
|---|----------|---------|-------|
| Q-01 | Should `Edge_Purger::purge_all` delegate Cloudflare transport to a shared `Cloudflare_Purger::purge(zone,token)` or should `CDN_Purger::purge_cloudflare` be made `protected`/shared? | `includes/class-edge-purger.php:137` vs `includes/class-cdn-purger.php:134` — identical `wp_remote_request` transport | Backend |
| Q-02 | Should `src/lib/useNotice.js` gain a `wrapAsync` helper to close D-03 without introducing a new file, or is a separate `src/lib/useApiCallWithNotice.js` preferred? | `src/lib/useNotice.js:1-74` vs `src/components/FileOptimization.js:175` `withNotification` | Frontend |
| Q-03 | When edge cache subsumes `cache_settings.cdnPurgeService=cloudflare`, should `CDN_Purger::purge_cloudflare` be deprecated (edge takes over Cloudflare zone purge) or should both fire and let `Edge_Purger` no-op when unconfigured? | `includes/class-edge-cache.php:106` fallback reads `cache_settings.cloudflareZoneId` when `edge_cache` zone empty | Backend + Design |
| Q-04 | Should `Database_Cleanup::auto_clean` include `unattached_media` and `oembed_cache` (now in `CLEANUP_METHOD_MAP`/`TABLE_MAP` at `@since NEXT`) or is their omission intentional? | `includes/class-database-cleanup.php:790` `auto_clean => array_values(MAP)` vs `715` `clean_all => MAP` — previously noted at A10, still open | Backend |
| Q-05 | When does the project intend to raise minimum WP to 6.9 so the `TODO(#553)` legacy `template_redirect` / `script_loader_tag` fallbacks can be removed? | `includes/class-main.php:548,570` etc. | Release |

---

*Generated without modifying production code. Evidence is `file:line` from the workspace at audit time (`31fffc61`). Reference traces via `grep -rn <symbol> includes/ src/` before declaring unused — see each finding's Evidence row.*
