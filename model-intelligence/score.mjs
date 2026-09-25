import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { promotionDecision, scoreCandidate } from './lib/scoring.mjs';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const config = JSON.parse(await readFile(join(root, 'model-intelligence/config/champion.json'), 'utf8'));
let results = [];
try {
  results = (await readFile(join(root, 'model-intelligence/results/results.jsonl'), 'utf8'))
    .split('\n').filter(Boolean).map((line) => JSON.parse(line));
} catch {}

const byModel = {};
for (const result of results) {
  const ref = `${result.model_provider}/${result.model_id}`;
  (byModel[ref] ??= []).push(result);
}
const challengers = Object.keys(byModel).filter((ref) => ref !== config.champion.model_ref).map((model_ref) => ({ model_ref }));
const decision = promotionDecision({
  champion: config.champion,
  challengers,
  promotion: config.promotion,
  resultsByModel: byModel,
});
const scores = Object.fromEntries(Object.entries(byModel).map(([ref, modelResults]) => [ref, scoreCandidate(modelResults, config.promotion)]));
console.log(JSON.stringify({ champion: config.champion.model_ref, scores, promotion: decision }, null, 2));
if (decision.promote) process.exitCode = 2;
