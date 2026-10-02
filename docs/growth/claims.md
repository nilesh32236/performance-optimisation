# Claim register

Every public compatibility claim this plugin makes, with the evidence behind it, the command that
produced that evidence, and the date it was produced.

**Rule for this file:** if a claim has no reproducible evidence, it does not go in. A missing row is
better than a plausible number, because a missing row is visible and a plausible number is not.

**Last reviewed:** 2026-10-02
**Baseline commit:** `6823bf1d5c812ee8c93d061621c125877757c54d` (master)
**Live listing version at review time:** 2.4.0, uploaded 2026-09-24
**Owner of record:** plugin maintainer. Any row here may be deleted or corrected by the maintainer
without discussion.

---

## How to re-verify this file

Every row names one command. Run them from the repository root. Paste the output next to the row
before you change the "verified" date.

```sh
# 1. Declared metadata (repo copy of what ships)
sed -n '1,12p' readme.txt
sed -n '1,16p' performance-optimisation.php

# 2. Declared metadata (what the directory actually serves)
curl -sS "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=performance-optimisation"
curl -sS "https://plugins.svn.wordpress.org/performance-optimisation/trunk/readme.txt"

# 3. CI: the real run record for the shipped commit
gh api repos/nilesh32236/performance-optimisation/commits/<SHA>/check-runs \
  --jq '.check_runs[] | "\(.name) | \(.conclusion) | \(.completed_at)"'

# 4. The version-gate suite, locally
vendor/bin/phpunit --filter WpVersionGateTest
vendor/bin/phpunit --filter VersionGuardTest
vendor/bin/phpunit --filter PhpDeprecationHygieneTest

# 5. The absence claims (rows that say "no matrix exists")
grep -rn "wordpress-version\|wp-env\|playground" .github/workflows/    # expect: no output
grep -rn "php-version" .github/workflows/webpack.yml
```

---

## Claims

### C-01 — Minimum WordPress version is 6.2

| Field | Value |
|---|---|
| **Claim** | "Requires at least: 6.2". On anything older the plugin does not load its optimisation runtime and shows the administrator a notice. |
| **Evidence** | `readme.txt:4`; `performance-optimisation.php:5`; constant `WPPO_REQUIRES_WP = '6.2'` at `performance-optimisation.php:46`; boot gate `if ( $wppo_have_deps && wppo_version_guard() ) {` at `performance-optimisation.php:275`; comparison at `performance-optimisation.php:104` and `:126`. Covered by `tests/php/VersionGuardTest.php`. |
| **Command** | `sed -n '1,12p' readme.txt` and `vendor/bin/phpunit --filter VersionGuardTest` |
| **Result** | 15 tests, 37 assertions, 0 failures. |
| **Verified** | 2026-10-02 |
| **Expires** | Never. It is a code constant, not a test result. Re-verify on any change to `performance-optimisation.php`. |

### C-02 — "Tested up to" is 7.1

| Field | Value |
|---|---|
| **Claim** | `Tested up to: 7.1` in the shipped readme. |
| **Evidence — repo** | `readme.txt:6`; `performance-optimisation.php:7`. |
| **Evidence — live directory** | The WordPress.org API reports `tested = "7.1.2"` while the SVN trunk readme still reads `Tested up to: 7.1`. **The two disagree.** The directory appears to resolve the short form; the file is the authority for what we wrote. |
| **Command** | `curl -sS "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=performance-optimisation"` then `curl -sS "https://plugins.svn.wordpress.org/performance-optimisation/trunk/readme.txt"` |
| **Verified** | 2026-10-02 |
| **Expires** | On the next WordPress core release. Until then this row is the only thing standing between "7.1" and whatever the directory displays. |

### C-03 — Minimum PHP version is 8.2

| Field | Value |
|---|---|
| **Claim** | "Requires PHP: 8.2". Below that the plugin does not load its optimisation runtime and shows an administrator notice. |
| **Evidence** | `readme.txt:5`; `performance-optimisation.php:6`; `WPPO_REQUIRES_PHP = '8.2'` at `performance-optimisation.php:42`; gate at `performance-optimisation.php:275`; comparison at `performance-optimisation.php:113`. Composer enforces the same floor: `composer.json:13` `"require": { "php": ">=8.2" }` and `composer.json:55-56` `config.platform.php = "8.2"`. |
| **Command** | `sed -n '1,12p' readme.txt`; `grep -n '"php"\|platform' composer.json`; `vendor/bin/phpunit --filter VersionGuardTest` |
| **Verified** | 2026-10-02 |
| **Expires** | Never. Code + manifest constant. |

### C-04 — PHPUnit runs on PHP 8.2 in CI

