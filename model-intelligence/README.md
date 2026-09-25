# Free model intelligence

This directory measures free OpenCode models without changing production routing. Muse Spark remains the champion:

`opencode/muse-spark-1.3-contributor-free`

The registry is data, not a claim about model quality. A catalog entry proves only that provider metadata was found. Health, capability, and coding promotion require separate executable evidence.

## Policy

- Only models with positive zero input/output pricing and a catalog free label enter the candidate pool.
- Unknown price, missing price, paid price, or missing provider metadata stays out of the pool.
- Explicit model selection remains authoritative.
- Muse Spark stays champion until a challenger meets sample, verification, health, failure-rate, task-category, and hysteresis gates.
- No model is promoted from one health response or one successful run.

## Discovery

Run discovery from the repository root:

```bash
node model-intelligence/refresh.mjs --write
```

The default source is `https://models.dev/api.json`. Override it with `MODEL_CATALOG_URL` for an authorized provider-compatible catalog. Requests have a 20-second timeout. A failed or malformed catalog preserves the previous known-good registry and records the discovery error in the returned object. Only a successful refresh writes the registry and report.

The committed `registry/free-models.json` is a dated snapshot. It is a cache, not a permanent source of model truth.

## Evaluation

Three levels are represented:

1. **Health probe:** `healthProbe()` checks that a model can respond within a bounded command timeout.
2. **Capability probe:** `capabilityProbe()` checks a bounded model invocation before repository changes are trusted.
3. **Executable coding benchmark:** `benchmark.mjs` verifies deterministic fixtures for TypeScript, JavaScript, PHP, WordPress, React, GitHub Actions, refactoring, bug fixing, security, tests, documentation, and readiness boundaries.

Run the deterministic suite:

```bash
node model-intelligence/benchmark.mjs
```

Run the same tasks through an explicitly selected model in a temporary directory:

```bash
MODEL_REF=opencode/muse-spark-1.3-contributor-free pnpm benchmark:model:run
```

The model runner never receives repository credentials and stores only redacted result metadata.

Run a bounded provider probe only when the command and credentials are explicitly available:

```bash
MODEL_REF=opencode/muse-spark-1.3-contributor-free MODEL_EVAL_MODE=health node model-intelligence/evaluate.mjs
```

Each execution writes a redacted result line with model, task, repository, language, framework, complexity, verification, tests, lint, build, tool, latency, timeout, retries, error category, benchmark result, and timestamp.

## Routing safety

`lib/scoring.mjs` provides scoring and promotion decisions only. It does not change the workflows that currently use `vars.OPENCODE_MODEL || opencode/muse-spark-1.3-contributor-free`.

The scorer uses global results as a prior. Repository-specific results become eligible only after three samples. A challenger needs at least five samples, healthy recent probes, acceptable failure rate, better verification, meaningful score and task-success gains, and no important-category regression. Hysteresis prevents a single result from oscillating routing.

The current Muse Spark baseline is explicit in `results/muse-spark-baseline.json`. Existing AI review and CI passes are recorded as workflow evidence, not coding-benchmark scores. Controlled task success, latency, tool reliability, security findings, and quality findings remain unmeasured until the benchmark runs.

## Privacy

The collector stores catalog metadata, bounded health status, and redacted evaluation results. It does not store prompts, credentials, cookies, authorization headers, raw provider responses, visitor data, or site-owner data. The workflow uses bounded concurrency, one retry, and timeouts. Catalog failure does not overwrite the last known-good registry.

## Commands

```bash
pnpm build
pnpm typecheck
pnpm test
pnpm lint
pnpm doc:check
pnpm test:model-registry
pnpm benchmark:model
pnpm model:refresh
pnpm model:score
```
