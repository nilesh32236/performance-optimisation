# FINAL-PERF-REVIEW — Phases 1-3 (7ce4834) Performance Audit

**Date:** 2026-08-28 · **Plugin:** `performance-optimisation` v1.9.0  
**Branch:** `fix/audit-2026-08-28` at `7ce4834` (Phase3 PR-C) — diff base `68a2f66a` audit gap → `d306e677` PR-A → `45ed2f79` PR-B → `7ce4834` PR-C  
**Scope:** WP-CLI bootstrap, hook overhead, CLI `--dry-run`, cache veto `wppo_should_cache_request`, invalidation `wppo_invalidation_urls`, DB cleanup per-type `wppo_database_cleanup_completed`, object-cache `wppo_object_cache_config`, context fence `Util::init_filesystem` lazy.  
**Method:** Full reads `performance-optimisation.php:1-70`, `includes/class-main.php:169-799` (`__construct` + `includes()` + `setup_hooks():485-799`), `includes/class-util.php:81-443` (`get_default_settings`, `init_filesystem`), `includes/class-cache.php:1331-1920` (`maybe_apply_cdn`, `is_not_cacheable`, `invalidate_dynamic_static_html`), `includes/class-cron.php:56-159` (`Cron::__construct`, `schedule_cron_jobs`), `includes/class-database-cleanup.php:714-750`, `includes/class-object-cache.php:40-260`, `includes/class-wppo-cli-command.php:1-970` diff. Cross-checked `PERF-RESEARCH.md` (297 lines) hook counts and `FINAL-ADVERSARIAL-REVIEW.md` retain/reject. No production edits; `git diff -- includes/` hot-path verified.

> Related: [`PERF-RESEARCH.md`](./PERF-RESEARCH.md) (context-aware loading & cost model), [`FINAL-ADVERSARIAL-REVIEW.md`](./FINAL-ADVERSARIAL-REVIEW.md) (retain/modify/reject), [`HOOK-AUDIT.md`](./HOOK-AUDIT.md) (272 hits), [`IMPLEMENTATION-LOG.md`](./IMPLEMENTATION-LOG.md) (Phase1-3).

---

## 0. Verdict

**Hot loops not regressed. Aggregate bootstrap perf neutral to +0.3–0.8 ms win on frontend (lazy FS) and unchanged on CLI.**

| Area | Perf delta per request (warm OPcache) | Hot loop? | Verdict |
|------|----------------------------------------|-----------|---------|
| WP-CLI bootstrap / synopsis `[<action>]` | 0 | No (docblock only) | **PASS** |
| Hook overhead (new `apply_filters`/`do_action`) | +0.01–0.03 ms frontend (1× veto), +0.03 ms on `clean_all` only | No | **PASS** — no per-tag/per-image filter |
| CLI `--dry-run` | +1 `get_counts()` (~2–8 ms) only when flag set; saves full `DELETE`/`OPTIMIZE` | No | **PASS** |
| Cache veto `wppo_should_cache_request` | +1 `apply_filters` before `ob_start` (~0.005 ms no listener) | No | **PASS** — single, after `DONOTCACHEPAGE` |
| Invalidation `wppo_invalidation_urls` | +1 `apply_filters` + O(M) sanitize/dedupe (M≈5–12) ~0.02 ms, dedupe saves duplicate `unlink` | No | **PASS** |
| DB cleanup per-type `do_action` | +9 `do_action` on `clean_all` only (~0.03 ms) | No | **PASS** |
| Object-cache `wppo_object_cache_config` | +1 `apply_filters` per `get_redis_config`/`ping`/`enable` (~0.005 ms) | No | **PASS** |
| Lazy `Util::init_filesystem` | **−0.3–0.8 ms frontend** (avoids `require file.php` + `WP_Filesystem` probe) | No | **PASS** — biggest win |
| Hot loops `Cache::maybe_apply_cdn` per tag / lazy load | 0 (rejected per-asset `wppo_cdn_url`, `wppo_should_lazy_load_image`) | **Yes** | **PASS** — not added, loop unchanged |