| Field | Value |
|---|---|
| **Claim** | "PHP 8.2 is the version the automated unit-test suite runs on." |
| **Evidence** | `.github/workflows/webpack.yml:104` job `php-tests`, `webpack.yml:114` `name: Setup PHP 8.2`, `webpack.yml:124` `run: composer test`. The check `PHPUnit Tests` completed `success` at 2026-10-01T05:26:45Z on baseline commit `6823bf1d`. |
| **Command** | `grep -n "php-version\|composer test" .github/workflows/webpack.yml` and `gh api repos/nilesh32236/performance-optimisation/commits/6823bf1d/check-runs --jq '.check_runs[] | "\(.name) | \(.conclusion) | \(.completed_at)"'` |
| **Command** | `grep -n "php-version\|composer test" .github/workflows/webpack.yml` and `gh api repos/nilesh32236/performance-optimisation/commits/6823bf1d/check-runs --jq '.check_runs[] | "\(.name) | \(.conclusion) | \(.completed_at)"'` |
| **Result** | `PHPUnit Tests | success | 2026-10-01T05:26:45Z` |
| **Verified** | 2026-10-02 |
| **Expires** | On any change to `webpack.yml`. If PHPUnit is ever added to 8.3/8.4/8.5, this row must be rewritten to say so — do not quietly widen it. |

### C-05 — PHP 8.2, 8.3, 8.4 and 8.5 are each syntax-checked

| Field | Value |
|---|---|
| **Claim** | "PHP 8.2, 8.3, 8.4 and 8.5 are each syntax-checked on every push and pull request." |
| **Evidence** | `.github/workflows/webpack.yml:84` job `php-syntax`, `webpack.yml:90-91` `strategy.matrix.php: ["8.2","8.3","8.4","8.5"]`, `webpack.yml:102` `run: parallel-lint --exclude vendor .`. All four check-runs completed `success` between 2026-10-01T05:25:35Z and 05:25:42Z on `6823bf1d`. |
| **Command** | same `gh api ... check-runs` call as C-04, filtered on `PHP Syntax Check` |
| **Result** | `(8.2) success`, `(8.3) success`, `(8.4) success`, `(8.5) success` |
| **Verified** | 2026-10-02 |
| **Expires** | On any change to the `php-syntax` matrix. |

### C-06 — The full unit suite also passes on PHP 8.3

| Field | Value |
|---|---|
| **Claim** | The PHPUnit suite passes when run on PHP 8.3, outside CI. |
| **Evidence** | Local run on PHP 8.3.33 in the maintainer workspace. `vendor/bin/phpunit --filter WpVersionGateTest` → 50 tests / 337 assertions, 0 failures. `vendor/bin/phpunit` (full suite) → 2783 tests / 25799 assertions, 1 failure, which is `ArchitectureInventoryTest::test_generated_artifacts_are_current` on the `design/variant-c-redesign` branch whose unregenerated `docs/architecture/class-inventory.json` is another writer's open work — not a test failure caused by PHP 8.3. |
| **Command** | `php -v` then `vendor/bin/phpunit` |
| **Verified** | 2026-10-02 |
| **Expires** | Immediately, in the sense that this is a single-machine observation, not a matrix. **Do not restate this as "PHP 8.3 is tested".** It is one green run on one host. The public wording in `readme.txt` deliberately says 8.2 only. |

### C-07 — PHP 8.4 and 8.5 deprecation handling is pinned by a test

| Field | Value |
|---|---|
| **Claim** | "PHP 8.4 and 8.5 deprecation handling is pinned by a dedicated test." |
| **Evidence** | `tests/php/PhpDeprecationHygieneTest.php` — a pattern scanner that fails the build on `Reflection::{Method,Property}::setAccessible()` and on un-routed resource teardown calls. Documented in `docs/php-84-85-compat.md`. |
| **Command** | `vendor/bin/phpunit --filter PhpDeprecationHygieneTest` |
| **Result** | 16 tests, 94 assertions, 1 skipped, 0 failures. |
| **Verified** | 2026-10-02 |
| **Expires** | On any change to that test file or to `docs/php-84-85-compat.md`. |

### C-08 — WordPress 6.2 through 7.2 are covered by a version-gate unit matrix

| Field | Value |
|---|---|
| **Claim** | "WordPress 6.2 through 7.2 are covered by an automated version-gate suite." |
| **Evidence — what it is** | `tests/php/WpVersionGateTest.php` data provider `version_provider()` enumerates `'6.2'`, `'6.2.6'`, `'6.3-alpha'`, `'6.3'`, `'6.6.2'`, `'6.7.2'`, `'6.8'`, `'6.8.3'`, `'6.9-alpha'`, `'6.9-beta1'`, `'6.9'`, `'6.9.1'`, `'7.0-alpha'`, `'7.0'`, `'7.2'`, plus unset and empty globals. Every test evaluates the plugin's real `Wp_Version` gate helpers against each value. |
| **Evidence — what it is NOT** | It is a matrix over **version strings**, not a matrix over **WordPress installations**. No WordPress core is downloaded, booted, or rendered. `readme.txt` says this explicitly. |
| **Command** | `vendor/bin/phpunit --filter WpVersionGateTest` |
| **Result** | 50 tests, 337 assertions, 0 failures. |
| **Verified** | 2026-10-02 |
| **Expires** | On any change to `version_provider()` or to the gate helpers in `includes/Core/class-wp-version.php`. |

