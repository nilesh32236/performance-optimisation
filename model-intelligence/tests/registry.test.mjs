import test from 'node:test';
import assert from 'node:assert/strict';
import { discoverFreeModels, isVerifiedFree, refreshRegistry } from '../lib/registry.mjs';

const catalog = {
  opencode: {
    id: 'opencode',
    name: 'OpenCode Zen',
    api: 'https://opencode.ai/zen/v1',
    npm: '@ai-sdk/openai-compatible',
    models: {
      'muse-spark-1.3-contributor-free': { id: 'muse-spark-1.3-contributor-free', name: 'Muse Spark 1.3 Free', cost: { input: 0, output: 0 }, limit: { context: 100, output: 20 }, reasoning: true, attachment: true },
      'paid-model': { id: 'paid-model', name: 'Paid Model', cost: { input: 1, output: 1 }, limit: { context: 100, output: 20 } },
      'unknown-price-model': { id: 'unknown-price-model', name: 'Unknown', limit: { context: 100, output: 20 } },
      'zero-no-label': { id: 'zero-no-label', name: 'Zero', cost: { input: 0, output: 0 }, limit: { context: 100, output: 20 } },
    },
  },
};

test('only positively verified zero-price free models qualify', () => {
  assert.equal(isVerifiedFree(catalog.opencode.models['muse-spark-1.3-contributor-free'], 'opencode'), true);
  assert.equal(isVerifiedFree(catalog.opencode.models['paid-model'], 'opencode'), false);
  assert.equal(isVerifiedFree(catalog.opencode.models['unknown-price-model'], 'opencode'), false);
  assert.equal(isVerifiedFree(catalog.opencode.models['zero-no-label'], 'opencode'), false);
  const models = discoverFreeModels(catalog, { now: '2026-01-01T00:00:00Z' });
  assert.deepEqual(models.map((model) => model.model_id), ['muse-spark-1.3-contributor-free']);
});

test('discovery merges new candidates and preserves first seen', () => {
  const first = refreshRegistry(null, catalog, { now: '2026-01-01T00:00:00Z' }).registry;
  const second = refreshRegistry(first, {
    ...catalog,
    opencode: { ...catalog.opencode, models: { ...catalog.opencode.models, 'qwen-free': { id: 'qwen-free', name: 'Qwen Free', cost: { input: 0, output: 0 }, limit: { context: 200, output: 40 } } } },
  }, { now: '2026-01-02T00:00:00Z' });
  assert.equal(second.status, 'updated');
  assert.equal(second.material, true);
  const muse = second.registry.models.find((model) => model.model_id === 'muse-spark-1.3-contributor-free');
  assert.equal(muse.first_seen, '2026-01-01T00:00:00Z');
  assert.equal(muse.last_verified, '2026-01-02T00:00:00Z');
});

test('successful catalog absence is retained as not-seen rather than treated as free evidence', () => {
  const first = refreshRegistry(null, catalog, { now: '2026-01-01T00:00:00Z' }).registry;
  const next = refreshRegistry(first, { opencode: { ...catalog.opencode, models: { 'other-free': { id: 'other-free', name: 'Other Free', cost: { input: 0, output: 0 } } } } }, { now: '2026-01-02T00:00:00Z' });
  assert.equal(next.status, 'updated');
  assert.equal(next.registry.models.find((model) => model.model_id === 'muse-spark-1.3-contributor-free').availability, 'not_seen_in_latest_catalog');
  assert.equal(next.material, true);
});

test('catalog failure preserves the previous known-good registry', () => {
  const first = refreshRegistry(null, catalog, { now: '2026-01-01T00:00:00Z' }).registry;
  const failed = refreshRegistry(first, null, { now: '2026-01-02T00:00:00Z' });
  assert.equal(failed.status, 'preserved');
  assert.deepEqual(failed.registry.models, first.models);
  assert.equal(failed.registry.last_successful_discovery, '2026-01-01T00:00:00Z');
  assert.equal(failed.registry.last_discovery_error, 'Catalog response is not an object');
});
