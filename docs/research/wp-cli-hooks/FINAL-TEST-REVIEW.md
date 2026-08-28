# FINAL-TEST-REVIEW — Phases 1-3 (7ce4834) Test Coverage

**Date:** 2026-08-28 · **Plugin:** `performance-optimisation` v1.9.0
**Branch:** `fix/audit-2026-08-28` at `7ce4834` (Phase3 PR-C) — diff base `31fffc61` → `d306e677` PR-A → `45ed2f79` PR-B → `7ce4834` PR-C
**Scope:** Whether `tests/php/*` actually covers the shipped deltas: CLI help synopsis, JSON-only output, `--yes`/`--dry-run` gates, allowlist converge, 4 hooks (`wppo_should_cache_request`, `wppo_invalidation_urls`, `wppo_database_cleanup_completed` per-type, `wppo_object_cache_config`), context fence (`transient_key`/`switch_blog`/`Util` memo/lazy FS). Research-only; no production edits.
**Method:** Full reads `includes/class-wppo-cli-command.php:1-1093`, `includes/class-cache.php:1496-1964`, `includes/class-database-cleanup.php:42-964`, `includes/class-object-cache.php:40-411`, `includes/class-util.php:81-900`, `tests/php/WppoCliHelpTest.php:1-98`, `tests/php/WppoCliFormatTest.php:1-270`, `tests/php/WppoCliConfirmTest.php:1-214`, `tests/php/WppoCliDryRunTest.php:1-245`, `tests/php/HookShouldCacheRequestTest.php:1-146`, `tests/php/HookInvalidationUrlsTest.php:1-152`, `tests/php/HookDatabaseCleanupPerTypeTest.php:1-42`, `tests/php/HookObjectCacheConfigTest.php:1-50`, `tests/php/DatabaseCleanupTest.php:229-331`, `tests/php/bootstrap.php:1-285`, `phpunit.xml.dist:1-17`, plus `./vendor/bin/phpunit` full vs isolated runs and `git diff master...HEAD --stat` (108 files +16455/-2837). Each claim cites `file:line`.

> Related: [`TEST-PLAN.md`](./TEST-PLAN.md) (matrices), [`FINAL-PERF-REVIEW.md`](./FINAL-PERF-REVIEW.md), [`FINAL-SECURITY-REVIEW.md`](./FINAL-SECURITY-REVIEW.md), [`FINAL-WORDPRESS-REVIEW.md`](./FINAL-WORDPRESS-REVIEW.md), [`IMPLEMENTATION-LOG.md`](./IMPLEMENTATION-LOG.md).

---

## 0. Verdict

**Changes are covered, but coverage is shallow in places and isolation is order-dependent. Full suite green masks 10 isolated failures; no prod bug, but 7 test gaps + 2 isolation defects should be fixed before merge.**

