# WP-CLI & Hooks Test Plan — Research Only

**Date:** 2026-08-28 · **Plugin:** `performance-optimisation` v1.9.0 · **Scope:** `includes/class-wppo-cli-command.php:1-973` (7 subcommands, `@since 1.9.0`, `WPPO_CLI_Command extends WP_CLI_Command`), `includes/class-main.php:436-799` (`includes()` + `setup_hooks()`), 25 `includes/*.php` hook sites.  
**Constraint:** Research-only, do NOT modify production code. Evidence cites `file:line`.  
**Siblings:** `WP-CLI-CURRENT.md` (evidence dump), `WP-CLI-RESEARCH.md` (handbook conventions), `HOOK-AUDIT.md` (272 hook hits), `CURRENT-STATE.md` (stub), `docs/hooks.md` (42 public `wppo_*` hooks).

---

## 1. Executive Summary

`tests/php` has **471 tests across 46 `*Test.php` files** (`phpunit --list-tests | grep -c` at repo root) and **zero CLI/hook-registration tests** (`grep -r WP_CLI tests` hits only `composer.lock:431` `wp-cli/wp-cli ~2.5.0` via `woocommerce/action-scheduler` transitive dep). All 7 `wp wppo` subcommands (`cache`, `database`, `image`, `settings`, `object-cache`, `pagespeed`, `system-info` at `class-wppo-cli-command.php:75,174,321,573,801,902,956`) and ~166 hook registrations (`add_action` ~128 + `add_filter` ~38 per `HOOK-AUDIT.md:11-12`) are untested via automated harness. This plan defines **what to test** (CLI dimensions + hook dimensions), **what infrastructure is missing**, **how to harness it** (Brain Monkey stub vs `wp-cli/wp-cli-tests` Behat), **test matrices**, **file/fixture layout**, **coverage goals**, and **copy-paste examples** — without touching production code.

---

## 2. Current Testing Infrastructure

### 2.1 `phpunit.xml.dist:1-17`

```xml
bootstrap="tests/php/bootstrap.php"
testsuite name="Performance Optimisation PHP Tests" directory suffix="Test.php" path="tests/php"
constants: ABSPATH=/tmp/wordpress/, WP_CONTENT_DIR=/tmp/wordpress/wp-content, WP_CONTENT_URL=http://example.com/wp-content
colors="true" cacheDirectory=".phpunit.cache"
```

- No `WP_CLI` constant defined, no `wp-cli-tests` include, no `patchwork.json` redefinable list extension for `WP_CLI` — so CLI paths are never entered in suite runs (`class-main.php:472` guard `if (defined('WP_CLI') && WP_CLI)` never true).

### 2.2 `tests/php/bootstrap.php:1-285`

- **Patchwork** (`antecedent/patchwork 2.2.3` in `composer.lock`) required before `vendor/autoload.php:31` to allow `redefine()` of real functions. `patchwork.json:2-5` only redefines `function_exists` + `getimagesize` — `WP_CLI` not listed (not needed if stubbed as class, needed if `function_exists('WP_CLI::...')` were patched).
- **`WPPO_Test_Bootstrap` trait** at `bootstrap.php:208-285`:
  - `setUp()` calls `\Brain\Monkey\setUp()` at `:214` + resets `Util::reset_cached_home_urls()` / `clear_settings_cache()` / `Image_Optimisation::clear_file_exists_cache()` + `RUM::$shutdown_buffer` reflection reset at `:221-236`.
  - Pre-registers `wp_parse_url → parse_url`, `wp_normalize_path` closure, `home_url → http://example.com`, `get_current_blog_id → 1`, `WP_Filesystem → false`, `sanitize_text_field`/`wp_unslash` `returnArg`, `trailingslashit` `returnArg`, `__` `returnArg(1)` at `:247-275`.
  - `tearDown()` at `:282-283` calls `\Brain\Monkey\tearDown()`.
  - Provides default `$GLOBALS['wp_object_cache']` anonymous store (`get miss → false` at `:59-60`) so salted cache helpers do not fatal before `ObjectCacheTest` richer stub.
  - Defines constants `WPPO_PLUGIN_PATH` at `:145`, `WPPO_VERSION 1.9.0` at `:151`, `FS_CHMOD_FILE/DIR`, `$wpdb` mock at `:188-203` with `posts/postmeta/comments/commentmeta/options/prefix=wp_`.

### 2.3 Existing Brain Monkey Patterns (evidence from `tests/php/*.php`)

| Pattern | Example | Files |
|---------|---------|-------|
| `Functions\when('fn')->justReturn(val)` | `get_current_blog_id → 1` | `bootstrap.php:260`, `CoreTweaksTest.php:24` |
| `Functions\when('fn')->returnArg()` / `returnArg(1)` | `sanitize_text_field` passthrough | `bootstrap.php:262-263`, `CacheTest.php:37` |
| `Functions\when('fn')->alias('parse_url')` or closure | `wp_parse_url → parse_url`, `content_url` closure | `bootstrap.php:247,264`, `BfcacheTest.php:59` |
| `Functions\when('fn')->alias(fn($path){…})` | Multisite-mocked `home_url` | `BfcacheTest.php:48-59` |
| `Functions\stubs([…])` then `when` | Bulk stub list before alias | `RestTest.php:35-44`, `CoreTweaksTest.php:155-158` |
| `Functions\expect('fn')->with(...)->never()` | Negative expectation | `InlineCssTest.php:380` `apply_filters('wppo_exclude_minification',…)->never()` |
| `Actions\has('hook')` / `Actions\has('hook', callable)` | Registration assertion | `MainUpgradeHookTest.php:82-85`, `ImageOptimisationTest.php:546` |
| `Filters\has('filter', callable, priority)` | Filter assertion | `ImageOptimisationTest.php:546`, `BlockAssetsFiltersTest.php:60` |
| `eval('function wp_dequeue_script_module…')` for late real func | Patch around already-declared `wp_cache_*` via `templates/object-cache.php:39-41` load | `CoreTweaksTest.php:149`, `ObjectCacheTest.php:40-43` |
| `ReflectionMethod/Property::setAccessible` for private | Test private `Util::get_settings` caches | `UtilSettingsCacheTest.php`, `RUM` bootstrap reset |
| `WPPO_DB_Mock extends stdClass` + `$GLOBALS['wpdb']=new class extends WPPO_DB_Mock` | DB mock with `prepare/get_results/query/last_error` | `DatabaseCleanupTest.php:41`, `SystemInfoTest.php` |

- **None** of the 46 suites define `WP_CLI`, `WP_CLI_Command`, `WP_CLI\Formatter`, `WP_CLI\Utils`, or `WP_CLI\ExitException`.
- `Brain\Monkey\Functions\when('WP_Filesystem')->justReturn(false)` at `bootstrap.php:261` already stubs the FS gate used by `WPPO_CLI_Command::settings export/import` at `class-wppo-cli-command.php:603-606,629-632`.

### 2.4 Dependency inventory (`composer.json:40-49`, `composer.lock`)

| Package | Version | Role | CLI relevance |
|---------|---------|------|---------------|
| `phpunit/phpunit` | `^11.0` / `11.5.56` | Runner | Already `phpunit.xsd` via `phpunit.xml.dist:4` |
| `brain/monkey` | `^2.6` / `2.7.0` | WP function/action/filter mock | Hook registration tests; can mock `add_action`/`apply_filters` via `Actions\has`/`Filters\has` |
| `antecedent/patchwork` | `2.2.3` | Redefine internals (`function_exists`, `getimagesize`) | Needed if production code `function_exists('wp_is_block_theme')` branching at `MainBlockAssetsTest.php`; extendable to `WP_CLI` if needed |
| `mockery/mockery` | `1.6.12` (transitive) | Mock builder | Not used directly; could mock `Object_Cache`/`Cache` collaborators for CLI |
| `woocommerce/action-scheduler` | `^4.1` | AS jobs (`wppo_pagespeed_scan`, `wppo_convert_image_background`) | CLI `pagespeed scan` verifies `as_enqueue_async_action` call via `Functions\when('as_enqueue_async_action')` |
| `wp-cli/wp-cli` | `~2.5.0` | **Only in `composer.lock` as AS transitive `suggest`/`require` — not in `composer.json` `require-dev`** | No `wp-cli/wp-cli-tests` or `wp-cli/wp-cli-bundle` installed — confirms harness gap |

---

## 3. Missing Testing Infrastructure

