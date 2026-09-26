import { createHash } from 'node:crypto';

export const REGISTRY_SCHEMA = 1;
export const MODELS_DEV_URL = 'https://models.dev/api.json';
export const DEFAULT_PROVIDER = 'opencode';

const FREE_LABEL = /free/i;

function numberOrNull(value) {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function canonicalEvidence(value) {
  return createHash('sha256').update(JSON.stringify(value)).digest('hex');
}

export function isVerifiedFree(model, providerId) {
  const cost = model?.cost;
  const marker = `${model?.id ?? ''} ${model?.name ?? ''}`;
  return Boolean(
    providerId === DEFAULT_PROVIDER &&
      cost &&
      numberOrNull(cost.input) === 0 &&
      numberOrNull(cost.output) === 0 &&
      FREE_LABEL.test(marker)
  );
}

function toRegistryModel(provider, model, now, source) {
  const evidence = {
    catalog_entry: `${model.id}`,
    input_price: model.cost?.input ?? null,
    output_price: model.cost?.output ?? null,
    free_label: FREE_LABEL.test(`${model.id} ${model.name}`),
  };
  return {
    provider: provider.id,
    model_id: model.id,
    display_name: model.name ?? model.id,
    pricing: {
      currency: 'USD',
      input_per_million: model.cost?.input ?? null,
      output_per_million: model.cost?.output ?? null,
      free_status: 'verified_zero_catalog_price',
      free_offer_status: 'catalog_free_label',
    },
    context_window: model.limit?.context ?? null,
    maximum_output: model.limit?.output ?? null,
    reasoning_support: model.reasoning === true,
    image_support: model.attachment === true || model.modalities?.input?.includes('image') === true,
    tool_compatibility: provider.npm ?? 'unknown',
    api_protocol: provider.api ?? 'unknown',
    availability: 'catalog_visible',
    first_seen: now,
    last_verified: now,
    source: {
      name: source.name,
      url: source.url,
      provider: provider.id,
    },
    evidence: {
      ...evidence,
      entry_hash: canonicalEvidence(model),
    },
    retired: false,
    unavailable_since: null,
  };
}

export function emptyRegistry({ champion, now }) {
  return {
    schema: REGISTRY_SCHEMA,
    policy: 'free-only',
    champion: {
      provider: champion.provider,
      model_id: champion.model_id,
      model_ref: `${champion.provider}/${champion.model_id}`,
    },
    first_seen: now,
    last_successful_discovery: null,
    last_discovery_attempt: now,
    last_discovery_error: null,
    models: [],
  };
}

export function discoverFreeModels(catalog, { now, source = { name: 'models.dev', url: MODELS_DEV_URL }, providerId = DEFAULT_PROVIDER } = {}) {
  if (!catalog || typeof catalog !== 'object') {
    throw new Error('Catalog response is not an object');
  }
  const provider = catalog[providerId];
  if (!provider?.models || typeof provider.models !== 'object') {
    throw new Error(`Provider ${providerId} is absent from catalog`);
  }
  const models = Object.values(provider.models)
    .filter((model) => isVerifiedFree(model, providerId))
    .map((model) => toRegistryModel(provider, model, now, source))
    .sort((a, b) => a.model_id.localeCompare(b.model_id));
  if (!models.length) {
    throw new Error(`No positively verified free models found for ${providerId}`);
  }
  return models;
}

function mergeModels(previousModels, discovered, now) {
  const byId = new Map(previousModels.map((model) => [`${model.provider}/${model.model_id}`, model]));
  for (const model of discovered) {
    const key = `${model.provider}/${model.model_id}`;
    const old = byId.get(key);
    byId.set(key, old
      ? { ...old, ...model, first_seen: old.first_seen ?? model.first_seen, retired: false, unavailable_since: null }
      : model);
  }
  const discoveredIds = new Set(discovered.map((model) => `${model.provider}/${model.model_id}`));
  for (const [key, model] of byId.entries()) {
    if (!discoveredIds.has(key)) {
      byId.set(key, { ...model, availability: 'not_seen_in_latest_catalog', unavailable_since: now, retired: false });
    }
  }
  return [...byId.values()].sort((a, b) => `${a.provider}/${a.model_id}`.localeCompare(`${b.provider}/${b.model_id}`));
}

export function refreshRegistry(previous, catalog, options = {}) {
  const now = options.now ?? new Date().toISOString();
  const champion = options.champion ?? previous?.champion ?? {
    provider: DEFAULT_PROVIDER,
    model_id: 'muse-spark-1.3-contributor-free',
  };
  const base = previous ?? emptyRegistry({ champion, now });
  try {
    const discovered = discoverFreeModels(catalog, { ...options, now });
    const next = {
      ...base,
      schema: REGISTRY_SCHEMA,
      policy: 'free-only',
      champion: { provider: champion.provider, model_id: champion.model_id, model_ref: `${champion.provider}/${champion.model_id}` },
      first_seen: base.first_seen ?? now,
      last_successful_discovery: now,
      last_discovery_attempt: now,
      last_discovery_error: null,
      models: mergeModels(base.models ?? [], discovered, now),
    };
    return { registry: next, status: 'updated', discovered: discovered.length, material: materialChange(base, next) };
  } catch (error) {
    return {
      registry: { ...base, last_discovery_attempt: now, last_discovery_error: error.message },
      status: 'preserved',
      discovered: 0,
      material: false,
      error: error.message,
    };
  }
}

export function materialChange(previous, next) {
  const before = new Map((previous.models ?? []).filter((m) => !m.retired).map((m) => [`${m.provider}/${m.model_id}`, m]));
  const after = new Map((next.models ?? []).filter((m) => !m.retired).map((m) => [`${m.provider}/${m.model_id}`, m]));
  if ([...after.keys()].some((id) => !before.has(id)) || [...before.keys()].some((id) => !after.has(id))) return true;
  return [...after.keys()].some((id) => before.get(id)?.availability !== after.get(id)?.availability || before.get(id)?.retired !== after.get(id)?.retired);
}

export function candidateModels(registry) {
  return (registry.models ?? []).filter((model) => isRegistryCandidate(model));
}

export function isRegistryCandidate(model) {
  return Boolean(
    model &&
      !model.retired &&
      model.availability === 'catalog_visible' &&
      model.pricing?.free_status === 'verified_zero_catalog_price' &&
      model.pricing?.free_offer_status === 'catalog_free_label'
  );
}

export function serializeRegistry(registry) {
  return `${JSON.stringify(registry, null, 2)}\n`;
}