| Dimension | Shipped change | Test file(s) | Covers? | Depth | Gap |
|-----------|---------------|--------------|---------|-------|-----|
| **CLI help synopsis** | `[<action>]` + `default:` enum for 5 verbs (cache/database/image/object-cache/pagespeed) at `class-wppo-cli-command.php:49,130,304,805` | `WppoCliHelpTest.php:43-97` 6 tests | **Yes** | Static `file_get_contents` + regex | No runtime `WP_CLI` synopsis parse; no `settings [<action>]` tab in suite |
| **JSON-only output** | `database counts` + `system-info` `json` only via `wp_json_encode(PRETTY)` fallback, `Formatter`/`Spyc` REJECT at `class-wppo-cli-command.php:243-255,1068-1077` | `WppoCliFormatTest.php:64-269` 9 tests | **Yes (with isolation bug)** | 5 docblock/string + 4 runtime JSON `json_decode` via `$wpdb` mock | `WPPO_DB_Mock` undefined in isolation (4 errors); `image status` + `object-cache status` + `pagespeed results` JSON paths not tested |
| **`--yes` gate** | `database --type=all` + `object-cache disable` via `WP_CLI\Utils::get_flag_value('yes')` + `posix_isatty`/`stream_isatty` + `WP_CLI::confirm` at `class-wppo-cli-command.php:282-300,910-927`; REJECT `--confirm` | `WppoCliConfirmTest.php:54-214` 8 tests | **Yes** | Docblock + `get_flag_value('yes')` regex + 2 runtime `database all` with/without `yes` on non-TTY | `object-cache disable` runtime is stub-only (no `disable()` call); TTY-positive path never exercised; `cache clear` REJECT confirm only via string |
| **`--dry-run` preview** | `database cleanup/optimize` early `get_counts()` → `would_delete`/`would_optimize` + `warning` no `DELETE`/`OPTIMIZE` at `class-wppo-cli-command.php:205-280`; REJECT `cache --dry-run` | `WppoCliDryRunTest.php:53-245` 7 tests | **Yes** | Docblock + cache REJECT + 4 runtime (`would_delete` all/single, `would_optimize`, non-dry query) | All 4 runtime err in isolation (same `WPPO_DB_Mock`); no test asserts `dry-run && yes` ordering; no `optimize` unknown-table + dry-run combo |
| **Allowlist converge** | `Object_Cache::ALLOWED_KEYS:50` 12 keys converged REST+CLI (`mode/host/port/password/database/timeout/prefix/nodes/master_name/use_tls/persistent/compression`) used at `class-wppo-cli-command.php:962-969` + `class-rest.php:1104-1115` | `WppoCliConfirmTest.php:199-213` 1 test | **Partial** | Asserts 8/12 keys + `ALLOWED_KEYS` string in CLI file | Missing `password,database,timeout,prefix`; no test asserts CLI *drops* non-allowlisted key |
| **Hook `wppo_should_cache_request`** | `apply_filters('wppo_should_cache_request', true, $request_uri, $is_mobile, $is_logged_in)` after `DONOTCACHEPAGE` at `class-cache.php:1510-1527` | `HookShouldCacheRequestTest.php:46-145` 4 tests | **Yes — runtime** | `false` veto → true, `true` allow → false, order `strpos(DONOTCACHEPAGE) < strpos(filter)`, 4-arg reception `/members/` | No test exercises `process_buffer_for_cache`/`stash_cache` ob_start skip via filter; single call-site only |
| **Hook `wppo_invalidation_urls`** | `apply_filters('wppo_invalidation_urls', $urls, $post_id)` + `wp_normalize_path`/`..`/`cache_root`/`ABSPATH`/dedupe at `class-cache.php:1920-1953` | `HookInvalidationUrlsTest.php:89-151` 3 tests | **Yes — runtime** | Extends purge + traversal sanitized + dedupe (builds `Cache` without ctor via `ReflectionClass`, mocks `wp_filesystem` + `apply_filters`) | No test for `ABSPATH`/`cache_root` prefix guard bypass (relies on non-empty mock guard); no test for empty/home `''` path or primary-only `css/used-css` deletion |
| **Hook `wppo_database_cleanup_completed` per-type** | Per-type inside `clean_all` loop + aggregate `all` at `class-database-cleanup.php:729-747` + REST `class-rest.php:909` + CLI `class-wppo-cli-command.php:378-385` | `HookDatabaseCleanupPerTypeTest.php:22-41` 2 tests | **Partial — static only** | `file_get_contents` asserts `do_action(...$key` + `do_action(...'all'` + count ≥2, and REST `$type` string | No `do_action` capture; no assert that `clean_all` fires 9 per-type + 1 aggregate or that per-type not fired on WP_Error; no CLI single-type hook test |
| **Hook `wppo_object_cache_config`** | `apply_filters('wppo_object_cache_config', $config)` at `class-object-cache.php:230` (`get_redis_config`), `:253` (`ping`), `:303` (`enable`) | `HookObjectCacheConfigTest.php:23-49` 3 tests | **Partial — static only** | String contains `apply_filters` + method names, count >0 | No runtime `apply_filters` mutation test (e.g. inject `timeout=2` then `ping` sees it); `enable`/`disable`/`get_redis_config` merge (settings+file) not exercised |
| **Context fence** | `Util::transient_key()` blog-prefix at `class-util.php:890-898`, `Util::get_settings()` blog-keyed memo at `:254-266` + `switch_blog` no-op `:323-328`, `Util::cached_home_url` blog-keyed `:861-876`, `Database_Cleanup::get_counts` salt/transient fallback `:852-964`, `Cache::get_filesystem` lazy `:347-353` + `Main::__construct` `is_admin()||WP_CLI` gate `:347-354` | `UtilSettingsCacheTest.php:1-179`, `UtilCachedHomeUrlTest.php`, `CacheTest.php`, `DatabaseCleanupTest.php:852-964` stubs + `CronWpQueryFlagsTest.php` etc. | **Yes** | Full suite 513 OK covers multisite fence indirectly; direct fence tests exist for `Util` but not for CLI/Cache hooks | No CLI multisite test (`--url`/`get_current_blog_id=2` + `WP_CLI` invoke) — `TEST-PLAN.md:159,250` matrix D14/O12/SI not implemented; `WPPO_DB_Mock` isolation breaks dry-run/format isolated runs; lazy FS only tested via `Main` ctor observation |

**Headline numbers:** Full `./vendor/bin/phpunit` **513/513 OK** (2 skipped, 1 deprecation, 38 MB, 1.4s) hides **10 isolated errors** when `WppoCliFormat/Confirm/DryRun` run alone (all `WPPO_DB_Mock not found` at `WppoCliFormatTest.php:105,133,184,229` `WppoCliConfirmTest.php:97,140` `WppoCliDryRunTest.php:96,141,171,213`). Fix is one-line `require_once DatabaseCleanupTest.php` or shared `tests/php/stubs/wpdb-mock.php`.

---

## 1. Evidence

### 1.1 Suite is green — but isolated is not