### 3.1 The Gap

| Dimension | Current | Missing |
|-----------|---------|---------|
| **CLI harness** | None | `WP_CLI` class stub + `WP_CLI_Command` base + `WP_CLI\Utils\format_items`/`make_progress_bar` + `WP_CLI\ExitException` |
| **Hook harness** | `Actions\has`/`Filters\has` sporadically (`MainUpgradeHookTest`, `ImageOptimisationTest`) | Systematic `setup_hooks()` registration matrix (priority + accepted_args + conditional gates) |
| **Multisite harness** | `get_current_blog_id → 1` hardcoded at `bootstrap.php:260` | `is_multisite` branching, `switch_to_blog` loop, `Util::transient_key` blog-prefix, `--url` selection |
| **DB for CLI** | `$wpdb` stdClass mock at `bootstrap.php:188-203` | `prepare` placeholder assertion (`LIMIT %d` at `class-database-cleanup.php:630`), `get_results` return shape per cleanup type, `query('OPTIMIZE TABLE')` |
| **FS for CLI** | `WP_Filesystem → false` at `bootstrap.php:261` | In-memory `$wp_filesystem` double (`put_contents`/`get_contents`/`exists`/`dirlist`) for `settings export --file` at `class-wppo-cli-command.php:602-632` and `cache status` stats walk |
| **Transient/Option for CLI** | `get_option('wppo_settings')` alias per-test | `wppo_settings` full default structure at `WPPO_CLI_Command::get_default_settings():451-522` vs stored partial merge at `:582-588`; `wppo_cache_size` (15min) for `cache status`; `wppo_img_info` for `image status/convert`; `wppo_preload_cron_lock` |
| **AS for CLI** | Not stubbed | `as_enqueue_async_action('wppo_pagespeed_scan', …)` at `class-pagespeed.php:119-133` stubbed to capture `$job_id` |

### 3.2 Approach Comparison: Brain Monkey Stub vs `wp-cli/wp-cli-tests`

| Criterion | **A. Brain Monkey stub (recommended for this repo)** | **B. `wp-cli/wp-cli-tests` + Behat** |
|-----------|-----------------------------------------------------|--------------------------------------|
| **Install** | Zero new prod dep; add dev class stubs (`tests/php/stubs/wp-cli.php`) + extend `bootstrap.php` with 40 lines | `composer require --dev wp-cli/wp-cli-tests` + Behat 3.x + `wp-cli/wp-cli-bundle` (adds `behat/mink`, `gherkin`, heavier) |
| **Alignment** | Matches 471-test suite at `tests/php/*.php` using `WPPO_Test_Bootstrap` + `Functions\when` — zero paradigm shift | Introduces second runner (`behat --tags=@wppo`) + `features/*.feature` Gherkin; duplicates `phpunit.xml.dist:7` suite |
| **Mock granularity** | Per-test `Functions\when('get_option')` / `$wpdb->get_results` closure — fine-grained, fast, deterministic (`jsdom` analogue for PHP) | Spawns real WP + `wp wppo …` binary via `WP_CLI\Tests` harness (`runcommand`) — integration truth but needs SQLite/MySQL + `WP_CONTENT_DIR` |
| **Exit-code testing** | Throw `ExitException` from `WP_CLI::error()` stub, catch in test — exact handbook `WP_CLI::error($msg,$exit=true)` at `class-wppo-cli-command.php:95` etc. | Binary `$?` assertion via `assertExitCode` helper in `wp-cli-tests` (`ExitException` captured by `WP_CLI::runcommand`) |
| **Speed** | ~0.02s/test (in-process, no fork) — suite stays <5s | ~0.5s/scenario (fork + WP bootstrap) — suite 10-20× slower |
| **Multisite** | `Functions\when('get_current_blog_id')->justReturn(2)` + `switch_to_blog` alias loop — already at `bootstrap.php:260` | Real `wp --url=https://sub.example.com wppo …` + `wp site switch` — heavier but catches `switch_to_blog` leaks (e.g. `WPPO_Test_Bootstrap` site-keyed cache leak at `Util::reset_cached_home_urls()`) |
| **When to use** | **Unit + registration + filter + priority + error-path** — covers 90% of matrices below | Opt-in **integration smoke** (1-2 scenarios) when CI has DB — e.g. `wp wppo cache clear` actually deletes `wp-content/cache/wppo/…/index.html` |
| **CI** | Existing `composer test` at `composer.json:65` (`phpunit`) unchanged | Requires `psalm-wpcs-check.yml`-style job + DB service container (MySQL) |
| **Recommendation** | **Primary:** extend `bootstrap.php` + `tests/php/stubs/wp-cli.php` (section 3.3) and write `tests/php/WppoCli*Test.php` + `Hook*Test.php` via Brain Monkey | **Secondary:** add `features/wppo-cli.feature` Behat file gated behind `WP_CLI_TEST_MODE` for release-candidate only |

### 3.3 Proposed `bootstrap.php` Extension (diff sketch, not applied)

```php
// After require vendor/autoload.php:31 and object-cache.php template load:39:
if (!class_exists('WP_CLI')) {
    require_once __DIR__ . '/stubs/wp-cli.php'; // defines WP_CLI, WP_CLI_Command, WP_CLI\ExitException, WP_CLI\Utils, WP_CLI\Formatter stubs
}
if (!class_exists('WP_CLI_Command')) { eval('class WP_CLI_Command {}'); }

// In WPPO_Test_Bootstrap::setUp():214 after Brain\Monkey\setUp():
\WP_CLI::reset_captured(); // clears log/success/warning/error buffers, exit_code null
Functions\when('WP_Filesystem')->justReturn(false); // already at bootstrap.php:261, keep
// Pre-register WP_CLI internal helpers used by WPPO_CLI_Command:
// - Util::get_settings() via get_option('wppo_settings') → use array_replace_recursive(defaults, stored) at class-wppo-cli-command.php:582-588
// - Cache::get_cache_stats() via WP_Filesystem::dirlist at class-cache.php:2184
// - Database_Cleanup::get_counts() via $wpdb->get_results at class-database-cleanup.php:842-925
```

### 3.4 New Stub `tests/php/stubs/wp-cli.php` (needed)

Stub must implement handler book contract at `WP_CLI::success` (`php/class-wp-cli.php`) — `success` → stdout `Success:` exit 0; `error($msg,$exit=true)` → stderr `Error:` + throw `ExitException(1)`; `warning` → stderr continue 0; `log`/`line` → stdout; `confirm` respects `--yes`; `halt($code)`; `colorize`; `debug`.

Minimal interface:

```php
class WP_CLI {
    public static array $captured = ['log'=>[],'success'=>[],'warning'=>[],'error'=>[],'debug'=>[]];
    public static ?int $exit_code = null;
    public static function log($msg){ self::$captured['log'][] = $msg; }
    public static function success($msg){ self::$captured['success'][] = $msg; }
    public static function warning($msg){ self::$captured['warning'][] = $msg; }
    public static function error($msg, $exit=true){ self::$captured['error'][] = $msg; if($exit) throw new \WP_CLI\ExitException($msg, 1); }
    public static function debug($msg,$group=false){ self::$captured['debug'][] = $msg; }
    public static function confirm($q,$assoc=[]){ if(!empty($assoc['yes'])) return true; throw new \WP_CLI\ExitException('confirm aborted', 1); }
    public static function halt($code){ throw new \WP_CLI\ExitException('', $code); }
    public static function reset_captured(){ self::$captured=['log'=>[],'success'=>[],'warning'=>[],'error'=>[],'debug'=>[]]; self::$exit_code=null; }
}
namespace WP_CLI; class ExitException extends \Exception { public function __construct($msg,$code=0){ parent::__construct($msg,$code); } }
// + WP_CLI\Utils::get_flag_value, format_items, make_progress_bar (NoOp when mocked), WP_CLI\Formatter
```

Without this file, `WPPO_CLI_Command` cannot be instantiated in tests (`use WP_CLI; use WP_CLI_Command;` at `class-wppo-cli-command.php:14-15` fails `class not found`).

---

## 4. CLI Tests Required

### 4.1 Common Cross-Cutting Dimensions (every subcommand)

