# 765 Duplicate Verification — Most Important Output

**Master:** `2c5b1dd6`, **Audit:** `661a3a7c`, **Merge-base:** `31fffc61`
**Question:** "Has the functionality, behavior, protection, performance improvement, test coverage, or developer capability genuinely landed in current master?" vs "Does code look similar?"

## Genuinely Duplicates (Safe to Ignore Cherry-Pick)

| Old Change | Master Evidence | Verdict |
|------------|----------------|---------|
| CLI synopsis/JSON/yes/dry-run/allowlist/9 types (d306e677,45ed2f79) | **PR #775 (39e52805, 7eb05beb)** — `git log -S "--dry-run" --all` → both, `git blame master -- class-wppo-cli-command.php:203` → 39e52805, `wp wppo database --help` shows 9 types + yes/dry-run/json, `git diff master...audit -- class-wppo-cli-command.php` still delta but same feature superset (master adds 12 ALLOWED_KEYS vs audit 6) | **Genuinely duplicate — evolved superset, safe to ignore audit cherry-pick** |
| Util `get_default_settings` + blog-keyed `get_settings` | **PR #775 (39e52805)** — `grep get_default_settings master` → 92 present, `git log -S get_default_settings` → d306e677 & 39e52805 | **Genuinely duplicate** |
| ObjectCache `ALLOWED_KEYS 12` + `get_redis_config` + `wppo_object_cache_config` | **PR #775 (39e52805)** — `grep ALLOWED_KEYS master` → 50, `git blame` → 39e52805 | **Genuinely duplicate** |
| `is_safe_mode` `?wppo_safe=1` 600s cookie (7fbfc8d8:989) | **Convergent duplicate** — `git blame master -- class-util.php:989` → 39e52805, `git blame audit -- class-util.php:989` → 7fbfc8d8 — **identical 38 lines**, different commits, different PRs (#775 vs audit) | **Genuinely duplicate (different commits, same content)** |
| `wppo_database_cleanup_completed` hook name | **Partially duplicate** — `grep` master → `all` at 774 + CLI per-type at 388 (39e52805), audit has per-type in DB class as well; hook name same, location partially | **Genuinely duplicate for hook name, but location partially missing (see below)** |

## Only Superficially Similar (Not Genuine Duplicates — Would Be Wrong to Classify as Duplicate)

| Old Change | Master Similar | Why Not Genuine Duplicate |
|------------|----------------|---------------------------|
| `Util::get_settings` blog-keyed memo (b84a07d6 vs b2425ed2/39e52805) | Both memoize, but master expanded to `ALLOWED_KEYS`/blog isolation differently; `git diff master...audit -- class-util.php` shows divergent `ensure_settings_cache_hook` + `switch_blog` | **Different mechanism, merging both risks conflict — superficial only** |
| `is_safe_mode` surrounding gates (Cache 6 safe-mode returns in audit vs 1 in master) | Audit adds 6 early returns (`Cache:1219,1244,1292,1312,1507` / `Main:543`), master only via CLI E1 partial | **Similar intent, different breadth — superficial** |
| Palette / AutoloadedOptions / WPCS (746/767 vs audit P5) | Same files touched for different reasons (xs breakpoint vs WPCS alignment, 31 vs sidebar transform) | **Superficial — same file, different semantics** |
| `wppo_database_cleanup_completed` per-type | Master has CLI per-type (388), audit has DB class per-type (737) — different call site | **Superficial — same hook name, different execution context, need both** |

## Incorrectly Classified as Duplicates (If Previous Reports Said So)
- **SRC_STAT LRU** (`Cache:128` LRU 500) — previous extraction map lumped as ALREADY via 751, but `git diff master...audit -- class-cache.php:128` shows **master missing LRU** — **incorrectly classified, actually PARTIALLY STILL NEEDED**
- **H-0x fixes** — previously grouped as duplicates of 746/751 — **incorrect, actually STILL NEEDED** (master still has `count===5`, dead branch, etc.)
- **is_safe_mode** — previously STILL NEEDED — now proven **ALREADY IMPLEMENTED** (convergent) — **incorrectly classified before, now corrected**

## Still Missing (Not Duplicates — Would Be Lost if Audit Deleted)
- **C-01** namespace typo, **H-01** iframe routing, **H-02/03/04/07** bfcache dead branch, **H-10** AbortController, **P4** cloudflare purger, **E5** UX (except is_safe_mode), **12 tests**, **wppo_should_cache_request** + **wppo_invalidation_urls** (only on `fix/hooks-e2` ddac08ec, not master), **per-type cleanup in DB class**, **SRC_STAT LRU**, **P3 uninstall wildcard**

## Proof Method
- `git show <branch>:<file>` byte comparison, `git blame <branch> -- <file>`, `git log -S <string> --all`, `git diff --stat`, `gh pr list` 0/1, `grep wppo_` filtered, `phpcs`/`phpunit` baseline (435/435)

**Conclusion:** 5 changes are **genuinely duplicate** (safe to ignore), 4 are **superficially similar** (not duplicate), 10 are **still missing** (must not be dismissed), 3 were **previously misclassified** and now corrected.
