import test from 'node:test';
import assert from 'node:assert/strict';
import { promotionDecision, scoreCandidate, summarizeResults } from '../lib/scoring.mjs';

const now = new Date().toISOString();
const result = (ref, taskType, ok, repository = 'nilesh32236/performance-optimisation') => ({
  model_provider: ref.split('/')[0], model_id: ref.split('/')[1], task_type: taskType, repository,
  language: 'JavaScript', framework: 'Node', complexity: 'small', success: ok, verification_success: ok,
  test_result: ok ? 'pass' : 'fail', lint_result: ok ? 'pass' : 'fail', build_result: ok ? 'pass' : 'fail',
  security_success: taskType === 'security' ? ok : null, tool_success: ok, latency_ms: ok ? 1000 : 2000,
  timeout: false, retry_count: 0, error_category: ok ? null : 'verification', benchmark_result: ok ? 'pass' : 'fail',
  timestamp: now,
});
const promotion = {
  minimum_samples: 5, minimum_repository_samples: 3, minimum_verification_success: 0.8,
  maximum_failure_rate: 0.2, minimum_score_improvement: 0.05, minimum_task_success_improvement: 0.05,
  required_health: 'healthy', important_categories: ['security', 'bug-fixing', 'refactoring', 'tests', 'documentation'],
};

test('one successful run cannot promote a challenger', () => {
  const champion = 'opencode/muse-spark-1.3-contributor-free';
  const decision = promotionDecision({
    champion: { model_ref: champion }, challengers: [{ model_ref: 'opencode/challenger-free' }],
    promotion, resultsByModel: { [champion]: [], 'opencode/challenger-free': [result('opencode/challenger-free', 'refactoring', true)] },
  });
  assert.equal(decision.promote, null);
});

test('global prior is used until repository samples exist', () => {
  const results = [result('opencode/model', 'tests', true, 'other/repo'), result('opencode/model', 'tests', true, 'other/repo')];
  const summary = summarizeResults(results, { repository: 'nilesh32236/performance-optimisation', minimumRepositorySamples: 3 });
  assert.equal(summary.repository_specific, false);
  assert.equal(summary.samples, 2);
});

test('a healthy challenger needs enough evidence and no category regression', () => {
  const champion = 'opencode/muse-spark-1.3-contributor-free';
  const challenger = 'opencode/challenger-free';
  const championResults = ['security', 'bug-fixing', 'refactoring', 'tests', 'documentation'].map((task) => result(champion, task, false));
  const challengerResults = ['security', 'bug-fixing', 'refactoring', 'tests', 'documentation'].map((task) => result(challenger, task, true));
  const decision = promotionDecision({
    champion: { model_ref: champion }, challengers: [{ model_ref: challenger }], promotion,
    resultsByModel: { [champion]: championResults, [challenger]: challengerResults },
  });
  assert.equal(decision.promote.model_ref, challenger);
  assert.deepEqual(decision.promote.regressed_categories, []);
  assert.ok(scoreCandidate(challengerResults).score > scoreCandidate(championResults).score);
});