### C-09 — There is no automated multi-WordPress-version matrix

| Field | Value |
|---|---|
| **Claim** | "There is no automated multi-WordPress-version install matrix in this plugin's CI." |
| **Evidence** | `grep -rn "wordpress-version\|wp-env\|playground" .github/workflows/` returns **no output**. No workflow declares a `wordpress-version` input or matrix key. The only PHP matrix in CI is the `php-syntax` lint matrix (C-05); the only PHPUnit job is single-version (C-04). |
| **Command** | `grep -rn "wordpress-version\|wordpress_version\|WP_VERSION\|wp-env\|playground" .github/workflows/` |
| **Verified** | 2026-10-02 |
| **Expires** | The moment anyone adds a WordPress version matrix. If you add one, this row becomes false and `readme.txt` must change in the same PR. |

### C-10 — "Tested up to: 7.1" rests on a readiness audit, not on an automated run

| Field | Value |
|---|---|
| **Claim** | "Tested up to reflects a manual readiness audit of the release, not a per-release automated test run." |
| **Evidence** | `docs/wordpress-7x-readiness.md` — dated 2026-09-01, records 6.8 / 6.9 / 7.0 / 7.1 / 7.2 API surfaces, which are adopted or `function_exists()`-gated, with file-level pointers. It is a code review against released core APIs. It contains no browser or install matrix. |
| **Command** | `head -6 docs/wordpress-7x-readiness.md` |
| **Verified** | 2026-10-02 |
| **Expires** | **Already aging.** The audit is dated 2026-09-01. Treat it as stale on any core release after that date and re-run the audit before shipping a new "Tested up to". |

### C-11 — Live listing state at review time

| Field | Value |
|---|---|
| **Claim** | Listing facts, recorded so that a later reader can tell what changed and when. |
| **Evidence** | WordPress.org API, 2026-10-02: `version 2.4.0`, `tested 7.1.2`, `requires 6.2`, `requires_php 8.2`, `rating 100`, `num_ratings 4`, `active_installs 0`, `last_updated 2026-09-24 1:10am GMT`, `added 2025-02-24`, tags `cache, performance, speed, pagespeed, minify`. |
| **Command** | `curl -sS "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=performance-optimisation" \| python3 -m json.tool` |
| **Verified** | 2026-10-02 |
| **Expires** | Any release. Never quote these numbers without re-running the command. |

---

## Claims deliberately NOT made

Recorded so that nobody later "fixes" the copy by adding them back.

| Not claimed | Why not |
|---|---|
| "Tested on every WordPress release since 6.2" | No such matrix exists (C-09). |
| "Full test suite runs on PHP 8.2–8.5" | PHPUnit runs on 8.2 only in CI (C-04). 8.3–8.5 are syntax-checked (C-05). `docs/site/compatibility.html` said otherwise until this register existed. |
| "Fully compatible with WooCommerce / Elementor / Divi" | Integration safeguards exist; a blanket guarantee across every version of every extension is not testable and is not tested. |
| "Works with every theme" | Same reason. |
| "Will improve your PageSpeed score by X%" | The only benchmark in the repo is a single internal example, and the readme already says results vary. No reproducible harness. |
| "Accessible / WCAG compliant" | See `docs/growth/ACCESSIBILITY-STATEMENT.md`. No independent audit, no assistive-technology user testing. |
| "Battle-tested" / "trusted by N sites" | `active_installs` is 0 and `num_ratings` is 4 (C-11). A volume claim would be false on its face. |
| "Faster than plugin X" | No head-to-head benchmark exists. |

---

## Expiry policy

1. **Code-backed rows** (C-01, C-03, C-07) do not expire on a calendar. They expire when the named
   file changes. Reviewer check: did the diff touch that file?
2. **Run-backed rows** (C-04, C-05, C-06) expire when the workflow changes or when the baseline
   commit moves more than one release behind.
3. **Declared-metadata rows** (C-02, C-10) expire on the next WordPress core release. A new
   "Tested up to" without a fresh audit is a claim with no evidence behind it.
4. **Absence rows** (C-09) expire the instant the thing they say is absent appears.
5. **Live-state rows** (C-11) are a snapshot. Never reuse the numbers.
6. **Anything not in this file is not a claim.** If copy in `readme.txt`, `readme.md`,
   `docs/site/**` or the directory listing says something that is not here, that copy is
   unbacked. Fix the copy or add the evidence — in that order.
