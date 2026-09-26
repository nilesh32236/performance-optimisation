import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { redactResult } from '../lib/redact.mjs';

test('redacts credential-shaped fields recursively', () => {
  const value = redactResult({ model_id: 'free-model', nested: { apiKey: 'secret', cookie: 'secret', authorization: 'secret', password: 'secret' }, safe: 'ok' });
  assert.deepEqual(value, { model_id: 'free-model', nested: { apiKey: '[REDACTED]', cookie: '[REDACTED]', authorization: '[REDACTED]', password: '[REDACTED]' }, safe: 'ok' });
});

test('configuration keeps Muse Spark as the sole fallback reference', async () => {
  const config = JSON.parse(await readFile(new URL('../config/champion.json', import.meta.url)));
  assert.equal(config.policy, 'free-only');
  assert.equal(config.champion.model_ref, 'opencode/muse-spark-1.3-contributor-free');
  assert.equal(config.promotion.minimum_samples >= 5, true);
});
