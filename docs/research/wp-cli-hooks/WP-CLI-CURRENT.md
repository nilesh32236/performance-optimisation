# WP-CLI Current State — Research (Read-Only)

**Date:** 2026-08-28 · **Plugin:** `performance-optimisation` v1.9.0 (`performance-optimisation.php:8`) · **Class:** `PerformanceOptimise\Inc\WPPO_CLI_Command` (`includes/class-wppo-cli-command.php:1-973`) · **Registrar:** `includes/class-main.php:472-474` · **Bootstrap:** `performance-optimisation.php:41-44`

> Evidence-based, no production edits. Every factual claim cites `file:line`. Line numbers refer to the 973-line file unless noted.

---

## 1. Registration & Bootstrap

| Aspect | Evidence |
|--------|----------|
| **Namespace / parent** | `namespace PerformanceOptimise\Inc;` + `use WP_CLI; use WP_CLI_Command;` + `class WPPO_CLI_Command extends WP_CLI_Command` at `class-wppo-cli-command.php:12-42` |
| **`@since`** | File header `@since 1.9.0` at `class-wppo-cli-command.php:9`; each public command doc carries `@since` inherited from class |
| **Registration point** | `Main::includes()` at `class-main.php:436-475`; guarded: `if ( defined( 'WP_CLI' ) && WP_CLI ) { \WP_CLI::add_command( 'wppo', 'PerformanceOptimise\Inc\WPPO_CLI_Command' ); }` at `class-main.php:472-474` |
| **Bootstrap order** | `performance-optimisation.php:41` `require vendor/autoload.php` → `new Main()` at `:44` → `Main::__construct():342` calls `includes()` — CLI registration happens **before** `setup_hooks()` so commands are available at `after_wp_load` |
| **Vendor autoload note** | No PSR-4 for plugin classes; CLI class manually included via `Main::includes()` path (see `AGENTS.md:18-19`) |
| **Guard / ABSPATH** | `if ( ! defined( 'ABSPATH' ) ) { exit; }` at `class-wppo-cli-command.php:17-19` and `performance-optimisation.php:19-21` |
| **No templates involvement** | `templates/object-cache.php` is the Redis drop-in template, not CLI-related; CLI does not load templates |

### 1.1 `@when` Policy

Every public subcommand method is annotated `@when after_wp_load` at:

- `cache` → `class-wppo-cli-command.php:69`
- `database` → `class-wppo-cli-command.php:168`
- `image` → `class-wppo-cli-command.php:315`
- `settings` (both docblocks) → `class-wppo-cli-command.php:442` / `567`
- `object_cache` → `class-wppo-cli-command.php:795`
- `pagespeed` → `class-wppo-cli-command.php:896`
- `system_info` → `class-wppo-cli-command.php:954`

Meaning: WP core, active plugins, `wppo_settings` option, and `WP_Filesystem` are fully bootstrapped before any subcommand runs. No `--url` multisite site-switching is implemented; commands operate on the **current blog** selected by WP-CLI's `--url` / `get_current_blog_id()`.

### 1.2 Top-Level Help Skeleton (from class docblock)

```php
/**
 * Manages Performance Optimisation features via WP-CLI.
 * @since 1.9.0
 * EXAMPLES:
 *   wp wppo cache clear
 *   wp wppo database cleanup --type=revisions
 *   wp wppo settings get file_optimisation
 *   wp wppo object-cache flush
 */
```
`class-wppo-cli-command.php:24-41`. WP-CLI auto-generates `wp wppo --help` → lists subcommands defined via `@subcommand` tags (see §3).

### 1.3 Command Tree as WP-CLI Sees It

```
wp wppo cache        → WPPO_CLI_Command::cache()        @subcommand cache        :75
wp wppo database     → WPPO_CLI_Command::database()     @subcommand database     :174
wp wppo image        → WPPO_CLI_Command::image()        @subcommand image        :321
wp wppo settings     → WPPO_CLI_Command::settings()     @subcommand settings     :573
wp wppo object-cache → WPPO_CLI_Command::object_cache() @subcommand object-cache :801  (dash vs underscore: method object_cache, CLI object-cache)
wp wppo pagespeed    → WPPO_CLI_Command::pagespeed()    @subcommand pagespeed    :902
wp wppo system-info  → WPPO_CLI_Command::system_info()  @subcommand system-info  :956
```
Plus two `private static` helpers:

- `get_default_settings(): array` at `class-wppo-cli-command.php:451-522`
- `get_redis_config_from_assoc(array $assoc_args): array` at `class-wppo-cli-command.php:864-872`

Total public subcommand count: **7** (prompt lists 6 but the file contains 7 including `system-info`).

---

## 2. Cross-Cutting Behaviour

### 2.1 Permissions — Who Can Run

- **No capability check in CLI file.** All methods assume the OS user invoking `wp ...` is trusted (standard WP-CLI model). The file contains **zero** `current_user_can` checks; those are REST-only (`class-rest.php:357-361`).
- The `undefined`/`WP_CLI` guard at `class-main.php:472` is the only gate; once loaded, any shell user with WP-CLI access can invoke destructive operations (`database cleanup`, `object-cache disable`, `settings import`).
- Permissions comment in task spec: "who can run" → answer: **any system user able to execute `wp` against this install**; no role escalation check exists.

### 2.2 Exit Codes & Error Handling

| Pattern | Evidence | Behaviour |
|---------|----------|-----------|
| `WP_CLI::error($msg)` on invalid action/type | `cache:95`, `database:208,275,280,286`, `image:390`, `settings:623,632,648,681,709,715,722,731,749`, `object_cache:854`, `pagespeed:931`, `system_info:962` | WP-CLI prints to STDERR and **exits with status 1** (fatal). No value returned after. |
| `WP_CLI::error` on filesystem/JSON/config failures | `settings:606,611,625,632,639`, `image:390` | Same fatal exit. |
| `WP_CLI::warning($msg)` for skipped/partial | `database:184,220`, `settings:731`, `pagespeed:923` | Prints yellow warning to STDERR, **continues**, exit 0. Used for unknown table skip, per-type `WP_Error` in `clean_all`, missing pagespeed results, unrecognized tab. |
| `WP_CLI::success($msg)` on success | `cache:81,109,120`, `database:196,231,293`, `image:365`, `settings:615,673,744`, `object_cache:817,827,837,845`, `pagespeed:916`, etc. | Prints green success to STDOUT, exit 0. |
| `WP_CLI::log($msg)` / JSON output | `cache:87-89,190,202`, `image:385`, `settings:617,694,699,702`, `object_cache:808`, `pagespeed:926`, `system_info:966,970` | Plain STDOUT log; JSON via `wp_json_encode(..., JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)` |
| No explicit `exit` / `die` | All methods `return;` after `WP_CLI::*` | WP-CLI handles exit; methods are `void` (`: void` at each signature) |
| No `WP_CLI::confirm()` | absent | Destructive ops (`clear_cache`, `clean_all`, `disable`, `import`) run without confirmation. |

### 2.3 Output Formatting

| Facet | Detail | Evidence |
|-------|--------|----------|
| **Formats** | No `--format=<table|json|yaml|csv>` table rendering except JSON and YAML branch in `settings get`. | `settings:690-703` checks `--format` default `json` with options `json,yaml`; YAML uses `Spyc::YAMLDump` or `yaml_emit`, fallback to JSON with warning |
| **Tables** | Never used `WP_CLI\Utils\format_items()` or `WP_CLI::table()` | Grep finds none; `database counts` and `image status` use `wp_json_encode` instead of `table` |
| **Progress bar** | No `WP_CLI::progress()` / `make_progress_bar()` | absent — batch counts only, no bar |
| **Dry-run** | Not implemented on any subcommand | No `--dry-run` param in any docblock |
| **Verbosity** | Silent on success except `success` string; per-item `log(" - ...")` for optimize/cleanup | `database:190,225,184` |
| **Colours** | Default WP-CLI formatting only (via success/warning) | — |

### 2.4 Multisite Handling

| Area | Status | Evidence |
|------|--------|----------|
| CLI itself | **No multisite awareness** — no `get_current_blog_id()` prefixing, no `switch_to_blog()`, no `--url` handling beyond what WP-CLI core provides | `class-wppo-cli-command.php` never calls `is_multisite`, `get_current_blog_id`, or `Util::transient_key` (whereas `Util::get_settings():145-157` **is** blog-keyed, and `Cache::clear_cache()` / `Database_Cleanup` are inherently site-scoped via `$wpdb->posts`). Behaviour depends on WP-CLI's `--url` resolving the blog before `after_wp_load`. |
| Dependencies are multisite-safe | `Util::get_settings()` caches per-blog at `class-util.php:91-157`; `Cache` uses domain+path dirs (multisite-safe per `AGENTS.md:159`); `Object_Cache` template uses `get_current_blog_id()` for prefix; transients elsewhere use `Util::transient_key()` | Indirect — CLI benefits because it delegates to site-safe helpers |
| Known gap | `Cron::get_sitemap_urls` off-site filtering, `Util::transient_key` isolation verified in `Database_Cleanup`, but CLI's `trigger_preload` shares a transient lock `wppo_preload_cron_lock` which **is** blog-prefixed via `Util::transient_key` at `class-cron.php:288` — but the CLI file never mentions it | No bug in CLI, just no explicit doc |

### 2.5 Performance & Memory

