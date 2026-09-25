import { appendFile, mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runBoundedCommand } from './lib/probe.mjs';
import { verifyTask, loadTasks } from './benchmark.mjs';
import { redactResult } from './lib/redact.mjs';

const root = dirname(dirname(fileURLToPath(import.meta.url)));

async function readSolution(directory) {
  const files = {};
  async function walk(current, prefix = '') {
    const entries = await import('node:fs/promises').then(({ readdir }) => readdir(current, { withFileTypes: true }));
    for (const entry of entries) {
      const path = join(current, entry.name);
      const relative = join(prefix, entry.name);
      if (entry.isDirectory()) await walk(path, relative);
      else files[relative] = await readFile(path, 'utf8');
    }
  }
  await walk(directory);
  return files;
}

export async function runModelTask(task, { modelRef, command = process.env.OPENCODE_BIN || 'opencode', timeoutMs = 300000 } = {}) {
  const directory = await mkdtemp(join(tmpdir(), 'wppo-model-benchmark-'));
  const started = Date.now();
  let verification = { success: false, failures: ['command did not complete'] };
  let commandResult;
  try {
    for (const [path, content] of Object.entries(task.seed_files ?? {})) {
      const target = join(directory, path);
      await mkdir(dirname(target), { recursive: true });
      await writeFile(target, content);
    }
    commandResult = await runBoundedCommand(command, ['run', '--standalone', '--auto', '--model', modelRef], {
      cwd: directory,
      timeoutMs,
      input: `${task.prompt}\nWork only in the current temporary directory. Do not access credentials or external services.`,
      env: { PATH: process.env.PATH, HOME: process.env.HOME, OPENCODE_API_KEY: process.env.OPENCODE_API_KEY, OPENAI_API_KEY: process.env.OPENAI_API_KEY },
    });
    verification = verifyTask(task, await readSolution(directory));
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
  return {
    model_provider: modelRef.split('/')[0],
    model_id: modelRef.split('/').slice(1).join('/'),
    task_type: task.task_type,
    repository: 'nilesh32236/performance-optimisation',
    language: task.language,
    framework: task.framework,
    complexity: task.complexity,
    success: Boolean(commandResult?.success && verification.success),
    verification_success: verification.success,
    test_result: verification.success ? 'pass' : 'fail',
    lint_result: 'not-run',
    build_result: 'not-run',
    security_success: task.task_type === 'security' ? Boolean(commandResult?.success && verification.success) : null,
    tool_success: Boolean(commandResult?.success),
    latency_ms: commandResult?.latency_ms ?? Date.now() - started,
    timeout: Boolean(commandResult?.timeout),
    retry_count: 0,
    error_category: commandResult?.error_category ?? (verification.success ? null : 'verification'),
    benchmark_result: verification.success ? 'pass' : 'fail',
    quality_findings: [],
    security_findings: [],
    timestamp: new Date().toISOString(),
    failures: verification.failures,
  };
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const modelRef = process.env.MODEL_REF;
  if (!modelRef) throw new Error('MODEL_REF is required');
  const requested = new Set((process.env.MODEL_TASKS ?? '').split(',').filter(Boolean));
  const tasks = (await loadTasks()).filter((task) => !requested.size || requested.has(task.id));
  const resultsPath = process.env.MODEL_RESULTS_PATH ?? join(root, 'model-intelligence/results/results.jsonl');
  for (const task of tasks) {
    const result = redactResult(await runModelTask(task, { modelRef, timeoutMs: Number(process.env.MODEL_BENCHMARK_TIMEOUT_MS ?? 300000) }));
    await appendFile(resultsPath, `${JSON.stringify(result)}\n`, { mode: 0o600 });
    console.log(JSON.stringify(result));
  }
}
