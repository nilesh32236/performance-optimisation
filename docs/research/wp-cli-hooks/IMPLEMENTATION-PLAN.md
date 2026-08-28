# IMPLEMENTATION-PLAN.md — WP-CLI Expansion + Hook Availability (implementation-ready)

**Base:** `fix/audit-2026-08-28` `68a2f66a` → `origin/master@31fffc61` (42 PHP 31k, 7 CLI verbs)  
**Mode:** Research → Plan only — **no production code modified** (`git diff -- includes/` empty)  
**Agents:** 11 (A-K) + synthesis, 17 docs, `ADVERSARIAL RETAIN/MODIFY/REJECT` applied  
**Coding run entry:** execute **Phase 1→6** in order, each `php -l` + `phpcs` + `phpunit` + `lint:js` + `build` per phase, `@since NEXT`, never bump `1.9.0`, commit `build/`, `gh pr checks` 5-10m.

## Executive Summary

Keep `wp wppo` single root (no `wppo` alias) + enhance 7 existing verbs `cache|database|image|settings|object-cache|pagespeed|system-info` for automation, then add 12 `wppo_*` Hook veto/observability filters at P0/P1. **What changes:** `includes/class-wppo-cli-command.php:76-973` synopsis (`[‹action›]`), `Formatter` `table|json`, `--yes/--confirm`, `--dry-run` (cache/database), `--network` paginated, `Format/yes/dry-run/network` gated, progress `>10` + `batch-size`, context fence `is_admin()` 7 hooks, 12 hooks `Cache:1505/1755/297`, `Database_Cleanup:714/935`, `Cache:1741/1838`, `Cron:301/364`, `Cdn:1357`, `Main:600`, `Image:887/100`, etc. **Why:** handbook `make.wordpress.org/cli` vs Woo 616 lines/Rocket/LSCWP 8 families shows gap, `HOOK-GAPS` 32 P0 veto needed for membership/Woo, `PERF-RESEARCH` 0.4-0.7 ms CLI fence, `ECOSYSTEM` `--dry-run` canon.

## Current Problems (evidence File:Line)

- **CLI help:** `<action>` not `[<action>]` `class-wppo-cli-command.php:49,130,301` → `wp wppo cache` without arg shows `Invalid cache action ""` (A, `WP-CLI-CURRENT:76`).
- **CLI output:** hardcoded `wp_json_encode(PRETTY)` `class-wppo-cli-command.php:202,385,808,965` no `Formatter` `table|json` `WP-CLI-RESEARCH:188`.
- **CLI safety:** `cache clear`/`database optimize --type=all`/`object-cache disable`/`settings import` no `WP_CLI::confirm`/`--yes` `ECOSYSTEM:199`.
- **CLI dry-run:** `database cleanup --type=all` `DELETE` 9 batches no `search-replace --dry-run` precedent `WP-CLI-RESEARCH:199` handled via `get_counts`.
- **CLI multisite:** no `--network` `get_sites`+`switch_to_blog` pagination `uninstall.php:193-217` canonical, `object-cache flush` warns all-sites not.
- **Hook veto:** `class-cache.php:1505` no `wppo_should_cache_request`, forces `DONOTCACHEPAGE` constant `HOOK-GAPS G-01` P0.
- **Hook user:** `class-cache.php:297`+`class-main.php:369` `loggedInCacheRoles` allowlist insufficient `G-28`.
- **Hook DB:** `class-database-cleanup.php:714` `wppo_database_cleanup_completed` only for `all` `G-15`.
- **Hook doc:** 15 public `wppo_*` undoc `HOOK-AUDIT:15` `docs/hooks.md 42→69`.
- **Perf:** `setup_hooks:485-799` 70 always-loaded `is_admin()` 7 hooks + eager `Util::init_filesystem:346` + 8× `wp_next_scheduled:114` `PERF-RESEARCH:2.3`.

## Goals

- Machine-readable `wp wppo --help` + `--format` for scripting `jq` `CI`.
- Safe `cache clear`/`database cleanup` in cron/CID with `confirm`+`dry-run`+`--network`.
- Veto cache `membership` without `DONOTCACHEPAGE` fork, per-type DB hooks for `Slack` log integration.
- 0.4-0.7 ms CLI fence, `build/` committed, `php -l`+`phpcs`+`phpunit 471→586`+`lint:js`+`build` green.