| Concern | Finding | Evidence |
|---------|---------|----------|
| **Settings reuse** | Each `database`/`image`/`settings` call reads via `Util::get_settings()` (memoized after first call per request, per-blog) — no repeated `get_option` deserialization if multiple CLI calls in same process were chained, but each `wp wppo ...` is a fresh PHP process so benefit limited. | `image:326`, `settings:577`, `pagespeed:904`, `object_cache:803-808` |
| **`new Img_Converter` per call** | `image:327` instantiates `new Img_Converter($options)` on every `convert` invocation | `class-wppo-cli-command.php:327` |
| **`new Object_Cache()` per call** | `object_cache:803` instantiates manager per call | `:803` |
| **FS I/O** | `cache status` calls `Cache::get_cache_stats()` → walks `wp-content/cache/wppo/` via `WP_Filesystem::dirlist` + `size()` per file (up to 5000 files × 2 encodings) — can be 200-800 ms on large sites (AUDIT flag P-WP-03) | `class-wppo-cli-command.php:86` + `class-cache.php:2184-` |
| **DB queries** | `database counts` fires 9 `COUNT(*)` queries (`class-database-cleanup.php:892-915`); `database optimize` fires `SELECT data_length` + `OPTIMIZE TABLE` per table; `database cleanup` uses batched `SELECT ID LIMIT 1000` → `DELETE` loops | `class-database-cleanup.php:138-180,1040-1065` |
| **Network** | `pagespeed scan` only enqueues (`as_enqueue_async_action`), no HTTP GET; `cache preload` schedules cron events (`wppo_generate_static_page/url`) with 0-1800s jitter, actual `wp_remote_get` happens in `Cron::process_page/url` workers | `class-wppo-cli-command.php:908`, `class-cron.php:337,372,461` |
| **Memory** | No `ini_set('memory_limit')` or `WP_CLI::get_config('memory')` handling; image convert decodes bitmap per file (GD/Imagick) — largest consumer. `Max 50 images/batch` default caps memory but no `--batch-size` CLI flag to tune. | `image:330`, `class-img-converter.php:320-` |
| **Progress feedback** | None — no `WP_CLI::progress_bar` for 500-item cron discovery or image batch | absent |

### 2.6 Validation Summary Table

| Subcommand | Action validation | Type/table validation | Assoc arg validation | JSON validation | Path safety |
|------------|-------------------|-----------------------|----------------------|-----------------|-------------|
| `cache` | `clear`,`preload`,`status` only; else `WP_CLI::error` at `:93-96` | n/a | `page` may be null; no type check | n/a | `wp_parse_url(path PHP_URL_PATH)` + `trim(.../ )` + `wp_normalize_path` at `:102`; no `realpath` prefix check (unlike REST) — path traversal `..` becomes normalized string passed to `Cache::clear_cache` which then rejects via its own `..` check |
| `database` | `cleanup`,`optimize`,`counts` only else error `:206-209` | `type` allowlist at `:143-145` (revisions etc.) + `TABLE_MAP` `allowlist` `:180` for optimize | `tables` default `posts,postmeta,comments,commentmeta,options` `:178` | n/a | n/a |
| `image` | `convert`,`status` only else error `:390` | `format` allowlist `webp,avif,both` `:335-337`; empty → settings `conversionFormat` | `--format` string, no sanitization beyond allowlist | n/a | `realpath` + `ABSPATH` prefix at `:353-355` |
| `settings` | `get,update,export,import` else error `:749` | tab vs `ALLOWED_SETTINGS_KEYS` / `ALLOWED_SETTINGS_TABS` at `644,728` | `file` existence via `WP_Filesystem->exists` `:631`, `settings` json string required `:713-714` | `json_decode` + `is_array` checks `:637-639,719-722` | `Util::init_filesystem()` gate at `603-607,629-632` |
| `object-cache` | `status,ping,enable,disable,flush` else error `:854` | n/a | `host,port,password,database,timeout,prefix` at `866-870` | n/a | n/a |
| `pagespeed` | `scan,results` else error `:931` | `strategy` default `mobile`, not validated except downstream | `url` default `Util::cached_home_url()` `:904` | n/a | URL via `esc_url_raw`/`wp_http_validate_url` inside `Pagespeed::queue_scan` |
| `system-info` | group filter `php,database,wordpress,wp_constants,server,cache,infrastructure` + `litespeed,opcache` via `System_Info::get_all():65-77` | `isset($all[$group])` check `:961` | positional `group` optional `:958` | n/a | n/a |

---

## 3. Subcommand Deep Dives

### 3.1 `wp wppo cache {clear|preload|status}`

**Purpose:** Manage static HTML cache (filesystem + gzip/br variants) and preload queue.

**Source / Method:** `class-wppo-cli-command.php:43-124` · `public function cache( array $args, array $assoc_args ): void` at `:75` · `@subcommand cache` at `:70` · `@when after_wp_load` at `:69`

**CLI Synopsis (from docblock):**

```
wp wppo cache <action> [--page=<url>]
  <action> : clear | preload | status
  --page   : optional URL or path to clear a single page (clear only)
```

**Arguments:**

| Kind | Name | Required | Default | Evidence |
|------|------|----------|---------|----------|
| Positional | `<action>` | no (defaults) | `'clear'` | ` $action = $args[0] ?? 'clear';` at `:76` |
| Assoc | `--page=<url>` | no | `null` | ` $page = $assoc_args['page'] ?? null;` at `:99`; doc `--page=<url>` at `:52` |

**Defaults & Validation:**

- `preload` branch at `:78-83`: `Cron::trigger_preload()` + `Log::add('Cache preload triggered via WP-CLI')` → `WP_CLI::success('Cache preload initiated...')` + `return`. No argument validation beyond `=== 'preload'`.
- `status` branch at `:85-91`: `Cache::get_cache_stats()` → three `WP_CLI::log(sprintf(...))` lines (size, cached_pages, last_cleared). `size` human-readable via `size_format`, `cached_pages` int, `last_cleared` string or `'never'`. No validation.
- `clear` else-error at `:93-97`: `if ( 'clear' !== $action ) WP_CLI::error('Invalid cache action "%s". Use "clear", "preload", or "status".')` + `return` (unreachable after error).
- Page mode at `:101-115`: `$path = wp_normalize_path( trim( (string) wp_parse_url( $page, PHP_URL_PATH ), '/' ) )` at `:102` → `Cache::clear_cache($path)` at `:103`. Success → `Log::add('Clear cache for %s via WP-CLI')` at `:107` + `WP_CLI::success('Cache cleared for page: %s')` at `:109`; failure → `WP_CLI::error('Failed to clear cache for page: %s')` at `:112`. Path traversal: relies on `Cache::clear_cache` internal `..` reject; CLI itself only normalizes, does not `realpath`-validate.
- All-mode at `:117-123`: `Cache::clear_cache()` (no arg) → `Log::add('Cleared all static HTML cache via WP-CLI')` + `success` or `error`.

**Permissions:** Any `wp` invoker; no `manage_options` check.

**Dependencies:**

- `Cron::trigger_preload()` at `:79` → `class-cron.php:84-87` (`new self()->schedule_page_cron_jobs()`) — respects `preload_settings.enablePreloadCache` gate at `:265-268`; no-op if disabled.
- `Cache::get_cache_stats()` at `:86` → `class-cache.php:2184-` (transient `wppo_cache_size` 15 min + `dirlist` walk).
- `Cache::clear_cache($path?)` at `:103,:117` → `class-cache.php:2030-` (static, dual FS: `WP_Filesystem` + direct `file_exists`/`unlink` fallback; fires `wppo_after_cache_clear` → `CDN_Purger` + `Edge_Purger`).
- `Log::add()` at `:80,:107,:119` → `class-log.php` (insert into `{prefix}wppo_activity_logs`).

**Output:**

| Branch | stdout/stderr | Evidence |
|--------|---------------|----------|
| `preload` | `WP_CLI::success('Cache preload initiated...')` | `:81` |
| `status` | `WP_CLI::log('Cache size: %s')` + `Cached pages: %d` + `Last cleared: %s` | `:87-89` |
| `clear --page` success | `WP_CLI::success('Cache cleared for page: %s')` | `:109` |
| `clear --page` failure | `WP_CLI::error('Failed to clear cache for page: %s')` → exit 1 | `:112` |
| `clear` all success | `WP_CLI::success('Static HTML cache cleared successfully.')` | `:120` |
| `clear` all failure | `WP_CLI::error('Failed to clear static HTML cache.')` → exit 1 | `:122` |
| invalid action | `WP_CLI::error('Invalid cache action "%s"...')` → exit 1 | `:95` |

No `--format` flag; `status` always human-readable plain logs, not JSON. To script it, parse logs or extend to JSON.

**Side Effects:**

- FS: `wp-content/cache/wppo/{domain}/{path}/index.html` (+ `.gz`/`.br`) deleted; full clear removes `wp-content/cache/wppo/` tree. Activity log row inserted on success branches.
- DB: 0 direct option writes except `delete_option('wppo_preload_cron_offset')` indirectly via `clear_cron_jobs` not triggered here; preload schedules `wppo_generate_static_page/url` single events (up to 200 posts + 500 sitemap URLs) in `wp_options` cron option.
- Cache: Object cache transient `wppo_cache_size` invalidated on next read (not explicitly flushed; recomputed after 15 min).

