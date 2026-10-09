# WPPO WordPress Feature Monitor Skill

Use this skill on a weekly schedule to check for new WordPress features, APIs, hooks, and best practices that could improve the Performance Optimisation plugin.

## Research Sources

1. **WordPress Developer Blog**: https://developer.wordpress.org/news/
2. **WordPress Core Trac**: https://core.trac.wordpress.org/ (recent commits)
3. **WordPress Core Changes**: `git log` on WordPress core (if available)
4. **Make WordPress Core Blog**: https://make.wordpress.org/core/
5. **Web search**: "new WordPress developer features", "WordPress {version} new hooks"
6. **Context7 MCP**: Query for latest WordPress API documentation

## Areas to Monitor

| Area | What to Check |
|------|---------------|
| Caching API | `wp_cache_*` changes, new cache primitives |
| Object Cache | New cache backends, `wp_cache_supports()` |
| Image Handling | New image formats, `wp_image_*` functions |
| Script/Style API | `wp_enqueue_*` changes, script loading strategies |
| REST API | New base endpoints, new conventions |
| WP Cron API | New scheduling primitives |
| Filesystem API | `WP_Filesystem` changes |
| Performance Hooks | New performance-related hooks and filters |
| Database API | `$wpdb` improvements, query optimizations |
| Lazy Loading | Core lazy loading additions |
| WebP/AVIF | Core image format support changes |
| Design/Usability | Competitor admin/settings-UX parity, WP admin design trends, usability pain points, SPA a11y gaps (see `docs/admin-design-plan.md`) |

## Audit Process

1. **Search for new features**:
   - Use web search: `site:make.wordpress.org/core "performance" 2026`
   - Use web search: `WordPress core new hooks filters 2026`
   - Check WordPress Developer News for recent articles

2. **Review plugin code**:
   - For each area in the table above, grep the plugin code for relevant function/hook usage
   - Check if WordPress introduced a more efficient way to do the same thing
   - Check if WordPress fixed a bug that the plugin is working around
   - For Design/Usability findings, anchor every proposal in `src/` + `.wppo-` SCSS and cross-check `docs/admin-design-plan.md` for the phased admin plan

3. **Evaluate relevance**:
   - Is the new feature relevant to this plugin's functionality?
   - Would adopting it improve performance, security, or user experience?
   - What is the minimum WordPress version required?
   - Is it stable enough to adopt?

4. **Create improvement plan**:
   - For each candidate improvement, document:
     - Current implementation in plugin
     - New WordPress feature
     - How to implement with backward compatibility
     - Risk assessment

## Implementation Pattern

When implementing WordPress feature updates, always use this pattern:

```php
/**
 * Feature description with WordPress version reference.
 *
 * Uses the new WordPress feature (WP X.Y+) with legacy fallback.
 */
if ( function_exists( 'wp_new_feature_function' ) ) {
    // New implementation using WordPress core function
    $result = wp_new_feature_function( $args );
} else {
    // Legacy fallback for older WordPress versions
    // [Current implementation preserved here]
}
```

For hooks/filters:
```php
if ( has_filter( 'new_hook_name' ) ) {
    // Let WordPress core handle it if available
    apply_filters( 'new_hook_name', $value );
} else {
    // Plugin's custom implementation
    $value = apply_filters( 'wppo_custom_filter', $value );
}
```

## Reporting

After analysis, create a markdown summary with:
```markdown
# WordPress Feature Monitor - YYYY-MM-DD

## New Features Detected
- [Feature Name] (WP X.Y): Description and plugin impact

## Improvement Opportunities
- [Opportunity]: Current code -> Recommended change -> Risk

## No-Action Items
- [Feature]: Why it's not relevant to this plugin

## Next Steps
- [ ] Create PR for improvement X
- [ ] Flag for next release cycle
```

## Machine-Readable Final Message (CI-parsed runs)

When this skill drives an automated monitor run whose output is parsed by
CI (see `.github/workflows/wordpress-monitor.yml` and
`.github/schemas/wppo-findings.schema.json`), the markdown report above is
NOT the deliverable. The final message SHOULD be exactly ONE compact
single-line JSON document starting with `{"schema_version"` (canonical
form) — no prose before or after, no markdown fences. The extractor
tolerates pretty-printed, indented, CRLF, BOM-prefixed, or fenced output by
scanning for complete JSON values, but compact output is preferred.

**Authoritative contract:** `.github/schemas/wppo-findings.schema.json`
wins on any conflict with the summary below. The schema sets
`additionalProperties: false` at every level — any unknown key rejects the
whole document, so emit exactly the fields listed here and nothing else.

Top-level object (no extra keys): `schema_version` (const `1`), `run`
(`commit`, `wordpress_target`, `php_target`, `date` as `YYYY-MM-DD`),
`lanes[]` (each: `name` string plus integer `searches_performed`,
`sources_consulted`, `findings`, all >= 0), `findings[]`.

Each finding (no extra keys) requires ALL of: `id` (64-char lowercase hex),
`category`, `title`, `status` (`candidate`, `validated`, `issue_created`,
`in_progress`, `implemented`, `benchmark_failed`, `merged`, `rejected`,
`obsolete`, `superseded`), `priority` (`high`, `medium`, `low`),
`difficulty` (`easy`, `medium`, `hard`), `risk` (`low`, `medium`, `high`),
`risk_numeric` (integer 1-5), `confidence` (number 0-1), `score`
(number >= 0), `performance_impact` / `user_value` / `feasibility`
(integers 1-10), `classification` (`NEW`, `CORE_PARTIAL`, `CORE_COMPLETE`,
`PLUGIN_UNIQUE`, `COMPETITOR_ONLY`, `EXPERIMENTAL`, `IRRELEVANT`,
`REMOVE_OBSOLETE`, `ALREADY_IMPLEMENTED`), `tier` (`A`, `B`, `C`),
`evidence[]` (each entry needs at least `url` starting with `http://` or
`https://`; optional `version`, `published`), `implementation` (object with
`files[]` and `functions[]` string arrays), `estimated_impact` (object with
numeric `queries`, `frontend_kb`, `request_ms`), `measured_impact: null`
on first submission, `fallback` (string), `acceptance_criteria[]`
(non-empty strings).

Emit compact single-line JSON (`jq -c`): the CI extractor collects every
complete JSON value in the stream and keeps the last object containing
`schema_version` with a `findings` array — compact single-line output is
canonical, and pretty-printed, indented, CRLF, BOM-prefixed, or fenced
output is tolerated, not preferred. Do NOT emit the JSON twice with prose
in between as a "verification echo": if you self-verify, verify the same
string you already emitted without re-printing a second copy. Before stopping, self-verify with
`echo '<json>' | jq -e .` and
`echo '<json>' | jq -e 'type == "object" and (.findings | type == "array")'` —
both must exit 0. If the JSON risks truncation, shorten prose fields
(`fallback`, `acceptance_criteria` wording), never the structure.