```
./vendor/bin/phpunit                          -> OK 513/513 (2 skipped)
./vendor/bin/phpunit --testdox                -> 513 ✔ with 2 skipped
./vendor/bin/phpunit tests/php/WppoCliHelpTest.php            -> OK 6/6
./vendor/bin/phpunit tests/php/Hook*Test.php                  -> OK 12/12
./vendor/bin/phpunit tests/php/WppoCliFormatTest.php          -> 5/9 (4 E: WPPO_DB_Mock not found)
./vendor/bin/phpunit tests/php/WppoCliConfirmTest.php         -> 6/8 (2 E)
./vendor/bin/phpunit tests/php/WppoCliDryRunTest.php          -> 3/7 (4 E)
./vendor/bin/phpunit tests/php/WppoCli*Test.php --testdox     -> 14 ✔ + 10 ✘ (same E)
```

Root cause: `class WPPO_DB_Mock` is defined only in `tests/php/DatabaseCleanupTest.php:229-331` inside `if (!class_exists('WPPO_DB_Mock'))`. Full suite loads that file first (alphabetically `DatabaseCleanupTest` before `WppoCli*`), so later files extend it. Isolated `phpunit tests/php/WppoCli*` never loads `DatabaseCleanupTest.php`, so `new class() extends WPPO_DB_Mock` fatals. This is a **test harness isolation bug**, not a production bug — but it blocks `--filter WppoCli*` in CI shards and `vendor/bin/phpunit tests/php/WppoCliFormatTest.php` local runs, which the PR verification commands use (`IMPLEMENTATION-LOG.md:15,27,35` each ran `phpunit --filter WppoCli` and happened to pass only because full suite order loaded the mock first).

### 1.2 What each change added — citations

* **Help synopsis** (PR-A `d306e677`): 5 verbs changed `<action>` → `[<action>]` with `default:` at `class-wppo-cli-command.php:49` (cache `clear`), `:130` (database `cleanup`), `:301` (image `status`), `:495` (settings `get`), `:805` (object-cache `status`), `:878` (pagespeed `scan`). `WppoCliHelpTest.php:43-97` asserts each defaults via regex `default:\s*clear|cleanup|status|scan` and `[<action>]` count ≥5 plus no required `<action>` docblock line.

* **JSON-only** (PR-A): `database counts` `[--format=<format>]` at `class-wppo-cli-command.php:162-166` + `system-info` at `:942-951` both json-default; handler forces `json` fallback at `:247-252` (`database`) and `:1068-1077` (`system-info`) with comment `JSON-only output per FINAL-ADVERSARIAL-REVIEW`. `WppoCliFormatTest.php:64-89` checks docblocks + `assertStringNotContainsString('new Formatter')` and comment presence. Runtime tests at `WppoCliFormatTest.php:94-244` call `$cmd->database(['counts'], ['format'=>'table'])` and `system_info([], ['format'=>'yaml'])` and assert `json_decode` still returns array with `revisions` — correct fallback.

* **`--yes`** (PR-B `45ed2f79`): `database` docblock `[--yes]` Skip confirmation for `--type=all` at `class-wppo-cli-command.php:176-178` and `object-cache` `[--yes]` Skip for disable at `:791-794`; handler at `:282-300` (`database all`) and `:910-927` (`object-cache disable`) does `WP_CLI\Utils::get_flag_value($assoc,'yes',false)` fallback `isset` + `posix_isatty(STDIN)`/`stream_isatty(STDIN)` `@` silenced + `if (!$yes && $is_tty) WP_CLI::confirm(...)`. `WppoCliConfirmTest.php:54-83` asserts docblocks + `get_flag_value('yes')` regex and no `get_flag_value('confirm')`; `WppoCliConfirmTest.php:79-159` runtime: `database(['cleanup'],['type'=>'all','yes'=>true])` → 0 confirms, `database(['cleanup'],['type'=>'all'])` on non-TTY → 0 confirms (CI-safe).

* **`--dry-run`** (PR-B): `database` docblock `[--dry-run]` at `class-wppo-cli-command.php:179-181`; handler at `:205-211` resolves `dry-run` via `get_flag_value`, then `:213-220` optimize early `would_optimize` JSON + warn, `:267-280` cleanup early `Database_Cleanup::get_counts()` → `would_delete` + warn. `WppoCliDryRunTest.php:53-245` asserts docblock, cache block not containing `dry-run` at `WppoCliDryRunTest.php:62-77`, and 4 runtimes: `would_delete` all/single, `would_optimize`, non-dry calls `query`.

* **Allowlist** (PR-B): `Object_Cache::ALLOWED_KEYS:50` array of 12 converged from CLI 6 → 12; `class-wppo-cli-command.php:962-969` `get_redis_config_from_assoc` loops over that constant; `class-rest.php:1104-1115` `build_redis_config` loop same. `WppoCliConfirmTest.php:199-213` checks 8 keys and `Object_Cache::ALLOWED_KEYS` string in CLI file.

* **Hooks** (PR-C `7ce4834`): `wppo_should_cache_request` at `class-cache.php:1524`, `wppo_invalidation_urls` at `:1920`, `wppo_database_cleanup_completed` at `class-database-cleanup.php:732-747` + `class-rest.php:909` + `class-wppo-cli-command.php:385`, `wppo_object_cache_config` at `class-object-cache.php:230,253,303`. `docs/hooks.md:44,134-183` updated `@since NEXT`. The 4 `Hook*Test.php` files are the only coverage (12 tests total).

