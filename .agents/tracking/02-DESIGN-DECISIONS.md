# Design decisions (what + why)

## Credentials handling rule
No secrets in this tracking set or in any web-served file. DB creds live in wp-config.php.
The leaked audit-designer password exists only in docs/UI_AUDIT_2026-09-02.md:5 — used for
Playwright verification, then rotated + redacted (03-TASKS.md W5).

## Docs architecture (user-mandated: docs live ON the WP site)
- Hierarchical child pages of /docs/ rendered by boltfolio page-docs.php: sidebar wp_list_pages,
  prev/next pager, TOC built by main.js from h2+h3 anchors (anchor_headings injects ids on
  the_content), [callout type=info|tip|warning] shortcodes, copy-buttons on <pre>, docs.css only
  on this template.
- API reference = Codex-style auto-generated pages (file -> classes/methods/hook tables with line
  numbers). Generator: wp-content/themes/boltfolio/tools/generate-api-reference.php (token-based,
  zero deps, deterministic, re-runnable). Loader: /tmp/opencode/docs-staging/insert-docs-v2.php
  (upsert by slug chain, explicit post_name, wp_slash content, template + excerpt from manifests).
- Arch Wiki style contract: sentence case; h2 start, no skipped levels; intro above first h2;
  imperative voice + why; no dates/versions in prose; Note/Warning/Tip sparingly (Warning must
  name scenario AND consequence); link-don't-duplicate; Related section last; tables w/ sentence
  case headers; troubleshooting entries = symptom -> Cause -> Fix.

## SEO stack decisions
- SINGLE sitemap: Rank Math owns sitemap_index.xml. Core wp-sitemap disabled via mu-plugin
  (boltfolio-seo.php, wp_sitemaps_enabled -> false). robots.txt stays virtual (no physical file);
  RM sitemap module auto-appends its Sitemap directive (verified class-sitemap-index.php:30).
- RM optional modules enabled: ['sitemap','image-seo','instant-indexing']. Titles & Social are
  CORE modules (always active) — do not put them in rank_math_modules. Schema/rich-snippet module
  intentionally OFF to avoid duplicate schema with the custom mu-plugin JSON-LD.
- Entity schema via mu-plugin JSON-LD @graph (wp_head prio 25):
  every page: WebSite(@id {home}#/schema/website, publisher -> Person) + Person(@id ...#/schema/person,
  sameAs GitHub nilesh32236 + LinkedIn nilesh-kanzariya-a8019b254, knowsAbout 6 real subjects);
  front page: ProfilePage(@id ...#/schema/webpage, mainEntity = Person).
- Site icon: generated 512px "NK" monogram PNG (brand: bg #0b0f14, text #e6edf3, muted #94a3b8,
  accent #ffb300, DejaVuSans-Bold). OG default: generated 1200x630 JPG, wired as RM
  homepage_facebook_image (+id).
- Meta descriptions: RM falls back to excerpt, so docs pages are covered automatically by loader-set
  excerpts; explicit rank_math_description postmeta only for pages with empty excerpts
  (11 about, 12 contact, 64 blog; home uses homepage_description).
- llms.txt: keep generating (proposal convention, no SEO claims in docs). Entity leak fixed in
  plugin code; cached files must be deleted to regenerate.

## Cache layer model (documented; not changed)
edge CDN -> server LSCache engine (OLS) -> wppo static files (advanced-cache.php) -> Redis -> PHP.
wppo in auto/wppo mode = orchestration layer (emits X-LiteSpeed-* headers, syncs purge);
server engine serves hits. NEVER run two owned HTML caches. Purge fan-out order:
edge -> server LSCache tags -> Redis. Purge-fan-out correctness = W4 test.

## Accepted backlog (documented, not blocking)
- front-page.php homepage copy is hardcoded (content model decision; refactor = user decision).
- boltfolio-card 800x500 image size registered but unused (dead crops candidate).
- Blog empty; readme.txt screenshot assets missing; style.css monolith ships docs CSS everywhere;
  backdrop-filter on sticky header (GPU cost, acceptable).

## CLOSING DECISIONS — 2026-09-07
- D28: Doc 241 fan-out incident — test overwrote features hub; repair protocol = restore from /tmp/opencode/docs-staging/blocks/d/features.html (kept until restore verified), purge both cache layers, then remove staging. Recorded to prevent "rm staging before verify" repeats.
- D29: Internal doc links inside migrated CPT docs still point at legacy /docs/features/* URLs which 301 to the new CPT URLs (importer did not rewrite hrefs). Functional; optional follow-up = rewrite hrefs in DB content for hop-free crawls.
- D30: WP_DEBUG/WP_DEBUG_LOG now false in production wp-config.php; debug.log truncated; new entries should stay near-zero — recheck if it grows.
