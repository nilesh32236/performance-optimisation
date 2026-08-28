# FINAL-SECURITY-REVIEW — Phases 1-3 (7ce4834)

**Date:** 2026-08-28 · **Plugin:** `performance-optimisation` v1.9.0
**Branch:** `fix/audit-2026-08-28` at `7ce4834` (Phase3 PR-C) — diff base `origin/master@31fffc61` → `68a2f66a` audit gap → `d306e677` PR-A synopsis/json → `45ed2f79` PR-B --yes/--dry-run/allowlist → `7ce4834` PR-C hooks+lazy-fs
**Scope:** Every changed line across 7 production files on this branch, focused on `--yes`/`database cleanup`, `object-cache disable`, `wppo_invalidation_urls` sanitization, `wppo_should_cache_request` veto — plus all ancillary production deltas (lazy `Util::init_filesystem`, `Object_Cache::ALLOWED_KEYS` converge, `vendor/autoload.php` removal, `Database_Cleanup` per-type `do_action`, SQL `LIMIT %d` parameterization, CLI allowlist/table map guards). Research-only; no production edits.
**Method:** Full reads `includes/class-cache.php:1-2413`, `includes/class-database-cleanup.php:1-1123`, `includes/class-main.php:1-1386` (incl. `__construct:169-362`, `includes():441-479`, `setup_hooks():489-803`), `includes/class-object-cache.php:1-411`, `includes/class-rest.php:1-1450`, `includes/class-wppo-cli-command.php:1-1093`, `includes/class-util.php:1-1024` (sanitize + FS + multisite), `includes/redis-connect-helper.php`, plus `docs/hooks.md`, `docs/research/wp-cli-hooks/IMPLEMENTATION-LOG.md`, `FINAL-ADVERSARIAL-REVIEW.md`, 4 hook test suites, and `git diff origin/master -- includes/ docs/hooks.md` (632 insertions, 37 deletions, 12 files). Each claimed line cited as `file:line`.

> Related: [`HOOK-AUDIT.md`](./HOOK-AUDIT.md) (272 hits), [`FINAL-ADVERSARIAL-REVIEW.md`](./FINAL-ADVERSARIAL-REVIEW.md) (retain/modify/reject matrix), [`IMPLEMENTATION-LOG.md`](./IMPLEMENTATION-LOG.md) (Phase1-3), [`FINAL-PERF-REVIEW.md`](./FINAL-PERF-REVIEW.md).

---

## 0. Verdict

**PASS — no blocking security defects. All four focus areas correctly implement defense-in-depth; two low-severity hardening notes and one informational observation remain (see §7).**

| Focus area | Finding | Severity | Status |
|---|---|---|---|
| `--yes` / `database cleanup` | `--yes` gates `type=all` only; `--dry-run` previews before any `DELETE`/`OPTIMIZE`; non-TTY bypasses `WP_CLI::confirm` by design (shell=auth) | **LOW** info — single-type deletes unconfirmed | **PASS** with note |
| `object-cache disable` | `--yes` gate + foreign-drop-in guard + `isatty` check; no capability bypass | — | **PASS** |
| `wppo_invalidation_urls` sanitization | Triple guard: normalize + `..` reject + `cache_root`/`ABSPATH` prefix + dedupe; empty-path fallback; `get_file_path` double-check | **LOW** — no count cap | **PASS** with note |
| `wppo_should_cache_request` veto | `DONOTCACHEPAGE` wins before filter; later `is_cart`/`is_feed`/path checks still enforce even when filter returns true; `(bool)` cast | — | **PASS** |
| CLI authorization | WP-CLI relies on OS/shell access, not `manage_options`; consistent with handbook, no privilege escalation | — | **PASS** |
| SQL / filesystem | All `$wpdb` writes use `prepare`+placeholders; filesystem writes via `WP_Filesystem` + `atomic_put_contents` + transient lock | — | **PASS** |

No new unauthenticated entry point, no stored XSS, no arbitrary file write, no SQL injection, no privilege escalation introduced by this branch. Two pre-existing medium observations (single-type CLI delete without `--yes`, unbounded invalidation URL count) are carried as low-severity hardening suggestions; neither blocks merge.

---

## 1. Changed-line inventory (what this review covers)

```
git diff origin/master --stat (7 production files):

 docs/hooks.md                                    |  60 +++ (4 rows @since NEXT)
 includes/class-cache.php                         | 105 +++ (LRU, should_cache_request, invalidation_urls)
 includes/class-database-cleanup.php              |  10 ++  (per-type do_action + LIMIT %d)
 includes/class-main.php                          |  10 +- (lazy FS, vendor autoload removal, namespace fix)
 includes/class-object-cache.php                  |  50 +++ (ALLOWED_KEYS, get_redis_config filter)
 includes/class-rest.php                          |   9 ++ (per-type do_action, ALLOWED_KEYS usage)
 includes/class-wppo-cli-command.php              |   8 ++  (docblock synopsis — runtime deltas in commits 45ed2f79+d306e677)
```

Full runtime deltas including earlier commits on this branch (`git log 31fffc61..7ce4834 --stat` = 134 files, +16455/-2837) were enumerated via `git diff 31fffc61..7ce4834 -- includes/class-wppo-cli-command.php` (323 lines: `--yes`, `--dry-run`, `--format json-only`, allowlist 6→12, table allowlist). This review re-reads the **current HEAD** files in full, not just the `origin/master` delta, so the stranded-CLI-and-hook state is fully covered.

**Untracked / committed tests (out of scope for prod but reviewed for coverage):** `HookShouldCacheRequestTest.php:1-146`, `HookInvalidationUrlsTest.php:1-152`, `HookObjectCacheConfigTest.php:1-50`, `HookDatabaseCleanupPerTypeTest.php:1-42`, plus Phase1-2 `WppoCli*Test.php` (confirm/dry-run/format/help). No test introduces prod code.

---

## 2. Focus 1 — `--yes` / `database cleanup` (CLI destructive confirmation)

### 2.1 What changed

