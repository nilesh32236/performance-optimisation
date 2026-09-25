# Image Optimization

Beginner-first guide to faster images: lazy loading, WebP/AVIF, and LCP heroes.

## What it does

- **Lazy loading:** off-screen images, iframes, and videos load only when
  scrolled near, instead of all at once.
- **Next-gen formats:** converts uploads to WebP and AVIF (smaller files,
  same look) and serves the best format the browser supports.
- **LCP protection:** the hero (largest) image is never lazy-loaded and can
  be preloaded with `fetchpriority="high"` so Largest Contentful Paint stays fast.

Sources: `includes/Images/class-image-optimisation.php`,
`includes/Images/class-img-converter.php`, `readme.txt`.

## When to use it

- Pages have several images and feel slow on mobile.
- PageSpeed or [Monitoring](monitoring.md) flags image payload or LCP.
- You upload photos/screenshots regularly and want new uploads converted
  automatically in the background.

Start here before touching [Critical CSS](critical-css.md) or
[Deferred JavaScript](deferred-js.md) — images are usually the bigger win.

## Safe default

- Native lazy loading (`loading="lazy"`) is **on** by default
  (`lazyLoadNative: true`); the legacy JavaScript IntersectionObserver
  loader is opt-in (`lazyLoadImages: false`).
- LCP hero preload and LCP guardrails are **on**
  (`lcpHeroPreload: true`, `lcp_guardrails: true`).
- Format conversion (WebP/AVIF) is **opt-in** — nothing is converted until
  you enable it.

Source: `Settings_Store::get_default_settings()` in
`includes/Settings/class-settings-store.php`.

## How to enable

1. Go to **Performance Optimisation → Image Optimization**.
2. Lazy loading already works via native attributes. Optionally enable the
   JS lazy loader for background images or older behaviors.
3. To convert images: enable conversion, choose WebP, AVIF, or both, and
   click **Optimize Now**. New uploads convert automatically in the
   background (Action Scheduler `wppo_convert_image_background` + hourly cron).
4. Keep **Preload LCP / hero image** on unless you have a reason not to.

## What changes

- Off-screen media gets lazy-load behavior with lightweight SVG placeholders.
- Converted copies are stored in a `wppo/` directory next to the original;
  supporting browsers get WebP/AVIF, others fall back to the original.
- The first ~3 images (`lcp_first_n`) are treated as above-the-fold
  candidates and excluded from lazy loading.
- Small files under ~5 KB (`skipSmallThresholdBytes`) are skipped.

## Compatibility

| Integration | Status | Notes |
|---|---|---|
| Page builders (Elementor, Divi) | `supported` | Builder-aware handling; test galleries/sliders on staging |
| WooCommerce product images | `supported` | Test product galleries; conversion never deletes originals |
| LiteSpeed next-gen rewrite | `supported` | Optional `enableNextGenRewrite` in LiteSpeed mode — see [LiteSpeed](litespeed.md) |
| Old browsers | `best effort` | Browsers without WebP/AVIF get the original format automatically |

See [Compatibility](compatibility.md) for the full matrix.

## Verify

1. Open a post with images in an incognito window.
2. View source: off-screen images carry `loading="lazy"`; the hero image
   has a preload link with `fetchpriority="high"`.
3. After conversion, the **Image Optimization** tab shows converted counts;
   originals remain untouched next to the `wppo/` copies.
4. Re-run [Monitoring](monitoring.md) and compare LCP before/after.

## Undo

1. Turn **off** conversion and/or lazy loading and save.
2. Use **Delete optimised images** (`delete_optimised_image` endpoint) to
   remove the `wppo/` directory — originals are never modified.
3. **Clear All Cache** so pages serve the original markup again.

## Troubleshooting

- **Images don't lazy load:** check per-page exclusions and the
  below-fold renderer settings; builder templates may opt out — see
  [Troubleshooting](troubleshooting.md#images).
- **Broken picture element:** disable conversion, purge, and re-enable one
  format at a time to isolate the culprit.
- **LCP got worse:** make sure hero preload is on and the hero is not in
  the lazy-load list — see [Preload](preload.md).

## FAQ

**Will conversion delete my originals?**
No. Converted files live in a separate `wppo/` directory; deleting
optimised images only removes the copies.

**WebP or AVIF?**
AVIF is smaller but slower to encode; WebP is the safe default. You can
enable both — supporting browsers pick AVIF first (`avifFirst: true`).

## Technical reference

- Frontend behavior: `includes/Images/class-image-optimisation.php`
- Conversion engine (GD/Imagick, deferred shutdown commits):
  `includes/Images/class-img-converter.php`
- Settings tab: `image_optimisation` · REST: `optimise_image`,
  `delete_optimised_image`, `image_job_status`
