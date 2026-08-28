# IMPLEMENTATION-LOG.md — WP-CLI Hooks Implementation Progress

## Phase1 PR-A `fix/cli-phase1-help-format` — 2026-08-28

**Branch:** `fix/audit-2026-08-28` → `origin/master@31fffc61`
**Scope:** FINAL-ADVERSARIAL-REVIEW PR-A (RETRAIN synopsis `[<action>]`, JSON-only format, Util::get_default_settings converge)
**Files:**
- `includes/class-wppo-cli-command.php:49,130,301,741,872` synopsis `[<action>]` + `default: clear|cleanup|status|scan` + options enum (docblock-only, zero runtime)
- `includes/class-wppo-cli-command.php:162-166,942-951` `[--format=<format>]` json default for `database counts` + `system-info`
- `includes/class-wppo-cli-command.php:222-235,972-985` JSON-only handling via `WP_CLI\Utils::get_flag_value` fallback to `wp_json_encode(PRETTY)` (REJECT Formatter table/csv/yaml, Spyc)
- `includes/class-util.php:81-162` `Util::get_default_settings()` single source (14 tabs, 7-tab drift fix CLI:451 vs Main:240)
- `includes/class-wppo-cli-command.php:488-490` `get_default_settings()` delegates to `Util::get_default_settings()` (A-01 minimal)
- `includes/class-wppo-cli-command.php:435,505` settings `[<action>]` synopsis fix (bonus, default: get)
- `tests/php/WppoCliHelpTest.php` 6 tests — asserts `[<action>]`, defaults, options, no required `<action>`
- `tests/php/WppoCliFormatTest.php` 9 tests — docblock json, no Formatter, json output, fallback, Util converge

**Verification:**
- `php -l includes/class-wppo-cli-command.php` OK
- `php -l includes/class-util.php` OK
- `vendor/bin/phpcs --standard=phpcs.xml includes/class-wppo-cli-command.php includes/class-util.php` 0 errors (3 auto-fixed via phpcbf)
- `vendor/bin/phpunit --filter WppoCli` 15/15 OK
- `vendor/bin/phpunit` 486/486 OK (2 skipped)
- No Formatter/Spyc added, cache status human logs unchanged, backward compat preserved, @since NEXT

**Rejected scope kept:** No `--network`, no `--dry-run`, no hooks, no `--batch-size`, no progress bar (deferred per FINAL-ADVERSARIAL-REVIEW).

**Commit:** Phase1 PR-A — synopsis, json-only, Util defaults converge

## Phase2 PR-B `fix/cli-phase2-safety-dryrun` — 2026-08-28

**Branch:** `fix/audit-2026-08-28` (d306e677)
**Scope:** FINAL-ADVERSARIAL-REVIEW PR-B (MODIFY --yes + --dry-run + allowlist converge, REJECT --confirm/cache-dry-run/--network)
**Files:**
- `includes/class-wppo-cli-command.php:133-195` database docblock `[--yes]` + `[--dry-run]` (REJECT --confirm alias, cache dry-run)
- `includes/class-wppo-cli-command.php:202-311` `database()` --dry-run early `Utils::get_flag_value('dry-run')` → `get_counts()` logs `would_delete` + `WP_CLI::warning` no DELETE/OPTIMIZE, `--yes` gate for `type==all` with `posix_isatty`/`stream_isatty` check + `WP_CLI::confirm` (REJECT --confirm, non-tty skip)
- `includes/class-wppo-cli-command.php:791-853` object-cache docblock `[--yes]` + `[--mode/--nodes/--master_name/--use_tls/--persistent/--compression]` converge 6→12
- `includes/class-wppo-cli-command.php:880-905` `object_cache()` disable `--yes` gate (same isatty logic, REJECT --confirm)
- `includes/class-wppo-cli-command.php:915-928` `get_redis_config_from_assoc()` uses `Object_Cache::ALLOWED_KEYS` (single source)
- `includes/class-object-cache.php:32-48` `Object_Cache::ALLOWED_KEYS` const (12 keys: mode,host,port,password,database,timeout,prefix,nodes,master_name,use_tls,persistent,compression)
- `includes/class-rest.php:1104-1115` `build_redis_config()` uses `Object_Cache::ALLOWED_KEYS` + handles `timeout`/`prefix` sanitization (converged)
- `tests/php/WppoCliConfirmTest.php` 8 tests — docblock yes, no confirm alias, yes-skips-confirm, non-tty skips, cache no confirm, allowlist converged
- `tests/php/WppoCliDryRunTest.php` 7 tests — docblock dry-run, cache no dry-run, would_delete preview, would_optimize preview, no network flag
- `tests/php/WppoCliFormatTest.php` patched stub to include `WP_CLI::$confirms` for cross-test isolation

**Verification:**
- `php -l includes/class-wppo-cli-command.php` OK
- `php -l includes/class-object-cache.php` OK
- `php -l includes/class-rest.php` OK
- `vendor/bin/phpcs --standard=phpcs.xml includes/class-wppo-cli-command.php includes/class-object-cache.php includes/class-rest.php` 0 errors
- `vendor/bin/phpunit --filter WppoCli` 30/30 OK (15 Phase1 + 15 Phase2)
- `vendor/bin/phpunit` 501/501 OK (2 skipped, +15 Phase2)
- No --confirm alias, no cache clear prompt, no --network, no cache dry-run per FINAL-ADVERSARIAL-REVIEW