- `includes/class-wppo-cli-command.php:176-178` docblock adds `[--yes]` + `[--dry-run]` for `database` (REJECT `--confirm` alias per `FINAL-ADVERSARIAL-REVIEW.md:57`).
- `includes/class-wppo-cli-command.php:205-211` resolves `--dry-run` via `WP_CLI\Utils::get_flag_value` when available, fallback `isset($assoc_args['dry-run'])`, cast `(bool)`.
- `includes/class-wppo-cli-command.php:213-220` `optimize` early dry-run: logs `would_optimize` JSON, warns, returns before any `OPTIMIZE TABLE`.
- `includes/class-wppo-cli-command.php:221-232` `optimize` table allowlist: `$allowed_tables = array_unique(array_merge(...array_values(Database_Cleanup::TABLE_MAP)))` then `in_array($table, $allowed_tables, true)` skip + `WP_CLI::warning('Skipped unknown table')`.
- `includes/class-wppo-cli-command.php:267-280` `cleanup` dry-run: `Database_Cleanup::get_counts()` → `would_delete` payload (`all` → full map, known type → single entry, unknown → full preview), logs JSON + warns, returns before any `DELETE`.
- `includes/class-wppo-cli-command.php:282-300` `cleanup --type=all` `--yes` gate: `get_flag_value('yes', false)` fallback `isset`, then `posix_isatty(STDIN)` / `stream_isatty(STDIN)` (silenced `@` per PHPCS) → `is_tty`; only when `!$yes && $is_tty` calls `WP_CLI::confirm('Are you sure ... all types?')`. Non-TTY silently proceeds without prompt.
- `includes/class-wppo-cli-command.php:321-388` per-type `switch($type)` covers 9 canonical types plus aliases (`drafts`, `trash`, `spam`, `trashed`, `transients`, `orphans`, `unattached`, `oembed`, plus `trashed_comments`/`unattached_media`/`oembed_cache`), each calling the corresponding `Database_Cleanup::clean_*()`; `default` emits `WP_CLI::error('Invalid cleanup type')` (exit 1, no delete). Post-success emits `Log::add`, fires `do_action('wppo_database_cleanup_completed', $type, (int)$cleaned_count)` at `class-wppo-cli-command.php:378-385`, then `WP_CLI::success`.
- `includes/class-database-cleanup.php:722-750` `clean_all()` loop `foreach(CLEANUP_METHOD_MAP as $key=>$method)` now fires `do_action('wppo_database_cleanup_completed', $key, (int)$res)` per-type at `class-database-cleanup.php:729-737` when `!is_wp_error && false!==$res`, before aggregating `$total_deleted` + `$affected_tables`; after loop fires aggregate `do_action('wppo_database_cleanup_completed', 'all', $total_deleted, $results)` at `class-database-cleanup.php:747` (existing, unchanged) and calls `maybe_optimize_tables`.
- `includes/class-rest.php:900-909` single-type REST `database_cleanup` also fires `do_action('wppo_database_cleanup_completed', $type, (int)$result)` at `class-rest.php:909` mirror.
- `includes/class-database-cleanup.php:632-635` SQL `LIMIT` now parameterized as `LIMIT %d` with `array_merge($autoload_values, [(int)$limit])` instead of interpolated `(int)$limit` (`class-database-cleanup.php:630-638`).

### 2.2 Security analysis

| Check | Result | Evidence |
|---|---|---|
| **Capability / authorization** | Correct — relies on shell/OS access, not `manage_options`. WP-CLI commands annotated `@when after_wp_load` load WordPress but deliberately perform no `current_user_can` check; the invoking user is whoever has shell access to run `wp wppo ...` (typically `www-data`/`root`/deploy key). Handbook `guides/commands-cookbook` confirms plugin CLI commands use no permission callback; `class-main.php:476-478` `if(defined('WP_CLI')&&WP_CLI) add_command` is the standard pattern. No privilege escalation: anonymous HTTP cannot trigger CLI. | `class-wppo-cli-command.php:476-478`, `class-main.php:476-478` |
| **Input validation — `type` allowlist** | Allowlisted. Canonical map is `Database_Cleanup::CLEANUP_METHOD_MAP` at `class-database-cleanup.php:81-91` (9 keys) plus `get_valid_cleanup_types():109-112` (`+ 'all'`). CLI `switch` at `class-wppo-cli-command.php:323-363` accepts canonical + documented aliases; `default` errors. REST `database_cleanup:820-827` validates via `Database_Cleanup::get_valid_cleanup_types()`. No raw user input reaches `call_user_func` or `self::$method()`; dispatch goes through `switch` → explicit `Database_Cleanup::clean_*` call or `invoke_cleanup_method($method)` only for `all` where `$method` comes from `CLEANUP_METHOD_MAP`. | `class-database-cleanup.php:81-112`, `class-wppo-cli-command.php:323-363`, `class-rest.php:820-827` |
| **Input validation — `tables` allowlist** | Allowlisted. `optimize` builds `$allowed_tables` from `TABLE_MAP` values at `class-wppo-cli-command.php:223` and skips unknown with warning before `optimize_table`. Prevents `OPTIMIZE TABLE` on arbitrary identifier. | `class-wppo-cli-command.php:223-228` |
| **SQL safety** | Parameterized. All `DELETE`/`SELECT`/`COUNT` use `$wpdb->prepare` with `%d`/`%s` placeholders and `...$ids`/`...$to_delete` spread; newly fixed `get_autoloaded_options:632-635` now binds `LIMIT %d` instead of interpolating `(int)$limit`. `information_schema.TABLES` query at `class-database-cleanup.php:1001-1007` binds `%s` for `DB_NAME` and table name. Identifiers (`$wpdb->posts`, `$wpdb->options`, `$wpdb->{$table}`) are not bindable but are allowlisted via `TABLE_MAP` → `optimize_table:1050-1075` explicitly documents the identifier interpolation with allowlist justification and verified no raw user input reaches it. | `class-database-cleanup.php:138-179`, `630-638`, `1001-1075` |
| **Destructive confirmation — `--yes` gate** | Implemented per `FINAL-ADVERSARIAL-REVIEW.md:57 MODIFY` retain `--yes` ONLY for `database cleanup --type=all`. Pattern is correct: `WP_CLI\Utils::get_flag_value('yes', false)` with `isset` fallback, strict `(bool)` cast, then `isatty` probe before `WP_CLI::confirm`. REJECT `--confirm` alias is intentional (handbook has `--yes` only; Rocket alias not needed). `WP_CLI::confirm` when called respects `--yes` internally if `$assoc_args` were forwarded, but this code explicitly gates via `get_flag_value` and `isatty` check instead — equivalent, and `WppoCliConfirmTest` mocks correctly. | `class-wppo-cli-command.php:283-300` |
| **Non-TTY auto-proceed** | By design. When `!$yes && !$is_tty`, no prompt is shown and deletion proceeds. This is the standard WP-CLI pattern: non-interactive shells (CI, cron, `echo y | wp wppo ...`, Ansible, `WP_CLI::confirm` docs) should not block; the human safety net is only for interactive TTYs. The security boundary is shell access, not the `confirm` prompt. Operator docs should state `wp wppo database cleanup --type=all --yes` for automation and avoid piping `--type=all` without `--yes` in non-TTY scripts only if disallowed by policy; behavior is not a bypass. | `class-wppo-cli-command.php:291-300` |
| **Single-type deletes lack `--yes`** | **Low note, not blocking.** `cleanup --type=revisions` (or `trashed_posts`, `oembed_cache`, etc.) executes immediately without `--yes`/`confirm`. Each single-type still deletes rows (batched `delete_in_batches:138` with `SELECT ID LIMIT 1000 → DELETE meta → DELETE rows` loop at `class-database-cleanup.php:138-179`), so a mistaken `wp wppo database cleanup --type=trashed_posts` in a script will delete trashed posts without a second chance. The `FINAL-ADVERSARIAL-REVIEW.md:57` decision to gate only `all` is intentional (`all` wipes 9 types, worst blast radius, ~`COUNT(*)` up to tens of thousands); single-type is lower blast radius and the `get_counts`/`--dry-run` + `counts` subcommand is the intended preview path. No action required, but documenting the asymmetry here for operators. | `class-wppo-cli-command.php:321-388` |
| **`--dry-run` non-destructiveness** | Correct. Both `optimize` and `cleanup` dry-run branches return before any `DELETE`/`OPTIMIZE` at `class-wppo-cli-command.php:214-220` and `267-280`. `cleanup` dry-run reuses `get_counts()`, which only reads (`SELECT COUNT(*)`). No `invalidate_counts_cache` is triggered in the dry-run branch (early return before `invoke_cleanup_method` which would bump the salt). Payload is logged via `WP_CLI::log(wp_json_encode(...))` and `WP_CLI::warning('Dry run — no rows deleted.')`, matching `wp search-replace --dry-run` precedent per review. `WppoCliDryRunTest` asserts no `DELETE`/`OPTIMIZE` when flag set. | `class-wppo-cli-command.php:267-280`, `213-220` |
| **`--format` coercion** | `counts` + `system-info` coerce unknown format to `json` at `class-wppo-cli-command.php:251-253` and `1074-1076` (`if('json'!==$format) $format='json'`) after `get_flag_value('format','json')`. JSON-only per `FINAL-ADVERSARIAL-REVIEW.md:112`. No `WP_CLI\Formatter` `table|csv|yaml` or `Spyc` path remains; `database counts` removed table formatter, uses `wp_json_encode(PRETTY)` fallback when `Formatter` absent — no deserialization gadget. | `class-wppo-cli-command.php:243-255`, `1068-1091` |
| **Multisite** | Pair with `Util::transient_key()` blog-prefix at `class-util.php:254-324` used by `get_counts` salt/transient and by `Database_Cleanup::get_counts:854-865`. CLI itself has no `--network` loop (intentionally REJECT per `FINAL-ADVERSARIAL-REVIEW.md:59,113`, docs-only `for url in $(wp site list --field=url); do wp --url=$url wppo database cleanup`); shell loop avoids 30-line `get_sites`+`switch_to_blog` OOM/perf leak if misused across 10k sites. `clean_all` + `auto_clean` use `$wpdb->posts`/`$wpdb->options` etc., which are per-site via table prefix when `switch_to_blog` is handled by the outer shell loop, correct. Uninstall's `uninstall.php:193-217` remains the only multisite pagination reference. | `class-util.php:254-324`, `class-database-cleanup.php:854-935` |
| **Logging / information disclosure** | `Log::add('Database cleanup (all via WP-CLI): %d items removed')` at `class-wppo-cli-command.php:315` logs only counts, not row IDs or passwords. `would_delete` JSON in dry-run logs counts per type, not raw IDs. No `assoc_args` dump includes `password`; object-cache config strips password before `var_export` at `class-object-cache.php:328`. | `class-wppo-cli-command.php:315`, `277`, `class-object-cache.php:324-336` |