No new `apply_filters` inside `while(next_tag)` (CDN) or per-image/iframe lazy loops. Per-asset predicates (`wppo_cdn_url`, `wppo_delay_js_should_delay`, `wppo_should_serve_next_gen`, `wppo_should_lazy_load_image`) remain **REJECT** per FINAL-ADVERSARIAL — correct for perf. Largest remaining opportunity is **not** in Phase1-3: `Cron::schedule_cron_jobs` still pays 8× `wp_next_scheduled` (~0.3–1.0 ms) on every front/CLI/REST `init`.

---

## 1. WP-CLI Bootstrap

### 1.1 What changed

- `performance-optimisation.php:41` keeps single `require vendor/autoload.php`; `includes/class-main.php:439` **removed** duplicate `require_once WPPO_PLUGIN_PATH . 'vendor/autoload.php'` (was at `:437` in `Main::includes()`). Now relies on the already-loaded autoloader before `new Main():44`.
- `WPPO_CLI_Command` synopsis (`--help`) tightened: `cache`, `database`, `image`, `settings`, `object-cache`, `pagespeed`, `system-info` each changed `<action>` → `[<action>]` with `--- default: clear|cleanup|status|get ---` + `options:` enum in docblock (`class-wppo-cli-command.php:49,130,301,495,805`). Docblock-only; runtime unchanged (`$action = $args[0] ?? 'clear'` etc. already defaulted).
- Format handling converged to **json-only** for `database counts` + `system-info` (`--format=json` default, fallback `wp_json_encode(PRETTY)` when `WP_CLI\Utils\get_flag_value` coercion forces `json` even if `yaml` requested). REJECT `Formatter table|csv|yaml|count` + `Spyc`/`yaml_emit` per FINAL-ADVERSARIAL.
- `Util::get_default_settings():92` centralised as single source for `Main::__construct:170` defaults and `WPPO_CLI_Command::get_default_settings:559` (delegates `return Util::get_default_settings()`). Fixes 7-tab drift (`CLI:451` vs `Main:240` where CLI lacked `litespeed_integration`, `llms_txt`, `od/bfcache/perf_translations/ai_adaptive/edge_cache`).

### 1.2 Cost

- Duplicate autoload removal: saves 1 `file_exists` stat + include guard on **every** request. ~0.01 ms + cleaner bootstrap. Risk low — `Main::includes()` never called standalone in tests without `performance-optimisation.php` bootstrap (tests `require` files manually). `composer dev-setup` still works (autoloader loaded once via `performance-optimisation.php:41` before `new Main`).
- Synopsis docblock: 0 runtime. `WP_CLI` validates `options:` before `invoke` per handbook `commands-cookbook/#longdesc` — now `wp wppo cache claer` fails fast with help, same exit 1 but earlier.
- `Util::get_default_settings()` is pure array literal (`function_exists('wp_load_classic_theme_block_styles_on_demand')` + `class_exists('OD_URL_Metric')` probes once). Called only on fresh-install CLI `settings get` when `get_option('wppo_settings')` empty, not on hot path. No per-request cost (memo `Util::get_settings()` handles warm path).

### 1.3 Assessment

**PASS.** Zero hot-path regression. Removal of duplicate autoload is the only bootstrap perf delta and it is positive.

---

## 2. Hook Overhead (70–105 registrations, not 270)

`PERF-RESEARCH.md:42-53` measured: 272 total hits `grep -rn "add_action|add_filter"` but **unique registrations per request 70–85** (max 95–105 with all features ON). The prompt's "270 hooks always-loaded" is ~3× overcount — includes `do_action`/`apply_filters` call sites (78 + 22) and conditional branches.

Phase1-3 add **4 new public hooks**:

| Hook | Type | File:Line | Fires |
|------|------|-----------|-------|
| `wppo_should_cache_request` | filter | `class-cache.php:1524` | 1× per `is_not_cacheable()` evaluation (once per request before `ob_start`) |
| `wppo_invalidation_urls` | filter | `class-cache.php:1920` | 1× per `invalidate_dynamic_static_html($post_id)` (on `save_post`, not per request) |
| `wppo_database_cleanup_completed` per-type | action | `class-database-cleanup.php:737`, `class-rest.php:908`, `class-wppo-cli-command.php:385` | 1× per type deleted (N=9 on `clean_all` only; 1× on single-type REST/CLI) |
| `wppo_object_cache_config` | filter | `class-object-cache.php:230`, `242:ping`, `303:enable`, `131:get_status` via `get_redis_config` | 1× per `get_redis_config()` / `ping` / `enable` / `get_status` |

