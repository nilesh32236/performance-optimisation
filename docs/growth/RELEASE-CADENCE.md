# Release cadence: five releases in thirteen days

**Recorded:** 2026-10-02
**Status:** finding only. No version number was changed, no changelog entry was written, no release
was cut. This file exists so the next person does not have to re-derive the dates.

> **Correction, 2026-10-02.** An earlier draft of this file stated that the WordPress.org plugin
> directory "caps a plugin at one release per seven days" and attributed it to a numbered section of
> the guidelines. **That is not a real rule, and the citation was fabricated.** It could not be found
> on any WordPress.org page, and the actual guidelines contain a different rule, quoted verbatim
> below. The fabricated cap has been deleted. The underlying finding — five releases in thirteen days
> is too much churn for a plugin that rewrites `wp-config.php`, `.htaccess` and `advanced-cache.php` —
> does not rest on the cap, and it is unchanged.

---

## The finding

Between 2.0.0 and 2.4.0 the plugin shipped five releases in thirteen days.

| Release | Git tag date | Days since previous |
|---|---|---|
| 2.0.0 | 2026-09-11 | — |
| 2.1.0 | 2026-09-17 | 6 |
| 2.2.0 | 2026-09-18 | 1 |
| 2.3.0 | 2026-09-22 | 4 |
| 2.4.0 | 2026-09-24 | 2 |

Four of the five landed within six days of the one before it. The shortest gap was a single day.

All five exist in the WordPress.org SVN `tags/` directory, so these are published releases, not local
tags.

### Reproduce it

```sh
git tag -l "v2.*" | sort -V | while read t; do
  echo "$t $(git log -1 --format=%cI "$t")"
done
```

```sh
curl -sS "https://plugins.svn.wordpress.org/performance-optimisation/tags/" | grep -o 'href="[^"]*/"'
```

---

## The actual WordPress.org rule

There is a real rule. It is not the one previously written here. It is item 14 of the
[Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/),
quoted verbatim:

> **14. Frequent commits to a plugin should be avoided.**
>
> The SVN repository is a release repository, not a development one. All commits, code or readme
> files, will trigger a regeneration of the zip files associated with the plugin, so only code that
> is ready for deployment (be that a stable release, beta, or RC) should be pushed to SVN. Including a
> descriptive and informative message with each commit is strongly recommended. Frequent 'trash'
> commit messages like 'update' or 'cleanup' makes it hard for others to follow changes. Multiple,
> rapid-fire commits that only tweak minor aspects of the plugin (including the readme) cause undue
> strain on the system and can be seen as gaming Recently Updated lists.
>
> An exception to this is when readme files are updated solely to indicate support of the latest
> release of WordPress.

Retrieved 2026-10-02.

**What that rule says, and what it does not.** It governs the SVN repository as a release channel:
push only deployable code, do not push minor readme tweaks, do not rapid-fire commits that game the
Recently Updated list. It states **no numeric release interval**. A check of that page and of
[The WordPress.org Plugin Directory](https://developer.wordpress.org/plugins/wordpress-org/) found no
seven-day rule, no seven-day window, and no per-week cap anywhere.

So the honest position is this: **five releases in thirteen days is not a violation of a numbered
interval rule, because no such rule exists.** The exposure is narrower and it is still real. Guideline
14 says every SVN commit regenerates the plugin zip. Five releases in thirteen days is five times a
user could install a build that rewrites their `wp-config.php`, `.htaccess` and `advanced-cache.php`.
The interval is not the thing to appeal to; the fact that each one is a full install-triggering
regeneration is.

## Why this still matters

The public readme already says the right thing, and it is worth quoting rather than paraphrasing:
*"The plugin does not ask for a rating or a review to use its features."* The repository is careful
about asking anything of users. Shipping five risky upgrades in a fortnight is the same pressure
applied from the other direction.

### What is verifiable, and what is not

**Verified:** the five tag dates; that all five exist in the directory's `tags/`; the verbatim text of
guideline 14; the absence of any numeric release-interval rule on the two pages checked.

**Not verified:** how many sites were running an older version during that window, and therefore how
much churn any individual user absorbed. `active_installs` reads 0 at 2026-10-02, so the exposed
population was small. That is a reason not to overstate the problem, not a reason to drop it.

---

## Recommendation — for the maintainer, not for this branch

1. **Say it in the changelog.** The next release entry should carry a short, factual note about the
   cadence: what shipped across 2.0.0–2.4.0, and that it was faster than intended. No apology
   theatre, no excuses, one or two lines. A user who hit a regression in that window should be able
   to find out from the plugin that they hit one.
2. **Space releases by judgement, not by a rule that does not exist.** A plugin doing filesystem
   surgery wants a window long enough that a site owner can clone staging, upgrade, and test cache
   regeneration, CDN purge and `.htaccess` state. Two weeks between risky releases is defensible on
   the merits. The point is the reasoning, not an invented cap.
3. **Follow guideline 14 where it does apply.** Do not push minor readme tweaks to SVN outside a
   release. The one documented exception is a readme edit made solely to record support for the
   latest WordPress release.
4. **Bundle before you ship.** The breaking-change list in `changelog.md` for 2.0.0 is long: a removed
   REST route, a removed public method, a removed settings tab, a removed settings key, a changed
   default for native lazy loading, a changed speculation default, and a stricter import/export
   contract. That is a major version's worth of change and it did not get one.
5. **Do not solve this by writing less.** The right response to shipping too often is a longer window
   and a fuller changelog, not a quieter one.

Nothing in this file was acted on. Version numbers, `Stable tag`, and the changelog are untouched.