### 2.3 Verdict — Focus 1

**PASS.** Destructive DB path is correctly gated for the worst-case `all` case, previewable via `--dry-run`/`counts`, and never exposes credentials. The single-type asymmetry is a deliberate, documented trade-off; if the team later wants uniform prompting, a one-line `if('all'!==$type && !$dry_run && !$yes && $is_tty) WP_CLI::confirm(...)` could be added, but it is not a security blocker.

---

## 3. Focus 2 — `object-cache disable` (CLI destructive confirmation)

### 3.1 What changed

- `includes/class-wppo-cli-command.php:791-853` docblock adds `[--yes]` + `[--mode/--nodes/--master_name/--use_tls/--persistent/--compression]` sink (converge 6→12 keys).
- `includes/class-wppo-cli-command.php:880-935` `object_cache()` `disable` case now gates with same `--yes`/`isatty`/`WP_CLI::confirm('Are you sure you want to disable Redis Object Cache?')` pattern as database `all` at `class-wppo-cli-command.php:910-927`, then calls `$manager->disable()` at `class-wppo-cli-command.php:928`.
- `includes/class-object-cache.php:40-50` new `ALLOWED_KEYS` const (`12` keys: `mode, host, port, password, database, timeout, prefix, nodes, master_name, use_tls, persistent, compression`) as single source.
- `includes/class-object-cache.php:40-50` + `class-object-cache.php:213-233` `get_redis_config():213-233` now merges `Util::get_settings()['object_cache']` + `wppo-redis-config.php` include (guarded `if(!is_array) $config=[]` at `class-object-cache.php:219-221`) then `(array) apply_filters('wppo_object_cache_config', $config)` at `class-object-cache.php:230`.
- `includes/class-object-cache.php:247-253` `ping($config)` filters incoming config via `wppo_object_cache_config` at `class-object-cache.php:253` before `connect_internal`.
- `includes/class-object-cache.php:302-303` `enable($config)` also filters incoming config at `class-object-cache.php:303` plus existing connection-test-then-write flow.
- `includes/class-object-cache.php:98-190` `get_status()` now delegates to `get_redis_config()` at `class-object-cache.php:131`, so status/telemetry respects the filtered merged config (previously had inline duplicate logic).
- `includes/class-rest.php:1113-1158` `build_redis_config($params):1113-1163` now iterates `Object_Cache::ALLOWED_KEYS` at `class-rest.php:1114` and handles all 12 keys with typed sanitization: `host/master_name/compression/mode/prefix` → `sanitize_text_field((string)$value)` at `class-rest.php:1128-1130`, `port/database/timeout` → `(int)$value` at `class-rest.php:1134`, `password` → conditional `sanitize_text_field` with `WPPO_REDIS_PASSWORD` constant precedence at `class-rest.php:1141-1145`, `use_tls/persistent` → `(bool)$value` at `class-rest.php:1148-1149`, `nodes` → `sanitize_nodes()` at `class-rest.php:1152`.
- `includes/class-wppo-cli-command.php:962-970` `get_redis_config_from_assoc():962-970` now iterates `Object_Cache::ALLOWED_KEYS` at `class-wppo-cli-command.php:964` instead of hardcoded 6.
- `includes/class-object-cache.php:324-342` `enable` strips `password` (+ `replicas[*].password`) before `var_export` config file write and before `put_contents` at `class-object-cache.php:328-342`.
- `includes/class-object-cache.php:373-396` `disable()` at `class-object-cache.php:373-396` guards `if($status['foreign_dropin']) return WP_Error('foreign_dropin', 'A foreign drop-in exists...')` at `class-object-cache.php:375-377` before any `delete`.

### 3.2 Security analysis

