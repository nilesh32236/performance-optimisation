# Support Forum Response Workflow

> WordPress.org uses **Support Resolved Rate** as a ranking signal. Target **≥80% resolved**.

## Cadence

- **Check daily** — WordPress.org support forum: `https://wordpress.org/support/plugin/performance-optimisation/`
- **Acknowledge within 24h** — even if fix needs investigation: “Thanks for reporting — reproducing now, will update within 24h.”
- **Resolve or follow-up within 72h** — close thread with `resolved` tag when fix shipped or workaround confirmed.

Owner: `@nilesh32236` (primary). Backup: on-call via GitHub notification (see Action below).

## Workflow

1. **New thread** → GitHub Issue created by monitor (optional Action) or manual triage.
2. **Label** `support:needs-reply` → reply with template (see below) within 24h.
3. **Reproduce** on clean WP 6.2 / PHP 8.2 + default theme. Log steps in Issue.
4. **Fix or workaround** → PR with `Fixes #<issue>`. Link PR back to wp.org thread.
5. **Verify** reporter confirms fix → mark wp.org thread `resolved`.
6. **Weekly review** (Monday) — audit unresolved rate: `resolved / total last 30d`.

## Template Responses

### 1. Cache conflicts (another cache plugin / server cache)

> Thanks for reporting! This plugin generates static HTML via `advanced-cache.php` drop-in (`includes/class-advanced-cache-handler.php:1`). Please run **one** full-page cache at a time.
>
> **Steps:**
> 1. Deactivate other cache plugins (WP Super Cache, LiteSpeed, etc.) — check `wp-content/advanced-cache.php` header to confirm only `WPPO` marker remains.
> 2. Tools → Performance Optimisation → Clear All Cache.
> 3. If on LiteSpeed/Nginx, purge server cache as well.
>
> If issue persists, share: WP version, other cache plugin + version, and whether `WP_CACHE` is set in `wp-config.php`. We’ll reproduce within 24h.

### 2. Minification breaks layout (JS/CSS)

> Thanks for the report — minify can affect theme/page-builder scripts. Safe defaults are off (`includes/class-main.php:390` defer/delay gated).
>
> **Steps:**
> 1. File Optimization → **Exclude** the breaking file: add its handle or URL fragment to `Exclude from Minify/Defer` (e.g. `elementor-frontend`, `divi-custom`).
> 2. Clear cache and hard-reload (Ctrl+Shift+R).
> 3. Tell us the excluded handle that fixed it — we’ll add it to the default exclusion list if it’s common.
>
> Share: URL, theme + page builder, browser console errors, and the handle you had to exclude.

### 3. Redis Object Cache setup (TLS/Sentinel/Cluster)

> Thanks for trying Redis! This plugin ships its own drop-in (`templates/object-cache.php` → `wp-content/object-cache.php`) with standalone/Sentinel/Cluster (`includes/class-object-cache.php:66`).
>
> **Steps:**
> 1. Confirm Redis reachable: Tools → Object Cache → **Ping**.
> 2. Config stored in `wp-content/wppo-redis-config.php` — check host/port, TLS cert, Sentinel master name.
> 3. If `WP_REDIS_*` constants exist, they take precedence — remove or align them.
> 4. Share: Redis topology, `ping` output, and `wppo-redis-config.php` (redact password).
>
> We’ll reply with the exact config correction within 24h.

### 4. Generic acknowledgement

> Thanks for reporting — we check support daily and aim to acknowledge within 24h. Could you share: WP version, PHP version, theme, other active plugins, and steps to reproduce? We’ll update within 24h.

## Metrics

- **Resolved rate** = `resolved_threads / total_threads (30d)` — target ≥80%.
- Track via wp.org plugin dashboard → Support → Resolved.

## Optional Automation

A weekly GitHub Action can poll `https://wordpress.org/support/plugin/performance-optimisation/feed/` and open a GitHub Issue labeled `support` for new threads. See `.github/workflows/support-monitor.yml` (future, not required for 80% target).

## References

- `includes/class-main.php:630` speculation/cache hooks are opt-in — disable cache to isolate.
- `readme.txt:240` FAQ for common exclusions.
