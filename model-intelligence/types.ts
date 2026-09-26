export type PricingEvidence = {
  currency: string;
  input_per_million: number | null;
  output_per_million: number | null;
  free_status: 'verified_zero_catalog_price' | 'unknown';
  free_offer_status: 'catalog_free_label' | 'unknown';
};

export type RegistryModel = {
  provider: string;
  model_id: string;
  display_name: string;
  pricing: PricingEvidence;
  context_window: number | null;
  maximum_output: number | null;
  reasoning_support: boolean;
  image_support: boolean;
  tool_compatibility: string;
  api_protocol: string;
  availability: string;
  first_seen: string;
  last_verified: string;
  source: { name: string; url: string; provider: string };
  evidence: Record<string, unknown>;
  retired: boolean;
  unavailable_since: string | null;
};

export type Registry = {
  schema: number;
  policy: 'free-only';
  champion: { provider: string; model_id: string; model_ref: string };
  first_seen: string;
  last_successful_discovery: string | null;
  last_discovery_attempt: string;
  last_discovery_error: string | null;
  models: RegistryModel[];
};

export type EvaluationResult = {
  model_provider: string;
  model_id: string;
  task_type: string;
  repository: string;
  language: string | null;
  framework: string | null;
  complexity: string;
  success: boolean;
  verification_success: boolean;
  test_result: 'pass' | 'fail' | 'not-run';
  lint_result: 'pass' | 'fail' | 'not-run';
  build_result: 'pass' | 'fail' | 'not-run';
  security_success: boolean | null;
  tool_success: boolean;
  latency_ms: number;
  timeout: boolean;
  retry_count: number;
  error_category: string | null;
  benchmark_result: 'pass' | 'fail' | 'not-run';
  quality_findings: string[];
  security_findings: string[];
  timestamp: string;
};
