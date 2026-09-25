# LiteSpeed Coexistence

Beginner-first guide to running this plugin on LiteSpeed / OpenLiteSpeed.

## What it does

Detects LiteSpeed/OLS servers and LSCache and negotiates who owns what:
four modes (**Auto**, **WPPO**, **LiteSpeed**, **Standalone**), purge sync so
both caches invalidate together, native `X-LiteSpeed-*` header protocol,
per-page/per-post-type TTL overrides, a background crawler, and an ESI
bridge for dynamic fragments.

Source: `includes/Integrations/class-litespeed-integration.php`,
`includes/Integrations/class-litespeed-crawler.php`,
`includes/Integrations/class-litespeed-esi.php`, `readme.txt`.

## When to use it

- Your host runs LiteSpeed or OpenLiteSpeed (ask your host or check
  [Monitoring](monitoring.md) → System Info).
- LSCache is (or is not) active and you want the two caches to stop
  fighting.
- You need ESI punch-holing for carts/nonces on LSWS Enterprise.

If you are on Apache/Nginx with no LiteSpeed, skip this page.

## Safe default

Mode is **auto** (`mode: 'auto'`) — the plugin detects the server and
LSCache and picks the safe owner. Purge sync is **on** (`purgeSync: true`)
with loop protection (WPPO→LS→WPPO guard). ESI is **off**
(`esi.enabled: false`); variant vary-groups (guest/mobile/webp) default off;
crawler concurrency defaults to 2.

Source: `Settings_Store::get_default_settings()` in
`includes/Settings/class-settings-store.php`.

## How to enable

1. Go to **Performance Optimisation → LiteSpeed** (or Advanced Features).
2. Leave **Auto** unless you have a reason: choose **WPPO** to let this
   plugin own caching, **LiteSpeed** to defer to LSCache, **Standalone** for
   neither integration.
3. Keep **Purge Sync** on so a post update clears both caches.
4. Enable ESI only on LSWS Enterprise after reading the ESI notes below.

## What changes

- The plugin emits/reads `X-LiteSpeed-*` headers (TTL, purge, vary) per the
  header protocol; purge events fan out to LSCache.
- Per-page and per-post-type TTL overrides tune edge retention.
- The background crawler warms the cache across a variant matrix with
  concurrency and load limits (SSRF-hardened same-host validation).

## Compatibility

| Integration | Status | Notes |
|---|---|---|
| LiteSpeed / OLS detection + modes | `supported` | Auto/WPPO/LiteSpeed/Standalone in `class-litespeed-integration.php` |
| Purge sync | `supported` | Loop-guarded; both caches invalidate together |
| ESI on LSWS Enterprise | `supported` | Nonce-gated punch-holes with per-IP throttle; fail-closed |
| ESI on OpenLiteSpeed | `known limitation` | OLS has no ESI — bridge reports disabled, AJAX fallback applies |
| Next-gen rewrite / Brotli | `supported` | Opt-in toggles (`enableNextGenRewrite`, `enableBrotli`) |

See [Compatibility](compatibility.md) for the full matrix.

## Verify

1. Check the detected server and effective mode in the settings screen.
2. Update a post and confirm both this plugin's cache and LSCache show the
   change (purge sync works).
3. Inspect response headers for the expected `X-LiteSpeed-*` values.
4. For ESI: confirm dynamic fragments (cart count, nonce) update while the
   surrounding page stays cached.

## Undo

1. Set mode back to **Auto** (or **Standalone** to fully disengage).
2. Turn **off** ESI and the crawler.
3. **Clear All Cache** on both sides (this plugin + LSCache).

## Troubleshooting

- **Pages fight (one cache stale):** purge sync may be off — re-enable and
  clear both caches. See [Troubleshooting](troubleshooting.md#litespeed).
- **ESI fragments stale on OLS:** expected — OLS has no ESI; use the AJAX
  fallback path.
- **Crawler load:** lower concurrency; same-host redirect policy already
  bounds fetching.

## FAQ

**Does this replace LiteSpeed Cache?**
No. It coexists with it — modes and purge sync define ownership so only one
layer serves each page.

**Which mode should I pick?**
Auto, unless your host or an administrator tells you otherwise. Switch only
to fix a concrete conflict, then verify purge sync.

## Technical reference

- Integration: `includes/Integrations/class-litespeed-integration.php`
  (`MODE_AUTO/WPPO/LITESPEED/STANDALONE`)
- Crawler: `includes/Integrations/class-litespeed-crawler.php`
- ESI: `includes/Integrations/class-litespeed-esi.php`
- Settings tab: `litespeed_integration` · related: [CDN](cdn.md),
  [Page Cache](page-cache.md)
