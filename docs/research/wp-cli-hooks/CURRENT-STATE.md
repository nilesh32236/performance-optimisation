# CURRENT-STATE — wp-cli-hooks (Aggregate Stub)

**Date:** 2026-08-28 · **Scope:** `docs/research/wp-cli-hooks/`

## Summary

`PerformanceOptimise\Inc\WPPO_CLI_Command` (`includes/class-wppo-cli-command.php:1-973`, registered at `includes/class-main.php:472-474`, `@since 1.9.0`) exposes **7** public subcommands under `wp wppo` (all `@when after_wp_load`): `cache`, `database`, `image`, `settings`, `object-cache`, `pagespeed`, `system-info`. Each delegates to a service class (`Cache`, `Database_Cleanup`, `Img_Converter`, `Util`, `Object_Cache`, `Pagespeed`, `System_Info`) and uses `WP_CLI::{log,success,error,warning}` with `wp_json_encode(PRETTY+SLASHES)` for JSON output. No `--dry-run`, `--format` (except `settings get`), progress bar, confirmation, or explicit multisite `switch_to_blog` handling. No tests exist at `tests/php/*` for CLI.

## Detail

Full evidence-based deep dive (every claim `file:line`-cited) is in [`WP-CLI-CURRENT.md`](./WP-CLI-CURRENT.md). Sections:

- §1 Registration & bootstrap, `@when` policy, top-level help, command tree
- §2 Cross-cutting: permissions (none; any `wp` invoker), exit codes, output formatting, multisite, performance/memory, validation table
- §3 Per-subcommand: `cache` (`class-wppo-cli-command.php:43-124`), `database` (`126-294`), `image` (`296-391`), `settings` (`392-750` + helpers `451-522,864-872`), `object-cache` (`752-856`), `pagespeed` (`874-932`), `system-info` (`934-972`) — each with Purpose / Source file:line / Method / Args / Defaults / Validation / Permissions / Dependencies / Output / Side effects / Performance / Tests / Docs
- §4 Defects (allowlist gaps, helper omissions, path divergences)
- §5 Admin UI → CLI gap matrix (Dashboard, FileOptimization, PreloadSettings, ImageOptimization, DatabaseCleanup, ObjectCache, PluginSetting, RUM, cron/health/reset)
- §6 Verbatim synopsis dump
- §7 Evidence index
- §8 Recommendations (not enacted — read-only research)

## For Aggregation

This stub will be merged into the repo-wide `docs/research/CURRENT-STATE.md` by an aggregator agent. Keep this file as-is; do not duplicate the full 600-line evidence there — link to `WP-CLI-CURRENT.md`.

## One-line per subcommand

- `wp wppo cache {clear|preload|status} [--page=<url>]` — static HTML cache + preload (`class-wppo-cli-command.php:75`)
- `wp wppo database {cleanup|optimize|counts} [--type=…] [--tables=…]` — batched deletes + OPTIMIZE TABLE (`:174`)
- `wp wppo image {convert|status} [--format=webp|avif]` — next-gen conversion queue (`:321`)
- `wp wppo settings {get|update|export|import} [<tab>] [--settings=<json>] [--file=<path>] [--format=json|yaml]` — `wppo_settings` CRUD with `ALLOWED_SETTINGS_KEYS` guard (`:573` / `:451`)
- `wp wppo object-cache {status|ping|enable|disable|flush} [--host/--port/--password/--database/--timeout/--prefix]` — Redis drop-in (`:801`) (6-key allowlist, vs REST 10)
- `wp wppo pagespeed {scan|results} [--url=<url>] [--strategy=mobile|desktop]` — async Action Scheduler scans (`:902`)
- `wp wppo system-info [<group>]` — `System_Info::get_all()` dump (`:956`, groups `php,database,wordpress,wp_constants,server,cache,infrastructure,+ litespeed/opcache`)

*Research-only, no production edits. See `WP-CLI-CURRENT.md` for file:line citations.*