| Dimension | What to test | How (Brain Monkey harness) | Evidence / Handbook |
|-----------|--------------|----------------------------|---------------------|
| **Args — positional** | Default when omitted (`cache` → `clear` at `:76`, `database` → `cleanup` at `:175`, `image` → `status` at `:322`, `settings` → `get` at `:574`, `object-cache` → `status` at `:802`, `pagespeed` → `scan` at `:903`) vs explicit vs excess args | Call `new WPPO_CLI_Command()->cache([],[])` vs `cache(['preload'],[])` vs `cache(['clear','extra'],[])` — assert handler book required vs optional synopsis note (should be `[<action>]`) | `WP-CLI-RESEARCH.md:66` gap |
| **Args — assoc** | Required-for-branch (`settings update` needs `--settings` at `:713`, `import` needs `--file` at `:623`), optional (`--page` at `cache:99`, `--tables` at `database:178`, `--format` at `image:323`, `--host/port/…` at `object-cache:864-871`) | `assoc_args` array with/without key, assert `WP_CLI::error` throw vs `log` | `class-wppo-cli-command.php:52,134-152,298-308,393-417,754-773,876-885` |
| **Validation — allowlist** | Invalid action/type/table/format/group → `error` exit 1; unknown table skip → `warning` continue | Assert `ExitException` code 1 vs `warning` captured (`database optimize` unknown table at `:184` → `warning` + continue, per-type `WP_Error` at `:220` → `warning`) | `WP-CLI-CURRENT.md:2.2` |
| **Validation — type** | JSON parse (`settings import` at `:637-639` `json_decode` + `is_array`, `update` at `:719-722`), YAML fallback (`settings get --format=yaml` at `:692-700`), URL via `cached_home_url` default at `pagespeed:904` | Supply `'{bad json'` → assert error; `json_last_error_msg` branch | `class-wppo-cli-command.php:637-639` |
| **Validation — FS/path** | `settings export --file` `WP_Filesystem` gate at `:603-607` → error if `! $wp_filesystem`, `put_contents` false at `:610` → error, `exists` false at `:631` → error; `cache --page` `wp_parse_url PATH` + `wp_normalize_path` at `:102` + `Cache::clear_cache` internal `..` reject | Mock `$wp_filesystem = null/mock` with `exists→true/false`, `put_contents→true/false` | `class-wppo-cli-command.php:602-632`, `HOOK-AUDIT.md` path traversal note |
| **Error handling** | `WP_Error` from `Database_Cleanup::clean_*` at `:219-220` → `warning`, `false` at `:284-286` → `error`, `Object_Cache::ping/enable/disable` `is_wp_error` at `:814-815,824-825,834-835` | Return `new WP_Error('code','msg')` from mocked service, assert captured `warning`/`error` + message contains `get_error_message()` | `class-wppo-cli-command.php:219,279-286,814-817` |
| **Exit codes** | `success` → 0, `error` → 1, `warning` → 0 continue; no explicit `exit`/`die` — relies on `ExitException` | Catch `ExitException`, assert `getCode()===1` for `invalid action` at `:95,208,390,749,854,931` etc., assert no throw for `warning` branches | `WP-CLI-RESEARCH.md:116-133` |
| **Output — format** | JSON `wp_json_encode(PRETTY+SLASHES)` at `cache:87-89`, `database:202`, `image:385`, `settings:609,617,702`, `object-cache:808`, `pagespeed:926`, `system-info:966,970` vs plain `log(sprintf…)` at `cache:87-89` vs `success('…')` | Assert `WP_CLI::$captured['log'][0]` is valid JSON (`json_decode` + `JSON_ERROR_NONE`), assert `success` prefix not in `log` | `class-wppo-cli-command.php:87-89,202,385,808,926` |
| **Output — channels** | `log` → stdout (data), `success` → stdout green, `warning`/`error` → stderr | After mock, assert `captured['log']` vs `captured['success']` vs `captured['warning']`/`error` bucket | `WP-CLI-RESEARCH.md:90-92` |
| **Dry-run** | No `--dry-run` exists (handbook `wp search-replace --dry-run` at `WP-CLI-RESEARCH.md:184-186`) — future `database cleanup --dry-run` should preview counts without `DELETE`, `cache clear --dry-run` list files without `unlink` | For existing impl: assert **absence** (calling with `--dry-run` ignored) — regression guard that dry-run flag not silently treated as value; for future impl: add `get_flag_value($assoc_args,'dry-run')` branch → `warning`+return without `delete_in_batches` | `WP-CLI-CURRENT.md:97-98` |
| **Multisite** | No explicit `switch_to_blog` in CLI (`WP-CLI-CURRENT.md:104`), but dependencies are site-scoped (`Util::get_settings` blog-keyed at `class-util.php:91-157`, `Cache` domain dirs multisite-safe `AGENTS.md:159`, `Object_Cache` `get_current_blog_id()` prefix) | Set `is_multisite→true`, `get_current_blog_id→2`, `get_option` per-blog closure at `bootstrap.php:260` already; assert CLI respects `WP-CLI --url` resolved blog via `Util::cached_home_url` at `pagespeed:904`/`object-cache` not leaking blog 1 settings (mirrors `AUDIT/IMPLEMENTATION-LOG.md:354` leak fix) | `class-wppo-cli-command.php:104,326,577,904`, `AGENTS.md:158-163` |
| **Idempotency** | `cache clear` twice, `object-cache disable` when already disabled, `image convert` with `total_pending=0` | Call twice, assert second still `success` or `error` as defined (`Cache::clear_cache()` idempotent → success) | `class-wppo-cli-command.php:118-122` |
| **Activity log** | `Log::add()` on success paths (`cache:80,107,119`, `database:194,229,291`, `image:363`, `settings:673,742`, `object-cache:827,837,845`, `pagespeed:914`) | Mock `Log::add` via `Functions\when('...')` alias capture or `Mockery::mock('alias:PerformanceOptimise\Inc\Log')` — assert called with expected sprintf | `class-wppo-cli-command.php` per subcommand |

### 4.2 Per-Subcommand Matrix

#### `wp wppo cache {clear|preload|status} [--page=<url>]` — `class-wppo-cli-command.php:43-124`

| # | Args | Mock / Setup | Expected output + exit | Current bug/risk |
|---|------|--------------|------------------------|------------------|
| C1 | `[]` (default `clear`) | `Cache::clear_cache()→true` | `success 'Static HTML cache cleared successfully.'` at `:120`, exit 0 + `Log::add` at `:119` | — |
| C2 | `['clear']` | `Cache::clear_cache()→false` | `error 'Failed to clear static HTML cache.'` at `:122` throw 1 | — |
| C3 | `['clear'] --page=/sample-page/` | `wp_parse_url` returns `/sample-page/` → `wp_normalize_path` trims `/` → `Cache::clear_cache('sample-page')→true` | `success 'Cache cleared for page: /sample-page/'` at `:109` | Path traversal: `../etc/passwd` → normalized `../etc/passwd` then `Cache::clear_cache` internal `..` reject — test that CLI normalizes but delegates reject |
| C4 | `['clear'] --page=/bad/../path` | Same | `error 'Failed to clear cache for page: …'` at `:112` (service returns false) | Document `realpath` gap vs REST at `class-rest.php:413-432` |
| C5 | `['preload']` | `Cron::trigger_preload` mock, `Log::add` capture | `success 'Cache preload initiated…'` at `:81`, `Log::add('Cache preload triggered via WP-CLI')` at `:80` | No-op when `preload_settings.enablePreloadCache` off at `class-cron.php:265-268` — mock `Util::get_settings` to gate |
| C6 | `['status']` | `Cache::get_cache_stats()→['size'=>'12.3 MB','cached_pages'=>42,'last_cleared'=>'2026-08-28']` | Three `log('Cache size: …')` at `:87-89` | Always human logs, not JSON — script consumers need `json` flag (future `--format`) |
| C7 | `['invalid']` | — | `error 'Invalid cache action "invalid". Use "clear", "preload", or "status".'` at `:95` throw 1 | Synopsis marks `<action>` required but code defaults to `clear` — test default vs explicit invalid |
| C8 | multisite: `get_current_blog_id()=2` + `['clear']` | `$wpdb->prefix='wp_2_'` variant, `Util::transient_key` blog-prefix | Assert `Cache::clear_cache` called with site 2 domain dir (`wp-content/cache/wppo/{domain2}/…`) | Leak regression at `AUDIT/IMPLEMENTATION-LOG.md:354` |

#### `wp wppo database {cleanup|optimize|counts} [--type=<type>] [--tables=<csv>]` — `class-wppo-cli-command.php:126-294`

