# Live task checklist (owners + acceptance criteria)

## Lane C — SEO (owner: orchestrator) — IN PROGRESS
- [x] mu-plugin wp-content/mu-plugins/boltfolio-seo.php (core sitemap off + JSON-LD graph)
- [x] /tmp/opencode/seogen.php (site-icon 512 + og-default 1200x630, brand tokens)
- [ ] php /tmp/opencode/seogen.php -> site-icon.png + og-default.jpg
- [ ] wp option update rank_math_modules --format=json '["sitemap","image-seo","instant-indexing"]'
- [ ] rank_math_options_titles: homepage_title, homepage_description, homepage_facebook_title,
      homepage_facebook_description, homepage_facebook_image (+_id)
- [ ] rank_math_options_social: twitter_card_type=summary_large_image
- [ ] wp media import both images; wp option update site_icon <id>; capture og URL
- [ ] rank_math_description postmeta for 11, 12, 64
- [ ] sudo rm wp-content/uploads/rank-math/rank_math_*.xml (stale localhost sitemap cache)
- [ ] rm wp-content/cache/wppo/llms.txt llms-full.txt (regenerate with entity fix)
- [ ] sudo wp wppo cache clear --allow-root
- [ ] AC (curl): home has title/desc/canonical/og/twitter/JSON-LD; robots.txt -> sitemap_index.xml;
      sitemap_index.xml 200; docs page shows excerpt meta description; llms.txt no &amp;;
      project page canonical+desc; 404 page OK
- [ ] Purge fan-out test: wp-cli edit a page -> verify change live after cache clear

## W4 — Playwright verification (owner: orchestrator)
- [ ] Login wp-admin as audit-designer (password per docs/UI_AUDIT_2026-09-02.md:5)
- [ ] wppo admin UI: dashboard tabs load; clear-cache works; settings save round-trip
- [ ] RM dashboard: modules on; titles/social values visible; sitemap settings OK
- [ ] Docs render spot-check: /docs/, /docs/features/page-cache/, /docs/reference/,
      /docs/reference/reference-includes/class-cache/ (TOC h2+h3, sidebar, pager, API tables)
- [ ] Site Health: no critical; object cache recognized
- [ ] debug.log: confirm no could_not_set after 12:07 UTC drop-in redeploy

## W5 — Production cleanup (owner: orchestrator)
- [ ] Rotate audit-designer password AFTER all Playwright work
- [ ] Redact docs/UI_AUDIT_2026-09-02.md:5 (password -> [REDACTED])
- [ ] wp-config.php: WP_DEBUG false, WP_DEBUG_LOG false (display already false)
- [ ] Truncate wp-content/debug.log after flood-stop confirmation
- [ ] Clean /tmp/opencode images after import

## W6 — Final report (owner: orchestrator)

## Manual follow-ups for USER (deliver in final report)
- [ ] Google Search Console: verify property + submit sitemap_index.xml
- [ ] Bing Webmaster Tools: import from GSC
- [ ] (Optional) PageSpeed Insights API key -> wppo settings (scheduled lab scans)
- [ ] (Optional) RM Instant Indexing: add IndexNow key
- [ ] Write first blog posts (blog section empty)
- [ ] Optional per-project og images/screenshots

## Backlog (accepted, not blocking)
- [ ] front-page.php hardcoded copy -> editable content model (needs user decision)
- [ ] Remove unused boltfolio-card image size (functions.php:76)
- [ ] readme.txt screenshot assets (7 descriptions, 0 files)
- [ ] Split docs CSS out of style.css monolith
- [ ] Edge/CDN providers untested until user adds Cloudflare/Bunny

## Docs CPT migration (done: docs moved from hierarchical pages to `docs` CPT)

- [x] Imported 75 block-markup fragments from /tmp/opencode/docs-staging/blocks/{d,e,f,g}/ into the `docs` CPT via `wp eval-file /tmp/opencode/docs-staging/import-docs-cpt.php` (script written for this task; idempotent — re-runs update in place, matched by post_name + post_parent).
- [x] Tree: hub `performance-optimisation` (id 239) + 7 hub children (installation/features/litespeed/configuration/troubleshooting/faq/reference), 9 features children, 4 configuration children, 4 reference section hubs, 50 per-file reference pages. Counts verified 75/7/9/4/4/42/3/2/3.
- [x] All 75 docs posts: `doc_project` term `performance-optimisation` assigned; `rank_math_description` meta set to the manifest excerpt.
- [x] Per-file reference slugs reuse the legacy page slugs (class-cache, class-css, object-cache, …) so the theme's pass-through 4-segment redirect rule lands on live docs; g-manifest prefixed slugs (includes-class-cache etc.) intentionally NOT used where they differed.
- [x] Configuration children imported as leaf slugs (settings-reference/hooks/wp-cli/rest-api); the `configuration/` prefix in the e-manifest slugs was stripped.
- [x] Hub title set to "Performance Optimisation" (tree contract; manifest title said "…Documentation").
- [x] Retired 75 legacy pages to draft (recovery list: /tmp/opencode/docs-staging/retired-pages.json — id → old pretty permalink; restore via `wp post update <ID> --post_status=publish`). NOTE: the task's list omitted the 9 legacy feature child pages (150-158); they were drafted too because the 301 map only fires on is_404() — a still-published /docs/features/page-cache/ would never redirect.
- [x] `wp rewrite flush --hard` (DB rules flushed; warning that .htaccess wasn't rewritten — file left intact, standard WordPress block still present and routing).
- [x] Caches purged: WPPO static cache `docs` subtree (sudo rm — `wp wppo cache clear` fails as admin because cache files are web-server-owned) and OpenLiteSpeed cache (/usr/local/lsws/cachedata, sudo; stale server-cache hits were serving 200s for retired URLs and defeating the 301s).
- [x] Verified: /docs/ archive 200 with product card; 9 new URLs 200 with docs-sidebar + docs-toc; 9 legacy URLs 301 to their new URLs; class-cache page renders wp-block-heading + wppo-api-meta with no [callout] literal; content callout blocks render on installation (2) and used-critical-css (1); old /docs/features/ 301s away; sidebar tree + pager chain render correctly on doc singles.
- [ ] FOLLOW-UP (content, not done here): primary + footer nav menu items "Plugin Docs" point to `/?page_id=13`, which now 404s — repoint the menu items to `/docs/`.
- [ ] FOLLOW-UP (theme, not done here): staged reference content uses CSS classes `wppo-api-meta`/`wppo-api-params`/`wppo-api-tag`; no `wppo-api-sign` class exists in the staged input (verify-spec expectation written for a different generator). If that class is wanted, it belongs in the generator + theme docs CSS.
