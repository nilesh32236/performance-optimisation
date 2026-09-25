# Growth Metrics — Evidence-Based Monitoring

**Campaign:** Phase G — evidence-based growth monitoring (issue #1642)
**Baseline date:** 2026-09-25
**Plugin version at baseline:** 2.4.0
**Collector:** `scripts/collect-growth-metrics.sh` (read-only, public sources only)
**Snapshots:** `docs/growth/metrics/<YYYY-MM-DD>.json` + `docs/growth/metrics/latest.json`
**Schema:** `docs/growth/metrics.schema.json` (JSON Schema draft-07)

No runtime, API, settings, or optimization behavior was changed to produce this
report. No WordPress visitor, owner, or authenticated site data leaves the
repository. No invasive analytics, tracking pixels, or new runtime telemetry
were added. No fake activity, reviews, or ratings were created.

## Baseline (2026-09-25)

### WordPress.org listing (public API + HTTP status)

| Metric | Baseline value | Source |
|---|---|---|
| Listing name | Performance Optimisation | wp.org plugin-information API |
| Directory version | 2.4.0 | wp.org API (`version`) |
| Last updated | 2026-09-24 1:10am GMT | wp.org API (`last_updated`) |
| Active installs | 0 (directory shows a new/uncounted listing) | wp.org API (`active_installs`) |
| Downloads total | unavailable (field not returned by API) | wp.org API |
| Rating score | 100/100, 5.0/5 weighted (4 ratings, all 5-star) | wp.org API (`rating`, `ratings`, `num_ratings`) |
| Support threads | 0 open / 0 resolved | wp.org API |
| Listing page HTTP | 200 | `https://wordpress.org/plugins/performance-optimisation/` |
| Banner asset HTTP | 404 (not yet synced — see limits) | `ps.w.org/.../banner-1544x500.png` |
| Icon asset HTTP | 404 (not yet synced — see limits) | `ps.w.org/.../icon-256x256.png` |

The 404 banner/icon statuses match the known Phase B publication state recorded
in [WORDPRESS-ORG.md](WORDPRESS-ORG.md): assets are committed in `assets/` but
directory publication is not yet claimed. This is a tracked follow-up, not a
regression.

### GitHub repository (public REST API)

| Metric | Baseline value | Source |
|---|---|---|
| Stars | 4 | `repos/{owner}/{repo}` |
| Forks | 0 | `repos/{owner}/{repo}` |
| Open issues (API count) | 8 | `repos/{owner}/{repo}` (`open_issues_count`) |
| Open issues (search total) | 8 | `search/issues` (best-effort `gh` CLI) |
| Open PRs (search total) | 2 | `search/issues` with `is:pr` (best-effort `gh` CLI) |
| Latest release sampled | v2.4.0 (2026-09-24) | `/releases` (5 most recent) |
| Recent release tags | v2.4.0, v2.3.0, v2.2.0, v2.1.0, v2.0.0 | `/releases` |
| Traffic views (14d aggregate) | 731 views / 7 uniques, when owner API permits | `/traffic/views`, else `unavailable` |
| Recent workflow runs | 5 most recent `gh run list` entries (best-effort) | `gh` CLI, else `unavailable` |

### Repository evidence (local, deterministic)

| Metric | Baseline value | Source |
|---|---|---|
| Plugin header version | 2.4.0 | `performance-optimisation.php` |
| Readme tags | performance, cache, optimization, core-web-vitals, pagespeed | `readme.txt` |
| Readme stable tag | 2.4.0 | `readme.txt` |

## Deltas

No prior snapshot exists, so there are no deltas yet. Each weekly run appends
`docs/growth/metrics/<YYYY-MM-DD>.json`, refreshes `latest.json`, and future
edits of this section record week-over-week movement as observed values only.
Change-impact language must not infer causality from post-change movement: a
metric moving after a release is correlated in time, not proven to be caused by
it.

## Feature coverage (18 features)

Coverage is measured by case-insensitive evidence grep over `readme.txt`,
`includes/`, and `src/`. `covered` means at least one match with up to three
witness files recorded in the snapshot; `gap` means no match. Baseline status
(2026-09-25): all 18 features covered.

| Feature | Baseline | Evidence examples (see snapshot for full list) |
|---|---|---|
| Page Cache | covered | readme.txt, builder-purge-watcher, litespeed-esi |
| CSS | covered | readme + minify/CSS pipeline |
| JS | covered | readme + defer/delay pipeline |
| HTML | covered | readme + HTML minifier |
| Images | covered | readme + image optimisation |
| WebP | covered | readme + converter |
| AVIF | covered | readme + converter |
| Lazy Load | covered | readme + lazy loader |
| Core Web Vitals | covered | readme + RUM/telemetry |
| PageSpeed | covered | readme + PageSpeed integration |
| RUM | covered | readme + RUM collector |
| Redis | covered | readme + object-cache |
| Database | covered | readme + database cleanup |
| WooCommerce | covered | readme + woo detection |
| LiteSpeed | covered | readme + LiteSpeed integration |
| CDN | covered | readme + CDN/edge purgers |
| Critical CSS | covered | readme + critical-CSS pipeline |
| Used CSS | covered | readme + used-CSS pipeline |

## Search-intent map (question → readme / docs / feature)

Intent phrases come from [KEYWORD-MAP.md](KEYWORD-MAP.md); documentation
targets come from [DOCUMENTATION-MAP.md](DOCUMENTATION-MAP.md). Every row must
resolve to an existing feature or a documented compatibility/configuration
path. An intent with no target is a documentation gap (see thresholds).

| User question | Readme target | Docs target | Product feature |
|---|---|---|---|
| How do I speed up WordPress? | Description, Why choose | `performance-optimisation.html` | Page Cache + audit |
| How do I enable page caching? | Page Caching | `page-cache.html` | Static HTML cache |
| How do I improve LCP / Core Web Vitals? | Core Web Vitals monitoring | `core-web-vitals.html` | RUM, preload, images |
| How do I convert images to WebP/AVIF? | Image Optimization, FAQ | `images-webp-avif.html` | Img converter |
| How do I defer or delay JavaScript? | File Optimization, FAQ | `defer-delay-js.html` | Defer/delay JS |
| How do I minify CSS without breaking layout? | File Optimization, FAQ | `minify-combine.html` | CSS combine policy |
| What is Critical CSS / Used CSS, and what if styles break? | Advanced Features | `used-critical-css.html` | Critical/Used CSS |
| How do I set up a CDN or purge edge cache? | Advanced Features, External Services | `cdn.html` | CDN + edge purge |
| Does this work with WooCommerce? | Compatibility, FAQ | `compatibility.html` | Woo safe-mode + self-test |
| Does this work with LiteSpeed? | Compatibility, Advanced Features | `litespeed.html` | Coexistence modes |
| How do I connect Redis object cache? | Database & Object Cache | `redis-object-cache.html` | Redis drop-in |
| How do I clean the database safely? | Database Cleanup | `database-cleanup.html` | DB cleanup + dry-run |
| What do PageSpeed / RUM numbers mean? | Performance Monitor | `monitoring-rum.html` | PageSpeed + RUM |
| The site looks broken after optimizing — how do I recover? | Support, Upgrade Notice | `troubleshooting.html`, `support.html` | Exclusions, rollback, verify |

## Material-change alert thresholds

Alerts are opened only when a threshold below is met. Daily fluctuations are
noise and never alerts. Sustained windows are evaluated across weekly
snapshots. Every alert must link the two snapshots compared and must not claim
causality.

| # | Signal | Material threshold | Window |
|---|---|---|---|
| T1 | Rating-score drop | Weighted 1–5 score drops ≥ 0.2 | Sustained 4 weeks |
| T2 | Support-thread spike | Open support threads up ≥ 50% vs baseline | Sustained 2 weeks |
| T3 | Broken listing | Listing page returns non-200 (e.g. 404) | Immediate (verify twice, 24h apart) |
| T4 | Broken visual assets | Banner or icon asset returns 404 after a previously-200 baseline | Immediate (verify twice, 24h apart) |
| T5 | Documentation gap | A mapped intent has no readme/docs/feature target | On detection during weekly review |
| T6 | Release regression | Verify or Playwright job fails on a release tag build | Per release-tag run |

Notes:

- T4 does not fire on the current 404 baseline: the assets were never observed
  at 200, and [WORDPRESS-ORG.md](WORDPRESS-ORG.md) already tracks directory
  sync as pending. T4 fires only on a 200 → 404 transition.
- T6 uses repository evidence only (`gh run list` entries captured in the
  snapshot); it never blocks or re-runs CI.
- T1/T2 require the wp.org API to return data; `unavailable` is recorded, not
  interpolated.

## Interpretation limits

1. Public listing and repository data only. No ranking, conversion, traffic
   attribution, or causality claims.
2. Daily fluctuations are noise; only sustained threshold crossings (table
   above) qualify as material.
3. Traffic aggregates appear only when the owner API permits; otherwise the
   snapshot records `unavailable` and reports must say so.
4. `active_installs` is a coarse directory bucket, not a precise user count.
5. Coverage grep proves the presence of terminology plus witness files, not
   feature quality or user outcomes.
6. Change-impact language describes timing correlation only and must state that
   causality is not established.
7. Every report states which metrics were unavailable and the coverage limits
   above.

## Reproducibility

```sh
bash scripts/collect-growth-metrics.sh
# writes docs/growth/metrics/<YYYY-MM-DD>.json + latest.json; exit 0 even
# when remote APIs are unreachable (metrics recorded as "unavailable").
```

Validate a snapshot against the schema (Node 22, no new dependencies beyond
the existing toolchain):

```sh
npx --yes ajv-cli validate -s docs/growth/metrics.schema.json -d 'docs/growth/metrics/*.json'
```

The scheduled weekly workflow (proposed in the Phase G PR description — not
committed here because workflow files require maintainer permissions) runs the
collector every Monday, validates snapshots, diffs against the previous week,
and opens or updates a single `growth-alert`-labeled issue only when a
threshold above is crossed.