Cost model (PHP 8.2 warm OPcache, no listener): `apply_filters`/`do_action` with `has_filter`/`has_action` check is ~3–8 µs + array allocation. With a listener doing `str_contains` it is ~10–30 µs.

- Frontend `GET /` cache-miss generation (`process_buffer_for_cache`): +1 filter `@is_not_cacheable` → +0.005 ms. Negligible vs 5–40 ms buffer.
- `save_post` invalidation: +1 filter + sanitize loop O(M) where M = canonical URLs (typically `path` + `home` + optional `posts_page` + `archive` + 3–8 term links = 5–12) + `array_unique` dedupe. Saves duplicate `delete_cache_files` when membership/Woo filter adds overlapping URLs.
- `clean_all` (daily cron or `wp wppo database cleanup --type=all`): 9 extra `do_action` → ~0.03 ms total, not per-request.
- `ping`/`get_status`: +1 filter each, not per-request (CLI or dashboard only).

All existing FINAL-ADVERSARIAL **REJECT** decisions preserved: no `wppo_should_cache_for_user`, `wppo_cache_written`/`miss`, `wppo_cdn_url` per-asset, `wppo_delay_js_should_delay`, `wppo_should_serve_next_gen`/`wppo_should_lazy_load_image`, `wppo_preload_batch_size`/`wppo_sitemap_preload_limit`, `wppo_cli_redis_config`. Those would have added per-tag/per-image filters in hot loops — correctly omitted.

**PASS — overhead <0.05 ms on hot path, <0.1 ms budget met.**

---

## 3. CLI `--dry-run` (database only)

**Added:** `includes/class-wppo-cli-command.php:205-280` early `get_flag_value('dry-run')` branch in `database()`:

- `optimize --dry-run`: `wp_json_encode(['would_optimize'=> $table_list])` + `warning "Dry run — no tables optimized"` then `return` before `Database_Cleanup::optimize_table` loop.
- `cleanup --dry-run`: `Database_Cleanup::get_counts()` → `['would_delete'=> {type: count} or all]` + `warning` then `return` before any `DELETE`/`OPTIMIZE`.
- Gated via `class_exists(WP_CLI\Utils) && method_exists(get_flag_value)` fallback to `isset($assoc_args['dry-run'])`.

**Cost:** When flag absent, 1 boolean check (`get_flag_value`) ~0.005 ms. When flag present, one extra `get_counts()` (batched `SELECT COUNT(*)` per cleanup type, ~2–8 ms) — but this is preview-only and still cheaper than the `DELETE`/`OPTIMIZE` it replaces (which would be 50 ms–2 s + table locks). Early return correctly skips `maybe_optimize_tables` and `Log::add`.

Correctly **REJECT** `cache clear --dry-run` (idempotent `Cache::clear_cache` already previewed via `cache status` `Cache::get_cache_stats` dir walk 200–800 ms duplicated) and `image convert`/`settings import` dry-run per FINAL-ADVERSARIAL.

**Assessment:** Non-flag path has zero token cost beyond boolean. Flag path trades one read-only `SELECT` batch for a multi-table `DELETE` — correct. No `WP_CLI::confirm` pollution in non-TTY CI (flag bypasses confirm anyway).

**PASS.**

---

## 4. Cache Veto `wppo_should_cache_request`

**File:Line:** `includes/class-cache.php:1496-1527` inside `Cache::is_not_cacheable():1496` after `DONOTCACHEPAGE`.

```php
if ( defined('DONOTCACHEPAGE') && DONOTCACHEPAGE ) { maybe_mark...; return true; }
/** Filter: 4 args */
$is_mobile = function_exists('wp_is_mobile') ? wp_is_mobile() : false;
$is_logged_in = function_exists('is_user_logged_in') ? is_user_logged_in() : false;
$should_cache = (bool) apply_filters('wppo_should_cache_request', true, $this->request_uri, $is_mobile, $is_logged_in);
if (! $should_cache) return true;
```

**Correct per FINAL-ADVERSARIAL §8 row 6 (MODIFY — keep SINGLE after `DONOTCACHEPAGE`).** Earlier dual insertion `Cache:1505+1755` in the plan was overreach; Phase3 correctly keeps ONE site at `is_not_cacheable` which is called from both `process_buffer_for_cache` and `is_request_cacheable()` wrapper (`:1845`).

