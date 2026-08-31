# Next Engineering Batch — 2026-08-31 13:00 UTC

**Master:** `c1cda64f` (after #773) — 0 open PRs, 8 open issues (755/754/757 closed)
**Previous:** `1beeb5f2` → implemented #755, #754, #757 (3 PRs merged)
**Queue completed:**

| Batch | Issue | Title | PR | Commit | Result |
|-------|-------|-------|----|--------|--------|
| B1 | #755 | fetchpriority high preload + filterable deferred | #771 | 7b11fa92 | MERGED |
| B2 | #754 | speculation rules validate + Prefixer | #772 | ad29d4f4 | MERGED |
| B3 | #757 | lazy honour Core LCP + contain allow-list | #773 | c1cda64f | MERGED |
| B4 | #756 | wp_cache_supports | — | — | VERIFIED OBSOLETE/DONE (already 2334) |
| B4 | #758 | Abilities API | — | — | VERIFIED OBSOLETE/DONE (class-abilities) |

## Batch Details

### #755 — fetchpriority
- **Gap:** preload via `Util::generate_preload_link` without high; deferred low hard-coded
- **Fix:** `Cache::maybe_preload_combine_css:590` filter `wppo_combine_preload_fetchpriority` default high → `generate_preload_link(..., fetchpriority)`; `Main::add_defer_strategy:1948` filter `wppo_deferred_fetchpriority` per-handle default low (allow high for LCP)
- **Compat:** `function_exists` not needed for unknown attr (harmless), `is_wp69_plus` guard for native vs regex, `@since NEXT`, docs/hooks.md
- **Tests:** `phpcs 0`, `npm test 34/34`, `phpunit 435/435`, `build` success; manual view-source preload high, deferred low, filter override high

### #754 — speculation
- **Gap:** mode/eagerness not validated, excludes not wildcarded via Prefixer
- **Fix:** `filter_speculation_rules_configuration` validate via `WP_Speculation_Rules::is_valid_mode/is_valid_eagerness` when class exists else allowlist; `wp_speculation_rules_href_exclude_paths` closure convert bare `/path` via `WP_URL_Pattern_Prefixer::prefix_path_pattern` when exists else `rtrim/*`, dedupe against incoming
- **Compat:** `class_exists`/`method_exists` guards for <6.8, no schema, WP 7.1 tested (wp_get_speculation_rules true)
- **Tests:** 435/435, build success

### #757 — lazy/auto-sizes
- **Gap:** `add_delay_load_img` forced `loading=lazy` even for LCP first N, missing `wp-img-auto-sizes-contain` allow-list
- **Fix:** when `use_native_lazy` and `loading` null, consult `wp_get_loading_optimization_attributes('img',src/width/height,'performance_optimisation_delay_load')`; if no `loading` (LCP), skip lazy; else set lazy. `Main::minify_queued_styles`/`minify_css` and `Cache::is_excluded_from_combine` early-return for `wp-img-auto-sizes-contain` when `wp_enqueue_img_auto_sizes_contain_css_fix` exists
- **Tests:** updated `ImageOptimisationTest.php:982,1088` mocks to return `loading=lazy` (first test defines function reused), 435/435 pass

### #756 / #758 — verified done
- `Cache::flush_group 2334` already `wp_cache_supports('flush_group')` guard; `Abilities` already `wp_register_ability_category/ability` gated — no PR needed

## 765 Extraction Re-evaluation (vs c1cda64f)
- `git diff master...origin/fix/audit-2026-08-28` still 212 files, but `755/754/757` now done reduces urgency
- 182 only-in-765, 30 overlap (partially): still STILL NEEDED for E1 CLI (`class-wppo-cli-command.php` synopsis/format), E2 Hooks, E4 hardened, E5 UX (blocked #709), E3 perf unique `src file stat LRU` — see `765-EXTRACTION-MAP.md` updated
- No new extraction PR created this batch (focus was WP modern APIs); next batch should prioritize E1 CLI (independent, tests)

## Quality Gate (c1cda64f)
- `php -l` PASS 43
- `vendor/bin/phpcs` 0
- `npm run lint:js` 0e5w
- `npm test` 34/34 345
- `vendor/bin/phpunit` 435/435 2 skipped
- `npm run build` webpack 5.109.2 success (245K entrypoint)
- `wp plugin status` Active 1.9.0, `curl -I` 200 LiteSpeed hit, `wp wppo --help` 7 cmds
- `gh pr list` 0, `gh issue list` 8 (754/755/757 closed, 756/758 remain OPEN as OBSOLETE/DONE polish, 709/708/707/646/369/368 product)

## Next Steps
- Do not auto-extract 765 E1-E5 now; next focused PR should be E1 CLI (<100 files) after verifying `class-wppo-cli-command.php` still needed
- 756/758 remain OPEN as documentation polish (400 message, hooks.md) — close or keep as deferred
- 709 vote still blocks E5 UX
