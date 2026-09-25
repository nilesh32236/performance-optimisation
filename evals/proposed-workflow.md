# Proposed twice-daily model-eval workflow

> `evals/` automation may not edit `.github/` (repo policy), so the workflow
> below is a **proposal for a maintainer to apply** as
> `.github/workflows/model-eval.yml`. It is bounded and advisory-only.

```yaml
name: model-eval

on:
  schedule:
    # Twice daily, offset from other heavy crons.
    - cron: '30 3 * * *'
    - cron: '30 15 * * *'
  workflow_dispatch: {}

permissions:
  contents: read

concurrency:
  group: model-eval
  cancel-in-progress: false

jobs:
  evaluate:
    runs-on: ubuntu-latest
    timeout-minutes: 20
    steps:
      - uses: actions/checkout@v4

      - uses: actions/setup-node@v4
        with:
          node-version: '22.14.0'
          cache: npm

      - run: npm ci --ignore-scripts

      # Bounded discovery: concurrency <= 4, 15 s per-host timeout,
      # <= 2 retries, ETag/file cache. Never fails the workflow:
      # a known-good registry survives discovery failure.
      - name: Discover (bounded, advisory)
        run: npm run eval:discover || echo 'discovery unavailable; using known-good registry'

      # Deterministic gates, fail-closed (non-zero blocks green checks).
      - name: Registry gate
        run: npm run eval:registry

      - name: Deterministic benchmark
        run: npm run eval:benchmark

      - name: Score (champion/challenger + hysteresis)
        run: npm run eval:score

      - name: Routing safety / privacy / docs
        run: npm run eval:checks

      - name: Upload eval artifacts
        if: always()
        uses: actions/upload-artifact@v4
        with:
          name: model-eval-${{ github.run_id }}
          path: |
            evals/discovery.json
            evals/results.json
            evals/verdict.json
          if-no-files-found: warn
          retention-days: 14
```

## Bounds recap

- Two cron ticks per day (`03:30`, `15:30` UTC), single `model-eval`
  concurrency group, `timeout-minutes: 20` per run.
- Discovery: max 4 concurrent hosts, ~15 s per-host timeout, ≤ 2 retries with
  backoff, 20 MB response cap, ETag/file cache under `evals/.cache/`.
- No secrets are stored (read-only `contents: read`, no model keys in repo);
  results leave the runner only as uploaded artifacts.
- The workflow never opens issues for catalog fluctuations and never rewrites
  `registry.json` or production routing — promotion stays human-approved.