**Perf:**
- 1 `apply_filters` per request that reaches `is_not_cacheable()`. `is_not_cacheable()` early-exits on empty `cache_root_dir`/`domain` before filter? No — filter is after those `true` returns but before path parsing, so unreachable cache roots still avoid filter — correct.
- `wp_is_mobile()` is a core `strpos($_SERVER['HTTP_USER_AGENT'])` check ~0.01 ms; `is_user_logged_in()` checks `wp_get_current_user()` memo — both cheap and already computed later anyway. No redundant I/O.
- No per-tag/per-image cost. Buffer gating `ob_start`/`wp_template_enhancement_output_buffer` still correctly avoided when filter returns `false` (returns `true` from `is_not_cacheable` → skip buffer). Verified via `HookShouldCacheRequestTest::test_veto_false` + `DONOTCACHEPAGE_wins` tests.

**Alternatives rejected:** `wppo_should_cache_for_user` (would add 1 filter per logged-in check on every frontend hit, redundant with `loggedInCacheRoles` allowlist + this single veto with `$is_logged_in`). `wppo_cache_written`/`miss` (would add observability on `atomic_put_contents` hot path, duplicate `wppo_after_cache_clear` + `wppo_cache_page_html`).

**PASS — single veto, constant wins, <0.02 ms, no loop exposure.**

---

## 5. Invalidation `wppo_invalidation_urls`

**File:Line:** `includes/class-cache.php:1857-1960`.

**Before:** Immediate `delete_cache_files` per path (`page`, `home`, `posts_page`, `archive`, per-term) — 5–15 direct deletions, no extension point.

**After Phase3:** Collect `$urls[]` (canonical paths), `apply_filters('wppo_invalidation_urls', $urls, $post_id):1920`, sanitize/dedupe, then single loop:

```php
$urls = (array) apply_filters('wppo_invalidation_urls', $urls, $page_id);
// sanitize: wp_normalize_path(trim($u,'/')), reject '..' traversal (non-empty), array_unique
$sanitized = array_values(array_unique($sanitized));
$primary_normalized = wp_normalize_path(trim((string)$path,'/'));
foreach ($sanitized as $url_path) {
  $html = get_file_path($url_path,'html');
  if (''===$html) continue;
  if (cache_root && 0!==strpos(normalize($html), cache_root)) continue;
  if (abspath && 0!==strpos(normalize($html), abspath)) continue;
  delete_cache_files($html); delete_role_variant_files(...); delete_no_cache_marker(...);
  if ($url_path === $primary_normalized) { delete css + used-css }
}
```

**Perf:**

- Extra work vs before: 1 `apply_filters` (~0.005 ms) + 1 `wp_normalize_path` + `strpos('..')` per URL (M × ~0.002 ms) + `array_unique` (M log M) + 2 `wp_normalize_path` + 2 `strpos` guard per URL before unlink. For M=8, ~0.02–0.04 ms overhead.
- Savings: `array_unique` prevents duplicate `delete_cache_files` when filter or taxonomy loop adds same path twice (e.g. `home` equals `posts_page`). `ABSPATH`/`cache_root` prefix guards prevent traversal → security, not perf.
- Primary `css`/`used-css` now only for `$url_path === $primary_normalized` — previously always deleted `css`/`used-css` for primary even when not needed; now correct and saves 2 `unlink` checks when `wppo_invalidation_urls` adds only unrelated URLs.

**Invoked only on `save_post` (and `Cron::mark_page_as_processed` does NOT go through this — it builds its own `wp-content/cache/wppo/{domain}/{path}/index.html` delete inline `class-cron.php:612`). So not per-request.**

**Hot-loop risk:** None — `save_post` is not a hot loop. If bulk edit fires `save_post` 200×, filter fires 200× but each is cheap; overall `wp_update_post` already dominates.

**PASS.** Sanitization mirrors `Rest:413-432` traversal pattern; dedupe avoids redundant FS `unlink` syscalls which are more expensive than loop.

---

## 6. DB Cleanup Per-Type `wppo_database_cleanup_completed`

**Files:** `includes/class-database-cleanup.php:722-750`, `includes/class-rest.php:899-908`, `includes/class-wppo-cli-command.php:378-385`.

