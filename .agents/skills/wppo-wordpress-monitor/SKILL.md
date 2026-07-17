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

## Audit Process

1. **Search for new features**:
   - Use web search: `site:make.wordpress.org/core "performance" 2026`
   - Use web search: `WordPress core new hooks filters 2026`
   - Check WordPress Developer News for recent articles

2. **Review plugin code**:
   - For each area in the table above, grep the plugin code for relevant function/hook usage
   - Check if WordPress introduced a more efficient way to do the same thing
   - Check if WordPress fixed a bug that the plugin is working around

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