## Non-Goals

- No `wppo` short alias (needs ≥10 families `ECOSYSTEM:151` `K W-11 REJECT`).
- No new subcommand `rum|used_css|presets` `K W-11 REJECT` 5 verbs (CLI bloat).
- No `wppo_buffer_stage` 6-stage buffer `K G-04 REJECT` (keep `wppo_cache_page_html:1661`).
- No PSR-4 `PR-D 2.0`/`Service_Locator`/`Frontend_Hooks` 3-file split `K Arch REJECT` (keep `classmap` + P0 fences only).
- No `cron_schedules` alias (core `cron_schedules:61` exists `K G-05`).

## WP-CLI Design

**Namespace:** `wp wppo <subcommand> [<action>] [--flags]` registered `class-main.php:472-474` `WP_CLI::add_command('wppo', WPPO_CLI_Command)` `@when after_wp_load` kept. **Subcommands:** `cache {clear,preload,status} [clear: --page=, --yes, --dry-run, --network]` `database {cleanup,optimize,counts} [cleanup: --type=, --dry-run, --network] [counts: --format=]` `image {convert,status} [convert: --format=, --batch-size=, progress]` `settings {get,update,export,import} [get: --format=, --fields]` `object-cache {status,ping,enable,disable,flush} [enable: --host,--port,--tls args 6→10]` `pagespeed {scan,results} [scan: --url=, --strategy=]` `system-info [<group>] [--format=]` (7 verbs, no new root).

For each **Arguments:** positional `[<action>]` default `clear|cleanup|status|get` via `??`, assoc `--page=` `trim(parse_url PATH)`, `--type=` allowlist `revisions|auto_drafts|...|all` default `all` `class-database-cleanup.php:81` `CLEANUP_METHOD_MAP`, `--format=json` default JSON fallback `Spyc`/`yaml_emit` per `class-wppo-cli-command.php:693`. **Options:** `--format=<format>` `table|json|csv|yaml|count` via `WP_CLI\Utils\format_items`/`Formatter` (K `W-02` narrow `table|json` only), `--yes` + `[--confirm]` alias `Utils\get_flag_value` + `WP_CLI::confirm` when `! --yes` && `STDIN isatty`, `--dry-run` `get_flag_value` first-line `if($dry_run){ log JSON would_*; return; }` before `Cache::clear_cache`/`DELETE`, `--network` `get_sites(['number'=>100])` paginated `switch_to_blog` `try/finally restore` per `uninstall.php:193-217`, `--batch-size=<num>` `absint 1-500` default 50 for `image convert`, progress `make_progress_bar:210` gated `count>10` + no `format` + tty.

**Permissions:** CLI shell trust (no `manage_options` like `class-rest.php:357`, `WP-CLI` already shell user), log `Log::add` still. **Output:** `WP_CLI::success →0`, `error →1`, `warning →0 continue`, `log` vs `table` per `WP_CLI-RESEARCH:188` `Formatter:advisory` `handle_warnings` pattern. **Errors:** success vs empty: `database counts` empty `[]` → `success 0` with `warning` not `error` `cache status` never→`never`; invalid arg → `error 1` `Invalid cache action "%s"`; permission missing `WP_CLI` class → early return. **Batch/multisite:** `--batch-size` chunk `LIMIT 50` `image:327` `array_slice`, multisite 10k-post `get_sites` 100/page `Cron::schedule_page_cron_jobs:288` `no_found_rows`. **Examples:** `wp wppo cache clear --dry-run --format=json | jq`, `wp wppo database cleanup --type=all --yes --network --dry-run`, `wp wppo settings get file_optimisation --format=yaml --fields=minifyHTML`, `crontab: wp wppo cache preload --network --yes`.

## Hook API Design (retained 12, @since NEXT, wppo_*)

