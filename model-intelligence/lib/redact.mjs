const SECRET_KEY = /(api[-_]?key|authorization|cookie|password|token|secret|credential)/i;

export function redactResult(value) {
  if (Array.isArray(value)) return value.map(redactResult);
  if (!value || typeof value !== 'object') return value;
  return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, SECRET_KEY.test(key) ? '[REDACTED]' : redactResult(item)]));
}

export function writeJsonLine(path, result) {
  return import('node:fs/promises').then(({ appendFile }) => appendFile(path, `${JSON.stringify(redactResult(result))}\n`, { mode: 0o600 }));
}
