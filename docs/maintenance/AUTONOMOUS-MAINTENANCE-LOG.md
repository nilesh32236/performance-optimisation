# Autonomous Maintenance Log
Started: 2026-08-31 autonomous run
Branch: master @ 63f3fb2b → 3102ed1c → 35e64fd0
Default branch: master (origin/master)
Completed: 2026-08-31 11:35 UTC

## Inventory Snapshot 2026-08-31 11:00
- Open PRs: 11 (765,762,761,753,752,751,749,748,747,746,744) → After processing: 0 open (8 merged, 2 duplicates closed, 1 supersized closed)
- Open Issues: 14 → After: 11 open (1 WPCS fixed #766, 1 supersized PR closed #765, 5 WP Monitor deferred, 6 marketing/design deferred)
- After sync: master 63f3fb2b, fix/audit-2026-08-28 pushed 661a3a7c
- After 8 merges: master 3102ed1c (744,747,748,753,761,746,751,749)
- After fix/wpcs-766: master 35e64fd0

## Processing Queue & Results
| # | PR | Title | Result | Commit |
|---|---|-------|--------|--------|
|744|Inspector RedisSentinel|canonical strict types + validation|MERGED|34a37999|
|752|Inspector RedisSentinel duplicate|duplicate of 744|CLOSED duplicate||
|762|Inspector QA duplicate|duplicate of 744|CLOSED duplicate||
|747|Sentinel SQLi HIGH|optimize_table regex guard|MERGED|84b60f26|
|748|Warden metabox nonce|nonce guard refactor|MERGED|2ef42ac4|
|753|Warden sensitive settings|DRY helper|MERGED|c4260af2|
|761|Bolt minify regex|remove regex in CSS minifier|MERGED|d96bc5fb|
|746|Autofix code-quality 9 important|SCSS tokens, BEM, hook hygiene|MERGED|d2ba94e6|
|751|Autofix performance 1 critical|revision flush, transients, cron|MERGED|7cf84421|
|749|Palette aria EC|aria-describedby EdgeCache|REBASED+MERGED|3102ed1c (5015a9c4)|
|765|Fix/audit 212 files|supersized, conflicting|CLOSED supersized, branch preserved|fix/audit-2026-08-28|
|767|Fix WPCS 766|31 WPCS + AutoloadedOptions|MERGED|35e64fd0 (d948d87e)|

## Issue Handling
| Issue | Title | Decision | PR |
|-------|-------|----------|----|
|766|Code Quality 31 WPCS|FIXED|767|
|763|Code Quality 1 inline comment|CLOSED (fixed earlier, superseded by 766)|-|
|750|Audit performance 1 critical|FIXED via 751|751|
|745|Audit code-quality 9 important|FIXED via 746|746|
|754|WP Monitor speculation rules|DEFERRED to next sprint (needs WP 6.9+ testing)|-|
|755|WP Monitor fetchpriority|DEFERRED|-| 
|756|WP Monitor cache group guard|DEFERRED|-|
|757|WP Monitor lazy/auto-sizes|DEFERRED|-|
|758|WP Monitor Abilities API|DEFERRED|-|
|709|Design chooser 3 proposals|DEFERRED (needs product decision)|-|
|708|LS-904 WP 7.x readiness|DEFERRED|-| 
|707|LS-903 N-features|DEFERRED|-|
|646|v2.0.0 meta|DEFERRED|-|
|369|Banner/icon|DEFERRED|-|
|368|Screenshots|DEFERRED|-|

## Quality Gate 2026-08-31 11:32-11:35
- vendor/bin/phpcs: 0 errors, 0 warnings (was 31) → PASS
- npm run lint:js: 0 errors, 5 warnings (react-hooks exhaustive-deps, pre-existing) → PASS
- npm test: 34/34, 345 tests (was 33/34) → PASS
- vendor/bin/phpunit: 435/435, 1021 assertions, 2 skipped → PASS
- npm run build: webpack 5.109.2 success → PASS
- php -l: ok → PASS
- CI: 767 all checks pass (JS Lint, WPCS & Psalm, AI Review, syntax 8.2-8.5, Snyk, CodeRabbit) → PASS

## Conflict Resolutions
- 749: 5-file conflict (.jules/palette.md + builds) → cherry-picked EdgeCachePanel.js, merged palette.md chronologically (08-29 + 08-31), rebuilt via wp-scripts, kept maps from HEAD
- 765: 212-file conflict → closed supersized, branch preserved for split (<100 files each)

## Remaining Work
- 5 WP Monitor enhancements → 5 focused PRs (<100 files, @since 2.0.0, guarded by function_exists)
- 6 marketing/design → require product/design input
- Fix/audit branch split → 5 PRs (CLI, Hooks, Perf, H-fixes, Option B redesign pending #709)


### 2026-08-31 14:30 UTC — E1 CLI Batch (fix/cli-e1 → 7eb05beb, PR #775)
- Master  e5b824c6 → 7eb05beb (39e52805 E1)
- Branch  fix/audit-2026-08-28 (661a3a7c) E1 extracted: 3 files (class-wppo-cli-command, class-util, class-object-cache) 531 ins vs 212-file supersized
- PR #775: synopsis [<action>] defaults, --format=json (counts), --yes (type=all tty + WP_CLI::confirm), --dry-run (would_delete/would_optimize JSON), allowlist TABLE_MAP, extra types trashed_comments/unattached_media/oembed_cache, hook wppo_database_cleanup_completed per-type, Util::get_default_settings single source + blog-keyed cache (switch_blog), ALLOWED_KEYS 12 + wppo_object_cache_config filter
- Tests: phpcs 0, phpunit 435/435, npm 34/34, build success, manual dry-run/counts/yes/help verified
- CI: JS 55s/59s pass, PHP 4× pass, WPCS 1m1s pass, Psalm 3s pass, Snyk pass, CodeRabbit pass, AI fail non-blocking (opencode-go unknown) → MERGED 7eb05beb
- Real WP: nileshportfolio.duckdns.org WP 7.1 PHP 8.3 LiteSpeed hit, wp wppo database counts --format=json 9 keys, dry-run preview, cache status


## 2026-09-25 — Settings persistence false-success
- Branch: `fix/settings-save-result-handling`, cut from `master` @ `efdb2019`. The #1563 security branch and the edge-purge branch are untouched.
- Root cause: `Settings_Store::save_settings()` returned `(bool) update_option(...)`, and `update_option()` returns false both for an unchanged value and for a failed write. Callers therefore could not tell "already persisted" from "not persisted", so `Rest_Settings::update_settings()` answered 200 with the merged array, the REST safe-mode handler reported success, and both `wp wppo settings` paths printed "successfully" regardless. Because the SPA commits its local settings on a success response, the admin showed a value that the database never held until the next page load reverted it.
- Fix is at the seam, not at four call sites: the pre-write value is captured from the memoized accessor — the same disambiguation `restore_settings_snapshot()` already used — so a false whose pre-write state equals the requested state is a success and a differing one is a failure. The memo is refreshed on both branches, so same-request reads never describe a state the database does not hold. `Rest_Settings::update_settings()`, the REST safe-mode handler and both CLI paths now fail closed on a genuine write failure.
- Why not a naive `if ( ! save() )` at the callers: `update_option()` returns false for an identical value too, so that would have rejected idempotent re-saves with false 500s. `SettingsCommandTest::test_unchanged_save_is_reported_as_success` pins the distinction and was confirmed to fail with the seam change reverted.
- Regression, each mutation-checked: the REST 500 and the unchanged-save success both fail with their fix reverted and pass with it. `test_failed_write_leaves_the_memo_on_the_stored_value` pins memo coherence on the failure path. The pre-existing `test_snapshot_failure_does_not_block_write` contract is unchanged and still passes.
- Gate: PHPCS, ESLint (0 errors), 833 Jest, 2,786 PHPUnit / 25,816 assertions, build, architecture check, diff check — all green.
- Scope boundary: the CLI snapshot churn (an unconditional `take_settings_snapshot()` on a no-op write destroying one-click undo) and the dead `Settings_Command` branch in the safe-mode handler are separate defects and were not bundled here.

## 2026-09-25 — WP-CLI settings writes through the Settings_Command seam
- Branch: `fix/cli-settings-undo-snapshot`. It was originally stacked on the
  settings write-result fix because the CLI now checks the write result, and
  without that seam an unchanged write would return false and make the CLI
  report an error for a no-op update. That dependency has since been satisfied:
  the settings fix merged to `master` as #1652 and this branch was rebased onto
  it, so it now carries only the CLI change.
- Defect: `settings import` and `settings update` hand-rolled
  `take_settings_snapshot()` before every write, with no "did anything change"
  guard. `take_settings_snapshot()` writes a single slot, so
  `wp wppo settings update file_optimisation --settings='{"minifyHTML":true}'`
  with that value already set rewrote the undo slot with the current state. The
  next "Undo settings" restored the settings already in place, and the operator's
  last real change became unrecoverable. `Settings_Command::save()` has exactly
  the `$prior !== $settings` guard these two paths lacked.
- Fix: both branches now call `Settings_Command::save( $next, $prior )`, which
  also removes the duplicated fail-open snapshot try/catch.
- Regression: new `tests/php/WppoCliSettingsTest.php` — the `wp wppo settings`
  subcommand previously had **no test file at all**, and the two highest-impact
  defects in it lived in that untested code. Three tests, all mutation-checked
  against the reverted source: a no-op write preserves the undo snapshot, a real
  change still snapshots the prior value, and a failed write is reported as an
  error rather than a success.
- Invariant: `MainSettingsOwnershipTest::test_cli_settings_writes_go_through_the_settings_command`
  now pins that the CLI has no raw `Util::save_settings` /
  `Util::take_settings_snapshot` call sites and exactly two
  `Settings_Command::save` sites, so the hand-rolled path cannot come back.
- Architecture: the new dependency edge `WPPO_CLI_Command -> Settings_Command`
  is recorded in the regenerated graph, and the `ArchitectureInventoryTest`
  edge ratchet moved 395 -> 396 (runtime 394 -> 395) with the reason noted in
  the assertion.
- Gate: PHPCS, ESLint (0 errors), 833 Jest, 2,790 PHPUnit / 25,830 assertions,
  build, architecture check, diff check — all green.
- Scope boundary: the CLI snapshots the defaults-merged prior state rather than
  the raw stored array. That is pre-existing behaviour, left alone here; the new
  test asserts the changed key rather than baking the wart in.

## 2026-09-25 — Redis Sentinel mode could not work on any supported phpredis
- Branch: `fix/redis-sentinel-constructor`, cut from `master` @ `efdb2019`.
- Defect: `wppo_redis_connect_sentinel()` refuses to run on phpredis < 6.0
  (`redis-connect-helper.php:162`) and then constructed `RedisSentinel` with the
  **phpredis 5.x positional signature** (six arguments). On the installed
  phpredis 6.3.0 that raises `ArgumentCountError: RedisSentinel::__construct()
  expects at most 1 argument, 6 given` — verified by Reflection and by direct
  reproduction on this host. The `catch ( \Throwable )` converted it into
  "Sentinel node connection failed." and finally into a `sentinel_fail` error.
- Amplification: `Object_Cache::is_transient_outage_error()` classified
  `sentinel_fail` as **transient**, so a permanent misconfiguration armed the
  persistent `outage_bypassed` flag, parked the drop-in and counted toward the
  5-failure circuit breaker. Sentinel mode therefore did not work at all, and a
  site configured for it was driven permanently uncached with no self-heal.
- Fix: construct with the phpredis 6.x options array. The removed `$retry`
  variable was only ever the value 0, which the array now carries as
  `retryInterval`.
- Regression: `tests/php/RedisSentinelConstructorTest.php` asserts the
  constructor shape against the source, re-asserts that the 6.0 version gate is
  still present (it is what makes the options array the only reachable form),
  and constructs the real `RedisSentinel` with the exact options the helper
  passes, so a future phpredis API change fails here rather than in production.
  Mutation-checked: restoring the positional constructor fails the suite.
- Scope boundary: `sentinel_fail` remains classified as transient on purpose.
  After this fix it means the configured Sentinel nodes were genuinely
  unreachable, which is a real outage the outage flag is designed to cover.
  Reclassifying it would trade away fast-path resilience for no verified gain.
- Gate: PHPCS, ESLint (0 errors), 833 Jest, 2,787 PHPUnit / 25,819 assertions,
  build, architecture check, diff check — all green.

## 2026-09-25 — Root cause of the Sentinel defect: PR #744
- The broken constructor did not come from an untested corner. PR #744
  (`google-labs-jules[bot]`, merged 2026-08-31) introduced it, and its body
  states the exact opposite of the truth: *"the phpredis extension strictly
  requires positional arguments for the RedisSentinel constructor"*. It
  refactored a working options array into the positional form.
- On the installed phpredis 6.3.0 the positional form raises
  `ArgumentCountError: RedisSentinel::__construct() expects at most 1 argument,
  6 given`, and the same PR's own version gate requires >= 6.0.
- The merge landed on `includes/redis-connect-helper.php`, a path that was later
  moved to `includes/Support/`, so the broken call travelled with it.
- The remote branch `fix/inspector-redis-sentinel-constructor-217604302318816716`
  (`33228349`) is an orphan with no open PR. It targets the pre-move path and its
  diff converts the working options array back into the broken positional form.
  **It must not be merged.** Recorded in the ledger as DO-NOT-MERGE.
- Systemic note: the claim was falsifiable with a single `ReflectionMethod` call
  against the installed extension, and the repository's 95%-confidence merge gate
  accepted it. Any future check of an extension API should assert the installed
  version's actual signature rather than reasoning from documentation — which is
  exactly what `RedisSentinelConstructorTest::test_options_array_is_accepted_by_the_installed_phpredis()` now does.

## 2026-09-26 — Contract tests for the three untested numeric normalizers
- A re-audit of issue #1628 found its "critical — silent UI/server divergence" claim **not reproducible**: all three JS copies agree with the PHP sanitizers across 34 inputs. The real exposure was coverage, not behaviour — of the four sibling normalizers only `normalizeRetries` had a direct test.
- Test-only change: 136 table-driven cases for `normalizeIdleTimeout`, `normalizeCcssMaxSize` and `unusedCSSRegressionThreshold`. No production file touched. `coerceLongestEdge` is deliberately left to the #1658 branch, which is already changing `ImageOptimization.js`.
- Mutation-verified (12/12 killed): both fallback values, both band edges in each direction, the `n <= 0` guards, `Math.trunc -> Math.round`, adding a 1 MB ceiling **and a 256 KB ceiling** to `normalizeCcssMaxSize`, and the hex/octal/binary-string and boolean/array guards inside the shared `parseGuardedNumber` core. The first no-clamp table was too weak — it only caught clamps above 1 MB — so 100 KB / 256 KB / 1048575 rows were added and re-verified.
- The deliberately NOT-done part matters: the proposed `parseGuardedInt(value,{min,max,fallback})` extraction cannot express `normalizeCcssMaxSize`'s intentional *absence* of an upper clamp without a sentinel, and `Settings_Store::sanitize_scalar_setting()` does not clamp it either, so a max would change the values written to `wppo_settings` and break the current settings schema.
- Two behaviours the tests now lock in, both confirmed true against PHP: the hex guard tests the *string* form, so the number literal `0x10` is accepted as 16 while `'0x10'` fails open; and only `unusedCSSRegressionThreshold` has a server-side band at all — `ccssMaxSize` and `delayJSIdleTimeout` fall through to `sanitize_scalar_setting`, which stores any numeric value verbatim.
- Gate: lint 0 errors, Jest 59 suites / 969 tests (+136 over master's 833). No PHP changed, so PHPCS/PHPUnit are not applicable.

## 2026-09-25 — Edge purge false-success (fail path only)
- Branch: `fix/edge-purge-false-success`, cut from `master` @ `efdb2019`. The #1563 security branch is untouched and still at its human gate; GitHub writes remain approval-gated, so no PR was created.
- Finding: a fresh lane reported the edge purge lock as "set but never released". Re-reading showed that framing was wrong — the 60s transient is a deliberate coalescing throttle (`EdgeCacheTest::test_purge_lock_is_transient_key_and_blocks_duplicate`, and the identical documented intent in the LiteSpeed twin). Unlocking unconditionally would silently re-enable double purges.
- Real defect, executed before fixing: the lock is armed *before* the fan-out, so a purge that reached the provider and failed leaves the window armed, and every retry inside the TTL returned `true` without contacting the edge. A scratch harness (2 tests / 11 assertions) proved the false success and, as a control, that a successful purge must keep its window.
- Fix: `Edge_Purger::release_purge_lock_on_failure()` releases the window only when the fan-out reports failure, on both the full and single-page paths. Successful purges keep the existing window unchanged, and only the request that armed the lock can reach the release.
- Regression: `EdgeCacheTest::test_retry_after_failed_purge_reaches_the_edge` and `test_single_page_purge_releases_lock_only_on_failure`; the transport stub is now driven by a per-test `$http_code`. Both new tests were confirmed to FAIL with the fix reverted and pass with it; the pre-existing coalescing test is unchanged and still passes.
- Side effect handled: `EdgePurgeCoordinatorTest` mocks the transient API by name, so the new `delete_transient` call needed a stub; the harness was extended rather than weakening the production guard.
- Architecture: the new private method changes the generated inventory, so `docs/architecture/class-inventory.json` and `DEPENDENCY-GRAPH.json` were regenerated as the project rules require.
- Gate: PHPCS, ESLint (0 errors), 833 Jest, 2,785 PHPUnit / 25,818 assertions, build, architecture check, diff check all green.
- Scope boundary: the LiteSpeed twin cannot receive the same fix — its sync methods return `void` with no failure signal, so "release on failure" is not expressible there and a design change is required. Recorded as a follow-up, not silently bundled.

## 2026-09-25 — Uninstall option leak closed, and the class of leak guarded
- Branch: `fix/uninstall-option-leak`, cut from `master` @ `efdb2019`. Independent
  of the other branches.
- Leak: `wppo_esi_fallback_secret` is a real 64-character secret written with
  `add_option()` / `update_option()` by the LiteSpeed ESI bridge when `wp_salt()`
  is unavailable. It was absent from `Util::UNINSTALL_OPTIONS` and from the
  inline `uninstall.php` list, so it survived a full "delete all plugin data"
  uninstall and would be reused by a later reinstall.
- Re-derived independently rather than trusting the earlier report: a source
  scan of every `wppo_` option literal and class constant under `includes/`,
  classified by which WordPress API it reaches, showed exactly one true
  persistent-option leak. The other 32 names that are also missing are
  transient-backed and removed by the bulk transient sweep, and
  `wppo_front_page_lcp_` is a documented dynamic prefix swept by an explicit
  LIKE query — so a blanket "everything must be in the list" rule would have
  produced 33 false positives and would have been disabled.
- Fix: the option is added to `Util::UNINSTALL_OPTIONS` and to the inline list
  in `uninstall.php`, and `UninstallOptionsTest::$expected_options` — which
  deliberately ratchets the exact list — is updated with the reason.
- New guard, `tests/php/UninstallOptionLeakTest.php`, closes the class of leak
  rather than only this instance. It reads the source instead of the database,
  so it sees options no local install has ever created — which is exactly why
  `wp wppo verify` (database-driven) and the sync test both passed while this
  secret survived. It separates persistent options from transient-backed keys
  and the documented dynamic prefix, and both directions are mutation-checked:
  removing the entry fails the guard and names the option and its source lines.
- Gate: PHPCS, ESLint (0 errors), 833 Jest, 2,786 PHPUnit / 25,816 assertions,
  build, architecture check, diff check — all green.

## 2026-09-26 — Two test defects in the option-leak guard (adversarial review)
- **Vacuous assertion.** `test_dynamic_option_prefixes_are_swept_on_uninstall` asserted a bare substring over the whole of `uninstall.php`, so deleting the real `esc_like( 'wppo_front_page_lcp_' ) . '%'` sweep while leaving the comment that names the prefix kept it green. It now asserts the executable statement via a regex over `esc_like( ... ) . '%'`. Re-mutation-checked: deleting the sweep and leaving the comment now fails. (A pre-existing test killed that mutation too, so this was a dead test rather than dead coverage — but it claimed to check something it did not.)
- **Overstated contract.** The scan does not match the `update_option( self::CONST, ... )` call form; the constant branch only recognises a bare identifier. That form is used at 56 option-API call sites in this repository versus 40 literal ones, so the docblock's claim that it "sees every option the plugin can ever write" was false for the dominant style. The docblock now states the limit explicitly instead of overclaiming; closing the gap is a follow-up.
- **Phantom reference.** Two comments cited `LiteSpeed_ESI::FALLBACK_SECRET_OPTION`. That class declares no constants at all — the real call sites are bare string literals at `includes/Integrations/class-litespeed-esi.php:373-398`. Corrected to cite the file:line.

## 2026-09-25 — Release ZIP no longer ships composer dev packages
- Branch: `fix/distignore-dev-packages`, cut from `master` @ `efdb2019`.
- Gap: the release workflow installs production dependencies with
  `composer install --no-dev`, so CI never exercises the local
  `scripts/build-release.sh` path, which stages whatever the working tree
  contains. `.distignore` lists dev vendor directories by hand and three
  packages added to `require-dev` afterwards were missing:
  `phpstan/phpstan`, `szepeviktor/phpstan-wordpress` and its transitive
  `php-stubs/wordpress-stubs`.
- Verified by execution, not by reading: an `rsync` staging dry run matching
  build-release.sh staged 532 entries, 78 of them from those three dev packages
  (including `wordpress-stubs.php` and a third-party LICENSE), plus the tracked
  internal files `wppo-agent-rules.md` and `empty_commit.sh`. After the fix the
  same dry run stages 436 entries with 0 dev-package and 0 internal leaks.
- Guard: `DistignoreDevPackageTest` derives the required vendor directories from
  `composer.lock` `packages-dev` rather than restating them, so adding a dev
  dependency without a matching `.distignore` entry fails the build. A package
  present in both `packages` and `packages-dev` is treated as a real runtime
  dependency and is allowed to ship. Both directions are mutation-checked:
  removing the `/vendor/phpstan` line, or the `/wppo-agent-rules.md` line, fails
  the suite.
- Gate: PHPCS, ESLint (0 errors), 833 Jest, 2,785 PHPUnit / 25,813 assertions,
  build, architecture check, diff check — all green.

## 2026-09-26 — The .distignore guard was one-directional (adversarial review)
- An independent review appended `/vendor/voku/` — a **production** dependency, `use`d at `includes/minify/class-html.php:17` — to `.distignore` and both tests stayed green. A release-breaking ZIP whose `vendor/autoload.php` fatals on the minify feature would have shipped with a fully passing suite, and nothing else in the suite catches it either.
- Added `test_no_production_package_is_excluded_from_the_release`, deriving the production set from `composer.lock` `packages` and failing for any whose vendor directory is excluded. Re-mutation-checked: appending `/vendor/voku/` now fails, naming `voku/simple_html_dom`.
- The guard is now bidirectional: dev -> excluded, and production -> not excluded.

## 2026-09-25 — Muse Spark model authority enforced in CI
- Branch: `fix/enforce-muse-spark-authority`, cut from `master` @ `efdb2019`.
  Independent of the other branches; the new `model-authority` job is appended at
  the end of `webpack.yml` precisely so it does not collide with the #1563
  security branch's edits to the same file.
- Gap: `model-intelligence/check-config.mjs` is the only executable proof that
  the four model-bearing workflows and `AGENTS.md` still carry
  `vars.OPENCODE_MODEL || 'opencode/muse-spark-1.3-contributor-free'`, that no
  other `opencode/<model>` literal has appeared, and that `champion.json` agrees
  with the registry champion. It passed when run by hand and **had no caller at
  all** — no package script, no workflow, no test. Worse, the test named
  "configuration keeps Muse Spark as the sole fallback reference" only asserted
  three `champion.json` fields and never ran the guard, so the objective's
  explicit-model-authority guarantee existed as prose.
- Fix: `npm run model:check` script; a dedicated `model-authority` job in
  `webpack.yml` (PR and push gate, no dependency install) and a step in the
  `discover` job of `model-intelligence.yml` before a refreshed registry is
  committed, since that job owns the registry data.
- Regression, in `model-intelligence/tests/safety.test.mjs`: the guard is now
  actually executed and its result asserted, and a further test pins that the
  guard is still wired into `package.json` and `webpack.yml` — so removing the
  CI step fails the suite again. Both were mutation-checked: deleting the
  `model-authority` job fails the wiring test, and hardcoding
  `opencode/some-other-model` into `wppo-ai-review.yml` makes the guard exit 1.
  The overstated test name was replaced by one that describes what it asserts.
- Documentation: `AGENTS.md` now records the invariant, both call sites, and the
  requirement that a new model-bearing workflow be added to
  `fallback-locations.json` to stay covered.
- No production model, provider, or routing change: the champion stays
  `opencode/muse-spark-1.3-contributor-free` and explicit `OPENCODE_MODEL`
  selection stays authoritative.
- Gate: model:check, 13 model-intelligence tests, benchmark 11/11, doc:check,
  typecheck, YAML parse, PHPCS, ESLint (0 errors), 833 Jest, 2,783 PHPUnit /
  25,808 assertions, build, architecture check, diff check — all green.

## 2026-09-26 — Hardening the model authority guard against neutering
- An independent adversarial review of `fix/enforce-muse-spark-authority` found two ways to disable the guard while every existing test stayed green. Both are now closed and both closures are mutation-verified.
- **Vector 1 — rewrite `check-config.mjs` to always report `ok`.** Only the *wiring* was pinned (the package script, and a grep of `webpack.yml`), never the script's content. Added a test that runs the **real** guard against a temporary fixture with a planted `opencode/attacker-model` literal, and asserts it exits non-zero *and that the diagnostic names the planted model* — so an unrelated crash cannot masquerade as a detection. Gutting the guard now fails that test.
- **Vector 2 — drop a covered workflow from `fallback-locations.json`.** A workflow could be removed from the scan and its model drift would be invisible. Added a test that walks every `.github/workflows/*.yml`, and fails for any file holding an `opencode/<model>` literal that the manifest does not list.
- The same review found a live coverage gap of exactly that kind: `model-intelligence.yml:84` (`refs=(opencode/muse-spark-1.3-contributor-free)`) and `webpack.yml:144` both hold real literals but were **not** in the manifest, so drift in them was invisible. Both are now covered; the guard's occurrence count moved 19 -> 21.
- Mutation results, all observed failing: neutering the guard script -> 2 fail; dropping `wppo-ai-review.yml` from the manifest -> 2 fail. Restored -> 15/15 pass.
- **Residual risk, recorded rather than dismissed:** `master` has no branch protection, so every check this adds is advisory. A PR that edits the guard, the manifest, and the tests together can still disable it. That is a repository-settings matter, not a code one, and is left for a human.

## 2026-09-26 — ImageOptimization save lifecycle, and a vacuous test found by adversarial review
- Branch: `fix/image-optimisation-save-lifecycle`. `ImageOptimization.js` was the one settings tab whose two `update_settings` paths had neither a mounted guard nor an AbortController; `ObjectCache.js` and `PreloadSettings.js` both had one (audit #1420).
- Fix: both paths create an AbortController, pass the signal, and return early after the await and in the catch when aborted or unmounted; both `finally` blocks are mount-guarded; one effect flips `isMountedRef` and aborts both controllers.
- Deliberately NOT done: routing these through `useSaveSettings`, which would change user-visible notice behaviour (it hardcodes `durationMs: 3000` and a generic message against these sites' `5000` and `res.message`).
- **The first version of these tests was vacuous.** They asserted on `console.error`, but React 18 removed the unmounted-setState warning, so deleting both guards still passed. An independent adversarial review then found a *second* vacuous case: `onSubmit`'s catch-block guard was pinned by nothing, because the test named "(failure path)" actually resolved `{success:false}`, which returns through the post-await guard and never enters the catch. The LCP twin of that guard was tested twice; the save one was not. A reject-path twin was added and the misleading name changed.
- All four guards are now mutation-verified: deleting the post-await guard from either path, the abort cleanup, or the catch guard from either path each fails the suite.
- The double-submit case pins pre-existing behaviour (the `isLoading` guard and button disabling already on master), not this change. Noted as characterisation, not regression coverage.
- Gate: lint 0 errors, Jest 59 suites / 842 tests, build, committed bundle regenerated. No PHP changed, so PHPCS/PHPUnit are not applicable.

## 2026-09-26 — Safe mode: its scope is now stated, and pinned
- An audit ranked "safe mode does not stop what users assume it stops" as the highest-risk feature interaction. Re-derived from source rather than taken on trust: `build_safe_mode_enable_payload()` (`includes/Core/class-main.php:5529`) sets exactly one key, `file_optimisation.safeMode`, and only **five** subsystems consult `is_safe_mode_active()` — Delay and Defer (`class-hook-registry.php:158`), Combine CSS (`class-css-combine.php:279,1139`), Used CSS (`class-used-css.php:4787`) and Critical CSS (`class-critical-css.php:5523`), plus a third `class-script-strategy.php` guard.
- **Not** consulted by minification (`Cache::minify_buffer()` checks only `should_bypass_for_litespeed()`), image optimisation, CDN rewriting, preload, Google Fonts or speculation rules. And `handle_safe_mode` then **purges the page cache**, so a still-broken page is re-cached with all of those still applied.
- The existing UI copy was **accurate but incomplete** — it named the four paused features correctly and did not claim more. So this is not a false claim. The defect is that a user whose breakage came from minification, images or the CDN gets no signal about why safe mode did not help.
- Fix: the description and the warning banner now state that minification, image optimisation, CDN rewriting and preload keep running and must be disabled individually. Zero behavioural change, so zero compatibility risk.
- `SafeModeScopeTest` pins the scope as a *characterization* test: it scans every plugin PHP file for consumers of the kill switch and fails if that set changes, asserts the ungated subsystems stay ungated, and asserts the UI says so. Without it the scope would drift silently and the copy would rot.
- Mutation-verified: making `minify_buffer()` consult safe mode fails with a message naming the copy that must change; reverting the UI text fails.
- **Deliberately not done:** extending the kill switch to minification. That is a real product decision with a real behavioural change for anyone already in safe mode, so it is raised for the maintainer rather than taken unilaterally. The honest options are to gate minification too, or to keep the narrow scope and the explicit wording above.
- Gate: PHPCS 0, ESLint 0 errors, Jest 60 suites / 1,072 tests, PHPUnit 2,808 / 25,972, build, architecture `--check`, `git diff --check` — all green.

## 2026-09-26 — Documented the frontend interaction traps that were written down nowhere
- A feature-by-feature audit found the single most consequential fact in the plugin undocumented: `Cache::process_buffer_only()` (`includes/Cache/class-cache.php:2614-2685`) decides the entire frontend output order, the cached artefact is the **fully processed** buffer, and the `advanced-cache.php` drop-in `exit`s before WordPress boots — so **no plugin filter runs on a cache hit**.
- Two high-severity documentation defects, both verified against source rather than taken from the report:
  - **`wppo_inline_combined_css` is the sole gate for Critical CSS.** `Critical_CSS::is_inline_allowed()` is nothing but `apply_filters( 'wppo_inline_combined_css', true )`, and `is_ccss_effective()` is `is_inline_allowed() && ! is_deferral_suspended_by_js()`. `AGENTS.md` described the filter only as "disable inlining of the combined/minified CSS — e.g. when using a CDN", so a user following that recipe silently **loses Critical CSS entirely**. The entry now says so, and names the gap: the filter cannot express "inlining off, Critical CSS on".
  - **Enabling defer or delay silently disables Critical CSS**, because `is_deferral_suspended_by_js()` is exactly `deferJS || delayJS` while the settings screen keeps showing Critical CSS as enabled. This was stated nowhere.
- New "Frontend transformation order and its traps" section records the order itself plus six consequences: the cache-hit dead zone, the Critical CSS interaction, the safe-mode scope, CDN URLs being baked into the cache, bfcache being inert on a hit, defer and delay not being mutually exclusive, and the domain-mapped-multisite fail-open (which `AGENTS.md` had called "inherently multisite-safe" — the code itself calls it a "Known tradeoff").
- Docs only. `git diff --check` exit 0, `npm run doc:check` -> `{"ok":true,"files":4}`.

