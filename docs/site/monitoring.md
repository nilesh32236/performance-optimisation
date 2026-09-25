# Monitoring and Core Web Vitals

Hub guide: measure first, then optimize (LCP, INP, CLS).

## What it does

- **Local telemetry scan** (`performance_scan`): real load times, TTFB, DNS,
  and connection timing measured from your own server via cURL.
- **PageSpeed Insights** (`pagespeed_scan` / `pagespeed_results`): Google
  lab scores and diagnostics for a URL (mobile + desktop), queued as
  background jobs.
- **Real-User Monitoring (RUM)** (`rum_collect` / `rum_data`): anonymised
  field LCP/INP/CLS from actual visitors, aggregated into trends
  (`web_vitals_trends`, capped at 30 entries per URL+strategy).
- **Suggestions** (`suggestions`): read-only next steps derived from
  telemetry + PageSpeed.

Sources: `includes/Insight/class-telemetry.php`,
`includes/Insight/class-pagespeed.php`, `includes/Insight/class-rum.php`,
`includes/Insight/class-suggestion-engine.php`, `readme.txt`.

## When to use it

- Before changing anything: capture a baseline.
- After each change: confirm it helped (one change at a time).
- When PageSpeed and real visitors disagree: trust RUM (field) for what
  users feel, PageSpeed (lab) for what to fix.

Related: [Images](images.md) for LCP payload, [Preload](preload.md) for LCP
delivery, [CSS and JS](css-js.md) and [Deferred JavaScript](deferred-js.md)
for INP/blocking work, [Page Cache](page-cache.md) for TTFB.

## Safe default

Monitoring is passive until enabled: RUM is **off** (`rum_enabled: false`,
sample rate 100 when on), PageSpeed needs **your own API key**
(`pagespeed_api_key: ''` — the plugin ships none), and auto-rescan is **off**
(`auto_rescan: ''`). No external request happens without your action.

Source: `Settings_Store::get_default_settings()` in
`includes/Settings/class-settings-store.php`.

## How to enable

1. Go to **Performance Optimisation → Dashboard → Performance Audit**.
2. Run a **local telemetry scan** (no key needed) for your baseline.
3. Add a PageSpeed API key (from Google Cloud Console) and click **Scan**
   for mobile + desktop.
4. Optionally enable **RUM** to collect field data, and **auto-rescan**
   (daily/weekly) to track the home + high-value URLs over time.

## What changes

- Telemetry/PageSpeed results are cached as transients (PageSpeed ~24h);
  trends persist in the `wppo_web_vitals_trends` option.
- RUM adds a tiny first-party beacon (`src/rum.js`); samples store path +
  LCP/INP/CLS only — no cookies, names, or IPs persisted (IP is read
  in-memory solely for the 120 req/hour beacon rate limit). Rolling 14-day
  window, up to 200 paths/day and 600 paths total.
- AI Adaptive suggestions are **read-only recommendations** — never silent
  changes.

## Compatibility

| Integration | Status | Notes |
|---|---|---|
| Any theme / builder | `verified` | Measurement only; never alters frontend output |
| Google PageSpeed API | `supported` | Requires your key; quota/terms per Google's docs — see External Services in `readme.txt` |
| Optimization Detective | `supported` | Bridge (`class-od-bridge.php`) consumes real-visit LCP data when the OD plugin is present |
| Cache layers | `best effort` | Scan warmed vs cold URLs separately; note which you measured |

See [Compatibility](compatibility.md) for the full matrix.

## Verify

1. Run telemetry twice and confirm timings are in the same ballpark.
2. After enabling RUM, visit pages yourself and confirm samples appear in
   `rum_data` aggregation.
3. After each optimization, re-scan the **same URL + strategy** and compare.

## Undo

1. Turn **off** RUM and auto-rescan.
2. Delete the API key to stop all PageSpeed requests.
3. Uninstalling removes stored trend/RUM data.

## Troubleshooting

- **Scores vary run to run:** normal — lab variance plus hosting noise.
  Compare medians, not single runs. See
  [Troubleshooting](troubleshooting.md#monitoring).
- **No RUM data:** RUM must be enabled and real visits must occur; ad
  blockers can suppress the beacon.
- **PageSpeed key errors:** quota, referrer restrictions, or wrong key —
  check Google Cloud Console, not this plugin.

## FAQ

**Will this plugin guarantee a 100 score or #1 ranking?**
No — and no honest plugin can. Scores depend on hosting, theme, plugins,
and content. Measure your own before/after; that is the only reliable guide.

**Lab vs field — which matters?**
Both. Lab (PageSpeed/telemetry) is reproducible and points at fixes. Field
(RUM) shows what visitors actually experience. Fix with lab, confirm with field.

## Technical reference

- Telemetry: `includes/Insight/class-telemetry.php`
- PageSpeed: `includes/Insight/class-pagespeed.php`
- RUM: `includes/Insight/class-rum.php` · Suggestions:
  `includes/Insight/class-suggestion-engine.php`
- Settings tab: `performance_audit` · REST: `performance_scan`,
  `pagespeed_scan`, `pagespeed_results`, `rum_collect`, `rum_data`,
  `web_vitals_trends`, `suggestions`, `system_info`
