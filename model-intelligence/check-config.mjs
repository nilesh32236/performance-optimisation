import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const config = JSON.parse(await readFile(join(root, 'model-intelligence/config/champion.json'), 'utf8'));
const expected = config.champion.model_ref;
const locations = JSON.parse(await readFile(join(root, 'model-intelligence/config/fallback-locations.json'), 'utf8'));
const files = [
  ...locations.fallback_locations.map((location) => join(root, location.path)),
  join(root, 'model-intelligence/config/champion.json'),
];
const mismatches = [];
const occurrences = [];
for (const file of files) {
  const text = await readFile(file, 'utf8');
  const location = locations.fallback_locations.find((item) => join(root, item.path) === file);
  if (location && !text.includes(location.pattern)) mismatches.push(`${location.path}: expected fallback pattern missing`);
  for (const match of text.matchAll(/opencode\/[A-Za-z0-9._-]+/g)) {
    occurrences.push({ file, value: match[0] });
    if (match[0] === expected) continue;
    if (file.includes('model-intelligence/config/champion.json') && match[0] === expected) continue;
    mismatches.push(`${file}: ${match[0]}`);
  }
}
const registry = JSON.parse(await readFile(join(root, 'model-intelligence/registry/free-models.json'), 'utf8'));
if (registry.champion.model_ref !== expected) mismatches.push(`registry champion ${registry.champion.model_ref} != ${expected}`);
if (mismatches.length) {
  console.error(JSON.stringify({ ok: false, expected, occurrences, mismatches }, null, 2));
  process.exitCode = 1;
} else {
  console.log(JSON.stringify({ ok: true, champion: expected, explicit_selection_preserved: true, occurrences: occurrences.length }, null, 2));
}
