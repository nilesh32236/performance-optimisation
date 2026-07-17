# WPPO Fixer Skill

Use this skill when fixing issues found during code review or audit in the Performance Optimisation plugin.

## Workflow

1. **Analyze the issue**: Read the full issue/error report. Understand what broke.
2. **Search for similar patterns**: Check existing code for how similar things are done.
3. **Fix with context**:
   - Read surrounding code to understand conventions
   - Read AGENTS.md for project-specific rules
4. **Apply fix**: Make minimal, focused changes. Do not refactor unrelated code.
5. **Verify**: Run all verification commands:
   ```bash
   npm run lint:js && composer lint && npm test && npm run build
   ```
6. **Iterate if needed**: If verification fails, analyze error, adjust fix, re-verify.
   Maximum 3 iterations per fix.

## Fixing Patterns

### PHP Fixes
- Add missing sanitization: `sanitize_text_field()`, `intval()`, `esc_url_raw()`
- Add missing escaping: `esc_html()`, `esc_url()`, `esc_attr()`
- Add missing nonce verification before REST/mutation operations
- Fix SQL queries to use `$wpdb->prepare()` with actual `%s`/`%d` placeholders; always sanitize/validate inputs before passing to prepare
- Replace raw `$_POST`/`$_GET` values with sanitized input: `sanitize_text_field( $_POST['key'] )`, `intval( $_GET['id'] )`
- Add capability checks: `current_user_can('manage_options')`
- Fix class file naming: `class-{name}.php` (e.g., `class-cache.php`)
- Use `wp_normalize_path()` for file paths

### JavaScript Fixes
- Replace `console.log` with `console.error` or `console.warn`
- Add ARIA labels: `aria-label={__('Description', 'text-domain')}`
- Wrap strings in translation function with `wppoSettings.translations` fallback
- Use `useId()` for unique HTML IDs in React components
- Add proper dependency arrays to `useEffect`/`useCallback`

### CSS Fixes
- Add `.wppo-` prefix to new classes
- Use CSS custom properties instead of hardcoded colors
- Use `respond-to()` mixin for responsive styles
- Remove `!important` unless absolutely necessary

## Build Output

After `npm run build`, always stage the updated `build/` directory:
```bash
git add build/
```

## WordPress Feature Compatibility

When implementing WordPress feature updates:
1. Check minimum requirements: WordPress 6.2+, PHP 8.2+
2. Use `function_exists()` and `has_filter()` for backward compatibility
3. Keep old implementation as fallback:
   ```php
   if ( function_exists( 'wp_new_function' ) ) {
       // New approach
   } else {
       // Legacy fallback
   }
   ```
4. Add `_doing_it_wrong()` deprecation notices for removed functions
5. Document the fallback in comments referencing the WP version that introduced the new function

## Verification Checklist

Before submitting any fix:
- [ ] `npm run lint:js` passes with no errors
- [ ] `composer lint` passes with no errors
- [ ] `npm test` passes all tests
- [ ] `npm run build` completes successfully
- [ ] Fix is minimal and targeted (no unrelated changes)
- [ ] Backward compatibility is maintained
- [ ] No new console.log statements
- [ ] All text is translatable