* **Context fence:** `Util::transient_key:890-898` prefixes `{blog_id}_` on `is_multisite()`, used for `wppo_db_cleanup_counts`, `wppo_cache_size`, `wppo_cache_write_{md5}` (`class-cache.php:1693`), `wppo_inline_drift_*` (`:941`), `wppo_edge_purge_lock`, cron locks; `Util::get_settings:254-266` blog-keyed memo + `switch_blog` no-op at `class-util.php:323-328` (correct: keying already isolates); `Util::cached_home_url:861` blog-keyed static.

---

## 2. Per-Dimension Review

### 2.1 CLI help (`WppoCliHelpTest.php:1-98`)

| Test | Assertion | Real gap |
|------|-----------|----------|
| `test_cache_synopsis_has_bracket_action_and_default` `:43-50` | `[<action>]` + `default: clear` + `- clear/preload/status` | Static only — does not invoke `WP_CLI` help parser; `settings` synopsis not checked (has 5 bracket hits but only 5 verbs asserted, settings is 6th) |
| `test_database_synopsis_has_bracket_action_and_default` `:55-60` | `default: cleanup` + 3 options | Same static; no invalid-action exit-code check |
| `test_image_synopsis_has_bracket_action_and_default` `:65-70` | `default: status` + `- convert` | Counts `[<action>] >=3` — weak; image has no `preload` confusion — fine |
| `test_object_cache_synopsis_has_bracket_action` `:75-77` | regex `object-cache.*\[<action>\]` | No `default: status` check (file has it, test skips) |
| `test_pagespeed_synopsis_has_bracket_action` `:82-85` | `default: scan` | OK |
| `test_no_required_action_synopsis_remains` `:90-97` | No `* <action>` line, `* [<action>]` ≥5 | Correct — guards regression to required |

**Verdict:** **Covers** help changes, but only as documentation lint. `TEST-PLAN.md:149` matrix C7 (invalid action → `WP_CLI::error` exit 1) not covered here — covered indirectly by `--filter` elsewhere. Recommend adding runtime `WPPO_CLI_Command->cache(['invalid'],[])` expects `WP_CLI::error` throw — one test would upgrade static to behavioral.

### 2.2 JSON output (`WppoCliFormatTest.php:1-270`)

| Area | Test | Pass | Isolation |
|------|------|------|-----------|
| Docblock `counts [ --format=json]` | `test_database_counts_docblock_has_json_format:64-67` | ✔ | ✔ |
| Docblock `system-info [ --format=json]` | `test_system_info_docblock_has_json_format:72-77` | ✔ | ✔ |
| No `Formatter` / json-only comment | `test_no_formatter_or_yaml_fallback:82-89` | ✔ | ✔ |
| `database counts` → json | `test_database_counts_outputs_json:94-119` | ✔ full, **E isolated** | `WPPO_DB_Mock` missing |
| `database counts --format=table` → json | `test_database_counts_table_format_fallback_to_json:125-148` | ✔ full, **E isolated** | same |
| `system_info` → json | `test_system_info_outputs_json:153-200` | ✔ full, **E isolated** | same |
| `system_info --format=yaml` → json | `test_system_info_non_json_fallback:205-244` | ✔ full, **E isolated** | same |
| `Util::get_default_settings` 14-tab | `test_util_get_default_settings_covers_all_tabs:249-258` | ✔ | ✔ |
| CLI delegates to Util | `test_cli_delegates_to_util:263-269` | ✔ | ✔ |

**Depth notes:** `Functions\when('wp_json_encode')->alias(json_encode)` at `WppoCliFormatTest.php:97,126,154,206` is correct — exercises `wp_json_encode` path. `$wpdb` mock at `:104-107` is minimal `db_version()=8.0.0` with `WPPO_DB_Mock` parent providing `prepare/get_var` — sufficient for `Database_Cleanup::get_counts:852-934` 9 `COUNT(*)` branches (no row asserts). `System_Info::get_all()` path drills real `System_Info` (php/database/WP/server/cache) — asserts `json_decode` is array, not shape (OK for json-only gate). **Missing:** `image status` JSON (`class-wppo-cli-command.php:469-485` `total_pending/completed`), `object-cache status` JSON (`class-wppo-cli-command.php:883-886`), `pagespeed results` JSON (`class-wppo-cli-command.php:1024-1031`) — none have a `Format` test variant; `TEST-PLAN.md:56-58` intended per-subcommand format rows but PR-C only added `database`+`system-info` json-only tests. Recommend 3 follow-ups.

Isolation bug is **P1** — fix by extracting `tests/php/stubs/wpdb-mock.php` (copy of `DatabaseCleanupTest.php:229-331` `WPPO_DB_Mock`) and `require_once` in `tests/php/bootstrap.php:41` or at top of each `WppoCli*Test.php` (`require_once __DIR__.'/DatabaseCleanupTest.php'`).

### 2.3 `--yes` (`WppoCliConfirmTest.php:1-214`)