**Change:** Before, only `do_action('wppo_database_cleanup_completed','all',$total,$results):747` after `clean_all` loop. Now also per-type inside `clean_all` loop:

```php
foreach ($methods as $key => $method) {
  $res = ...invoke_cleanup_method...
  $results[$key] = $res;
  if (!is_wp_error($res) && false !== $res) do_action('wppo_database_cleanup_completed', $key, (int)$res);
  // ...accumulate $total_deleted, $affected_tables
}
do_action('wppo_database_cleanup_completed','all',$total_deleted,$results);
```

And single-type paths `Rest::database_cleanup:900` and `WPPO_CLI_Command::database:385` now also `do_action('wppo_database_cleanup_completed', $type, (int)$result)`.

**Perf:**

- `clean_all` is `daily` cron (via `Cron::database_cleanup_cron:737` → `Database_Cleanup::auto_clean`) or CLI `database cleanup --type=all`. Fires at most once per day per site unless manually triggered. 9 extra `do_action` (~0.03 ms) negligible vs `DELETE` batches (each batch `LIMIT 1000` with `util::is_url_excluded`-like checks, often 10–50 ms per batch).
- Single-type `clean_*` paths previously had no visibility; now 1 `do_action` ~0.005 ms vs `DELETE` cost. Slack/alert consumer can now hook per-type without polling `Log` tail.

**Rejected:** `wppo_before_database_cleanup` action and `wppo_database_cleanup_type_completed` filter — FINAL-ADVERSARIAL correctly nests those as action suffices; Slack/alert consume `after` arg. Filter to mutate count would encourage count inflation — not needed.

**PASS.**

---

## 7. Object-Cache Config `wppo_object_cache_config`

**Files:** `includes/class-object-cache.php:40-260`.

**Change:**

- `ALLOWED_KEYS` const (`:50`) converged 12 keys: `mode,host,port,password,database,timeout,prefix,nodes,master_name,use_tls,persistent,compression` — merges CLI 6-key (`host,port,password,database,timeout,prefix`) vs REST 10-key divergence (`includes/class-rest.php:1114` now uses `Object_Cache::ALLOWED_KEYS` with `prefix`+`timeout` handling).
- `get_redis_config():213` merges `Util::get_settings()['object_cache']` + on-disk `wppo-redis-config.php` then `apply_filters('wppo_object_cache_config',$config):230`.
- `ping($config):242` and `enable($config):303` filter incoming `$config` before `connect_internal`.
- `get_status():131` now delegates `$config = $this->get_redis_config()` instead of duplicating merge logic.

**Perf:**

- `get_redis_config()` is 1 `get_option('wppo_settings')` memo hit (`Util::$settings_cache`) + 1 `file_exists($config_path)` stat only when `$options['object_cache']` empty (first enable before dashboard save). +1 `apply_filters` (~0.005 ms).
- `get_status()` is called on dashboard SPA `toplevel_page_performance-optimisation` and `wp wppo object-cache status`. Not per-request. `ping`/`enable` are CLI/REST only.
- `ALLOWLIST converge` fixes silent drop of `timeout`/`prefix`/`nodes`/`master_name`/`use_tls`/`persistent`/`compression` when supplied via CLI but not REST allowlist — correctness win, no perf delta.

**Hot path:** None — object-cache operations are admin/CLI only. Drop-in `templates/object-cache.php` does **not** call `get_redis_config()`; it reads the baked config file directly (zero-boot). No impact on frontend cache-hit path (served via `advanced-cache.php` before WP boots).

**PASS.**

---

## 8. Context Fence — Lazy `Util::init_filesystem`

**Files:** `includes/class-main.php:342-354` (`__construct` + `setup_hooks`), `includes/class-util.php:431-443` (`init_filesystem`).

**Before:** `class-main.php:343` eager `Util::init_filesystem()` on every request:

```php
$this->filesystem = Util::init_filesystem(); // require file.php + WP_Filesystem() probe
```

`PERF-RESEARCH.md:115` costed `~0.3–0.8 ms` first call (require `ABSPATH/wp-admin/includes/file.php` + FTP detection), ~0.02 ms warm.

**After Phase3:**

