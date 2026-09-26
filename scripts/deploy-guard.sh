#!/usr/bin/env bash
#
# Guard a deploy to the live site.
#
# Why this exists, stated so nobody has to rediscover it:
#
#   Twice now, `rsync -a --delete src/ build/ <live>/` was run from a worktree
#   that was NOT the branch the live site was serving. `--delete` removed the
#   Overview from the deployed tree, `build/tab-overview.js` went with it, and
#   the admin page rendered its shell and then threw. The first time (round 19)
#   the rule written down was "do not deploy from a master-based worktree while
#   a review is running" — and it was broken again four rounds later, because
#   a remembered rule is not a check.
#
#   This is that check. It refuses the deploy, it does not warn.
#
# Usage:
#   scripts/deploy-guard.sh <worktree> [live-dir]
#
# Exit codes:
#   0  the worktree matches what the live tree is serving; safe to deploy
#   1  refused — deploying would remove files the live site is using
#   2  usage or environment error

set -uo pipefail

WORKTREE="${1:-}"
LIVE="${2:-/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation}"

if [ -z "$WORKTREE" ] || [ ! -d "$WORKTREE" ]; then
	echo "usage: $0 <worktree> [live-dir]" >&2
	exit 2
fi

if [ ! -d "$LIVE" ]; then
	echo "error: live directory not found: $LIVE" >&2
	exit 2
fi

fail() {
	echo "REFUSED: $1" >&2
	echo >&2
	echo "  The live site is currently serving files this worktree does not have."
	echo "  An 'rsync --delete' from here would remove them and break the page."
	echo >&2
	echo "  Fix, in order of preference:" >&2
	echo "    1. If the worktree IS what should be live, deploy the branch that the" >&2
	echo "       live tree is actually running — not this one." >&2
	echo "    2. If a review is in progress, restore the live tree to the reviewed" >&2
	echo "       SHA:  git -C <worktree> checkout <sha> -- src build" >&2
	echo "       then rsync from that." >&2
	echo "    3. Only after 1 or 2, re-run this script." >&2
	exit 1
}

echo "== deploy guard =="
echo "  worktree : $WORKTREE"
echo "  live     : $LIVE"

# Informational only. The guard's job is comparing trees, so a directory that
# is not a git worktree is still safe to check — the head is a convenience for
# the human reading the output.
HEAD_SHA=$( git -C "$WORKTREE" rev-parse --short HEAD 2>/dev/null )
echo "  head     : ${HEAD_SHA:-<not a git worktree>}"
echo

# ---------------------------------------------------------------------------
# 1. Files the live site serves that this worktree does not have at all.
#    These are the ones `--delete` would remove. This is the check that would
#    have caught both incidents.
# ---------------------------------------------------------------------------
echo "-- files present live but absent from the worktree --"
missing=0
while IFS= read -r rel; do
	[ -z "$rel" ] && continue
	if [ ! -e "$WORKTREE/$rel" ]; then
		echo "  MISSING  $rel"
		missing=$(( missing + 1 ))
	fi
done < <(
	find "$LIVE/src" "$LIVE/build" -type f 2>/dev/null \
		| sed -e "s|^$LIVE/||" \
		| sort
)

if [ "$missing" -gt 0 ]; then
	echo
	fail "$missing file(s) the live site is serving would be deleted"
fi
echo "  none"
echo

# ---------------------------------------------------------------------------
# 2. Files that differ in content. Not automatically fatal — a deploy is
#    supposed to change things — but the count is printed so it is visible
#    rather than silent, and a large count is a signal you are deploying the
#    wrong branch.
# ---------------------------------------------------------------------------
echo "-- files that differ in content --"
changed=0
while IFS= read -r rel; do
	[ -z "$rel" ] && continue
	cmp -s "$LIVE/$rel" "$WORKTREE/$rel" || {
		echo "  DIFFERS  $rel"
		changed=$(( changed + 1 ))
	}
done < <(
	find "$WORKTREE/src" "$WORKTREE/build" -type f 2>/dev/null \
		| sed -e "s|^$WORKTREE/||" \
		| sort
)

echo "  $changed file(s) would change"
echo
echo "ALLOWED. If this is more than you expected, check the branch before rsyncing:"
echo "  git -C $WORKTREE log --oneline -1"
echo
echo "After deploying, confirm the served bundle has the feature the page uses:"
echo "  ls $LIVE/build/tab-overview.js"
