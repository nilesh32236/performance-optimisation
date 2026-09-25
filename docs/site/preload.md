# Preload, Preconnect, and Cache Warm-up

Beginner-first guide to making the next visit (and the first paint) faster.

## What it does

- **Cache warm-up (crawler):** visits your pages in the background so the
  [Page Cache](page-cache.md) is already built when visitors arrive.
- **Preconnect / DNS-prefetch:** opens early connections to third-party
  origins (fonts, embeds) to cut handshake time.
- **Preload:** fetches hero images, critical fonts, and critical CSS early
  with the right priority (`fetchpriority`); LCP heroes can be
  auto-discovered.

Sources: `includes/Settings/class-settings-store.php` (preload_settings),
`includes/Scheduler/class-cron.php`, `readme.txt`.

## When to use it

- First visitors (or PageSpeed lab runs) are slow but repeat visits are fast
  — the cache is cold.
- LCP images or webfonts load late in [Monitoring](monitoring.md).
- Third-party origins (Google Fonts, video embeds) add DNS/connection delay.

## Safe default

Everything here is **off** by default: `enablePreloadCache: false`,
`preloadSitemap: false`, `autoLcpPreload: false`,
`autoDiscoverFonts: false`, `enableSpeculationRules: false`. Commerce paths
(`my-account/*`, `cart/*`, `checkout/*`) are excluded from warm-up by default.

Source: `Settings_Store::get_default_settings()` in
`includes/Settings/class-settings-store.php`.

## How to enable

1. Go to **Performance Optimisation → Preload**.
2. Enable **Cache warm-up**; the 5-hourly cron processes ~200 posts per
   batch with a random 0–30 min delay per page.
3. Optionally enable **Sitemap preload** (`preloadSitemap`) so
   `wp-sitemap.xml` URLs are scheduled (500-URL discovery cap, 15s budget).
4. Add hero/font preloads for your LCP element, or enable LCP auto-preload
   once [Images](images.md) are stable.

## What changes

- WP-Cron single events (`wppo_generate_static_url`) fetch pages in the
  background to fill the static cache.
- `<link rel="preconnect">`, DNS-prefetch, and preload hints are emitted
  (preconnect via core `wp_resource_hints`).
- Speculation rules (if enabled) default to conservative `prefetch` mode,
  RUM-gated, limited to ~2 top URLs — never aggressive prerender by default.

## Compatibility

| Integration | Status | Notes |
|---|---|---|
| WooCommerce | `verified` | Cart/checkout/account excluded from warm-up by default |
| Shared hosting cron | `supported` | Warm-up relies on WP-Cron; low-traffic sites may need a real cron trigger |
| LiteSpeed crawler | `supported` | Either crawler can warm; avoid running both at full concurrency — see [LiteSpeed](litespeed.md) |
| Plugins needing exact hit counts | `best effort` | Crawler visits are real HTTP hits; exclude analytics-sensitive paths if needed |

See [Compatibility](compatibility.md) for the full matrix.

## Verify

1. Enable warm-up, then check `preload_status` (honest bounded progress).
2. If stalled, use `preload_resume`.
3. Load a warmed page in incognito — it should serve the cached copy
   immediately (see [Page Cache](page-cache.md)).
4. View source: preload/preconnect hints present for configured assets.

## Undo

1. Turn **off** warm-up / sitemap preload / auto-preloads and save.
2. Scheduled single events drain harmlessly; **Clear All Cache** if you want
   warmed entries gone too.

## Troubleshooting

- **Preload stuck:** check `preload_status`, then `preload_resume` — see
  [Troubleshooting](troubleshooting.md#preload).
- **Server load spikes:** reduce batch concurrency; the random per-page
  delay already spreads load.
- **Fonts still slow:** confirm the font URL actually matches a configured
  preload; auto-discovery is opt-in.

## FAQ

**Warm-up vs preload — what's the difference?**
Warm-up builds cached pages ahead of time (server work). Preload hints tell
the browser to fetch specific assets early (browser work). They complement
each other.

**Will speculation rules break my shop?**
Defaults are conservative prefetch with commerce exclusions. Keep RUM gating
on and test shop flows.

## Technical reference

- Scheduler: `includes/Scheduler/class-cron.php`
  (`get_sitemap_urls()`, `schedule_sitemap_url_jobs()`)
- Preload buffer coordination:
  `includes/Core/class-preload-buffer-coordinator.php`
- Settings tab: `preload_settings` · REST: `preload_status`, `preload_resume`
