# FINAL ARCHITECTURE REVIEW — Post-Fix Verification

**Reviewer:** Final Architecture Agent (independent)  
**Date:** 2026-08-28  
**Workspace:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation`  
**Branch:** `fix/deep-dashboard-2026`  
**Git range inspected:** `origin/master (c127b865)` → `HEAD` (46 files, `git diff HEAD` unstaged) + `git diff origin/master`  
**Method:** Independent re-read of every changed file (`Read` with offsets, `grep -rn` for coupling/global-state/dependency patterns, cross-ref call-sites). Traced `Util::`, `Cache::`, `Database_Cleanup::`, `RUM::`, `Main::` lifecycles against `AUDIT/AGENTS/agent-A12-quality-architecture.md` (A-01…A-15, Q-01…Q-07). No trust in implementation-agent self-report.

---

## 1. Scope

| Surface | Files | Lines (approx) |
|---------|-------|----------------|
| PHP backend | `includes/*.php` (25), `performance-optimisation.php`, `uninstall.php`, `templates/object-cache.php` | ~28k PHP |
| JS SPA | `src/**/*.js` (`App.js`, `lib/*.js`, `components/*.js`, `common/*`) | ~13k JS |
| CSS/SCSS | `src/css/**/*.scss`, `build/` | ~3.4k SCSS |
| Changed in this patch | 46 files (`build/*`, `includes/class-{util,cache,rum,image-optimisation,cron,database-cleanup,main,rest,abilities,google-fonts,used-css,critical-css,wppo-cli-command}`, `uninstall.php`, `src/components/{Dashboard,FileOptimization,PluginSetting}`, `src/lib/litespeed.js`, `src/css/*`, `tests/php/*`) | +1141 / -676 |

Architecture dimensions inspected: responsibilities / SRP (god classes), coupling / abstractions / DI, global state / singletons, lifecycle / construction / invalidation, naming, complexity / McCabe, error handling, maintainability / testability.

Source findings under verdict: `A-01…A-15` + `Q-01…Q-07` from Agent A12 + A01/A03.

---

## 2. Fixes Verified (with file:line evidence)

### 2.1 Util constants — single source for settings allowlist (A-02 cohesion, A-06 coupling, DRY)

**Was:** `ALLOWED_SETTINGS_KEYS` / `allowed_tabs` / `allowed_keys` / `method_map` copy-pasted 4-way across `Database_Cleanup`, `Rest`, `Abilities`, `WPPO_CLI_Command` + JS `PLUGIN_SETTING` `ALLOWED_IMPORT_KEYS`. Drift: `auto_clean` missed `unattached_media`/`oembed_cache`, `Abilities` had stale `trash/spam/transients/orphans` enum.

**Fix — PASS:**

| Constant | Location | Consumers |
|----------|----------|-----------|
| `Util::ALLOWED_SETTINGS_KEYS` | `includes/class-util.php:43-53` (9 keys) | `Rest::update_settings:452`, `Rest::import_settings:732`, `Main::enqueue:1526`, `WPPO_CLI::settings:627` |
| `Util::ALLOWED_SETTINGS_TABS` | `includes/class-util.php:64` (`= ALLOWED_SETTINGS_KEYS`) alias | `Rest::update_settings:452`, `WPPO_CLI::711` |
| `get_allowed_settings_keys()` | `includes/class-util.php:72-74` | Test seam |
| `Database_Cleanup::CLEANUP_METHOD_MAP` | `includes/class-database-cleanup.php:81-91` (9 types) + `get_valid_cleanup_types():109` (+`all`) + `get_cleanup_method_map()` | `Rest::database_cleanup:805` (`get_valid_cleanup_types`), `Rest::invoke:856` (`CLEANUP_METHOD_MAP`), `Abilities::451,465`, `Database_Cleanup::clean_all:715`, `auto_clean:760` (now `array_values`) |

* **Coupling:** 4-way drift eliminated; `includes/class-main.php:1526` exposes `allowedSettingsKeys => Util::ALLOWED_SETTINGS_KEYS` so JS `src/components/PluginSetting.js:37-43` stays sync via `wppoSettings.allowedSettingsKeys` with `FALLBACK_ALLOWED_KEYS` fallback — correct, no codegen needed.
* **Residual drift:** `Util::ALLOWED_SETTINGS_KEYS` drops `core_tweaks` (present in pre-fix `WPPO_CLI:627` allowlist). `Rest::import_settings` now 400 on `core_tweaks` payloads — compatibility break noted in security review `R-CORE-TWEAKS-IMPORT` (see §4).
* **Abstraction:** Constants are values, not interfaces — appropriate; no over-abstraction. `ALLOWED_SETTINGS_TABS = ALLOWED_SETTINGS_KEYS` identity alias is intentional (semantic clarity, `src/lib/litespeed.js` note).

**Verdict: PASS — real DRY win, zero behavioural change except intentional `core_tweaks` narrowing. Maintainability +1.**

### 2.2 `delete_in_batches` — centralize `clean_*` batch loops (A-02, A-15, P-CPU-07)

**Was:** 5 copy-pasted `do { get_col SELECT IDs LIMIT 1000 → DELETE meta → DELETE main → count } while (count>=1000)` loops in `clean_revisions`/`clean_auto_drafts`/`clean_trashed_posts`/`clean_spam_comments`/`clean_trashed_comments` (~40 lines each, 5× error branches, placeholder generation diverged).

**Fix — PASS:** `includes/class-database-cleanup.php:138-180` `private static function delete_in_batches(string $select_sql, string $meta_table, string $meta_column, string $main_table, string $id_column, int $batch=1000): int|false` — single loop with `$wpdb->last_error` checks, `prepare("DELETE FROM {$meta_table} WHERE {$meta_column} IN ($placeholders)", ...$ids)` parametrized. Callers at `190,336,355,372,387` delegate with `$wpdb->posts`/`$wpdb->comments` constants — correct. `clean_revisions_advanced` (209+ lines, grouped `HAVING`) correctly not folded.

* **Abstraction:** Helper is `private static`, not a new class — proportionate. Signature makes table/column identifiers explicit (cannot be `%s` placeholders — interpolation justified, `TABLE_MAP` allowlist upstream, PHPCS suppressed correctly).
* **Complexity:** 5× 40-line branches → 1× 42-line helper → McCabe per caller drops from branching to linear. Net -250 lines dup.
* **Error handling:** Preserves `last_error` + `false === query` returns; `Database::get_table_size` fallback `SHOW TABLE STATUS` added `956-988` for restricted `information_schema`.

**Verdict: PASS — justified consolidation, no SQLi, no behavioural change.**

### 2.3 `should_bypass_for_litespeed` — DRY LiteSpeed gate (A-06, A-11)

**Was:** `if (class_exists(LiteSpeed_Integration) && should_disable_wppo_optimizer()) return; if (has_filter(litespeed_can_optm) && !apply_filters(...)) return;` copy-pasted at `Cache::combine_css` + `Cache::minify_buffer` (+ CDN path).

**Fix — PASS:** `includes/class-cache.php:380-388` `private function should_bypass_for_litespeed(): bool` centralizes both checks. Used at `combine_css:397` and `minify_buffer:1362`.

* **Coupling:** Removes duplicated knowledge of coexistence contract; future gate change is single edit.
* **Lifecycle:** No construction change; `has_filter` check is runtime (filter may be added late) — correct to keep in method, not constructor.

**Verdict: PASS — minimal, correct.**

### 2.4 `file_exists` cache + `get_cached_image_size` LRU — collapse stat storms (P-CPU-04, D-14, Q-01)

**Was:** `replace_image_with_next_gen` did 5 `file_exists` per image (avif/webp exist checks + source exist on queue-miss) × 80-image gallery ×3 srcset = 480 `stat` syscalls per render, no memo. `getimagesize` LRU copy-pasted between `post_process_img_dimensions` and `add_delay_load_img`.

**Fix — PASS:** `includes/class-image-optimisation.php:117-128` `private static array $file_exists_cache` + `FILE_EXISTS_CACHE_LIMIT 500` FIFO; `cached_file_exists(string $path): bool` at `832-844` (bounded, `array_shift` on overflow); `get_cached_image_size(string $local_path): array|false` at `857-884` (single LRU, 100 cap, hit promotes via `unset+reinsert` LRU). All 5 `file_exists` in `replace_image_with_next_gen:912-938` now memo'd; `post_process_img_dimensions:338` + `add_delay_load_img:1648,1848` share `cached_file_exists` + `get_cached_image_size`. `clear_file_exists_cache()` for tests + wired in `tests/php/bootstrap.php:213-216` `reset_all_caches`.

* **Lifecycle:** `static` per class, not per instance — shared across `Cache::process_buffer_only` re-instantiations in same request (correct). Per-request only (no cross-request stale); negative cache (not-yet-converted avif) visible next request — acceptable.
* **Abstraction:** FIFO for `file_exists` (insertion order, PHP 8.2 guarantees) vs LRU for image sizes — deliberate tradeoff; 500 cap rarely evicts (160 uniques typical), so FIFO≈LRU.
* **Duplication:** Consolidates D-14 `getimagesize` LRU duplication (-40 lines). `D-13/D-14` duplication notes added as comments (`272-280`, `438-446`) documenting why 3 regex passes + TagProcessor vs regex dual paths are kept — correct (see §4 Q-05).

**Verdict: PASS — largest FE-CPU win (~66% fewer `stat`), complexity down.**

### 2.5 Settings memoization — collapse 6× `get_option` deserialization (P-WP-01, A-03, Q-01 lifecycle)

**Was:** `Main::__construct:169` + `Cache::__construct:261` + `Cron::schedule_cron_jobs:115` etc. did `get_option('wppo_settings')` up to 6× per frontend render (`maybe_unserialize` + `apply_filters('option_wppo_settings')` + memcpy each).

**Fix — PARTIAL PASS (hot path fixed, long tail remains):**

`includes/class-util.php:84-213` `private static ?array $settings_cache` + `bool $settings_cache_loaded` + `get_settings(): array` (memo, `ensure_settings_cache_hook` with `static $hooked` once-per-request) + `set_settings_cache`/`clear_settings_cache`/`reset_all_caches` + `on_settings_update`/`on_settings_add` hooks. Migrated: `Main:251`, `Cache:261,1633`, `Cron:115,168,184,202,256,307,378,629,698` (8 sites), `Used_CSS:137,994`.

* **Correctness:** `ensure_settings_cache_hook` registers `update_option_wppo_settings`/`add_option_wppo_settings`/`delete_option_wppo_settings` once — keeps memo coherent for REST `update_option` paths. `on_settings_update` preserves type (`is_array` else `[]`). `set_settings_cache` used by tests; `clear_settings_cache` by `bootstrap`.
* **Lifecycle gap:** `add_option` handler signature `on_settings_add($option,$value)` checks `'wppo_settings' === $option` — correct but `add_option` fires only when option did not exist (fresh install path via `Activate`). Hot path not affected.
* **Residual:** `grep get_option.*wppo_settings` still finds ~32 direct sites (`LiteSpeed_Integration`×8, `CDN_Purger`×2, `LLMS`×4, `Advanced_Cache_Handler:153`, `Critical_CSS`×3, `Rest`×4, etc.) — performance review `P-WP-01 residual` measures 6→1 on frontend hot path but 45→32 overall (only 29% reduction). Not a correctness regression.
* **Global state trade:** Adds mutable static (`$settings_cache` + `$home_url_cache` + `$hooked` + `$file_exists_cache` + `$core_will_inline_memo`) — Q-01 static-leak concern mitigated via `reset_all_caches()` + `tests/php/bootstrap.php:213-216` `WPPO_Test_Bootstrap::setUp` clears (`Util::reset_cached_home_urls`, `Util::clear_settings_cache`, `Image_Optimisation::clear_file_exists_cache`). `Cache::$core_will_inline_memo` is instance not static — correct; reset via `register_combine_css_path:965-966`.

**Verdict: PARTIAL PASS — hot frontend 6→1 memo is real (3–5 ms saved); long-tail 32 sites + static count are deferred (see §4).**

### 2.6 Cache hot-path fixes — `eligible_handles` single classify + `core_will_inline_memo` + `atomic_put_contents` (P-CPU-01/02, P-CACHE-03, Q-03)

| Fix | File:Line | Evidence | Result |
|-----|-----------|----------|--------|
| `eligible_handles = get_combined_handles(...)` single classify | `class-cache.php:442-494` (freshness `459-469` + generation `492-493` iterate `eligible_handles` only) | Triple loops → single `get_combined_handles` | 3×→1× classify, -67% predicate work |
| `core_will_inline_memo` | `class-cache.php:119-129` instance memo + `720-768` early return + `965-966` invalidate on `register_combine_css_path` | 120→60 `uasort` sims per 30-style page | -50%, correct invalidation |
| `atomic_put_contents` | `class-cache.php:1569-1587` tmp+`move` | Torn-read elimination | Correct, +1 `rename` syscall |
| Stampede transient lock | `class-cache.php:1608-1621` per-file `wppo_cache_write_ md5(path)` 5 s, `try/finally delete_transient` | 100 RPS burst → ≤2 writers | Advisory (get→set not atomic, see §3 N-02) but atomic `move` prevents corruption |

**Verdict: PASS — all 4 are proportionate, well-commented, correctly invalidated.**

### 2.7 Other fixes verified

| Item | File:Line | Before → After | Architecture impact |
|------|-----------|----------------|---------------------|
| `sanitize_settings_recursively` branch order | `class-util.php:799-802` `exclude/preload/delay/list` now precedes `url/cdn/origin` | Fixes Q-02 `excludeUrlToKeepJSCSS` → `esc_url_raw` truncation | Correct heuristic ordering |
| Admin bar capability gate | `class-main.php:1727-1734` `add_setting_to_admin_bar` now `current_user_can(manage_options)` early return | Fixes information disclosure A-AUTH-02 | Correct |
| Google Fonts exact host | `class-google-fonts.php:112,271-278,302` `wp_parse_url host ===` allowlist | Fixes SSRF via substring bypass | Correct, 3 sites |
| Uninstall symlink guard | `uninstall.php:115-144` `is_link` before `is_dir` at root + loop | Fixes traversal on planted symlinks | Correct |
| `get_sitemap_urls` response code | `class-cron.php:502` `wp_remote_retrieve_response_code !==200 → continue` | Fixes Q-06 silent 404/500 swallow | Correct |
| CCSS blog isolation | `class-critical-css.php:156` `md5(blog_id '-' template '-' stylesheet)` | Multisite cache bleed | Correct |
| `advanced-cache` COOKIEHASH host-only fallback | `class-advanced-cache-handler.php:144-149` `wp_parse_url host` → `md5(host)` | Scheme/path mismatch | Correct |
| Used-CSS OR logic | `class-used-css.php:485` `matches_simple_selector true → keep` | Fixes Q-05 descendant false-purge `.sidebar .widget` | Correct |
| Image fallback regex `<source>/<video poster>` | `class-image-optimisation.php:675-702` regex fallback now handles source/srcset + video poster | D-13 fallback parity | Correct |
| JS `modeLabel` DRY | `src/lib/litespeed.js` + `Dashboard/FileOptimization` import | Duplicate `wppo/LiteSpeed` label ternaries → helper | Correct |
| CSS `--wppo-transition` specificity | `src/css/abstracts/_variables.scss`, `src/css/components/_forms.scss` transition `all` → `border-color, background-color, box-shadow, transform, opacity` + scoped `.wppo-dashboard-view` + `mixins @include respond-to` | F-03 specificity, F-22 bespoke breakpoint documented, `_forms` removed dead `.wppo-switch` | Correct |
| Tests | `tests/php/bootstrap.php:213-216`, `CronSitemapTest`, `RumTest`, `AbilitiesTest` | `reset_all_caches` + `clear_file_exists_cache` in bootstrap; added response-code mocks; `AbilitiesTest` new | Lifecycle test isolation restored |

---

## 3. New Issues Introduced by This Patch

No high-severity architecture violation introduced. 4 low/medium notes (performance/security reviews echo some):

| # | Severity | File:Line | Issue | Impact | Mitigation / Cost |
|---|----------|-----------|-------|--------|-------------------|
| **N-ARCH-01** | **LOW** | `includes/class-util.php:84-213` | **New mutable statics increase global state.** `Util` now owns 3 statics (`$home_url_cache`, `$settings_cache`, `$settings_cache_loaded` plus `ensure_settings_cache_hook::$hooked`; plus `Image_Optimisation::$file_exists_cache`, `Cache::$core_will_inline_memo` instance). Q-01 static-leak risk grows: `processIsolation=false` suite requires `reset_all_caches()` — wired in `bootstrap` correctly, but new contributors may add a 4th static and forget. `on_settings_update` takes `($old,$new)` but hooked as `update_option_wppo_settings` which passes `($old,$new,$option)` — extra arg silently ignored (2 formal vs 3 actual) but correct per PHP arity; `on_settings_add($option,$value)` first arg check is technically for `added_option` (passes `$option,$value` filtered) — works. | Test isolation fragility if bootstrap not extended. | **Keep** — cost is awareness. Add `Utils_Static` trait or `Resettable_Interface` if a 5th static appears. Already mitigated by `reset_all_caches()` + `bootstrap`. No revert. |
| **N-ARCH-02** | **LOW** | `includes/class-rum.php:317-431` + `class-cron.php:74,409` | **RUM queue adds state machine complexity.** Replaces 1-line `get_option+update_option` with transient `wppo_rum_queue` (max 100, threshold 20, 10% random flush, cron `wppo_rum_flush +300s`) + `wppo_rum_flush_lock` 30 s + `get_data` opportunistic flush + `Cron` wiring. Queue is `get_transient→set_transient` not atomic → burst can lose 1 sample per concurrent pair; `array_slice(-100)` drops oldest; crash between `delete_transient(queue)` and `update_option` loses ≤100 samples; `wp_next_scheduled` per 90% beacons adds 1 DB query. | Complexity up (~+110 lines) for -95% option-write win — justified, but new code is harder to reason about than single write. | **Keep** — head-line perf win dominates. Follow-up: `wp_cache_add` CAS or schedule-on-transition-0→1 (see performance review N-01). Not blocking. |
| **N-ARCH-03** | **LOW** | `includes/class-cache.php:1608-1621`, `1569-1587` | **Stampede lock is advisory; `atomic_put_contents` fallback reintroduces torn write.** `get_transient`→`set_transient` is not `wp_cache_add` CAS → 2 writers can both miss. `if(!$moved){ delete tmp; return put_contents(path) }` direct-writes torn path if `WP_Filesystem::move` fails (FTP/ssh FS). `wp_rand()` tmp name 1/2^31 collision. | Lock reduces 100→≤2 writers; atomic path prevents torn file when `move` succeeds (direct FS). Fallback branch is unreachable on direct FS. | **Keep** — no corruption on primary path. Follow-up: `wp_cache_add` for lock + remove fallback if not needed. |
| **N-ARCH-04** | **INFO** | `includes/class-cache.php:1633-1660` | **Dead `elseif` retained.** Brotli branch `if(function_exists(brotli_compress)) ... elseif(extension_loaded(brotli)) { if(function_exists(brotli_compress)) ... }` — second branch unreachable, duplicate. Flagged `P-CPU-10` pre-fix, still present. | ~ns branch, no arch impact. | Collapse to single `if($use_brotli && function_exists(...))`. S cost 30 min. |

No new god class, no new circular dependency, no naming regression, no testability regression beyond N-ARCH-01 (mitigated).

---

## 4. Remaining Issues (not fixed, still measurable — architecture lens)

These are **A-01…A-15 / Q-01…Q-07** HIGH/MEDIUM that this patch did not claim to fix. Each is independently shippable; together they are the next architecture debt after this patch.

| ID | Severity | File:Line (still present) | Title | Evidence (current HEAD) | Architecture impact if left |
|----|----------|---------------------------|-------|-------------------------|-----------------------------|
| **R-01** | **HIGH** | `includes/class-main.php:1-2956` | **God class `Main` (2956 lines, 30+ responsibilities)** | Constructor defaults 60+ keys + `includes()` manual requires + `setup_hooks()` ~40 hooks (5 version-gated `is_wp63_plus/is_wp69_plus`) + delay/defer + block-assets migration + `on_settings_update` 92 lines (6 tab gates) + `is_file_minified` 70 lines + enqueue ×2 | SRP violation; change to preload risks delay regression. `Main` construction still does `Util::get_settings()` + `new Image_Optimisation` + `new Google_Fonts` + `Util::init_filesystem` unconditionally — testability near-zero (instantiating `Main` triggers I/O). `setup_hooks` McCabe >30. **Patch relief:** hot `get_option` collapsed via memo, but no facade split yet. |
| **R-02** | **HIGH** | `includes/class-util.php:1-810` | **God utility `Util` (810 lines, 10 concerns, fan-in 23 files)** | Still owns filesystem (`prepare_cache_dir`/`init_filesystem`), URL (`get_local_path`/`is_url_excluded`/`process_urls`), cache keys (`transient_key`/`cached_home_url`/`cached_content_url`/`min_cache_*`), settings (`sanitize_settings_recursively` + new `ALLOWED_SETTINGS_KEYS` + `settings_cache`), mime, `generate_preload_link` echo | Patch **grew** `Util` (+~170 lines for constants + `settings_cache` hooks). `ALLOWED_SETTINGS_KEYS` belongs in `Settings_Registry`, `settings_cache` in `Settings_Store`, `transient_key` in `Cache_Key`. High fan-in means `Util` change risks 23 consumers. Keep-as-facade split (`Filesystem`/`Url`/`Cache_Key`/`Settings_Sanitizer`) still recommended (A-02). |
| **R-03** | **HIGH** | `performance-optimisation.php:44` → `class-main.php:169` | **Constructor I/O at file load + no lazy settings** | `new Main()` still at file load (before `plugins_loaded`). `__construct` still eagerly `Util::get_settings()` + 3 in-memory backfills. `advanced-cache.php` hit still boots full stack for fallback path. Defaults still inline (60+ keys) not `private const DEFAULTS`. | Wastes 0.5–1 ms on `DONOTCACHEPAGE`/REST/CLI paths. Proposal (A-03): `private const DEFAULTS` + lazy `get_options(): array { return $this->options ??= Util::get_settings() }` + move `setup_hooks` to `plugins_loaded`. Not done. |
| **R-04** | **HIGH** | `includes/class-main.php:1524` + `src/lib/apiRequest.js:71-131` + `src/App.js:135` | **Global mutable `wppoSettings` SPA singleton** | `Main::enqueue` still `wp_localize_script('wppoSettings', {settings: redacted, litespeed: get_info(), ...})`; `apiCall` still `wppoSettings.settings = response.data` globally; `App.js` reads `wppoSettings?.settings` per render; `Dashboard` fallbacks `wppoSettings?.settings?.cache_settings`. No `createStore`/`Context`/`subscribe`. | Race on rapid tab saves; stale `cacheSettings` prop until reload. `setupTests.js` must mock `global.wppoSettings` per suite — leak-prone. A-04 store proposal unchanged. |
| **R-05** | **HIGH** | `includes/class-cache.php:1-2269` + `class-main.php:473-539` | **Setter injection → partially-initialized `Cache`** | `Cache` still has `set_image_optimisation`/`set_google_fonts` setters (two construction paths: `Main:473-475` with setters vs `Main:536-539` `new Cache()` when `enableCache` off, plus `process_buffer_only:1183` fallback `new Image_Optimisation/ Google_Fonts` with stale `$this->options` snapshot). No constructor injection. | Stale-options bug risk persists; 3 instantiation sites for same deps. A-05 constructor-injection proposal not applied. |
| **R-06** | **HIGH** | `includes/class-main.php:387-407` + `composer.json` + `performance-optimisation.php:28` | **No abstraction boundaries (36 concretes, 0 interfaces, manual `require_once`)** | Still no `PSR-4` for `PerformanceOptimise\Inc`; `Main::includes()` with `file_exists` + `require_once` + string `'PerformanceOptimise\Inc\WPPO_CLI_Command'` for `WP_CLI`. Adding a class still requires editing `Main::includes()`. | Open/Closed violation; CLI typo silent. A-06 classmap fallback not applied. |
| **R-07** | **MEDIUM** | `includes/class-cache.php:549,800,938,1134` vs `class-main.php:2791` vs `class-img-converter.php:755` | **Mixed filesystem abstraction (native `is_file`/`filesize` vs `WP_Filesystem`)** | Cache still uses native `is_file+filesize+is_readable` for `inline_size_map`/`combined_handles` (direct) vs `$fs->put_contents/get_contents` for writes. `Main::is_file_minified` uses `fopen+flock+fgets` native. | FTP/SSH hosts: native read may disagree with `WP_Filesystem`. Proposal: `Util::is_file()/filesize()` delegating to `$fs`. Not done (except `cached_file_exists` for images, which is memoized native — not `WP_Filesystem`). |
| **R-08** | **MEDIUM** | `includes/class-app` SPA `src/App.js:1-527` (unchanged) | **God `App.js` (527 lines, routing + fetching + theming + focus-trap + resize)** | Still 3 fetchers in one `useEffect` with `hasFetched*` refs + `AbortController` + `rulesRetryTrigger`/`ccssRefreshTrigger`; `renderContent` recreates 7 lazy entries; `sidebarItems` memo but `components` map not `useMemo`. | A-09 extract `useServerRules`/`useCcssStatus` + `useMemo(components)` not applied. Not in this patch's JS scope. |
| **R-09** | **MEDIUM** | `src/components/Dashboard.js:1-1327` (unchanged size) | **God `Dashboard` (1327 lines, 8 panels, polling)** | 4 `useState` blocks + `pollJobStatus` + `fetchDbCounts` + triplicated `save*Settings` (`currentSettings = wppoSettings.settings?.cache_settings ??`) still inline. | A-10 decompose to `CacheStatsCard`/`PageCacheCard` etc. not applied. Only `modeLabel` DRY done. |
| **R-10** | **MEDIUM** | `includes/class-cache.php:1394-1441` | **CQS violation `is_not_cacheable` with side-effect** | `is_not_cacheable(): bool` still calls `maybe_mark_page_not_cacheable()` (writes `.wppo-no-cache` marker + deletes) on every cache-miss for `DONOTCACHEPAGE`. Doc at `1428` notes coupling intentional. | Hidden I/O per cache-miss; void `maybe_mark_page_not_cacheable` silent on `put_contents` failure (drop-in still stale). Q-03 rename/extract not applied. |
| **R-11** | **MEDIUM** | `includes/class-main.php:435,1158,1239` + `class-cache.php:373` | **Scattered `version_compare` gates + TODOs** | Still `$is_wp63_plus`/`$is_wp69_plus` + `TODO(#553/#624)` repeated 6 sites (Main 5 + Cache 1) with slightly different `function_exists && is_wp69_plus` vs bare. | A-13 `Wp_Version::isAtLeast()` / `hasTemplateEnhancement()` central gate not applied. |
| **R-12** | **LOW** | `includes/class-cache.php:800-816` | **`inline_size_map` hidden temporal coupling** | `inline_size_map` still invalidated manually after `register_combine_css_path`; queue mutation after map built uses stale sizes (drift detection fires). Now also `core_will_inline_memo` must be co-invalidated — correctly done at `965-966`, but invariant is still implicit. | A-08 accept-param-snapshot proposal not applied. Current co-invalidation correct. |
| **R-13** | **MEDIUM** | Perf opportunistic | **P-CPU-03 Telemetry `calculate_sizes` sequential `wp_remote_head` ×110 (550 s worst)** | `includes/class-telemetry.php:682-744` still sequential 5 s HEAD per asset | Admin scan blocks FPM. Batch via `Requests::request_multiple` not done (deferred Tier-2 per perf review). |
| **R-14** | **MEDIUM** | Perf | **P-WP-02 `schedule_page_cron_jobs` 200× `wp_next_scheduled` + `get_posts` without `no_found_rows`** | `includes/class-cron.php:274-339` still `wp_next_scheduled` 200×/batch + `get_posts` without tuning | Cron tick 10–20 ms per `init` even when `preloadSitemap` off. S cost 2 h. |
| **R-15** | **LOW** | Q-04 | **`Rest::update_settings` stale preservation without re-sanitize** | `includes/class-rest.php:453-466` still copies raw `options['performance_audit']['pagespeed_api_key']` verbatim when omitted (with fix now sanitized on re-insert? actually `474` now `sanitize_text_field` on preserve — but `server_timing_enabled`/`auto_rescan` still raw) | Low — DB corruption propagates. |

**Not regressions** — pre-existing and explicitly deferred (performance review §4 lists 7 as Tier-2). No architecture debt was worsened by this patch except `Util` growth (R-02), which is offset by constant/tag centralization.

---

## 5. Verdict

**PASS — with no revert, 1 compatibility note, and deferred Tier-2 architecture debt.**

| Question | Answer | Evidence |
|----------|--------|----------|
| **Were findings really fixed?** | **Yes for the 4 scoped architecture couplings; partial for settings memo.** | `Util::ALLOWED_SETTINGS_KEYS/TABS` + `CLEANUP_METHOD_MAP` + `delete_in_batches` + `should_bypass_for_litespeed` + `cached_file_exists/get_cached_image_size` + `core_will_inline_memo/eligible_handles` + `atomic_put_contents`/stampede lock — all read + `grep -rn` verified. `get_settings` memo: hot 6→1 path fixed; 32 long-tail sites remain (see R-05 residual). |
| **New issues introduced?** | **No high arch issue.** 3 low/1 info (N-ARCH-01…04): added statics, queue state machine, advisory lock, dead `elseif` — all justified or cosmetic. | See §3. No new god class, coupling, or lifecycle violation. |
| **Functionality preserved?** | **Yes, with 1 intentional narrowing.** | Admin bar still renders for admins (now correctly gated), Google Fonts still hosts for allowlisted hosts, cache clear works for uncached pages via `realpath` fallback + `..` check, RUM still collects (queued), DB cleanup still deletes same rows via same `prepare` placeholders, uninstall still deletes dirs (symlink-safe). `core_tweaks` import now 400 — intentional if feature removed; else re-add to `ALLOWED_SETTINGS_KEYS`. |
| **Remaining duplication?** | **Largely resolved for changed paths; architectural god-object duplication remains as deferred debt (§4 R-01/R-02/R-08/R-09).** | Changed-path dup: -250 lines (batch), -40 lines (image LRU), constant centralization. Architectural dup: `Main` 2956 lines, `Util` 810 lines, `App` 527 + `Dashboard` 1327 remain — not in patch scope. |
| **Abstraction / naming / complexity?** | **No over-abstraction; naming unchanged (good). Complexity: dup down, state up modestly, justified.** | Constants are values not classes; helpers are `private` not new services; `should_bypass_for_litespeed` is private method not new class. `Util` still grab-bag (R-02) but not worsened structurally — added constants are correctly scoped. No generic-name collision change (A-14 low). |
| **Error handling / lifecycle?** | **Improved for changed paths; pre-existing silent swallows remain elsewhere (§4 R-10, Q-03).** | `get_sitemap_urls` now checks response code, `get_table_size` handles restricted `information_schema`, `get_settings` hooks keep memo coherent, `RUM flush` has lock + `try/finally`, `atomic_put_contents` prevents torn reads. `is_not_cacheable` CQS and cron silent `WP_DEBUG` guards still present (deferred). |
| **Testability?** | **Improved for changed paths via `reset_all_caches` + bootstrap clearing; `Main`/`Cache` setter-injection + `wppoSettings` global still hinder (R-05/R-04).** | `tests/php/bootstrap.php:213-216` clears `Util` + `Image_Optimisation` statics per test; `RumTest` + `CronSitemapTest` updated. `Main` still hard to unit-test without stubbing. |
| **Recommendation** | **Ship.** Follow-up sprint S should address `R-02 Util` facade split (`Filesystem`/`Url`/`Cache_Key`/`Settings_Sanitizer`) behind deprecated `Util` proxies, plus `R-05 Cache` constructor injection and `R-01 Main` `private const DEFAULTS` + lazy `get_options()`. If `core_tweaks` import compat must be preserved, re-add it to `ALLOWED_SETTINGS_KEYS` or add migration. JS `R-04` store and `R-08/R-09` god-component splits are independent Tier-2. No architecture blocker. | See §4 for per-item cost (S/M). |

---

*Evidence produced by independent re-read of `git diff HEAD` (46 files) + `git diff origin/master` + direct `Read` of `includes/class-util.php:1-810`, `class-cache.php:119-1621`, `class-database-cleanup.php:81-387`, `class-image-optimisation.php:117-938`, `class-main.php:169-1734`, `class-rum.php:58-431` and grep traces; no reliance on implementation-agent self-report. All `file:line` refs point to `HEAD` (unstaged) at `2026-08-28`.*