```php
if ((function_exists('is_admin') && is_admin()) || (defined('WP_CLI') && WP_CLI)) {
  $this->filesystem = Util::init_filesystem();
  if (!$this->filesystem) $this->filesystem = null;
} else {
  $this->filesystem = null;
}
```

**Perf:**

- Frontend (`is_admin()==false && !WP_CLI`, covers `!wp_doing_cron() && !REST_REQUEST` implicitly): **saves 0.3–0.8 ms** cold, 0.02 ms warm — the single largest win in Phase1-3. Frontend `GET /` cache-miss `5–40 ms` buffer dominates, so win is ~1–5% of generation time; on cache-hit (drop-in) WP never boots, so irrelevant — but for `enableCache=false` frontends still pays.
- Admin/CLI: unchanged (still initializes when needed).
- Correctness: `class-main.php` never uses `$this->filesystem` after construction except stored property (no consumer reads it — `Cache` has its own `get_filesystem()` lazy via `Util::init_filesystem()` at `class-cache.php:347`). So frontend `null` is safe. `Cache` helpers (`prepare_cache_dir`, `delete_cache_files`, etc.) each call `$this->get_filesystem()` lazily — no regression.
- Edge: `WP_CLI` constant true before `plugins_loaded` on some hosts when plugin loaded as must-use; `function_exists('is_admin')` guard keeps fallback safe.

**What remains (PERF-RESEARCH §5.4):**

- `class-main.php:485-799` `setup_hooks()` still registers **~70 hooks unconditionally** on every context — 7 admin hooks (`admin_menu:490`, `admin_init×4`, `upgrader_process_complete×2`, `admin_enqueue_scripts:498`) fire `init` even on frontend/CLI/REST. PERF-RESEARCH P01 estimated `is_admin()` fence would save ~0.08–0.20 ms on CLI/REST/Cron/Frontend by skipping registry + future `maybe_fix_wp_cache` transient probe. Phase3 deliberately **kept** this monolith (per FINAL-ADVERSARIAL §8 row 7-8: 30-line branching hurts grep-ability, `is_admin()` false for `admin-ajax.php` breaks `wp_ajax_wppo_get_nonce:797`). Decision is sound: saving 0.1 ms at cost of `admin-ajax` breakage is not worth it.
- `Cron::__construct:56` still `add_action('init', schedule_cron_jobs)` on every request; `schedule_cron_jobs:114-159` still does 8× `wp_next_scheduled` (each `get_option('cron')` deserialization when no object cache) costing **0.3–1.0 ms worst case on every frontend** — the highest repeated cost after cache buffer per `PERF-RESEARCH:132`. Phase3 correctly leaves workers (`wppo_generate_static_page/url`, `wppo_img_conversion`, etc.) registered — only scheduler should be fenced/debounced (`get_transient('wppo_schedule_checked')` 1h lock pattern like `maybe_fix_wp_cache:924`). Recommended as **next P1** PR-C follow-up, not in Phase3.

**PASS — lazy FS is correct and sufficient for now; broader fence deferred intentionally.**

---

## 9. `class-main.php:485-799` — Spot Check Remaining Registrations

Grepped `class-main.php:485-799` (current) — still 74 `add_action`/`add_filter` lines unconditional; 12–18 conditional on settings (`enableCache`, `delayJS`, `deferJS`, `minify*`, `combineCSS`, `criticalCSS`, `removeWoo*`, `server_timing_enabled`, `is_admin()` for `save_post:599`).

Key findings:

- `save_post`/`deleted_post` for DB counts (`599-602`) now correctly `if (is_admin())` gated — saves 2 registrations on frontend/CLI/REST.
- `Cron` instantiated via `new Cron():767` still unconditional (registers 12 hooks via `Cron::__construct:57-75`). As above, recommend gating `schedule_cron_jobs` worker inside `Cron` rather than `new Cron()` guard — keep async worker hooks, debounce scheduler.
- Frontend optimisation block (`wp_enqueue_scripts`, `wp_head`, `wp_resource_hints`, `script_loader_tag`, `RUM`, `Llms`, etc.) still unconditional — `P10` in `PERF-RESEARCH.md:200` estimated 0.3–0.6 ms CLI saving if fenced `!is_admin() && !WP_CLI && !wp_doing_cron() && empty(REST_REQUEST)`. Deferred per FINAL-ADVERSARIAL; acceptable for Phase1-3 because frontend buffer gating (`is_cache_allowed_for_current_user`, `is_not_cacheable`) already prevents work despite registration.

