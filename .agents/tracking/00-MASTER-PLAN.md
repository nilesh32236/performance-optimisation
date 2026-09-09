# Master plan — Nilesh portfolio engagement (tracking index)

Purpose: durable on-disk record so nothing is lost when the agent's conversation context is
compressed. Files here are SECRET-FREE by rule (credentials live in wp-config.php; the one
leaked password is handled per 03-TASKS.md W5 and must never be copied into any web-served file).

## Original user request (verbatim intent)
"On this project i want you to first understand full wordpress site understand all the things there.
And please check is our portfolio has all the things which it should have you are allow to do all the changes
allow to login wordpress using playwrite mcp server and check and modify all the things.
Currenlty we have created documentations for performance optimisation plugin but this is way to simple and not cover
all the things and we also need to test performance optimisation plugin and make it currect and proper.
Can you please check all the things improve docs and also improve content and other things and make my portfolio
currect and proper and make sure it rank currectly and we have good documentation like arch wiki.
Can you please use army of sub agent understand all the things ask questions to agent answer it questions
make a decisions and make it currect as you are manager of this site."

Additional user steer (chronological):
- Docs must LIVE ON THE WORDPRESS SITE as hierarchical linked documentation pages (not HTML files,
  not plugin repo docs). Codex-style coverage: every function/hook with file names + line numbers.
- Custom engineering goes in the boltfolio custom theme (plus blocks if needed).
- Approved using generated HTML fragments as WP page content.
- Approved chown-ing theme/plugin dirs to admin:nogroup for writability; runtime dirs stay nobody:nogroup.
- User wants MD tracking files (this set) to stay on track across context compression.

## Phase status
| Phase | Scope | Status |
|---|---|---|
| W1 | Full recon (site, plugin, theme, docs, 2026 benchmarks) | DONE |
| W2 | Diagnostics (RM unconfigured, double-cache check, headers) | DONE |
| W3-A | Plugin bug fixes + PHPStan 242->0 + internal docs drift | DONE |
| W3-B | Theme a11y/perf/SEO fixes (13 items) | DONE |
| W3-D/E/F | Guide docs authoring (features, config, litespeed, troubleshooting, FAQ) | DONE |
| W3-G | Codex-style API reference generator + 55 fragments | DONE |
| W3-DB | Insert 79 docs pages into WP + tree repairs | DONE |
| W3-C | SEO lane: RM config, sitemap, icons, OG, JSON-LD, postmeta | IN PROGRESS |
| W4 | Playwright wp-admin verification + curl SEO checks | PENDING |
| W5 | DONE (2026-09-07) |
| W6 | Final report to user | PENDING |

## File map (this set)
- 00-MASTER-PLAN.md — intent + status index (this file)
- 01-IMPLEMENTATION-PLAN.md — phase detail w/ acceptance criteria
- 02-DESIGN-DECISIONS.md — decisions made and why
- 03-TASKS.md — live checklist (owners, AC, backlog, manual follow-ups)

## Key locations
- WP root: /var/www/nileshportfolio.duckdns.org (WP-CLI: /usr/local/bin/wp, workdir = WP root)
- Plugin: wp-content/plugins/performance-optimisation ("wppo" v1.9.0; .agents/ = internal notes)
- Theme: wp-content/themes/boltfolio (tools/generate-api-reference.php)
- Docs staging: /tmp/opencode/docs-staging/{d,e,f,g}/ + insert-docs-v2.php (idempotent loader)
- Images gen: /tmp/opencode/seogen.php

## Specialist session registry (reusable aliases)
exp-1 ses_f84b60a8dffe6GZJ6oHu1Y3Vaj (plugin architecture), exp-2 ses_f84b5e4e7ffepyZfdpVMLwOgdx (docs audit),
exp-3 ses_f84b5b217ffey0XsyHxHz5nsCe (theme audit), lib-1 ses_f84b55b3effeC5iWqRYQV1uyZH (2026 benchmarks),
fix-1 ses_f84a90618ffeVhdQPtSrpOfspM (plugin fixes), fix-2 ses_f849b1789ffeIHUfwo24Fgvnz3 (docs d/),
fix-3 ses_f849a7eb8ffetu309jjWYCufPm (docs f/), fix-4 ses_f849ad8bdffelVO6VY4Wa28JBX (docs e/),
fix-5 ses_f8472ab7affeI3Aql57c012kGT (theme fixes), fix-6 ses_f8471cf25ffe3jwwQwbNBJwX2f (API generator)

## FINAL STATUS — 2026-09-07 (W6 closure)
All phases complete: W1 recon / W2 diagnostics / W3 specialist lanes (plugin fixes, theme fixes, docs authoring, API reference generator) / docs CPT migration (75 posts, 301s, legacy pages deleted) / Lane C SEO (Rank Math configured incl. sitemap, OG defaults, titles; JSON-LD mu-plugin; site icon) / W4 Playwright admin verification / W5 hardening (WP_DEBUG off, debug.log truncated + flood stopped, creds rotated + redacted, 75 legacy drafts permanently deleted, purge fan-out verified, doc 241 features-hub incident repaired from staged fragment) / W6 (root AGENTS.md drift patched, staging cleaned).
Final verified state: home/docs/projects pages serve title+meta description+canonical+OG incl. og:image+twitter card+JSON-LD; robots.txt advertises sitemap_index.xml (200, includes page/project/docs/project_type sub-sitemaps; wp-sitemap.xml + sitemap.xml 301); llms.txt clean; 404 arrow fixed; docs at /docs/ via docs CPT with block editor + doc_project taxonomy; 4 core pages remain (home/blog/contact/about); audit-designer password rotated (stored at /tmp/opencode/audit-designer.newpass until handover).
