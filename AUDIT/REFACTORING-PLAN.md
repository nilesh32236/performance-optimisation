# REFACTORING-PLAN.md — Prioritized, evidence-based

_Do not modify production code during audit. This plan is audit-only; implementation follows explicit request, one batch at a time._

## P1 — God-class фасады (A12 A-01..A-04, 72K)
- `includes/class-main.php:21,169,418` `Main` 2956 → extract `PreloadController`, `StyleController`, `ScriptController`, `LcpController`, `HookRegistry` behind `HookRegistrar` interface; `setup_hooks` McCabe >30 → registry pattern. Confidence HIGH.
- `includes/class-util.php:30` `Util` 643 → split `TransientKeys`, `UrlHelper`, `Sanitizer`, `PathHelper`. Confidence HIGH.
- `src/lib/apiRequest.js:71` mutable `wppoSettings` → `createStore` singleton with `subscribe`. Confidence HIGH.

## P2 — Performance (A09 HIGH×8)
- `get_option(wppo_settings)` 6×/render `P-WP-01` → `WppoSettings::get()` singleton memo per request (measure 3-6 ms). Confidence HIGH.
- Static HTML stampede `P-CACHE-03` → `tmp+rename` atomic + `wp_cache_add` lock. Confidence HIGH.
- RUM per-view `get_option+update_option` `P-DB-01` → buffer to transient queue + `wppo_rum_flush` cron (10k×200KB→1×200KB). Confidence HIGH.
- `combine_css` triple classify `P-CPU-01` + double budget sim 120× `P-CPU-02` → single-pass classify + memo. Confidence MEDIUM.

## P3 — Correctness HIGH (A02/A03/A04)
- `class-abilities.php:270` DB ability enum `trash` vs `trashed_posts` → align + test. Confidence HIGH.
- `class-image-optimisation.php:576` regex fallback loses <source>/poster → `TagProcessor` path. Confidence HIGH.
- `uninstall.php:109` symlink traversal → `is_link` check before `is_dir`. Confidence MEDIUM.
- `class-rest.php:376` `realpath` false-rejects → `wp_normalize_path` fallback. Confidence MEDIUM.

## P4 — Duplication hygiene (A10 20 dupes, A07 54 selectors)
- `includes/class-database-cleanup.php:86` batched DELETE ×5 → `BatchDeleter` trait. Confidence HIGH.
- Settings whitelist triplication `class-rest.php:423/713` vs `PluginSetting.js:22` → single `wppo-allowed-keys.json` + generated JS. Confidence HIGH.
- SCSS dead mixins `flex-center/truncate` + legacy `wppo-switch` → remove + `stylelint --fix`. Confidence MEDIUM.

## P5 — Compatibility (A11 16 finds)
- `readme.txt` External Services disclosure for `pagespeedonline.googleapis.com` + `fonts.googleapis.com` (wp.org gate). Confidence HIGH.
- `Tested up to: 7.1` → `7.0` or `7.2` after smoke on 7.2; `composer.json:55` `php>=8.2` pin justified. Confidence MEDIUM.

## Guardrails per AGENTS.md
- Order: `npm run lint:js` → `composer lint` → `npm test` → `npm run build` → `composer test`. Commit `build/`. Use `@since NEXT`, never bump `1.9.0` during audit batches. Keep opt-in `enabled=false` for wp.org edge features.

## Verification after each batch
- `php -l` + `vendor/bin/phpcs --report=summary` (ignore free-tier `deepseek` err_… outage, infra `8.5 parallel-lint` download), `vendor/bin/phpunit` (401/946), `npm run lint:js` (0 errors), `npm test` (345), `npm run build` (webpack 5.109).