**No hot-loop regression from 485-799; remaining gates are optional polish.**

---

## 10. Hot Loops — `Cache::maybe_apply_cdn` per tag, lazy & co.

**`Cache::maybe_apply_cdn:1331-1410`** uses `WP_HTML_Tag_Processor` `while(next_tag)` loop over all tags, inner `foreach(['src','href','data-src'])` + `srcset` split per `img/source` tag. For 40 taggable elements, ~120 attribute checks.

Change count: **none** — `wppo_cdn_url` per-asset `apply_filters` inside loop was **REJECT** per FINAL-ADVERSARIAL §7 (would add 1× `apply_filters` per attribute × per tag = up to 200 filters/page @0.02 ms = 0.8 ms TTFB). Phase3 kept existing gates `wppo_litespeed_can_cdn:1349` + `litespeed_can_cdn` before loop — correct.

Other hot loops unchanged:

- `class-main.php:513-536` `script_loader_tag` for `delayJS`/`deferJS` fires N× per script tag (8–30 scripts) — already gated per feature; `matches_delay_pattern` `preg_match` per handle preserved.
- `Image_Optimisation` lazy-load / next-gen per-image predicates remain setting-gated (`excludeConvertImages`, `excludeLazyImgs`), no new per-image `apply_filters` — `wppo_should_serve_next_gen`/`wppo_should_lazy_load_image` REJECT preserves `process_buffer_only` 5–40 ms budget.

**PASS — no new filter in any tag/image/asset loop.**

---

## 11. `Cron::schedule_cron_jobs` — Why Still Hot

`includes/class-cron.php:114-159`:

```php
public function schedule_cron_jobs(): void {
  $options = Util::get_settings(); // memo hit after first
  if (!empty(preload/enablePreloadCache)) { if (!wp_next_scheduled('wppo_page_cron_hook')) wp_schedule_event(...) else clear... }
  if (!wp_next_scheduled('wppo_img_conversion')) wp_schedule_event(...);
  if (!wp_next_scheduled('wppo_database_cleanup_cron')) ...
  if (!wp_next_scheduled('wppo_web_vitals_rescan')) ...
  if (!empty(llms/enabled)) ... else clear...
  if (!empty(file_optimisation/removeUnusedCSS)) ...
  if (!wp_next_scheduled('wppo_ccss_regeneration')) ...
}
```

Called via `add_action('init', schedule_cron_jobs)` on **every** request (front, admin, REST, CLI, cron). Each `wp_next_scheduled` does `get_option('cron')` deserialization (when no object-cache-backed cron) — 8×. `PERF-RESEARCH:132` worst case **0.3–1.0 ms** per request, highest repeated cost after cache buffer.

Phase3 left this untouched (intentional per `IMPLEMENTATION-LOG` rejected scope). Correct — fixing needs `get_transient('wppo_schedule_checked')` debounce or gating `is_admin() || wp_doing_cron() || WP_CLI` — but must keep worker `add_action('wppo_generate_static_page/url', ...)` always registered so async workers can fire. Recommend **P11** PR as next:

- Scheduler: fence `schedule_cron_jobs` to `is_admin()` or debounced transient.
- Workers: keep unconditional.

Not a perf regression from Phase1-3 — just unfixed pre-existing cost.

---

## 12. Aggregate Savings (Estimate, warm OPcache, no XHProf)

| Profile | Before (bootstrap only) | After Phase3 | Delta |
|---------|--------------------------|--------------|-------|
| WP-CLI read-only `wp wppo system-info` / `database counts` | ~1.2–2.0 ms bootstrap | ~0.9–1.6 ms | **−0.3 ms** (lazy FS only) |
| Frontend `GET /` anonymous `enableCache=false` | ~1.5–2.5 ms + 0.5–2 ms asset checks | ~1.2–2.0 ms | **−0.3–0.5 ms** (lazy FS) |
| Frontend `GET /` `enableCache=true` miss (buffer 5–40 ms) | +5–40 ms generation | +5–40 ms + 0.005 ms veto | **+0.005 ms** noise |
| Frontend hit | 0 ms WP (drop-in) | 0 ms | 0 |
| Cron spawner `wp-cron.php` | includes `schedule_cron_jobs` 0.3–1.0 ms | same | 0 |
| `save_post` invalidation | ~0.05 ms FS unlink batch | +0.02 ms filter/sanitize | +0.02 ms (once per save) |

