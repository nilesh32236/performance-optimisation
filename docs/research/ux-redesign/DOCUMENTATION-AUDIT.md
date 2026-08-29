# DOCUMENTATION-AUDIT.md — User vs Developer Docs

**Inspect:** `README readme.txt docs/ AUDIT/ docs/hooks.md` (493 lines 30+ hooks) `WP-CLI docs/research/wp-cli-hooks/` 22 files 6152 lines `class-rest.php` 28 endpoints doc says 25 stale `Agent N` `performance-optimisation.php` `package.json` scripts.

## Current Docs
- `readme.txt:4-6` Requires 6.2 Tested 7.1 Stable 1.9.0 — Description "Simple to use: Enable what you need, leave rest off. No guesswork. — Powerful — Safe by default aggressive off with warnings" good but fragmented across tags/cache/file/image/preload/db/redis/monitor/developer sections — dev-facing last.
- `docs/hooks.md:493` Action `wppo_before/after_cache_clear` `wppo_database_cleanup_completed` per-type `NEXT` + Filters `wppo_exclude_delay_js` etc `wppo_should_cache_request:1524` `wppo_invalidation_urls:1920` `wppo_object_cache_config:213` — dev only, no user help.
- `docs/competitive-audit-2026.md:151` matrix 27 rows + over/under `73-85` + novel `88-109` — product strategy not user doc.
- `docs/litespeed-research.md` + `litespeed-integration-plan.md` + `litespeed-roadmap.md` — LS deep, not user.
- `AUDIT/` 22 docs `CODE-INVENTORY.md` `MASTER-AUDIT-2026-08-28.md` etc — internal.
- `WP_CLI docs/research/wp-cli-hooks/WP-CLI-CURRENT.md 76` `reality vs handbook` — dev.
- `docs/DOUBTS.md` `performance-report-2026-08-27.md` — internal.

## Problems
- Too technical for user: `docs/hooks.md` 30 filters with `apply_filters wppo_should_cache_request true $uri $mobile $logged` `class-cache.php:1524` — correct for dev but no user explain "What does Delay do?".
- Repetitive: RUM+Telemetry+Suggestions fragmented `competitive-audit-2026.md:79-80` "don't fragment" vs Dashboard 8 cards.
- Outdated: REST 25 vs 28 `Agent N`, `wppo_settings` 11/14 `class-util.php:43` missing tabs, `package.json` scripts `wp-scripts build src/index.js src/lazyload.js src/main.js src/rum.js` but docs say 2 entries `AGENTS.md:Build` stale.
- Written for developers not users: `docs/hooks.md` no "Why would I use this?" vs "Make pages faster".
- Missing practical: no "Recommended setup for blog vs Woo vs membership" guide.

## Separation
- **User docs:** `readme.txt` Description + new `docs/user/` (Install, Recommended setup, Health, Media, Troubleshooting, FAQ "Delay broke checkout?") — short explanation + Learn more link, not giant description under every setting `§15`.
- **Developer docs:** `docs/hooks.md` already good + `docs/wp-cli.md` from `WP-CLI-CURRENT.md` + `docs/rest.md` 28 endpoints `class-rest.php:78-300` + `docs/cron.md` `wppo_*` 15 events.
- **Technical reference:** `AUDIT/CODE-INVENTORY.md` + `docs/competitive-audit-2026.md` matrix — keep internal.

## Recommendation
- Redesign help per §15: Short explanation "Make files smaller — removes whitespace — saves 10%" + "Learn more" link to `docs/user/file-optimization.md` + "Advanced details" collapsed — not giant 5-line under every toggle `FileOptimization.js:359-382`.

