# Escalations

Open items that are **not** mine to close, with the evidence that got me there.

---

## D5 — Docs-site publish path: SOLVED, not parked

**Status:** root cause found and reproduced in 6 minutes, well inside the 30-minute cap. Publishing to
post 239 was **not** attempted; that is the orchestrator's call, and it needs the LiteSpeed purge in
item 4 below before it will be visible either way.

### Symptom

`wp post update 239 --content=...` reported `Success` three times. `post_content` stayed
byte-identical; `post_modified` advanced on every attempt.

### Root cause: `--content` is not an argument of `wp post update`

```
wp --allow-root post update --help | sed -n '/SYNOPSIS/,/^## DESCRIPTION/p'

  wp post update <id>... [--post_author=<post_author>] [--post_date=<post_date>]
  [--post_date_gmt=<post_date_gmt>] [--post_content=<post_content>]
  [--post_content_filtered=<post_content_filtered>] [--post_title=<post_title>]
  ...
  [<file>]
```

The supported argument is **`--post_content=`**. There is no `--content`. WP-CLI accepts the unknown
assoc argument, finds no consumer for it, drops it, and calls `wp_update_post()` with an empty
change set — which still bumps `post_modified` and still prints `Success: Updated post 239.`

### Proof

Run against a **throwaway draft**, not against post 239. Draft created, tested, force-deleted;
`SELECT COUNT(*) WHERE ID=661` afterwards returned `0`.

```sh
ID=$(wp --allow-root post create --post_type=docs --post_status=draft \
        --post_title="D5 arg test -- safe to delete" \
        --post_content="ORIGINAL" --porcelain)          # -> 661

wp --allow-root post update $ID --content="MARKER_A"
# Success: Updated post 661.
wp --allow-root db query "SELECT post_content, post_modified FROM wp_posts WHERE ID=661"
#   ORIGINAL   2026-10-05 11:11:39      <-- reported Success, wrote nothing

wp --allow-root post update $ID --post_content="MARKER_B"
# Success: Updated post 661.
wp --allow-root db query "SELECT post_content, post_modified FROM wp_posts WHERE ID=661"
#   MARKER_B   2026-10-05 11:11:42      <-- wrote

wp --allow-root post delete $ID --force                  # Success: Deleted post 661.
```

That is the reported symptom, exactly, and it is the argument name.

### Fix for whoever publishes

Any one of:

- `wp post update 239 --post_content="$(cat bundle.html)"` — **note the underscore**
- `wp post update 239 /path/to/content.html` — the synopsis also accepts a trailing `<file>` argument,
  which avoids shell quoting entirely and is the safer option for multi-kilobyte HTML
- **The intended path**, which nobody appears to be using: `docs/site/**` plus `manifest.json` is a
  bundle, and the theme ships an idempotent importer for it —
  `php wp-content/themes/boltfolio/tools/sync-plugin-docs.php --bundle=DIR --product=performance-optimisation`.
  It matches by product, slug and parent, updates in place, and is the only one of the three that
  keeps the parent/child tree (`reference` → `entry-points` → …) consistent. It is CLI-only by
  design and exits otherwise.

### Everything that was ruled out

| Hypothesis | Test | Result |
|---|---|---|
| Page renders from a field other than `post_content` | `single-docs.php:119` is `the_content()` | **Falsified** — `post_content` is the render source |
| Theme or mu-plugin save filter | `grep -rn "wp_insert_post_data\|content_save_pre\|save_post\|pre_post_update" wp-content/themes/boltfolio/ wp-content/mu-plugins/` | **Falsified** — no such filter. Only `save_post → boltfolio_flush_caches` |
| A plugin rewriting content | same grep across every `wp-content/plugins/`; active set is `duoport-connect-for-opencode`, `performance-optimisation`, `seo-by-rank-math`, `wpforms-lite` | **Falsified** — matches only inside `vendor/`, in Action Scheduler's own scoped add/remove |
| Persistent object cache masking the write | `wp_cache_flush()`, then `wp eval` read **and** a direct `SELECT MD5(post_content)` | **Falsified** — DB row itself is unchanged. `MD5 40d0f9a5…, CHAR_LENGTH 5766` |
| Wrong post serves the URL | `wp eval 'echo url_to_postid(home_url("/docs/performance-optimisation/"))'` | **239, confirmed.** Not a routing problem |
| `wp-config.php` hooking content | `grep -nE "add_filter\|add_action\|WP_CLI" wp-config.php` | **Falsified** — clean |

