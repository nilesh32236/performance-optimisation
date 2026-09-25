# WordPress.org Listing Audit — Phase A (issue #1622)

> Documentation/metadata only. No runtime functionality or API changes.
> Validate the live listing + readme parse after merge when directory sync permits.

## 1. Baseline audit (pre-Phase A)

- Short description (`readme.txt:11`, header `performance-optimisation.php:4`): 144 chars,
  covered caching/minify/lazy/WebP/Redis/DB but did **not** name “free”, “Core Web Vitals”,
  or “CSS/JS optimization” as searchable phrases.
- Tags (`readme.txt:3`): `cache, performance, speed, pagespeed, minify` — 5 tags but
  `pagespeed` risks a trademark reading (Google PageSpeed) and `speed` duplicates
  `performance` (stuffing-adjacent); `lazy-load`/`optimization` intent unmapped.
- Description (`readme.txt:13-75`): single flat list — core benefits and advanced topics
  (LiteSpeed/ESI/edge-purge/bfcache/llms.txt/Abilities) at the same level; advanced items
  competed with first-screen conversion copy.
- Compatibility (`readme.txt:15,347-351`; FAQ `338-342`): unqualified “fully compatible /
  fully tested” claims across themes, builders, WooCommerce, and SEO plugins; PageSpeed FAQ
  opened with “Yes.” before the results-vary caveat; lazy-load FAQ described only the
  legacy IntersectionObserver path although native `loading="lazy"` is the default since
  2.0.0.
- `Tested up to: 7.1` (`readme.txt:6`, header `:7`): staged claim — WordPress 7.1 “Mary Lou”
  shipped 2026-08-19; prior audits flagged it as unverified without a trace.

## 2. New copy and tags (what changed)

- **Short description (119 chars, ≤150):** “Free WordPress performance plugin: page
  caching, Core Web Vitals, WebP/AVIF images, lazy load, and CSS/JS optimization.”
  Applied identically to `readme.txt:11` and the plugin header so directory and dashboard
  stay in sync. First-screen paragraph additionally names LCP/INP/CLS, page caching,
  WebP/AVIF + lazy loading, and minification.
- **Tags (exactly 5):** `cache, performance, optimization, lazy-load, minify` — rationale
  in `docs/growth/KEYWORD-MAP.md` (lowercase, no competitor/trademark, no stuffing; each
  tag evidence-mapped).
- **Hierarchy:** core benefits keep their order; a new “Advanced capabilities” subheading
  demotes LiteSpeed coexistence, edge/CDN purge, crawler, bfcache, llms.txt, and ESI/Abilities
  lower while keeping them discoverable. No feature text added beyond code.
- **Compatibility (qualified):** “designed to work with / reported working with” language;
  exclusion paths named (built-in file/handle exclusions, per-page exclusions, WooCommerce
  safe-mode, builder safe-mode, automatic cart/checkout/account cache exclusion); readers
  invited to report conflicts. PageSpeed FAQ reframed as “targets metrics, results vary —
  no specific score promised”. Lazy-load FAQ now states the native default + opt-in JS
  loader + MutationObserver + LCP exclusion.

## 3. Compatibility-evidence trace

| Claim | Evidence |
|---|---|
| Works across hosting/server types | Cache + `.htaccess`/Nginx rules adapt (`includes/Cache/`, `includes/Edge/`); Redis optional (FAQ) |
| WooCommerce cart/checkout/account excluded from page cache | `includes/Integrations/class-woo-detect.php` (`is_woo_*`, safe-mode, Store API, wc-ajax, faceted-query, dynamic-path detection); self-test REST endpoint |
| Builder conflicts recoverable | Combine-CSS builder safe-mode + Elementor regen purge (2.1.0 changelog); per-page asset manager (`includes/Admin/class-metabox.php`); File Optimization exclusion rules |
| Minify/defer breakage recoverable | Exclusion lists in File Optimization tab; Delay-JS presets + per-page kill switch + WooCommerce safe-mode (2.0.0 changelog) |
| `Tested up to: 7.1` (staged) | `docs/wordpress-7x-readiness.md` §§1–3: 6.9 APIs (template buffer, inline budget, block assets, Abilities, salted cache) and 7.1 client-side-media coexistence adopted behind `function_exists`/`class_exists` gates; requires WP 6.2 / PHP 8.2 floors enforced with clean self-deactivation (`performance-optimisation.php` guard). Post-merge queue records show live verification on WordPress 7.1.2 / PHP 8.3. CI matrix is the final authority — if 7.1 is unproven there, revert `Tested up to` to the last CI-proven release. |

## 4. Validator checklist (run when directory sync permits)

- [ ] `readme.txt` parses with the official WordPress.org readme validator (no warnings).
- [ ] Directory listing shows the new short description and exactly the 5 tags above.
- [ ] First screen reads: free + page caching + Core Web Vitals + images + CSS/JS.
- [ ] “Advanced capabilities” renders below core benefits with all advanced items intact.
- [ ] No PHP/JS runtime diff: `git diff --stat` touches only `readme.txt`,
      `performance-optimisation.php` (header line), `docs/growth/*`, and
      `docs/architecture/refactor-queue.yaml`.
- [ ] PHPCS untouched paths pass; no build needed (no `src/`/`build/` changes).