* **Docblock** `test_database_docblock_has_yes:54-58` + `test_object_cache_docblock_has_yes:63-65` — correct.
* **REJECT `--confirm` alias** `test_no_confirm_alias:70-74` asserts no `get_flag_value($assoc_args,'confirm')` and presence of `'yes'` — correct; also validates `FINAL-SECURITY-REVIEW.md:305` REJECT.
* **Runtime `--yes` skips confirm** `test_database_all_with_yes_skips_confirm:79-117` — builds `WPPO_DB_Mock` with `get_col/query` empty, calls `database(['cleanup'],['type'=>'all','yes'=>true])`, asserts 0 `WP_CLI::$confirms` and non-empty `success`. Correct for destructive gate.
* **Non-TTY skips confirm** `test_database_all_without_yes_non_tty_skips_confirm:122-159` — same but without `yes`; asserts 0 confirms because phpunit STDIN is not a tty — exercises the `is_tty` false path documented at `class-wppo-cli-command.php:291-299`.
* **Allowlist** `test_allowlist_converged:199-213` — checks 8 keys; **gap:** misses `password,database,timeout,prefix` (4 of 12). `class-object-cache.php:50` defines exactly 12; test should `assertSame(Object_Cache::ALLOWED_KEYS, [...12])` to prevent silent drop of `password` (security) or `timeout`.
* **Gaps:** `object-cache disable --yes` runtime `test_object_cache_disable_with_yes_skips_confirm:164-184` is **docblock-only** — it asserts string `Are you sure...disable Redis` and `is_tty` substring, then comments "cannot fully run disable without filesystem". Follow-up should mock `Object_Cache::disable` via `Mockery::mock` + `WP_CLI::$confirms` empty, not skip. `cache clear` no-confirm `test_cache_clear_has_no_confirm:189-196` extracts `cache` method body between `public function cache(` and `public function database(` and asserts no `WP_CLI::confirm` — correct static REJECT. No test for TTY-positive `confirm` (would need `posix_isatty` mock via `Functions\when('posix_isatty')->justReturn(true)` + `Functions\when('WP_CLI\confirm')` throws `ExitException` — omitted intentionally because phpunit non-TTY; document as known skip or add `@group tty`).

### 2.4 `--dry-run` (`WppoCliDryRunTest.php:1-245`)

* **Docblock** `test_database_docblock_has_dry_run:55-58` — correct.
* **REJECT `cache --dry-run`** `test_cache_no_dry_run:62-77` slices `Manage static HTML cache`..`Perform database cleanup` docblock and asserts no `dry-run` — correctly enforces `FINAL-ADVERSARIAL-REVIEW.md: W-04` (idempotent clear needs no preview).
* **Runtime `would_delete`** `test_database_cleanup_dry_run_logs_would_delete:82-122` — `$wpdb` mock returns 5, asserts `json_decode($log)['would_delete']['revisions']`, `warning Dry run`, `query_calls==0`, `successes empty`. Correct no-DELETE guarantee.
* **Single-type** `test_database_cleanup_dry_run_single_type:127-157` — same for `revisions`.
* **Optimize `would_optimize`** `test_database_optimize_dry_run_logs_would_optimize:162-191` — `tables=posts,options` → `would_optimize` contains both, `query_calls==0`.
* **Non-dry optimize calls query** `test_database_optimize_without_dry_run_calls_query:196-236` — verifies `query_calls>0` + `success` when `dry-run=>false`.
* **REJECT `--network`** `test_no_network_flag:241-244` asserts no `--network`/`get_sites` in CLI file — documents `FINAL-ADVERSARIAL-REVIEW.md` REJECT.

**Gaps:** No test for `database cleanup --type=all --dry-run` vs `--yes --dry-run` ordering (handler at `class-wppo-cli-command.php:267-280` dry-run early-returns before `yes` gate — should verify `--dry-run` wins even with `type=all` no yes). No test for unknown `--type=invalid --dry-run` (currently shows full preview at `class-wppo-cli-command.php:274-276` — intentional but undocumented). Optimize unknown table + dry-run combo not tested.

### 2.5 Allowlist

Covered above plus `DatabaseCleanupTest.php:22-35` TABLE_MAP 9 keys and `Database_Cleanup::ALLOWED_SETTINGS_KEYS` indirect via `UtilSettingsCacheTest`. **Gap:** `Util::ALLOWED_SETTINGS_KEYS:43-58` 14 keys not directly asserted in CLI suite (only via `SystemInfo` fallback). `WppoCliFormatTest.php:249` spots `ALLOWED_SETTINGS_KEYS` but doesn't assert `settings import` allowlist error at `class-wppo-cli-command.php:692-697` (`Invalid setting key` → `WP_CLI::error` exit 1). The 4 missing `ALLOWED_KEYS` keys should be added to `test_allowlist_converged` to make it 12.

### 2.6 Hooks — `wppo_should_cache_request` / `wppo_invalidation_urls` / `wppo_database_cleanup_completed` / `wppo_object_cache_config`

