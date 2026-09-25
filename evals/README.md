# Model evaluation foundation (`evals/`)

Evidence-based foundation for comparing currently available **free** OpenCode
models by task type. The initial champion is
`opencode/muse-spark-1.3-contributor-free`, and **production routing is
unchanged**: every workflow keeps explicit `vars.OPENCODE_MODEL` selection with
the champion as fallback (audit: `MODEL-PATHS.md`).

## Toolchain mapping (acceptance note)

The issue acceptance cites `pnpm build / typecheck / test / lint / doc:check`,
but this repo is **npm-based** (no pnpm, no TypeScript, no `doc:check`), so the
foundation maps 1:1 onto the repo toolchain instead of adding one:

| Acceptance (`pnpm …`) | Repo equivalent (`npm run …`) |
|---|---|
| `pnpm build` | `npm run build` |
| `pnpm typecheck` | `npm run typecheck` (ESM syntax check; no TS in repo) |
| `pnpm test` | `npm test` (Jest, incl. `evals/*.test.js`) |
| `pnpm lint` | `npm run lint:js` (+ `composer lint` for PHP) |
| `pnpm doc:check` | `npm run doc:check` (`evals/check-docs.mjs`) |

Plus the eval-specific scripts below.

## Scripts

| Script | Command | Purpose |
|---|---|---|
| `eval:registry` | `node evals/check-registry.mjs` | Positive-free gate (numeric `price === 0`), champion pin, no-credentials / no-payload scan |
| `eval:discover` | `node evals/discover.mjs` | Bounded Models.dev catalog discovery (concurrency ≤ 4, 15 s timeout, ≤ 2 retries, ETag/file cache); failure keeps the known-good registry, exit 0 |
| `eval:benchmark` | `node evals/run-benchmark.mjs` | Deterministic fixture run → `evals/results.json` (git-ignored artifact) |
| `eval:score` | `node evals/score.mjs` | Task-aware scoring + priors + sample gates + champion/challenger + hysteresis → verdict JSON |
| `eval:checks` | `node evals/check-all.mjs` | All gates in order (registry → benchmark → score → routing-safety → privacy → docs), fail-closed |
| `typecheck` | — | ESM `--check` across eval scripts |
| `doc:check` | `node evals/check-docs.mjs` | README / MODEL-PATHS / fixture-area coverage |

## Files

- `MODEL-PATHS.md` — read-only audit of all 12 executable model-selection sites.
- `registry.json` — known-good registry (`registry.schema.json` is authoritative for shape).
- `check-registry.mjs` — gate: unknown price is not free; paid/unknown excluded.
- `discover.mjs` — bounded discovery; writes advisory `discovery.json` only, never rewrites `registry.json`, never files issues.
- `benchmarks/fixtures/*.json` — 11 deterministic tasks (typescript, javascript, php, wordpress, react, github-actions, refactoring, bugfix, security, tests, documentation) with pinned inputs + expected checks.
- `benchmarks/manifest.json` — sha256 per fixture; drift fails the run.
- `run-benchmark.mjs` — offline self-check proving fixture determinism; result records carry `latencyMs, timedOut, retries, toolCalls, verification, lint, build, security, error`.
- `score.mjs` — Beta(2,2) global prior, equal weight per area, repo sample gates, hysteresis margin 0.05; promotion is advisory and never edits routing.
- `check-routing-safety.mjs` — re-verifies explicit selection + champion fallback in `.github/workflows` (read-only).
- `check-privacy.mjs` — no credentials / raw payloads; artifacts git-ignored.
- `check-docs.mjs` — README / MODEL-PATHS / area-coverage presence.
- `check-all.mjs` — ordered aggregator.
- `proposed-workflow.md` — twice-daily workflow proposal (lives here because `evals/` may not edit `.github/`; a maintainer applies it).

## Policy summary

- Unknown price is not free (`price` must be numeric `0`).
- Catalog presence does not prove health or coding quality.
- No paid model candidates.
- No broad production model replacement; the champion stays until executable evidence justifies promotion.
- Explicit model selection, SHA binding, fork rejection, human merge approval, green-check gates, fail-closed behavior, retry limits, checkpoint/resume, and artifact validation are preserved (workflows untouched).
- No credentials or raw provider payloads are stored; no model issue is filed for catalog fluctuations.