| Check | Result | Evidence |
|---|---|---|
| **Destructive confirmation** | Mirrors database `all` exactly: `--yes` via `get_flag_value('yes', false)` fallback `isset`, `@posix_isatty/@stream_isatty`, only when `!$yes && $is_tty` calls `WP_CLI::confirm` at `class-wppo-cli-command.php:911-926`. Non-TTY automation proceeds without prompt (shell=auth, same rationale as §2.2). No `--confirm` alias (REJECT intentional). | `class-wppo-cli-command.php:910-927` |
| **Foreign drop-in safety** | `disable()` checks `get_status()['foreign_dropin']` before any `delete` at `class-object-cache.php:375-377`. Path `get_status:106-124` detects foreign by absence of `DROPIN_MARKER`/`LEGACY_DROPIN_MARKER` in `WP_CONTENT_DIR/object-cache.php` (<1 MB + readable guard). `enable()` similarly rejects if foreign present at `class-object-cache.php:305-307`. Prevents deleting another plugin's `object-cache.php`. | `class-object-cache.php:25-38`, `98-124`, `305-307`, `373-377` |
| **Capability / privilege** | Same CLI-auth model as §2.2: shell access controls. No `manage_options` check in CLI (by design). HTTP `object_cache` REST `handle_object_cache:1034-1096` remains gated by `permission_callback:83` (`manage_options` + `X-WP-Nonce`) — CLI adds no HTTP bypass. | `class-rest.php:138-143`, `357-362` |
| **Password handling** | Reviewed separately (§6.1). `enable()` at `class-object-cache.php:324-342` unsets `password` and `replicas[*].password` before `var_export` + `put_contents` of `wppo-redis-config.php`. The on-disk config never contains ciphertext-equivalent; password lives only in `wppo_settings` DB + merged at runtime via `WPPO_REDIS_PASSWORD` / env / `wppo_object_cache_config` filter + `redis-connect-helper.php:wppo_redis_connect`. CLI `get_redis_config_from_assoc` reads `--password` from `assoc_args` (process args, visible in `ps` briefly but same as any `wp wppo object-cache enable --password=...`; operator advised to use constant instead). Log never dumps `assoc_args`. | `class-object-cache.php:324-342`, `class-rest.php:1137-1145`, `class-wppo-cli-command.php:962-970` |
| **Input validation — allowlist converge 6→12** | Close. Previously CLI accepted only 6 keys (`host,port,password,database,timeout,prefix`) at old `class-wppo-cli-command.php:910` while REST accepted 10; Sentinel/Cluster/TLS options (`nodes`, `master_name`, `use_tls`, `persistent`, `compression`, `mode`) were silently dropped. Now both sides use `ALLOWED_KEYS` at `class-object-cache.php:50` and `class-rest.php:1114`, `class-wppo-cli-command.php:964`, `class-object-cache.php:50`. Typed sanitization at `class-rest.php:1124-1153` correctly distinguishes text/int/bool/nodes. CLI raw `assoc_args` values are not re-sanitized in `get_redis_config_from_assoc` (stores verbatim), but `enable`/`ping` immediately filter via `wppo_object_cache_config` then pass to `wppo_redis_connect` / `wppo_parse_nodes`; that helper is out of scope but `build_redis_config` sanitizes `nodes` via `sanitize_nodes:1175-1181`. No crash loop: unknown keys are declined by `ALLOWED_KEYS` iteration (implicit allowlist). | `class-object-cache.php:40-50`, `class-rest.php:1113-1158`, `class-wppo-cli-command.php:962-970` |
| **TLS / Sentinel / Cluster exposure** | Adding `use_tls`, `persistent`, `nodes`, `master_name`, `compression`, `mode` unlocks previously blocked configurations for CLI users. These are operator-intentional (the same tools now available via REST). No new attack surface beyond what REST already permitted, now uniformly available. `wppo_redis_connect` helper at `includes/redis-connect-helper.php` is the trust boundary; Phase2 audit did not surface flaws there. | `class-object-cache.php:40-50` |
| **Multisite** | `object-cache.php` drop-in is `WP_CONTENT_DIR/object-cache.php` (single per network, `WP_CONTENT_DIR` is shared). `WP_CLI --url=<site>` does not change `WP_CONTENT_DIR`, so `disable`/`enable` are inherently network-wide. No `is_multisite` guard or warning is emitted. This is pre-existing and intentional (drop-in is network singleton), but worth surfacing in `docs/hooks.md` / `--help` longdesc so site admins on multisite understand `wp --url=site2 wppo object-cache disable` disables for the whole network. Not a code bug. | `class-object-cache.php:79-81` |
| **Filesystem** | `enable` uses `Util::init_filesystem()` + `put_contents($config_path, var_export(...))` + `copy($template_path, $dropin_path, true)` at `class-object-cache.php:344-358`, with cleanup `delete($config_path)` on `copy` failure at `class-object-cache.php:356`. `disable` uses `delete($dropin_path)` + `delete($config_path)` via `WP_Filesystem` at `class-object-cache.php:379-393`. All paths are `WP_CONTENT_DIR`-anchored, not user traversable. | `class-object-cache.php:344-393` |

### 3.3 Verdict — Focus 2

**PASS.** Destructive drop-in removal now prompts on TTY exactly like DB `all`, foreign-drop-in guard prevents collateral deletion, password never hits disk, allowlist converge closes the 6→10 silent-drop without opening new vectors. Add a help-text sentence noting multisite network-wide effect if desired (docs-only).

---

## 4. Focus 3 — `wppo_invalidation_urls` sanitization (filesystem safety)

### 4.1 What changed

Full before/after at `includes/class-cache.php:1857-1974`:

**Before** (`origin/master@31fffc61`): each canonical path immediately resolved to `$this->get_file_path($path, 'html')` and `delete_cache_files`/`delete_role_variant_files`/`delete_no_cache_marker` were called inline for `page`, `home`, `posts_page`, `post_type_archive`, each taxonomy term. No filter, no sanitization beyond `get_file_path`'s own `..` → `''` guard.

**After** (`7ce4834`):

1. Collects canonical URLs into `$urls = []` then `$urls[] = $path` (primary), `Util::cached_home_url('/')` home, optional `page_for_posts`, `get_post_type_archive_link`, per-term `get_term_link`, all via `wp_make_link_relative` at `class-cache.php:1858-1909`.
2. Filters once: `$urls = (array) apply_filters('wppo_invalidation_urls', $urls, $page_id)` at `class-cache.php:1920` (new `@since NEXT` docblock merges G-03+G-27 single URL list for FS+CDN).
3. Sanitizes at `class-cache.php:1922-1935`:
   ```php
   $cache_root_norm = '' !== $this->cache_root_dir ? wp_normalize_path($this->cache_root_dir) : '';
   $abspath_norm    = defined('ABSPATH') ? wp_normalize_path(ABSPATH) : '';
   $sanitized = [];
   foreach ($urls as $u) {
       $u = is_string($u) ? $u : (string)$u;
       $u = wp_normalize_path(trim($u, '/'));
       if ('' !== $u && strpos($u, '..') !== false) continue; // reject any .. segment
       $sanitized[] = $u; // '' allowed = home
   }
   $sanitized = array_values(array_unique($sanitized)); // dedupe
   ```
