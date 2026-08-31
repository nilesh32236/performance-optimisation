# Deferred Backlog Plan — Engineering

**Master:** `788bf59b` (after #769) — 0 open PRs, 11 open issues (5 WP Monitor engineering deferred)
**Source:** Issues 754-758 re-read + `AGENTS.md` PHP 8.2 / WP Requires 6.2 (readme 7.1 tested), `function_exists` guards, multisite via `Util::transient_key()` + domain cache, 95% gate.

## P0 — Must Fix (none)

No P0. Full gate passes, WP active, no fake metrics, no critical regression. All WP Monitor issues are additive enhancements with legacy fallback.

## P1 — High Value Next Sprint (5 issues, each <100 files, @since NEXT)

### 754 — Delegate speculation rules to Core on WP 6.8+
- **Problem:** Plugin's `Main::add_speculation_rules()` prints own `<script type=speculationrules>` at `wp_head:0` unconditionally when `preload_settings.enableSpeculationRules` on, duplicates Core's 6.8 `<script type=speculationrules>` and misses Core built-ins (`wp-admin/*`, `uploads`, `content`, `plugins`, `template`, `stylesheet`, `?*`, `rel=nofollow`, `.no-prefetch/.no-prerender`).
- **Current:** `includes/class-main.php:759` `add_speculation_rules()` + `setup_hooks:553` unconditional `add_action wp_head`. No delegation.
- **Why deferred:** Needs manual viewport test (speculation mode eagerness) on WP 6.8+ not in CI. Low risk additive.
- **Recommended:** In `setup_hooks()` gate `if (function_exists('wp_get_speculation_rules')) { add_filter wp_speculation_rules_configuration → filter_speculation_rules_configuration; add_filter wp_speculation_rules_href_exclude_paths → filter_speculation_exclude_paths; } else { add_action wp_head add_speculation_rules; }` — map `enableSpeculationRules false` or logged-in→return null (suppress Core), else map `speculationMode {prefetch,prerender}` + `speculationEagerness {conservative,moderate,eager}` via `WP_Speculation_Rules::is_valid_mode/eagerness` when class exists; merge `Util::process_urls(speculationExcludeUrls)` via `WP_URL_Pattern_Prefixer::prefix_path_pattern` + dedupe.
- **Files:** `class-main.php` (setup_hooks, 2 filters + helpers), `class-util.php` (process_urls), `docs/hooks.md`, `tests/php` (new SpeculationRulesTest)
- **Tests:** Unit for filter delegation (Brain Monkey), manual 6.8+ viewport + View Source `speculationrules` count, OFF suppresses, logged-in null.
- **Compat:** `function_exists('wp_get_speculation_rules')` + `class_exists('WP_Speculation_Rules')`, WP<6.8 legacy printer untouched, multisite path patterns no blog_id.
- **Risk:** low, perf positive (avoids 2× prefetch).
- **Complexity:** S, **PR size:** ~6 files.

### 755 — Add native fetchpriority to combined-CSS preload and deferred scripts
- **Problem:** Combined-CSS preload `Cache::maybe_preload_combine_css()` calls `Util::generate_preload_link($url,'preload','style')` without `fetchpriority=high`; deferred scripts via `Main::add_defer_strategy()` do `fetchpriority low` correctly on 6.9+ but preload missing.
- **Current:** `class-cache.php:590` no fetchpriority, `class-util.php:415` `generate_preload_link()` already accepts `$fetchpriority` and emits, `class-main.php:1925` deferred low done at `1948 version_compare 6.9-alpha` + `2285 add_fetchpriority_to_deferred` fallback disabled on 6.9+.
- **Why deferred:** Needs browser hint verification (view source) + not urgent (unknown attr harmless).
- **Recommended:** When `!will_combine_css_inline()` (external preload path) pass `fetchpriority=high` via `Util::generate_preload_link(..., 'high')` or `wp_style_add_data('wppo-combine-css','fetchpriority','high')` when handle exists; expose `wppo_combine_preload_fetchpriority` filter default high/auto; guard `function_exists('wp_style_add_data')` + version gate.
- **Files:** `class-cache.php` (maybe_preload_combine_css), `class-util.php`, `docs/hooks.md`
- **Tests:** View source `rel=preload as=style fetchpriority=high` on 6.9+, filter low, WP<6.9 no attr.
- **Risk:** low additive, unknown values ignored.
- **Complexity:** XS, **PR size:** ~4 files.

### 757 — Align image lazy/auto-sizes pipeline with Core helpers
- **Problem:** `Image_Optimisation::add_delay_load_img()` forces `loading=lazy` when `lazyLoadNative=true`, bypassing Core's first-N LCP protection (threshold 3) + `template_part_header`, risks LCP regression; missing allow-list for `wp_enqueue_img_auto_sizes_contain_css_fix()` handle when minifyCSS+combined active.
- **Current:** `class-image-optimisation.php:1478 apply_loading_optimization_attributes` gates `wp_get_loading_optimization_attributes` for fetchpriority low but add_delay_load_img still unconditional; containment fix handle not allow-listed in `Main::minify_queued_styles()`.
- **Why deferred:** Medium risk LCP, needs mobile/desktop viewport groups (OD), 6.7/6.9/7.0 matrix.
- **Recommended:** In `add_delay_load_img()` when `lazyLoadNative=true && function_exists('wp_get_loading_optimization_attributes')` call `wp_get_loading_optimization_attributes('img',$attr,'performance_optimisation_delay_load')` and honour `loading` (omit when Core says not lazy), `fetchpriority`, `decoding`; else legacy `loading=lazy`. In `style_loader_tag`/`minify_queued_styles` allow-list `img-auto-sizes-contain` handle (detect via `function_exists('wp_enqueue_img_auto_sizes_contain_css_fix')`).
- **Files:** `class-image-optimisation.php` (add_delay_load_img + helper), `class-main.php` (minify allow-list), `src/lazyload.js` (retain restoreSizes probe), `docs/hooks.md`
- **Tests:** Manual mobile/desktop LCP (Performance Lab OD), 6.3+ first 3 not lazy, 7.0 hidden fetchpriority low, 6.9+ containment not minified, <6.3 legacy.
- **Compat:** `function_exists` both guards, JS probe retains fallback.
- **Risk:** medium (LCP), **Complexity:** M, **PR size:** ~6 files.

### 756 — Guard object-cache group flush with wp_cache_supports
- **Problem:** `Rest::clear_cache(group=>...)` → `Cache::flush_group()` calls `wp_cache_flush_group` without checking backend support; Core polyfills unsupported as flush all groups (surprise).
- **Current:** Already implemented — `class-cache.php:2334` checks `function_exists('wp_cache_supports') && !wp_cache_supports('flush_group') return false` before `wp_cache_flush_group()`, fallback `method_exists($wp_object_cache,'flush_group')`. `class-rest.php:371` handles `$_REQUEST['group']` → `Cache::flush_group()` and `send_response 500` with `Log::add`.
- **Why deferred (OBSOLETE/DONE minor polish):** Only message wording delta to spec (`400 "Object cache does not support flush_group — no action taken"` + SPA useNotice warning) and docs comment for future `wp_cache_get_salted` pattern (already at `class-cache.php:2362` noted). No behavior change on Redis/Memcached.
- **Recommended if polish PR:** Change REST error to 400 + message, SPA warning type warning, add inline comment referencing salted family.
- **Files:** `class-cache.php`, `class-rest.php`, `docs/hooks.md`
- **Risk:** low, **Complexity:** XS.

### 758 — Register performance operations as Abilities (WP 6.9+)
- **Problem:** WP 6.9 Abilities `wp_register_ability_category/ability` + REST `wp-abilities/v1` not yet exposed for `clear-cache`, `system-info`, `pagespeed` etc.
- **Current:** Already implemented — `class-abilities.php:28` `__construct` wires `wp_abilities_api_categories_init/init`, `register_categories:36` + `register_abilities:56` gated `function_exists('wp_register_ability')`, ~12 abilities delegating to `Cache/System_Info/Telemetry` with `permission_callback manage_options` + `readonly/destructive` meta.
- **Why deferred (OBSOLETE/DONE docs polish):** Only `docs/hooks.md` entry for `.no-prefetch` + abilities missing.
- **Recommended if polish PR:** Add docs/hooks.md entry, verify `WP_Ability::check_permissions` unauthed returns WP_Error (manual).
- **Files:** `docs/hooks.md` (maybe class-abilities.php comment)
- **Risk:** medium (new REST surface, cap audit), **Complexity:** XS.

## P2 — Useful (none beyond P1 engineering)

## Dependencies & Suggested Order
1. **756/755** (XS, low, independent) → 2. **754** (S, speculation) → 3. **757** (M, LCP, needs viewport) → 4. **758** (XS docs, last). All guarded, no DB migration, no schema change, `@since NEXT`.

## PR Sizing
Each ≤6 files + 1 test + 1 doc, <100 limit, must pass `npm lint → phpcs → npm test 34/34 → phpunit 435/435 → npm build`.

## References
- WP 6.8 Field Guide Speculation Rules, 6.9 fetchpriority (#61734), salted cache (#59592), Abilities, 6.7 sizes auto + 6.9 contain fix + 6.3-7.0 wp_get_loading_optimization_attributes
- Plugin `class-main.php:553,759,1925,2285`, `class-cache.php:590,2334`, `class-image-optimisation.php:1478`, `class-abilities.php:28`, `Util::process_urls`, `WP_Speculation_Rules`, `WP_URL_Pattern_Prefixer`
