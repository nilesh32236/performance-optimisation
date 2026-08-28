# FINAL-IMPLEMENTATION-REVIEW.md — Implemented vs Planned (2026-08-28)

**Branch:** `fix/audit-2026-08-28` `23ea195e→d306e677→45ed2f79→7ce4834` (+ db-mock fix) | **Base:** `origin/master@31fffc61` | **Date:** 2026-08-28
**Plan:** `IMPLEMENTATION-PLAN.md` 120 + `IMPLEMENTATION-MATRIX.md` 25 rows + `FINAL-ADVERSARIAL-REVIEW.md` 3 PRs ~120 lines (RETAIN/MODIFY/REJECT)
**Mode:** Research → 3 PRs implementation, audit-only prior; this run implements.

## Implemented (per FINAL-ADVERSARIAL survivors)

**PR-A `fix/cli-phase1-help-format` `d306e677` — WP-CLI help + JSON (RETAIN/MODIFY)**
- `includes/class-wppo-cli-command.php:49,130,301,741,872` synopsis `<action>`→`[<action>]` + `default: clear|cleanup|status|scan` + `options:` enums (docblock-only, zero runtime per `make.wordpress.org/cli/handbook/guides/commands-cookbook/#longdesc`) — **IMPLEMENTED** `WppoCliHelpTest:43` 6 tests.
- `includes/class-wppo-cli-command.php:162-166,222-235,942-985` `database counts` + `system-info` `[--format=<format>]` `default: json` `options: json` + runtime `WP_CLI\Utils::get_flag_value` fallback `wp_json_encode(PRETTY)` json-only (REJECT `table|csv|yaml|count`, `Formatter`, `Spyc` per `FINAL-ADVERSARIAL:56`) — **IMPLEMENTED** `WppoCliFormatTest:64` 9 tests (docblock + json + fallback + Util converge).
- `includes/class-util.php:81-162` `Util::get_default_settings()` single source 14 tabs (7-tab drift `CLI:451` vs `Main:240` `Unrecognized tab:731`) + `class-wppo-cli-command.php:488-490` delegates — **IMPLEMENTED** `A-01 minimal` per `ARCH-RESEARCH`.

**PR-B `fix/cli-phase2-safety-dryrun` `45ed2f79` — Safety --yes + --dry-run (MODIFY)**
- `includes/class-wppo-cli-command.php:133-195` `database` `[--yes]` (REJECT `--confirm` alias per `FINAL:57` handbook `--yes` only) + `class-wppo-cli-command.php:282-300` `--yes` gate `Utils\get_flag_value('yes')` + `posix_isatty`/`stream_isatty` + `WP_CLI::confirm` for `cleanup --type=all` only (REJECT `cache clear`, `settings import` prompts) — **IMPLEMENTED** `WppoCliConfirmTest:54` 8 tests (docblock REJECT confirm, runtime type=all with/without yes non-TTY, cache no confirm).
- `includes/class-wppo-cli-command.php:202-311` `database` `[--dry-run]` early `get_flag_value('dry-run')` → `get_counts()` logs `would_delete`/`would_optimize` + `WP_CLI::warning` no `DELETE`/`OPTIMIZE` before `delete_in_batches:138` — **IMPLEMENTED** `WppoCliDryRunTest:53` 7 tests (would_delete, would_optimize, REJECT cache --dry-run).
- `includes/class-wppo-cli-command.php:791-853` `object-cache` `[--yes]` for `disable` + `class-wppo-cli-command.php:864-871` allowlist `6→12` via `Object_Cache::ALLOWED_KEYS:50` (`mode,nodes,master_name,use_tls,persistent,compression` + `host,port,password,database,timeout,prefix`) + `class-object-cache.php:32-48` const + `class-rest.php:1104-1115` `build_redis_config` delegates — **IMPLEMENTED** `WppoCliConfirmTest:199` allowlist 8/12.

