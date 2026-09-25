# Phase G — Measurement & Growth Monitoring

Captured: `2026-09-25T10:13:06+00:00`

This report uses public WordPress.org and GitHub repository metadata. It does not collect WordPress visitors, site-owner settings, cookies, or private application traffic.

## Public product metrics

| Metric | Current | Previous | Delta |
| --- | ---: | ---: | ---: |
| WordPress.org active installs | 0 | 0 | +0 |
| WordPress.org downloads | Unavailable | Baseline | N/A |
| WordPress.org rating score | 100 | 100 | +0 |
| WordPress.org rating count | 4 | 4 | +0 |
| WordPress.org support threads | 0 | 0 | +0 |
| WordPress.org version | 2.4.0 | 2.4.0 | N/A |
| WordPress.org last updated | 2026-09-24 1:10am GMT | 2026-09-24 1:10am GMT | N/A |
| GitHub stars | 4 | 4 | +0 |
| GitHub forks | 0 | 0 | +0 |
| GitHub open issues | 5 | 6 | -1 |
| GitHub open PRs | 5 | 2 | +3 |
| GitHub 14-day views | Unavailable | 731 | N/A |
| GitHub 14-day view uniques | Unavailable | 7 | N/A |
| GitHub 14-day clones | Unavailable | 16616 | N/A |
| GitHub 14-day clone uniques | Unavailable | 1169 | N/A |

## Operational metrics

- Latest release: `v2.4.0` (2026-09-24T01:09:01Z).
- Median release gap across the last 10 releases: `4.33` days.
- Regression count, issue-to-merge time, merge-to-release time, structured verification failures, and Playwright failure history are not yet exported as series.

## Feature coverage

| Feature | Documentation | Readme terminology |
| --- | --- | --- |
| Page Cache | Covered (page-cache) | Present |
| CSS | Covered (minify-combine) | Present |
| JS | Covered (defer-delay-js) | Present |
| HTML | Covered (minify-combine) | Present |
| Images | Covered (images-webp-avif) | Present |
| WebP | Covered (images-webp-avif) | Present |
| AVIF | Covered (images-webp-avif) | Present |
| Lazy Load | Covered (images-webp-avif) | Present |
| Core Web Vitals | Covered (core-web-vitals) | Present |
| PageSpeed | Covered (monitoring-rum) | Present |
| RUM | Covered (monitoring-rum) | Present |
| Redis | Covered (redis-object-cache) | Present |
| Database | Covered (database-cleanup) | Present |
| WooCommerce | Covered (troubleshooting) | Present |
| LiteSpeed | Covered (litespeed) | Present |
| CDN | Covered (cdn) | Present |
| Critical CSS | Covered (used-critical-css) | Present |
| Used CSS | Covered (used-critical-css) | Present |

## Searchability coverage

| User intent | Readme | Documentation | Product feature | Status |
| --- | --- | --- | --- | --- |
| enable page cache | Yes | Yes | Page Cache | Covered |
| improve LCP | Yes | Yes | Critical Assets Preloading | Covered |
| improve Core Web Vitals | Yes | Yes | Performance Audit | Covered |
| optimize images | Yes | Yes | Image Optimization | Covered |
| convert images to WebP | Yes | Yes | Image Optimization | Covered |
| optimize CSS | Yes | Yes | File Optimization | Covered |
| optimize JavaScript | Yes | Yes | File Optimization | Covered |
| lazy load images | Yes | Yes | Image Optimization | Covered |
| enable Redis | Yes | Yes | Object Cache | Covered |
| configure CDN | Yes | Yes | File Optimization / Edge Cache | Covered |
| critical CSS missing styles | Yes | Yes | Used and Critical CSS | Covered |
| WooCommerce cart cache | Yes | Yes | WooCommerce safe mode | Covered |
| LiteSpeed coexistence | Yes | Yes | LiteSpeed mode | Covered |
| site broken after optimization | Yes | Yes | Disable narrow feature and clear cache | Covered |

## Change impact

The following changes are tracked for later comparison. Movement after a change does not establish causation.

| Date | Change | Why | Causality |
| --- | --- | --- | --- |
| 2026-09-25 | Phase E documentation consolidation | Reduce beginner friction and improve search coverage | Unknown; public baseline and later snapshots required |
| 2026-09-25 | Phase F trust and conversion | Clarify free/open-source value, safe onboarding, and support recovery | Unknown; no causal claim |
| 2026-09-25 | Phase D feature discoverability | Map tasks to existing controls | Unknown; no causal claim |

## Unavailable metrics and limits

- WordPress.org downloads unavailable
- GitHub traffic requires owner-scoped API access

## Material-change gate

The scheduled workflow creates an issue only for sustained listing changes, rating/support regressions, GitHub issue growth, release changes, or a 25% traffic movement on a meaningful baseline. It does not create alerts for daily noise.

Current run: no material change from the previous snapshot.

## Privacy boundary

The collector sends no WordPress data to an external analytics service. GitHub traffic is aggregate owner-scoped repository data returned by the GitHub API; no visitor identities or URLs are stored.
