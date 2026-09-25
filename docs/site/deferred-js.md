# Deferred and Delayed JavaScript

Beginner-first guide to defer-JS and delay-JS (including presets).

## What it does

- **Defer** (`deferJS`) tells the browser to download scripts in parallel
  but run them after the HTML is parsed — pages paint sooner.
- **Delay** (`delayJS`) goes further: selected scripts do not run until the
  visitor interacts (or the browser is idle), which helps Interaction to
  Next Paint (INP).
- **Presets** bundle known-safe exclusion sets: builder, commerce,
  interaction, plus opt-in consent / analytics / gallery / jQuery-legacy and
  an auto-delay for known third parties when idle.

Sources: `includes/minify/class-minify-policy.php` (strategy),
`readme.txt`, `docs/growth/WORDPRESS-ORG.md`.

## When to use it

- PageSpeed flags "reduce render-blocking JavaScript" or INP is poor in
  [Monitoring](monitoring.md).
- You have already done [CSS and JS](css-js.md) minification safely.
- Third-party widgets (chat, analytics, galleries) slow the first paint.

Never start here — defer/delay is the most likely feature to break
interactive elements. Images and cache first.

## Safe default

Both are **off** by default (`deferJS: false`, `delayJS: false`), the safe
mode is **on** (`delayJSSafeMode: true`), and payment gateways plus consent
banners stay eager. Builder/commerce/interaction presets default **on** as
exclusion guards; consent/analytics/gallery/jQuery presets default **off**.

Source: `Settings_Store::get_default_settings()` in
`includes/Settings/class-settings-store.php`.

## How to enable

1. Go to **File Optimization → JavaScript**.
2. Enable **Defer JS** first, save, purge, test every interactive element.
3. Only then try **Delay JS** (strategy default `interaction`), with the
   presets that match your site.
4. Add problem scripts to **Exclude Delay JS** (`excludeDelayJS`) or
   **Exclude Defer JS** (`excludeDeferJS`).
5. There is a per-page kill switch in the post metabox for emergencies.

## What changes

- Deferred scripts keep their order but execute after parsing.
- Delayed scripts are held back until interaction/idle/timeout
  (`delayJSIdleTimeout` default 3000 ms); exclusions (`delayJSExcludeUrls`,
  idle/viewport/priority lists) carve out scripts that must run early.
- WooCommerce safe-mode toggle keeps shop flows eager.

## Compatibility

| Integration | Status | Notes |
|---|---|---|
| WooCommerce / payments | `supported` | Commerce preset + safe mode keep gateways eager; test cart/checkout — see [Compatibility](compatibility.md) |
| Consent banners | `supported` | Consent preset is opt-in; banners stay eager by default so consent still registers |
| jQuery legacy plugins | `best effort` | jQuery preset is opt-in and off by default; exclude old plugins |
| Builders (Elementor/Divi) | `supported` | Builder preset guards editor/frontend builder scripts |

## Verify

1. Enable, purge, and load the frontend in incognito.
2. Confirm menus, sliders, forms, add-to-cart, and checkout all still work.
3. View source: deferred scripts carry `defer`; delayed scripts show the
   delay loader with your exclusions applied.
4. Compare INP/LCP in [Monitoring](monitoring.md) before/after.

## Undo

1. Turn **off** Delay JS (and Defer JS if needed) and save.
2. **Clear All Cache**.
3. For a single broken page, use the per-page kill switch instead of a
   global rollback.

## Troubleshooting

- **Buttons/menus dead:** exclusions first — see
  [Troubleshooting](troubleshooting.md#broken-css-js).
- **Checkout or payment broken:** disable delay-JS immediately, purge, and
  re-enable with the commerce preset plus gateway exclusions.
- **Analytics missing hits:** delayed analytics only fire on interaction —
  that is expected; exclude them if you need immediate tracking.

## FAQ

**Defer or delay — which first?**
Defer. It is milder. Add delay only after defer proves stable.

**Will delay-JS hurt SEO?**
Content is still in the HTML; delay affects script execution timing, not
crawlable markup. Verify with PageSpeed after enabling.

## Technical reference

- Strategy engine: `includes/minify/class-minify-policy.php`
- Delay patterns filterable via `wppo_delay_js_third_party_auto_patterns`
- Settings tab: `file_optimisation` (delay/defer keys) · REST: `update_settings`
