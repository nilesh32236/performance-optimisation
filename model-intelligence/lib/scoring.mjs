const CATEGORY_WEIGHTS = {
  security: 1.2,
  'bug-fixing': 1.1,
  refactoring: 1.0,
  tests: 1.0,
  documentation: 0.9,
};

function average(values) {
  return values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : 0;
}

function rate(results, field) {
  const values = results.map((result) => typeof field === 'function' ? field(result) : result[field]);
  return values.length ? average(values) : 0;
}

function passFailRate(results, field) {
  const values = results.filter((result) => result[field] === 'pass' || result[field] === 'fail').map((result) => result[field] === 'pass' ? 1 : 0);
  return values.length ? average(values) : null;
}

function recentHealth(results, now = Date.now()) {
  if (!results.length) return 'unknown';
  const recent = results.filter((result) => now - Date.parse(result.timestamp) <= 7 * 86400000);
  if (!recent.length) return 'stale';
  const failures = recent.filter((result) => !result.success).length;
  return failures / recent.length <= 0.2 ? 'healthy' : 'degraded';
}

export function summarizeResults(results, { repository = null, minimumRepositorySamples = 3 } = {}) {
  const relevant = repository
    ? results.filter((result) => result.repository === repository)
    : results;
  const useRepository = relevant.length >= minimumRepositorySamples;
  const selected = useRepository ? relevant : results;
  const successRate = rate(selected, (result) => (result.success ? 1 : 0));
  const verificationRate = rate(selected, (result) => (result.verification_success ? 1 : 0));
  const toolRate = rate(selected, (result) => (result.tool_success ? 1 : 0));
  const lintRate = passFailRate(selected, 'lint_result');
  const buildRate = passFailRate(selected, 'build_result');
  const securityResults = selected.filter((result) => result.task_type === 'security');
  const securityRate = securityResults.length ? rate(securityResults, (result) => (result.security_success ? 1 : 0)) : null;
  const latency = selected.length ? average(selected.map((result) => Number(result.latency_ms) || 0)) : 0;
  const timeouts = selected.filter((result) => result.timeout).length;
  const toolFailures = selected.filter((result) => !result.tool_success).length;
  const failures = selected.filter((result) => !result.success).length;
  const healthResults = selected.filter((result) => result.task_type === 'health-probe');
  return {
    samples: selected.length,
    repository_samples: relevant.length,
    repository_specific: useRepository,
    success_rate: successRate,
    verification_success_rate: verificationRate,
    tool_success_rate: toolRate,
    lint_success_rate: lintRate,
    build_success_rate: buildRate,
    security_success_rate: securityRate,
    failure_rate: selected.length ? failures / selected.length : 1,
    timeout_rate: selected.length ? timeouts / selected.length : 1,
    tool_failure_rate: selected.length ? toolFailures / selected.length : 1,
    average_latency_ms: latency,
    recent_health: recentHealth(healthResults.length ? healthResults : selected),
  };
}

function weightedScore(summary, weights) {
  const latencyScore = summary.average_latency_ms ? Math.max(0, 1 - summary.average_latency_ms / 120000) : 0;
  return (
    summary.success_rate * weights.success +
    summary.verification_success_rate * weights.verification +
    summary.tool_success_rate * weights.tool +
    (summary.lint_success_rate ?? summary.success_rate) * weights.lint +
    (summary.build_success_rate ?? summary.success_rate) * weights.build +
    (summary.security_success_rate ?? summary.success_rate) * weights.security +
    latencyScore * weights.latency +
    (summary.recent_health === 'healthy' ? 1 : 0) * weights.health +
    (summary.recent_health === 'healthy' ? 0.1 : 0) * weights.exploration
  );
}

export function scoreCandidate(results, options = {}) {
  const weights = {
    success: 0.28,
    verification: 0.25,
    tool: 0.15,
    lint: 0.07,
    build: 0.07,
    security: 0.08,
    latency: 0.05,
    health: 0.04,
    exploration: 0.01,
    ...options.weights,
  };
  const summary = summarizeResults(results, options);
  return { summary, score: weightedScore(summary, weights) };
}

export function categoryScores(results, categories = Object.keys(CATEGORY_WEIGHTS)) {
  return Object.fromEntries(categories.map((category) => {
    const categoryResults = results.filter((result) => result.task_type === category);
    return [category, scoreCandidate(categoryResults).score];
  }));
}

export function promotionDecision({ champion, challengers, promotion, resultsByModel }) {
  const championSummary = scoreCandidate(resultsByModel[champion.model_ref] ?? [], promotion).summary;
  const decisions = challengers.map((candidate) => {
    const results = resultsByModel[candidate.model_ref] ?? [];
    const scored = scoreCandidate(results, promotion);
    const categoryChampion = categoryScores(resultsByModel[champion.model_ref] ?? []);
    const categoryCandidate = categoryScores(results);
    const regressed = promotion.important_categories.filter((category) =>
      (categoryCandidate[category] ?? 0) + 0.05 < (categoryChampion[category] ?? 0)
    );
    const enough = scored.summary.samples >= promotion.minimum_samples;
    const hysteresisSatisfied = scored.summary.samples >= promotion.minimum_samples + (promotion.hysteresis_samples ?? 0);
    const healthy = scored.summary.recent_health === promotion.required_health;
    const acceptableFailure = scored.summary.failure_rate <= promotion.maximum_failure_rate;
    const verificationAcceptable = scored.summary.verification_success_rate >= promotion.minimum_verification_success;
    const betterVerification = scored.summary.verification_success_rate > championSummary.verification_success_rate;
    const meaningful = scored.score > (scoreCandidate(resultsByModel[champion.model_ref] ?? [], promotion).score + promotion.minimum_score_improvement);
    const practicalTaskGain = scored.summary.success_rate > championSummary.success_rate + promotion.minimum_task_success_improvement;
    return {
      model_ref: candidate.model_ref,
      score: scored.score,
      samples: scored.summary.samples,
      enough,
      hysteresis_satisfied: hysteresisSatisfied,
      healthy,
      acceptable_failure: acceptableFailure,
      verification_acceptable: verificationAcceptable,
      better_verification: betterVerification,
      meaningful_improvement: meaningful,
      practical_task_gain: practicalTaskGain,
      regressed_categories: regressed,
      promote: enough && hysteresisSatisfied && healthy && acceptableFailure && verificationAcceptable && betterVerification && meaningful && practicalTaskGain && regressed.length === 0,
    };
  });
  return {
    champion: champion.model_ref,
    challengers: decisions,
    promote: decisions.find((decision) => decision.promote) ?? null,
  };
}