### Three more things that will bite the publisher, none of which are the bug

**1. The page is served from LiteSpeed's cache, not WordPress.**

```
curl -sS -D- -o /dev/null https://nileshportfolio.duckdns.org/docs/performance-optimisation/
  HTTP/2 200
  server: LiteSpeed
  x-litespeed-cache: hit
```

A successful database write will therefore **not** change the served page until LiteSpeed's cache is
purged. This is a second, independent staleness layer and it is the likely reason a previous run
believed the write had failed. I did **not** purge it — that is a live-site mutation outside this
objective. **Whoever publishes must purge LiteSpeed and then re-check the response headers for
`x-litespeed-cache: miss` before declaring success.**

**2. The `v2.4.0` badge in the page header does not come from `post_content` at all.**

`single-docs.php:64` calls `boltfolio_documented_version()`
(`wp-content/themes/boltfolio/inc/template-tags.php:208`), which reads the `Version:` header **from
the plugin file on disk** and caches the answer in a **24-hour transient**
(`boltfolio_plugin_version_<md5 of the plugin path>`). No amount of editing post 239 will change it.
It clears when the transient expires, or immediately via
`wp transient delete boltfolio_plugin_version_$(printf '%s' /path/performance-optimisation.php | md5sum | cut -d' ' -f1)`.

Worth knowing: on disk the plugin header now reads **`Version: 2.4.1`**, so this badge is already
stale on its own terms.

**3. ID 310 is a real slug collision, but it is not what is breaking the publish.**

Both 239 and 310 carry `post_name = performance-optimisation`. 310 is parented under 263, so its
resolved path is `performance-optimisation/reference/entry-points/performance-optimisation` — deep
inside 239's own subtree. `url_to_postid()` returns 239 unambiguously today. It is still worth
resolving, because a second page holding the same slug anywhere under a shared ancestor will break
the moment either is re-parented.

### Recommended order of operations for the publish

```sh
# 1. write the content (underscore, or use the file form)
wp post update 239 /path/to/new-content.html
# 2. confirm the ROW actually changed — this is the check the old attempts skipped
wp db query "SELECT MD5(post_content), CHAR_LENGTH(post_content), post_modified FROM wp_posts WHERE ID=239"
# 3. purge the LiteSpeed page cache for that URL
# 4. clear the version-badge transient if the badge is in scope
# 5. re-fetch and check the header
curl -sS -D- -o /dev/null https://nileshportfolio.duckdns.org/docs/performance-optimisation/ | grep -i x-litespeed-cache
```

Step 2 is the one that would have caught this on the first attempt.

---

## PR #1756 was closed without merging

`growth/evidenceable-compat-claim` — the readme `= Compatibility =` rewrite with the version-specific,
evidence-backed claims, plus the `docs/site/compatibility.html` corrections — is **CLOSED, never
merged** (closed 2026-10-03T13:00:04Z, `mergedAt: null`). #1757 and #1760 merged; #1756 did not.

Consequence: `docs/growth/claims.md` is on `master` and it records `readme.txt`'s corrected
compatibility wording as shipped, but `readme.txt` does not currently carry the version-specific
Compatibility block. The register and the readme are out of step.

**Nobody should redo this without checking first** — it may have been closed deliberately. If it was
closed in error, the work is recoverable: the branch still exists and the diff is only
`readme.txt` + `docs/site/compatibility.html`.

## Branch coordination — `growth/a11y-and-review-note`

Another writer pushed `38ed2d74` ("Statement: record the measured resolution of F-1..F-4") on top of
my `ac3d40a9` on PR #1759. **No conflict — my commit is an ancestor — but two writers now share that
branch.** Whoever merges it should expect commits from both.

## Out of scope, found anyway

- `changelog.md:48` carries the same withdrawn WCAG AA contrast claim as `readme.txt:298`, verbatim.
  `changelog.md` is outside my write scope so it is **not** fixed. It will keep contradicting
  `readme.txt` until somebody owns it.
- `.audit-prompts/javascript.md:26` says "Color contrast meets WCAG AA for all text on background
  combinations". That is an *audit instruction*, not a user-facing claim, so it is arguably correct as
  a standard to audit against. Flagged, not touched — also outside my write scope.