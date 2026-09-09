# Implementation plan (phases + acceptance criteria)

## W1 — Recon (DONE)
Site stack (OpenLiteSpeed/PHP 8.3/MariaDB 11.8/Redis 8.0), plugin architecture (42 classes,
30 REST routes, 7 CLI subcommands), theme map, content model (6 projects CPT, 9 pages originally,
blog empty, media library empty), docs state (~1,827 words user-facing), 2026 benchmarks research.
Full findings were recorded in the plugin's internal docs + .agents notes.

## W2 — Diagnostics (DONE)
- Rank Math active but NEVER configured: no rank_math_modules, no titles/sitemap/social options,
  no wizard record. Only housekeeping options exist. Canonical appears (core titles module always on).
- wppo litespeed_integration.mode='auto' -> effective 'wppo' (no LSCWP plugin active). Live miss
  response emits X-LiteSpeed-Cache-Control public,max-age=604800 + tag set; OLS serves hits
  (x-litespeed-cache: hit). Model: wppo = orchestration layer; server engine = hit server.
- site_icon='0'; media library empty; no favicon/og source.
- robots.txt = core virtual robots -> wp-sitemap.xml; sitemap_index.xml 404 (RM sitemap module off).
- Cron could_not_set flood (4,937 lines) in wp-content/debug.log tied to Redis outage behavior
  of the OLD object-cache drop-in.

## W3 — Implementation (A/B/D/E/F/G/DB DONE; C in progress)
### A — plugin fixes (fix-1) DONE
uninstall orphans (10 options + transient sweep), llms.txt entity leak, dead vars, CLI docblock dup,
REST crawler status authority, drop-in hardening (options/alloptions/network_options non-persistent;
throttled outage logging 1 line/5min), empty 'W.' tag removed, abilities fatal (get_counts()),
PHPStan stubs 242->0 (level 5), Jest console noise, internal docs drift (AGENTS.md 42/30/7,
hooks.md +47 filters, roadmap ticks, readme versions).
Drop-in REDEPLOYED to wp-content/object-cache.php at 12:07 UTC Sep 7 (chown nobody:nogroup 644).
AC: all suites green (PHPUnit 489/1131, Jest 353, PHPCS 0, PHPStan 0) — met.
### B — theme fixes (fix-5) DONE
404 arrow double-escape; h1->h2 card hierarchy (index/archive/search; front cards keep h3 correctly);
footer nav landmark; social dup title removed; breadcrumbs aria-current; dead view.js deleted;
comments_template guarded; inline styles -> classes; wppo_exclude_delay_js filter for 'boltfolio-script';
TOC h2+h3; print CSS; docs.css .wppo-api-* classes; Theme URI removed.
### D/E/F — guide docs DONE — 24 fragments (d/, e/, f/), all code-verified, Arch-Wiki style.
### G — API reference DONE — theme/tools/generate-api-reference.php + 55 fragments (g/):
reference hub, 4 section hubs, 50 per-file pages; 1081 declarations + 176 hook rows line-verified.
Re-run: cd wp-content/themes/boltfolio && php tools/generate-api-reference.php --out=/tmp/opencode/docs-staging/g
### DB insertion DONE — insert-docs-v2.php (idempotent; repairs included: ID15 restored to features,
strays 77/95 deleted). Final tree (79 published pages):
/docs(13) -> installation(14,o1) features(15,o2) litespeed(146,o3) configuration(16,o4)
troubleshooting(147,o5) faq(17,o6) reference(148,o7 'Code reference');
features -> 9 children (150-158, o10-18); configuration -> 4 (159 settings-reference o10,
160 hooks o11, 161 wp-cli o12, 86 rest-api o13); reference -> 4 section hubs (163-166 o10-13)
-> 50 per-file pages (186-235; entry points use -php suffix slugs).
All pages: _wp_page_template=page-docs.php, excerpt set (RM description fallback), comments closed.

## W4 — Verification (PENDING) — see 03-TASKS.md
## W5 — Production cleanup (PENDING) — see 03-TASKS.md
## W6 — Final report (PENDING)

## COMPLETION ADDENDUM — 2026-09-07
All W1-W6 acceptance criteria met and live-verified. Notes: (1) purge fan-out verified end-to-end (wppo static + OpenLiteSpeed cachedata + re-curl fresh). (2) One incident during fan-out test: doc 241 (features hub) was overwritten by the test update; restored from staged blocks fragment (title 'Features Guide', content, excerpt, menu_order 2 verified, term+rank_math_description untouched). (3) Legacy docs pages permanently deleted after restore verification (75 IDs, 0 orphaned postmeta). (4) Root AGENTS.md drift corrected (30 routes, 42 classes, 9 cleanup types, wp wppo CLI).
