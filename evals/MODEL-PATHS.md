# Model selection paths audit

Champion model (initial, unchanged): `opencode/muse-spark-1.3-contributor-free`

Explicit selection is preserved everywhere via the repo variable `OPENCODE_MODEL`
with a champion fallback:

```yaml
model: ${{ vars.OPENCODE_MODEL || 'opencode/muse-spark-1.3-contributor-free' }}
```

No workflow file was edited by the evals foundation. This table is read-only
evidence; the routing-safety check (`evals/check-routing-safety.mjs`) re-verifies
it in CI.

## Executable selection sites (12)

| # | File | Line(s) | Key / flag |
|---|------|---------|------------|
| 1 | `.github/workflows/wppo-ai-review.yml` | 150 | `model:` (review job) |
| 2 | `.github/workflows/wppo-ai-review.yml` | 254 | `model:` (autofix job) |
| 3 | `.github/workflows/wppo-ai-review.yml` | 255 | `review_model:` (autofix job) |
| 4 | `.github/workflows/wppo-ai-review.yml` | 256 | `fix_model:` (autofix job) |
| 5 | `.github/workflows/wppo-ai-review.yml` | 391 | CLI `--model` (fast-review prompt) |
| 6 | `.github/workflows/wppo-ai-review.yml` | 464 | `model:` (re-review job) |
| 7 | `.github/workflows/wppo-ai-review.yml` | 465 | `review_model:` (re-review job) |
| 8 | `.github/workflows/wppo-ai-review.yml` | 466 | `fix_model:` (re-review job) |
| 9 | `.github/workflows/wppo-ai-review.yml` | 588 | `model:` (summary job) |
| 10 | `.github/workflows/wppo-tri-merge-workflow.yml` | 174 | CLI `--model` (merge agent) |
| 11 | `.github/workflows/daily-audit.yml` | 173 | `model:` (audit job) |
| 12a | `.github/workflows/wordpress-monitor.yml` | 344 | CLI `--model` (`wppo-researcher`) |
| 12b | `.github/workflows/wordpress-monitor.yml` | 813 | CLI `--model` (`wppo-issue-publisher`) |

(12a/12b are two CLI sites in one file, so 13 literal occurrences across 12
site rows.)

## Non-executable references (not routing)

- `.github/workflows/wordpress-monitor.yml` (L70-72): comment documenting the
  `OPENCODE_MODEL` variable and its fallback. No model literal executed.
- `AGENTS.md` (model section): documents the same variable/fallback pattern.
- `.github/scripts/setup-opencode.sh` (L7): installer usage comment
  (`opencode run --model <model>` placeholder). No pinned literal.
- `opencode.json`: agent definitions carry no `model` field (kept as-is).

## Invariants (enforced by `check-routing-safety.mjs`)

1. Every executable selection site keeps the `${{ vars.OPENCODE_MODEL || '<champion>' }}`
   shape — explicit selection wins, champion is the fallback.
2. No model literal other than the champion appears in workflow/CLI files.
3. No `evals/` artifact may change production routing; the champion stays until
   executable benchmark evidence justifies promotion (see `evals/score.mjs`).
