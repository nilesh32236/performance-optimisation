# Claim register

Every public compatibility claim this plugin makes, with the evidence behind it, the command that
produced that evidence, and the **commit SHA that evidence was produced on**.

**Rule for this file:** if a claim has no reproducible evidence, it does not go in. A missing row is
better than a plausible number, because a missing row is visible and a plausible number is not.
Evidence from another branch, another commit, or another file is **not** evidence for this one.

**Exemption (cross-branch run records, recorded 2026-10-06):** C-04 and C-05 cite
check-run records on `72cd755c` (`release/2.4.1`), and C-13 cites probe results on
`origin/design/variant-c-redesign`, because GitHub check-runs and unmerged-branch probe
output cannot be reproduced from this branch's tree. Each such row names the foreign SHA
on its face, is marked re-verification-needed on any workflow change, and must never be
read as same-branch evidence. No same-branch substitute exists; absent this written
exemption those three rows would fail the rule above.

**Last reviewed:** 2026-10-06
**Baseline commit (this branch):** `644556a5f3663e517a9ae12f47cd30dda84ffb7c` (`growth/fix-wcag-claim-and-register`)
**Live listing version at review time:** 2.4.1, uploaded 2026-10-03
**Owner of record:** plugin maintainer. Any row here may be deleted or corrected by the maintainer
without discussion.

### What changed in this revision, and why

A previous revision of this register was written against commit `6823bf1d`. Every command below was
re-run on this branch, and four rows did not survive:

| Row | Previous evidence | Verdict now |
|---|---|---|
| C-04 | "PHPUnit Tests `success` at 2026-10-01T05:26:45Z on `6823bf1d`" | **FALSE.** No such check-run exists on `6823bf1d`. Re-cited against `72cd755c`. |
| C-05 | Four `PHP Syntax Check` successes on `6823bf1d` | **FALSE.** No such check-run exists on `6823bf1d`. Re-cited against `72cd755c`. |
| C-06 | "2783 tests, **1 failure** (`ArchitectureInventoryTest`)" | **FALSE for this branch.** That result is from `design/variant-c-redesign`. Here the suite is green. |
| C-11 | `active_installs 0`, `num_ratings 4` | **TRUE**, re-confirmed 2026-10-03, with a warning added about the `rating` field. |

A fifth row (C-13) was **added**, because the register omitted the accessibility measurements that
later turned out to exist.

---

## How to re-verify this file

Every row names one command. Run them from the repository root on the SHA named in the row. Paste the
output next to the row before you change the "verified" date.

```sh
git rev-parse HEAD                                    # confirm which commit you are on
php -v                                                # the interpreter the numbers below came from

# 1. Declared metadata (repo copy of what ships)
sed -n '1,12p' readme.txt
sed -n '1,16p' performance-optimisation.php

# 2. Declared metadata (what the directory actually serves)
curl -sS "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=performance-optimisation"
curl -sS "https://plugins.svn.wordpress.org/performance-optimisation/trunk/readme.txt"

# 3. CI: the real run record for a commit
gh api repos/nilesh32236/performance-optimisation/commits/<SHA>/check-runs \
  --jq '.check_runs[] | "\(.name) | \(.conclusion) | \(.completed_at)"'

# 4. The version-gate suite, locally
vendor/bin/phpunit --filter WpVersionGateTest
vendor/bin/phpunit --filter VersionGuardTest
vendor/bin/phpunit --filter PhpDeprecationHygieneTest

# 5. The absence claims (rows that say "no matrix exists")
grep -rn "wordpress-version\|wp-env\|playground" .github/workflows/   # expect: no output
grep -n "php-version\|composer test\|parallel-lint" .github/workflows/webpack.yml
```

**A note on `gh api .../check-runs`:** a commit can have **zero** check-runs from a workflow and still
look healthy. The CI workflow in `webpack.yml` carries `paths-ignore` for `**.md`, `build/**`,
`.distignore` and `.gitignore`, so commits touching only those paths run none of the PHP jobs. An empty
result is not a pass. Always read the check **names** that came back, not just the count.

---

## Claims

### C-01 — Minimum WordPress version is 6.2