4. Purges at `class-cache.php:1937-1964`:
   ```php
   $primary_normalized = wp_normalize_path(trim((string)$path, '/'));
   foreach ($sanitized as $url_path) {
       $html_file_path = $this->get_file_path($url_path, 'html'); // 1985-1994 own .. → '' guard
       if ('' === $html_file_path) continue;
       $norm = wp_normalize_path($html_file_path);
       if ('' !== $cache_root_norm && 0 !== strpos($norm, $cache_root_norm)) continue;
       if ('' !== $abspath_norm    && 0 !== strpos($norm, $abspath_norm))    continue;
       $this->delete_cache_files($html_file_path);
       $this->delete_role_variant_files(dirname($html_file_path));
       $this->delete_no_cache_marker($html_file_path);
       if ($url_path === $primary_normalized) {
           $css_file_path = $this->get_file_path($url_path, 'css');
           $used_css_path = $this->get_file_path($url_path, 'used-css');
           if ('' !== $css_file_path)  $this->delete_cache_files($css_file_path);
           if ('' !== $used_css_path)  $this->delete_cache_files($used_css_path);
       }
   }
   ```
5. `docs/hooks.md:154-168` documents the filter (`string[] $urls`, `int $post_id`, sanitized via `wp_normalize_path` + `ABSPATH`/`cache_root` guard, deduped).

Tests at `tests/php/HookInvalidationUrlsTest.php:86-151` cover: custom feed extension via filter, `../etc/passwd` rejection while `about` passes, duplicate dedupe via `array_count_values`.

### 4.2 Security analysis

| Check | Result | Evidence |
|---|---|---|
| **Traversal — `..` rejection** | Correct. First guard at `class-cache.php:1929` `if(''!==$u && strpos($u,'..')!==false) continue` rejects any path containing `..` after `wp_normalize_path(trim($u,'/'))`. Covers `/../../etc/passwd`, `a/../b`, `..`, `../`, `foo/..bar` (conservative: `a..b` false positive but safe). `get_file_path:1988` has its own `if(strpos($url_path,'..')!==false) return ''` double-check, and purge loop skips `''` at `class-cache.php:1941`. Double defense. | `class-cache.php:1926-1930`, `1985-1992` |
| **Encoded traversal** | Handled by literal interpretation. Inputs like `%2e%2e%2f`, `%2E%2E%2F`, `%2e%2e` contain no literal `..`, so they pass the substring check but `wp_normalize_path` leaves them as `%2e` literals; `get_file_path:1994` would build `$cache_root_dir/$domain/%2e%2e%2f/index.html`, a literal `%` directory, not a directory traversal; `delete_cache_files` then tries to unlink a non-existent literal path — harmless. Upstream `is_not_cacheable:1530` does `rawurldecode` before path checks, but that is for cache-ability decisions, not invalidation; no decode is performed here — consistent with no bypass. If a future caller pre-decodes, the `..` guard would still catch it, since decode first would reintroduce `..`. | `class-cache.php:1928-1929`, `1986-1994`, `1530` |
| **Absolute URL / scheme injection** | Blocked by prefix guard. If filter returns `https://evil.com/about/` or `//evil.com`, `wp_normalize_path(trim($u,'/'))` yields `https:/evil.com/about` (double slash collapsed to single by normalize regex `(?<=.)/+` at `class-util.php` mock in test). That normalized string does not begin with `https:/evil.com` as a cache path; then `get_file_path` builds `$cache_root_dir/$domain/https:/evil.com/about/index.html` — contains `:` but still under `cache_root_dir`? The subsequent guard `if(0!==strpos($norm, $cache_root_norm)) continue` at `class-cache.php:1945` would **not** block it if `cache_root_norm="/var/www/wp-content/cache/wppo"` and `$norm="/var/www/wp-content/cache/wppo/example.com/https:/evil.com/about/index.html"` — that *does* start with `cache_root_norm` (it is under the cache dir). However deletion would only remove a bogus subdirectory `https:/evil.com/about` inside the WPPO cache, not an arbitrary outside path. The `ABSPATH` guard at `class-cache.php:1948` similarly passes (cache is under `ABSPATH`). No escape outside `cache_root`/`ABSPATH`. Absolute URLs are thus contained, merely pollute the cache with weird directories that will be reaped by `delete_all_cache_files` anyway. No security breach, only minor junk. | `class-cache.php:1940-1949` |
| **Prefix guard — `cache_root` + `ABSPATH`** | Defense-in-depth. After `get_file_path`, `wp_normalize_path($html_file_path)` is checked against both `$cache_root_norm` and `$abspath_norm` at `class-cache.php:1944-1949`. Even if a filter returned `/` or empty home, or if `get_file_path` were ever to mishandle `/`, the guard ensures no deletion outside `WP_CONTENT_DIR/cache/wppo` or `ABSPATH`. `cache_root_dir` is anchored at `class-cache.php:264` `WP_CONTENT_DIR . '/cache/wppo'` (not user-controlled); `$domain` at `class-cache.php:231-265` is `sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']))` with `..` rejection already — cannot inject path separator (`sanitize_text_field` preserves `/` but `domain` is `HTTP_HOST` like `example.com`, no `/`; construction `$cache_root_dir/$domain/$url_path` inserts `/` only as separator). | `class-cache.php:231-269`, `1940-1950` |
| **Filesystem double-write / symlink** | Minimal risk. `delete_cache_files:2029-2042` calls `$fs->exists` then `$fs->delete` via `WP_Filesystem` (direct or ftpext). `WP_Filesystem_Direct`'s `delete` ultimately uses `unlink`/`rmdir`, which follows symlinks but the resolved path is still under `cache_root` due to prefix guard. Attack would need to have previously planted a symlink inside `cache/wppo/example.com/...` pointing outside — attacker would need write access to `wp-content/cache/wppo`, at which point host is already compromised. No new symlink-planting vector is introduced by this filter. Guard uses normalized string prefix, not `realpath`, so a pre-existing symlink under cache that points outside would still be traversed by `delete`. This is pre-existing with the old inline purge path too; fix would be `realpath`+prefix, but WP cache dirs are not expected to contain symlinks, and Core `delete` pattern matches. Not blocking. | `class-cache.php:2029-2042`, `1944-1949` |
| **Dedupe / DoS** | `array_unique` at `class-cache.php:1935` prevents repeated `unlink` of the same path when a filter duplicates entries, saving I/O (verified by `HookInvalidationUrlsTest:133-151` asserting 1 delete for 3× `/about/`). However the array is **uncapped**: a malicious filter returning 100k entries would cause `foreach` of 100k iterations, each doing `get_file_path` + 2× `strpos` + 3× `WP_Filesystem` ops — CPU + I/O DoS confined to `save_post` which requires `edit_post` capability (HTTP) or shell (CLI). Filter requires code execution (`add_filter`) — already privileged. Capping to e.g. `array_slice($sanitized, 0, 50)` would harden but not security-required. Logged as low info. | `class-cache.php:1935-1940` |
| **Type juggling** | Handled. `$urls = (array) apply_filters(...)` at `class-cache.php:1920` coerces non-array to array. Loop casts `is_string($u) ? $u : (string)$u` at `class-cache.php:1927` — objects with `__toString` become strings, arrays become `"Array"`, resources `""`. No `TypeError` / no object-injection via `unserialize`. Safe. | `class-cache.php:1920-1927` |
| **Empty string (home) handling** | Correct. `'' !== $u && strpos($u,'..')` at `class-cache.php:1929` explicitly allows `''` (home lives at `$cache_root_dir/$domain/index.html` per `get_file_path:1994` `'' === $url_path ? $filename : "$url_path/$filename"`). Prefix guard passes because home path is `$cache_root_dir/$domain/index.html`. | `class-cache.php:1928-1935`, `1985-1994` |
| **CSS/used-CSS scoping** | Contained. Only primary URL triggers `css`+`used-css` deletes at `class-cache.php:1954-1963` (`if($url_path === $primary_normalized)`), so a filter injecting 50 URLs cannot wipe CSS for unrelated pages. Isolated. | `class-cache.php:1954-1963` |
| **Existing path vs new path equivalence** | Strictly more restrictive than before. Old code deleted inline without filter or sanitization (trusted internal URLs only). New code adds filter then sanitizes; canonical URLs from internal sources (`get_permalink`, `cached_home_url` at `class-cache.php:1858-1883`) already pass `wp_normalize_path` + no `..`; behavior for unhooked installs is unchanged except dedupe. Verified by reading old diff block (`origin/master` inline `delete_cache_files` at former `class-cache.php:1844-1876`). | diff |

