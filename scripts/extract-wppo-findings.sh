#!/usr/bin/env bash
# extract-wppo-findings.sh — tolerant extractor for the WordPress Feature
# Monitor research output.
#
# The research agent streams ALL of its messages to stdout (progress chatter
# plus the final findings JSON). CI must narrow that stream to exactly the
# LAST COMPLETE findings document. The inline workflow version anchors on
# `awk '/^\{"schema_version"/'`, which misses indented output, a UTF-8 BOM,
# CRLF line endings, and markdown fences — every one of which the model has
# emitted in failed weekly runs (issue #1563).
#
# This script is the canonical tolerant implementation the workflow should
# call (one-line adoption, see issue #1563). It intentionally lives
# outside `.github/` so it can be unit-tested without workflow permissions.
#
# Usage:
#   scripts/extract-wppo-findings.sh <raw-output> [clean-output]
#   scripts/extract-wppo-findings.sh < raw.txt > clean.json
#
# Exit 0 + clean JSON on stdout/file when the stream contains a complete
# findings object (`{"schema_version"...}` with a `findings` array).
# Exit 1 + human-readable jq error on stderr otherwise (fail-hard: callers
# must abort, never fall back to placeholders).
#
# @since NEXT
set -euo pipefail

INPUT="${1:-/dev/stdin}"
OUTPUT="${2:-}"

if [ "$INPUT" != "/dev/stdin" ] && [ ! -r "$INPUT" ]; then
	printf 'extract-wppo-findings: input not readable: %s\n' "$INPUT" >&2
	exit 1
fi

narrow="$(mktemp)"
norm="$(mktemp)"
trap 'rm -f -- "$narrow" "$norm"' EXIT

# 1. Normalise: strip UTF-8 BOM (first line only), CR bytes (CRLF files),
#    and markdown fences. Fences are stripped BEFORE scanning so they never
#    hide a document. A fence that shares its line with the JSON start
#    (e.g. '```json {"schema_version"...') has the marker stripped as a
#    prefix so the document survives; bare fence lines are deleted outright.
sed -e '1s/^\xEF\xBB\xBF//' -e 's/\r//g' \
	-e '/^[[:space:]]*```[[:space:]]*$/d' \
	-e 's/^[[:space:]]*```[a-zA-Z0-9_-]*[[:space:]]*//' "$INPUT" >"$norm"

# 2. Collect every complete JSON value in the normalised stream and keep the
#    LAST object containing "schema_version". Scanning complete values (not
#    anchoring on the first matching line) is what makes prose safe: a chatter
#    line quoting '"schema_version":' is not valid JSON so it can never
#    poison a later real document, and prose between two echoes is skipped
#    instead of aborting the parse. Pretty-printed, indented, and multi-doc
#    streams all collapse to the final complete document; a truncated tail
#    is simply never complete so it is dropped.
clean=""
if command -v python3 >/dev/null 2>&1; then
	clean="$(python3 - "$norm" <<'EOF' 2>/dev/null || true
import sys
import json
with open(sys.argv[1], encoding='utf-8', errors='replace') as f:
    text = f.read()
dec = json.JSONDecoder()
docs = []
i = 0
n = len(text)
while i < n:
    if text[i] not in '{[':
        i += 1
        continue
    try:
        obj, j = dec.raw_decode(text, i)
    except Exception:
        i += 1
        continue
    docs.append(obj)
    i = j
cands = [d for d in docs if isinstance(d, dict) and 'schema_version' in d]
if cands:
    print(json.dumps(cands[-1], separators=(',', ':')), end='')
EOF
)"
else
	# Fallback when python3 is unavailable: anchor on the LAST
	# '"schema_version":' line (quoted + colon-terminated), backtrack at
	# most 10 lines to the nearest opening brace for pretty-printed docs,
	# then keep the last complete `jq` document. Scanning anchors last to
	# first keeps quoted prose before the real doc from poisoning it.
	mapfile -t anchors < <(grep -n -E '"schema_version"[[:space:]]*:' "$norm" | cut -d: -f1 || true)
	clean=""
	for (( idx=${#anchors[@]} - 1; idx >= 0; idx-- )); do
		anchor="${anchors[idx]}"
		start="$anchor"
		anchor_line="$(sed -n "${anchor}p" "$norm")"
		case "$anchor_line" in
			*\{*'"schema_version"'*) ;;
			*)
				s="$anchor"
				while [ "$s" -gt 1 ] && [ $(( anchor - s )) -lt 10 ]; do
					s=$(( s - 1 ))
					prev="$(sed -n "${s}p" "$norm")"
					case "$prev" in
						*\{*) start="$s"; break ;;
					esac
				done
				;;
		esac
		tail -n +"$start" "$norm" >"$narrow"
		candidate="$(jq -c . "$narrow" 2>/dev/null | head -1 || true)"
		if [ -n "$candidate" ] && printf '%s' "$candidate" | grep -q '"schema_version"'; then
			if printf '%s' "$candidate" | jq -e 'type == "object" and (.findings | type == "array")' >/dev/null 2>&1; then
				clean="$candidate"
				break
			fi
			# Keep the last schema_version doc even when the shape gate
			# will reject it, so callers get the shape error, not silence.
			clean="$candidate"
			break
		fi
	done
fi

if [ -z "$clean" ]; then
	printf 'no JSON document found (output empty, or no "schema_version" field present)\n' >&2
	exit 1
fi

# 4. Shape gate: object with a findings array (mirrors the workflow check).
if ! printf '%s' "$clean" | jq -e 'type == "object" and (.findings | type == "array")' >/dev/null 2>&1; then
	printf 'output JSON is not an object with a findings array\n' >&2
	exit 1
fi

if [ -n "$OUTPUT" ]; then
	printf '%s\n' "$clean" >"$OUTPUT"
else
	printf '%s\n' "$clean"
fi
