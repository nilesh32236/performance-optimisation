# FINDINGS — OPTIMIZATION

_Consolidated from 12 agents (2286 lines). Each finding below is traceable to `AUDIT/AGENTS/agent-*.md` file:line evidence. This shard groups by severity OPTIMIZATION._

## Index

### agent-A01-php-correctness.md

- > Severities: `CRITICAL` > `HIGH` > `MEDIUM` > `LOW` > `INFO` > `OPTIMIZATION` > `DUPLICATE` > `DEAD CODE`
- | A04 | OPTIMIZATION | `advanced-cache-handler.php:221-259` | generated `wppo_serve_cache_file()` | `md5_file()` + `filemtime()` called for `br`, then again for `gzip`, then again for `html` — up to 6 stats + 3 hashes per cache hit before `readfile()`. | Adds ~1-3ms + I/O per hit; at scale measurable. | Cache `filemtime`/`md5_file` together or store mtime in filename; use `filesize`+`mtime` for ETag if CPU-sensitive. | MEDIUM |
- | C05 | OPTIMIZATION | `class-cache.php:800-852` | `core_inline_budget_will_inline()` | Builds `inline_size_map` lazily via `is_file`+`filesize`+`is_readable` per queued handle; then `uasort` smallest-first + cumulative budget simulation. Called via `core_will_inline()` which invokes it **twice** per handle (`prediction` vs `reference` with `core_faithful` true/false) — so per handle up to 2× sort+loop. In `combine_css` freshness loop may call `core_will_inline` per handle again. | Up to 6× filesystem stats per request (as doc says). `inline_size_map` cache mitigates but still double simulation per handle. | Merge prediction+reference into single pass returning both results; or cache comparison result per handle. | MEDIUM |
- All other findings are `LOW` (correctness/robustness), `INFO` (design notes), `OPTIMIZATION` (CPU/I/O), `DUPLICATE` (maintainability), or `DEAD CODE`.

### agent-A02-php-media.md

- Severity legend: **CRITICAL** = exploitable / data-loss; **HIGH** = correctness / security regression; **MEDIUM** = functional bug / measurable perf / a11y; **LOW** = minor edge / style; **INFO** = observation / confirmation; **OPTIMIZATION** = perf improvement opportunity; **DUPLICATE** = copy-pasted logic; **DEAD CODE** = unreachable / never-executed.
- | A02-025 | OPTIMIZATION | `class-image-optimisation.php:2402-2624` | `add_delay_load_img` runs 3 full HTML parses on same buffer | Sequence: `WP_HTML_Tag_Processor` pass for img/iframe lazy (2447-2577) → `post_process_placeholders/dimensions/auto_sizes` each does its own `preg_replace_callback` scanning `buffer` again (2579-2581) → `process_picture_blocks_processor` does full `WP_HTML_Processor` token walk (2584). That's 4-5 passes over potentially 200KB HTML. | Combine post-process passes into single token walk or cache `WP_HTML_Tag_Processor` instance. For regex fallback, combine placeholder/dimension/auto-size regexes. Benchmark before changing. |
- | A02-026 | OPTIMIZATION | `class-img-converter.php:715-764` | Dominant-color sampling uses `sqrt(area/500)` stride — wastes work on thumbnails | For 4000×3000 (12M px) stride ~ `sqrt(12M/500)=154` → ~ 500 samples intended, but nested loops still iterate `height/stride`×`width/stride` ≈ 500, correct. For 100×100 thumb, stride=1 → 10k samples (overkill). Could cap samples to 500 distinct points for small images, but current is fine. INFO. |

### agent-A03-php-infra.md

- > Severity: `CRITICAL` > `HIGH` > `MEDIUM` > `LOW` > `INFO` > `OPTIMIZATION` / `DUPLICATE` / `DEAD CODE`
- | A03-025 | OPTIMIZATION | `class-telemetry.php:632-665` `get_sitemap_urls` regex vs parser | Performance | Uses `preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i', $body)` which is O(n) on body string but allocates `$matches[1]` full copy per sitemap child. For a 500-URL sitemap (10k loc elements across 50 children) allocates ~10k strings × 50 iterations = transient memory spike (~5 MB). `SimpleXML` would stream. | Acceptable for 500 cap but allocation-heavy under `WPPO_CRON_DISCOVERY_LIMIT=2000` filter edge. | Keep regex (fast, no libxml errors on malformed XML) but free via `unset($matches)` after iteration. | low |
- | A03-026 | OPTIMIZATION | `class-cron.php:648,326,491` `apply_filters('wppo_cron_discovery_limit',50)` | API | Filter applied inside `img_convert_cron` and `schedule_sitemap_url_jobs` hard caps but no sanity clamp (could be set to 100000). Would cause OOM if filter returns large number. | Operator error via filter misconfiguration. | Clamp with `max(1, min(500, (int) apply_filters(...)))`. | low |

### agent-A05-js-spa.md

- | 35 | OPTIMIZATION | `src/components/PerformanceAudit.js:121-129` | `numericStatus` thresholds for `load_time` (2.5/4) and `ttfb` (200/500) are hardcoded, duplicate of PHP SuggestionEngine thresholds – drift risk | If PHP changes thresholds, UI badge color diverges | Extract constants to shared `src/lib/metrics.js` or import from `litespeed.js`-style config; document sync with `Suggestion_Engine::THRESHOLDS`. | MEDIUM |

