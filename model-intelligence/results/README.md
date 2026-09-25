# Model evaluation results

Each line in `results.jsonl` is one sanitized model execution. Results never contain prompts, credentials, raw provider responses, cookies, authorization headers, or private site data.

Required result fields are defined in `../schema/result.schema.json`.

The initial repository result is a baseline for the existing Muse Spark workflow path. A health response alone does not create a coding score. Promotion requires enough executable benchmark and verification samples.