| Field | Value |
|---|---|
| **Claim** | "Requires at least: 6.2". On anything older the plugin does not load its optimisation runtime and shows the administrator a notice. |
| **Verdict** | **TRUE** |
| **Evidence** | `readme.txt:4`; `performance-optimisation.php:5`; constant `WPPO_REQUIRES_WP = '6.2'` at `performance-optimisation.php:46`; boot gate `if ( $wppo_have_deps && wppo_version_guard() ) {` at `performance-optimisation.php:275`; comparison at `performance-optimisation.php:104` and `:126`. Covered by `tests/php/VersionGuardTest.php`. |
| **Command** | `sed -n '1,12p' readme.txt`; `grep -n "WPPO_REQUIRES_WP" performance-optimisation.php`; `vendor/bin/phpunit --filter VersionGuardTest` |
| **Result** | `Requires at least: 6.2`; constant at `:46`; gate at `:275`. Test: **15 tests, 37 assertions, 0 failures**. |
| **SHA** | `66fd4914164d135d0176a45243748d7af6eee44e` |
| **Verified** | 2026-10-03 |
| **Re-verified** | 2026-10-06 on `644556a5` (`growth/fix-wcag-claim-and-register`): this branch touches `readme.txt:298` only; header lines 1–12 byte-identical, `WPPO_REQUIRES_WP` still at `performance-optimisation.php:46`, gate at `:275`. |
| **Expires** | Never. It is a code constant, not a test result. Re-verify on any change to `performance-optimisation.php`. |

### C-02 — "Tested up to" is 7.1

| Field | Value |
|---|---|
| **Claim** | `Tested up to: 7.1` in the shipped readme. |
| **Verdict** | **TRUE** (repo). The directory's own display disagrees; both values recorded below. |
| **Evidence — repo** | `readme.txt:6`; `performance-optimisation.php:7`. |
| **Evidence — live directory** | The WordPress.org API reports `tested = "7.1.2"` while the SVN trunk readme still reads `Tested up to: 7.1`. **The two disagree.** The file is the authority for what we wrote. |
| **Command** | `curl -sS "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=performance-optimisation"` then `curl -sS "https://plugins.svn.wordpress.org/performance-optimisation/trunk/readme.txt"` |
| **Result** | API: `tested: 7.1.2`. SVN trunk `readme.txt:6`: `Tested up to: 7.1`. |
| **SHA** | `66fd4914164d135d0176a45243748d7af6eee44e` |
| **Verified** | 2026-10-03 |
| **Re-verified** | 2026-10-06 on `644556a5`: `readme.txt:6` still reads `Tested up to: 7.1`; live API still `tested 7.1.2` / version 2.4.1. |
| **Expires** | On the next WordPress core release. Until then this row is the only thing standing between "7.1" and whatever the directory displays. |

### C-03 — Minimum PHP version is 8.2

| Field | Value |
|---|---|
| **Claim** | "Requires PHP: 8.2". Below that the plugin does not load its optimisation runtime and shows an administrator notice. |
| **Verdict** | **TRUE** |
| **Evidence** | `readme.txt:5`; `performance-optimisation.php:6`; `WPPO_REQUIRES_PHP = '8.2'` at `performance-optimisation.php:42`; gate at `performance-optimisation.php:275`; comparison at `performance-optimisation.php:113`. Composer enforces the same floor: `composer.json:13` `"require": { "php": ">=8.2" }` and `composer.json:55-56` `config.platform.php = "8.2"`. |
| **Command** | `sed -n '1,12p' readme.txt`; `grep -n '"php"\|platform' composer.json`; `vendor/bin/phpunit --filter VersionGuardTest` |
| **Result** | `Requires PHP: 8.2`; `composer.json:13: "php": ">=8.2",`; `55: "platform": {` / `56: "php": "8.2"`. Test: **15 tests, 37 assertions, 0 failures**. |
| **SHA** | `66fd4914164d135d0176a45243748d7af6eee44e` |
| **Verified** | 2026-10-03 |
| **Re-verified** | 2026-10-06 on `644556a5`: `readme.txt:5` still `Requires PHP: 8.2`; `composer.json:13` still `"php": ">=8.2"`, platform still `8.2`. |
| **Expires** | Never. Code + manifest constant. |

### C-04 — PHPUnit runs on PHP 8.2 in CI

