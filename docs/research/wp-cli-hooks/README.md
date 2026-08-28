# WP-CLI Expansion + Hook Availability — Research & Implementation Plan

**Base:** `fix/audit-2026-08-28` `68a2f66a` → `master@31fffc61` (42 PHP 31k, 7 CLI subcommands)  
**Mode:** Research + planning only — **no production code modified** (all `includes/*.php` unchanged, `git diff -- includes/` empty)  
**Agents:** 11 specialized (A WP-CLI, B UX, C Hook Arch, D Extensibility, E Core Hooks, F Perf, G Compat, H Tests, I Arch, J Competitive, K Adversarial) + synthesis

## Reading order

1. `CURRENT-STATE.md` — 7 subcommands `cache|database|image|settings|object-cache|pagespeed|system-info` + hook inventory 272 hits
2. `WP-CLI-CURRENT.md` (880 lines) — per-subcommand evidence `File:Line`, args, validation, exit codes, gaps vs admin UI
3. `WP-CLI-RESEARCH.md` (76KB) — handbook `make.wordpress.org/cli` vs Woo/Rocket/LSCWP conventions
4. `WP-CLI-GAPS.md` — feature→CLI matrix, hierarchy `wp wppo` kept, no `wppo` alias
5. `HOOK-AUDIT.md` (616 lines) — 272 `add_/do_/apply_` map, priorities, consumers, docs
6. `HOOK-GAPS.md` (32 gaps, 17 cats) — missing extension points P0-3
7. `HOOK-PROPOSED-DESIGN.md` — retained `wppo_*` Hook name / Action-filter / File:Line / Args / Example
8. `WP-CLI-PROPOSED-DESIGN.md` — CLI for automation: `--format`/`--yes`/`--dry-run`/`--network`/`--batch-size`/progress
9. `ECOSYSTEM-RESEARCH.md` (67KB) — Woo 616 lines, Rocket, LSCWP 8 families, Super Cache, search-replace patterns
10. `HOOK-CORE-RESEARCH.md` + `PERF-RESEARCH.md` + `ARCH-RESEARCH.md` + `MIGRATION-COMPATIBILITY.md` + `TEST-PLAN.md`
11. `BRAINSTORM.md` (A/B/C) + `OPTIONS-COMPARISON.md` (Impact/Effort/Risk) + `ADVERSARIAL-REVIEW.md` (RETAIN/MODIFY/REJECT)
12. **`IMPLEMENTATION-PLAN.md`** ← master, implementation-ready (this is what the coding run executes)
13. `IMPLEMENTATION-MATRIX.md` — file-level Priority|Area|File|Class/Function|Change|Reason|Deps|Tests
14. `RISK-REGISTER.md` — likelihood/impact/mitigation per phase
15. `TEST-PLAN.md` (59K) — 115 new tests → 586 total, Brain Monkey harness

## How to use IMPLEMENTATION-PLAN

The coding run should execute **Phase 1 → 6** in order, each `php -l` + `phpcs` + `phpunit` + `lint:js` + `build` per phase, `@since NEXT`, never bump `1.9.0`, commit `build/`. The plan lists exact `File:Line` and `Class/Function` for every change, so no questions are needed.

## No production code modified

`git diff --stat origin/master...HEAD` shows only `docs/research/wp-cli-hooks/` added. Phase PRs will be `fix/cli-phase-*` → `gh pr checks` 5-10m → fix real → merge.

