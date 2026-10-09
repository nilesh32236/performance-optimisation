# wppo-agent-rules.md — Watchdog Guardrails for AI Agent Loops

**Read this file FIRST, before any audit, monitor, autofix, review, merge, or release work in this repo.**
It sits above per-run instructions. If a workflow prompt conflicts with this file, this file wins.
Modular detail lives in `AGENTS.md`, `.agents/AGENTS.md`, and `docs/` — load those bit by bit per Module 0.

## Module 0 — How to load rules (bit by bit, every run)

1. Read this file fully (it is short by design).
2. Read `AGENTS.md` sections as needed for the task (commands, architecture, REST, PHP/JS conventions, build).
3. Read `.agents/AGENTS.md` for agent permissions + merge gate (confidence >= 95% + all verification green).
4. Read only the `docs/` module relevant to the task:
   - LiteSpeed work → `docs/litespeed-integration-plan.md`, `docs/litespeed-roadmap.md`, `docs/litespeed-research.md`
   - Hooks/filters → `docs/hooks.md`
   - Compat → `docs/php-84-85-compat.md`, `docs/wordpress-7x-readiness.md`
5. Never invent version numbers. New code gets `@since NEXT` (replaced at release). Current release line: `2.4.0` (`performance-optimisation.php:8`, `WPPO_VERSION`).

## Module 1 — Protected owner decisions (do NOT re-litigate)

- **LiteSpeed ESI bridge STAYS.** `includes/Integrations/class-litespeed-esi.php` is deliberate (LSWS Enterprise ESI; OLS → disabled path).
  - Issue #1291 ("Delete Enterprise only LiteSpeed ESI bridge") is **WONTFIX** by owner decision (2026-09-17). Do not re-open it.
  - Deletion PR #1301 was **closed unmerged** with its branch deleted before anything merged. Do not re-create it.
  - PR #1249 nonce hardening (ESI POST-only nonce, per-key dismiss nonces, cookie SameSite) must NOT be reverted.
- Coexistence modes (`auto`/`wppo`/`litespeed`/`standalone`) and header protocol (`X-LiteSpeed-*`) stay per `docs/litespeed-integration-plan.md`.

## Module 2 — Forbidden proposals (close as WONTFIX, do not open PRs)

- Deleting `includes/Integrations/class-litespeed-esi.php` or its tests (`tests/php/LiteSpeedEsiTest.php`).
- Removing LiteSpeed integration classes to "simplify" (`class-litespeed-integration.php`, `class-litespeed-crawler.php`, `class-litespeed-esi.php`).
- Switching plugin classes to PSR-4 autoload (Composer generates a plugin classmap, while `Main::includes()` and `Loader_Map` provide explicit runtime/stale-classmap loading).
- Adding a routing or state-management library to the React SPA (tab switching is `useState` + conditional rendering; state is `useState` only).
- Committing `vendor/` (git-ignored; installed on demand) or skipping committed `build/` output after JS/SCSS changes.
- Bulk-merging without per-PR diff review (owner flagged over-eager auto-merge as an error).

## Module 3 — Consistency contract (every PR)

- Follow `AGENTS.md` PHP conventions (WPCS, PHP 8.2 min, `function_exists()`/`has_filter()` + version-gated fallbacks), JS conventions (`@wordpress/eslint-plugin/recommended`, only `console.error`/`console.warn`, `.wppo-` BEM-like SCSS, strings via `wppoSettings.translations` with English fallback).
- Shared UI feedback: `useNotice()` + `NoticeBanner` (`role="alert"`, `aria-live` assertive for errors, polite otherwise) — no per-component notification state.
- REST: namespace `performance-optimisation/v1`, `manage_options` + `X-WP-Nonce` (except public `rum_collect` with token + IP rate limiting).
- Multisite: `Util::transient_key()` (`{blog_id}_` prefix), domain-based static cache dirs, `get_current_blog_id()` namespacing in Redis drop-in.
- Responses must be consistent: small scoped diffs, tests for behavior changes, no drive-by refactors, no unrelated file churn.

## Module 4 — Loop discipline (duplicate + merge safety)

- **Before opening any fix PR:** `gh pr list --state open` + `gh issue list --state open` — if a PR/branch already covers the issue, do NOT create a duplicate.
- **Before merging any PR:**
  1. Branch loop is idle: no `in_progress`/`queued` runs for that head branch across ALL workflows (`daily-audit.yml`, `wordpress-monitor.yml`, autofix/review loops), not just audit/monitor.
  2. CI is CLEAN (not UNSTABLE/DIRTY/CONFLICTING).
  3. Manual diff review done locally against repo conventions (required for `autofix:needs-manual-review`; spot-check otherwise).
- Merging a branch mid-loop orphans half-baked commits — never merge a branch with a live loop run.
- Conflict rule for committed build assets (`build/index.asset.php`, `build/index.js`, etc.): keep `--ours`, re-run `npm run build`, commit rebuilt output, push with lease. Never hand-edit built assets.
- Labels: `autofix-trigger`/`analysis:ready` open work; `autofix`, `autofix:ready`, `autofix:needs-manual-review`, `autofix:skipped` track fix state.

## Module 5 — Run cadence (owner-defined)

- **Monitor first fixes all open issues, then monitor runs again.** Never run monitor over a pile of unfixed findings.
- Each monitor cycle fans out to **at least 5–6 daily-audit runs**.
- Each audit cycle is: issue → analyze → PR → AI review **+ manual review** → fix changes → merge to `master` → next daily audit.
- `master` is the release branch (plugin repo). Reviewer repo (`opencode-ai-reviewer`) uses `main`. Do not mix them.

## Module 6 — Verification + release gate

- Required order: `npm run lint:js` → `composer lint` → `npm test` → `npm run build` (plus `composer test` for PHP unit tests where touched). After PHP source changes, run `php scripts/generate-class-inventory.php --check` and review the architecture delta.
- Rebuild after every JS/SCSS change and stage `build/` output.
- Before any wordpress.org release: regression-test old + new functionality (cache generate/serve/purge, minify/defer/delay, lazy load, WebP/AVIF, DB cleanup, Redis object cache, PageSpeed, RUM, CLI `wp wppo` 8 subcommands). Fix breakage first, then release.
- Release tags are `vX.Y.Z` (plugin is at `2.4.0`).

## Module 7 — Evidence before claims

- Never claim "loops idle", "CI green", or "verified" without the command output in hand (`gh run list`, `gh pr view`, lint/test/build logs).
- Quote file/line evidence for review findings. Name coverage limits explicitly.