### 4.3 Verdict — Focus 3

**PASS.** Four-layer containment (normalize → `..` reject → `get_file_path` `..` guard → `cache_root`/`ABSPATH` prefix → dedupe) is correctly implemented. No directory traversal, no escape outside cache, no arbitrary file write. Recommend optionally capping `count($sanitized) ≤ 50` to bound I/O DoS via malicious filter, but not required for merge.

---

## 5. Focus 4 — `wppo_should_cache_request` veto (authorization / cache poisoning)

### 5.1 What changed

New block in `includes/class-cache.php:1496-1527`, placed **immediately after** the `DONOTCACHEPAGE` early return at `class-cache.php:1504-1508`:

```php
/**
 * Filter whether the current request should be cached.
 *
 * Placed after the DONOTCACHEPAGE check so the constant always wins
 * even if the filter returns true. Return false to skip caching.
 *
 * @since NEXT
 * @param bool   $should       Whether the request should be cached. Default true.
 * @param string $request_uri  The request URI.
 * @param bool   $is_mobile    Whether the request is from a mobile device.
 * @param bool   $is_logged_in Whether the user is logged in.
 */
$is_mobile    = function_exists('wp_is_mobile') ? wp_is_mobile() : false;
$is_logged_in = function_exists('is_user_logged_in') ? is_user_logged_in() : false;
$should_cache = (bool) apply_filters('wppo_should_cache_request', true, $this->request_uri, $is_mobile, $is_logged_in);
if (!$should_cache) {
    return true; // is_not_cacheable() → skip ob_start/storage
}
```

Context: `is_not_cacheable():1496-1565` is the single predicate queried by `maybe_store_cache():1774-1833` and `is_request_cacheable():1845-1847` and LiteSpeed header path; returning `true` means "do not cache this request" (no `ob_start`, no `save_cache_files`, plus `litespeed_control_set_nocache` when LS owns cache at `class-cache.php:1780-1784`).

The existing `is_not_cacheable` flow after the veto at `class-cache.php:1529-1565`:

```php
$parsed_path    = wp_parse_url($this->request_uri, PHP_URL_PATH);
$local_url_path = wp_normalize_path(trim(rawurldecode((string)$parsed_path), '/'));
if (strpos($local_url_path, '..') !== false) return true;
// is_feed(), sitemap regex, is_cart/is_checkout/is_account_page + regex ^/(cart|checkout|my-account)/, Woo cookies, is_404()/path_info extension
```

`docs/hooks.md:134-151` documents the filter contract. Tests at `HookShouldCacheRequestTest.php:46-145` cover false-veto, true-allow, order (`DONOTCACHEPAGE` before filter), and 4-arg reception (`/members/`, mobile, logged_in).

### 5.2 Security analysis

| Check | Result | Evidence |
|---|---|---|
| **`DONOTCACHEPAGE` precedence** | Correct. `is_not_cacheable:1504-1508` checks `defined('DONOTCACHEPAGE') && DONOTCACHEPAGE` with `maybe_mark_page_not_cacheable()` side effect before the filter. `HookShouldCacheRequestTest:98-108` verifies source ordering via `strpos('defined...' ) < strpos("apply_filters( 'wppo_should_cache_request'")`. Even a filter returning `true` cannot override the constant for WooCommerce/cart/checkout pages, matching `MAYBE_STORE_CACHE:1799-1802` symmetry. No cache poisoning of intentionally dynamic pages. | `class-cache.php:1504-1527`, `HookShouldCacheRequestTest:98-108` |
| **Filter can veto but not force** | Correct layered defense. When filter returns `false`, method returns `true` (not cacheable) at `class-cache.php:1525-1527`, short-circuiting all later checks — intended "never cache `/members/`" use case. When filter returns `true` (or any truthy due to `(bool)` cast), execution falls through to `local_url_path` parsing + `is_feed`/`is_cart`/`is_checkout`/`is_account_page` + Woo cookie path + `is_404` at `class-cache.php:1529-1565`. So a filter attempting to force caching of `/cart/` or `/checkout/` still hits those hard blocks and remains uncached. Prevents cache poisoning of private/authenticated routes by rogue filter. | `class-cache.php:1522-1565` |
| **Input sanitization — `$request_uri`** | `$this->request_uri` at `class-cache.php:268` is `sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))` in `__construct:268` plus `cache_root_dir`/`domain` guards at `class-cache.php:1497-1502`. The raw `$_SERVER['REQUEST_URI']` may contain XSS payload; `sanitize_text_field` strips tags/breaks but preserves `/`, `?`, `&`; safe for string-compare inside filter. Filter consumers who echo `$request_uri` must escape per normal WP practice — documented contract (`string $request_uri`) implies escaping via `esc_url`/`esc_html` is caller responsibility; plugin never echoes the filtered value without escaping. | `class-cache.php:268`, `1524` |
| **`$is_mobile` / `$is_logged_in` derivation** | Guards `function_exists` before call at `class-cache.php:1522-1523`; fallback `false` when WP not fully loaded (e.g., early `init` context). Cast to `bool` prevents `null` confusion. No auth leakage: `$is_logged_in` is already available via `is_user_logged_in()` — exposing it to filter is not a leak, it is contextual input for the veto decision. | `class-cache.php:1522-1523` |
| **Veto semantics → `ob_start` / storage** | Returning `false` from filter → `is_not_cacheable=true` → `maybe_store_cache` returns `false` → `save_cache_files` at `class-cache.php:1674` early-returns for `html` before any `put_contents`; `process_buffer_for_cache` still processes transforms (image, Google Fonts, minify, used-CSS, CDN) at `class-cache.php:1150-1431` but does not stash. So veto affects storage only, not transforms — correct: page still optimizes for the user, just not cached. No stale private content stored. | `class-cache.php:1674`, `1774-1833` |
| **Capability / authorization** | Filter is `apply_filters` (code-level hook). Any `add_filter('wppo_should_cache_request', ...)` requires code execution (plugin/theme, `must-use`, `functions.php`). No privilege escalation: unprivileged visitor cannot inject without code deploy. Multisite `switch_to_blog` does not affect predicate (uses current request context only). | `class-cache.php:1524` |
| **Performance / DoS** | Single `apply_filters` per request at `class-cache.php:1524`, before `ob_start` — negligible (~0.005 ms with no listener). No loop. FILTER cannot cause amplification. Correct design: one veto point replaces multiple per-tag predicates (rejected `wppo_cdn_url`/`wppo_should_lazy_load_image`). | `class-cache.php:1524`, `FINAL-ADVERSARIAL-REVIEW.md:57` |
| **WP-CLI vs HTTP context** | CLI bootstrap via `Main::__construct` runs in `WP_CLI` context (`defined('WP_CLI')&&WP_CLI` at `class-main.php:347`), but `is_not_cacheable` is not invoked for CLI (no `WP_Query`/`template_redirect`). No filter overhead in CLI. | `class-main.php:347`, `class-cache.php:1496` |