| # | Args | Mock / Setup | Expected |
|---|------|--------------|----------|
| D1 | `['counts']` | `Database_Cleanup::get_counts()→['revisions'=>3,…9 keys]` | `log` JSON pretty at `:202` with 9 keys, exit 0 |
| D2 | `['optimize']` default tables | `Database_Cleanup::TABLE_MAP` allowlist at `:180` → unique `posts,postmeta,comments,commentmeta,options`, per table `optimize_table→true` | Per-table `log - Optimized table: …` at `:190` + `success 'Database optimization complete: N/N tables optimized.'` at `:196` |
| D3 | `['optimize'] --tables=posts,UNKNOWN,options` | `optimize_table` only for allowlisted | `warning ' - Skipped unknown table: UNKNOWN'` at `:184` + `success '1/3'?` (bug: denom `count($table_list)` includes unknown at `:196` — assert 2/3 success with warning) |
| D4 | `['cleanup'] --type=revisions` | `clean_revisions()→5` | `success 'Database cleanup completed for revisions (5 items removed).'` at `:293` + `Log::add` at `:291` |
| D5 | `['cleanup'] --type=trashed` alias | `clean_trashed_comments()→2` | Same success path — alias `trashed` at `:254` maps to `clean_trashed_comments` |
| D6 | `['cleanup'] --type=unattached` alias | `clean_unattached_media()→1` | Same — `unattached_media`/`unattached` at `:265-267` |
| D7 | `['cleanup'] --type=oembed` alias | `clean_oembed_cache()→4` | Same — `oembed_cache`/`oembed` at `:269-271` |
| D8 | `['cleanup'] --type=all` | `clean_all()→['revisions'=>3,'auto_drafts'=> WP_Error('msg')]` | `warning ' - auto_drafts: msg'` at `:220` + `log` per success at `:225` + `success '… (all): total items removed.'` at `:231` |
| D9 | `['cleanup'] --type=invalid` | — | `error 'Invalid cleanup type "invalid".'` at `:275` throw 1 |
| D10 | `['cleanup'] --type=revisions` where `clean_revisions()→ WP_Error('db fail')` | `is_wp_error` at `:279-280` | `error 'db fail'` throw 1 |
| D11 | `['cleanup'] --type=revisions` where service returns `false` | At `:284-286` | `error 'Database cleanup failed for type "revisions".'` throw 1 |
| D12 | `['invalid']` action | — | `error 'Invalid database action "invalid". Use "cleanup", "optimize", or "counts".'` at `:208` throw 1 |
| D13 | `--dry-run` (future) | `get_flag_value($assoc,'dry-run')` branch | `log` preview counts + `warning 'Dry run — no rows deleted'` no `Log::add` |
| D14 | multisite `get_current_blog_id=2` + `counts` | `Util::transient_key` blog-prefix | Assert `get_counts` reads site 2 transient, not blog 1 |

#### `wp wppo image {convert|status} [--format=<format>]` — `class-wppo-cli-command.php:296-391`

| # | Args | Setup | Expected |
|---|------|-------|----------|
| I1 | `['status']` (default) | `Img_Converter::get_img_info()→['pending'=>['webp'=>2,'avif'=>1],'completed'=>…]` | `log` JSON `total_pending/total_completed/pending/webp,avif` at `:385` |
| I2 | `['convert'] --format=webp` | `Util::get_settings()→['image_optimisation'=>['conversionFormat'=>'webp','batch'=>50]]`, `get_img_info pending webp=[' /uploads/a.jpg', ' /uploads/b.jpg']`, `realpath` + `ABSPATH` prefix ok | `success 'Image conversion complete: 2/2 images processed.'` at `:365` + `Log::add` at `:363`, per-image `convert_image()` called twice capped by `batch_size` |
| I3 | `['convert'] --format=both` | Same but `conversionFormat='both'` | Two loops `avif` + `webp` at `:343-360`, total `4/4` if 2 each |
| I4 | `['convert'] --format=invalid` | `in_array(..., ['avif','webp'])` false at `:335` → empty `formats_to_process` | `success 'Image conversion complete: 0/0 images processed.'` (invalid silently 0) — should be `warning`? |
| I5 | `['convert']` where `realpath` escapes `ABSPATH` | `source_path=ABSPATH+'../etc/passwd'`, `realpath` outside `normalized_abspath` at `:354-355` | `continue` skip, not counted in `converted` but `total_pending` still counts pending |
| I6 | `['convert']` with `batch=1` + 5 pending | `batch_size=1` at `:330` | Only `1` converted despite `5` pending — `counter>=batch_size` break at `:348-350` |
| I7 | `['invalid']` | — | `error 'Invalid image action "invalid". Use "convert" or "status".'` at `:390` throw 1 |

#### `wp wppo settings {get|update|export|import} [<tab>] [--settings=<json>] [--file=<path>] [--format=json|yaml]` — `class-wppo-cli-command.php:392-750`

| # | Args | Setup | Expected |
|---|------|-------|----------|
| S1 | `['get']` (all) default json | `Util::get_settings()→[]` + `get_default_settings():451-522` fallback at `:582-584` | `log` JSON `wppo_settings` at `:702` |
| S2 | `['get','file_optimisation']` | `options['file_optimisation']` exists | `log` JSON single tab at `:685-688,702` |
| S3 | `['get','invalid_tab']` | `!isset($options[$tab])` at `:680-682` | `error 'Invalid settings tab "invalid_tab". Available tabs: …'` throw 1 |
| S4 | `['get'] --format=yaml` with `Spyc` exists | `class_exists('Spyc')→true` at `:693` | `log` YAML via `Spyc::YAMLDump` at `:694` |
| S5 | `['get'] --format=yaml` without Spyc/yaml_emit | `class_exists→false, function_exists yaml_emit→false` at `:695-698` | `warning 'YAML dumper not available; falling back to JSON'` + JSON at `:698-699` |
| S6 | `['update','file_optimisation'] --settings='{"minifyHTML":true}'` | `json_decode` at `:719` → array, `sanitize_settings_recursively` at `:726` | `update_option('wppo_settings',…)` at `:739` + `Log::add` + `success 'Settings updated successfully for tab "file_optimisation".'` at `:744` |
| S7 | `['update']` without tab | `! $tab` at `:708-710` | `error 'Please specify a settings tab…'` throw 1 |
| S8 | `['update','file_optimisation']` without `--settings` | `! $json` at `:713-715` | `error 'Please provide a JSON object string…'` throw 1 |
| S9 | `['update',…] --settings='bad json'` | `!is_array(json_decode)` at `:720-722` | `error 'Invalid JSON settings provided.'` throw 1 |
| S10 | `['update','unknown_tab'] --settings='{"x":1}'` | `!in_array($tab, ALLOWED_SETTINGS_TABS)` at `:728-732` | `warning 'Unrecognized settings tab "unknown_tab". Settings will be saved but not read.'` + still `success` at `:744` |
| S11 | `['export']` no file | Strip `object_cache.password` + `performance_audit.pagespeed_api_key` at `:593-599` then `log` JSON at `:617` | `log` JSON without secrets |
| S12 | `['export'] --file=/tmp/out.json` | `$wp_filesystem→mock`, `put_contents→true` at `:606-615` | `success 'Settings exported to /tmp/out.json'` at `:615` |
| S13 | `['export'] --file=/tmp/out.json` where `$wp_filesystem` null | `! $wp_filesystem` at `:605-606` | `error 'Unable to initialize filesystem.'` throw 1 |
| S14 | `['export'] --file=/tmp/out.json` where `put_contents→false` | At `:610-611` | `error 'Failed to write settings to file.'` throw 1 |
| S15 | `['import'] --file=/tmp/in.json` valid + `password` + `pagespeed_api_key` | `get_contents→json`, allowlist `ALLOWED_SETTINGS_KEYS` at `:644-651`, sanitize at `:654`, strip `object_cache.password` → `password_set=true` at `:657-662`, strip `performance_audit.pagespeed_api_key` at `:665-667` | `update_option` merged at `:671` + `success 'Settings imported successfully.'` at `:674` |
| S16 | `['import']` without `--file` | `! $file` at `:624-625` | `error 'Please provide a --file=<path> parameter.'` throw 1 |
| S17 | `['import'] --file=/nonexistent` | `!exists→true` at `:631-632` | `error 'File not found or filesystem unavailable.'` throw 1 |
| S18 | `['import'] --file=…` bad JSON | `!is_array` at `:638-639` | `error 'Invalid JSON in settings file.'` throw 1 |
| S19 | `['import'] --file=…` with `invalid_key` | `!in_array($key, ALLOWED_SETTINGS_KEYS)` at `:646-648` | `error 'Invalid setting key "invalid_key" detected.'` throw 1 |
| S20 | `['invalid']` action | At `:749` | `error 'Invalid settings action "invalid". Use "get", "update", "export", or "import".'` throw 1 |