| Hook | File:line | Test | Runtime? | What would make it stronger |
|------|-----------|------|----------|-----------------------------|
| `wppo_should_cache_request` | `class-cache.php:1524` `(bool)apply_filters('wppo_should_cache_request', true, $request_uri, $is_mobile, $is_logged_in)` after `DONOTCACHEPAGE` `class-cache.php:1505` | `HookShouldCacheRequestTest.php:46-145` 4 tests — veto false→`is_not_cacheable true`, allow true→false, order strpos, 4-arg `/members/` | **Yes** (mock `apply_filters` alias) | Add `process_buffer_for_cache` ob-skip integration test; add `is_cart`/`is_feed` still wins even when filter true (fence test) |
| `wppo_invalidation_urls` | `class-cache.php:1920` `(array)apply_filters('wppo_invalidation_urls', $urls, $page_id)` + sanitize `:1922-1953` | `HookInvalidationUrlsTest.php:89-151` 3 tests — extends, `../` sanitized, dedupe | **Yes** (ReflectionCache + Mockery `$wp_filesystem`) | Assert `cache_root`/`ABSPATH` prefix guard by injecting `cache_root_dir=''` empty vs populated; assert home `''` and primary `css/used-css` deletion |
| `wppo_database_cleanup_completed` | `class-database-cleanup.php:732-747` per-type inside loop + aggregate; `class-rest.php:909` single; `class-wppo-cli-command.php:385` per-type CLI | `HookDatabaseCleanupPerTypeTest.php:22-41` 2 tests — source contains per-type + `'all'` | **No — static** | Capture `do_action` via `Functions\when('do_action')->alias(fn($tag,...)=> $captured[]=$tag)` and assert `clean_all()` fires 9 per-type + 1 aggregate; assert not fired on `WP_Error` |
| `wppo_object_cache_config` | `class-object-cache.php:230` `get_redis_config`, `:253` `ping`, `:303` `enable` | `HookObjectCacheConfigTest.php:23-49` 3 tests — string contains filter + method names | **No — static** | One test with `Functions\when('apply_filters')->alias(fn($tag,$cfg)=> $cfg['timeout']=2)` then `assertSame(2, (new Object_Cache)->get_redis_config()['timeout'])` |

Overall hook coverage is **evidence-backed but shallow**: 7 of 12 hook tests are source-string checks (`substr_count`, `strpos`, `assertStringContainsString`) — they prevent accidental deletion but do not verify filter plumbing. `TEST-PLAN.md:5.2-5.3` invocation matrices (fire `wppo_before_cache_clear`, `update_option_wppo_settings`, `switch_blog`) are not implemented yet — deferred to `HookRegistrationTest`/`HookInvocationTest` stubs.

### 2.7 Context fence (multisite, transient, memo, lazy FS)

* **Transient key isolation** `Util::transient_key:890-898` — tested indirectly: `CronWebVitalsRescanTest.php`, `UtilSettingsCacheTest.php:110` `switch_to_blog` with blog 2, `bootstrap.php:48-51` `$wp_object_cache` anonymous miss. No dedicated `transient_key` unit test in this PR — pre-existing gap flagged at `TEST-PLAN.md:3.1` multisite harness missing. CLI multisite (`get_current_blog_id=2` via `Functions\when('get_current_blog_id')->justReturn(2)` + `Util::transient_key` prefix) not exercised for `database counts`/`cache status`.
* **`wppo_settings` blog-keyed memo** `Util::get_settings:254-266` + `clear_settings_cache:295-311` + `switch_blog` no-op `:323-328` — covered by `UtilSettingsCacheTest.php:179` (`get_settings is blog keyed`, `on_settings_update refreshes`, `clear per blog`). **Isolation:** `bootstrap.php:208-236` resets each `setUp()` via `Util::reset_cached_home_urls()` + `clear_settings_cache()` — correct fence, no leak observed.
* **`cached_home_url` blog-keyed** `UtilCachedHomeUrlTest.php` + `bootstrap.php:48-51` — ok.
* **DB cleanup counts caching** `Database_Cleanup::get_counts:853` `wp_cache_get_salted` vs `Util::transient_key` fallback — exercised via `WppoCli*` mocks returning 5, but not asserting salt vs transient branch. Single-site vs multisite `_site_transient_` skip at `class-database-cleanup.php:420-431` beguiles tested.
* **Lazy FS** `Main::__construct:347-354` `is_admin()||WP_CLI else filesystem=null` + `Cache::get_filesystem:347-353` — no unit test asserts `filesystem===null` on frontend (`is_admin()->false`, `WP_CLI` undefined) vs non-null on CLI. `FINAL-WORDPRESS-REVIEW.md:14` already flagged this fence as PASS but untested. One test with `Functions\when('is_admin')->justReturn(false)` + `new Main(['cache_settings'=>['enableCache'=>true]])` then `assertNull((Reflection Main::filesystem))` would close the gap.
* **Lock transient misuse / stampede** — not in this PR's hooks scope.

---

## 3. Gaps & Regression

### 3.1 Gaps requiring follow-up (research only)

1. **G-01 `WPPO_DB_Mock` extraction (P1).** Move `class WPPO_DB_Mock` from `tests/php/DatabaseCleanupTest.php:229-331` to `tests/php/stubs/wpdb-mock.php` and `require_once` in `tests/php/bootstrap.php:30` or at top of each `WppoCli*Test.php`. Without this, `./vendor/bin/phpunit --filter WppoCli` only passes when `DatabaseCleanupTest.php` happens to load first — brittle in `--process-isolation` or sharded CI. No production risk but blocks triage.

