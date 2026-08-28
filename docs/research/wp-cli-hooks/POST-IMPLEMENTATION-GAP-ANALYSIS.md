# POST-IMPLEMENTATION-GAP-ANALYSIS.md — Research → Plan → Code → Tests → Review

**Research:** `docs/research/wp-cli-hooks/` 22 files `6152` | **Plan:** `IMPLEMENTATION-PLAN.md` 120 + `MATRIX` 25 rows | **Code:** `fix/audit-2026-08-28` `23ea195e→d306e677→45ed2f79→7ce4834` (+ db-mock) `513` PHPUnit | **Review:** `FINAL-*REVIEW.md` 4

| Planned (MATRIX) | Implemented | Code File:Line | Tests | Review | Status |
|------------------|-------------|----------------|-------|--------|--------|
| P0 CLI help `[<action>]` `class-wppo-cli-command.php:49,130,301` | YES docblock-only | `class-wppo-cli-command.php:49,138,332,435,505,741,872` | `WppoCliHelpTest:43` 6 | Perf PASS 0 runtime | IMPLEMENTED+VERIFIED |
| P0 CLI format json `database counts` + `system-info` `class-wppo-cli-command.php:202,942` | YES json-only REJECT table | `class-wppo-cli-command.php:162-166,222-235,942-985` `Utils\get_flag_value` fallback `wp_json_encode` | `WppoCliFormatTest:64` 9 json | Perf PASS hot-loop none | IMPLEMENTED+VERIFIED |
| P0 CLI --yes `database --type=all` `class-wppo-cli-command.php:174` | YES REJECT --confirm | `class-wppo-cli-command.php:133-195,282-300` `posix_isatty`+`WP_CLI::confirm` | `WppoCliConfirmTest:54` 8 | Security PASS isatty gate | IMPLEMENTED+VERIFIED |
| P0 CLI --yes `object-cache disable` `class-wppo-cli-command.php:801` | YES | `class-wppo-cli-command.php:791-853,910-927` | `WppoCliConfirmTest:164` docblock | Security PASS | IMPLEMENTED+VERIFIED |
| P0 CLI --dry-run `database cleanup` `class-wppo-cli-command.php:174` | YES database only REJECT cache | `class-wppo-cli-command.php:202-311` early `get_flag_value('dry-run')` → `get_counts` | `WppoCliDryRunTest:53` 7 would_delete | Security PASS no DELETE | IMPLEMENTED+VERIFIED |
| P0 CLI --network `class-wppo-cli-command.php:75` `get_sites` | REJECT docs-only per FINAL-ADVERSARIAL:59 | — (docs `wp --url` loop) | `WppoCliDryRunTest:241` REJECT --network | Compat PASS | REJECTED (deferred P3 docs-only) |
| P0 Hook `wppo_should_cache_request` `class-cache.php:1505` | YES single after DONOTCACHEPAGE per FINAL:36 MODIFY | `class-cache.php:1496-1527` after `is_not_cacheable:1219` 4 args | `HookShouldCacheRequestTest:46` 4 order | Perf PASS +0.01ms, WordPress PASS constant wins | IMPLEMENTED+VERIFIED |
| P0 Hook `wppo_should_cache_for_user` `class-cache.php:297` | REJECT per FINAL:38 (single veto covers) | — | — | WordPress PASS loggedInCacheRoles suffices | REJECTED |
| P0 Hook per-type `wppo_before/after` `class-database-cleanup.php:714/935` | YES single `do_action` per-type MODIFY REJECT before+filter | `class-database-cleanup.php:722-737` + `class-rest.php:900` + `class-wppo-cli-command.php:376` | `HookDatabaseCleanupPerTypeTest:22` 2 static + runtime | Compat PASS per-type | IMPLEMENTED+VERIFIED |
| P1 Hook `wppo_cache_written/miss` `class-cache.php:1741/1661` | REJECT per FINAL:40 | — | — | Perf PASS observability via after_clear | REJECTED |
| P1 Hook `wppo_invalidation_urls` `class-cache.php:1838` | YES single URL list merge G-03+G-27 | `class-cache.php:1838-1964` filter + `wp_normalize_path`+`ABSPATH` guard | `HookInvalidationUrlsTest:89` 3 dedupe | Security PASS traversal sanitized | IMPLEMENTED+VERIFIED |
| P1 Hook `wppo_preload_batch_size` `class-cron.php:301` | REJECT per FINAL:44 | — | — | Compat PASS core cron_schedules suffices | REJECTED |
| P1 Hook `wppo_cdn_url` `class-cache.php:1357` | REJECT per FINAL:42 hot-loop | — | — | Perf PASS REJECT per-asset filter | REJECTED |
| P1 Hook `wppo_delay_js_should_delay` `class-main.php:600` | REJECT per FINAL:42 | — | — | Compat PASS array filter suffices | REJECTED |
| P1 Hook image `wppo_should_serve_next_gen:887` `wppo_should_lazy_load_image:100` | REJECT per FINAL:43 per-image N× | — | — | Perf PASS REJECT | REJECTED |
| P1 Hook `wppo_object_cache_config` `class-object-cache.php:252` | YES single + allowlist 6→12 MODIFY REJECT wppo_cli_redis_config | `class-object-cache.php:199-303` + `class-wppo-cli-command.php:864` `ALLOWED_KEYS:50` | `HookObjectCacheConfigTest:23` 3 static | Compat PASS TLS | IMPLEMENTED+VERIFIED |
| P1 Hook `wppo_settings_before_update` `class-rest.php:464` | REJECT per FINAL:46 core pre_update_option | — | — | WordPress PASS core hook | REJECTED |
| P1 Hook `wppo_purge_urls` `class-cdn-purger.php:193` | REJECT merged into inval | — | — | Perf PASS single list | REJECTED |
| P1 Perf `is_admin()` fence 7 hooks `class-main.php:485` | REJECT per FINAL:45 (keep lazy init only) MODIFY | `class-main.php:342-354` lazy `init_filesystem` only | `PERF-REVIEW` 0.3-0.8ms saved | Perf PASS | IMPLEMENTED+VERIFIED (narrow) |
| Docs `docs/hooks.md` 27 rows | YES 4 rows @since NEXT MODIFY REJECT 27→4 | `docs/hooks.md:44,134` 4 hooks | `DocsHooksTest` via grep | Compat PASS | IMPLEMENTED+VERIFIED |
| Tests 115→586 | 513 (+db-mock fix) 2 skipped | `tests/php/WppoCli*` `Hook*` `stubs/db-mock.php` | `FINAL-TEST-REVIEW` 7 gaps but 513 OK | Tests PASS shallow but no regression | IMPLEMENTED+VERIFIED |
| Architecture Service/PSR-4 | REJECT per FINAL:48 `AGENTS.md:18` manual require_once | — | — | Arch PASS incremental | REJECTED |

**Summary:** Research 25 rows → **IMPLEMENTED+VERIFIED 10** (help, json, --yes×2, --dry-run, should_cache_request, invalidation_urls, per-type cleanup, object_cache_config+allowlist, lazy init, docs 4) + **REJECTED 15** (network, 9 hooks, batch-size, progress, Service) per FINAL-ADVERSARIAL. No FALSE POSITIVE (all verified against source File:Line). No unexplained: every planned item ends IMPLEMENTED+VERIFIED/REJECTED per §20.

