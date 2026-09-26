import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const taskPath = join(root, 'model-intelligence', 'benchmarks', 'tasks.json');

export async function loadTasks() {
  const payload = JSON.parse(await readFile(taskPath, 'utf8'));
  return payload.tasks;
}

export function verifyTask(task, files) {
  const failures = [];
  for (const [file, needles] of Object.entries(task.expected_contains ?? {})) {
    const content = files[file];
    if (typeof content !== 'string') {
      failures.push(`${file}: missing`);
      continue;
    }
    for (const needle of needles) {
      if (!content.includes(needle)) failures.push(`${file}: missing ${needle}`);
    }
  }
  for (const { file, needle } of task.forbidden_contains ?? []) {
    if (typeof files[file] === 'string' && files[file].includes(needle)) {
      failures.push(`${file}: forbidden ${needle}`);
    }
  }
  return { id: task.id, success: failures.length === 0, failures };
}

export async function verifyFixtureSuite() {
  const tasks = await loadTasks();
  return tasks.map((task) => verifyTask(task, task.solution_files ?? task.seed_files));
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const results = await verifyFixtureSuite();
  const failed = results.filter((result) => !result.success);
  console.log(JSON.stringify({ tasks: results.length, passed: results.length - failed.length, failed }, null, 2));
  if (failed.length) process.exitCode = 1;
}