### 5.3 Verdict — Focus 4

**PASS.** Veto design is correct: `DONOTCACHEPAGE` outranks filter, filter can deny but cannot poison protected routes, inputs are sanitized, storage is gated, no unauthenticated trigger.

---

## 6. Additional changed-line security audit

### 6.1 Password / credential handling (`wppo_object_cache_config`)

`handle_object_cache` REST: `build_redis_config:1137-1145` drops request-supplied `password` when `defined('WPPO_REDIS_PASSWORD') && !apply_filters('wppo_redis_allow_request_password', false)` — constant wins unless the escape-hatch filter returns `true`. This prevents HTTP-borne password override when the operator pins the credential in `wp-config.php`. `update_settings:480-485` and `import_settings:758-765` similarly never store `password` as plaintext; they unset it and set `password_set` flag. `update_settings` additionally preserves `pagespeed_api_key` only if request omits it (`class-rest.php:491-493`), avoiding API key wipe then leak. CLI or filter consumers respect the same constant precedence via `get_redis_config` → `apply_filters` chain.

**Residual note (info):** `get_redis_config():230` returns `(array) apply_filters('wppo_object_cache_config', $config)` with **no post-filter sanitization**. A malicious `wppo_object_cache_config` filter could inject arbitrary keys (`replicas`, `scheme=tls://host;`) or overwrite `host`/`port`. This is code-level auth (already privileged) — not an unauthenticated input — and matches WordPress convention for `apply_filters` on config arrays. If desired, a one-line `array_intersect_key($config, array_flip(Object_Cache::ALLOWED_KEYS))` after the filter would harden, but omitting it is not a vulnerability.

### 6.2 Filesystem — lazy `Util::init_filesystem`

`includes/class-main.php:346-354` now conditions `Util::init_filesystem()` on `(is_admin() || (WP_CLI && defined('WP_CLI')))` at `class-main.php:347`. Frontend leaves `$this->filesystem = null`, saving 0.3-0.8 ms per `FINAL-PERF-REVIEW`. Every filesystem-consuming path checks `$this->get_filesystem()` before use (`class-cache.php:1458`, `1642`, `1686`, `1761`, `2051`, `2093`, etc.) and returns early when `null`. No frontend code path attempts `put_contents`/`delete` without FS, so no silent data loss. Admin and CLI retain full FS. `includes()` at `class-main.php:441-449` removed the redundant `require_once WPPO_PLUGIN_PATH . 'vendor/autoload.php'` (already via `performance-optimisation.php:41`), no autoload break.

### 6.3 Filesystem guards — `get_file_path` + `clear_cache`

`class-cache.php:1985-1995` `get_file_path` rejects `..` → `''`; `clear_cache:2097-2118` at `class-cache.php:2097-2118` normalizes `wp_normalize_path($url_path)` then rejects `..`, verifies `get_file_path(...)` triples are non-empty before `delete_cache_files` + `delete_role_variant_files` + `delete_no_cache_marker`. Already present and unchanged — re-verified with this branch's added prefix guards in `invalidate_dynamic_static_html`.

### 6.4 REST permission / nonce

Every REST route in `class-rest.php:78-260` uses `'permission_callback' => [$this,'permission_callback']` except `rum_collect:224` with `__return_true` (public beacon, intentional, compensating controls documented at `class-rest.php:215-228`: daily rolling token `wp_hash('wppo_rum_' . Ymd . '|' . path)` + `hash_equals`, 120/hour IP rate limit via `Util::transient_key('wppo_rum_ratelimit_' . md5(IP))`, bounded storage 14d×200/day). `permission_callback:357-362` checks `sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WP_NONCE']))` + `wp_verify_nonce('wp_rest')` + `current_user_can('manage_options')`. `ajax_get_nonce:1189-1203` checks `is_user_logged_in` + `manage_options` + `check_ajax_referer('wppo_nonce_refresh')`. No capability regression.

### 6.5 SQL — batched deletes

`delete_in_batches:138-180`, `clean_revisions_advanced:209-326`, `clean_expired_transients:408-490`, `clean_orphan_postmeta:500-545`, `clean_oembed_cache:663-706`, `get_counts:852-935`, `get_autoloaded_options:624-656`, `optimize_table:1050-1098` all use `$wpdb->prepare` with proper placeholders; dynamic `IN (...)` lists are `implode(',', array_fill(0, count($ids), '%d'/'%s'))` then `...$ids` spread. `LIMIT` now ` %d` per §2.1. No concatenated user input. `TABLE_MAP` allowlist prevents identifier injection at `optimize_table:1053-1075`.

### 6.6 Escaping (XSS)

No new `echo`/`print` of unsanitized input in this delta. `class-cache.php:1680` `apply_filters('wppo_cache_page_html', $buffer, $current_url)` returns HTML for caching, not immediate output — consumers must treat as HTML; not escapped by design (filter contract). `class-rest.php:442-448` `esc_url`/`esc_html` on log strings. No stored XSS introduced.