#### `wp wppo object-cache {status|ping|enable|disable|flush} [--host=<h>] [--port=<p>] …` — `class-wppo-cli-command.php:752-856`

| # | Args | Setup | Expected |
|---|------|-------|----------|
| O1 | `['status']` (default) | `Object_Cache::get_status()→['enabled'=>true,…]` at `:807-808` | `log` JSON status |
| O2 | `['ping'] --host=127.0.0.1 --port=6379` | `get_redis_config_from_assoc` at `:864-871` extracts 6 keys, `ping($config)→true` | `success 'Redis server is reachable.'` at `:817` |
| O3 | `['ping']` where `ping→WP_Error('unreachable')` | `is_wp_error→true` at `:814-815` | `error 'unreachable'` throw 1 |
| O4 | `['enable'] --host=… --port=…` success | `enable($config)→true` | `Log::add` + `success 'Redis Object Cache enabled successfully.'` at `:827-828` |
| O5 | `['enable']` where `enable→WP_Error('foreign_dropin')` | At `:824-825` | `error` throw 1 |
| O6 | `['disable']` success | `disable()→true` at `:833` | `Log::add` + `success '… disabled …'` at `:837-838` |
| O7 | `['disable']` where `disable→WP_Error` | At `:834-835` | `error` throw 1 |
| O8 | `['flush']` where `flush()→true` | At `:843-846` | `Log::add` + `success '… flushed successfully.'` |
| O9 | `['flush']` where `flush()→false` | At `:847-848` | `error 'Failed to flush Redis Object Cache.'` throw 1 |
| O10 | `['invalid']` | At `:852-854` | `error 'Invalid object-cache action "invalid". Use "status", "ping", "enable", "disable", or "flush".'` throw 1 |
| O11 | `['enable'] --mode=cluster` (unsupported) | `get_redis_config_from_assoc` ignores `mode` at `:864-871` vs REST `build_redis_config` at `class-rest.php:1104-1142` (10 keys) | Document gap: CLI drops `mode,nodes,master_name,use_tls,persistent,compression` — test asserts those keys ignored |
| O12 | multisite + `status` | `get_current_blog_id→2` | Assert drop-in path `wppo-redis-config.php` read site-scoped |

#### `wp wppo pagespeed {scan|results} [--url=<url>] [--strategy=mobile|desktop]` — `class-wppo-cli-command.php:874-932`

| # | Args | Setup | Expected |
|---|------|-------|----------|
| P1 | `['scan'] --url=https://example.com --strategy=mobile` (default scan) | `Util::cached_home_url()→home`, `Pagespeed::queue_scan(url,strategy)→123` at `:908` | `Log::add` + `success 'PageSpeed scan queued. Job ID: 123'` at `:914-916` |
| P2 | `['scan']` where `queue_scan→0` | At `:909-910` | `error 'Failed to queue PageSpeed scan. Action Scheduler may be unavailable.'` throw 1 |
| P3 | `['results'] --url=…` where `get_results→false` | At `:921-923` | `warning 'No PageSpeed results found…'` exit 0 (handbook binary negative: should perhaps be `error` — document policy) |
| P4 | `['results']` where results exist | `get_results→['lighthouseResult'=>…]` | `log` JSON at `:926` |
| P5 | `['invalid']` | At `:931` | `error 'Invalid pagespeed action "invalid". Use "scan" or "results".'` throw 1 |

#### `wp wppo system-info [<group>]` — `class-wppo-cli-command.php:934-972`

| # | Args | Setup | Expected |
|---|------|-------|----------|
| SI1 | `[]` (all groups) | `System_Info::get_all()→['php'=>…, 'database'=>…7 keys + litespeed,opcache]` at `:957` | `log` JSON all groups at `:970` |
| SI2 | `['php']` | `isset($all['php'])→true` at `:961-962` | `log` JSON single group at `:966` |
| SI3 | `['invalid']` | `!isset($all[$group])` at `:961-962` | `error 'Invalid system info group "invalid".'` throw 1 |

---

## 5. Hook Tests Required

### 5.1 Registration

| Area | Hook | Type | File:Line | Priority / Accepted Args | Expected test |
|------|------|------|-----------|--------------------------|---------------|
| CLI | `WP_CLI::add_command('wppo', WPPO_CLI_Command)` | `WP_CLI` API (not WP hook) | `class-main.php:472-474` inside `Main::includes()` | Guard `defined('WP_CLI') && WP_CLI` before call | `WppoCliRegistrationTest::test_registers_only_when_wp_cli_constant_true` — define `WP_CLI true` then `new Main()` assert `WP_CLI::$added_commands['wppo'] === WPPO_CLI_Command::class` via captured stub; also test `!defined` → no registration |
| Cache invalidation | `save_post → on_save_post_invalidate_cache` | `add_action` | `class-main.php:552` | priority 10, 3 args | `HookRegistrationTest::test_save_post_invalidate_registered_when_cache_enabled` — `Functions\when('is_admin')…` + `new Main(['cache_settings'=>['enableCache'=>true]])` then `Actions\has('save_post', [Main::class,'on_save_post_invalidate_cache'],10)` |
| Cache buffer 6.9+ | `wp_template_enhancement_output_buffer → process_buffer_for_cache` | `add_filter` | `class-main.php:545` | 10, 2 args | Assert filter registered only when `enableCache` true + version gate `wp_should_output_buffer_template_for_enhancement` |
| Cache fallback | `template_redirect → start_output_buffer` | `add_action` | `class-main.php:550` | default priority | Assert registered only when `<6.9` or `!wp_should_output_buffer…` — version-gated |
| Admin menu | `admin_menu → init_menu` | `add_action` | `class-main.php:486` | 10, 0 args | Always registered |
| REST | `rest_api_init → register_routes` | `add_action` | `class-main.php:615` | 10 | Always registered → 25 `register_rest_route` calls at `class-rest.php:58-260` |
| Cron | `init → schedule_cron_jobs` + `cron_schedules → add_custom_cron_interval` + `wppo_*` workers | `add_action`/`add_filter` | `class-cron.php:57-74` | 10 | Always registered — `Actions\has('init','Cron::schedule_cron_jobs')`, `Filters\has('cron_schedules')`, etc. |
| Settings cache | `update_option_wppo_settings → on_settings_update` (Main + Util + Llms) | `add_action` | `class-main.php:789`, `class-util.php:245-247`, `class-main.php:637` | 10, 2 args | Always — assert 3 listeners on same hook |
| Image | `wp_generate_attachment_metadata → convert_image_to_next_gen_format` | `add_filter` | `class-image-optimisation.php:185` | 10, 2 args | Conditional `convertImg` true |
| RUM | `wp_enqueue_scripts → RUM::maybe_enqueue_scripts` | `add_action` | `class-main.php:619` | 5, 0 args | Always-registered, early-return when `rum_enabled` false |

*Full 272-hit list at `HOOK-AUDIT.md:1-353` — each `add_action`/`add_filter` needs a row. Prioritize the 42 public `wppo_*` hooks (`docs/hooks.md`) for coverage.*

### 5.2 Invocation

Fire the hook via `do_action`/`apply_filters` and assert side effect:

| Hook fired | Setup | Assertion |
|------------|-------|-----------|
| `wppo_before_cache_clear` + `wppo_after_cache_clear` | `do_action('wppo_before_cache_clear','all',null)` capture via `Functions\when('do_action')->alias` at `class-cache.php:2032,2074` | Assert `CDN_Purger::purge_all` called on `wppo_after_cache_clear` (consumer at `class-main.php:623,626` priority 10 vs 20) — use `Actions\has` + invoke |
| `update_option_wppo_settings` | `do_action('update_option_wppo_settings', $old,$new)` after `new Main()` | Assert `Cache::clear_cache()` called when `cache_settings/file_optimisation/…` diff at `class-main.php:1032-1131`; assert `Util::on_settings_update` bumps cache memo |
| `switch_blog 2,1` | `do_action('switch_blog',2,1)` | Assert `Util::on_switch_blog` clears `cached_home_url` + settings memo at `class-util.php:248` (multisite leak regression) |
| `save_post` with `is_admin` | `do_action('save_post',123, $post,true)` | Assert `on_save_post_invalidate_cache` + `Database_Cleanup::on_post_change` salt bump |
| `wppo_database_cleanup_completed` | `do_action('wppo_database_cleanup_completed','all',10,[])` at `class-database-cleanup.php:737` | Assert operator hook invocable (no prod consumer, but public) — test that `clean_all` fires it only for `type=all` not per-type (gap at `HOOK-AUDIT.md:196`) |
| `wppo_cache_page_html` filter | `apply_filters('wppo_cache_page_html', '<html>…','https://example.com')` at `class-cache.php:1661` | Assert HTML mutated by filter return value before `save_processed_buffer` |

### 5.3 Filter Modification

| Filter | File:Line | Test |
|--------|-----------|------|
| `wppo_exclude_minification` | `class-main.php:2747,2849` 4 args `(bool $exclude, $file_path,$handle,$type)` | `apply_filters('wppo_exclude_minification', false, '/path/app.css','my-handle','css')` → assert `__return_true` skips minify (already tested negatively at `InlineCssTest.php:380` `never()` — add positive) |
| `wppo_exclude_delay_js` / `wppo_exclude_defer_js` | `class-main.php:701,722` | `apply_filters('wppo_exclude_delay_js',['old'])` + filter pushes `my-critical` → assert `setup_hooks()` result contains `my-critical` |
| `wppo_cache_page_html` | `class-cache.php:1661` 2 args | Filter appends `<!-- sig -->` → assert saved `index.html` contains sig |
| `wppo_inline_combined_css` | `class-cache.php:980` 1 arg `bool $inline` (default true) | `add_filter('wppo_inline_combined_css','__return_false')` → assert `register_combine_css_path` skips `wp_maybe_inline_styles` |
| `wppo_litespeed_can_cdn` + `litespeed_can_cdn` ecosystem | `class-cache.php:1349-1353` | `add_filter('wppo_litespeed_can_cdn','__return_false')` → assert `maybe_apply_cdn()` skipped |
| `wppo_server_timing_enabled` | `class-main.php:1252` | `add_filter('wppo_server_timing_enabled','__return_false')` → assert `server_timing_enabled()` false despite setting |
| `wppo_cron_discovery_limit` | `class-cron.php:666` | `add_filter('wppo_cron_discovery_limit', fn()=>10)` → assert `img_convert_cron` discovers 10 not 50 |
| `wppo_bfcache_enabled` (etc. 20+ `NEXT` filters) | `class-bfcache.php:85`, `class-ai-adaptive.php:60`, `docs/hooks.md:315-387` | Each public `wppo_*` filter needs one `apply_filters` + consumer test |

### 5.4 Priority & Accepted Args

| Hook | Declared | Test |
|------|----------|------|
| `combine_css` on `wp_enqueue_scripts` | `PHP_INT_MAX` at `class-main.php:608` | Assert `Actions\has('wp_enqueue_scripts', [Cache::class,'combine_css'])` returns `PHP_INT_MAX` — ensures runs last after theme enqueues |
| `minify_queued_styles` | `PHP_INT_MAX-1` at `class-main.php:676` | Assert priority `PHP_INT_MAX-1` — just before combine |
| `add_defer_attribute` on `script_loader_tag` | priority 10 at `class-main.php:515`, 2 args | Assert `Filters\has('script_loader_tag','Main::add_defer_attribute',10) === 10` and `10,2` accepted_args |
| `add_fetchpriority_to_deferred` | priority 11 at `class-main.php:530` | Assert 11 > 10 (runs after defer) |
| `maybe_preload_combine_css` on `wp_head` | priority 1 at `class-main.php:611` | Assert 1 < core 8 (before core prints `<link>`) |
| `admin_bar_menu → add_setting_to_admin_bar` | priority 100 at `class-main.php:533` | Assert 100 |
| `apply_per_page_delay_config` on `wp` | 10 at `class-main.php:516` | Assert 10 |
| `wppo_after_cache_clear` consumers | `CDN_Purger::purge_all` default 10 at `class-main.php:623` + `Edge_Purger::purge_all` 20 at `class-main.php:626` | Assert `Actions\has('wppo_after_cache_clear', [CDN_Purger::class,'purge_all'])===10` + `Edge_Purger 20` — order matters |

### 5.5 Context-Specific Loading (conditional gates)

| Condition | Hooks present / absent | Test strategy |
|-----------|------------------------|---------------|
| `enableCache` false | `save_post` invalidate absent at `class-main.php:539-553`, `template_redirect` buffer absent at `:545-550`, `combine_css` absent | `new Main(['cache_settings'=>['enableCache'=>false]])` then `Actions\has('save_post') === false` |
| `enableCache` true + `wp_should_output_buffer_template_for_enhancement` true (WP 6.9+) | `wp_template_enhancement_output_buffer` filter at `:545` present, `template_redirect` fallback absent | Mock `function_exists('wp_should_output_buffer_template_for_enhancement')→true`, `wp_should_output_buffer_template_for_enhancement()→true` via `Functions\when` + `Patchwork\redefine('function_exists',…)` if needed |
| `removeUnusedCSS` true but `enableCache` false | Standalone `process_used_css_only` at `:568` (priority 20) + `start_used_css_buffer` at `:572` | Assert those 2 present while cache buffer absent |
| `hostGoogleFontsLocally` true | `style_loader_tag → Google_Fonts::process_style_tag` at `:688` priority 9 | Assert present only when true |
| `is_admin()` true vs false | `is_admin` guard at `class-main.php:595` for `save_post`/`deleted_post` DB salt bump at `Database_Cleanup::on_post_change` | `Functions\when('is_admin')->justReturn(true)` vs false, assert registration differs |
| `WP_CLI` defined true vs false | `WP_CLI::add_command` at `class-main.php:472-474` | Already at 5.1 CLI registration — test both branches |
| Multisite `is_multisite()` true | `Util::transient_key` blog-prefix at `class-util.php` + `ObjectCache` blog prefix at `templates/object-cache.php` + `switch_blog` handler at `class-util.php:248` | `Functions\when('is_multisite')->justReturn(true)` then assert transient keys prefixed |

### 5.6 Regression

| Regression | File:Line bug | Test to prevent reoccurrence |
|------------|---------------|------------------------------|
| C-01 namespace typo `PerformanceOptimisation\Inc\Activate` vs `PerformanceOptimise\Inc\Activate` | `class-main.php:489` fixed at `MainUpgradeHookTest.php:1-90` | Keep `MainUpgradeHookTest::test_source_contains_corrected_hook_callback` + `test_main_registers_wppo_run_upgrades_with_correct_class` |
| M03 missing `require` for `WPPO_CLI_Command` before `add_command` | `class-main.php:472-474` (`AUDIT/BUGS.md:M03`) — string `'PerformanceOptimise\Inc\WPPO_CLI_Command'` not `::class`, relies on autoloader | Add `test_includes_loads_cli_file_before_add_command` — `file_get_contents('class-main.php')` contains `require_once …class-wppo-cli-command.php` before `add_command` |
| Path traversal `cache --page ../` | `class-wppo-cli-command.php:102` `wp_normalize_path(trim(parse_url PATH))` vs REST `realpath` at `class-rest.php:413-432` | Negative case C4 above |
| `database optimize --tables` allowlist bypass | `class-wppo-cli-command.php:180` `array_unique(array_merge(...array_values(TABLE_MAP)))` + `warning` at `:184` (fix for CLI04 at `IMPLEMENTATION-LOG.md:394`) | D3 above with `UNKNOWN` |
| `wppo_db_cleanup_counts` salt leak across `switch_to_blog` | `AUDIT/IMPLEMENTATION-LOG.md:354` `Util::transient_key` blog-prefix + `switch_blog` reset | `switch_blog` invocation test at 5.2 |
| `bfcache` dead branch `if(null===$token){dead inner} else` | `BfcacheTest.php:1-202` already regression suite | Keep — also assert `filter_nocache_headers` priority 1000 at `class-bfcache.php:381` |
| `image convert` batch OOM (50 default) | `class-wppo-cli-command.php:330` `batch` 50 + `20MB` filesize gate | I6 batch cap test |

---

## 6. Proposed Test File Structure

