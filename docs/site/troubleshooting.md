# Troubleshooting

Hub guide: diagnose fast, roll back safely.

## What it does

Collects the rollback-first fixes for every feature in one place: broken
CSS/JS, stale cache, WooCommerce, Redis, Critical CSS, Used CSS, LiteSpeed,
preload, images, database, and monitoring. Status labels below match the
feature pages: `verified` means covered by tests or a self-test,
`supported` means a named integration path exists, `best effort` is guidance
only, and `known limitation` documents what will not work.

Rollback rule for every section below: **disable the feature → Clear All
Cache → re-test**. Rollbacks never need a new setting or code change.

## Broken CSS/JS (layout wrong, buttons dead) {#broken-css-js}

Most likely cause: minify, combine, defer, or delay changed script/style
execution.

1. Turn **off** Delay JS, purge, re-test. Still broken? Turn off Defer JS.
   Still broken? Turn off Combine CSS, then Minify JS, then Minify CSS —
   one at a time until the culprit is found.
2. Re-enable everything except the culprit layer, then narrow to the file
   via the exclusion boxes (`excludeJS`, `excludeCSS`, `excludeDeferJS`,
   `excludeDelayJS`, `excludeCombineCSS`).
3. For a single page, use the post-metabox kill switches
   (`_wppo_disabled_scripts`, `_wppo_disabled_styles`).
4. Check the staged/preview state for [Critical CSS](critical-css.md) /
   [Used CSS](used-css.md) before promoting.

Sources: `includes/minify/class-minify-policy.php`,
`includes/CSS/class-critical-css.php`, `includes/CSS/class-used-css.php`.

## Stale cache (changes don't appear) {#stale-cache}

1. **Clear All Cache** in this plugin first.
2. Check the other layers in order: LSCache ([LiteSpeed](litespeed.md)),
   edge/CDN ([CDN](cdn.md)), host cache, browser cache.
3. Confirm the URL is actually cacheable (logged-in, cart/checkout/account,
   and excluded paths never serve static cache).
4. For builder edits, check the builder-drift log — derived CSS caches
   requeue automatically but take a moment.

Source: `includes/Cache/class-cache-invalidator.php`.

## WooCommerce (cart, checkout, fragments wrong) {#woocommerce}

1. Confirm safe mode is **on** and `cart`, `checkout`, `my-account` remain
   excluded (plus your translated/custom slugs).
2. Run the **cache self-test** (`woo_cache_self_test`) and follow its
   remediation, including the safe-mode call to action.
3. Disable Delay JS (commerce preset) and Woo asset removal, purge, re-test
   the full guest + logged-in purchase flow on staging.

Source: `includes/Integrations/class-woo-detect.php`.

## Redis (status red, errors, stale objects) {#redis}

1. `redis_missing: true` → the PHP Redis extension is not installed: a host
   task, not a plugin bug.
2. Reachable but failing → wrong host/port/password, TLS mismatch, or
   firewall; check status telemetry with `WP_DEBUG`.
3. After migrations or mass cleanups, **Flush** once.
4. Repeated failures trip the circuit breaker (auto-disable + admin notice)
   by design — fix connectivity, then re-enable.

Source: `includes/Cache/class-object-cache.php`.

## Critical CSS (FOUC, wrong above-the-fold styles) {#critical-css}

1. **Rollback** to the last-good file immediately.
2. Re-run with `dry_run`, inspect bytes/checksum/changed-vs-live, then
   promote only when clean.
3. Confirm commerce pages and builder templates are still excluded.
4. Check `ccss_status` retries/queue state; preview fetch timeout is
   filterable (`wppo_css_preview_fetch_timeout`).

Source: `includes/CSS/class-ccss-store.php`,
`includes/CSS/class-ccss-generator.php`.

## Used CSS (missing styles, stale trimmed CSS) {#used-css}

1. Turn **off** Remove Unused CSS, run `purge_used_css_cache`, re-test.
2. Safelist the missing selectors (`unusedCSSSafelistExtra`), regenerate
   that URL (`used_css_regenerate` with `path`), then promote.
3. Keep the regression guard on (`unusedCSSRegressionGuard`, 20% threshold).

Source: `includes/CSS/class-used-css.php`.

## LiteSpeed conflicts {#litespeed}

1. Set mode to **Auto**, keep **Purge Sync** on, clear both caches.
2. Only one full-page cache owner per page — use **Standalone** to disengage
   this side entirely while diagnosing.
3. ESI issues on OLS are expected (no ESI there); use the AJAX fallback.

Source: `includes/Integrations/class-litespeed-integration.php`.

## Preload / warm-up stalled {#preload}

1. Check `preload_status` (bounded, honest progress).
2. Run `preload_resume` to continue a stalled queue.
3. Reduce concurrency on constrained hosting; low-traffic sites may need a
   real cron trigger for WP-Cron.

Source: `includes/Scheduler/class-cron.php`.

## Images not converting {#images}

1. Check `image_job_status` for queue state and failures.
2. Confirm GD or Imagick is available; AVIF needs newer libraries — fall
   back to WebP-only if encodes fail.
3. Originals are never modified; **Delete optimised images** removes only
   the `wppo/` copies.

Source: `includes/Images/class-img-converter.php`.

## Database cleanup {#database}

1. Nothing is un-deletable except via your **backup** — that is why backup
   comes first on the [Database Cleanup](database-cleanup.md) page.
2. Autoload remediation has **revert / revert_all**.
3. If counts regrow, find the producing plugin before re-running.

Source: `includes/Database/class-database-cleanup.php`.

## Monitoring confusion {#monitoring}

1. Compare medians across runs, same URL + strategy — single lab runs vary.
2. Note warmed vs cold cache state when comparing.
3. Trust RUM (field) for user experience, PageSpeed (lab) for fix lists.

Sources: `includes/Insight/class-telemetry.php`,
`includes/Insight/class-pagespeed.php`, `includes/Insight/class-rum.php`.

## Getting help

Include: plugin version, WordPress/PHP versions (System Info), what you
changed last, the rollback steps you tried, and (for shops) the self-test
output. Export settings from the Tools tab so helpers can reproduce your
setup.