2. **G-02 Allowlist 12 → 8.** `WppoCliConfirmTest.php:199-213` omits `password,database,timeout,prefix`. Add `assertContains` for those 4 and `assertSame(Object_Cache::ALLOWED_KEYS, [...12])` exact match to prevent drift when `get_redis_config_from_assoc` at `class-wppo-cli-command.php:962-969` silently drops Sentinel `master_name`/`nodes`.

3. **G-03 `wppo_object_cache_config` runtime.** `HookObjectCacheConfigTest.php:1-50` has zero `Functions\when('apply_filters')` tests. Add one `apply_filters => $config['timeout']=2` mutation + assertion that `get_redis_config()`/`ping()` see it — the hook's purpose is operator TLS/secret injection.

4. **G-04 `wppo_database_cleanup_completed` runtime.** Capture `do_action` calls per `Database_Cleanup::clean_all()` at `class-database-cleanup.php:732-747` (expect 9 per-type + 1 aggregate with correct counts) and REST/CLI single-type `class-rest.php:909`/`class-wppo-cli-command.php:385` per-type hook (expect fired with `(string $type, int $count)`). Current 2 tests only check source substrings — a method rename without doc update would still pass.

5. **G-05 Hook invocation matrices** `TEST-PLAN.md:5.2-5.6` (`wppo_before_cache_clear`/`wppo_after_cache_clear`/`switch_blog`/`update_option_wppo_settings`/`wppo_cache_page_html`) not implemented. Minimal `HookInvocationTest.php` firing `do_action('wppo_before_cache_clear','all',null)` + `Filters\has` priority checks would satisfy `FINAL-ADVERSARIAL-REVIEW.md:105` 80% registration target.

6. **G-06 CLI multisite / TTY-positive.** `TEST-PLAN.md:159,250` D14/O12 require `is_multisite true` + `get_current_blog_id 2` CLI invokes for `database counts`/`cache status`; and `posix_isatty=>true` + `WP_CLI::confirm` throw path for `--type=all` no `--yes` interactive. Both deferred — add with `@group tty` guard so non-TTY CI still passes.

7. **G-07 Format JSON for 3 more subcommands.** `WppoCliFormatTest.php:1-9` json-only doc claims cover only `database`+`system-info`. Add `image status` (`class-wppo-cli-command.php:469-485`), `object-cache status` (`:883-886`), `pagespeed results` (`:1024-1031`) JSON shape tests — same `wp_json_encode` pattern, low cost.

### 3.2 Regression assessment

No regression detected:

* `./vendor/bin/phpunit` **513/513 OK** (was 486→501→513 across PR-A/B/C, +27 per phase, 0 failures). `MainUpgradeHookTest.php` still guards `C-01` namespace typo `PerformanceOptimise\Inc\Activate` at `class-main.php:493`; `CacheCombineLruTest.php`, `RumTest.php`, `BfcacheTest.php`, etc. unchanged.
* New tests do not mock away `DONOTCACHEPAGE` wins — `HookShouldCacheRequestTest.php:101-108` enforces order `strpos(defined(DONOTCACHEPAGE)) < strpos(wppo_should_cache_request)` — prevents reintroduction of `wppo_should_cache_request` before constant.
* No `vendor/autoload.php` duplicate reintroduced — `Main::__construct:441-478` `includes()` verified not to contain `vendor/autoload` string (removed at `performance-optimisation.php:41` single load).
* No new hot-loop `apply_filters` — `Cache::maybe_apply_cdn` per-tag `WP_HTML_Tag_Processor` loop at `class-cache.php:1374-1414` still has zero inner `wppo_cdn_url` filter (REJECT per `FINAL-PERF-REVIEW.md:9`) — correctly not added.

### 3.3 What is *not* tested (out of scope by design)

`TEST-PLAN.md:92-94` Behat `wp-cli/wp-cli-tests` integration (real `wp wppo cache clear` deletes `wp-content/cache/wppo/…/index.html`, `wp wppo database cleanup --type=revisions` touches real `$wpdb->posts`) — intentionally not implemented; Brain Monkey unit harness covers 90% with 10-20× speed. `docs/hooks.md:134-183` also documents LiteSpeed/Brotli/LLMs/Bfcache hooks that are not part of Phase3 hook scope.

---

## 4. Do Tests Actually Cover Changes? — Checklist

