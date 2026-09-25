# Critical CSS

Beginner-first guide to Critical CSS (above-the-fold styles).

## What it does

Extracts the CSS needed for above-the-fold content and inlines it, so the
first paint does not wait for full stylesheets. Full stylesheets still load
afterwards. Generation is safe-by-default: dry-run previews stage to a
`.staged` sibling (bytes/checksum/changed-vs-live), promote keeps a
last-good copy, and a post-apply health gate auto-restores on failure.

Source: `includes/CSS/class-critical-css.php`,
`includes/CSS/class-ccss-store.php`,
`includes/CSS/class-ccss-generator.php`, `readme.txt`.

## When to use it

- PageSpeed flags "eliminate render-blocking CSS" after you have done
  [CSS and JS](css-js.md) minification.
- LCP is slow because stylesheets block the first paint.
- You are comfortable previewing a staged result before promoting it.

Try [Used CSS](used-css.md) first if you want the milder option.

## Safe default

Critical CSS is **off** by default (`criticalCSS: false`). Guardrails
default on: commerce exclusion (`ccssCommerceExclude: true`), checksum
regeneration (`ccssChecksumRegen: true`), RUM priority (`ccssRumPriority:
true`), size cap ~20 KB (`ccssMaxSize: 20480`), queue cap 5, max 5 retries,
and builder templates (`elementor_library`, `fl-builder-template`) excluded.

Source: `Settings_Store::get_default_settings()` in
`includes/Settings/class-settings-store.php`.

## How to enable

1. Go to **File Optimization → Critical CSS** and enable it.
2. Click **Regenerate** with `dry_run` to preview the staged result
   (check bytes, checksum, changed-vs-live).
3. **Promote** the staged file only when the preview looks right.
4. Regenerate endpoints also accept `promote` / `rollback` / `health` flags.

## What changes

- A per-template critical-CSS file is generated and inlined (within the
  ~14 KB inline budget, `ccssInlineBudgetKb`); overflow falls back to file
  delivery.
- Commerce pages are excluded by default; excluded post types are skipped.
- Failed generations retry (up to `ccssMaxRetries`) and are status-tracked
  (`ccss_status` endpoint).

## Compatibility

| Integration | Status | Notes |
|---|---|---|
| WooCommerce / commerce pages | `supported` | Excluded by default (`ccssCommerceExclude`) |
| Elementor / Beaver templates | `supported` | Excluded post types by default; builder-drift requeue regenerates on builder updates |
| CDN-served CSS | `supported` | File-first delivery works behind a CDN — see [CDN](cdn.md) |
| Highly dynamic themes | `best effort` | Per-template variants help; test each template |

See [Compatibility](compatibility.md) for the full matrix.

## Verify

1. Dry-run, inspect the staged diff, then promote.
2. Load the frontend in incognito: above-the-fold content should be styled
   immediately with no flash of unstyled content (FOUC).
3. Check `ccss_status` for a healthy status and the rollout slot state.
4. Compare LCP in [Monitoring](monitoring.md).

## Undo

1. Turn **off** Critical CSS and save — full stylesheets serve normally.
2. Use **Rollback** to restore the last-good file if a promotion regressed.
3. **Regenerate** (or purge used CSS + page cache together via
   `purge_used_css_cache`) to clear derived output.

## Troubleshooting

- **Unstyled flash or broken layout:** roll back to last-good, then
  re-preview — see [Troubleshooting](troubleshooting.md#critical-css).
- **Generation never finishes:** check `ccss_status` retry counts and the
  queue cap; preview fetch timeout is filterable
  (`wppo_css_preview_fetch_timeout`).
- **Builder pages look wrong:** they are excluded by default — if you
  removed the exclusion, add it back.

## FAQ

**Critical CSS vs Used CSS — which one?**
Used CSS ([Used CSS](used-css.md)) trims whole stylesheets per URL and is
milder. Critical CSS inlines above-the-fold rules for the fastest paint but
needs preview/promote care.

**Does it work with combine-CSS?**
Yes, but enable and test [CSS and JS](css-js.md) combining separately first.

## Technical reference

- Facade/output: `includes/CSS/class-critical-css.php`
- Storage/status: `includes/CSS/class-ccss-store.php`
- Generation lifecycle: `includes/CSS/class-ccss-generator.php`
- Settings tab: `file_optimisation` (ccss* keys) · REST: `regenerate_ccss`,
  `ccss_status`
