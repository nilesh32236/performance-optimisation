import { spawn } from 'node:child_process';
import { performance } from 'node:perf_hooks';

export function runBoundedCommand(command, args, { timeoutMs = 30000, cwd = process.cwd(), env = process.env, input = '' } = {}) {
  return new Promise((resolve) => {
    const started = performance.now();
    const child = spawn(command, args, { cwd, env, stdio: ['pipe', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      child.kill('SIGTERM');
    }, timeoutMs);
    child.stdout.on('data', (chunk) => { stdout += chunk.toString(); });
    child.stderr.on('data', (chunk) => { stderr += chunk.toString(); });
    child.stdin.end(input);
    child.on('error', (error) => {
      clearTimeout(timer);
      resolve({ success: false, timeout: timedOut, latency_ms: performance.now() - started, stdout, stderr: error.message, error_category: 'spawn' });
    });
    child.on('close', (code) => {
      clearTimeout(timer);
      resolve({ success: code === 0 && !timedOut, timeout: timedOut, exit_code: code, latency_ms: performance.now() - started, stdout, stderr, error_category: timedOut ? 'timeout' : code === 0 ? null : 'command' });
    });
  });
}

function safeProviderEnv(extra = {}) {
  return {
    PATH: process.env.PATH,
    HOME: process.env.HOME,
    OPENCODE_API_KEY: process.env.OPENCODE_API_KEY,
    OPENAI_API_KEY: process.env.OPENAI_API_KEY,
    ...extra,
  };
}

export async function healthProbe({ modelRef, command = process.env.OPENCODE_BIN || 'opencode', timeoutMs = 30000, cwd = process.cwd() } = {}) {
  if (!modelRef) throw new Error('modelRef is required');
  return runBoundedCommand(command, ['run', '--standalone', '--auto', '--model', modelRef], { timeoutMs, cwd, input: 'Respond with HEALTH_OK. Do not edit files or use tools.', env: safeProviderEnv({ MODEL_HEALTH_PROBE: '1' }) });
}

export async function capabilityProbe({ modelRef, command = process.env.OPENCODE_BIN || 'opencode', timeoutMs = 90000, cwd = process.cwd() } = {}) {
  if (!modelRef) throw new Error('modelRef is required');
  return runBoundedCommand(command, ['run', '--standalone', '--auto', '--model', modelRef], { timeoutMs, cwd, input: 'Return exactly {"protocol":"openai-compatible","capability":"ok","tools":"available"}. Do not edit files.', env: safeProviderEnv({ MODEL_CAPABILITY_PROBE: '1' }) });
}