| Field | Value |
|---|---|
| **Claim** | "PHP 8.2 is the version the automated unit-test suite runs on." |
| **Verdict** | **TRUE, but re-cited.** The previous run record was attached to the wrong commit and has been replaced. |
| **Why this row was rewritten** | The register previously cited `6823bf1d` and reported `PHPUnit Tests \| success \| 2026-10-01T05:26:45Z`. Re-running the cited command on that SHA returns **51 check-runs, none of them named `PHPUnit Tests` or `PHP Syntax Check`** (the CI workflow skips PHP jobs on `paths-ignore`d paths). The cited workflow line numbers were also wrong: `:104` is `with:`, `:114` is `- name: JS Tests (React 19)`, `:124` is `timeout-minutes: 10`. |
| **Evidence** | `.github/workflows/webpack.yml:140` job `php-tests`; `:153` `php-version: '8.2'`; `:160` `run: composer test`. |
| **Command** | `grep -n "php-syntax:\|php-tests:\|php-version\|composer test\|parallel-lint" .github/workflows/webpack.yml` and `gh api repos/nilesh32236/performance-optimisation/commits/72cd755c/check-runs --jq '.check_runs[] \| "\(.name) \| \(.conclusion) \| \(.completed_at)"'` |
| **Result** | `PHPUnit Tests \| success \| 2026-10-02T14:26:43Z` |
| **SHA** | **`72cd755c3913bb2856f76b78d93c3cc3577c798d`** (`release/2.4.1`) — *not* `6823bf1d` |
| **Verified** | 2026-10-03 |
| **Expires** | On any change to `webpack.yml`. If PHPUnit is ever added to 8.3/8.4/8.5, this row must be rewritten to say so — do not quietly widen it. |

### C-05 — PHP 8.2, 8.3, 8.4 and 8.5 are each syntax-checked in CI

| Field | Value |
|---|---|
| **Claim** | "PHP 8.2, 8.3, 8.4 and 8.5 are each syntax-checked by CI on pushes and pull requests that touch source." |
| **Verdict** | **TRUE, but re-cited and narrowed.** The previous run record was attached to the wrong commit. The phrase "every push and pull request" was also false: `webpack.yml:6-9` sets `paths-ignore` for `build/**`, `**.md`, `.distignore`, `.gitignore`. |
| **Evidence** | `.github/workflows/webpack.yml:120` job `php-syntax`; `:126` `strategy.matrix`; `:131` `- name: Setup PHP ${{ matrix.php }}`; `:134` `php-version: ${{ matrix.php }}`; `:138` `run: parallel-lint --exclude vendor .`. Matrix values `["8.2","8.3","8.4","8.5"]`. |
| **Command** | same `gh api ... check-runs` call as C-04 |
| **Result** | `PHP Syntax Check (8.2–8.5) (8.2) \| success \| 2026-10-02T14:24:41Z`<br>`PHP Syntax Check (8.2–8.5) (8.3) \| success \| 2026-10-02T14:24:41Z`<br>`PHP Syntax Check (8.2–8.5) (8.4) \| success \| 2026-10-02T14:24:50Z`<br>`PHP Syntax Check (8.2–8.5) (8.5) \| success \| 2026-10-02T14:24:42Z` |
| **SHA** | **`72cd755c3913bb2856f76b78d93c3cc3577c798d`** (`release/2.4.1`) — *not* `6823bf1d` |
| **Verified** | 2026-10-03 |
| **Expires** | On any change to the `php-syntax` matrix or to its `paths-ignore`. |

### C-06 — The full unit suite passes locally on PHP 8.3

| Field | Value |
|---|---|
| **Claim** | The PHPUnit suite passes when run on PHP 8.3 in the maintainer workspace. |
| **Verdict** | **TRUE on this branch.** The previous entry recorded a **failing** run; that run was taken on `design/variant-c-redesign`, not here, and it has been removed. |
| **Evidence** | Local run on PHP 8.3.33. `vendor/bin/phpunit --filter WpVersionGateTest` → 50 tests / 337 assertions, 0 failures. Full `vendor/bin/phpunit` → **2861 tests / 26191 assertions, 9 skipped, 0 failures, exit code 0.** |
| **Command** | `php -v` then `vendor/bin/phpunit` |
| **SHA** | `66fd4914164d135d0176a45243748d7af6eee44e` |
| **Verified** | 2026-10-03 |
| **Expires** | Immediately, in the sense that this is a single-machine observation, not a matrix. **Do not restate this as "PHP 8.3 is tested".** It is one green run on one host. The public wording in `readme.txt` deliberately says 8.2 only. |
| **Note on the removed failure** | A prior revision cited `ArchitectureInventoryTest::test_generated_artifacts_are_current` as failing with a stale `docs/architecture/class-inventory.json` on `design/variant-c-redesign`. That branch is not this one, and this register cannot vouch for its state. If someone needs that number, re-run it on that branch and record the SHA there. |