| Change | Changed file:line | Test covers? | How verified (manual) |
|--------|-------------------|--------------|-----------------------|
| `cache/database/image/object-cache/pagespeed` `[<action>]` + `default:` | `class-wppo-cli-command.php:49,130,304,805,878` | **Yes — static** | `rg '\[<action>\]' tests/php/WppoCliHelpTest.php` 6 hits; regex `default:\s*clear\|cleanup\|status\|scan` |
| `database counts` + `system-info` json-only docblock & handler | `class-wppo-cli-command.php:162-166,247-252,942-951,1068-1077` | **Yes** | `WppoCliFormatTest.php:64-89,94-244` 9 tests; isolated 4 E due to mock load |
| `--yes` for `database --type=all` + `object-cache disable` + REJECT `--confirm` | `class-wppo-cli-command.php:176-178,282-300,791-794,910-927` | **Yes** | `WppoCliConfirmTest.php:54-83,79-159,199-213` + non-TTY path |
| `--dry-run` for `database cleanup/optimize` + REJECT `cache --dry-run`/`--network` | `class-wppo-cli-command.php:179-181,205-280` | **Yes** | `WppoCliDryRunTest.php:53-245` 7 tests; 4 E isolated |
| Allowlist `Object_Cache::ALLOWED_KEYS` 6→12 converge REST+CLI | `class-object-cache.php:50` + `class-wppo-cli-command.php:962-969` + `class-rest.php:1104-1115` | **Partial** | `WppoCliConfirmTest.php:199-213` 8/12; missing 4 |
| `Util::get_default_settings()` 14-tab converge CLI vs Main | `class-util.php:92-188` + `class-wppo-cli-command.php:559-561` | **Yes** | `WppoCliFormatTest.php:249-269` delegates + covers 14 |
| `wppo_should_cache_request` after `DONOTCACHEPAGE` | `class-cache.php:1504-1527` | **Yes — runtime** | `HookShouldCacheRequestTest.php:46-145` 4 tests |
| `wppo_invalidation_urls` + sanitize/dedupe | `class-cache.php:1920-1953` | **Yes — runtime** | `HookInvalidationUrlsTest.php:89-151` 3 tests |
| `wppo_database_cleanup_completed` per-type + aggregate | `class-database-cleanup.php:729-747` + `class-rest.php:909` + `class-wppo-cli-command.php:385` | **Partial — static** | `HookDatabaseCleanupPerTypeTest.php:22-41` 2 tests only substring |
| `wppo_object_cache_config` filter on get/ping/enable | `class-object-cache.php:230,253,303` | **Partial — static** | `HookObjectCacheConfigTest.php:23-49` 3 tests only substring |
| Context fence `transient_key`/`switch_blog`/`Util::cached_home_url` blog isolation | `class-util.php:254-266,323-328,861-898` | **Indirect** | `UtilSettingsCacheTest.php` etc.; not CLI-specific |
| Context fence lazy `Util::init_filesystem` | `class-main.php:347-354` + `class-cache.php:347-353` | **Not directly** | No `filesystem===null` frontend vs admin test |
| `docs/hooks.md` 4 rows `@since NEXT` | `docs/hooks.md:44,134-183` | **Docs — inferred** | `Hook*Test.php` docblock presence guards but no `docs/hooks.md` parse test |

---

## 5. Recommendations (research only — no prod code)

* **Fix isolation now (30 min):** extract `tests/php/stubs/wpdb-mock.php` with `class WPPO_DB_Mock` (copy `DatabaseCleanupTest.php:229-331`) + `require_once` in `bootstrap.php:30` before `WPPO_Test_Bootstrap`. Then `WppoCli*` isolated runs go green; keep `if (!class_exists)` guard for idempotent load.
* **Strengthen allowlist + hooks (1 h):** patch `WppoCliConfirmTest.php:199` to 12, add `HookObjectCacheConfigTest` runtime `apply_filters` mutation, upgrade `HookDatabaseCleanupPerTypeTest` to capture `do_action` counts.
* **Close CLI multisite hole (45 min):** add `WppoCliMultisiteTest.php` with `Functions\when('is_multisite')->justReturn(true)` + `get_current_blog_id 2` + `Util::transient_key` assertion for `database counts` — validates `AGENTS.md:158-163` isolation is plumbed through CLI.
* **Lazy FS guard (15 min):** one `MainFilesystemLazyTest.php` asserting `Main( enableCache true, is_admin false ) -> filesystem null` vs `is_admin true -> object`.
* **Do not add:** Behat integration, per-image `wppo_should_lazy_load_image` filter, `cache --dry-run`, `--network` flag, `progress_bar` — all REJECT per `FINAL-ADVERSARIAL-REVIEW.md` and `FINAL-PERF-REVIEW.md:9`.

---

## 6. Summary

On `fix/audit-2026-08-28` at `7ce4834`, **`tests/php` does cover the shipped WP-CLI + hooks changes** — help synopsis, json-only format, `Util::get_default_settings` converge, `--yes`/`--dry-run` gates (+ REJECT aliases/flags), and the 4 hooks + context fence are all touched by the 30 new tests across `WppoCliHelp/Format/Confirm/DryRun` (30) + `HookShouldCache/HookInvalidation/HookDatabase/HookObjectCache` (12) = **42 new assertions suites**. Full green `513/513` proves no regression. **But** the coverage is **documentation-heavy**: 10 of 42 new tests are source-string checks, 2 isolation defects make `--filter WppoCli` shard red, and 7 gaps (allowlist 8/12, no runtime hook mutations, no CLI multisite/TTY-positive, 3 json subcommands missing) leave the most security-sensitive paths (`wppo_object_cache_config` secret injection, per-type cleanup aggregate) only guarded by substring tests. Ship is safe; harden with `G-01..G-07` before tagging `v*`.

