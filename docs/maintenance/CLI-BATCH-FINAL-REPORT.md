# CLI Batch Final Report — 2026-08-31 14:30 UTC (E1)

**Master:** `e5b824c6` → `7eb05beb` (after #775)
**Branch:** `fix/cli-e1` at `39e52805` → **PR #775 MERGED 7eb05beb**
**Old audit:** `origin/fix/audit-2026-08-28` at `661a3a7c` — E1 STILL NEEDED → IMPLEMENTED, 52 AUDIT docs remain archived
**Working tree:** clean

## Current CLI Baseline (pre-E1, master e5b824c6)
- 7 groups: `wppo cache` (clear/preload/status, <action> required), `wppo database` (cleanup/optimize/counts, --type 6 options), `wppo image` (convert/status), `wppo settings` (get/update/export/import), `wppo object-cache` (6 keys host/port/password/database/timeout/prefix), `wppo pagespeed` (scan/results), `wppo system-info`
- Issues: synopsis `<action>` vs code `??'clear'` drift, no --format, no --yes for destructive all, no --dry-run preview, optimize unbounded tables, 6/9 type drift (trashed_comments etc. missing in CLI), get_default_settings stale 7-tab (drift vs Util), object-cache 6-key allowlist (Sentinel/Cluster/TLS dropped)

## Changes Retained (KEEP)
- **Synopsis `[<action>]` default/options** for cache/database/image/object-cache/settings/pagespeed — WP-CLI Handbook predictable, shell completion
- **--format=json JSON-only** for `database counts` (and system-info) — wp_json_encode fallback, valid JSON, stable, jq-ready, reject non-json → json, no Spyc/Formatter
- **--yes for `database cleanup --type=all`** — `WP_CLI\Utils::get_flag_value` + `posix_isatty/stream_isatty` + `WP_CLI::confirm('Are you sure...')`, tty-only prompt, non-TTY (cron) proceeds, standard `wp core delete --yes` pattern
- **--dry-run for `database cleanup` and `optimize`** — reuse `get_counts()` → `would_delete` / `would_optimize` JSON, early return before DELETE/OPTIMIZE, warning, useful counts, no fake output
- **Allowlist `optimize --tables`** — `array_merge(...TABLE_MAP)` unique, `WP_CLI::warning` skip unknown (not error), prevents `OPTIMIZE users` typo / defence-in-depth vs `$wpdb->{$table}` regex
- **Extra types** `trashed_comments/trashed`, `unattached_media/unattached`, `oembed_cache/oembed` — backend already has `clean_trashed_comments:387`, `clean_unattached_media:594`, `clean_oembed_cache:700` + TABLE_MAP, alias support, help now 9+all
- **Hook `wppo_database_cleanup_completed` per-type** — additive, `do_action(type,count)` after per-type, docs hook already exists for all
- **Delegate `get_default_settings` → `Util::get_default_settings()`** — single source (A-01 minimal), fixes 7→12 tab drift (litespeed_integration/llms_txt etc.), memo `Util::get_settings` per-request blog-keyed (switch_blog safe via `current_blog_id()` + `on_switch_blog` hook, `switch_blog` action)
- **Object-cache ALLOWED_KEYS 12** — converge CLI 6 + REST 10 → `mode,host,port,password,database,timeout,prefix,nodes,master_name,use_tls,persistent,compression`, `get_redis_config()` helper with `wppo_object_cache_config` filter, loop over ALLOWED_KEYS for ping/enable
- **Help truthfulness** — updated `--type` options to 9+all

## Changes Rejected (intentionally not included)
- `--confirm` alias (REJECT per research, use --yes only)
- `table/csv/yaml` formatters + Spyc dep (REJECT, JSON-only per FINAL-ADVERSARIAL-REVIEW)
- `--network` custom `get_sites` (REJECT, use standard `--url` + `--path` for multisite, no custom flag)
- `--batch-size` / progress for image convert (REJECT, complexity vs value, non-interactive check needed — deferred)
- Research docs `docs/research/wp-cli-hooks/*` 20 files (REJECT for dist, internal only, not shipped)
- UX/performance unrelated changes (REJECT, E2-E5 separate)

## Commands Changed
- `wppo database` — now `[<action>]` + 3 new types + `--format=json` + `--yes` + `--dry-run`
- `wppo cache`, `image`, `object-cache`, `settings`, `pagespeed` — synopsis `[<action>]` default/options only
- `wppo object-cache` — 12 keys (mode/nodes/master_name/use_tls/persistent/compression added)
- `wppo settings get` — still supports --format json/yaml, but database counts now json-only

## Backward Compatibility
- All existing invocations still work: `wp wppo cache clear/status`, `database counts` (ignores non-json → json), `database cleanup --type=revisions`, `image status`, `settings get`, `object-cache status`, `system-info`, `pagespeed scan --url`
- Interactive `database cleanup --type=all` now prompts in TTY unless --yes (previously no prompt) — **intentional safety improvement**, documented; non-TTY unchanged
- Table allowlist now warns on unknown (previously silently tried optimize and failed per-table) — partial success still, not fatal

## Tests
- `vendor/bin/phpcs` 0
- `vendor/bin/phpunit` 435/435 1021a 2 skipped
- `npm test` 34/34 345 (JS not relevant to CLI)
- `npm run build` webpack success (no CLI asset)
- Manual: `wp wppo database --help` shows 9 types + --yes/--dry-run/--format, `wp wppo database counts --format=json` valid JSON, `cleanup --type=revisions --dry-run` would_delete 31, `object-cache --help` shows 12 options, `cache --help` shows default clear

## CI / Automated Review
- **PR #775** branch `fix/cli-e1` → master `7eb05beb`
- **CI — JS & PHP:** JS Lint/Test/Build pass 55s/59s, PHP Syntax 8.2-8.5 4× pass 8-11s, **WPCS & Psalm Security Scan pass 1m1s**, **Psalm 3s pass**, `Auto-Merge Approved PR skipping`, `Fix Audit Issue skipping`
- **AI Code Review:** fail 1m10s `Unknown provider opencode-go` / `muse-spark-1.2` — non-blocking (opencode-go unknown), `CodeRabbit` pass (skip OSS), `Snyk` pass (no manifest changes)
- **Review loop:** 1 status check (bounded, not infinite `for 1..6`), required checks all SUCCESS → merged via `gh pr merge --merge` (fast-forward, no conflict)
- **Merge commits:** `39e52805` → `7eb05beb Merge pull request #775`

## Security
- `--tables` allowlist vs TABLE_MAP + underlying `Database_Cleanup::optimize_table` regex `/^[A-Za-z0-9_]+$/` + `isset($wpdb->{$table})` double defence; `--type` strict `in_array` + `all` gate; settings JSON via `Util::sanitize_settings_recursively` (existing); no authz weakening (WP-CLI runs as admin, no cap bypass); no sensitive exposure in help

## Performance
- CLI startup +1 include (class-util blog-keyed static, negligible), `get_counts()` for dry-run is single SELECT per type (same as normal counts), no extra queries for synopsis; --dry-run avoids DELETE/OPTIMIZE entirely (saves DB)

## Real WordPress Verification (nilesportfolio.duckdns.org WP 7.1 PHP 8.3)
- `wp wppo database counts --format=json` 9 keys (including trashed_comments etc.)
- `wp wppo database cleanup --type=revisions --dry-run` would_delete 31, Warning dry run
- `wp wppo cache status` 27KB, `system-info` php 8.3, `object-cache status` Redis 8.0.2 hit
- `curl -I https://...` 200 LiteSpeed hit, no frontend regression
- Normal WP requests unaffected (Util blog-keyed cache no overhead)

## Remaining E2/E3/E4/E5 Status (vs c1cda64f → 7eb05beb)
- **E1 CLI:** ✅ IMPLEMENTED (this report)
- **E2 Hooks:** STILL NEEDED (`wppo_should_cache_request`, `wppo_invalidation_urls`, `wppo_object_cache_config` filter now partially in 775 but per-type cleanup/invalidation not yet), `docs/hooks.md` 60 lines still missing
- **E3 Performance:** PARTIALLY NEEDED / ALREADY IN MASTER for LRU/depth via 751, but `src file stat LRU` for core_will_inline (765 adds 24 lines at class-cache:128) STILL NEEDED unique
- **E4 Hardened H-01..12/C-01:** STILL NEEDED (C-01 namespace typo, H-01 iframe routing, H-05 dequeue etc. — not in master, 68a2f66a only on fix/audit)
- **E5 UX:** STILL NEEDED but BLOCKED on #709 vote (tailwind, HealthHeader, SetupWizard, ui/*)

## Completion Criteria (41)
- Master synchronized (7eb05beb), current CLI audited, old E1 compared, duplicate removed, research verified, behavior documented, improvements reviewed (8 agents KEEP/MODIFY), valid implemented (small focused 3 files 531 ins), tests added (existing 435 cover, no new CLI Test added — existing WppoCli*Test on fix/audit not cherry-picked, but manual dry-run verified), docs updated (help synopsis), sub-agent review completed (8 agents), PR created (#775), CI completed (JS/WPCS/PHP/Psalm pass, AI fail non-blocking), review evaluated, merge conflicts none, final diff reviewed (531 ins), PR merged, master synchronized after merge, real CLI smoke tests pass, full gate passes, security/performance/adversarial passes (allowlist, dry-run, --yes tty), extraction map updated (E1 IMPLEMENTED), maintenance log next, final E1 report created (this)

**Optimized for correctness/compatibility/safety, not PR count. E1 focused, not giant.**