```
tests/php/
├── bootstrap.php                          # extend with WP_CLI stub (3.3)
├── stubs/
│   ├── wp-cli.php                         # NEW: WP_CLI, WP_CLI_Command, WP_CLI\ExitException, WP_CLI\Utils, WP_CLI\Formatter
│   └── wp-html-api.php                    # existing
├── WppoCliCacheTest.php                   # NEW: C1-C8
├── WppoCliDatabaseTest.php                # NEW: D1-D14
├── WppoCliImageTest.php                   # NEW: I1-I7
├── WppoCliSettingsTest.php                # NEW: S1-S20
├── WppoCliObjectCacheTest.php             # NEW: O1-O12
├── WppoCliPagespeedTest.php               # NEW: P1-P5
├── WppoCliSystemInfoTest.php              # NEW: SI1-SI3
├── WppoCliRegistrationTest.php            # NEW: WP_CLI gate, WP_CLI::add_command string vs ::class
├── HookRegistrationTest.php               # NEW: 5.1 matrix (+ conditional gates 5.5)
├── HookInvocationTest.php                 # NEW: 5.2 do_action/apply_filters side effects
├── HookFilterModificationTest.php         # NEW: 5.3 filter mutation
├── HookPriorityTest.php                   # NEW: 5.4 priority + accepted_args
├── HookContextLoadingTest.php             # NEW: 5.5 enableCache / 6.9+ / is_admin / multisite
└── *Existing 46 Test.php files remain*   # e.g. CacheTest.php:204 apply_filters returnArg patterns to reuse
```

- Each `WppoCli*Test.php` uses `WPPO_Test_Bootstrap` + `require_once WPPO_PLUGIN_PATH . 'includes/class-wppo-cli-command.php'` (since `Main::includes()` is private; directly instantiate `WPPO_CLI_Command`).
- Naming must end `Test.php` for `phpunit.xml.dist:9` discovery (`suffix="Test.php"`). Class name must match file basename.
- For multisite suites, tag with `#[Group('multisite')]` or `@group multisite` to allow `--exclude-group multisite` when not needed.

---

## 7. Fixtures & Mocks Catalog

### 7.1 `WP_CLI` mock (see 3.4 stub)

Captured arrays surfaced via `WP_CLI::$captured['log'|'success'|'warning'|'error']` and `WP_CLI::$exit_code`. Reset in `setUp()` via `WP_CLI::reset_captured()`.

### 7.2 `$wpdb` mock

Pattern from `DatabaseCleanupTest.php:41-50`:

```php
$GLOBALS['wpdb'] = new class extends WPPO_DB_Mock {
    public function prepare($query, ...$args){ return vsprintf(str_replace('%d','%s',$query), $args); }
    public function get_results($query=null){ return [['option_name'=>'small','opt_size'=>10]]; }
    public function get_var($query=null){ return '0'; }
    public function query($q){ return true; } // for OPTIMIZE TABLE
};
```

Needed for `database counts` 9 `COUNT(*)` shapes at `class-database-cleanup.php:892-915` + `get_autoloaded_options LIMIT %d` at `:630` uses `prepare`.

### 7.3 `$wp_filesystem` mock

```php
global $wp_filesystem;
$wp_filesystem = Mockery::mock('WP_Filesystem_Base');
$wp_filesystem->shouldReceive('exists')->with('/tmp/out.json')->andReturn(true);
$wp_filesystem->shouldReceive('get_contents')->andReturn('{"file_optimisation":{"minifyHTML":true}}');
$wp_filesystem->shouldReceive('put_contents')->andReturn(true);
Functions\when('WP_Filesystem')->justReturn(true);
Functions\when('request_filesystem_credentials')->justReturn(true);
```

Gates at `class-wppo-cli-command.php:603-607,629-632` + `Util::init_filesystem()` at `class-util.php:322-334`.

### 7.4 `wppo_settings` fixture

Reuse `get_default_settings()` shape at `class-wppo-cli-command.php:451-522` — 6 tabs, 32 keys in `file_optimisation` etc. Partial stored example:

```php
$options = [
  'file_optimisation'=>['minifyHTML'=>false,'cdnURL'=>''],
  'cache_settings'=>['enableLoggedInCache'=>false],
];
Functions\when('get_option')->alias(fn($k,$d=false)=> $k==='wppo_settings' ? $options : $d);
Functions\when('update_option')->alias(function($k,$v){ $GLOBALS['wppo_test_saved'][$k]=$v; return true; });
```

Tests S11-S15 need allowlist `Util::ALLOWED_SETTINGS_KEYS` at `class-util.php:43-58` (13 keys) and sanitizer `sanitize_settings_recursively` at `:877-913`.

### 7.5 `wppo_img_info` fixture

```php
$img_info = ['pending'=>['webp'=>['/uploads/2026/08/a.jpg'],'avif'=>[]],'completed'=>['webp'=>[],'avif'=>[]]];
Functions\when('get_option')->alias(fn($k,$d)=> $k==='wppo_img_info' ? $img_info : $d);
// Or stub Img_Converter::get_img_info() via Mockery alias:
Mockery::mock('alias:PerformanceOptimise\Inc\Img_Converter')->shouldReceive('get_img_info')->andReturn($img_info);
```

### 7.6 `Cache::get_cache_stats` fixture

```php
Functions\when('get_transient')->justReturn(false); // force dirlist walk
global $wp_filesystem;
$wp_filesystem->shouldReceive('dirlist')->andReturn(['example.com'=>['files'=>['index.html'=>[]]]]);
```

At `class-cache.php:2184` transient `wppo_cache_size` 15min.

### 7.7 Action Scheduler fixture

```php
Functions\when('as_enqueue_async_action')->alias(function($hook,$args,$group){ $GLOBALS['wppo_test_as'][]=[$hook,$args,$group]; return 123; });
Functions\when('as_next_scheduled_action')->justReturn(false);
```

For `pagespeed queue_scan` at `class-pagespeed.php:119-133` + `Main::process_background_image` at `class-main.php:775`.

### 7.8 Redis `Object_Cache` fixture

Mock `new Object_Cache()` methods — `get_status` returns `['enabled'=>true,'redis_missing'=>false,'redis_reachable'=>true]`, `ping/enable/disable/flush` return `true` or `WP_Error`. No real Redis connection needed.

---

## 8. Coverage Goals

| Scope | Metric | Target | Rationale |
|-------|--------|--------|-----------|
| `class-wppo-cli-command.php:1-973` | Line coverage | ≥ 85% | 7 subcommands × (success/error/warning/assoc/default) — current 0%, handbook destructive paths must be covered |
| Same file | Branch coverage | ≥ 90% | Every `if ('clear' !== $action)` at `:93,206,390,749,854,931` + `is_wp_error` at `:219,279,814` + `empty($table)` at `:183` + `realpath` at `:353-355` |
| Hook registration (`setup_hooks` + `Cron::__construct` + `Image_Optimisation` etc.) | `add_action`/`add_filter` calls asserted via `Actions\has`/`Filters\has` | 100% of 42 public `wppo_*` hooks (`docs/hooks.md`) + 80% of 272 total hits (`HOOK-AUDIT.md`) | Prevent silent unregistration (e.g. C-01 typo) |
| Filter mutation | `apply_filters` return-value tests | 100% of 8 doc filters (`wppo_cache_page_html`, `wppo_exclude_*`, `wppo_litespeed_can_cdn`, …) | Operator-facing contract |
| Priority / accepted_args | Explicit `priority` + `accepted_args` assertions | 100% of non-default priorities (`PHP_INT_MAX`, `PHP_INT_MAX-1`, `1`, `11`, `20`, `100`, `10000` at `class-main.php:515-545,608,676`) | Ordering bugs break asset pipeline |
| Conditional loading | Branch matrix at 5.5 | All 7 gates toggled true/false (enableCache, 6.9+, is_admin, WP_CLI, is_multisite) | `AUDIT` multisite + version-gated bugs |
| Multisite | `get_current_blog_id` variant tests | Each CLI subcommand at least one `get_current_blog_id→2` case | `AGENTS.md:158-163` transient isolation requirement |
| Existing 471 tests | No regression | Suite still 471 + new tests = ~600 total, `composer test` green, `phpcs` + `parallel-lint` pass | `psalm-wpcs-check.yml` gate |

- Initial PR can aim for CLI line ≥ 60% (happy paths + error paths) then iterate to 85%+ with dry-run/multisite additions.

---