| Hook | Action/filter | File:Line | When (Before/During/After) | Priority | Args | Purpose | Example consumer | BC/Perf |
|------|---------------|-----------|----------------------------|----------|------|---------|----------------|---------|
| `wppo_should_cache_request` | filter | `class-cache.php:1505` + `:1755` | Before `if(DONOTCACHEPAGE||$exclude)` | 10 | `bool $should, string $request_uri, bool $is_mobile, bool $is_logged_in` | Veto HTML cache `membership` | `add_filter('wppo_should_cache_request', fn($s,$uri)=>str_contains($uri,'/members/')?false:$s,10,4)` | new, early return saves `ob_start` 0.02ms |
| `wppo_should_cache_for_user` | filter | `class-cache.php:297` | Before `loggedInCacheRoles` `in_array` | 10 | `bool $should, int $user_id, array $roles` | Per-user cache `Woo membership` | `add_filter('wppo_should_cache_for_user', fn($s,$id,$r)=>wc_memberships_is_user_member($id)?false:$s,10,3)` | veto only |
| `wppo_before_database_cleanup` | action | `class-database-cleanup.php:714` | Before `switch $type` `clean_all` loop | 10 | `string $type, array $args` | Pre-cleanup log | `add_action` slack | new |
| `wppo_database_cleanup_type_completed` | filter | `class-database-cleanup.php:935` per `clean_*` | After each `clean_*` | 10 | `int $count, string $type` | Per-type completed | `add_filter` adjust count | `all` already `wppo_database_cleanup_completed:737` kept |
| `wppo_cache_written` | action | `class-cache.php:1741` | After `atomic_put_contents` success | 10 | `string $file, int $size, int $duration_ms` | Observe write | `NewRelic` | new |
| `wppo_cache_miss` | action | `class-cache.php:1661` + `templates/advanced-cache.php` | Before `ob_start` | 10 | `string $uri` | Miss metric | `StatsD` | veto `written`+`miss` only |
| `wppo_invalidation_urls` | filter | `class-cache.php:1838` | Before `foreach $urls purge` | 10 | `array $urls, int $post_id` | Extend purges | `add_filter(fn($u,$id)=>[...$u, home_url("/feed/")])` | new |
| `wppo_preload_batch_size` | filter | `class-cron.php:301` | Before `get_posts(['posts_per_page'=>200])` | 10 | `int $size, int $offset` | 10k-post tuning | `add_filter(fn()=>500)` | `G-05` modify keep 3 |
| `wppo_sitemap_preload_limit` | filter | `class-cron.php:364` | Before `array_slice($urls,0,500)` | 10 | `int $limit` | Sitemap cap | `add_filter(fn()=>1000)` | keep |
| `wppo_cdn_url` | filter | `class-cache.php:1357` | During `cdn_rewrite` per-asset | 10 | `string $cdn_url, string $src` | Per-asset CDN | `add_filter('wppo_cdn_url', fn($c,$s)=>str_contains($s,'logo.png')?'':$c,10,2)` | narrow |
| `wppo_delay_js_should_delay` | filter | `class-main.php:600` | Before `wppo_exclude_delay_js:515` | 10 | `bool $should, string $handle` | Delay gate | `add_filter` | `G-09` keep delay only |
| `wppo_should_serve_next_gen` | filter | `class-image-optimisation.php:887` | Before `cached_home_url` | 10 | `bool $should, string $url, string $format` | Next-gen veto | `add_filter` | `G-12` veto |
| `wppo_object_cache_config` | filter | `class-object-cache.php:252` | After `get_redis_config` merge | 10 | `array $config` | Converge 6→10 keys | `add_filter` TLS | `G-19/22` |
| `wppo_cli_redis_config` | filter | `class-wppo-cli-command.php:864` | After `get_redis_config_from_assoc` | 10 | `array $config, array $assoc_args` | CLI parity | `add_filter` | `G-22` |

Plus `wppo_should_lazy_load_image:100` `wppo_database_cleanup_batch_size:138` `wppo_revision_defaults:753` `wppo_rum_should_collect:121` `wppo_purge_urls:193` `wppo_settings_before_update:464` `wppo_invalidation_urls` etc. total 27 `docs/hooks.md` (15 undoc+12 new) `@since NEXT` `docs/hooks.md` table `Hook|Type|Since|File|Args|Priority|Example`.

