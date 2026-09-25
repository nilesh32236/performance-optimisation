import test from 'node:test';
import assert from 'node:assert/strict';
import { verifyFixtureSuite, loadTasks } from '../benchmark.mjs';

test('deterministic benchmark covers required task categories', async () => {
  const tasks = await loadTasks();
  const categories = new Set(tasks.map((task) => task.task_type));
  for (const required of ['TypeScript', 'JavaScript', 'PHP', 'WordPress', 'React', 'GitHub Actions', 'refactoring', 'bug fixing', 'security', 'tests', 'documentation']) {
    assert.equal(categories.has(required), true, `missing ${required}`);
  }
});

test('all deterministic benchmark fixtures pass executable checks', async () => {
  const results = await verifyFixtureSuite();
  assert.equal(results.length, 11);
  assert.deepEqual(results.filter((result) => !result.success), []);
});