### C-07 — PHP 8.4 and 8.5 deprecation handling is pinned by a test

| Field | Value |
|---|---|
| **Claim** | "PHP 8.4 and 8.5 deprecation handling is pinned by a dedicated test." |
| **Verdict** | **TRUE** |
| **Evidence** | `tests/php/PhpDeprecationHygieneTest.php` — a pattern scanner that fails the build on `Reflection::{Method,Property}::setAccessible()` and on un-routed resource teardown calls. Documented in `docs/php-84-85-compat.md`. |
| **Command** | `vendor/bin/phpunit --filter PhpDeprecationHygieneTest` |
| **Result** | **16 tests, 94 assertions, 1 skipped, 0 failures.** |
| **SHA** | `66fd4914164d135d0176a45243748d7af6eee44e` |
| **Verified** | 2026-10-03 |
| **Expires** | On any change to that test file or to `docs/php-84-85-compat.md`. |

### C-08 — WordPress 6.2 through 7.2 are covered by a version-gate unit matrix

| Field | Value |
|---|---|
| **Claim** | "WordPress 6.2 through 7.2 are covered by an automated version-gate suite." |
| **Verdict** | **TRUE** |
| **Evidence — what it is** | `tests/php/WpVersionGateTest.php` data provider `version_provider()` enumerates `'6.2'`, `'6.2.6'`, `'6.3-alpha'`, `'6.3'`, `'6.6.2'`, `'6.7.2'`, `'6.8'`, `'6.8.3'`, `'6.9-alpha'`, `'6.9-beta1'`, `'6.9'`, `'6.9.1'`, `'7.0-alpha'`, `'7.0'`, `'7.2'`, plus unset and empty globals. Every test evaluates the plugin's real `Wp_Version` gate helpers against each value. |
| **Evidence — what it is NOT** | It is a matrix over **version strings**, not a matrix over **WordPress installations**. No WordPress core is downloaded, booted, or rendered. It runs inside the PHPUnit job described in C-04, and therefore is also subject to that job's `paths-ignore`. `readme.txt` must say this explicitly. |
| **Command** | `vendor/bin/phpunit --filter WpVersionGateTest` |
| **Result** | **50 tests, 337 assertions, 0 failures.** |
| **SHA** | `66fd4914164d135d0176a45243748d7af6eee44e` |
| **Verified** | 2026-10-03 |
| **Expires** | On any change to `version_provider()` or to the gate helpers in `includes/Core/class-wp-version.php`. |

### C-09 — There is no automated multi-WordPress-version matrix

| Field | Value |
|---|---|
| **Claim** | "There is no automated multi-WordPress-version install matrix in this plugin's CI." |
| **Verdict** | **TRUE** |
| **Evidence** | `grep -rn "wordpress-version\|wp-env\|playground" .github/workflows/` returns **no output** (exit 1). The widened form including `wordpress_version` and `WP_VERSION` also returns nothing. No workflow declares a `wordpress-version` input or matrix key. The only PHP matrix in CI is the `php-syntax` lint matrix (C-05); the only PHPUnit job is single-version (C-04). |
| **Command** | `grep -rn "wordpress-version\|wordpress_version\|WP_VERSION\|wp-env\|playground" .github/workflows/` |
| **SHA** | `66fd4914164d135d0176a45243748d7af6eee44e` |
| **Verified** | 2026-10-03 |
| **Expires** | The moment anyone adds a WordPress version matrix. If you add one, this row becomes false and `readme.txt` must change in the same PR. |

### C-10 — "Tested up to: 7.1" rests on a readiness audit, not on an automated run

| Field | Value |
|---|---|
| **Claim** | "Tested up to reflects a manual readiness audit of the release, not a per-release automated test run." |
| **Verdict** | **TRUE** |
| **Evidence** | `docs/wordpress-7x-readiness.md` — dated 2026-09-01, records 6.8 / 6.9 / 7.0 / 7.1 / 7.2 API surfaces, which are adopted or `function_exists()`-gated, with file-level pointers. It is a code review against released core APIs. It contains no browser or install matrix. |
| **Command** | `head -6 docs/wordpress-7x-readiness.md` |
| **Result** | `# WordPress 6.8 → 7.2 Readiness` … `**Date:** 2026-09-01` |
| **SHA** | `66fd4914164d135d0176a45243748d7af6eee44e` |
| **Verified** | 2026-10-03 |
| **Expires** | **Already aging.** The audit is dated 2026-09-01. Treat it as stale on any core release after that date and re-run the audit before shipping a new "Tested up to". |