### 6.7 Hooks — `wppo_database_cleanup_completed` per-type

New `do_action` at `class-database-cleanup.php:729-738` and `class-rest.php:909` spans three trigger sites (`clean_all` loop, REST single-type, CLI single-type at `class-wppo-cli-command.php:378-385`). All three fire only after a successful `clean_*`/`invoke_cleanup_method` with `(int)` cast on count. No `$_POST`/`$_GET` leaks into `$type` (allowlisted). No exfiltration: payload is type + count, plus optional aggregate `$results` for `all`.

---

## 7. Risk register (security only)

| # | Title | File:Line | Severity | Likelihood | Notes / Recommendation |
|---|---|---|---|---|---|
| S-01 | Single-type `database cleanup` without `--yes` | `class-wppo-cli-command.php:321-363` | **Low** | Med — script typo in CI pipeline deletes `trashed_posts`/`spam_comments` without second chance | Keep as-is per `FINAL-ADVERSARIAL` unless policy demands uniform prompt; if harden, wrap single-type with `if(!$dry_run && !$yes && $is_tty) WP_CLI::confirm` — one-line. Preview remains via `counts`/`--dry-run`. |
| S-02 | Unbounded `wppo_invalidation_urls` loop | `class-cache.php:1935-1940` | **Low** | Low — requires code-level `add_filter` (privileged) | Cap after dedupe: `$sanitized = array_slice($sanitized, 0, 50)` or `ini_get('max_execution_time')` budget; not blocking. |
| S-03 | `wppo_object_cache_config` post-filter unsanitized | `class-object-cache.php:230`, `253`, `303` | **Info** | Low — requires code-level filter | Optionally `array_intersect_key($config, array_flip(self::ALLOWED_KEYS))` after filter + re-apply `sanitize_text_field`/`(int)` per key; omits `replicas` maybe intentional, verify with helper. |
| S-04 | Multisite `object-cache disable` is network-wide | `class-object-cache.php:79-81` | **Info** | Med — admin on site2 may not realize global effect | Docs-only: add `Note: Multisite — drop-in at WP_CONTENT_DIR/object-cache.php is network singleton` to `docs/hooks.md` + CLI longdesc. |
| S-05 | CLI shell-auth model (no `manage_options` on CLI) | `class-wppo-cli-command.php:*`, `class-main.php:476-478` | **Info** | — | Confirm operator scoping: restrict shell to `www-data` deploy role, audit `~/.ssh/authorized_keys`, rotate `GH_PAT`/`SVN_*` (see AGENTS Required Secrets). Not a code defect. |

No `Critical` or `Medium` security risks remain.

---

## 8. Verification performed (research-only)

- `php -l includes/class-cache.php includes/class-database-cleanup.php includes/class-main.php includes/class-object-cache.php includes/class-rest.php includes/class-wppo-cli-command.php includes/class-util.php` — syntax OK (per `IMPLEMENTATION-LOG.md` Phase3).
- `vendor/bin/phpcs --standard=phpcs.xml includes/class-cache.php` `0 errors (5 auto-fixed)`, sibling files `0` (Phase3 log).
- `vendor/bin/phpunit --filter Hook` `12/12 OK` (`HookShouldCache 4`, `HookInvalidation 3`, `HookDatabase 2`, `HookObjectCache 3`); `vendor/bin/phpunit` `513/513 OK (2 skipped, +12 Phase3)` (`IMPLEMENTATION-LOG.md: Phase3 PR-C`).
- Hook test assertions exercised the sanitization contract:
  - `HookInvalidationUrlsTest:86-151` — `apply_filters` injects `/custom-feed/` → `assertStringContainsString('custom-feed', $deleted)`; injects `../etc/passwd` + `/about/` → `assertStringNotContainsString('etc/passwd')` + contains `about`; injects 3× `/about/` → `assertSame(1, $counts[about_html])`.
  - `HookShouldCacheRequestTest:46-145` — `false` → `assertTrue(is_not_cacheable)`, `true` → `assertFalse`, order `strpos(DONOTCACHEPAGE) < strpos(wppo_should_cache_request)`, 4-arg alias check `assertSame('/members/', $uri)` + `assertTrue($is_mobile)` + `assertTrue($is_logged)`.
- Adversarial checklist: `--dry-run` before vs after `DELETE`, `get_flag_value` first line, WPPO_REDIS_PASSWORD precedence, `ABSPATH`/`cache_root` prefix, foreign-drop-in guard — all verified via source reads above.

---

## 9. Recommendations (non-blocking)

1. **Keep `--yes` scope as shipped.** Adding single-type confirmation would churn CI (`wp wppo database cleanup --type=trashed_posts` in nightly cron would start prompting unless `--yes` appended). If compliance requires, gate with `isatty` check as for `all` (no prompt in non-TTY automation).
2. **Optionally cap invalidation URLs** at `class-cache.php:1935` to `50` (or `apply_filters('wppo_invalidation_url_limit', 50)`) to bound I/O from a rogue filter; keep `array_unique` before cap.
3. **Optionally re-allowlist after `wppo_object_cache_config`** in `get_redis_config` (`array_intersect_key` + per-key `sanitize_*`) if category C filters are expected from untrusted must-use plugins.
4. **Docs-only:** Annotate `docs/hooks.md:154-168` multisite note and CLI synopsis longdesc for `object-cache disable` network effect.

---

## 10. References

- Changed-line sources: `includes/class-cache.php:1496-1527,1920-1964,1985-1995`, `includes/class-database-cleanup.php:50,81-91,632-635,722-750`, `includes/class-main.php:346-354,441-449`, `includes/class-object-cache.php:40-50,130-131,213-233,247-253,302-308,324-342,373-377`, `includes/class-rest.php:1113-1158,909`, `includes/class-wppo-cli-command.php:176-178,205-280,282-300,321-388,910-935,962-970`, `includes/class-util.php:254-324,986-1022` (sanitize contract).
- Security controls: `class-rest.php:357-362` `permission_callback`, `class-rest.php:215-228` `rum_collect` compensating controls, `class-cache.php:231-269` HTTP_HOST/cache_root domain anchoring, `class-cache.php:2029-2042` WP_Filesystem deletes, `class-database-cleanup.php:1050-1075` allowlist docblock.
- Decisions: `docs/research/wp-cli-hooks/FINAL-ADVERSARIAL-REVIEW.md:57-58,112-120` (retain `--yes` for `all` + `disable`, retain `--dry-run` DB only, reject `--confirm` alias + `cache clear` prompt, converge `ALLOWED_KEYS`, keep `amount=120 lines`).
- Tests: `tests/php/Hook*Test.php`, `tests/php/WppoCli*Test.php`, `.agents/AGENTS.md` workspace rules, `AGENTS.md: portal` vendor/CI notes.
