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

# Both trees must actually look like plugin trees. An earlier version only
# checked that $LIVE existed, so a typo'd path produced an empty file list and
# the guard printed "0 file(s) would change / ALLOWED" with exit 0 — a green
# light from a tool whose only job is to fail closed.
for d in "$LIVE/src" "$LIVE/build" "$WORKTREE/src" "$WORKTREE/build"; do
	if [ ! -d "$d" ]; then
		echo "REFUSED: not a plugin tree: $d" >&2
		echo "  Both src/ and build/ must exist, or the comparison has nothing to" >&2
		echo "  compare and would silently allow the deploy." >&2
		exit 1
	fi
done

# List a tree's src/ and build/ files, as paths relative to the tree.
#
# `find -printf '%P\n'` is used rather than `find | sed "s|^$ROOT/||"` because
# the latter treats the path as a regex: a `|` anywhere in it broke the
# expression, `find`'s output was discarded, and the guard — whose only job is to
# fail closed — exited 0 ALLOWED with the sed error printed to stderr.
list_relative() {
	(
		cd "$1" || exit 1
		# '%p' is the path as given (build/index.js). '%P' would strip to the
		# matched subdirectory and yield index.js, so every lookup below would
		# miss and the guard would report every file as absent.
		find src build -type f 2>/dev/null -printf '%p\n'
	) | sort
}

# Files the live tree holds that this deploy intentionally removes. One path per
# line; blank lines and `#` comments are ignored.
ALLOW_REMOVED="$LIVE/.deploy-guard-allowlist"

is_allowed_removal() {
	[ -f "$ALLOW_REMOVED" ] || return 1
	grep -qxF "$1" "$ALLOW_REMOVED" 2>/dev/null
}

fail() {
	echo "REFUSED: $1" >&2
	echo >&2
	echo "  The live site is currently serving files this worktree does not have."
	echo "  An 'rsync --delete' from here would remove them and break the page."
	echo >&2
	echo "  If the live tree is running a branch this worktree does not have:" >&2
	echo "    deploy the branch the live tree is actually serving, not this one." >&2
	echo >&2
	echo "  If a review is in progress, restore the live tree to the reviewed SHA:" >&2
	echo "    git -C <worktree> checkout <sha> -- src build && rsync from that." >&2
	echo >&2
	echo "  If these files are being removed ON PURPOSE, list them in:" >&2
	echo "    $ALLOW_REMOVED" >&2
	echo >&2
	echo "  Only after one of the above, re-run this script." >&2
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
	if [ ! -e "$WORKTREE/$rel" ] && is_allowed_removal "$rel"; then
		echo "  ALLOWED  $rel  (intentionally removed; listed in .deploy-guard-allowlist)"
	fi
	if [ ! -e "$WORKTREE/$rel" ] && ! is_allowed_removal "$rel"; then
		echo "  MISSING  $rel"
		missing=$(( missing + 1 ))
	fi
done < <( list_relative "$LIVE" )

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
empty=0
while IFS= read -r rel; do
	[ -z "$rel" ] && continue
	cmp -s "$LIVE/$rel" "$WORKTREE/$rel" || {
		echo "  DIFFERS  $rel"
		changed=$(( changed + 1 ))
	}
	# A bundle that exists but is empty ships a blank admin page. The refuse
	# condition above only covers *absent* files, so this is checked separately.
	case "$rel" in
	build/*.js)
		if [ ! -s "$WORKTREE/$rel" ]; then
			echo "  EMPTY    $rel  (would deploy a blank bundle)"
			empty=$(( empty + 1 ))
		fi
		;;
	esac
done < <( list_relative "$WORKTREE" )

echo "  $changed file(s) would change"

if [ "$empty" -gt 0 ]; then
	echo
	fail "$empty build file(s) would deploy empty, which blanks the admin page"
fi

echo
echo "ALLOWED. If this is more than you expected, check the branch before rsyncing:"
echo "  git -C $WORKTREE log --oneline -1"
echo
echo "After deploying, confirm the served bundle is non-empty:"
echo "  find $LIVE/build -name '*.js' -size -1c"