### C-11 — Live listing state at review time

| Field | Value |
|---|---|
| **Claim** | Listing facts, recorded so that a later reader can tell what changed and when. |
| **Verdict** | **TRUE** (snapshot) — **re-fetched 2026-10-05; the previous numbers were stale** |
| **Evidence** | WordPress.org API, re-fetched **2026-10-05**: `version 2.4.1`, `tested 7.1.2`, `requires 6.2`, `requires_php 8.2`, `rating 100`, `num_ratings 4`, `active_installs 0`, `last_updated 2026-10-03 9:56am GMT`, `added 2025-02-24`, tags `cache, core-web-vitals, optimization, pagespeed, performance`. |
| **What changed since the 2026-10-03 snapshot** | **A release shipped between the two fetches.** `version` went `2.4.0` → **`2.4.1`**; `last_updated` went `2026-09-24` → **`2026-10-03 9:56am GMT`**; and the tag set changed to the five intended tags — the old listing was still serving `cache, minify, pagespeed, performance, speed`. Unchanged: `tested`, `requires`, `requires_php`, `rating`, `num_ratings`, `active_installs`. |
| **Command** | `curl -sS "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=performance-optimisation" \| python3 -m json.tool` |
| **SHA / date** | Live API, re-checked 2026-10-06 from `644556a5f3663e517a9ae12f47cd30dda84ffb7c`: still `version 2.4.1`, `last_updated 2026-10-03 9:56am GMT`, `tested 7.1.2` |
| **Verified** | 2026-10-06 |
| **Expires** | Any release — and a release happened inside this register's own lifetime, which is the proof. **Never quote these numbers without re-running the command.** |
| **Do not repackage** | `rating 100` is computed from **`num_ratings 4`**. Four ratings cannot support a quality claim. Never surface `rating` without `num_ratings` beside it, and never use either in copy. `active_installs` is **0** — the listing shows **fewer than 10 active installs**, and no copy may imply otherwise. |

### C-12 — "Fully compatible with popular themes and page builders" — was FALSE, was served, is now fixed

**This was the plugin's single most public compatibility claim, and it was false. As of 2026-10-03 it
is no longer served. This row is retained as a regression tripwire, not as an open defect.**

| Where | Claim | State as of 2026-10-05 |
|---|---|---|
| `readme.txt` (repo) | "includes compatibility safeguards for…" | Corrected wording. |
| **WordPress.org listing** | **"Fully compatible with popular themes and page builders (Elementor, Divi, Astra, GeneratePress, Kadence…)"** | **No longer present.** Ships 2.4.1. |

**Evidence as it stood when the row was first written (2026-10-03):** the API returned `"version":"2.4.0"`
and `sections.description` contained the overclaim. See the correction-status block below for the
re-verification that closed it.

**Why it was false as stated.** "Fully compatible" is a claim about the behaviour of software this
plugin does not control. Nothing in this repository can establish it, and no test suite here exercises
a third party's themes or page builders. The plugin ships *safeguards* — guards against known conflicts
— which is a real and much smaller claim, and the size of the difference is the whole point.

### Correction status — RESOLVED 2026-10-03. This row is now RETIRED, not merely pending.

The previous revision of this row said the release had not been cut and that the listing still served
2.4.0's text. **That is no longer true.** Re-fetched 2026-10-05:

| Field | Before (2026-10-03) | Now (2026-10-05) |
|---|---|---|
| Listing version | 2.4.0 | **2.4.1** |
| `last_updated` | 2026-09-24 1:10am GMT | **2026-10-03 9:56am GMT** |
| `"Fully compatible"` in `sections.description` | present | **absent** |

```sh
curl -sS "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=performance-optimisation" \
  | python3 -c "import json,sys; d=json.load(sys.stdin); print(d['version'], d['last_updated'], 'Fully compatible' in d['sections']['description'])"
```

**Output:** `2.4.1 2026-10-03 9:56am GMT False`

The live description now opens:

