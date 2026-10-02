# Release cadence: five releases in thirteen days

**Recorded:** 2026-10-02
**Status:** finding only. No version number was changed, no changelog entry was written, no release
was cut. This file exists so the next person does not have to re-derive the dates.

---

## The finding

Between 2.0.0 and 2.4.0 the plugin shipped five releases in thirteen days. WordPress.org's plugin
directory guidelines cap a plugin at **one release per seven days**.

| Release | Git tag date | Gap from previous |
|---|---|---|
| 2.0.0 | 2026-09-11 | — |
| 2.1.0 | 2026-09-17 | 6 days |
| 2.2.0 | 2026-09-18 | 1 day |
| 2.3.0 | 2026-09-22 | 4 days |
| 2.4.0 | 2026-09-24 | 2 days |

**Four of the five releases landed inside the seven-day window.** Only 2.0.0 → 2.1.0 clears it.

### Reproduce it

```sh
git tag -l "v2.*" | sort -V | while read t; do
  echo "$t $(git log -1 --format=%cI "$t")"
done
```

All five tags exist in the WordPress.org SVN `tags/` directory, so these are real published
releases, not local-only tags:

```sh
curl -sS "https://plugins.svn.wordpress.org/performance-optimisation/tags/" | grep -o 'href="[^"]*/"'
```

---

## Why this is worse than a rule technicality

The directory's release-cadence rule exists because a plugin that rewrites `wp-config.php`,
`.htaccess` and `advanced-cache.php` can break a site on upgrade. Five releases in thirteen days
means a site owner who fell behind by one week had five upgrade decisions, not one — and no time to
test between them.

The public readme already says the right thing, and it is worth quoting rather than paraphrasing:
*"The plugin does not ask for a rating or a review to use its features."* The repository is careful
about asking for anything from users. Shipping five risky upgrades in a fortnight is the same
pressure applied from the other direction.

### What is verifiable, and what is not

Verified: the tag dates above, and that all five exist in the directory's `tags/`.

Not verified: how many sites were actually running an older version during that window, and
therefore how much churn any individual user absorbed. `active_installs` reads 0 at 2026-10-02, so
the population exposed to this was small. That is a reason not to panic, not a reason to skip the
write-up.

---

## Recommendation — for the maintainer, not for this branch

1. **Say it in the changelog.** The next release entry should carry a short, factual note
   acknowledging the cadence: what shipped in the 2.0.0–2.4.0 run, and that it was faster than
   intended. No apology theatre, no excuses, one or two lines. A user who hit a regression in that
   window should be able to find out from the plugin that they hit one.
2. **Extend the gap before the next release.** A plugin at this version, doing filesystem
   surgery, wants a window longer than the seven-day minimum — long enough that a site owner can
   actually clone staging, upgrade, and test cache regeneration, CDN purge and `.htaccess` state.
3. **Bundle before you ship.** The breaking-change list in `changelog.md` for 2.0.0 is long: a
   removed REST route, a removed public method, a removed settings tab, a removed settings key, a
   changed default for native lazy loading, a changed speculation default, and a stricter
   import/export contract. That is a major version's worth of change and it did not get one.
4. **Do not solve this by writing less.** The right response to shipping too often is a longer
   window, not a quieter changelog.

Nothing in this file was acted on. Version numbers, `Stable tag`, and the changelog are untouched.
