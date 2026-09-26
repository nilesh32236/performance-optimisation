import { appendFile } from 'node:fs/promises';
import { performance } from 'node:perf_hooks';
import { healthProbe, capabilityProbe } from './lib/probe.mjs';
import { redactResult } from './lib/redact.mjs';

const [modelProvider = 'opencode', modelId = 'unknown'] = (process.env.MODEL_REF ?? 'opencode/unknown').split('/', 2);
const mode = process.env.MODEL_EVAL_MODE ?? 'health';
const taskType = process.env.MODEL_TASK_TYPE ?? (mode === 'health' ? 'health-probe' : 'capability-probe');
const now = () => new Date().toISOString();
const resultPath = process.env.MODEL_RESULTS_PATH ?? 'model-intelligence/results/results.jsonl';
const started = performance.now();

const result = {
  model_provider: modelProvider,
  model_id: modelId,
  task_type: taskType,
  repository: process.env.MODEL_REPOSITORY ?? 'nilesh32236/performance-optimisation',
  language: process.env.MODEL_LANGUAGE ?? null,
  framework: process.env.MODEL_FRAMEWORK ?? null,
  complexity: process.env.MODEL_COMPLEXITY ?? 'probe',
  success: false,
  verification_success: false,
  test_result: 'not-run',
  lint_result: 'not-run',
  build_result: 'not-run',
  security_success: null,
  tool_success: false,
  latency_ms: 0,
  timeout: false,
  retry_count: 0,
  error_category: null,
  benchmark_result: 'not-run',
  quality_findings: [],
  security_findings: [],
  timestamp: now(),
};

try {
  if (!process.env.MODEL_REF) throw new Error('MODEL_REF is required; no probe was run');
  const maxRetries = Math.min(1, Math.max(0, Number(process.env.MODEL_MAX_RETRIES ?? 1)));
  let probe;
  for (let attempt = 0; attempt <= maxRetries; attempt += 1) {
    probe = mode === 'capability'
      ? await capabilityProbe({ modelRef: process.env.MODEL_REF, timeoutMs: Number(process.env.MODEL_TIMEOUT_MS ?? 90000) })
      : await healthProbe({ modelRef: process.env.MODEL_REF, timeoutMs: Number(process.env.MODEL_TIMEOUT_MS ?? 30000) });
    result.retry_count = attempt;
    if (probe.success) break;
  }
  result.success = probe.success;
  result.verification_success = probe.success;
  result.tool_success = probe.success;
  result.test_result = probe.success ? 'pass' : 'fail';
  result.timeout = probe.timeout;
  result.error_category = probe.error_category;
  result.latency_ms = Math.round(probe.latency_ms);
} catch (error) {
  result.error_category = error.message;
} finally {
  result.latency_ms ||= Math.round(performance.now() - started);
  await appendFile(resultPath, `${JSON.stringify(redactResult(result))}\n`, { mode: 0o600 });
  console.log(JSON.stringify(redactResult(result)));
  if (!result.success) process.exitCode = 1;
}