### agent-A07-css.md

- > Severity: **CRITICAL** > **HIGH** > **MEDIUM** > **LOW** > **INFO** > **OPTIMIZATION** > **DUPLICATE** > **DEAD CODE**
- | F-14 | **MEDIUM** | `src/css/components/_stats.scss:61-82, 73-82` | `&::after { background-image: radial-gradient(circle at 1px 1px, rgba(148,163,184,0.35) 1px, transparent 0); background-size: 18px 18px; opacity:0.04; }` on every `.wppo-stat-item` | Decorative dot pattern: extra paint layer per card (4 cards) for `opacity:0.04` almost invisible. Adds **paint cost** for aesthetic with negligible visual gain — **performance-audit flagged OPTIMIZATION**. | Remove or gate behind `@media (min-width: 768px)` and `@media not (prefers-reduced-motion)` or use single pseudo on grid container. | 70% |
- | F-28 | **OPTIMIZATION** | `build/style-index.css` (56,431 bytes) | 457 selectors, 54 duplicate selector blocks, 25 `color-mix` without individual `@supports` per call | Build size **56 KB minified** (admin-only, not frontend) — acceptable. 54 duplicates add ~2-3 KB. Uncompressed vs gzipped (~9 KB). For frontend `lazyload` not relevant (separate entry `src/lazyload.js`). | Deduplicate SCSS (F-06, F-21) to save ~5-8% and reduce parse time. Enable `cssnano` `mergeRules`/`deduplicate` if not already via `wp-scripts`. | 75% |
- | F-29 | **OPTIMIZATION** | `src/css/components/_stats.scss:44-59` + `_card.scss:7-9` | `box-shadow: var(--wppo-shadow-card)` (2 layers) + `transform` hover + `backdrop-filter` on same element | Layer promotion per card (4 cards) → 4 compositor layers. No `contain: layout paint` to isolate. | Add `contain: layout paint` or `content-visibility: auto` for below-fold cards; test in Chrome Layers panel. | 68% |
- | F-30 | **OPTIMIZATION** | `src/css/base/_base.scss:72,87` + `src/css/components/_tabs.scss:14` | `-webkit-overflow-scrolling: touch;` (3 occurrences) | Deprecated since iOS 13 (always momentum). Harmless but dead bytes. `wp-scripts` Autoprefixer no longer adds it. | Remove — no longer needed with `overscroll-behavior-x: contain` already present. | 96% |

### agent-A09-performance.md

- | P-CACHE-01 | **OPTIMIZATION** | `includes/class-cache.php:2137-2155,2165-2185` | `get_cache_stats()` calls `calculate_directory_size` (recursive `dirlist` walk) **and** `count_cached_pages` (second recursive walk of same tree). Both start at `cache/{domain}` and recurse identically, doubling `dirlist`/`size` syscalls. `admin_enqueue_scripts` calls only `get_cache_size` (one walk), but a future `get_cache_stats` wiring would pay double. Inside each, `WP_Filesystem::dirlist` returns `name→{type,size}` but code ignores `size` from `dirlist` and instead calls `WP_Filesystem::size(file_path)` per file (line 2151) — **duplicate stat** (dirlist already has size on some filesystem adapters, but `WP_Filesystem_Direct::dirlist` does `filesize` internally and returns it; calling `size` again re-stats). | On 5000 cached pages, `get_cache_stats` would do 2× directory walks = 10000 `dirlist` dir stats + 10000 `size` stats (20k syscalls) vs 10k needed. `get_cache_size` alone: 5k files × `size` =5k stats ≈ 100–400 ms. Double walk = 200–800 ms. | Reuse `size` from `dirlist` entry (`$file['size'] ?? $fs->size(file_path)`) and memoize walk result between `calculate_directory_size`+`count_cached_pages` with single `walk_cache_dir(cache_dir, &$total_size, &$count)` helper doing one traversal that returns both. | **HIGH** |
- **OPTIMIZATION: 1 | HIGH: 8 | MEDIUM: 12 | LOW: 6 | INFO: 4**

### agent-A12-quality-architecture.md

- **Instruction:** Check responsibilities, abstractions, coupling, global state, dependency management, lifecycle, naming, overly complex functions, god classes, circular deps, testability, repeated logic, inconsistent patterns, error handling, edge cases, coding standards. Classify CRITICAL/HIGH/MEDIUM/LOW/INFO/OPTIMIZATION/DUPLICATE/DEAD CODE with file:line evidence, impact, recommendation, confidence. Do not modify production code. Be evidence-based, do not invent unnecessary abstraction.
- | 51 | `src/components/DatabaseCleanup.js` | 644 | 9 types + `all`, `ConfirmDialog` per type, `get_counts` polling, `clean_all` vs individual, OPTIMIZATION badge |


> Source agents: agent-A01-php-correctness.md, agent-A02-php-media.md, agent-A03-php-infra.md, agent-A04-php-rest-cli.md, agent-A05-js-spa.md, agent-A06-js-vanilla.md, agent-A07-css.md, agent-A08-security.md, agent-A09-performance.md, agent-A10-duplication-deadcode.md, agent-A11-compatibility.md, agent-A12-quality-architecture.md — full evidence in each agent file.