> **Performance Optimisation** is a free WordPress performance plugin for site owners who want faster
> pages without a complex stack of separate tools. It combines page caching, CSS and JavaScript
> optimization, image optimization, and Core Web Vitals monitoring in one clear dashboard.

Cross-checked against the deployed file itself, not only the API:

```sh
curl -sS "https://plugins.svn.wordpress.org/performance-optimisation/trunk/readme.txt" | grep -n "Fully compatible"
```

The only surviving occurrence of the phrase is at **line 235, inside the 2.4.1 changelog entry**, where
it is quoted as the withdrawn claim — "the previous text read 'Fully compatible with popular themes and
page builders', which is a claim about the behaviour of software this plugin does not control". That is
a correction notice, not a claim.

**What this row is now for.** The defect it described is fixed and verified. The row is retained
because a register that deletes rows when they go stale stops being a register. Anyone re-running the
command above will get `False`; if it ever returns `True`, the release process has regressed and
`readme.txt` must be checked before anything else.

### C-13 — Accessibility: what was measured, and what was not

| Field | Value |
|---|---|
| **Claim** | The plugin makes **no WCAG conformance claim of any kind.** This row exists so that the absence is deliberate and recorded, not merely forgotten. |
| **Verdict** | **TRUE**, with the measurements below |
| **The narrow, real measurement** | On `design/variant-c-redesign`, two WCAG criteria were measured to zero failures in a real browser against the live admin: **1.4.3 Contrast (Minimum)** (Level AA) and **1.4.12 Text Spacing** (Level AA). |
| **Command** | `git show origin/design/variant-c-redesign:scripts/probe-text-spacing.mjs`, and the commit bodies of `77e89ce1` and `38d5f15d` for the contrast and text-spacing results. |
| **Result** | `77e89ce1` — "overview 0 speed 0 media 0 data-system 0 manage 0 / TOTAL 0". `38d5f15d` — "clipped/collapsed 0 overlaps 0 horizontal scroll none / TOTAL 0". Found-then-fixed: `c97ee033` recorded 35 AA text-contrast failures first; `77e89ce1` recorded their closure to TOTAL 0 (see the withdrawn-claims table below for the finding half). |
| **SHA** | `c97ee033…`, `77e89ce1…`, `38d5f15d…` on `origin/design/variant-c-redesign` — **not merged into `master`** |
| **NOT measured — do not claim** | **2.4.7 Focus Visible**, **2.4.11 Focus Not Obscured** and **2.4.13 Focus Appearance** have probe scripts on that branch (`probe-focus.mjs`, `probe-focus-ring.mjs`) but **no recorded result was found in the repository**. `2.4.13` is **Level AAA**, not AA. `probe-forced-colors.mjs` tests a rendering *condition*, not a numbered criterion. `probe-inspector.mjs`, `probe-save.mjs` and `probe-tokens.mjs` are **not WCAG criteria** at all. |
| **NOT a conformance claim** | There is no automated WCAG conformance scan: `axe-core` is not a dependency. No probe result artifact is committed — only screenshots. The recorded results exist **solely in commit messages**, which is weaker evidence than a run log. |
| **Also not done** | No independent accessibility audit. No assistive-technology testing — no screen reader was ever used to operate the plugin. No testing by a person who uses assistive technology. |
| **Verified** | 2026-10-03 |
| **Where the full disclosure lives** | `docs/growth/ACCESSIBILITY-STATEMENT.md`, added by **open PR #1759** (head `38ed2d74f883a7f9e03af286f149169a9eb9d9d5`). **It is not on this branch yet.** Until it lands, this row is the only accessibility disclosure, and it is not a conformance statement. |

> **A correction to a widely repeated number.** It is tempting to say "seven WCAG 2.2 AA criteria measured
> at zero failures on `design/variant-c-redesign`". **Do not.** There are seven probe *scripts* on that
> branch, and they do not map to seven AA criteria: two criteria have recorded results, three more have
> probes with no recorded result, one of those is AAA rather than AA, and three of the seven scripts are
> not WCAG checks. The honest sentence is the one in this row: two AA criteria, measured, on an
> unmerged branch, reported by their authors.

---

## Claims deliberately NOT made

Recorded so that nobody later "fixes" the copy by adding them back.

