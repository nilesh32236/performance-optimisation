# Used CSS

Beginner-first guide to per-URL Used CSS.

## What it does

Builds a trimmed stylesheet per URL containing only the rules that page
actually uses, instead of serving full theme/plugin stylesheets. Delivery
defaults to file mode. Regeneration is staged (dry-run → promote) with
last-good retention and a health gate, like [Critical CSS](critical-css.md).

Source: `includes/CSS/class-used-css.php`, `readme.txt`.

## When to use it

- PageSpeed flags "reduce unused CSS" and full stylesheets dominate payload.
- You want CSS savings with less risk than Critical CSS inlining.
- Builder content changes often — the builder-drift requeue regenerates
  affected URLs automatically.

## Safe default

Used CSS removal is **off** by default (`removeUnusedCSS: false`), with the
regression guard **on** (`unusedCSSRegressionGuard: true`, 20% threshold),
delivery mode `file`, queue cap 50, and RUM priority on.

Source: `Settings_Store::get_default_settings()` in
`includes/Settings/class-settings-store.php`.

## How to enable

1. Go to **File Optimization → Remove Unused CSS** and enable it.
2. Preview with `dry_run` on key templates (home, post, page, product).
3. Promote when previews look right; add persistent exclusions
   (`excludeUnusedCSS`, `unusedCSSSafelistExtra`) for third-party rules that
   must survive trimming.
4. Keep the regression guard on.

## What changes

- Each URL gets a derived used-CSS file served in place of (or alongside)
  the full stylesheets, per `usedCSSDeliveryMode`.
- Builder updates trigger purge + requeue via the builder-update watcher, so
  edited pages regenerate instead of serving stale trimmed CSS.
- `purge_used_css_cache` purges page cache + used CSS together (optional
  `path` for a single page).

## Compatibility

| Integration | Status | Notes |
|---|---|---|
| Elementor / Divi / Bricks / WPBakery | `supported` | Builder-update watcher + drift logging regenerate on builder edits |
| WooCommerce | `supported` | Test shop flows; exclude dynamic widget CSS that appears only after interaction |
| CDN | `supported` | File delivery works behind a CDN — see [CDN](cdn.md) |
| JS-injected markup | `best effort` | Rules used only by late-injected DOM may be trimmed — safelist them |

See [Compatibility](compatibility.md) for the full matrix.

## Verify

1. Dry-run then promote on one template first.
2. Load affected templates in incognito and compare against the unoptimized
   view (staging or preview): styling must be identical.
3. Edit a builder page and confirm it regenerates (drift log).
4. Compare CSS payload in [Monitoring](monitoring.md).

## Undo

1. Turn **off** Remove Unused CSS and save.
2. Run `purge_used_css_cache` (or **Clear All Cache**) to drop derived files.
3. Roll back to the last-good file if a promotion regressed.

## Troubleshooting

- **Missing styles after enabling:** safelist the missing selectors, then
  regenerate — see [Troubleshooting](troubleshooting.md#used-css).
- **Builder edit not reflected:** check the drift log / requeue status, then
  regenerate that URL (`used_css_regenerate` with `path`).
- **Coupled stale pages:** purge page cache + used CSS together rather than
  one at a time.

## FAQ

**Used CSS vs Critical CSS?**
Used CSS trims per-URL stylesheets (milder). Critical CSS inlines
above-the-fold rules (faster paint, more care). You can use either or both —
enable separately and test.

**Will it break checkout widgets?**
Dynamic-only rules can be trimmed; exclude dynamic widget CSS via the
safelist before promoting on shop templates.

## Technical reference

- Engine: `includes/CSS/class-used-css.php`
- Settings tab: `file_optimisation` (unusedCSS* keys) · REST:
  `used_css_regenerate`, `purge_used_css_cache`