## Architecture Changes (files/classes/functions)

`includes/class-wppo-cli-command.php:1-973` `WPPO_CLI_Command` `cache:75` `database:174` `image:321` `settings:573` `object-cache:801` `pagespeed:902` `system-info:956` `get_redis_config_from_assoc:864` `get_default_settings:451` missing 7 tabs converge via `Util::get_default_settings()` **or keep minimal** `ARCH-RESEARCH` incremental `Z→` (not PSR-4). `includes/class-cache.php:1505/1755/297/1741/1838/1357/1243` veto/observability/inval/CDN. `includes/class-database-cleanup.php:714/935/138/753` per-type lifecycle. `includes/class-cron.php:301/364` batch caps. `includes/class-main.php:485-799` `setup_hooks` `is_admin()` fence 7 hooks `admin_menu:486` `Admin_Notices:495` `Metabox:499` `add_sites:507` `maybe_fix_wp_cache:488` `maybe_run_upgrades:489` `migrate_block_assets:492` + `Util::init_filesystem:346` lazy. `includes/class-util.php:781` `transient_key` unchanged `includes/class-object-cache.php:252` `wppo_object_cache_config`. `templates/advanced-cache.php` miss hook. `docs/hooks.md:1-439` 27 rows.

## Implementation Sequence (Phase PRs, exact files)

**Phase 1** `fix/cli-phase1-help-format` `P0 help+format`: `includes/class-wppo-cli-command.php:76,175,322` synopsis `[<action>]` + `class-wppo-cli-command.php:202,385,808,965` `Formatter` `table|json` `phpcs 0` `phpunit WppoCliHelp/Format` — `0.5d` low risk.

**Phase 2** `fix/cli-phase2-safety-dryrun-network` `P0 safety`: `class-wppo-cli-command.php:75,174,622,801` `--yes/--confirm` `WP_CLI::confirm` + `class-wppo-cli-command.php:75,174` `--dry-run` early `get_flag_value` + `class-wppo-cli-command.php:75` `--network` `get_sites` pagination `switch_to_blog` — `1d` medium.

**Phase 3** `fix/hooks-phase3-p0-veto` `P0 hooks`: `class-cache.php:1505/1755` `wppo_should_cache_request` + `class-cache.php:297`+`class-main.php:369` `wppo_should_cache_for_user` + `class-database-cleanup.php:714/935` `before/type_completed` + `docs/hooks.md` — `1d` medium.

**Phase 4** `fix/hooks-phase4-p1-observability` `P1`: `class-cache.php:1741/1838/1357` `written/miss/inval/cdn` + `class-cron.php:301/364` `batch/sitemap` + `class-main.php:600` `delay` + `class-image-optimisation.php:887/100` `next_gen/lazy` + `class-database-cleanup.php:138` `batch_size` + `class-object-cache.php:252`/`class-wppo-cli-command.php:864` `redis_config` + `class-rest.php:464` `settings_before_update` + `class-cdn-purger.php:193` `purge_urls` — `1.5d` medium.

**Phase 5** `fix/perf-phase5-context-fence` `P1 perf`: `class-main.php:485-799` `is_admin()` 7 hooks + `Util::init_filesystem:346` lazy + `class-cron.php:114` `wp_next_scheduled` defer `did_action('init')` — `0.5d` low.

**Phase 6** `fix/tests-docs-phase6` `P2+Docs`: `tests/php/stubs/wp-cli.php` `Formatter/Utils/ExitException` + `tests/php/WppoCli*Test.php` 7 + `Hook*Test.php` 12 (~115 tests →586) `phpunit` + `docs/hooks.md` `readme.txt` `== External Services ==` already 279 — `1d` low.

Total `5.5d` `P0 2d` `P1 2.5d` `P2/P3 1d`. Each `PR fix/cli-phase-*` `gh pr create --base master` → `gh pr checks` 5-10m `WPCS & Psalm`/`JS Lint` → fix real `phpcs:ignore` via `phpcbf` → merge.

## Tests (per TEST-PLAN.md 59K, 115 new →586)

