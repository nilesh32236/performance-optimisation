# Phase F — Trust & Conversion

**Campaign:** Phase F — Autonomous Trust & Conversion Campaign
**Issue:** #1639

## Objective

Make the existing Performance Optimisation product easier to trust, evaluate, install, onboard, recover, and support. This phase adds no optimization features and creates no fake reviews, ratings, support posts, installations, or performance claims.

## Trust audit

| Surface | Friction found | Response |
| --- | --- | --- |
| `readme.txt` | Free positioning existed in the FAQ but appeared after a long feature list. | Added a short free/open-source trust block near the description. |
| First impression | Advanced features appeared before the safe first run. | Kept the value proposition focused on page cache, Core Web Vitals, images, and CSS/JS. |
| `WelcomePanel` | The panel said “get started” but did not state the staged test-and-review sequence. | Added Start Safe → Test → Review → Advanced copy. |
| Presets | Safe/Balanced/Aggressive risk language was already present from Phase C. | Kept behavior unchanged and linked the preset workflow to the trust story. |
| Compatibility | Users could confuse a safeguard with a universal guarantee. | Kept the Phase E compatibility matrix and linked it from the readme and feature index. |
| Troubleshooting | Recovery advice existed but support evidence/redaction was scattered. | Added a dedicated support and safe recovery source page. |
| External services | The readme documents PageSpeed, Google Fonts, and edge purge behavior. | Kept the disclosures and added a clear instruction to keep credentials server-side. |
| Changelog and upgrade | Users could update without a staged verification habit. | Added upgrade steps: record cache owner, export settings, run verify, test logged out and the main form. |
| Reviews | A rating or review request could create pressure or false confidence. | Added an explicit statement that the plugin does not ask for a rating or review. |

## Value proposition

The first impression now states that the plugin:

- Is free and open source under GPLv2 or later
- Has no premium version, feature lock, or subscription requirement
- Focuses on WordPress performance: page cache, Core Web Vitals, images, CSS, and JavaScript
- Starts with safe defaults and a testable workflow
- Keeps advanced options available without requiring them
- Provides compatibility boundaries and a safe recovery path

## Onboarding changes

`src/components/WelcomePanel.js` now explains the sequence before the four existing actions:

1. Start Safe
2. Test the site
3. Review Performance Audit
4. Enable advanced options only when needed

The existing actions still enable Page Cache, minify CSS/JS, enable lazy loading, and run the WooCommerce self-test. No action, payload, or preset behavior changed.

`OptimizationPresets` keeps the existing Safe/Balanced/Aggressive payloads, preview, restore point, and Undo action. The copy continues to identify Beginner, Recommended, and Advanced audiences.

## Support and recovery

`docs/site/support.html` gives users one support path:

1. Disable the most recent advanced optimization.
2. Save and clear the affected page cache.
3. Test the site logged out.
4. Run `wp wppo verify` and `wp wppo system-info`.
5. Record versions, URL, steps, cache owner, and redacted evidence.
6. Report the problem without passwords, API keys, cookies, bearer tokens, session IDs, private data, or database exports.

The readmes now link the support route and repeat the redaction rule.

## Compatibility trust

The existing Phase E compatibility matrix remains the source of truth. It separates:

- Verified: current CI or recorded live evidence
- Supported: implemented path with configuration and recovery
- Best effort: common setup that needs site-specific testing
- Known limitation: a deliberate boundary the plugin does not hide

The public readme keeps the caution that cache, minification, defer/delay, image, and CDN behavior must be tested on staging.

## External services and ethics

The plugin documents PageSpeed, Google Fonts, Cloudflare, Bunny, and Varnish behavior. The trust copy does not imply that external services are required for every feature, and it does not ask users to post credentials or leave ratings.

No review automation, fake support content, fabricated installation count, or unsupported score was added.

## New-user Playwright journey

The journey checks the live admin without applying settings:

1. Open the plugin Dashboard.
2. Read the first-run or Dashboard trust copy.
3. Open the Safe preset preview and confirm the explanation and diff surface.
4. Navigate to File Optimization, Preload, Image Optimization, Database, Object Cache, and Tools.
5. Confirm the Tools/System Info and support/recovery route can be found.
6. Check for console errors, failed requests, and horizontal overflow.

### Observed result

The live admin journey passed the read-only interaction checks at desktop and mobile:

- Safe preset preview opened and exposed its diff/explanation.
- All six secondary tabs rendered without overflow.
- Tools exposed System Info.
- No console errors appeared.
- The WelcomePanel was not visible because the existing site had already dismissed onboarding, so the new safe-sequence copy could not be observed on the live instance; focused Jest coverage verifies it.
- Public troubleshooting returned `200`.
- Public support returned `404` and compatibility returned `503` during the check because the Phase E source pages still await the editor publishing sync.

No Apply, save, or setting mutation was sent during the Playwright journey.

## WordPress verification

- Performance Optimisation remains active.
- `all-in-one-wp-migration` remains inactive.
- The live frontend and admin smoke checks remain unchanged.
- Public documentation synchronization remains a separate publishing step and is not claimed here.

## Remaining trust backlog

- Measure real support resolution time and repeat-failure causes before adding more onboarding prompts.
- Keep compatibility evidence tied to CI and live smoke tests as versions move.
- Review external-service disclosures whenever a new integration is added.
- Do not add review prompts or rating gates.