**Performance:** Single `clear` is `O(files_in_path)` I/O; `status` walks full domain dir (`count * dirlist`). `preload` only schedules jobs, near-zero cost (transient lock `wppo_preload_cron_lock` 20 min via `Util::transient_key` at `class-cron.php:288`).

**Tests:** No CLI tests found under `tests/php/*` (`glob:0` CLI). No `WPPO_CLI_CommandTest.php` exists.

**Docs (help text):** Full `## OPTIONS` + `## EXAMPLES` block at `:46-68` reproduced above; examples: `wp wppo cache clear`, `clear --page=/sample-page/`, `preload`, `status`.

**Gap vs Admin UI:** Admin bar buttons (Clear All / Clear This Page) and `Dashboard` Clear-all + single-page flows call `POST performance-optimisation/v1/clear_cache` (`class-rest.php:78-83`). CLI parity is near-complete except CLI lacks `group` flush (`Rest::clear_cache` `cache_dir` group param at `class-rest.php:378-399`) and does not expose the `wppo_after_cache_clear` `purge_litespeed` bridge timing (implicitly triggered anyway via `Cache::clear_cache`'s `do_action('wppo_after_cache_clear')`).

---

### 3.2 `wp wppo database {cleanup|optimize|counts}`

**Purpose:** Batched DB bloat removal and `OPTIMIZE TABLE` — mirrors `DatabaseCleanup.js` tabs and `Rest::database_cleanup` (`class-rest.php:819-916`).

**Source / Method:** `class-wppo-cli-command.php:126-294` · `public function database( array $args, array $assoc_args ): void` at `:174` · `@subcommand database` at `:169` · `@when after_wp_load` at `:168`

**CLI Synopsis (from docblock):**

```
wp wppo database <action> [--type=<type>] [--tables=<tables>]
  <action>  : cleanup | optimize | counts
  --type    : revisions|auto_drafts|trashed_posts|spam_comments|expired_transients|orphan_postmeta|all
              (also aliases: drafts, trash, spam, trashed, transients, orphans, unattached, unattached_media, oembed, oembed_cache)
              default: all (cleanup only)
  --tables  : comma CSV for optimize, default: posts,postmeta,comments,commentmeta,options
```

Full option list at `:134-152` (incl. triple-dash WP-CLI YAML `---` blocks).

**Arguments:**

| Kind | Name | Required | Default | Evidence |
|------|------|----------|---------|----------|
| Positional | `<action>` | no | `'cleanup'` | `$action = $args[0] ?? 'cleanup';` at `:175` |
| Assoc | `--type=<type>` | no (cleanup only) | `'all'` | `$type = $assoc_args['type'] ?? 'all';` at `:212` |
| Assoc | `--tables=<tables>` | no (optimize only) | `'posts,postmeta,comments,commentmeta,options'` | `$tables = $assoc_args['tables'] ?? 'posts,postmeta...'` at `:178` |

**Defaults & Validation:**

- **optimize** branch at `:177-198`: `array_map('trim', explode(',', $tables))` at `:179` → `array_merge(...array_values(Database_Cleanup::TABLE_MAP))` allowlist at `:180` → unique values `posts,postmeta,comments,commentmeta,options` (also covers `unattached_media`, `oembed_cache` tables). Unknown table → `WP_CLI::warning(' - Skipped unknown table: %s')` at `:184`. Else `Database_Cleanup::optimize_table($table)` at `:187` → log `Optimized table: %s` at `:190`. Final `Log::add('Database optimize via WP-CLI: %d tables optimized')` at `:194` + `WP_CLI::success('Database optimization complete: %1$d/%2$d tables optimized.')` at `:196`. Note: denominator is `count($table_list)` (input count, not allowlisted count).
- **counts** branch at `:200-204`: `Database_Cleanup::get_counts()` at `:201` → `WP_CLI::log(wp_json_encode($counts, PRETTY+SLASHES))` at `:202` + `return`.
- **cleanup invalid action** at `:206-210`: `if ('cleanup' !== $action) WP_CLI::error('Invalid database action "%s". Use "cleanup", "optimize", or "counts".')` at `:208`.
- **cleanup all** at `:214-233`: `Database_Cleanup::clean_all()` at `:215` → iterate `foreach ($results as $key=>$val)` at `:218`. `WP_Error` → `WP_CLI::warning(' - %s: %s', $key, $val->get_error_message())` at `:220`; else `log(' - %s: %d cleaned')` at `:225`. Total sum → `Log::add('Database cleanup (all via WP-CLI): %d items removed')` at `:229` + `WP_CLI::success('Database cleanup completed (%1$s): %2$d total items removed.')` at `:231`.
- **cleanup per-type switch** at `:237-277`: canonical + legacy aliases:
  - `revisions` → `clean_revisions()` at `:239`
  - `auto_drafts`/`drafts` → `clean_auto_drafts()` at `:243`
  - `trashed_posts`/`trash` → `clean_trashed_posts()` at `:247`
  - `spam_comments`/`spam` → `clean_spam_comments()` at `:251`
  - `trashed_comments`/`trashed` → `clean_trashed_comments()` at `:255`
  - `expired_transients`/`transients` → `clean_expired_transients()` at `:259`
  - `orphan_postmeta`/`orphans` → `clean_orphan_postmeta()` at `:263`
  - `unattached_media`/`unattached` → `clean_unattached_media()` at `:267`
  - `oembed_cache`/`oembed` → `clean_oembed_cache()` at `:271`
  - default → `WP_CLI::error('Invalid cleanup type "%s".')` at `:275`
- Post-clean error triage at `:279-293`: `is_wp_error($cleaned_count)` → `WP_CLI::error($cleaned_count->get_error_message())`; `false === $cleaned_count` → `WP_CLI::error('Database cleanup failed for type "%s".')`; else `Log::add('Database cleanup (%1$s via WP-CLI): %2$d items removed')` at `:291` + `WP_CLI::success('Database cleanup completed for %1$s (%2$d items removed).')` at `:293`. Note: `clean_all` uses per-type `WP_Error` checks but the method itself converts `false` to `WP_Error` via `invoke_cleanup_method` at `class-database-cleanup.php:935-942`, so the `false` path only fires for direct per-type `delete_in_batches` false (unlikely after invoke wrapper).

**Permissions:** Any `wp` invoker.

**Dependencies:**

- `Database_Cleanup::TABLE_MAP` (`:42-52`) + `CLEANUP_METHOD_MAP` (`:81-91`) + `METHOD_TO_TYPE` (`:60-70`) → allowlisting & optimization dedup
- `Database_Cleanup::optimize_table(string $table)` at `class-database-cleanup.php:1040-1088` (allowlisted `$wpdb->{table}` interpolation, size guard 1 GB, fallback `SHOW TABLE STATUS`, `OPTIMIZE TABLE $full_table_name`)
- `Database_Cleanup::get_counts()` at `class-database-cleanup.php:842-925` (salted cache `wp_cache_get_salted('wppo_db_cleanup_counts','wppo',SALT_KEY)` or transient, 9 COUNT(*) queries, multisite site-transient skip)
- `Database_Cleanup::clean_all()` at `class-database-cleanup.php:714-742` (iterates `CLEANUP_METHOD_MAP`, `get_revision_defaults()` with bounds `max_age 1-365, keep 1-100`, `do_action('wppo_database_cleanup_completed')`, `maybe_optimize_tables(dedup, true)`)
- Individual cleaners at `class-database-cleanup.php:188-706` (all via `delete_in_batches` helper at `:138-180` except transients/orphans variants)
- `Log::add()` on every success path

**Output:**

| Branch | stdout/stderr | Evidence |
|--------|---------------|----------|
| `optimize` per table | `log - Optimized table: %s` or `warning - Skipped unknown table: %s` | `:184,:190` |
| `optimize` summary | `success Database optimization complete: %d/%d tables optimized.` | `:196` |
| `counts` | `log <json pretty>` (9 keys) | `:202` |
| `cleanup --type=all` per-type | `log - %s: %d cleaned` or `warning - %s: %s` | `:220,:225` |
| `cleanup --type=all` summary | `success Database cleanup completed (all): %d total items removed.` | `:231` |
| `cleanup --type=X` success | `success Database cleanup completed for %s (%d items removed).` | `:293` |
| invalid action/type | `error Invalid ...` → exit 1 | `:208,:275` |
| per-type WP_Error/false | `error <msg>` → exit 1 | `:280,:286` |

No `--format` for `counts`; always `json`. No `--dry-run` to preview rows-to-delete.

**Side Effects:**

- DB: `DELETE` on `$wpdb->posts/postmeta/comments/commentmeta/options` depending on type; `OPTIMIZE TABLE` for optimize (table lock, log row). Counts cache salt bumped via `invalidate_counts_cache()` at `class-database-cleanup.php:950-956` after each `invoke_cleanup_method`.
- FS: None (except log row).
- Triggers: `Database_Cleanup::on_post_change` not invoked by CLI.

**Performance:**

- `optimize`: sequential per table; each does `SELECT data_length+index_length FROM information_schema.TABLES` (or `SHOW TABLE STATUS` fallback) + `OPTIMIZE TABLE` — table lock duration proportional to size (skipped if >1 GB at `class-database-cleanup.php:1051-1059`).
- `cleanup`: each batched loop `SELECT ID LIMIT 1000` → `DELETE meta → DELETE rows` until `< batch`; `clean_all` runs all 9 cleaners serially.
- `counts`: 9 sequential `COUNT(*)` (+ join for transients) — cheapest; salted cache bypasses DB if recent.

**Tests:** None (`DatabaseCleanupTest.php` covers class methods but no CLI invocation test; no `WP_CLI::` mock test).

**Docs (help text):** Full `## OPTIONS` with triple-dash defaults/options enumerations at `class-wppo-cli-command.php:126-173` + `## EXAMPLES` at `154-167`.

**Gap vs Admin UI / REST:** `DatabaseCleanup.js` shows per-type counts + schedule controls (`dbSchedule` daily/weekly/monthly + `dbOptimize` toggle + `dbRevMaxAge/dbRevKeepLatest`) but CLI `counts` does not expose `autoloaded_options` audit (REST `autoloaded_options` at `class-rest.php:236-241` → `Database_Cleanup::get_autoloaded_options()`), and `cleanup` has no `--schedule` or `--rev-max-age/keep-latest` tuning (hardcodes defaults at `class-database-cleanup.php:753-768`). REST also supports `type=all` partial `failures` 500 response (`class-rest.php:831-863`) whereas CLI warns instead of failing.

---

### 3.3 `wp wppo image {convert|status}`

**Purpose:** Queue/sync next-gen image conversion (WebP/AVIF) and inspect pending/completed counts.

**Source / Method:** `class-wppo-cli-command.php:296-391` · `public function image( array $args, array $assoc_args ): void` at `:321` · `@subcommand image` at `:316` · `@when after_wp_load` at `:315`

**CLI Synopsis:**

```
wp wppo image <action> [--format=<format>]
  <action> : convert | status
  --format : webp | avif (Default: auto-detected from settings image_optimisation.conversionFormat)
```

From `class-wppo-cli-command.php:297-319`.

**Arguments:**

| Kind | Name | Required | Default | Evidence |
|------|------|----------|---------|----------|
| Positional | `<action>` | no | `'status'` | `$action = $args[0] ?? 'status';` at `:322` |
| Assoc | `--format=<format>` | no | `''` (→ settings) | `$format = $assoc_args['format'] ?? '';` at `:323` |

**Defaults & Validation:**

- `convert` branch at `:325-367`:
  - Settings: `$options = Util::get_settings()` at `:326`; `$img_converter = new Img_Converter($options)` at `:327`; `$img_info = Img_Converter::get_img_info()` at `:328`.
  - Format resolution at `:329-337`: `$conversion_format = $format ? $format : ($options['image_optimisation']['conversionFormat'] ?? 'webp')` at `:329`; `batch_size = $options['image_optimisation']['batch'] ?? 50` at `:330`; `formats_to_process` built: `'both'→['avif','webp']`, else single `avif` or `webp` allowlisted via `in_array(... ['avif','webp'] ...)` at `:335`. Invalid format → empty list → 0 converted → reports `0/0`.
  - Loop at `:343-360`: for each `fmt` in `formats_to_process`, `$images = $img_info['pending'][$fmt] ?? []` at `:344`; `total_pending += count($images)` at `:345`; inner `foreach` caps at `$counter >= $batch_size` break at `:348-350`; `++$counter` at `:351`; path safety `source_path = wp_normalize_path(ABSPATH . $img)` at `:352`; `resolved = realpath($source_path)` at `:353`; prefix check `strpos(wp_normalize_path($resolved), normalized_abspath) !== 0` continue at `:354-355` (prevents `..` traversal out of ABSPATH); then `$img_converter->convert_image($source_path, $fmt)` at `:357` + `++$converted` at `:358`.
  - Final log at `:362-365`: `Log::add('Image conversion via WP-CLI: %d images processed')` + `WP_CLI::success('Image conversion complete: %d/%d images processed.')`
- `status` branch at `:369-387`: `Img_Converter::get_img_info()` at `:370` → aggregate `total_pending`, `total_completed`, per-`webp`/`avif` counts via `count($pending/$completed)` at `:371-384` → `WP_CLI::log(wp_json_encode($output,...))` at `:385` + `return`.
- Invalid action at `:390`: `WP_CLI::error('Invalid image action "%s". Use "convert" or "status".')`.

**Permissions:** Any `wp` invoker.

**Dependencies:**

- `Util::get_settings()` at `:326` (memoized, blog-keyed)
- `Img_Converter` at `:327` → `class-img-converter.php:113-130` (reads `excludeWebPImages`, `conversionFormat`; core gate `core_handles_next_gen()` → format `'none'` short-circuit)
- `Img_Converter::get_img_info()` at `:328,370` → `class-img-converter.php:` (reads option `wppo_img_info` with deferred shutdown commits + salted cache `wppo_img_info_salt`; contains `pending/webp`, `pending/avif`, `completed/*`, `failed/*`, `dominant_color`, `lqip`)
- `Img_Converter::convert_image(string $src, string $fmt, int $quality=-1)` at `:357` → `class-img-converter.php:319-686` (resolves output format via `wp_get_image_editor_output_format`, gain-map skip, filesize `20MB`/`wppo_filesize_limit_bytes` gate, `getimagesize` dims gate `5000×5000`, GD `imagecreatefrom*` + palette→truecolor, Imagick GIF→WebP, quality via `wp_get_image_encode_quality`/`wp_image_quality` falling back 82, writes to `WP_CONTENT_DIR/wppo/...` via `Util::prepare_cache_dir`, updates `wppo_img_info` status deferred)
- `Log::add()` at `:363`

**Output:**

| Branch | stdout/stderr | Evidence |
|--------|---------------|----------|
| `convert` success | `success Image conversion complete: %d/%d images processed.` | `:365` |
| `status` | `log { total_pending, total_completed, pending:{webp,avif}, completed:{webp,avif} }` JSON pretty | `:385` |
| invalid action | `error Invalid image action ...` → exit 1 | `:390` |

No per-image logs (unlike `database optimize`), no progress bar, no `--batch` override.

**Side Effects:**

- FS: WebP/AVIF files at `wp-content/wppo/{path}/file.{webp,avif}` (or `wp-content/uploads/wppo/...` rewrite); placeholder data `dominant_color`/`lqip` updated in `wppo_img_info`.
- DB: `wppo_img_info` option mutated via deferred atomic commits (shutdown hook `class-img-converter.php:34-51`); no `posts` mutation.
- Log: One activity row on `convert`.

**Performance:**

- Batch capped at `batch` setting (default 50) per format per run → at most 100 files if `both` (50 WebP + 50 AVIF). Each `convert_image` decodes bitmap (memory spike) + `filesize`/`getimagesize`/`realpath` + GD encode. Time: ~0.2-1s per image depending on size/Imagick. No memory limit raise; large libraries must be iterated via repeated CLI calls or `Cron::img_convert_cron` hourly.
- No `queue_unconverted_library_images` discovery (CLI reads only `pending` queue; new media DB-scan via `Cron::img_convert_cron:664-667` with `wppo_cron_discovery_limit 50` not triggered).
- `realpath` I/O per image.

**Tests:** `ImgConverterTest.php` covers `get_img_path`, placeholder helpers, but no CLI convert/status test.

**Docs (help text):** `## OPTIONS` with `--format=<format>` (webp/avif, default auto from settings) at `class-wppo-cli-command.php:299-320`.

**Gap vs Admin UI:** `ImageOptimization.js` + `Dashboard` show lazy-load toggles, placeholder type, `excludeWebPImages`, `forceServerSideConversion`, `conversionFormat`/`batch` settings, plus `optimise_image` REST batch-queues via `as_enqueue_async_action` per image (`class-rest.php:605-641`) or sync fallback (`:664-682`). CLI only processes the **already-queued** `pending` list synchronously (no async scheduler) and never surfaces/updates the image settings themselves (settings subcommand covers those tabs but not the per-image queue beyond convert/status).

---

### 3.4 `wp wppo settings {get|update|export|import}`

**Purpose:** View, mutate, export, or import `wppo_settings` — the single source for all tabs. This is the most complex subcommand (483 lines incl. helpers).

**Source / Method:** `class-wppo-cli-command.php:392-750` · `public function settings( array $args, array $assoc_args ): void` at `:573` · two docblocks at `:393-441` and `:524-572` (first precedes `get_default_settings`, second is the method) · `@subcommand settings` at `:567` · `@when after_wp_load` at `:566` · helpers at `:451-522`, `:864-872`

**CLI Synopsis (from docblock):**

```
wp wppo settings <action> [<tab>] [--settings=<json>] [--file=<path>] [--format=<format>]
  <action>    : get | update | export | import
  [<tab>]     : settings tab name (file_optimisation, preload_settings, image_optimisation, database_cleanup, object_cache, …)
  --settings  : JSON string of k=>v pairs (update only, required)
  --file      : file path for export/import
  --format    : json | yaml (get only, default: json)
```

Full `## OPTIONS` at `class-wppo-cli-command.php:391-440` (duplicated at `524-549`). Examples at `550-565`.

**Arguments:**

| Kind | Name | Required | Default | Evidence |
|------|------|----------|---------|----------|
| Positional | `<action>` | no | `'get'` | `$action = $args[0] ?? 'get';` at `:574` |
| Positional | `[<tab>]` | no | `null` | `$tab = $args[1] ?? null;` at `:575` |
| Assoc | `--settings=<json>` | update only, required | `null` | `$json = $assoc_args['settings'] ?? null;` at `:713` |
| Assoc | `--file=<path>` | export/import only, optional/required | `null` | `$file = $assoc_args['file'] ?? null;` at `:601,:623` |
| Assoc | `--format=<format>` | get only | `'json'` | `$format = $assoc_args['format'] ?? 'json';` at `:690` |

**Defaults & Validation — Helpers First:**

`get_default_settings(): array` at `class-wppo-cli-command.php:451-522` mirrors `Main::__construct()` defaults without persisting. Tabs: `cache_settings` (enableLoggedInCache, loggedInCacheRoles), `file_optimisation` (32 keys incl. `minifyHTML`, `deferJS`, `delayJS*`, `combineCSS`, `exclude*`, `removeHTMLComments=true`, etc.), `preload_settings` (enableSpeculationRules, speculationMode/Eagerness/ExcludeUrls, preloadSitemap), `image_optimisation` (lazyLoadImages, lazyLoadNative=true, placeholderType=svg, etc.), `performance_audit` (pagespeed_api_key='', high_value_urls, auto_fix, server_timing, auto_rescan, rum_enabled), `database_cleanup` empty, `object_cache` empty. Note: CLI defaults omit `litespeed_integration`, `llms_txt`, `od_integration`, `bfcache`, `perf_translations`, `ai_adaptive`, `edge_cache` (those live in `Main::defaults:240-265` but not in CLI helper — explains warning "Unrecognized settings tab" if operator uses those tabs).

**Settings loading at `:577-588`:**

```php
$options = Util::get_settings();
if ( empty($options) || !is_array($options)) $options = self::get_default_settings();
else $options = array_replace_recursive(self::get_default_settings(), $options);
```

Fresh installs get usable defaults without persisting; partial stored arrays are backfilled. Idempotent.

**Branch: `export` at `:590-620`:**

- Stripping at `:593-599`: unsets `object_cache.password` and `performance_audit.pagespeed_api_key` before export (security — never exfiltrate secrets).
- File mode at `:601-616`: if `--file` supplied, `Util::init_filesystem()` at `:603` → global `$wp_filesystem` must exist else `WP_CLI::error('Unable to initialize filesystem.')` at `:606`; `wp_json_encode(PRETTY+SLASHES)` at `:609` → `$wp_filesystem->put_contents($file, $json, FS_CHMOD_FILE)` at `:610` → error `Failed to write settings to file.` at `:611` else `success('Settings exported to %s')` at `:615`. Without file → `WP_CLI::log(wp_json_encode(...))` at `:617`. No format flag for export (always json).

**Branch: `import` at `:622-676`:**

- Requires `--file` else `error('Please provide a --file=<path>')` at `:625`.
- FS at `:629-634`: `Util::init_filesystem()` + `exists($file)` check → error `File not found or filesystem unavailable.` at `:632`.
- JSON at `:636-641`: `get_contents` → `json_decode(..., true)` → `!is_array` → `error('Invalid JSON in settings file.')`.
- Allowlist at `:644-651`: `ALLOWED_SETTINGS_KEYS = Util::ALLOWED_SETTINGS_KEYS` (`class-util.php:43-58`: 13 keys) → any unknown top-level key → `error('Invalid setting key "%s" detected.')` and abort (no partial import).
- Sanitize at `:654`: `Util::sanitize_settings_recursively($new_settings)` (key strip `/[^a-zA-Z0-9_\-]/`, mode allowlist `auto/wppo/litespeed/standalone`, type-aware sanitizeTextarea/esc_url/etc. at `class-util.php:877-913`).
- Password/API stripping at `:657-667`: `object_cache.password` removed but `password_set=true` if provided at `:661` (never persist raw password); `performance_audit.pagespeed_api_key` always unset at `:666` (preserve via update_settings flow, not import).
- Merge at `:669-671`: `$existing = Util::get_settings()` → `array_replace_recursive($existing, $new_settings)` → `update_option('wppo_settings', $merged)` at `:671` (triggers `update_option_wppo_settings` actions: `Main::on_settings_update` clears cache / regenerates `.htaccess` / re-bakes drop-in, `Cache::clear_cache()` etc.).
- Success at `:673-674`: `Log::add('Settings imported via WP-CLI')` + `WP_CLI::success('Settings imported successfully.')`

**Branch: `get` at `:678-705`:**

- If `$tab` supplied and `!isset($options[$tab])` → `WP_CLI::error('Invalid settings tab "%1$s". Available tabs: %2$s.', tab, implode(', ', array_keys($options)))` at `:682`.
- Else `$data = $tab ? $options[$tab] : $options` at `:685-688`.
- Format at `:690-703`: `$format = assoc['format'] ?? 'json'`. `yaml` → `Spyc::YAMLDump` preferred, `yaml_emit` fallback, else `warning('YAML dumper not available; falling back to JSON')` + json. Default `json` → `log(wp_json_encode($data,PRETTY+SLASHES))`.

**Branch: `update` at `:707-746`:**

- Requires `$tab` else `error('Please specify a settings tab name...')` at `:709`.
- Requires `$json` else `error('Please provide a JSON object string via --settings')` at `:714-715`.
- `json_decode` + `is_array` at `:719-722` else error.
- Sanitize at `:726`: `Util::sanitize_settings_recursively($new_settings)`.
- Known-tab check at `:728-732`: `in_array($tab, Util::ALLOWED_SETTINGS_TABS)` else `warning('Unrecognized settings tab "%s". Settings will be saved but the plugin may not read them.')` — soft warning, not error.
- Deep merge at `:734-739`: `if (!isset($options[$tab]) || !is_array) $options[$tab]=[];` then `array_replace_recursive($options[$tab], $new_settings)` → `update_option('wppo_settings', $options)` at `:739` (triggers same `on_settings_update` cascade).
- Success at `:742-744`: `Log::add('Updated plugin settings for tab %s via WP-CLI')` + `success('Settings updated successfully for tab "%s".')`.

**Fallback:** `error('Invalid settings action "%s". Use "get", "update", "export", or "import".')` at `:749`.

**Permissions:** Any `wp` invoker.

**Dependencies:**

- `Util::get_settings()` memoized at `class-util.php:145-157` + `get_allowed_settings_keys()`
- `Util::ALLOWED_SETTINGS_KEYS / ALLOWED_SETTINGS_TABS` at `class-util.php:43-69`
- `Util::sanitize_settings_recursively()` at `class-util.php:877-913`
- `Util::init_filesystem()` + `$wp_filesystem` (`class-util.php:322-334`)
- `update_option('wppo_settings', ...)` → fires `Main::on_settings_update` (`class-main.php:1032-1131`: cache clear, `Advanced_Cache_Handler::create()`, `.htaccess` via `Htaccess_Handler::update_rules()`, Google Fonts cache, image salt, audit salt)
- `Log::add()` on update/import

**Output:**

| Branch | stdout/stderr | Evidence |
|--------|---------------|----------|
| `get json` | `log <json pretty>` | `:702` |
| `get yaml` via Spyc | `log <yaml dump>` | `:694` |
| `get yaml` via yaml_emit | `log <yaml>` | `:696` |
| `get yaml` fallback | `warning YAML dumper not available...` + json | `:698-699` |
| `update` success | `success Settings updated successfully for tab "%s".` | `:744` |
| `export --file` success | `success Settings exported to %s` | `:615` |
| `import` success | `success Settings imported successfully.` | `:674` |
| `export` (no file) | `log <json pretty>` | `:617` |
| errors/warnings as above | `error` → exit 1, `warning` → continue | `:606,:611,:625,:632,:639,:648,:682,:709,:715,:722,:731,:749` |

No `--dry-run`, no diff preview.

**Side Effects:**

- DB: `wppo_settings` option mutated on `update`/`import`; transient `wppo_wp_cache_fix_checked` not touched; `wppo_version`, `wppo_block_assets_migrated` untouched.
- FS: `.htaccess` may be rewritten; `advanced-cache.php` drop-in re-generated; `wp-content/cache/wppo/` cleared when `cache_settings/file_optimisation/image_optimisation/preload_settings/core_tweaks` tab changes.
- Log: One row on update/import.

**Performance:** Single `update_option` + action cascade (cache clear is most expensive I/O). `export` reads no DB beyond settings memo.

**Tests:** No CLI settings test; `UtilSettingsCacheTest.php` covers memoization, not CLI.

**Docs (help text):** Duplicated `## OPTIONS` blocks at `392-440` and `525-549` + 5 examples at `551-565`.

**Gap vs Admin UI:** SPA `PluginSetting.js` covers export/import UI, activity log pagination, PageSpeed API key visibility toggle, language strings — CLI export/import parity is close except: CLI never exports `pagespeed_api_key`/`password` (REST also redacts those at `class-rest.php:509-514,792-797` — consistent); CLI import also strips those secrets (REST preserves `pagespeed_api_key` when omitted at `class-rest.php:491-493` but CLI import unconditionally unsets). UI also handles per-page `Asset_Manager` and `Metabox` (`class-metabox.php`) — no CLI equivalent. UI `update_settings` uses `apiCall('update_settings')` global mutate (`src/lib/apiRequest.js`) — CLI mirrors but requires explicit JSON string.

---

### 3.5 `wp wppo object-cache {status|ping|enable|disable|flush}`

**Purpose:** Manage Redis object cache drop-in (`wp-content/object-cache.php` + `wp-content/wppo-redis-config.php`).

**Source / Method:** `class-wppo-cli-command.php:752-856` · `public function object_cache( array $args, array $assoc_args ): void` at `:801` · `@subcommand object-cache` at `:796` · `@when after_wp_load` at `:795` + helper `:864-872`

**CLI Synopsis:**

```
wp wppo object-cache <action> [--host=<host>] [--port=<port>] [--password=<password>] [--database=<database>] [--timeout=<timeout>] [--prefix=<prefix>]
  <action> : status | ping | enable | disable | flush
  --host/_port/_password/_database/_timeout/_prefix : for ping/enable only
```

Docblock at `753-800` with 5 examples.

**Arguments:**

| Kind | Name | Required | Default | Evidence |
|------|------|----------|---------|----------|
| Positional | `<action>` | no | `'status'` | `$action = $args[0] ?? 'status';` at `:802` |
| Assoc | `--host,--port,--password,--database,--timeout,--prefix` | no | none (passed through) | `get_redis_config_from_assoc($assoc_args)` at `:812,822` → `class-wppo-cli-command.php:864-871` extracts only those six keys via `isset` loop; unlisted keys ignored (e.g. `--mode, --nodes, --master_name, --use_tls, --persistent, --compression` accepted by REST `build_redis_config` at `class-rest.php:1104-1142` but **ignored** by CLI) |

**Validation & Flow at `:805-855`:**

- `switch ($action)`:
  - `status` at `:806-809`: `$manager = new Object_Cache()` at `:803`; `get_status()` at `:807` → `log(wp_json_encode($status,...))` at `:808` + `return`. Status shape from `class-object-cache.php:86-184`: `enabled`, `redis_missing`, `redis_reachable`, `foreign_dropin`, plus `telemetry`/`telemetry_error` when reachable+enabled.
  - `ping` at `:811-819`: `config = self::get_redis_config_from_assoc($assoc_args)` at `:812`; `ping($config)` at `:813`; `is_wp_error($result)` → `error($result->get_error_message())` → exit 1 at `:815`; else `success('Redis server is reachable.')` at `:817`.
  - `enable` at `:821-830`: `config = ...` at `:822`; `enable($config)` at `:823`; error → `error(get_error_message())` at `:825`; else `Log::add('Redis Object Cache enabled via WP-CLI')` at `:827` + `success('Redis Object Cache enabled successfully.')` at `:828`.
  - `disable` at `:832-840`: `disable()` at `:833`; error → `error(...)`; else `Log::add('Redis Object Cache disabled via WP-CLI')` + `success('Redis Object Cache disabled successfully.')` at `:837-838`.
  - `flush` at `:842-850`: `$success = $manager->flush()` at `:843` (wraps `wp_cache_flush()`); true → `Log::add('Flushed Redis Object Cache via WP-CLI')` + `success('Redis Object Cache flushed successfully.')` at `:845-846`; else `error('Failed to flush Redis Object Cache.')` at `:848`.
  - `default` at `:852-854`: `error('Invalid object-cache action "%s". Use "status", "ping", "enable", "disable", or "flush".')`.

**Permissions:** Any `wp` invoker. `enable`/`disable` writes to `wp-content/*.php` (needs FS write permission, not WP capability).

**Dependencies:**

- `Object_Cache` at `class-object-cache.php` — `get_status()` (`86-184` reads drop-in content + `Util::get_settings()/config.php` + `wppo_redis_connect()` via `redis-connect-helper.php`), `ping(array $config)` (`205-238`), `enable(array $config)` (`252-316` pings, parses nodes via `wppo_parse_nodes`, strips password from config file, `put_contents(config) + copy(template->dropin) + wp_cache_flush()`), `disable()` (`325-348` delete dropin+config), `flush()` (`356-361` `wp_cache_flush`).
- `get_redis_config_from_assoc()` at `864-871` (allowlist 6 keys).
- `Log::add()` on enable/disable/flush.

**Output:**

| Branch | stdout/stderr | Evidence |
|--------|---------------|----------|
| `status` | `log <json pretty status>` (enabled/redis_missing/redis_reachable/foreign_dropin/telemetry) | `:808` |
| `ping` success | `success Redis server is reachable.` | `:817` |
| `ping` failure | `error <WP_Error message>` → exit 1 | `:815` |
| `enable` success | `success Redis Object Cache enabled successfully.` | `:828` |
| `enable` failure | `error <message>` (missing extension, foreign_dropin, redis_unreachable, write_error) | `:825` |
| `disable` success/failure | analogous | `:835,:838` |
| `flush` success/failure | success / error | `:846,:848` |

No `--format` (`status` always JSON pretty).

**Side Effects:**

- FS: `wp-content/wppo-redis-config.php` written/stripped, `wp-content/object-cache.php` copied/deleted; `wp_cache_flush()` on enable.
- DB: `wppo_settings` **not** mutated by CLI (unlike REST `update_settings` which stores `password_set` flag). CLI `enable` only writes FS config, not DB — a divergence from UI flow where REST also merges settings.
- Log: Row on enable/disable/flush.

**Performance:** `status` opens `object-cache.php` (≤1 MB) + attempts Redis `info()` (network RTT + `INFO` parse). `enable` does ping before write.

**Tests:** `ObjectCacheTest.php` mocks FS + Redis, but no CLI test.

**Docs (help text):** `## OPTIONS` with per-flag lines at `755-800`.

**Gap vs Admin UI / REST:** `ObjectCache.js` UI + REST `handle_object_cache` (`class-rest.php:1025-1087`) supports full sentinel/cluster config (`mode`, `nodes`, `master_name`, `use_tls`, `persistent`, `compression`) with `wppo_redis_allow_request_password` filter and `WPPO_REDIS_PASSWORD` precedence (`class-rest.php:1130-1131`). CLI helper **drops** those keys — cluster/sentinel/TLS/compression cannot be configured via `wp wppo object-cache enable`; only `host,port,password,database,timeout,prefix`. UI also shows `supported_compressors` (`class-rest.php:1033-1037`) and telemetry UI cards — CLI `status` includes `telemetry` when enabled but does not expose `supported_compressors` (REST appends it). UI flush is same.

---

### 3.6 `wp wppo pagespeed {scan|results}`

**Purpose:** Queue Google PageSpeed Insights scans (via Action Scheduler) and fetch cached results.

**Source / Method:** `class-wppo-cli-command.php:874-932` · `public function pagespeed( array $args, array $assoc_args ): void` at `:902` · `@subcommand pagespeed` at `:897` · `@when after_wp_load` at `:896`

**CLI Synopsis:**

```
wp wppo pagespeed <action> [--url=<url>] [--strategy=<strategy>]
  <action>   : scan | results
  --url      : Page URL to scan (default: Util::cached_home_url() i.e. home)
  --strategy : mobile | desktop (default: mobile)
```

Docblock at `874-901` + examples `scan --url=https://example.com` / `results --url=...`.

**Arguments:**

| Kind | Name | Required | Default | Evidence |
|------|------|----------|---------|----------|
| Positional | `<action>` | no | `'scan'` | `$action = $args[0] ?? 'scan';` at `:903` |
| Assoc | `--url=<url>` | no | `Util::cached_home_url()` | `$url = $assoc_args['url'] ?? Util::cached_home_url();` at `:904` |
| Assoc | `--strategy=<strategy>` | no | `'mobile'` | `$strategy = $assoc_args['strategy'] ?? 'mobile';` at `:905` |

**Validation & Flow:**

- `scan` branch at `:907-918`: `$job_id = Pagespeed::queue_scan($url,$strategy)` at `:908`; if `<=0` → `error('Failed to queue PageSpeed scan. Action Scheduler may be unavailable.')` → exit 1 at `:910`; else `Log::add('PageSpeed scan queued via WP-CLI for %s')` at `:914` + `success('PageSpeed scan queued. Job ID: %d')` at `:916` + `return`.
- `results` branch at `:920-928`: `$results = Pagespeed::get_results($url,$strategy)` at `:921`; if `false === $results` → `warning('No PageSpeed results found for the given URL and strategy.')` at `:923` + `return` (exit 0 with warning); else `log(wp_json_encode($results,PRETTY+SLASHES))` at `:926`.
- Invalid action at `:931`: `error('Invalid pagespeed action "%s". Use "scan" or "results".')`.

**Permissions:** Any `wp` invoker.

**Dependencies:**

- `Util::cached_home_url()` at `:904` (blog-keyed)
- `Pagespeed::queue_scan(string $url, string $strategy): int` at `class-pagespeed.php:119-133` (requires `as_enqueue_async_action`, hook `wppo_pagespeed_scan` group `performance_optimisation`, returns job ID)
- `Pagespeed::get_results(string $url, string $strategy)` at `class-pagespeed.php:275-277` → `get_transient(Util::transient_key('wppo_pagespeed_...'))` (TTL `DAY_IN_SECONDS` at `:64`)
- `Log::add()` on scan queue
- Indirect: `Pagespeed::run_scan` (`146-262`) handles SSRF `wp_http_validate_url`, API key gate, `wp_remote_get` 120s timeout, `prepare_response`, `set_transient`, `register_transient_key`, `record_trend`, `store_lcp_image_url`

**Output:**

| Branch | stdout/stderr | Evidence |
|--------|---------------|----------|
| `scan` success | `success PageSpeed scan queued. Job ID: %d` | `:916` |
| `scan` failure | `error Failed to queue PageSpeed scan...` → exit 1 | `:910` |
| `results` found | `log <json pretty prepared response>` (scores/vitals/diagnostics/lcp_image_url) | `:926` |
| `results` not ready | `warning No PageSpeed results found...` → exit 0 | `:923` |
| invalid action | `error Invalid pagespeed action...` → exit 1 | `:931` |

No `--format`; always JSON. No polling loop (REST UI polls until 202 → result; CLI single-shot).

**Side Effects:**

- DB: `wp_options` `wppo_pagespeed_*` transient + `wppo_web_vitals_trends` on completion via worker; cron `wppo_web_vitals_trends_lock` option during trend write.
- FS: none.
- Log: One row on scan.

**Performance:** `scan` is near-zero (single `as_enqueue_async_action` insert). `results` is single `get_transient`. Actual PageSpeed HTTP 120s timeout occurs in background worker, not CLI.

**Tests:** `PagespeedTrendsTest.php` covers `record_trend`/`prune`/`lock` but no CLI test. `TelemetryTest.php`/`CronWebVitalsRescanTest.php` cover related.

**Docs (help text):** `## OPTIONS` at `874-901`.

**Gap vs Admin UI / REST:** `Dashboard.js` `pagespeed_scan` + `pagespeed_results` + `web_vitals_trends` REST triad (`class-rest.php:159-176`) + `Cron::web_vitals_rescan_cron` daily auto-rescan (`class-cron.php:201-257`). CLI lacks `web_vitals_trends` history view and `suggestions` endpoint, and cannot configure `high_value_urls` / `auto_rescan` / `pagespeed_api_key` directly (must via `settings update performance_audit`).

---

### 3.7 `wp wppo system-info [group]`

**Purpose:** Dump PHP/DB/WP/server/cache/infrastructure/litespeed/opcache diagnostics — same data as REST `system_info` (`class-rest.php:145-151`, `1201-1203`) and Dashboard System Info widget.

**Source / Method:** `class-wppo-cli-command.php:934-972` · `public function system_info( array $args, array $assoc_args ): void` at `:956` · `@subcommand system-info` at `:952` · `@when after_wp_load` at `:954`

**CLI Synopsis (from docblock):**

```
wp wppo system-info [<group>]
  [<group>] : optional group filter: php, database, WordPress, wp_constants, server, cache, infrastructure
              (actual implementation also supports: litespeed, opcache, wordpress variants per System_Info::get_all)
```

Docblock at `934-955` examples: `wp wppo system-info` (all) vs `wp wppo system-info php` (single group).

**Arguments:**

| Kind | Name | Required | Default | Evidence |
|------|------|----------|---------|----------|
| Positional | `[<group>]` | no | `null` | `$group = $args[0] ?? null;` at `:958` |
| Assoc | none | — | — | `$assoc_args` unused (signature retains `array $assoc_args` at `:956` but ignored) |

**Validation & Flow at `:957-971`:**

- `$all = System_Info::get_all()` at `:957` → `class-system-info.php:65-77` returns 9 keys: `php, database, wordpress, wp_constants, server, cache, litespeed, infrastructure, opcache`.
- If `$group` supplied at `:960`: `if (!isset($all[$group])) WP_CLI::error('Invalid system info group "%s".')` at `:962` → exit 1 + `return`; else `WP_CLI::log(wp_json_encode($all[$group],PRETTY+SLASHES))` at `:965` + `return`.
- Else `log(wp_json_encode($all,PRETTY+SLASHES))` at `:970`.

**Permissions:** Any `wp` invoker.

**Dependencies:**

- `System_Info::get_all()` at `class-system-info.php:65-77` → delegates to `get_php()`, `get_database()`, `get_wordpress()`, `get_wp_constants()`, `get_server()`, `get_cache()`, `get_litespeed()`, `get_infrastructure()`, `get_opcache()`. Each is null-safe. `get_litespeed()` additionally probes `LiteSpeed_Integration::get_info()` / `Server_Rules::get_server_type()` + drop-in file reads (≤1 MB cap) for `advanced_cache`/`object_cache` arbitration.

**Output:**

| Branch | stdout/stderr | Evidence |
|--------|---------------|----------|
| All groups | `log <json pretty with 9 top-level keys>` | `:970` |
| Single group | `log <json pretty of that group's array>` | `:965` |
| Invalid group | `error Invalid system info group "%s".` → exit 1 | `:962` |

No `--format` (always JSON pretty). Key naming is snake_case (`php`, `database`, `wordpress`, etc.); `wp_constants` holds string `'true'/'false'/'undefined'` per `format_constant` at `class-system-info.php:626-631`.

**Side Effects:** None (read-only; no FS/DB writes).

**Performance:** Negligible except `get_litespeed` file reads (two `file_get_contents` ≤1 MB).

**Tests:** `SystemInfoTest.php` exists (covers `get_all` groups).

**Docs (help text):** Docblock `## OPTIONS` + `## EXAMPLES` at `934-955`; group help text lists `php, database, WordPress, wp_constants, server, cache, infrastructure` (omits `litespeed`/`opcache` even though supported via `get_all`).

---

## 4. Global Defects & Observations

| Area | Finding | Evidence |
|------|---------|----------|
| **No dry-run** | No subcommand implements `--dry-run` | All docblocks lack it |
| **No `--format` table** | Only `settings get` has `json/yaml`; other commands hardcode JSON pretty | `:690-703` |
| **No progress bar** | No `WP_CLI::make_progress_bar` | absent |
| **No confirmation** | Destructive `clear`, `cleanup --type=all`, `enable/disable`, `import` run without `WP_CLI::confirm` | absent |
| **Exit-code inconsistency** | `pagespeed results` not ready uses `warning` (exit 0) not error; `database optimize` unknown table uses `warning` but still `success` overall. Consistency with REST (which returns 202/500) not preserved. | `:923,:184` |
| **Path safety divergence** | CLI `cache clear --page` uses string-normalized path → delegates to `Cache::clear_cache` which re-validates via `..` reject; REST validates via `realpath` prefix (`class-rest.php:413-432`) with fallback candidate-path string prefix. CLI path may clear a non-existent page (no-op) where REST would 400. Considered medium in `AUDIT/AGENTS/agent-A04` R03/CLI01 | `class-wppo-cli-command.php:102-103` vs `class-rest.php:371-433` |
| **Missing multisite flags** | CLI never documents or implements `switch_to_blog` semantics; relies on `--url` but operator may expect `--network` sweep. REST/Cron are site-aware. | `class-wppo-cli-command.php` never calls `is_multisite` |
| **Helper omission** | `get_default_settings` omits 7 newer tabs (`litespeed_integration`, `llms_txt`, `od_integration`, `bfcache`, `perf_translations`, `ai_adaptive`, `edge_cache`) that `Main::defaults` includes at `class-main.php:240-265`; CLI `settings get` warns on those as "Unrecognized" though they are valid. | `class-wppo-cli-command.php:451-522` vs `class-main.php:240-265` |
| **Object-cache allowlist gap** | CLI only exposes 6 Redis keys vs REST's 10; cluster/sentinel users must use REST/DB manual config. | `:864-870` vs `class-rest.php:1104-1117` |
| **Tests absent** | `tests/php/*Test.php` (44 files) — zero covers `WPPO_CLI_Command`; `composer test` discovery requires `*Test.php` naming but none exists for CLI. | `glob tests/php/**/*` confirms none |

---

## 5. Admin UI → CLI Gap Matrix

> “What UI can do that CLI cannot” — per SPA component tree in `AGENTS.md:31-50` and REST table at `:25` endpoints.

| UI Area (tab/component) | File | REST capability | CLI coverage | Gap |
|--------------------------|------|-----------------|--------------|-----|
| **Dashboard** — Performance scan, suggestions, pagespeed trends, recent activities, cache stats, image savings | `src/components/Dashboard.js` | `performance_scan`, `suggestions`, `pagespeed_scan/results`, `web_vitals_trends`, `system_info`, `recent_activities`, `image_job_status`, `get_savings_summary` | `pagespeed scan/results`, `system-info` | No `performance_scan` (local telemetry), `suggestions`, `web_vitals_trends`, `recent_activities` / `autoloaded_options` CLI; `image savings` not in `image status` (CLI shows pending/completed counts but not `savings`); `dismiss_welcome` missing |
| **File Optimization** — minify/combine, defer/delay, CDN, server rules, exclude lists, critical/used CSS, block assets toggles | `src/components/FileOptimization.js` | `update_settings tab=file_optimisation`, `server_rules`, `regenerate_ccss`, `ccss_status`, `used_css_regenerate`, `get_page_assets` | `settings get/update file_optimisation` only | No direct `server_rules` dump, `used_css_regenerate`, `regenerate_ccss`/`ccss_status`, `get_page_assets` (asset manager), `combineCSS` cache size visibility (`Cache::get_cache_size` via REST transient) beyond `cache status` |
| **Preload Settings** — cache warm-up toggles, speculation rules, preload sitemap | `src/components/PreloadSettings.js` | `update_settings tab=preload_settings` | `settings get/update preload_settings` | No direct trigger for sitemap URL list or per-page preload (CLI `cache preload` triggers batch but not sitemap-only or single-post warm-up UI semantics) |
| **Image Optimization** — WebP/AVIF format/batch, lazy load, `<picture>`, responsive limits, video lazy, placeholder types | `src/components/ImageOptimization.js` | `optimise_image`, `delete_optimised_image`, `image_job_status`, `update_settings tab=image_optimisation` | `image convert/status` + `settings` | No `delete_optimised_image` (wipe `wp-content/wppo`), no `image_job_status` savings/queued_jobs detail (CLI `status` omits `failed` + `queued_jobs` + `savings`), no per-format `batch` tuning flag |
| **Database Cleanup** — 7 types + schedule + optimize + orphan counts + autoloaded options | `src/components/DatabaseCleanup.js` | `database_cleanup`, `database_cleanup_counts`, `autoloaded_options`, `update_settings tab=database_cleanup` | `database cleanup/optimize/counts` | No `autoloaded_options` audit, no schedule (`dbSchedule`) CRUD via CLI arg (only via `settings update database_cleanup '{\"dbSchedule\":\"daily\"}'`), no `dbRevMaxAge/dbRevKeepLatest` per-run override |
| **Object Cache** — Redis standalone/sentinel/cluster, TLS, compression, modes | `src/components/ObjectCache.js` | `object_cache status/ping/enable/disable/flush` (+ full 10-param config) | `object-cache status/ping/enable/disable/flush` (6-param only) | Cluster/sentinel (`nodes`, `master_name`), `mode`, `use_tls`, `persistent`, `compression` unsupported; `supported_compressors` telemetry not in CLI status |
| **Plugin Setting** — activity log, PageSpeed API key, export/import, LiteSpeed/LLMs/AI/Edge flags | `src/components/PluginSetting.js` | `recent_activities`, `import_settings`, `update_settings tab=performance_audit` + 7 newer tabs | `settings get/export/import, system-info` | No `recent_activities` pagination, no RUM (`rum_collect` public, `rum_data` admin) CLI, no health/LLMs regeneration (`wppo_llms_txt_daily`), no `ai_learn/ai_model/ai_suggestions` (REST `ai_*` at `class-rest.php:242-259`), no cron status/health, no export-with-file JSON redaction visibility (CLI does it but not documented) |
| **RUM** | `src/lib/rum`? REST `rum_collect`/`rum_data` | `rum_collect` (public token + IP rate 120/h), `rum_data` (admin) | **None** | Entire beacon pipeline invisible from CLI |
| **Cron / Health / Reset** | `class-cron.php` (12 hooks) | indirect via settings+REST | `cache preload` only | No `wppo_img_conversion`, `wppo_database_cleanup_cron`, `wppo_web_vitals_rescan`, `wppo_used_css_cron`, `wppo_ccss_regeneration`, `wppo_rum_flush`, `wppo_llms_txt_daily` triggers; no cache health/ttl inspector; no full settings reset |

---

## 6. Full `@subcommand` Synopsis Dump (verbatim)

**`cache`** `class-wppo-cli-command.php:44-74`:

```
Manage static HTML cache.
OPTIONS
  <action> : clear, preload, or status.
  [--page=<url>] : Optional specific page URL or relative path to clear cache for.
EXAMPLES
  wp wppo cache clear
  wp wppo cache clear --page=/sample-page/
  wp wppo cache preload
  wp wppo cache status
@when after_wp_load · @subcommand cache
```

**`database`** `class-wppo-cli-command.php:126-173`:

```
Perform database cleanup and optimization routines.
OPTIONS
  <action> : cleanup, optimize, or counts.
  [--type=<type>] : default: all  options: revisions, auto_drafts, trashed_posts, spam_comments, expired_transients, orphan_postmeta, all  (plus CLI aliases: drafts, trash, spam, trashed, transients, orphans, unattached, unattached_media, oembed, oembed_cache)
  [--tables=<tables>] : default: posts,postmeta,comments,commentmeta,options
EXAMPLES
  wp wppo database cleanup --type=revisions
  wp wppo database cleanup --type=all
  wp wppo database optimize
  wp wppo database counts
@when after_wp_load · @subcommand database
```

**`image`** `class-wppo-cli-command.php:296-320`:

```
Manage image conversion.
OPTIONS
  <action> : convert or status.
  [--format=<format>] : webp or avif. Default: auto-detected from settings.
EXAMPLES
  wp wppo image convert
  wp wppo image status
@when after_wp_load · @subcommand image
```

**`settings`** `class-wppo-cli-command.php:392-441` / `524-572`:

```
View, update, export, or import plugin settings.
OPTIONS
  <action> : get, update, export, or import.
  [<tab>]  : tab name (file_optimisation, preload_settings, image_optimisation, database_cleanup, object_cache).
  [--settings=<json>] : JSON string (update)
  [--file=<path>]     : file path (export/import)
  [--format=<format>] : json or yaml (get, default json)
EXAMPLES
  wp wppo settings get
  wp wppo settings get file_optimisation
  wp wppo settings update file_optimisation --settings='{"minifyHTML":true}'
  wp wppo settings export --file=/tmp/wppo-settings.json
  wp wppo settings import --file=/tmp/wppo-settings.json
@when after_wp_load · @subcommand settings
```

**`object-cache`** `class-wppo-cli-command.php:752-800`:

```
Manage Redis Object Cache.
OPTIONS
  <action> : status, ping, enable, disable, or flush.
  [--host/--port/--password/--database/--timeout/--prefix] : for ping/enable
EXAMPLES
  wp wppo object-cache status
  wp wppo object-cache ping --host=127.0.0.1 --port=6379
  wp wppo object-cache enable --host=127.0.0.1 --port=6379
  wp wppo object-cache disable
  wp wppo object-cache flush
@when after_wp_load · @subcommand object-cache
```

**`pagespeed`** `class-wppo-cli-command.php:874-901`:

```
Manage Google PageSpeed scans.
OPTIONS
  <action>   : scan or results.
  [--url=<url>]         : Page URL to scan.
  [--strategy=<strategy>] : mobile or desktop. Default: mobile.
EXAMPLES
  wp wppo pagespeed scan --url=https://example.com
  wp wppo pagespeed results --url=https://example.com
@when after_wp_load · @subcommand pagespeed
```

**`system-info`** `class-wppo-cli-command.php:934-955`:

```
Show system information.
OPTIONS
  [<group>] : Optional group filter: php, database, WordPress, wp_constants, server, cache, infrastructure.
EXAMPLES
  wp wppo system-info
  wp wppo system-info php
@when after_wp_load · @subcommand system-info
```

---

## 7. Evidence Index

| Claim | File:Line |
|-------|-----------|
| Class header & package | `class-wppo-cli-command.php:1-11` |
| Namespace & parent | `:12-15`, `:42` |
| ABSPATH guard | `:17-19` |
| Class doc examples | `:24-41` |
| All `@when after_wp_load` | `:69,:168,:315,:442,:567,:795,:896,:954` |
| All `@subcommand` tags | `:70,:169,:316,:568,:796,:897,:952` |
| `cache()` signature + body | `:75-124` |
| `database()` signature + body | `:174-294` |
| `image()` signature + body | `:321-391` |
| `get_default_settings()` | `:451-522` |
| `settings()` signature + body | `:573-750` |
| `object_cache()` + switch | `:801-856` |
| `get_redis_config_from_assoc()` | `:864-872` |
| `pagespeed()` signature + body | `:902-932` |
| `system_info()` signature + body | `:956-972` |
| Registrar | `class-main.php:472-474` |
| Bootstrap | `performance-optimisation.php:41-44` |
| Settings allowlist | `class-util.php:43-69` |
| Sanitize | `class-util.php:877-913` |
| Memoized `get_settings()` | `class-util.php:145-157` |
| `Cache::clear_cache` / `get_cache_stats` | `class-cache.php:2030-`, `2184-` |
| `Cache::get_counts` / `optimize_table` / `TABLE_MAP` | `class-database-cleanup.php:42-52,842-925,1040-1088` |
| `Img_Converter::convert_image` + gates | `class-img-converter.php:113-130,319-686` |
| `Object_Cache` manager | `class-object-cache.php:86-361` |
| `Pagespeed::queue_scan` / `get_results` | `class-pagespeed.php:119-133,275-277` |
| `System_Info::get_all` | `class-system-info.php:65-77` |
| `Cron::trigger_preload` | `class-cron.php:84-87` |
| REST route table (25) | `class-rest.php:74-260` |
| REST perms nonce | `class-rest.php:357-361` |
| Build entries | `performance-optimisation.php:8` |

---

## 8. Recommendations (Out of Scope — Read-Only Note)

> Not enacted; listed for a follow-up PR.

- Emit JSON `--format` for `cache status`, `database counts`, `image status`, `system-info` (script-friendly).
- Add `--dry-run` to `database cleanup`, `--confirm` to destructive clears, `--tables` allowlist expansion.
- Complete `get_default_settings()` with missing 7 tabs and expose `--batch` on `image convert`.
- Expand `object-cache enable --mode/--nodes/--use-tls` parity with REST.
- Add `rum`, `activities`, `autoloaded_options`, `used_css/ccss` subcommands to close UI gaps.
- Add `tests/php/WppoCliCommandTest.php` (Brain Monkey + `WP_CLI::` function mocks) for exit-code coverage; wire into `composer test`.
- Document multisite `--url=<site>` requirement explicitly in help text.
- Fix `database optimize` denominator to use allowlisted count.

*Generated by read-only research; no production files modified beyond `docs/`.*
