import { readdir } from 'node:fs/promises';
import { join, extname, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
async function files(dir) {
  const entries = await readdir(dir, { withFileTypes: true });
  return (await Promise.all(entries.map(async (entry) => {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) return files(path);
    return entry.isFile() && extname(entry.name) === '.mjs' ? [path] : [];
  }))).flat();
}
const paths = await files(join(root, 'model-intelligence'));
for (const path of paths) {
  await new Promise((resolve, reject) => {
    const child = spawn(process.execPath, ['--check', path], { stdio: 'inherit' });
    child.on('close', (code) => code === 0 ? resolve() : reject(new Error(`syntax check failed: ${path}`)));
    child.on('error', reject);
  });
}
console.log(JSON.stringify({ ok: true, files: paths.length }));
