# WPPO Reviewer Skill

Use this skill when reviewing pull requests or running codebase audits for the Performance Optimisation WordPress plugin.

## Workflow

1. **Gather context**: Read AGENTS.md, identify what changed (git diff --stat, git diff HEAD~1)
2. **Run verification commands** in this order:
   - `npm run lint:js`
   - `composer lint`
   - `npm test`
   - `npm run build`
3. **If any command fails**: Report the failure as a blocking issue with full error output. Do NOT proceed to merge.
4. **If all commands pass**: Proceed to code review.

## Code Review Checklist

### PHP
- WordPress Coding Standards (`composer lint` catches most)
- Proper sanitization: `sanitize_text_field`, `esc_html`, `esc_url`, `intval`
- Proper nonce verification: `wp_verify_nonce`, `check_admin_referer`
- Proper capability checks: `current_user_can('manage_options')`
- SQL prepared statements: `$wpdb->prepare()`, `$wpdb->placeholders`
- No direct DB queries without caching consideration
- No `$_POST`/`$_GET` without validation
- All REST endpoints require `manage_options` + `X-WP-Nonce`
- Class naming matches file naming (`class-{name}.php`)

### JavaScript/React
- `@wordpress/scripts` lint rules (ESLint)
- No `console.log` (only `console.error`/`console.warn`)
- `wppoSettings` global for settings, not direct REST calls
- `apiCall()` from `src/lib/apiRequest.js` for API calls
- Pure `useState`, no external state management
- No routing library (tab switching via `useState`)
- ARIA labels on interactive elements
- Translation-ready strings via `wppoSettings.translations`

### SCSS/CSS
- `.wppo-` prefix on all classes
- BEM-like naming convention
- CSS custom properties for theming
- `respond-to()` mixin for breakpoints
- No Tailwind, no CSS-in-JS

### Security
- Path traversal prevention (check `realpath()` usage)
- SSRF prevention (URL validation for external requests)
- Nonce refresh mechanism for long-running admin sessions
- API key redaction from logs/exports
- Output escaping in all admin-facing views

## Confidence Scoring

After review, provide a confidence score (0-100):

```
CONFIDENCE: <number>
VERDICT: <approved|changes-requested>
SUMMARY: <2-3 sentence summary>
ISSUES:
- [severity: critical/important/minor] <description> (<file>:<line>)
STRENGTHS:
- <description>
```

- **95+**: Approved — all checks pass, no security issues, clean code
- **80-94**: Approved with minor notes — can merge but document suggestions
- **50-79**: Changes requested — issues found that must be addressed
- **<50**: Blocked — critical security/functionality issues

## Merge Gate

NEVER set confidence to >= 95 unless:
- All verification commands pass
- No critical or important issues found
- No security vulnerabilities
- Code follows WordPress conventions
- All translatable strings have fallbacks