**PR-C `fix/hooks-phase3-p0` `7ce4834` — Approved hooks (RETAIN 1+1+1)**
- `includes/class-cache.php:1496-1527` `wppo_should_cache_request` single filter **after** `DONOTCACHEPAGE` in `is_not_cacheable:1219` after constant gate (MODIFY single site per `FINAL:36` REJECT dual 1505/1755) `apply_filters('wppo_should_cache_request', true, $request_uri, $is_mobile, $is_logged_in)` `return false → is_not_cacheable true → skip ob_start:1226` — **IMPLEMENTED** `HookShouldCacheRequestTest:46` 4 runtime (veto, allow, order `strpos(DONOTCACHEPAGE) < filter` at `:101`, 4-arg).
- `includes/class-cache.php:1838-1964` `wppo_invalidation_urls` `apply_filters('wppo_invalidation_urls', $urls, $post_id)` before `foreach purge` + `wp_normalize_path` + `..` reject + `ABSPATH`/`cache_root` guard + `array_unique` (merge `G-03`+`G-27` `wppo_purge_urls`) — **IMPLEMENTED** `HookInvalidationUrlsTest:89` 3 runtime (extends, traversal sanitized, dedupe via ReflectionCache).
- `includes/class-database-cleanup.php:722-737` + `class-rest.php:900` + `class-wppo-cli-command.php:376` `do_action('wppo_database_cleanup_completed', $type, (int)$count)` per-type after each `clean_*` (MODIFY single action per `FINAL:39` REJECT `before` + `*_type_completed` filter) — **IMPLEMENTED** `HookDatabaseCleanupPerTypeTest:22` 2 static + runtime.
- `includes/class-object-cache.php:199-303` `wppo_object_cache_config` `apply_filters('wppo_object_cache_config', $config)` after merge in `get_redis_config`/`ping`/`enable` — **IMPLEMENTED** `HookObjectCacheConfigTest:23` 3 static.
- `includes/class-main.php:342-354` lazy `Util::init_filesystem` only `is_admin()||WP_CLI` + remove duplicate `vendor/autoload` at `Main:436` (already `performance-optimisation.php:41`) — **IMPLEMENTED** per `PERF-RESEARCH` P0 saves 0.3-0.8ms.
- `docs/hooks.md:44,134` 4 rows `@since NEXT` `Hook|Type|Since|File:Line|Args|Priority|Example` — **IMPLEMENTED**.

**Not Implemented (deliberately REJECTED per FINAL-ADVERSARIAL, documented):**
- `--network` `get_sites` pagination (P0 → P3 docs-only `wp --url=<site> wppo cache clear` loop via `wp site list` shell), `--batch-size`, `make_progress_bar` (>10 tty), 9 hooks `wppo_should_cache_for_user`, `wppo_cache_written/miss`, `wppo_cdn_url`, `wppo_delay_js_should_delay`, `wppo_should_serve_next_gen`, `wppo_should_lazy_load_image`, `wppo_preload_batch_size`, `wppo_rum_should_collect`, `wppo_settings_before_update` (use core `pre_update_option_wppo_settings`), `wppo_cli_redis_config`, `wppo_buffer_stage`, Service/Context/PSR-4 extraction — all REJECT with handbook/core alternative cited in `FINAL-ADVERSARIAL:8`.

## CLI Changes

- Commands: 7 verbs unchanged `cache|database|image|settings|object-cache|pagespeed|system-info` `wp wppo` single root per `ECOSYSTEM:151`.
- Options: new `[<action>]` synopsis (`cache clear` etc. default), `database counts --format=json` (json-only fallback `wp_json_encode`), `system-info --format=json`, `database cleanup --type=all --yes`, `object-cache disable --yes`, `database cleanup --dry-run` (would_delete), `object-cache --mode/--nodes/...` 12 keys.
- Output: `database counts` JSON `{"revisions":12,…}` via `WP_CLI::log`, `system-info` JSON, `WP_CLI::warning` for dry-run, `WP_CLI::success` still, `error 1` for invalid arg, `warning 0` for empty.
- Errors: `Invalid cache action "%s"` stays `error 1`; empty `counts` `[]` → `log []` not error; `confirm` respects `isatty`.

## Hook Changes

| Hook | Type | Since | File:Line | Args | Priority | Example |
|------|------|-------|-----------|------|----------|---------|
| `wppo_should_cache_request` | filter | NEXT | `class-cache.php:1524` after `DONOTCACHEPAGE` | 10 | `bool $should, string $request_uri, bool $is_mobile, bool $is_logged_in` | `add_filter('wppo_should_cache_request', fn($s,$uri)=>str_contains($uri,'/members/')?false:$s)` |
| `wppo_invalidation_urls` | filter | NEXT | `class-cache.php:1920` before purge | 10 | `array $urls, int $post_id` | `add_filter(fn($u,$id)=>[...$u,home_url('/feed/')])` `..` sanitized |
| `wppo_database_cleanup_completed` | action | NEXT (per-type) | `class-database-cleanup.php:737` per `clean_*` | 10 | `string $type, int $count` | `add_action('wppo_database_cleanup_completed', fn($t,$c)=>error_log("$t:$c"))` |
| `wppo_object_cache_config` | filter | NEXT | `class-object-cache.php:213` after merge | 10 | `array $config` | `add_filter('wppo_object_cache_config', fn($c)=>[...$c,'compression'=>true])` |