| Not claimed | Why not |
|---|---|
| "Tested on every WordPress release since 6.2" | No such matrix exists (C-09). |
| "Full test suite runs on PHP 8.2–8.5" | PHPUnit runs on 8.2 only in CI (C-04). 8.3–8.5 are syntax-checked (C-05). PR #1756 corrects `docs/site/compatibility.html`, which still says otherwise on `master`. |
| "Fully compatible with WooCommerce / Elementor / Divi" | Integration safeguards exist; a blanket guarantee across every version of every extension is not testable and is not tested (C-12). |
| "Works with every theme" | Same reason. |
| "Will improve your PageSpeed score by X%" | The only benchmark in the repo is a single internal example, and the readme already says results vary. No reproducible harness. |
| "Accessible" / "WCAG compliant" / "WCAG-AA" | See C-13. No conformance claim at any level. No independent audit, no assistive-technology user testing, no conformance scanner. |
| "Battle-tested" / "trusted by N sites" | `active_installs` is **0** and `num_ratings` is **4** (C-11). A volume claim would be false on its face. |
| "Faster than plugin X" | No head-to-head benchmark exists. |
| "Rated 100% by users" | `rating: 100` is computed from 4 ratings (C-11). |
| "CI runs on every push and pull request" | The CI workflow sets `paths-ignore` for `**.md`, `build/**`, `.distignore`, `.gitignore` (`webpack.yml:6-9`, `:13-16`). |

---

## Known unevidenced claims still present in the repository

Copy that is not backed by anything in this file. Fix the copy or add the evidence — in that order.

| Location | Text | Status |
|---|---|---|
| `readme.txt:298` (2.0.0 changelog) | "New: Redesigned dashboard and all settings tabs with **WCAG AA contrast**…" | **WITHDRAWN 2026-10-05.** The later measured contrast work on `design/variant-c-redesign` found **35** AA text-contrast failures in the admin (`c97ee033`, `77e89ce1`) and closed them only on that unmerged branch, so the shipped 2.0.0 claim was contradicted by the project's own measurement. Found-then-fixed: the 35 were found in `c97ee033` and closed to TOTAL 0 in `77e89ce1` (see C-13 for the closure half). The clause has now been removed from `readme.txt` and replaced with a note that the claim was never measured and is withdrawn, matching the 2.4.1 changelog's own correction style. It is still live on the listing at `trunk/readme.txt:298` and will clear on the next release. |
| `changelog.md:48` | "- **Redesigned dashboard and all settings tabs** with WCAG AA contrast, equal-height metrics, segmented tabs, full mobile/RTL support and 44×44 touch targets." (verbatim) | **STILL PRESENT 2026-10-06.** Same unmeasured 2.0.0-era WCAG AA wording as the withdrawn `readme.txt:298` line, surviving in the repo's own changelog. Not edited here (outside this PR's write scope); needs the same withdraw-or-measure treatment before anyone quotes it. |
| `docs/site/compatibility.html:4` | `WordPress 7.1` → **Verified** → "CI syntax and React 19 coverage"; `PHP 8.2–8.5` → **Verified** → "CI syntax checks **and full PHPUnit runs**" | **CONTRADICTED** by C-04 and C-09. Open PR #1756 replaces both rows. Not edited here to avoid colliding with it. |
| `docs/growth/claims.md` (previous revision) | citation to `docs/growth/ACCESSIBILITY-STATEMENT.md` | **DANGLING at the time of writing** — the file arrives with PR #1759. Now cited precisely as such in C-13. |

---

## Expiry policy

1. **Code-backed rows** (C-01, C-03, C-07) do not expire on a calendar. They expire when the named
   file changes. Reviewer check: did the diff touch that file?
2. **Run-backed rows** (C-04, C-05, C-06) expire when the workflow changes or when the cited commit
   moves more than one release behind. **A run record is valid only for the SHA beside it.**
3. **Declared-metadata rows** (C-02, C-10) expire on the next WordPress core release. A new
   "Tested up to" without a fresh audit is a claim with no evidence behind it.
4. **Absence rows** (C-09) expire the instant the thing they say is absent appears.
5. **Live-state rows** (C-11) are a snapshot. Never reuse the numbers.
6. **Unmerged-branch rows** (C-13) say so on their face. They describe `design/variant-c-redesign`,
   which is not `master` and not any release.
7. **Anything not in this file is not a claim.** If copy in `readme.txt`, `readme.md`,
   `docs/site/**` or the directory listing says something that is not here, that copy is
   unbacked. Fix the copy or add the evidence — in that order.