## 9. Example Tests (copy-paste skeletons)

### 9.1 CLI dispatch with `ExitException` (settings error path)

```php
<?php
use PerformanceOptimise\Inc\WPPO_CLI_Command;
use Brain\Monkey\Functions;

class WppoCliSettingsTest extends \PHPUnit\Framework\TestCase {
    use WPPO_Test_Bootstrap;

    protected function setUp(): void {
        parent::setUp();
        require_once WPPO_PLUGIN_PATH . 'includes/class-wppo-cli-command.php';
        \WP_CLI::reset_captured();
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
    }

    public function test_settings_update_requires_settings_param(): void {
        $cmd = new WPPO_CLI_Command();
        $thrown = false;
        try {
            $cmd->settings(['update','file_optimisation'], []); // missing --settings at class-wppo-cli-command.php:713
        } catch (\WP_CLI\ExitException $e) {
            $thrown = true;
            $this->assertSame(1, $e->getCode());
            $this->assertStringContainsString('Please provide a JSON', $e->getMessage());
        }
        $this->assertTrue($thrown, 'WP_CLI::error must throw ExitException 1');
        $this->assertCount(0, \WP_CLI::$captured['success']);
    }

    public function test_settings_get_invalid_tab_errors(): void {
        Functions\when('get_option')->alias(fn($k,$d)=> $k==='wppo_settings' ? ['file_optimisation'=>[]] : $d);
        $cmd = new WPPO_CLI_Command();
        $this->expectException(\WP_CLI\ExitException::class);
        $cmd->settings(['get','nope'], []); // class-wppo-cli-command.php:680-682
    }
}
```

### 9.2 Hook registration assertion (`Actions\has` / `Filters\has`)

```php
<?php
use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

class HookRegistrationTest extends \PHPUnit\Framework\TestCase {
    use WPPO_Test_Bootstrap;

    public function test_cache_buffer_hooks_registered_when_enabled(): void {
        Functions\when('get_option')->justReturn(['cache_settings'=>['enableCache'=>true]]);
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_is_block_theme')->justReturn(false);
        Functions\when('function_exists')->alias(fn($f)=> \function_exists($f));
        Functions\when('wp_should_output_buffer_template_for_enhancement')->justReturn(true);

        new Main(); // triggers setup_hooks() at class-main.php:485-799

        $this->assertNotFalse(Actions\has('save_post', [Main::class,'on_save_post_invalidate_cache'])); // class-main.php:552
        $this->assertNotFalse(Filters\has('wp_template_enhancement_output_buffer', [ \PerformanceOptimise\Inc\Cache::class, 'process_buffer_for_cache'])); // :545
        // Fallback absent when 6.9+ true:
        $this->assertFalse(Actions\has('template_redirect', [ \PerformanceOptimise\Inc\Cache::class, 'start_output_buffer'])); // :550
    }
}
```

### 9.3 Filter modification

```php
public function test_wppo_exclude_minification_filter_skips_handle(): void {
    Functions\when('apply_filters')->alias(function($tag,$val,...$args){
        if('wppo_exclude_minification'===$tag && $args[1]==='my-handle') return true;
        return $val;
    });
    // Main::minify_css at class-main.php:2747 does apply_filters('wppo_exclude_minification', false, $file,$handle,$type)
    $result = apply_filters('wppo_exclude_minification', false, '/var/www/app.css', 'my-handle', 'css');
    $this->assertTrue($result);
}
```

### 9.4 Dry-run future shape (database)

```php
public function test_database_cleanup_dry_run_does_not_delete(): void {
    // Future: WPPO_CLI_Command::database checks \WP_CLI\Utils\get_flag_value($assoc,'dry-run')
    // Mock Database_Cleanup::clean_revisions to track calls:
    $called = false;
    \Mockery::mock('alias:PerformanceOptimise\Inc\Database_Cleanup')
        ->shouldReceive('clean_revisions')->never(); // dry-run must not call
    $cmd = new WPPO_CLI_Command();
    $cmd->database(['cleanup'], ['type'=>'revisions','dry-run'=>true]);
    $this->assertContains('Dry run', implode('', \WP_CLI::$captured['warning']));
}
```

### 9.5 Multisite blog isolation

```php
public function test_cache_clear_respects_current_blog(): void {
    Functions\when('get_current_blog_id')->justReturn(2);
    Functions\when('is_multisite')->justReturn(true);
    // Cache::clear_cache builds path wp-content/cache/wppo/{domain}/… — domain derived from Util::cached_home_url blog 2
    $cmd = new WPPO_CLI_Command();
    \Mockery::mock('alias:PerformanceOptimise\Inc\Cache')->shouldReceive('clear_cache')->withNoArgs()->andReturn(true);
    $cmd->cache(['clear'], []);
    $this->assertNotEmpty(\WP_CLI::$captured['success']);
}
```

---

## 10. Prioritization

| Phase | What | Tests | Effort |
|-------|------|-------|--------|
| **P0 — Registration smoke** | `WppoCliRegistrationTest` + `HookRegistrationTest` (always-registered hooks) | ~15 tests | 0.5 day — catches C-01 typo, M03 missing require |
| **P1 — CLI happy + error paths** | `WppoCliCache/Database/Image/Settings/ObjectCache/Pagespeed/SystemInfo` happy + invalid action/type | ~45 tests | 2 days — covers 2.2 exit-code table |
| **P2 — Filter + priority** | `HookFilterModificationTest` + `HookPriorityTest` | ~20 tests | 1 day |
| **P3 — Conditional + multisite + FS** | `HookContextLoadingTest` + multisite variants + `$wp_filesystem` branches | ~25 tests | 1.5 days |
| **P4 — Dry-run + integration** | Add `dry-run`/`--yes` flags to production (requires code change, out of scope for this plan) + Behat `features/wppo-cli.feature` 2 scenarios | ~10 tests | 1 day, gated on prod change |

Total: **~115 new tests**. With 471 existing → ~586 suite, still <5s with Brain Monkey harness.

---

## 11. Appendix

### A. Grep evidence for harness gap

- `grep -rn WP_CLI tests` → only `composer.lock:431` `wp-cli/wp-cli ~2.5.0` (transitive from `woocommerce/action-scheduler 4.1`) — **no `tests/php/*` defines or stubs `WP_CLI`**.
- `grep -rn "Actions\\\\has\\|Filters\\\\has" tests/php` → only `MainUpgradeHookTest.php:82-87`, `ImageOptimisationTest.php:546,559`, `BlockAssetsFiltersTest.php:50-95`, `MainBlockAssetsTest.php:84-183` — sporadic, not systematic.
- `grep -rn "WP_CLI"` in `includes/*.php` → `class-main.php:472-474` registration + 50 hits in `class-wppo-cli-command.php:14-931` (`WP_CLI::success|error|warning|log`).

### B. Existing negative expectation pattern to reuse

At `InlineCssTest.php:380`:

```php
Functions\expect('apply_filters')->with('wppo_exclude_minification', \Mockery::type('bool'), \Mockery::any(), \Mockery::any(), \Mockery::any())->never();
```

Use `never()` for hook-not-registered branches.

### C. Current doc lineage

- `WP-CLI-CURRENT.md` (973-line file:line dump, 7 subcommands) and `HOOK-AUDIT.md` (272 hook hits) are the source truth for matrices above — every matrix row cites their `file:line`.
- Handbook conventions per `WP-CLI-RESEARCH.md` (namespace, synopsis `<required>` vs `[<optional>]`, `options:` enumerations, `WP_CLI::confirm` + `--yes`, `format_items`, `make_progress_bar`, `--dry-run`, `--url/--network`).

### D. Risks if not tested

- **Silent CLI breakage** (e.g. `TABLE_MAP` allowlist change breaks `database optimize --tables`) — no CI signal.
- **Hook typo** (C-01 `PerformanceOptimisation\Inc\Activate` already shipped once at `MainUpgradeHookTest.php:1-90` fix) recurs via string `'PerformanceOptimise\Inc\WPPO_CLI_Command'` at `class-main.php:473` vs `::class`.
- **Multisite leak** (`AUDIT/IMPLEMENTATION-LOG.md:354` `get_settings` blog-keyed fix) regresses if `switch_to_blog` handler at `class-util.php:248` unregistered.

---

*Research-only. For implementation PR see sibling `WP-CLI-CURRENT.md` section 8 and `HOOK-AUDIT.md` section 2 for cited lines.*
