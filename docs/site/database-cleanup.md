# Database Cleanup

Beginner-first guide to safe database tidying.

## What it does

Removes stale data in bounded batches: post revisions, auto-drafts, trashed
posts, spam/trashed comments, expired transients, orphan postmeta,
unattached media, oEmbed caches, and (standalone) Action Scheduler queue
data. Cleanups can run once or on a daily/weekly/monthly schedule, with
counts previewed before you commit.

Source: `includes/Database/class-database-cleanup.php`
(`CLEANUP_METHOD_MAP`), `includes/Database/class-database-cleanup-runner.php`,
`readme.txt`.

## When to use it

- The **Database** tab shows large counts (e.g. thousands of revisions).
- `wp-admin` or backups feel sluggish from table bloat.
- You want scheduled hygiene instead of one-off phpMyAdmin surgery.

Back up first. Cleanup deletes data — that is the point, so make it
reversible with a backup.

## Safe default

Nothing runs until you ask: there is no automatic deletion beyond the
schedule **you** configure, and every type shows counts
(`database_cleanup_counts`) before running. Autoload-bloat remediation
defaults to dry-run-first with apply/revert/revert-all and a threshold of
~1 KB (`autoloadThreshold: 1024`).

Source: `includes/Database/class-database-cleanup.php`,
`includes/Settings/class-settings-store.php`.

## How to enable

1. **Back up your database.**
2. Go to **Performance Optimisation → Database**.
3. Review counts per type, select what to clean, and run once.
4. Optionally set a **Daily / Weekly / Monthly** schedule.
5. For autoload bloat: run **dry_run**, review, then **apply** (revert
   available).

## What changes

- Selected rows are deleted in atomic batches (safe on large tables).
- Expired transients and oEmbed caches regenerate naturally afterwards.
- Action Scheduler cleanup delegates to the core queue cleaner (kept outside
  the table-delete map on purpose).
- Activity is logged to `wppo_activity_logs`.

## Compatibility

| Integration | Status | Notes |
|---|---|---|
| Revisions / drafts / trash / spam | `verified` | Core WordPress data with well-understood regeneration |
| Multisite | `supported` | `$wpdb`-prefixed queries are site-scoped |
| WooCommerce orders / products | `best effort` | Never bulk-clean shop data without a backup + staging check |
| Object cache | `supported` | Flush [Redis](redis.md) after large cleanups if counts look stale |

See [Compatibility](compatibility.md) for the full matrix.

## Verify

1. Note counts before, run cleanup, confirm counts drop and the
   `database_cleanup` response reports affected rows.
2. Browse the site and wp-admin — content, menus, and widgets intact.
3. For autoload: `autoloaded_options` shows the largest options shrinking;
   `autoload_remediate` revert restores if needed.

## Undo

There is no undelete — **the backup is the undo**. For autoload remediation
specifically, **revert / revert_all** restores previous values. For scheduled
cleanups, turn the schedule off to stop future runs.

## Troubleshooting

- **Counts don't drop:** another process recreates the rows (e.g. a plugin
  spamming transients) — find the producer before re-running. See
  [Troubleshooting](troubleshooting.md#database).
- **Site feels same:** database size rarely affects frontend TTFB as much as
  [Page Cache](page-cache.md) — measure with [Monitoring](monitoring.md).
- **Expired-transients export:** `expired_transients_export` gives a
  read-only JSON preview before the run.

## FAQ

**How often should I schedule cleanup?**
Monthly is enough for most sites; busy editorial or WooCommerce sites may
prefer weekly. Daily is rarely needed.

**Will cleanup delete my posts or products?**
Selected types only touch revisions, drafts, trash, spam, transients,
orphans, and caches — never published posts, products, or orders. Read the
type label before confirming.

## Technical reference

- Engine: `includes/Database/class-database-cleanup.php`
  (9 `CLEANUP_METHOD_MAP` types + `action_scheduler` + `all`)
- Dispatch/CLI/validation: `includes/Database/class-database-cleanup-runner.php`
- Settings tab: `database_cleanup` · REST: `database_cleanup`,
  `database_cleanup_counts`, `autoloaded_options`, `autoload_remediate`,
  `expired_transients_export` · CLI: `wp wppo database`
