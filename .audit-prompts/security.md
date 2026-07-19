# Audit: Security & WordPress Hardening

You are auditing a WordPress performance plugin for security vulnerabilities. Focus on WordPress-specific attack vectors and WP coding standard violations.

## What to Check

### Nonce & Capability Verification
- All REST endpoints must call `current_user_can('manage_options')` AND verify `X-WP-Nonce`
- `check_ajax_referer()` or `wp_verify_nonce()` called before any state-changing action
- Nonce fields present in all form submissions
- REST routes registered with correct `permission_callback`

### Sanitization & Escaping
- Every `$_GET`, `$_POST`, `$_SERVER` value sanitized before use (`sanitize_text_field`, `absint`, `sanitize_url`, etc.)
- Output escaped with `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` — never raw echo of user data
- `$wpdb->prepare()` used for ALL database queries with dynamic values — no sprintf/string concat in SQL

### File System & Path Traversal
- `WP_FILESYSTEM` API used for file reads/writes (not raw `file_get_contents`/`file_put_contents` on user-controlled paths)
- User-supplied paths validated against `WP_CONTENT_DIR` before any file operation
- No `shell_exec`, `exec`, `system`, `passthru` with user input

### API Key & Secret Handling
- No API keys or tokens logged via `error_log()` or returned in REST responses
- Keys stored in wp_options, not hardcoded
- PageSpeed API key not exposed in JS globals accessible to non-admins

### REST API Security
- All 16 REST endpoints require `manage_options` + nonce — verify none are missing
- Rate limiting or throttle protection for resource-intensive endpoints (image conversion, PageSpeed scan)
- No unauthenticated endpoints that trigger heavy background jobs

### Redis & Object Cache
- Redis connection strings must not be written to publicly-accessible files
- Config file `wp-content/wppo-redis-config.php` must not be web-accessible
- TLS cert paths validated before use

## Output Format

Write findings to the output file in JSON Lines format:

```jsonl
{"type":"summary","text":"Audited {target_dir}. Found X issues."}
{"type":"issue","severity":"critical|important|minor","file":"relative/path","line":42,"message":"What the issue is","suggestion":"How to fix it","inline":false}
```

## Severity Guide

- **critical**: Missing nonce verification, capability check bypass, unescaped output, SQL injection, hardcoded secrets, path traversal
- **important**: Missing sanitization on optional fields, overly broad REST permission, API key in log
- **minor**: Non-blocking config issues, missing return type hints on security functions
