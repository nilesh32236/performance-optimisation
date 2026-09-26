#!/usr/bin/env bash
# The guard exists because I broke production twice with `rsync --delete`. If it
# stops working, nothing tells me until the page throws. So it is itself tested.
set -uo pipefail
HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
GUARD="$HERE/../../scripts/deploy-guard.sh"
fails=0

ok()   { echo "  PASS  $1"; }
bad()  { echo "  FAIL  $1"; fails=$(( fails + 1 )); }

# A fake live tree that serves a feature the worktree does not have.
fixture() {
	local root; root="$( mktemp -d )"
	mkdir -p "$root/live/src/components/overview" "$root/live/build" \
		"$root/wt/src/components/overview" "$root/wt/build"
	echo 'x' > "$root/live/build/tab-overview.js"
	echo 'x' > "$root/live/src/components/overview/Overview.js"
	echo 'x' > "$root/live/build/index.js"
	echo 'x' > "$root/wt/build/index.js"
	echo "$root"
}

# 1. Refuses when the worktree would delete a file the live site is serving.
r=$( fixture )
out=$( bash "$GUARD" "$r/wt" "$r/live" 2>&1 ); rc=$?
if [ "$rc" -eq 1 ] && printf '%s' "$out" | grep -q 'MISSING  build/tab-overview.js'; then
	ok "refuses a deploy that would delete a served file"
else
	bad "did not refuse the deleting deploy (rc=$rc)"
fi
rm -rf "$r"

# 2. Allows when the worktree is a superset.
r=$( fixture )
echo 'x' > "$r/wt/build/tab-overview.js"
echo 'x' > "$r/wt/src/components/overview/Overview.js"
out=$( bash "$GUARD" "$r/wt" "$r/live" 2>&1 ); rc=$?
if [ "$rc" -eq 0 ] && printf '%s' "$out" | grep -q 'ALLOWED'; then
	ok "allows a deploy from a superset worktree"
else
	bad "refused a legitimate deploy (rc=$rc)"
fi
rm -rf "$r"

# 3. Reports content differences rather than silently swallowing them.
r=$( fixture )
echo 'x' > "$r/wt/build/tab-overview.js"
echo 'x' > "$r/wt/src/components/overview/Overview.js"
echo 'DIFFERENT' > "$r/wt/build/index.js"
out=$( bash "$GUARD" "$r/wt" "$r/live" 2>&1 )
if printf '%s' "$out" | grep -q 'DIFFERS  build/index.js'; then
	ok "reports differing files explicitly"
else
	bad "did not report the content difference"
fi
rm -rf "$r"

# 4. A missing worktree is a usage error, not a silent pass.
out=$( bash "$GUARD" /nonexistent/path /tmp 2>&1 ); rc=$?
if [ "$rc" -eq 2 ]; then ok "rejects a nonexistent worktree"; else bad "bad usage gave rc=$rc"; fi

echo
[ "$fails" -eq 0 ] && echo "deploy-guard: all tests passed" || echo "deploy-guard: $fails FAILED"
exit "$fails"