**Rejected scope kept:** REJECT `--confirm` alias (handbook --yes only), REJECT `cache clear` prompt/dry-run (idempotent), REJECT `--network` (docs-only shell loop), REJECT hooks/progress/batch-size

**Commit:** Phase2 PR-B — --yes (all+disable) + --dry-run (cleanup/optimize) + allowlist converge

## Phase3 PR-C `fix/hooks-phase3-p0` — 2026-08-28

**Branch:** `fix/audit-2026-08-28` (45ed2f79 → NEXT)
**Scope:** FINAL-ADVERSARIAL-REVIEW PR-C (RETAIN single wppo_should_cache_request after DONOTCACHEPAGE, wppo_invalidation_urls, wppo_database_cleanup_completed per-type, plus wppo_object_cache_config and lazy init_filesystem per MATRIX)
**Files:**
- `includes/class-cache.php:1496-1525` `wppo_should_cache_request` single filter after `DONOTCACHEPAGE` (4 args: `$should, $request_uri, $is_mobile, $is_logged_in`), DONOTCACHEPAGE wins if true, return false → `is_not_cacheable=true` → skip `ob_start`/`process_buffer_for_cache`
- `includes/class-cache.php:1838-1920` `wppo_invalidation_urls` filter at `invalidate_dynamic_static_html` before foreach, sanitize via `wp_normalize_path` + `ABSPATH`/`cache_root` guard, `array_unique` dedupe, primary css/used-css handling preserved
- `includes/class-database-cleanup.php:722-737` per-type `wppo_database_cleanup_completed` inside `clean_all` loop (`$key, (int)$res`) plus existing `all` aggregate; `includes/class-rest.php:900-908` and `includes/class-wppo-cli-command.php:376-384` single-type per-type firing for REST/CLI
- `includes/class-object-cache.php:199-250` `get_redis_config()` merged settings+file + `wppo_object_cache_config` filter; `ping()`/`enable()` also filter incoming config; `get_status()` now delegates to `get_redis_config()`
- `includes/class-main.php:342-350` lazy `Util::init_filesystem()` only when `is_admin()||WP_CLI` (0.3-0.8ms frontend save); `includes/class-main.php:436` removed duplicate `vendor/autoload.php` (already in `performance-optimisation.php:41`)
- `docs/hooks.md:44-56,134-175` 4 rows @since NEXT: `wppo_should_cache_request`, `wppo_invalidation_urls`, `wppo_object_cache_config`, updated `wppo_database_cleanup_completed` per-type
- `tests/php/HookShouldCacheRequestTest.php` 4 tests — veto false, allow true, DONOTCACHEPAGE wins, 4-arg reception
- `tests/php/HookInvalidationUrlsTest.php` 3 tests — extends purge, traversal sanitized, dedupe
- `tests/php/HookDatabaseCleanupPerTypeTest.php` 2 tests — source contains per-type + all
- `tests/php/HookObjectCacheConfigTest.php` 3 tests — get_redis_config filter, ping filter source checks

**Verification:**
- `php -l includes/class-cache.php includes/class-database-cleanup.php includes/class-object-cache.php includes/class-main.php includes/class-rest.php includes/class-wppo-cli-command.php` OK
- `vendor/bin/phpcs --standard=phpcs.xml includes/class-cache.php` 0 errors (5 auto-fixed), `includes/class-database-cleanup.php` 0, `includes/class-object-cache.php` 0, `includes/class-main.php` 0, `includes/class-rest.php` 0
- `vendor/bin/phpunit --filter Hook` 12/12 OK (HookShouldCache 4, HookInvalidation 3, HookDatabase 2, HookObjectCache 3)
- `vendor/bin/phpunit` 513/513 OK (2 skipped, +12 Phase3)
- Rejected hooks kept: `wppo_should_cache_for_user`, `wppo_cache_written/miss`, `wppo_cdn_url`, `wppo_delay_js` etc per FINAL-ADVERSARIAL-REVIEW

**Rejected scope kept:** REJECT `wppo_should_cache_for_user` (covered by single veto + loggedInCacheRoles), REJECT `wppo_cache_written/miss`/`wppo_purge_urls` (covered by after_cache_clear + cache_page_html), REJECT `wppo_cdn_url` per-asset (use litespeed_can_cdn + cdnURL), REJECT `wppo_delay_js_should_delay`/`wppo_should_serve_next_gen`/`wppo_should_lazy_load_image` (use exclude settings), REJECT `wppo_cli_redis_config` (converge via ALLOWED_KEYS suffices), REJECT context fence 30-hook (keep lazy filesystem only), REJECT `--network`/`--batch-size`/`make_progress_bar`

**Commit:** Phase3 PR-C — hooks p0 single veto + inval + per-type + object-cache config + lazy fs
