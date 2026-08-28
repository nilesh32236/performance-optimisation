# BEFORE-AFTER-SUMMARY.md — Measurable changes (spec §6)

| ID | Before (what work, how often, why expensive) | After (what changed, how avoided, trade-off) | Expected improvement |
|----|----------------------------------------------|----------------------------------------------|----------------------|
| P-WP-01 `get_option` 6× | 6 deserializations per frontend render (Main+Cache+Cron+UsedCss+RUM) ~3-6ms churn | `Util::get_settings()` static memo + `clear` on `update_option` hooks → 1× hot path | -3–5ms per cache-miss render; 32 long-tail sites deferred |
| P-CACHE-03 stampede | Concurrent cold-miss `file_put_contents` torn reads | `atomic_put_contents tmp+rename` + 5s transient lock `wppo_cache_write_<md5>` | 100 RPS burst → ≤2 writers; no partial HTML |
| P-DB-01 RUM | `get_option+update_option` per beacon 10k×200KB UPDATE/day ~2 GB | transient queue `QUEUE_MAX 100/THRESHOLD 20` + `flush_queue` batched + `wppo_rum_flush` cron | 10k → ~500 writes/day (-95%, 2 GB→100 MB) |
| P-CPU-01/02 combine_css | `eligible_handles` classified 3× + 120× `uasort` budget sim ~2-3ms | single `eligible_handles` reuse + `core_will_inline_memo` 60 sims | -1.5ms per page with 30 styles |
| P-CPU-04 `file_exists` | 480 `stat` per 80-image gallery ~10-50ms | FIFO 500 `cached_file_exists` + LRU 100 `get_cached_image_size` | 480→≤160 stats (-8–42ms) |
| Google fonts SSRF | `strpos` allows `evil.com/fonts.googleapis.com` | `wp_parse_url host ===` exact 3 sites | Blocks host spoof |
| Ability enum | `trash` not in `TABLE_MAP` → 0 cleaned via MCP | aligned to `trashed_posts` + `AbilitiesTest` | MCP now 3 cleaned |
| Next-gen fallback | regex only `<img>` loses `<source>`/poster | 3 passes img+source+video mirroring TagProcessor | Poster/srcset preserved on WP<6.2 |
| `realpath` | false for uncached → 400 "Clear This Page" | `candidate_path` fallback + `..` check | Uncached pages clearable |
| `COOKIEHASH` | `md5(site_url)` scheme-dependent | `md5(host)` | Logged-in cache correct across http/https |
| `get_sitemap_urls` | HTML 500 parsed as sitemap | `200 !== response_code` guard | Error pages not indexed |
| BatchDeleter | 5× copy-paste `LIMIT 1000 → DELETE` | `delete_in_batches` helper 1 | -250 lines dup |
| Allow-list | 4-way drift `trash`/`core_tweaks` | `Util::ALLOWED_SETTINGS_KEYS` + `wppoSettings.allowedSettingsKeys` JS | 0 drift; `core_tweaks` legacy 400 documented |
| SCSS | 52 lines legacy `wppo-switch` + 20 `!important` + dup selectors | removed legacy, `respond-to`, `overflow-wrap`, `focus-within`, 54.8 KiB | 0.3 KiB smaller, a11y + maintainability |
| `NoticeBanner` | `role alert` for all + no `aria-live` | `error→alert/assertive` else `status/polite` | WCAG live region correct |

New trade-offs: RUM queue advisory race (100-cap drop oldest), stampede advisory lock (not `wp_cache_add` CAS), `get_option` long-tail 32 sites, `core_tweaks` narrowing breaks legacy import (400).

