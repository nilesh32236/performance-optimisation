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

# 5. Refuses a $LIVE that exists but is not a plugin tree. An earlier version
#    only checked that the directory existed, so `find ... 2>/dev/null` saw
#    nothing and the guard printed ALLOWED with exit 0 — a green light from a
#    tool whose only job is to fail closed.
r=$( fixture ); rm -rf "$r/live/src" "$r/live/build"
out=$( bash "$GUARD" "$r/wt" "$r/live" 2>&1 ); rc=$?
if [ "$rc" -eq 1 ] && printf '%s' "$out" | grep -q 'not a plugin tree'; then
	ok "refuses a live dir that is not a plugin tree"
else
	bad "allowed a live dir with no src/ or build/ (rc=$rc)"
fi
rm -rf "$r"

# 6. Refuses an empty build artefact, which blanks the admin page. The
#    absent-file check does not cover this: the file exists in both trees.
r=$( fixture )
echo 'x' > "$r/wt/build/tab-overview.js"
echo 'x' > "$r/wt/src/components/overview/Overview.js"
: > "$r/wt/build/index.js"
out=$( bash "$GUARD" "$r/wt" "$r/live" 2>&1 ); rc=$?
if [ "$rc" -eq 1 ] && printf '%s' "$out" | grep -q 'EMPTY    build/index.js'; then
	ok "refuses a deploy that would ship an empty bundle"
else
	bad "allowed an empty build/index.js (rc=$rc)"
fi
rm -rf "$r"

# 7. A `|` in the live path used to break the sed prefix-strip, discard find's
#    output and still exit 0. The path is now stripped with find -printf.
r=$( mktemp -d )
mkdir -p "$r/live/src/components/overview" "$r/live/build" \
	"$r/wt/src/components/overview" "$r/wt/build"
echo 'x' > "$r/live/build/tab-overview.js"
echo 'x' > "$r/live/src/components/overview/Overview.js"
echo 'x' > "$r/live/build/index.js"
echo 'x' > "$r/wt/build/index.js"
# A pipe in the path: the guard used to interpolate it into a sed BRE, where a
# second `|` ends the s/// command, discarding find's output and exiting 0.
mv "$r/live" "$r/live|weird"
out=$( bash "$GUARD" "$r/wt" "$r/live|weird" 2>&1 ); rc=$?
if [ "$rc" -eq 1 ] && ! printf '%s' "$out" | grep -q "unknown option to"; then
	ok "handles a live path containing a pipe"
else
	bad "failed open on a piped path (rc=$rc)"
fi
rm -rf "$r"

# 8. An intentionally removed file is allowlisted, so it does not block every
#    future deploy until someone hand-deletes it from live.
r=$( fixture )
printf 'build/tab-overview.js\nsrc/components/overview/Overview.js\n' \
	> "$r/live/.deploy-guard-allowlist"
out=$( bash "$GUARD" "$r/wt" "$r/live" 2>&1 ); rc=$?
if [ "$rc" -eq 0 ] && printf '%s' "$out" | grep -q 'intentionally removed'; then
	ok "honours the allowlist for a deliberately removed file"
else
	bad "ignored .deploy-guard-allowlist (rc=$rc)"
fi
rm -rf "$r"

# 9. A symlinked src/ or build/ must still be enumerated. `[ -d ]` follows
#    symlinks and passes, but find's default -P does not, so the subtree
#    listed zero files and the guard reported "none" and allowed the deploy.
r=$( fixture )
mv "$r/live/src" "$r/live-src-real"
ln -s "$r/live-src-real" "$r/live/src"
out=$( bash "$GUARD" "$r/wt" "$r/live" 2>&1 ); rc=$?
if [ "$rc" -eq 1 ] && printf '%s' "$out" | grep -q 'components/overview/Overview.js'; then
	ok "enumerates a symlinked src/ instead of reporting none"
else
	bad "did not follow a symlinked src/ (rc=$rc)"
fi
rm -rf "$r"

# 10. An unreadable subtree must refuse, not report an empty list. The
#     2>/dev/null on find used to swallow this and the guard allowed it.
if [ "$( id -u )" -ne 0 ]; then
	r=$( fixture )
	echo 'x' > "$r/wt/build/tab-overview.js"
	echo 'x' > "$r/wt/src/components/overview/Overview.js"
	chmod 0000 "$r/live/src"
	out=$( bash "$GUARD" "$r/wt" "$r/live" 2>&1 ); rc=$?
	chmod 0755 "$r/live/src"
	if [ "$rc" -ne 0 ]; then
		ok "refuses when a subtree cannot be read"
	else
		bad "allowed a deploy from an unreadable live tree (rc=$rc)"
	fi
	rm -rf "$r"
else
	echo "  SKIP  unreadable-subtree case (needs non-root)"
fi

echo
[ "$fails" -eq 0 ] && echo "deploy-guard: all tests passed" || echo "deploy-guard: $fails FAILED"
exit "$fails"
