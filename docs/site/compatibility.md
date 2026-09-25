# Compatibility

Hub guide: what works with what, stated honestly.

## What it does

This page is the single honest matrix for third-party environments. Labels:

- `verified` — covered by automated tests or a built-in self-test.
- `supported` — a named integration path exists in code; test on staging.
- `best effort` — coexistence guidance only, no dedicated path.
- `known limitation` — documented non-support with a workaround or opt-out.

No blanket "fully compatible" claim is made anywhere. Test caching,
minification, defer/delay, and image handling on staging before production.

Sources: `docs/growth/WORDPRESS-ORG.md` (classification),
`includes/Integrations/class-woo-detect.php`,
`includes/Cache/class-object-cache.php`,
`includes/Integrations/class-litespeed-integration.php`, `readme.txt`.

## When to use it

- Before installing alongside WooCommerce, a builder, LiteSpeed, Redis, a
  CDN, or SEO plugins.
- When planning a staging test checklist.
- When a conflict appears and you need the fastest safe rollback.

## Safe default

All aggressive integrations default to safe: WooCommerce safe mode on,
commerce pages excluded from cache and warm-up, builder safe modes on,
delay-JS safe mode on with gateways/consent eager, Critical CSS commerce
exclusion on. See each feature page: [Page Cache](page-cache.md),
[CSS and JS](css-js.md), [Deferred JavaScript](deferred-js.md),
[Critical CSS](critical-css.md), [Preload](preload.md).

## Full matrix

| Integration | Status | Detail |
|---|---|---|
| Elementor | `supported` | Builder-aware asset handling + exclusions; Elementor-safe mode steps Combine CSS aside on builder pages; staging test required |
| Divi | `supported` | Same builder safeguards; test layout/interactions after minify/defer/delay |
| Beaver Builder / WPBakery / Bricks | `supported` | Exclusions + builder-update purge watcher with drift logging |
| Astra / GeneratePress / Kadence / OceanWP / Blocksy / Twenty Twenty-Four | `supported` | Safeguards exist; no blanket version guarantee |
| WooCommerce | `supported` | Dynamic route detection, cart/checkout/account cache exclusions, Store API + `wc-ajax` awareness, optional self-test (`woo_cache_self_test`), safe-mode toggle; asset removal optional and off by default |
| Yoast / Rank Math / All in One SEO / SEOPress | `best effort` | Coexistence guidance; no dedicated integration path |
| LiteSpeed / OpenLiteSpeed + LSCache | `supported` | Auto/WPPO/LiteSpeed/Standalone modes, header protocol, purge sync — see [LiteSpeed](litespeed.md); ESI Enterprise-only (`known limitation` on OLS) |
| Redis | `supported` | With prerequisite: PHP Redis extension + reachable server — see [Redis](redis.md) |
| CDN (Cloudflare / Bunny / Varnish) | `supported` | Optional integration; credentials-gated; Bunny single-page purge unsupported — see [CDN](cdn.md) |
| Other full-page cache plugins | `known limitation` | One full-page cache at a time; foreign drop-ins are never overwritten |
| Other object-cache drop-ins | `known limitation` | Only one `object-cache.php` can exist |
| Old browsers (no WebP/AVIF) | `best effort` | Automatic fallback to original formats |

## WooCommerce checklist (staging)

1. Run the **cache self-test** and follow its plain-language remediation.
2. Walk cart → checkout → account → order confirmation as a guest and logged in.
3. Confirm dynamic fragments (cart count, totals, nonces) update.
4. Only then enable aggressive asset rules.

Source: `includes/Integrations/class-woo-detect.php`.

## Verify

1. For each integration you use, run its feature-page Verify steps on staging.
2. Record what you tested (theme version, builder version, Woo version) —
   that record is your compatibility proof, not a marketing claim.

## Undo

Per-feature rollbacks: disable the feature → purge caches (see
[Troubleshooting](troubleshooting.md)). For shops, the WooCommerce safe-mode
toggle plus the self-test give the fastest path back to correct behavior.

## Troubleshooting

Start at [Troubleshooting](troubleshooting.md): broken CSS/JS, stale cache,
WooCommerce, Redis, Critical CSS, Used CSS, LiteSpeed, preload, database,
and monitoring sections each name the rollback first.

## FAQ

**Is the plugin "fully compatible" with my theme/builder/shop?**
It includes safeguards and tested paths for the products above, but no
plugin can promise every version combination. Your staging test is the
guarantee.

**Can I run two cache plugins?**
No — one full-page cache at a time. You can still use minification, images,
and database features alongside most other plugins.

## Technical reference

- Woo detection/exclusions/self-test:
  `includes/Integrations/class-woo-detect.php`
- Redis prerequisites: `includes/Cache/class-object-cache.php`
- LiteSpeed modes: `includes/Integrations/class-litespeed-integration.php`
- Related: [Page Cache](page-cache.md), [LiteSpeed](litespeed.md),
  [Redis](redis.md), [CDN](cdn.md), [FAQ](faq.md)