---

## 13. Risks & Follow-ups

| # | Risk / Next | File:Line → Action |
|---|-------------|---------------------|
| 1 | `Util::init_filesystem` lazy `null` on frontend — `Cache` path already lazy via `get_filesystem()` safe. `Main::$filesystem` never read after construct except stored. **No action.** | `class-main.php:346-354`, `class-cache.php:347` |
| 2 | `wppo_should_cache_request` veto `false` with plugin returning non-bool (e.g. `''`) — code casts `(bool)` correctly. Consumer must `return false` strictly. Document in `docs/hooks.md:135` that truthy keeps cache. | `class-cache.php:1524` |
| 3 | `wppo_invalidation_urls` traversal `..` — sanitized via `strpos('..')` skip (non-empty) + `ABSPATH`/`cache_root` prefix guard. Empty string `''` (home) allowed. `wp_normalize_path(trim(...,'/'))` matches `Rest:413-432` pattern. **OK.** | `class-cache.php:1926-1950` |
| 4 | `wppo_database_cleanup_completed` per-type inside `clean_all` loop uses `(int)$res` cast — `WP_Error` already guarded. REST/CLI single-type paths also cast. `do_action` with 2 args; `all` variant passes 3 args `(all,total,results)` — consumer must `function($type,$count,$results=null)`. Document. | `class-database-cleanup.php:737`, `class-rest.php:908` |
| 5 | `wppo_object_cache_config` double-filtered if consumer hooks and `get_redis_config()` already filtered by `enable()` upstream — filtering incoming `$config` in `ping`/`enable` before merge may double-apply. Current code filters incoming `$config` in `ping`/`enable` **after** merge in `get_redis_config()`? Check: `ping` filters incoming `$config` param directly before `connect_internal`; `enable` does same. `get_status` delegates to `get_redis_config` filtered. No double-filter for same config path — separate entry points. **OK.** | `class-object-cache.php:230,253,303` |
| 6 | `Cron::schedule_cron_jobs` still hot — recommend **P11** debounce (`get_transient('wppo_schedule_checked')` 1h) per `PERF-RESEARCH:204`. Keep workers unconditional. | `class-cron.php:114` |
| 7 | Verify with `vendor/bin/phpunit --filter Hook` 12/12 + `vendor/bin/phpunit` 513/513 per `IMPLEMENTATION-LOG` before next PR. Run `microtime(true)` markers at `performance-optimisation.php:41`, `Main::__construct:169`, `Main::setup_hooks:489` for before/after XHProf (PERF-RESEARCH §8 recipe). | `PERF-RESEARCH.md:268` |

---

## 14. Non-Goals Preserved (correct)

- `Util::get_settings():254` per-blog memo + `Util::cached_home_url():861` per-blog memo — zero repeated deserialization.
- `Cache::is_cache_allowed_for_current_user()` + `Cache::is_not_cacheable()` gates still prevent cache writes on non-cacheable responses despite registration.
- `WPPO_CLI_Command` `WP_CLI::add_command` guard `Main:476-478` `defined(WP_CLI) && WP_CLI` handbook-compliant.
- Drop-in `advanced-cache.php` (`class-advanced-cache-handler.php:128`) zero hooks — untouched.
- No `--network`, no `table|csv|yaml`, no `make_progress_bar`, no `--batch-size`, no `wppo_buffer_stage` — all correctly rejected.

---

*Evidence read: `performance-optimisation.php`, `includes/class-main.php:169-799`, `includes/class-util.php:81-443`, `includes/class-cache.php:1331-1960`, `includes/class-cron.php:56-159`, `includes/class-database-cleanup.php:714-750`, `includes/class-object-cache.php:40-260`, `includes/class-wppo-cli-command.php` diff `68a2f66a..7ce4834`, `docs/research/wp-cli-hooks/PERF-RESEARCH.md`, `FINAL-ADVERSARIAL-REVIEW.md`, `IMPLEMENTATION-LOG.md`, `IMPLEMENTATION-PLAN.md`, `docs/hooks.md:42-175`.*
