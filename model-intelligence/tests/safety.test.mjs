import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { redactResult } from '../lib/redact.mjs';

const run = promisify(execFile);
const here = dirname(fileURLToPath(import.meta.url));
const root = dirname(dirname(here));
const CHAMPION = 'opencode/muse-spark-1.3-contributor-free';

test('redacts credential-shaped fields recursively', () => {
  const value = redactResult({ model_id: 'free-model', nested: { apiKey: 'secret', cookie: 'secret', authorization: 'secret', password: 'secret' }, safe: 'ok' });
  assert.deepEqual(value, { model_id: 'free-model', nested: { apiKey: '[REDACTED]', cookie: '[REDACTED]', authorization: '[REDACTED]', password: '[REDACTED]' }, safe: 'ok' });
});

test('champion configuration declares Muse Spark under a free-only policy', async () => {
  const config = JSON.parse(await readFile(new URL('../config/champion.json', import.meta.url)));
  assert.equal(config.policy, 'free-only');
  assert.equal(config.champion.model_ref, CHAMPION);
  assert.equal(config.promotion.minimum_samples >= 5, true);
});

// The check-config.mjs guard is the only executable proof that the workflows
// and AGENTS.md still carry the vars.OPENCODE_MODEL fallback and that no other
// opencode/<model> literal has appeared. The test below used to be named as if
// it covered that, but it only asserted three champion.json fields and never
// ran the guard — so the guarantee existed as prose alone.
test('check-config.mjs passes and reports Muse Spark as the sole fallback', async () => {
  const { stdout } = await run(process.execPath, [join(root, 'model-intelligence/check-config.mjs')], { cwd: root });
  const result = JSON.parse(stdout);
  assert.equal(result.ok, true, `model authority check failed: ${stdout}`);
  assert.equal(result.champion, CHAMPION);
  assert.equal(result.explicit_selection_preserved, true);
  assert.ok(result.occurrences > 0, 'the guard must actually inspect the declared fallback locations');
});

// Pins the wiring itself. The guard was written, passed, and had no caller at
// all, so nothing failed when the invariant lapsed; these assertions fail if the
// script is un-referenced again.
test('the model authority guard is wired into package scripts and CI', async () => {
  const pkg = JSON.parse(await readFile(join(root, 'package.json'), 'utf8'));
  assert.equal(pkg.scripts['model:check'], 'node model-intelligence/check-config.mjs');

  const workflowDir = join(root, '.github/workflows');
  const files = (await readdir(workflowDir)).filter((name) => name.endsWith('.yml'));
  const callers = [];
  for (const name of files) {
    const text = await readFile(join(workflowDir, name), 'utf8');
    if (text.includes('model:check')) callers.push(name);
  }
  assert.ok(
    callers.includes('webpack.yml'),
    `webpack.yml must run the model authority check on pull requests; found callers: ${callers.join(', ') || 'none'}`
  );
});
