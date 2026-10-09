#!/usr/bin/env bash
# Tests for scripts/extract-wppo-findings.sh (issue #1563).
# The workflow's inline `awk '/^\{"schema_version"/'` misses indented, BOM,
# CRLF, and fenced JSON — every miss aborts the weekly monitor run. These
# tests pin the tolerant behaviour of the canonical extractor.
set -uo pipefail
HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
EXTRACT="$HERE/../../scripts/extract-wppo-findings.sh"
fails=0

ok()   { echo "  PASS  $1"; }
bad()  { echo "  FAIL  $1"; fails=$(( fails + 1 )); }

DOC='{"schema_version": 1, "run": {"commit":"abc","wordpress_target":"6.2+","php_target":"8.2+","date":"2026-10-09"}, "lanes": [], "findings": []}'

# 1. Exact anchor (workflow's existing happy path keeps working).
out=$( printf 'chatter\n%s\n' "$DOC" | bash "$EXTRACT" 2>/dev/null ); rc=$?
if [ "$rc" -eq 0 ] && printf '%s' "$out" | grep -q '"schema_version"'; then
	ok "extracts the exact-anchor document"
else
	bad "exact anchor failed (rc=$rc)"
fi

# 2. Leading whitespace before the brace (model indentation).
out=$( printf 'progress\n   %s\n' "$DOC" | bash "$EXTRACT" 2>/dev/null ); rc=$?
if [ "$rc" -eq 0 ] && printf '%s' "$out" | grep -q '"schema_version"'; then
	ok "tolerates leading whitespace"
else
	bad "indented JSON failed (rc=$rc)"
fi

# 3. Markdown fences around the document.
out=$( printf 'progress\n```json\n%s\n```\n' "$DOC" | bash "$EXTRACT" 2>/dev/null ); rc=$?
if [ "$rc" -eq 0 ] && printf '%s' "$out" | grep -q '"schema_version"'; then
	ok "strips markdown fences"
else
	bad "fenced JSON failed (rc=$rc)"
fi

# 4. CRLF line endings.
out=$( printf 'progress\r\n%s\r\n' "$DOC" | bash "$EXTRACT" 2>/dev/null ); rc=$?
if [ "$rc" -eq 0 ] && printf '%s' "$out" | grep -q '"schema_version"'; then
	ok "strips carriage returns"
else
	bad "CRLF input failed (rc=$rc)"
fi

# 5. UTF-8 BOM at the start of the stream.
out=$( printf '\xEF\xBB\xBFprogress\n%s\n' "$DOC" | bash "$EXTRACT" 2>/dev/null ); rc=$?
if [ "$rc" -eq 0 ] && printf '%s' "$out" | grep -q '"schema_version"'; then
	ok "strips a UTF-8 BOM"
else
	bad "BOM input failed (rc=$rc)"
fi

# 6. Multi-doc capture keeps the LAST COMPLETE document (repeated
#    self-verification echoes collapse to one doc).
other='{"schema_version": 1, "run": {"commit":"old","wordpress_target":"6.2+","php_target":"8.2+","date":"2026-10-08"}, "lanes": [], "findings": []}'
out=$( printf 'nonsense {"a":1}\n%s\n%s\n' "$other" "$DOC" | bash "$EXTRACT" 2>/dev/null ); rc=$?
if [ "$rc" -eq 0 ] && printf '%s' "$out" | grep -q '"commit":"abc"'; then
	ok "keeps the last complete document"
else
	bad "multi-doc input kept the wrong doc (rc=$rc)"
fi

# 7. Chatter containing braces before the document does not confuse anchoring.
out=$( printf 'Dispatching lanes… {"not": "json"}\nReconciling…\n%s\n' "$DOC" | bash "$EXTRACT" 2>/dev/null ); rc=$?
if [ "$rc" -eq 0 ] && printf '%s' "$out" | grep -q '"schema_version"'; then
	ok "ignores pre-document chatter with braces"
else
	bad "chatter with braces broke extraction (rc=$rc)"
fi

# 8. Empty output fails hard with a readable error, never an empty doc.
out=$( printf '' | bash "$EXTRACT" 2>&1 ); rc=$?
if [ "$rc" -ne 0 ] && printf '%s' "$out" | grep -q 'no JSON document found'; then
	ok "fails hard on empty output"
else
	bad "empty output gave rc=$rc out=$out"
fi

# 9. No anchor fails hard (prose-only model reply).
out=$( printf 'All done, no JSON here\n' | bash "$EXTRACT" 2>&1 ); rc=$?
if [ "$rc" -ne 0 ]; then
	ok "fails hard when no JSON document is present"
else
	bad "anchorless output unexpectedly passed"
fi

# 10. Valid JSON without a findings array fails the shape gate.
out=$( printf '{"schema_version": 1}\n' | bash "$EXTRACT" 2>&1 ); rc=$?
if [ "$rc" -ne 0 ] && printf '%s' "$out" | grep -q 'findings array'; then
	ok "rejects JSON without a findings array"
else
	bad "shape gate did not fire (rc=$rc)"
fi

echo
[ "$fails" -eq 0 ] && echo "extract-wppo-findings: all tests passed" || echo "extract-wppo-findings: $fails FAILED"
exit "$fails"
