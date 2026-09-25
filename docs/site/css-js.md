# CSS and JavaScript Optimization

Beginner-first guide to minify, combine, and core-tweak settings.

## What it does

- **Minify** removes whitespace/comments from CSS, JS, and HTML so files
  are smaller.
- **Combine CSS** merges stylesheets into fewer files so the browser makes
  fewer requests (served file-first; can inline via core
  `wp_maybe_inline_styles()` within budget — filter `wppo_inline_combined_css`).
- **Core tweaks** optionally remove WordPress bloat: emojis, embeds,
  dashicons, RSD/WLW manifests, generator tag, heartbeat limits, and more.

Sources: `includes/minify/class-minify-policy.php`, `readme.txt`.

## When to use it

- PageSpeed flags "reduce unused CSS/JS" or "minify" opportunities.
- Your theme/plugins load many small stylesheets.
- You want to remove emoji/embed overhead you never use.

Do [Images](images.md) and [Page Cache](page-cache.md) first — they are
safer wins. Change one asset option at a time.

## Safe default

Everything aggressive is **off** by default: `minifyHTML`, `minifyJS`,
`minifyCSS`, `deferJS`, `delayJS`, `combineCSS`, `minifyInlineCSS`,
`minifyInlineJS` are all `false`. Comment stripping
(`removeHTMLComments: true`) is the only cleanup on by default, and
Elementor/builder safe modes are on (`elementorSafeMode: true`,
`delayJSBuilderPreset` / `delayJSCommercePreset` /
`delayJSInteractionPreset: true`).

Source: `Settings_Store::get_default_settings()` in
`includes/Settings/class-settings-store.php`.

## How to enable

1. Go to **Performance Optimisation → File Optimization**.
2. Enable **Minify CSS** first, save, purge, and test the frontend.
3. Then try **Minify JS**, then **Combine CSS** — one at a time.
4. Only then consider [Deferred JavaScript](deferred-js.md) or delay-JS.
5. Use the exclusion boxes (`excludeJS`, `excludeCSS`, `excludeCombineCSS`)
   for any file that misbehaves.

## What changes

- Enqueued scripts/styles are rewritten to minified/combined files served
  from the cache directory; originals on disk are untouched.
- HTML comments are stripped from the output buffer.
- Core tweaks remove the selected tags/scripts (e.g. no emoji script, no
  oEmbed discovery) — each toggle is independent.
- `?ver=` query strings are always preserved (cache-busting); the old
  "remove query strings" option was removed in 2.0.0.

## Compatibility

| Integration | Status | Notes |
|---|---|---|
| Elementor / Divi / builders | `supported` | `elementorSafeMode` steps combine-CSS aside on builder pages; test after each change |
| WooCommerce scripts | `supported` | `disableWooCartFragments` is opt-in and off by default; commerce preset keeps shop scripts eager |
| jQuery-dependent themes | `best effort` | Minify/defer can break old jQuery plugins — exclude them |
| CDN-hosted combined CSS | `supported` | Return falsy from `wppo_inline_combined_css` to disable inlining when a CDN serves the file |

See [Compatibility](compatibility.md) for the full matrix.

## Verify

1. Enable one option, save, **Clear All Cache**.
2. View source on the frontend: asset URLs should point at minified/combined
   files and the page should look and behave identically.
3. Click through menus, sliders, and forms — JS breakage shows up as dead
   buttons, not error pages.
4. Re-run [Monitoring](monitoring.md) to confirm payload shrank.

## Undo

1. Turn the option **off** and save.
2. **Clear All Cache**.
3. If a specific file breaks: add it to the exclusion list instead of
   disabling the whole feature.
4. Per-page kill switches exist in the post metabox (`_wppo_disabled_scripts`,
   `_wppo_disabled_styles`) for single-page rollbacks.

## Troubleshooting

- **Broken layout or dead buttons:** this is the classic minify/defer casualty —
  see [Troubleshooting](troubleshooting.md#broken-css-js) for the bisect routine.
- **Stale minified files after theme update:** builder-update watcher purges
  derived caches; if in doubt, clear all cache manually.
- **Combined CSS too large to inline:** core budget applies; it falls back to
  a file link automatically.

## FAQ

**Should I enable everything at once?**
No. One option at a time, test, then continue. That is the documented safe rollout.

**Will minification change my source files?**
No. Minified copies are generated into the cache; theme/plugin files are untouched.

## Technical reference

- Policy engine: `includes/minify/class-minify-policy.php`
- Used/Critical CSS siblings: [Used CSS](used-css.md), [Critical CSS](critical-css.md)
- Settings tab: `file_optimisation` · REST: `update_settings`, `server_rules`
