import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { MODELS_DEV_URL, refreshRegistry, serializeRegistry } from './lib/registry.mjs';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const registryPath = join(root, 'model-intelligence', 'registry', 'free-models.json');
const configPath = join(root, 'model-intelligence', 'config', 'champion.json');
const reportPath = join(root, 'model-intelligence', 'registry', 'report.md');

async function readJson(path, fallback = null) {
  try { return JSON.parse(await readFile(path, 'utf8')); } catch { return fallback; }
}

function renderReport(registry) {
  const lines = [
    '# Free model registry',
    '',
    `Last successful discovery: \`${registry.last_successful_discovery ?? 'never'}\``,
    `Last attempt: \`${registry.last_discovery_attempt ?? 'never'}\``,
    `Champion: \`${registry.champion.model_ref}\``,
    '',
    'Only models with positive zero-price evidence and a catalog free label appear in the candidate pool. Catalog presence does not establish health or coding quality.',
    '',
    '| Model | Context | Output | Reasoning | Image | Availability |',
    '| --- | ---: | ---: | --- | --- | --- |',
  ];
  for (const model of registry.models ?? []) {
    lines.push(`| \`${model.provider}/${model.model_id}\` | ${model.context_window ?? '?'} | ${model.maximum_output ?? '?'} | ${model.reasoning_support ? 'yes' : 'no'} | ${model.image_support ? 'yes' : 'no'} | ${model.availability} |`);
  }
  lines.push('', '## Privacy and retention', '', 'The registry stores catalog metadata and evidence hashes. It stores no credentials, prompts, raw provider payloads, cookies, or site-owner data.');
  return `${lines.join('\n')}\n`;
}

const args = new Set(process.argv.slice(2));
const config = await readJson(configPath);
const previous = await readJson(registryPath);
let catalog = null;
let error = null;
const controller = new AbortController();
const timeout = setTimeout(() => controller.abort(), Number(process.env.MODEL_DISCOVERY_TIMEOUT_MS ?? 20000));
try {
  const response = await fetch(process.env.MODEL_CATALOG_URL ?? MODELS_DEV_URL, { signal: controller.signal, headers: { accept: 'application/json', 'user-agent': 'performance-optimisation-model-registry' } });
  if (!response.ok) throw new Error(`Catalog HTTP ${response.status}`);
  catalog = await response.json();
} catch (failure) {
  error = failure.name === 'AbortError' ? 'catalog timeout' : failure.message;
} finally {
  clearTimeout(timeout);
}

const refreshed = refreshRegistry(previous, catalog, { now: new Date().toISOString(), champion: config.champion, source: { name: 'models.dev', url: process.env.MODEL_CATALOG_URL ?? MODELS_DEV_URL } });
if (args.has('--write') && refreshed.status === 'updated') {
  await writeFile(registryPath, serializeRegistry(refreshed.registry));
  await writeFile(reportPath, renderReport(refreshed.registry));
}
console.log(JSON.stringify({ status: refreshed.status, material: refreshed.material, discovered: refreshed.discovered, models: refreshed.registry.models?.length ?? 0, error: error ?? refreshed.error ?? null }));
if (refreshed.status !== 'updated') {
  console.error('Discovery failed; previous registry was preserved.');
}