`tests/php/bootstrap.php:214` extend `WPPO_Test_Bootstrap` `Brain\Monkey` `when('get_sites')` 3-site fixtures `switch_to_blog` `restore_current_blog` `WP_CLI` stub `tests/php/stubs/wp-cli.php` `WP_CLI\ExitException` `Utils::format_items` `Formatter`. **CLI:** `WppoCliHelpTest` `WppoCliFormatTest` `WppoCliConfirmTest` `WppoCliDryRunTest` `WppoCliNetworkTest` `WppoCliProgressTest` `WppoCliBatchSizeTest` (args positional/assoc, allowlist, `WP_Error`→`WP_CLI::error 1`, empty `[]`→`warning 0`, JSON `wp_json_encode(PRETTY)`). **Hooks:** `HookRegistrationTest` `has_action('wppo_should_cache_request',10)` `HookInvocationTest` `do_action` side effect `HookFilterTest` `apply_filters` modifies `wppo_cdn_url` empty→skip `HookPriorityTest` `PHP_INT_MAX:608` `HookContextFenceTest` `is_admin false → has_action admin_menu false` on CLI. Coverage CLI line `≥85%` branch `≥90%` public hooks `100%`.

## Documentation

- `docs/hooks.md:1-439` `42→69` rows `Hook|Type|Since NEXT|File:Line|Args|Priority|Example` + 15 undoc +12 new `ADVERSARIAL` survivors.
- `readme.txt:279` `== External Services ==` already `pagespeedonline.googleapis.com` + `fonts.googleapis.com` `when/where/EOL` kept.
- `AGENTS.md` unchanged (quick start `npm run build` + `composer test` 471).

## Risks (see RISK-REGISTER.md 14)

`hook conflict wppo_*` low `grep wppo_` `transient_key:781` `blog_id_` collision low `WP_CLI missing` low `@when after_wp_load:69` `dry-run leak` medium `Utils\get_flag_value` first line `WppoCliDryRunTest` `multisite pagination` medium `get_sites number 100` not `-1` `progress piped CI` low `count>10 && tty` `god 3053` high deferred 2.0 `docs drift` medium `grep wppo_ → docs/hooks.md`.

## Rollback Strategy

Per-phase revert `git revert <phase-commit>` + `git checkout master -- docs/hooks.md` no DB migration, hooks `@since NEXT` new only no rename `wppo_` keep `_deprecated_hook` alias if needed per `MIGRATION-COMPATIBILITY.md:379` 12 matrices. CLI `--network` paginated `finally restore` prevents `switch_to_blog` leak.

## Acceptance Criteria (measurable)

- `wp wppo cache --help` shows `[<action>]` `[--page]` `[--yes]` `[--dry-run]` `[--network]` `[--format]`.
- `wp wppo database counts --format=table` renders `Formatter table` headers `type|count`.
- `wp wppo cache clear --dry-run --format=json | jq .would_clear` == `200` with no `Cache::clear_cache` mutation `unlink` `DELETE`.
- `wp wppo database cleanup --type=revisions --dry-run` logs `would_delete` + `WP_CLI::warning` no `clean_revisions`.
- `wp wppo settings get file_optimisation --format=yaml --fields=minifyHTML` `yaml_emit` or fallback JSON.
- `wp wppo object-cache ping --host=127.0.0.1 --format=json` `is_wp_error` → `WP_CLI::error 1`.
- `wp wppo --network cache clear --yes` sweeps 3 sites `switch_to_blog` `get_sites` `restore_current_blog` `finally`.
- `apply_filters('wppo_should_cache_request', true, '/members/')` false → `Cache::should_cache_request` skips `ob_start` `wppo_cache_hit` not fired.
- `do_action('wppo_cache_written', $file,$size,$duration)` fires `is_array` `File:Line` `class-cache.php:1741` after `atomic_put_contents:1572` success.
- `hook registration` `is_admin() false` on CLI → `has_action('admin_menu')` false, `has_action('wp_enqueue_scripts', Main:619)` true.
- `php -l 42 clean` `phpcs includes/ 0e3w` `phpunit 586 2 skipped` `npm test 345` `build webpack 5.109` green per `AGENTS.md:79` `npm run lint:js → composer lint → npm test → build`.

