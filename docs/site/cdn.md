# CDN and Edge Cache

Hub guide: serving and purging through Cloudflare, Bunny, Varnish, and CDN rewrites.

## What it does

- **CDN URL rewriting:** serves static assets from your CDN domain(s) via
  per-mapping rules with attribute controls (`includes/Edge/class-cdn.php`).
- **Edge purge fan-out:** when this plugin clears its own page cache, it
  also purges the configured edge layers — Cloudflare, Bunny, Varnish —
  through one coordinated fan-out (`class-edge-purge-coordinator.php`) with
  per-event de-duplication.

Source: `includes/Edge/class-cdn.php`,
`includes/Edge/class-edge-purge-coordinator.php`,
`includes/Edge/class-cloudflare-purger.php`,
`includes/Edge/class-cdn-purger.php`, `readme.txt` (External Services).

## When to use it

- Visitors are geographically spread and static assets should come from
  nearby edge nodes.
- You already use Cloudflare/Bunny/Varnish and see stale HTML after
  publishing (edge outlives origin cache).
- You want the [Page Cache](page-cache.md) + [LiteSpeed](litespeed.md)
  story to extend past your origin server.

## Safe default

Edge integration is **off** until configured (`edge_cache.enabled: false`,
CDN mapping empty, `cdnURL: ''`). Purge calls happen **only** after the
plugin clears its own cache **and** credentials are stored (Cloudflare token
constant + Zone ID; Bunny key + Pull Zone ID; Varnish URLs). Nothing phones
home on install.

Source: `Settings_Store::get_default_settings()`, `readme.txt`.

## How to enable

1. Go to **Edge Cache / CDN** settings.
2. Add a CDN mapping (origin → CDN host) for assets; keep HTML on origin
   unless you know why you are moving it.
3. For purging: store the Cloudflare API token (`WPPO_CLOUDFLARE_API_TOKEN`)
   + Zone ID, or Bunny key + Pull Zone ID, or Varnish purge URLs.
4. Publish a test post and confirm the edge reflects it.

## What changes

- Asset URLs in HTML output are rewritten to the CDN host per mapping
  (srcset rewriting included).
- Post publish/update, settings saves, and manual clears trigger edge
  purges: Cloudflare supports full + single-file purges; Bunny is
  **all-or-nothing** (single-page clears skip it); Varnish receives HTTP
  `PURGE` to your configured endpoints (capped at 20, filterable).

## Compatibility

| Integration | Status | Notes |
|---|---|---|
| Cloudflare purge | `supported` | Full + single-file; token + Zone ID required |
| Bunny purge | `supported` | Full-zone only (`known limitation` for single-page purges) |
| Varnish purge | `supported` | Your endpoints, your responsibility; `PURGE` only |
| LiteSpeed + edge | `supported` | Purge sync covers LSCache; edge fan-out covers the rest — see [LiteSpeed](litespeed.md) |
| Combined-CSS inlining | `supported` | Return falsy from `wppo_inline_combined_css` when the CDN serves the combined file |

See [Compatibility](compatibility.md) for the full matrix.

## Verify

1. View source: asset URLs use the CDN host for mapped extensions.
2. Publish/update a post: origin **and** edge show the change.
3. Single-page clear: Cloudflare file purge fires; Bunny correctly skips
   (full purges only).
4. Remove credentials → no further purge calls (opt-out works).

## Undo

1. Clear the CDN mapping / set purge service to **None**.
2. Remove the stored token/key/URLs.
3. **Clear All Cache** at origin and purge the edge once manually.

## Troubleshooting

- **Edge still stale after publish:** credentials missing, wrong Zone/Pull
  ID, or Bunny single-page skip — see
  [Troubleshooting](troubleshooting.md#stale-cache).
- **Mixed-content warnings:** CDN host must serve HTTPS if your site does;
  fix the mapping scheme.
- **Assets bypass CDN:** mapping pattern/attribute rules exclude them —
  review `find_cdn_for_url` mapping diagnostics.

## FAQ

**Do I need a CDN?**
No. [Page Cache](page-cache.md) + [Images](images.md) already help most
sites. Add a CDN when your audience is global or your host recommends it.

**Does the plugin send visitor data to the CDN?**
Purge calls send only zone IDs, URLs being purged, and auth headers — no
visitor data or site content. Full disclosures in `readme.txt` External Services.

## Technical reference

- Rewrite: `includes/Edge/class-cdn.php` · Fan-out:
  `includes/Edge/class-edge-purge-coordinator.php`
- Adapters: `includes/Edge/class-cloudflare-purger.php`,
  `includes/Edge/class-cdn-purger.php`, `includes/Edge/class-edge-purger.php`
- Server rules (gzip/caching): `includes/Edge/class-server-rules.php`,
  `includes/Edge/class-htaccess-handler.php`
- Settings tabs: `edge_cache`, `file_optimisation` (cdnURL/cdnMapping)