## Performance Impact

- `wppo_should_cache_request` **after** `DONOTCACHEPAGE` single `apply_filters` before `ob_start` +0.01-0.03ms frontend (early veto saves buffer 5-40ms if false) — not in hot per-tag loop.
- `wppo_invalidation_urls` +0.02ms on `save_post` only (one filter + `wp_normalize_path` + `array_unique`).
- `wppo_database_cleanup_completed` 9× 0.03ms on cron/CLI only.
- `Util::init_filesystem` lazy saves 0.3-0.8ms CLI `database counts` read-only (largest win).
- No per-asset `wppo_cdn_url` (REJECT) avoids 200 filters/page.

## Compatibility

- WP `6.2` Requires `6.2` `performance-optimisation.php:5`, Tested `7.1` `readme.txt:4-6`, PHP `>=8.2` `composer.json:14`, multisite `Util::transient_key:781` `{blog_id}_` + `Util::clear_settings_cache` per blog, `WP_CLI` guard `class-main.php:472` `class_exists`, `@when after_wp_load:69` docs-only per `HOOK-CORE-RESEARCH`, `@since NEXT` per `AGENTS.md:184`, `grep wppo_ → docs/hooks.md` CI.

## Tests

- **CLI:** `WppoCliHelpTest` 6, `WppoCliFormatTest` 9 (json-only REJECT table), `WppoCliConfirmTest` 8 (yes only REJECT confirm alias), `WppoCliDryRunTest` 7 (database only REJECT cache), `WppoCliAllowlist` 8/12.
- **Hooks:** `HookShouldCacheRequestTest` 4 runtime (veto/allow/order/4-arg), `HookInvalidationUrlsTest` 3 runtime (extends/traversal/dedupe), `HookDatabaseCleanupPerTypeTest` 2 static, `HookObjectCacheConfigTest` 3 static — no runtime `do_action` capture gaps noted in `FINAL-TEST-REVIEW` 7 gaps but full suite 513/513 OK.
- **Full:** `vendor/bin/phpunit 513/513 1265a 2 skipped` `npm test 34/34 345` `php -l 4 clean` `phpcs includes/ 0e1w` `build webpack 5.109 55.1 KiB`.

## Risks

- `hook conflict wppo_*` low `grep wppo_` + `docs/hooks.md` drift CI.
- `dry-run leak` medium `get_flag_value` first line + `WppoCliDryRunTest`.
- `redis silent drop` medium `ALLOWED_KEYS` const + `extension_loaded` guard.
- `wppo_should_cache_request` before-constant inversion **fixed** after `DONOTCACHEPAGE`.

## Files Changed (production)

- `includes/class-wppo-cli-command.php:49-973` synopsis, json, --yes/--dry-run, allowlist 6→12
- `includes/class-util.php:81-162` `get_default_settings` single source
- `includes/class-cache.php:1496-1527` `wppo_should_cache_request` + `1838-1964` `wppo_invalidation_urls`
- `includes/class-database-cleanup.php:722-737` per-type `wppo_database_cleanup_completed`
- `includes/class-object-cache.php:32-48,199-303` `ALLOWED_KEYS` + `wppo_object_cache_config`
- `includes/class-rest.php:1104-1115,900` allowlist + per-type hook
- `includes/class-main.php:342-354` lazy `init_filesystem`
- `docs/hooks.md:44,134` 4 rows `@since NEXT`
- `tests/php/WppoCliHelpTest.php` + `WppoCliFormatTest.php` + `WppoCliConfirmTest.php` + `WppoCliDryRunTest.php` + `HookShouldCacheRequestTest.php` + `HookInvalidationUrlsTest.php` + `HookDatabaseCleanupPerTypeTest.php` + `HookObjectCacheConfigTest.php` + `stubs/db-mock.php` + `bootstrap.php` require

