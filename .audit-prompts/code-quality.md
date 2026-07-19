# Audit: Code Quality & WordPress Conventions

You are auditing a WordPress plugin for code quality and adherence to WordPress Coding Standards (WPCS). The plugin targets PHP 8.2+ and WP 6.2+.

## What to Check

### WordPress Coding Standards (PHP)
- All PHP files use tabs for indentation (not spaces)
- Opening braces on same line for control structures: `if ( condition ) {`
- Spaces around operators, after commas, inside parentheses: `function_call( $arg1, $arg2 )`
- Yoda conditions used: `if ( 'value' === $var )`
- No short PHP tags (`<?` — must use `<?php`)
- Class names in `PascalCase`, methods in `snake_case`, constants in `UPPER_SNAKE_CASE`

### Deprecated & Compatibility Issues
- No deprecated WordPress functions (check against WP 6.2–6.8 deprecation list)
- `wp_enqueue_script()` / `wp_enqueue_style()` used (not direct `<script>` tags)
- `sanitize_*`, `esc_*`, `wp_kses_*` from WP core used (not custom reimplementations)
- PHP 8.2 compatibility: no dynamic properties without `#[AllowDynamicProperties]`, no `null` coercion deprecation notices

### Dead Code & Debug Artifacts
- `console.log()` calls in JS (should be `console.error()` or `console.warn()` only)
- `error_log()` calls left in PHP (should be removed for production or gated behind `WP_DEBUG`)
- Commented-out code blocks without explanation
- TODO/FIXME comments that reference known bugs

### Class Architecture (Manual Autoloading)
- All plugin classes loaded via `require_once` in `Main::includes()` — not via Composer PSR-4
- No circular includes between class files
- `vendor/autoload.php` loaded once in the plugin entry file only

### JS/React Conventions
- All React components read initial settings from `wppoSettings.settings[tabName]`
- API calls use `apiCall()` from `src/lib/apiRequest.js` — not raw `fetch()`
- Translatable strings come from `wppoSettings.translations` with English fallback
- No `console.log` (ESLint error-level — use `console.error` or `console.warn`)
- SCSS uses `.wppo-` prefix and BEM-like naming — no inline styles or ad-hoc class names

### SCSS Conventions
- All class names prefixed with `.wppo-`
- CSS custom properties used for colors/spacing (no raw hex values hardcoded in component styles)
- Breakpoints use `respond-to()` mixin (`sm`/`md`/`lg`/`xl`) — no raw `@media` queries

## Output Format

Write findings to the output file in JSON Lines format:

```jsonl
{"type":"summary","text":"Audited {target_dir}. Found X issues."}
{"type":"issue","severity":"critical|important|minor","file":"relative/path","line":42,"message":"What the issue is","suggestion":"How to fix it","inline":false}
```

## Severity Guide

- **critical**: PHPCS violations that block CI, deprecated function in production path, console.log in JS, PHP 8.2 incompatibility
- **important**: Dead code, debug artifacts, non-WPCS formatting in public functions, missing English fallback on translation
- **minor**: Naming drift, commented code with explanation, missing return type hints
