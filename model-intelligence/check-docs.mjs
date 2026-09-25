import { access, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const required = [
  ['model-intelligence/README.md', ['## Policy', '## Discovery', '## Evaluation', '## Routing safety', '## Privacy']],
  ['model-intelligence/registry/report.md', ['# Free model registry', '## Privacy and retention']],
  ['model-intelligence/results/README.md', ['Each line', 'Required result fields']],
  ['AGENTS.md', ['## Free model intelligence', 'pnpm typecheck', 'pnpm doc:check']],
];
const errors = [];
for (const [relativePath, sections] of required) {
  const path = join(root, relativePath);
  try { await access(path); } catch { errors.push(`${relativePath}: missing`); continue; }
  const text = await readFile(path, 'utf8');
  for (const section of sections) if (!text.includes(section)) errors.push(`${relativePath}: missing section ${section}`);
}
if (errors.length) { console.error(errors.join('\n')); process.exitCode = 1; } else { console.log(JSON.stringify({ ok: true, files: required.length })); }
